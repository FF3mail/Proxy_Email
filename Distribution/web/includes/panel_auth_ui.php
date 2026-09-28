<?php
declare(strict_types=1);

// Panel auth / operators UI handlers (PROMPT 24). Loaded from index.php.

function renderLoginForm(): void
{
    global $flash;
    $loginAllowed = panelAuthLoginAllowed();
    $setupMessage = '';
    if (!$loginAllowed) {
        if (!panelAdminsTableExists()) {
            $setupMessage = __('auth.setup_table_missing');
        } elseif (panelInactiveMasterExists()) {
            $setupMessage = __('auth.setup_master_inactive');
        } else {
            $setupMessage = __('auth.setup_master_missing');
        }
    }
    ?>
<!DOCTYPE html>
<html lang="<?= h(panelHtmlLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(__('auth.login_title')) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .lang-link { color: #64748b; font-size: 0.875rem; text-decoration: none; }
        .lang-link:hover { color: #1e293b; }
        .lang-active { color: #1e293b; font-size: 0.875rem; font-weight: 600; }
        .lang-sep { color: #94a3b8; font-size: 0.875rem; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
<div class="bg-white shadow rounded p-8 w-full max-w-md">
    <div class="flex justify-between items-start mb-4">
        <h1 class="text-2xl font-bold"><?= h(__('auth.login_heading')) ?></h1>
        <div aria-label="<?= h(__('common.language')) ?>"><?php renderLanguageSelector(); ?></div>
    </div>
    <?php if ($flash): ?>
        <div class="<?= $flash['type'] === 'success'
            ? 'bg-green-100 border border-green-400 text-green-700'
            : 'bg-red-100 border border-red-400 text-red-700' ?> px-4 py-3 rounded mb-4">
            <?= h((string)$flash['message']) ?>
        </div>
    <?php endif; ?>
    <?php if (!$loginAllowed): ?>
        <div class="bg-yellow-100 border border-yellow-400 text-yellow-800 px-4 py-3 rounded mb-4 text-sm">
            <?= h($setupMessage) ?>
        </div>
    <?php endif; ?>
    <form method="post" action="/index.php" class="space-y-4">
        <input type="hidden" name="action" value="login_submit">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
        <div>
            <label class="block text-sm font-medium mb-1" for="username"><?= h(__('auth.username')) ?></label>
            <input class="w-full border rounded px-3 py-2" type="text" id="username" name="username" required autocomplete="username">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1" for="password"><?= h(__('auth.password')) ?></label>
            <input class="w-full border rounded px-3 py-2" type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="w-full bg-slate-800 text-white rounded py-2"<?= $loginAllowed ? '' : ' disabled' ?>><?= h(__('auth.login_button')) ?></button>
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

    if (!panelAuthLoginAllowed()) {
        writeLog("Panel login rejected ip={$ip} reason=auth_not_ready");
        setFlash('error', __('auth.login_unavailable'));
        redirectTo('login');
    }

    if ($username === '' || $password === '') {
        writeLog("Panel login failed for user='{$username}' ip={$ip} reason=empty");
        setFlash('error', __('auth.invalid_credentials'));
        redirectTo('login');
    }

    if (!panelLoginThrottleAllow()) {
        writeLog("Panel login failed for user='{$username}' ip={$ip} reason=throttled");
        setFlash('error', __('auth.invalid_credentials'));
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
        setFlash('error', __('auth.invalid_credentials'));
        redirectTo('login');
    }

    establishPanelSession($row);
    writeLog("Panel login success for user='{$username}' role={$row['role']} ip={$ip}");
    setFlash('success', __('auth.login_success'));
    redirectTo('dashboard');
}

function handleLogout(): void
{
    $user = (string)($_SESSION['admin_username_display'] ?? '');
    clearPanelSession();
    session_regenerate_id(true);
    writeLog("Panel logout for user='{$user}' ip=" . getClientIp());
    setFlash('success', __('auth.logout_success'));
    redirectTo('login');
}

function renderOperatorList(): void
{
    $pdo = getPdo();
    $stmt = $pdo->query(
        'SELECT id, username, role, active, created_at FROM panel_admins ORDER BY role DESC, username ASC'
    );
    $rows = $stmt->fetchAll();

    renderHeader(__('operator.title'));
    ?>
    <h2 class="text-xl font-semibold mb-4"><?= h(__('operator.heading')) ?></h2>
    <p class="text-sm text-slate-600 mb-6"><?= h(__('operator.hint')) ?></p>

    <table class="min-w-full bg-white shadow rounded mb-8">
        <thead class="bg-slate-100 text-left">
        <tr>
            <th class="px-4 py-2"><?= h(__('common.username')) ?></th>
            <th class="px-4 py-2"><?= h(__('common.role')) ?></th>
            <th class="px-4 py-2"><?= h(__('common.active')) ?></th>
            <th class="px-4 py-2"><?= h(__('common.created')) ?></th>
            <th class="px-4 py-2"><?= h(__('common.actions')) ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="border-t">
                <td class="px-4 py-2"><?= h((string)$r['username']) ?></td>
                <td class="px-4 py-2"><?= h((string)$r['role']) ?></td>
                <td class="px-4 py-2"><?= (int)$r['active'] === 1 ? h(__('common.yes')) : h(__('common.no')) ?></td>
                <td class="px-4 py-2"><?= h((string)$r['created_at']) ?></td>
                <td class="px-4 py-2">
                    <?php if ((string)$r['role'] !== 'master' && (int)$r['active'] === 1): ?>
                        <form method="post" action="/index.php" class="inline">
                            <input type="hidden" name="action" value="operator_deactivate">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="text-red-700 text-sm"><?= h(__('operator.deactivate')) ?></button>
                        </form>
                    <?php else: ?>
                        <?= h(__('common.dash')) ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="bg-white shadow rounded p-6 max-w-lg">
        <h3 class="font-semibold mb-4"><?= h(__('operator.add_heading')) ?></h3>
        <form method="post" action="/index.php" class="space-y-3">
            <input type="hidden" name="action" value="operator_create">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
            <div>
                <label class="block text-sm mb-1" for="op_username"><?= h(__('common.username')) ?></label>
                <input class="w-full border rounded px-3 py-2" type="text" id="op_username" name="username" required maxlength="100">
            </div>
            <div>
                <label class="block text-sm mb-1" for="op_password"><?= h(__('common.password')) ?></label>
                <input class="w-full border rounded px-3 py-2" type="password" id="op_password" name="password" required minlength="8">
            </div>
            <button type="submit" class="bg-slate-800 text-white rounded px-4 py-2"><?= h(__('operator.create')) ?></button>
        </form>
    </div>
    <?php
    renderFooter();
}

function handleOperatorCreate(): void
{
    if (isset($_POST['role'])) {
        writeLog(
            'Panel operator_create rejected: role payload attempted by master='
            . (string)($_SESSION['admin_username_display'] ?? '')
            . ' ip=' . getClientIp()
        );
        setFlash('error', __('operator.invalid_param'));
        redirectTo('operator_list');
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $masterUser = (string)($_SESSION['admin_username_display'] ?? '');

    if ($username === '' || strlen($username) > 100 || $password === '' || strlen($password) < 8) {
        $password = '';
        setFlash('error', __('operator.invalid_credentials'));
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
        setFlash('error', __('operator.create_failed'));
        redirectTo('operator_list');
    }

    writeLog(
        "Panel operator_create by master='{$masterUser}' target='{$username}' role=admin ip="
        . getClientIp()
    );
    setFlash('success', __('operator.created'));
    redirectTo('operator_list');
}

function handleOperatorDeactivate(): void
{
    $id = (int)($_POST['id'] ?? 0);
    $masterUser = (string)($_SESSION['admin_username_display'] ?? '');
    $masterId = (int)($_SESSION['admin_id'] ?? 0);

    if ($id <= 0 || $id === $masterId) {
        setFlash('error', __('operator.invalid'));
        redirectTo('operator_list');
    }

    $row = fetchPanelAdminById($id);
    if ($row === null || (string)$row['role'] === 'master') {
        setFlash('error', __('operator.cannot_deactivate'));
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
    setFlash('success', __('operator.deactivated'));
    redirectTo('operator_list');
}
