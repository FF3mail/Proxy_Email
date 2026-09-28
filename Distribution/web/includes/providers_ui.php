<?php
declare(strict_types=1);

use PDO;

function renderProviderList(): void
{
    $pdo = \getPdo();

    $stmt = $pdo->prepare(
        'SELECT id, code, name, auth_endpoint, token_endpoint, scopes, active
         FROM oauth_providers
         ORDER BY id'
    );
    $stmt->execute();

    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    renderHeader(__('provider.title'));

    echo '<div class="max-w-7xl mx-auto p-6">';
    echo '<div class="flex justify-between items-center mb-6">';
    echo '<h1 class="text-2xl font-bold">' . h(__('provider.title')) . '</h1>';
    echo '<a href="?action=provider_form" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">' . h(__('provider.add')) . '</a>';
    echo '</div>';

    echo '<div class="overflow-x-auto bg-white shadow rounded">';
    echo '<table class="min-w-full border-collapse">';
    echo '<thead class="bg-gray-100">';
    echo '<tr>';
    echo '<th class="border p-2 text-left">' . h(__('common.id')) . '</th>';
    echo '<th class="border p-2 text-left">' . h(__('provider.code')) . '</th>';
    echo '<th class="border p-2 text-left">' . h(__('provider.name')) . '</th>';
    echo '<th class="border p-2 text-left">' . h(__('provider.auth_endpoint')) . '</th>';
    echo '<th class="border p-2 text-left">' . h(__('provider.token_endpoint')) . '</th>';
    echo '<th class="border p-2 text-left">' . h(__('provider.scopes')) . '</th>';
    echo '<th class="border p-2 text-left">' . h(__('common.status')) . '</th>';
    echo '<th class="border p-2 text-left">' . h(__('common.actions')) . '</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($providers as $provider) {
        echo '<tr>';
        echo '<td class="border p-2">' . h((string)$provider['id']) . '</td>';
        echo '<td class="border p-2 font-mono">' . h($provider['code']) . '</td>';
        echo '<td class="border p-2">' . h($provider['name']) . '</td>';
        echo '<td class="border p-2 break-all">' . h($provider['auth_endpoint']) . '</td>';
        echo '<td class="border p-2 break-all">' . h($provider['token_endpoint']) . '</td>';
        echo '<td class="border p-2">' . h($provider['scopes']) . '</td>';
        echo '<td class="border p-2">';

        if ((int)$provider['active'] === 1) {
            echo '<span class="px-2 py-1 rounded bg-green-100 text-green-800 text-sm">' . h(__('common.active')) . '</span>';
        } else {
            echo '<span class="px-2 py-1 rounded bg-gray-100 text-gray-700 text-sm">' . h(__('common.disabled')) . '</span>';
        }

        echo '</td>';
        echo '<td class="border p-2">';

        echo '<div class="flex gap-2">';

        echo '<a href="?action=provider_form&id=' . (int)$provider['id'] . '" 
                 class="bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700">'
                 . h(__('common.edit')) .
              '</a>';

        echo '<form method="post" action="?action=provider_toggle" class="inline">';
        echo '<input type="hidden" name="action" value="provider_toggle">';
        echo '<input type="hidden" name="id" value="' . (int)$provider['id'] . '">';
        echo '<input type="hidden" name="csrf_token" value="' . csrfField() . '">';
        echo '<button type="submit" class="bg-gray-600 text-white px-3 py-1 rounded hover:bg-gray-700">';
        echo ((int)$provider['active'] === 1 ? h(__('common.off')) : h(__('common.on')));
        echo '</button>';
        echo '</form>';

        echo '</div>';

        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';

    renderFooter();
}

