<?php
declare(strict_types=1);

namespace MailProxy;

use PDO;
use RuntimeException;

function initiateOAuth2(int $accountId): never
{
    $pdo = \getPdo();

    $stmt = $pdo->prepare('SELECT id, referent_id, email, auth_type, provider, client_id, client_secret_enc, active FROM external_accounts WHERE id = :id');
    $stmt->execute([':id' => $accountId]);
    $ea = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ea) {
        \writeLog("OAuth2 INIT ERROR: account $accountId not found");
        $_SESSION['flash'] = ['type'=>'error','message'=>'Аккаунт не найден'];
        header('Location: /index.php'); exit();
    }

    if ((int)$ea['active'] !== 1) {
        \writeLog("OAuth2 INIT ERROR: account $accountId is inactive");
        $_SESSION['flash'] = ['type'=>'error','message'=>'Аккаунт не активен'];
        header('Location: /index.php'); exit();
    }

    if ($ea['auth_type'] !== 'oauth2') {
        \writeLog("OAuth2 INIT ERROR: account $accountId is not oauth2 type");
        $_SESSION['flash'] = ['type'=>'error','message'=>'Аккаунт не поддерживает OAuth2'];
        header('Location: /index.php'); exit();
    }

    if (empty($ea['client_id'])) {
        \writeLog("OAuth2 INIT ERROR: client_id is empty for account $accountId");
        $_SESSION['flash'] = ['type'=>'error','message'=>'client_id пустой для аккаунта'];
        header('Location: /index.php'); exit();
    }

    if (empty($ea['client_secret_enc'])) {
        \writeLog("OAuth2 INIT ERROR: client_secret_enc is empty for account $accountId");
        $_SESSION['flash'] = ['type'=>'error','message'=>'client_secret пустой для аккаунта'];
        header('Location: /index.php'); exit();
    }

    $stmt = $pdo->prepare('SELECT id, code, name, auth_endpoint, scopes, extra_params_json, active FROM oauth_providers WHERE code = :provider_code');
    $stmt->execute([':provider_code' => $ea['provider']]);
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$provider) {
        \writeLog("OAuth2 INIT ERROR: OAuth2 provider '{$ea['provider']}' not found in DB");
        $_SESSION['flash'] = ['type'=>'error','message'=>'Провайдер не найден'];
        header('Location: /index.php'); exit();
    }

    if ((int)$provider['active'] !== 1) {
        \writeLog("OAuth2 INIT ERROR: OAuth2 provider '{$provider['code']}' is disabled");
        $_SESSION['flash'] = ['type'=>'error','message'=>'Провайдер отключён'];
        header('Location: /index.php'); exit();
    }

    try {
        \assertSafeOAuthEndpoint($provider['auth_endpoint'], 'auth_endpoint');
    } catch (RuntimeException $e) {
        \writeLog("OAuth2 INIT ERROR: unsafe auth_endpoint for provider {$provider['code']}: " . $e->getMessage());
        $_SESSION['flash'] = ['type'=>'error','message'=>'Небезопасный auth_endpoint провайдера'];
        header('Location: /index.php'); exit();
    }

    $cryptor = new Cryptor();
    try {
        $cryptor->decrypt($ea['client_secret_enc']);
    } catch (RuntimeException $e) {
        \writeLog("OAuth2 INIT ERROR: cannot decrypt client_secret for account $accountId: " . $e->getMessage());
        $_SESSION['flash'] = ['type'=>'error','message'=>'Ошибка расшифровки секрета. Проверьте crypto.key.'];
        header('Location: /index.php'); exit();
    }

    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_account_id'] = $accountId;

    $redirectUri = APP_BASE_URL . '/index.php?action=oauth_callback';

    $FORBIDDEN_KEYS = ['redirect_uri','client_id','response_type','scope','state'];
    $extra = [];
    if (!empty($provider['extra_params_json'])) {
        $decoded = json_decode($provider['extra_params_json'], true);
        if (!is_array($decoded)) {
            $decoded = [];
            \writeLog("OAuth2 INIT WARN: invalid extra_params_json for provider {$provider['code']}, ignored");
        }
        foreach ($FORBIDDEN_KEYS as $key) {
            if (array_key_exists($key, $decoded)) {
                unset($decoded[$key]);
                \writeLog("OAuth2 INIT WARN: removed forbidden key '$key' from extra_params for {$provider['code']}");
            }
        }
        $extra = $decoded;
    }

    $params = array_merge([
        'client_id'     => $ea['client_id'],
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => $provider['scopes'],
        'state'         => $state,
    ], $extra);

    $authUrl = $provider['auth_endpoint'] . '?' . http_build_query($params);

    \writeLog("OAuth2 INIT: redirecting account $accountId to provider {$provider['code']}");
    header('Location: ' . $authUrl);
    exit();
}

