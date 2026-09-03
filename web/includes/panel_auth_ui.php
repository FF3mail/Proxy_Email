<?php
declare(strict_types=1);

// Panel auth / operators UI handlers (PROMPT 24). Loaded from index.php.

function renderLoginForm(): void
{
    global $flash;
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход — DELTA-транзит</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
<div class="bg-white shadow rounded p-8 w-full max-w-md">
    <h1 class="text-2xl font-bold mb-6">DELTA-транзит — вход</h1>
    <?php if ($flash): ?>
        <div class="<?= $flash['type'] === 'success'
            ? 'bg-green-100 border border-green-400 text-green-700'
            : 'bg-red-100 border border-red-400 text-red-700' ?> px-4 py-3 rounded mb-4">
            <?= h((string)$flash['message']) ?>
        </div>
    <?php endif; ?>
    <form method="post" action="/index.php" class="space-y-4">
        <input type="hidden" name="action" value="login_submit">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
        <div>
            <label class="block text-sm font-medium mb-1" for="username">Имя пользователя</label>
            <input class="w-full border rounded px-3 py-2" type="text" id="username" name="username" required autocomplete="username">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" for="password">Пароль</label>
            <input class="w-full border rounded px-3 py-2" type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="w-full bg-slate-800 text-white rounded py-2">Войти</button>
    </form>
</div>
</body>
</html>
    <?php
}

function handleLoginSubmit(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        redirectTo('login');
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $ip = getClientIp();

    if ($username === '' || $password === '') {
        writeLog("Panel login failed for user='{$username}' ip={$ip} reason=empty");
        setFlash('error', 'Неверные учётные данные или слишком много попыток');
        redirectTo('login');
    }

    if (!panelLoginThrottleAllow()) {
        writeLog("Panel login failed for user='{$username}' ip={$ip} reason=throttled");
        setFlash('error', 'Неверные учётные данные или слишком много попыток');
        redirectTo('login');
    }

    $row = fetchPanelAdminByUsername($username);
    $ok = $row !== null
        && (int)$row['active'] === 1
        && password_verify($password, (string)$row['password_hash']);

    $password = '';

    if (!$ok) {
        panelLoginThrottleRegisterFailure();
        writeLog("Panel login failed for user='{$username}' ip={$ip}");
        setFlash('error', 'Неверные учётные данные или слишком много попыток');
        redirectTo('login');
    }

    establishPanelSession($row);
    writeLog("Panel login success for user='{$username}' role={$row['role']} ip={$ip}");
    setFlash('success', 'Вход выполнен');
    redirectTo('dashboard');
}

function handleLogout(): void
{
    $user = (string)($_SESSION['admin_username_display'] ?? '');
    clearPanelSession();
    session_regenerate_id(true);
    writeLog("Panel logout for user='{$user}' ip=" . getClientIp());
    setFlash('success', 'Вы вышли из системы');
    redirectTo('login');
}

