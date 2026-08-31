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

    renderHeader('OAuth2 провайдеры');

    echo '<div class="max-w-7xl mx-auto p-6">';
    echo '<div class="flex justify-between items-center mb-6">';
    echo '<h1 class="text-2xl font-bold">OAuth2 провайдеры</h1>';
    echo '<a href="?action=provider_form" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Добавить провайдер</a>';
    echo '</div>';

    echo '<div class="overflow-x-auto bg-white shadow rounded">';
    echo '<table class="min-w-full border-collapse">';
    echo '<thead class="bg-gray-100">';
    echo '<tr>';
    echo '<th class="border p-2 text-left">ID</th>';
    echo '<th class="border p-2 text-left">Код</th>';
    echo '<th class="border p-2 text-left">Название</th>';
    echo '<th class="border p-2 text-left">Auth endpoint</th>';
    echo '<th class="border p-2 text-left">Token endpoint</th>';
    echo '<th class="border p-2 text-left">Скоупы</th>';
    echo '<th class="border p-2 text-left">Статус</th>';
    echo '<th class="border p-2 text-left">Действия</th>';
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
            echo '<span class="px-2 py-1 rounded bg-green-100 text-green-800 text-sm">Активен</span>';
        } else {
            echo '<span class="px-2 py-1 rounded bg-gray-100 text-gray-700 text-sm">Отключён</span>';
        }

        echo '</td>';
        echo '<td class="border p-2">';

        echo '<div class="flex gap-2">';

        echo '<a href="?action=provider_form&id=' . (int)$provider['id'] . '" 
                 class="bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700">
                 Редактировать
              </a>';

        echo '<form method="post" action="?action=provider_toggle" class="inline">';
        echo '<input type="hidden" name="action" value="provider_toggle">';
        echo '<input type="hidden" name="id" value="' . (int)$provider['id'] . '">';
        echo '<input type="hidden" name="csrf_token" value="' . csrfField() . '">';
        echo '<button type="submit" class="bg-gray-600 text-white px-3 py-1 rounded hover:bg-gray-700">';
        echo ((int)$provider['active'] === 1 ? 'Выкл' : 'Вкл');
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
                'message' => 'Провайдер не найден'
            ];

            header('Location: /index.php?action=provider_list');
            exit();
        }

        $provider = $row;
    }

    renderHeader($id !== null ? 'Редактирование провайдера' : 'Создание провайдера');

    echo '<div class="max-w-4xl mx-auto p-6">';
    echo '<h1 class="text-2xl font-bold mb-6">'
        . ($id !== null ? 'Редактирование провайдера' : 'Создание провайдера')
        . '</h1>';

    echo '<form method="post" action="?action=provider_save" class="bg-white shadow rounded p-6 space-y-4">';
    echo '<input type="hidden" name="action" value="provider_save">';

    if ($id !== null) {
        echo '<input type="hidden" name="id" value="' . (int)$provider['id'] . '">';
    }

    echo '<div>';
    echo '<label class="block font-medium mb-1">Код</label>';
    echo '<input type="text"
                 name="code"
                 pattern="[a-z0-9_]+"
                 value="' . h((string)$provider['code']) . '"'
                 . ($id !== null ? ' disabled class="w-full border rounded px-3 py-2 bg-gray-100 cursor-not-allowed"'
                                 : ' class="w-full border rounded px-3 py-2"') . '>';
    echo '</div>';

    echo '<div>';
    echo '<label class="block font-medium mb-1">Название</label>';
    echo '<input type="text"
                 name="name"
                 value="' . h((string)$provider['name']) . '"
                 class="w-full border rounded px-3 py-2">';
    echo '</div>';

    echo '<div>';
    echo '<label class="block font-medium mb-1">Auth endpoint</label>';
    echo '<input type="url"
                 name="auth_endpoint"
                 value="' . h((string)$provider['auth_endpoint']) . '"
                 class="w-full border rounded px-3 py-2">';
    echo '</div>';

    echo '<div>';
    echo '<label class="block font-medium mb-1">Token endpoint</label>';
    echo '<input type="url"
                 name="token_endpoint"
                 value="' . h((string)$provider['token_endpoint']) . '"
                 class="w-full border rounded px-3 py-2">';
    echo '</div>';

    echo '<div>';
    echo '<label class="block font-medium mb-1">Скоупы</label>';
    echo '<textarea name="scopes" rows="3"
                     class="w-full border rounded px-3 py-2">'
         . h((string)$provider['scopes']) .
         '</textarea>';
    echo '</div>';

    echo '<div>';
    echo '<label class="block font-medium mb-1">extra_params_json</label>';
    echo '<textarea name="extra_params_json"
                     rows="6"
                     placeholder=\'{"access_type":"offline"}\'
                     class="w-full border rounded px-3 py-2">'
         . h((string)$provider['extra_params_json']) .
         '</textarea>';

    echo '<p class="text-sm text-gray-600 mt-2">';
    echo 'Запрещённые ключи (будут удалены автоматически): ';
    echo 'redirect_uri, client_id, response_type, scope, state';
    echo '</p>';
    echo '</div>';

    echo '<div>';
    echo '<label class="inline-flex items-center">';
    echo '<input type="checkbox" name="active" value="1" '
         . ((int)$provider['active'] === 1 ? 'checked' : '')
         . ' class="mr-2">';
    echo '<span>Активен</span>';
    echo '</label>';
    echo '</div>';

    echo '<input type="hidden" name="csrf_token" value="' . csrfField() . '">';

    echo '<button type="submit"
                  class="bg-green-600 text-white px-5 py-2 rounded hover:bg-green-700">';
    echo 'Сохранить провайдер';
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
            $_SESSION['flash'] = ['type'=>'error','message'=>'Код провайдера: только a-z, 0-9, _'];
            header('Location: /index.php?action=provider_form'); exit();
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM oauth_providers WHERE code = :code');
        $stmt->execute([':code' => $code]);
        if ($stmt->fetchColumn() > 0) {
            $_SESSION['flash'] = ['type'=>'error','message'=>"Провайдер с кодом '$code' уже существует"];
            header('Location: /index.php?action=provider_form'); exit();
        }
    } else {
        $stmt = $pdo->prepare('SELECT code FROM oauth_providers WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $existingCode = $stmt->fetchColumn();
        if ($existingCode === false) {
            $_SESSION['flash'] = ['type'=>'error','message'=>'Провайдер не найден'];
            header('Location: /index.php?action=provider_list'); exit();
        }
        $code = $existingCode;
    }

    if ($name === '') {
        $_SESSION['flash'] = ['type'=>'error','message'=>'Название не может быть пустым'];
        header('Location: /index.php?action=provider_form' . ($id ? "&id=$id" : '')); exit();
    }

    foreach (['auth_endpoint' => $authEndpoint, 'token_endpoint' => $tokenEndpoint] as $field => $url) {
        try {
            \assertSafeOAuthEndpoint($url, $field);
        } catch (\RuntimeException $e) {
            $_SESSION['flash'] = ['type'=>'error','message'=>$e->getMessage()];
            header('Location: /index.php?action=provider_form' . ($id ? "&id=$id" : '')); exit();
        }
    }

    if ($scopes === '') {
        $_SESSION['flash'] = ['type'=>'error','message'=>'Скоупы не могут быть пустыми'];
        header('Location: /index.php?action=provider_form' . ($id ? "&id=$id" : '')); exit();
    }

    if ($extraParamsJson !== '') {
        $decodedExtra = json_decode($extraParamsJson, true);
        if ($decodedExtra === null) {
            $_SESSION['flash'] = ['type'=>'error','message'=>'extra_params_json: невалидный JSON'];
            header('Location: /index.php?action=provider_form' . ($id ? "&id=$id" : '')); exit();
        }
        if (!is_array($decodedExtra)) {
            $_SESSION['flash'] = ['type'=>'error','message'=>'extra_params_json: ожидается объект JSON'];
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

    $_SESSION['flash'] = ['type'=>'success', 'message'=>'Провайдер сохранён'];
    header('Location: /index.php?action=provider_list'); exit();
}

function handleProviderToggle(): void
{
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id === 0) {
        $_SESSION['flash'] = ['type'=>'error', 'message'=>'Не указан ID провайдера'];
        header('Location: /index.php?action=provider_list'); exit();
    }

    $pdo = \getPdo();
    $stmt = $pdo->prepare('SELECT id, code, active FROM oauth_providers WHERE id=:id');
    $stmt->execute([':id' => $id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        $_SESSION['flash'] = ['type'=>'error','message'=>'Провайдер не найден'];
        header('Location: /index.php?action=provider_list'); exit();
    }

    $newActive = ((int)$current['active']) ? 0 : 1;
    $stmt = $pdo->prepare('UPDATE oauth_providers SET active=:active, updated_at=NOW() WHERE id=:id');
    $stmt->execute([':active' => $newActive, ':id' => $id]);

    \writeLog("Provider TOGGLED: id=$id, code={$current['code']}, active=$newActive");

    $_SESSION['flash'] = [
        'type'=>'success',
        'message'=>'Провайдер ' . ($newActive ? 'включён' : 'отключён')
    ];
    header('Location: /index.php?action=provider_list'); exit();
}