function handleOAuth2Callback(): never
{
    $code  = trim($_GET['code'] ?? '');
    $state = trim($_GET['state'] ?? '');

    if ($code === '' || $state === '') {
        \writeLog("OAuth2 CALLBACK ERROR: missing code or state parameter");
        $_SESSION['flash'] = ['type'=>'error','message'=>'Неверный запрос: отсутствуют параметры'];
        header('Location: /index.php'); exit();
    }

    if (!preg_match('/^[A-Za-z0-9\-._~\/+%]+=*$/', $code)) {
        \writeLog('OAuth2 CALLBACK ERROR: authorization code has invalid format');
        $_SESSION['flash'] = ['type'=>'error','message'=>'Неверный формат authorization code'];
        header('Location: /index.php'); exit();
    }

    $savedState = $_SESSION['oauth_state'] ?? '';
    if ($savedState === '' || !hash_equals($savedState, $state)) {
        \writeLog('OAuth2 CALLBACK ERROR: state mismatch');
        unset($_SESSION['oauth_state'], $_SESSION['oauth_account_id']);
        $_SESSION['flash'] = ['type'=>'error','message'=>'Ошибка безопасности: state не совпадает'];
        header('Location: /index.php'); exit();
    }
    unset($_SESSION['oauth_state']);

    $accountId = (int)($_SESSION['oauth_account_id'] ?? 0);
    unset($_SESSION['oauth_account_id']);
    if ($accountId === 0) {
        \writeLog("OAuth2 CALLBACK ERROR: oauth_account_id missing from session");
        $_SESSION['flash'] = ['type'=>'error','message'=>'Сессия устарела. Начните авторизацию заново.'];
        header('Location: /index.php'); exit();
    }

    $pdo = \getPdo();

    $stmt = $pdo->prepare('SELECT id, email, provider, client_id, client_secret_enc FROM external_accounts WHERE id = :id AND active = 1');
    $stmt->execute([':id' => $accountId]);
    $ea = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ea) {
        \writeLog("OAuth2 CALLBACK ERROR: account $accountId not found or inactive");
        $_SESSION['flash'] = ['type'=>'error','message'=>'Аккаунт не найден или не активен'];
        header('Location: /index.php'); exit();
    }

    $stmt = $pdo->prepare('SELECT code, token_endpoint, scopes, active FROM oauth_providers WHERE code = :provider_code');
    $stmt->execute([':provider_code' => $ea['provider']]);
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$provider) {
        \writeLog("OAuth2 CALLBACK ERROR: provider '{$ea['provider']}' not found in DB for account $accountId");
        $_SESSION['flash'] = ['type'=>'error','message'=>'Провайдер не найден'];
        header('Location: /index.php'); exit();
    }
    if ((int)$provider['active'] !== 1) {
        \writeLog("OAuth2 CALLBACK ERROR: provider '{$provider['code']}' is disabled for account $accountId");
        $_SESSION['flash'] = ['type'=>'error','message'=>'Провайдер отключён'];
        header('Location: /index.php'); exit();
    }

    try {
        \assertSafeOAuthEndpoint($provider['token_endpoint'], 'token_endpoint');
    } catch (RuntimeException $e) {
        \writeLog("OAuth2 CALLBACK ERROR: unsafe token_endpoint for provider {$provider['code']}: " . $e->getMessage());
        $_SESSION['flash'] = ['type'=>'error','message'=>'Небезопасный token_endpoint провайдера'];
        header('Location: /index.php'); exit();
    }

    $cryptor = new Cryptor();
    try {
        $clientSecret = $cryptor->decrypt($ea['client_secret_enc']);
    } catch (RuntimeException $e) {
        \writeLog("OAuth2 CALLBACK ERROR: cannot decrypt client_secret for account $accountId: " . $e->getMessage());
        $_SESSION['flash'] = ['type'=>'error','message'=>'Ошибка расшифровки секрета. Проверьте crypto.key.'];
        header('Location: /index.php'); exit();
    }

    $postFields = http_build_query([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'client_id' => $ea['client_id'],
        'client_secret' => $clientSecret,
        'redirect_uri' => APP_BASE_URL . '/index.php?action=oauth_callback',
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $provider['token_endpoint'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
    ]);

    $curlResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        \writeLog("OAuth2 CALLBACK ERROR: cURL error for account $accountId");
        $_SESSION['flash'] = ['type'=>'error','message'=>'Ошибка запроса к провайдеру: '.\h($curlErr)];
        header('Location: /index.php'); exit();
    }

    if ($curlResponse === false) {
        \writeLog("OAuth2 CALLBACK ERROR: cURL returned false, HTTP=$httpCode for account $accountId");
        $_SESSION['flash'] = ['type'=>'error','message'=>'Нет ответа от провайдера'];
        header('Location: /index.php'); exit();
    }

    $tokenData = json_decode($curlResponse, true);
    if (!is_array($tokenData)) {
        \writeLog("OAuth2 CALLBACK ERROR: invalid JSON from provider. HTTP=$httpCode for account $accountId");
        $_SESSION['flash'] = ['type'=>'error','message'=>'Неверный ответ от провайдера'];
        header('Location: /index.php'); exit();
    }

    if (isset($tokenData['error'])) {
        $errCode = (string)($tokenData['error'] ?? 'unknown');
        \writeLog("OAuth2 CALLBACK ERROR: provider returned error '$errCode' HTTP=$httpCode for account $accountId");
        $_SESSION['flash'] = ['type'=>'error','message'=>'Провайдер отклонил запрос: '.\h($errCode)];
        header('Location: /index.php'); exit();
    }

    $accessToken  = $tokenData['access_token'] ?? '';
    $refreshToken = $tokenData['refresh_token'] ?? null;
    $expiresIn    = (int)($tokenData['expires_in'] ?? 3600);
    $tokenType    = $tokenData['token_type'] ?? 'Bearer';
    $scope        = $tokenData['scope'] ?? $provider['scopes'];

    if ($accessToken === '') {
        \writeLog("OAuth2 CALLBACK ERROR: access_token missing in provider response for account $accountId");
        $_SESSION['flash'] = ['type'=>'error','message'=>'Провайдер не вернул access_token'];
        header('Location: /index.php'); exit();
    }

    $accessTokenEnc = $cryptor->encrypt($accessToken);
    $refreshTokenEnc = !empty($refreshToken) ? $cryptor->encrypt($refreshToken) : null;
    $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

    $upsert = $pdo->prepare(
        'INSERT INTO oauth_tokens
            (account_id, access_token_enc, refresh_token_enc, scope, token_type, expires_at)
         VALUES
            (:account_id, :access_token_enc, :refresh_token_enc, :scope, :token_type, :expires_at)
         ON DUPLICATE KEY UPDATE
            access_token_enc = VALUES(access_token_enc),
            refresh_token_enc = COALESCE(VALUES(refresh_token_enc), refresh_token_enc),
            scope = VALUES(scope),
            token_type = VALUES(token_type),
            expires_at = VALUES(expires_at),
            updated_at = NOW()'
    );
    $upsert->execute([
        ':account_id'       => $accountId,
        ':access_token_enc' => $accessTokenEnc,
        ':refresh_token_enc'=> $refreshTokenEnc,
        ':scope'            => $scope,
        ':token_type'       => $tokenType,
        ':expires_at'       => $expiresAt,
    ]);

    \writeLog("OAuth2 SUCCESS: Account ID {$accountId}, provider {$provider['code']}, tokens updated, expires_at={$expiresAt}");
    $_SESSION['flash'] = ['type'=>'success','message'=>"OAuth2 авторизация успешна. Токен активен до $expiresAt"];
    header('Location: /index.php'); exit();
}