function renderOperatorList(): void
{
    $pdo = getPdo();
    $stmt = $pdo->query(
        'SELECT id, username, role, active, created_at FROM panel_admins ORDER BY role DESC, username ASC'
    );
    $rows = $stmt->fetchAll();

    renderHeader('Операторы панели');
    ?>
    <h2 class="text-xl font-semibold mb-4">Операторы панели</h2>
    <p class="text-sm text-slate-600 mb-6">Только master может управлять операторами. Через UI создаются только role=admin.</p>

    <table class="min-w-full bg-white shadow rounded mb-8">
        <thead class="bg-slate-100 text-left">
        <tr>
            <th class="px-4 py-2">Username</th>
            <th class="px-4 py-2">Role</th>
            <th class="px-4 py-2">Active</th>
            <th class="px-4 py-2">Created</th>
            <th class="px-4 py-2">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="border-t">
                <td class="px-4 py-2"><?= h((string)$r['username']) ?></td>
                <td class="px-4 py-2"><?= h((string)$r['role']) ?></td>
                <td class="px-4 py-2"><?= (int)$r['active'] === 1 ? 'yes' : 'no' ?></td>
                <td class="px-4 py-2"><?= h((string)$r['created_at']) ?></td>
                <td class="px-4 py-2">
                    <?php if ((string)$r['role'] !== 'master' && (int)$r['active'] === 1): ?>
                        <form method="post" action="/index.php" class="inline">
                            <input type="hidden" name="action" value="operator_deactivate">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="text-red-700 text-sm">Deactivate</button>
                        </form>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="bg-white shadow rounded p-6 max-w-lg">
        <h3 class="font-semibold mb-4">Добавить оператора (admin)</h3>
        <form method="post" action="/index.php" class="space-y-3">
            <input type="hidden" name="action" value="operator_create">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
            <div>
                <label class="block text-sm mb-1" for="op_username">Username</label>
                <input class="w-full border rounded px-3 py-2" type="text" id="op_username" name="username" required maxlength="100">
            </div>
            <div>
                <label class="block text-sm mb-1" for="op_password">Password</label>
                <input class="w-full border rounded px-3 py-2" type="password" id="op_password" name="password" required minlength="8">
            </div>
            <button type="submit" class="bg-slate-800 text-white rounded px-4 py-2">Создать</button>
        </form>
    </div>
    <?php
    renderFooter();
}

function handleOperatorCreate(): void
{
    // Application rule: UI always inserts role='admin'. Never honor a client-supplied role
    // (including attempts to set role=master). Enforcing a single master in the DB via
    // trigger is an accepted non-goal — see schema.sql comment.
    if (isset($_POST['role'])) {
        writeLog(
            'Panel operator_create rejected: role payload attempted by master='
            . (string)($_SESSION['admin_username_display'] ?? '')
            . ' ip=' . getClientIp()
        );
        setFlash('error', 'Недопустимый параметр');
        redirectTo('operator_list');
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $masterUser = (string)($_SESSION['admin_username_display'] ?? '');

    if ($username === '' || strlen($username) > 100 || $password === '' || strlen($password) < 8) {
        $password = '';
        setFlash('error', 'Некорректные username/password');
        redirectTo('operator_list');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $password = '';

    try {
        $stmt = getPdo()->prepare(
            'INSERT INTO panel_admins (username, password_hash, role, active) VALUES (?, ?, \'admin\', 1)'
        );
        $stmt->execute([$username, $hash]);
    } catch (PDOException $e) {
        writeLog(
            "Panel operator_create failed by master='{$masterUser}' target='{$username}' ip="
            . getClientIp()
        );
        setFlash('error', 'Не удалось создать оператора (возможно, имя занято)');
        redirectTo('operator_list');
    }

    writeLog(
        "Panel operator_create by master='{$masterUser}' target='{$username}' role=admin ip="
        . getClientIp()
    );
    setFlash('success', 'Оператор создан');
    redirectTo('operator_list');
}

function handleOperatorDeactivate(): void
{
    $id = (int)($_POST['id'] ?? 0);
    $masterUser = (string)($_SESSION['admin_username_display'] ?? '');
    $masterId = (int)($_SESSION['admin_id'] ?? 0);

    if ($id <= 0 || $id === $masterId) {
        setFlash('error', 'Некорректный оператор');
        redirectTo('operator_list');
    }

    $row = fetchPanelAdminById($id);
    if ($row === null || (string)$row['role'] === 'master') {
        setFlash('error', 'Нельзя деактивировать эту учётную запись');
        redirectTo('operator_list');
    }

    $stmt = getPdo()->prepare(
        'UPDATE panel_admins SET active = 0, updated_at = NOW() WHERE id = ? AND role = \'admin\''
    );
    $stmt->execute([$id]);

    writeLog(
        "Panel operator_deactivate by master='{$masterUser}' target='{$row['username']}' id={$id} ip="
        . getClientIp()
    );
    setFlash('success', 'Оператор деактивирован');
    redirectTo('operator_list');
}