function renderProviderForm(?int $id = null): void
{
    $provider = [
        'id' => '',
        'code' => '',
        'name' => '',
        'auth_endpoint' => '',
        'token_endpoint' => '',
        'scopes' => '',
        'extra_params_json' => '',
        'active' => 1,
    ];

    if ($id !== null) {
        $pdo = \getPdo();

        $stmt = $pdo->prepare(
            'SELECT id, code, name, auth_endpoint, token_endpoint,
                    scopes, extra_params_json, active
             FROM oauth_providers
             WHERE id = :id'
        );

        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $_SESSION['flash'] = [
                'type' => 'error',
                'message' => __('provider.not_found'),
            ];

            header('Location: /index.php?action=provider_list');
            exit();
        }

        $provider = $row;
    }

    $formTitle = $id !== null ? __('provider.edit_title') : __('provider.create_title');
    renderHeader($formTitle);

    echo '<div class="max-w-4xl mx-auto p-6">';
    echo '<h1 class="text-2xl font-bold mb-6">' . h($formTitle) . '</h1>';

    echo '<form method="post" action="?action=provider_save" class="bg-white shadow rounded p-6 space-y-4">';
    echo '<input type="hidden" name="action" value="provider_save">';

    if ($id !== null) {
        echo '<input type="hidden" name="id" value="' . (int)$provider['id'] . '">';
    }

    echo '<div>';
    echo '<label class="block font-medium mb-1">' . h(__('provider.code')) . '</label>';
    echo '<input type="text"
                 name="code"
                 pattern="[a-z0-9_]+"
                 value="' . h((string)$provider['code']) . '"'
                 . ($id !== null ? ' disabled class="w-full border rounded px-3 py-2 bg-gray-100 cursor-not-allowed"'
                                 : ' class="w-full border rounded px-3 py-2"') . '>';
    echo '</div>';

    echo '<div>';
    echo '<label class="block font-medium mb-1">' . h(__('provider.name')) . '</label>';
    echo '<input type="text"
                 name="name"
                 value="' . h((string)$provider['name']) . '"
                 class="w-full border rounded px-3 py-2">';
    echo '</div>';

    echo '<div>';
    echo '<label class="block font-medium mb-1">' . h(__('provider.auth_endpoint')) . '</label>';
    echo '<input type="url"
                 name="auth_endpoint"
                 value="' . h((string)$provider['auth_endpoint']) . '"
                 class="w-full border rounded px-3 py-2">';
    echo '</div>';

    echo '<div>';
    echo '<label class="block font-medium mb-1">' . h(__('provider.token_endpoint')) . '</label>';
    echo '<input type="url"
                 name="token_endpoint"
                 value="' . h((string)$provider['token_endpoint']) . '"
                 class="w-full border rounded px-3 py-2">';
    echo '</div>';

    echo '<div>';
    echo '<label class="block font-medium mb-1">' . h(__('provider.scopes')) . '</label>';
    echo '<textarea name="scopes" rows="3"
                     class="w-full border rounded px-3 py-2">'
         . h((string)$provider['scopes']) .
         '</textarea>';
    echo '</div>';

    echo '<div>';
    echo '<label class="block font-medium mb-1">' . h(__('provider.extra_params')) . '</label>';
    echo '<textarea name="extra_params_json"
                     rows="6"
                     placeholder=\'{"access_type":"offline"}\'
                     class="w-full border rounded px-3 py-2">'
         . h((string)$provider['extra_params_json']) .
         '</textarea>';

    echo '<p class="text-sm text-gray-600 mt-2">';
    echo h(__('provider.extra_params_hint'));
    echo '</p>';
    echo '</div>';

    echo '<div>';
    echo '<label class="inline-flex items-center">';
    echo '<input type="checkbox" name="active" value="1" '
         . ((int)$provider['active'] === 1 ? 'checked' : '')
         . ' class="mr-2">';
    echo '<span>' . h(__('provider.active')) . '</span>';
    echo '</label>';
    echo '</div>';

    echo '<input type="hidden" name="csrf_token" value="' . csrfField() . '">';

    echo '<button type="submit"
                  class="bg-green-600 text-white px-5 py-2 rounded hover:bg-green-700">';
    echo h(__('provider.save'));
    echo '</button>';
    echo '</form>';
    echo '</div>';

    renderFooter();
}

function handleProviderSave(): void
{
    $pdo = \getPdo();

    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $authEndpoint = trim($_POST['auth_endpoint'] ?? '');
    $tokenEndpoint = trim($_POST['token_endpoint'] ?? '');
    $scopes = trim($_POST['scopes'] ?? '');
    $extraParamsJson = trim($_POST['extra_params_json'] ?? '');
    $active = (int)(bool)($_POST['active'] ?? 0);

    $removedKeys = [];

    if ($id === null) {
        if (!preg_match('/^[a-z0-9_]+$/', $code)) {
            $_SESSION['flash'] = ['type'=>'error','message'=>__('provider.code_invalid')];
            header('Location: /index.php?action=provider_form'); exit();
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM oauth_providers WHERE code = :code');
        $stmt->execute([':code' => $code]);
        if ($stmt->fetchColumn() > 0) {
            $_SESSION['flash'] = ['type'=>'error','message'=>__('provider.code_exists', ['code' => $code])];
            header('Location: /index.php?action=provider_form'); exit();
        }
    } else {
        $stmt = $pdo->prepare('SELECT code FROM oauth_providers WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $existingCode = $stmt->fetchColumn();
        if ($existingCode === false) {
            $_SESSION['flash'] = ['type'=>'error','message'=>__('provider.not_found')];
            header('Location: /index.php?action=provider_list'); exit();
        }
        $code = $existingCode;
    }

    if ($name === '') {
        $_SESSION['flash'] = ['type'=>'error','message'=>__('provider.name_required')];
        header('Location: /index.php?action=provider_form' . ($id ? "&id=$id" : '')); exit();
    }

    foreach (['auth_endpoint' => $authEndpoint, 'token_endpoint' => $tokenEndpoint] as $field => $url) {
        try {
            \assertSafeOAuthEndpoint($url, $field);
        } catch (\Throwable $e) {
            $_SESSION['flash'] = ['type'=>'error','message'=>exceptionUserMessage($e)];
            header('Location: /index.php?action=provider_form' . ($id ? "&id=$id" : '')); exit();
        }
    }

    if ($scopes === '') {
        $_SESSION['flash'] = ['type'=>'error','message'=>__('provider.scopes_required')];
        header('Location: /index.php?action=provider_form' . ($id ? "&id=$id" : '')); exit();
    }

    if ($extraParamsJson !== '') {
        $decodedExtra = json_decode($extraParamsJson, true);
        if ($decodedExtra === null) {
            $_SESSION['flash'] = ['type'=>'error','message'=>__('provider.extra_json_invalid')];
            header('Location: /index.php?action=provider_form' . ($id ? "&id=$id" : '')); exit();
        }
        if (!is_array($decodedExtra)) {
            $_SESSION['flash'] = ['type'=>'error','message'=>__('provider.extra_json_object')];
            header('Location: /index.php?action=provider_form' . ($id ? "&id=$id" : '')); exit();
        }
        $forbidden = ['redirect_uri','client_id','response_type','scope','state'];
        foreach ($forbidden as $k) {
            if (array_key_exists($k, $decodedExtra)) {
                unset($decodedExtra[$k]);
                $removedKeys[] = $k;
            }
        }
        if (!empty($removedKeys)) {
            \writeLog("Provider save: removed forbidden keys [" . implode(',',$removedKeys)
                . "] from extra_params for provider $code");
        }
        $extraParamsJson = json_encode($decodedExtra, JSON_UNESCAPED_UNICODE);
    } else {
        $extraParamsJson = null;
    }

    if ($id === null) {
        $stmt = $pdo->prepare(
            'INSERT INTO oauth_providers
             (code, name, auth_endpoint, token_endpoint, scopes, extra_params_json, active)
             VALUES (:code, :name, :auth_endpoint, :token_endpoint, :scopes, :extra_params_json, :active)'
        );
        $stmt->execute([
            ':code' => $code,
            ':name' => $name,
            ':auth_endpoint' => $authEndpoint,
            ':token_endpoint' => $tokenEndpoint,
            ':scopes' => $scopes,
            ':extra_params_json' => $extraParamsJson,
            ':active' => $active,
        ]);
        \writeLog("Provider CREATED: code=$code, name=$name");
    } else {
        $stmt = $pdo->prepare(
            'UPDATE oauth_providers SET
                name=:name,
                auth_endpoint=:auth_endpoint,
                token_endpoint=:token_endpoint,
                scopes=:scopes,
                extra_params_json=:extra_params_json,
                active=:active,
                updated_at=NOW()
             WHERE id=:id'
        );
        $stmt->execute([
            ':name' => $name,
            ':auth_endpoint' => $authEndpoint,
            ':token_endpoint' => $tokenEndpoint,
            ':scopes' => $scopes,
            ':extra_params_json' => $extraParamsJson,
            ':active' => $active,
            ':id' => $id,
        ]);
        \writeLog("Provider UPDATED: id=$id, code=$code, name=$name");
    }

    $_SESSION['flash'] = ['type'=>'success', 'message'=>__('provider.saved')];
    header('Location: /index.php?action=provider_list'); exit();
}

function handleProviderToggle(): void
{
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id === 0) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>__('provider.id_required')];
        header('Location: /index.php?action=provider_list'); exit();
    }

    $pdo = \getPdo();
    $stmt = $pdo->prepare('SELECT id, code, active FROM oauth_providers WHERE id=:id');
    $stmt->execute([':id' => $id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        $_SESSION['flash'] = ['type'=>'error','message'=>__('provider.not_found')];
        header('Location: /index.php?action=provider_list'); exit();
    }

    $newActive = ((int)$current['active']) ? 0 : 1;
    $stmt = $pdo->prepare('UPDATE oauth_providers SET active=:active, updated_at=NOW() WHERE id=:id');
    $stmt->execute([':active' => $newActive, ':id' => $id]);

    \writeLog("Provider TOGGLED: id=$id, code={$current['code']}, active=$newActive");

    $_SESSION['flash'] = [
        'type'=>'success',
        'message'=> $newActive ? __('provider.enabled') : __('provider.disabled'),
    ];
    header('Location: /index.php?action=provider_list'); exit();
}
