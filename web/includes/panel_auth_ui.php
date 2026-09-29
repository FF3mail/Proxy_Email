<?php
declare(strict_types=1);

// Panel auth / operators UI handlers (PROMPT 24). Loaded from index.php.

require_once __DIR__ . '/panel_brand.php';

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
    <?php renderPanelFaviconLinks(); ?>
    <link rel="stylesheet" href="/assets/login-brand.css">
</head>
<body class="login-page">
<div class="login-lang" aria-label="<?= h(__('common.language')) ?>"><?php renderLanguageSelector(); ?></div>
<div class="login-card">
    <div class="login-brand">
        <img class="login-wordmark-delta" src="<?= h(panelBrandUrl('wordmark-delta.png')) ?>" width="335" height="105" alt="Delta">
        <img class="login-wordmark-transit" src="<?= h(panelBrandUrl('wordmark-transit.png')) ?>" width="113" height="26" alt="TRANSIT">
    </div>
    <h2><?= h(__('auth.login_heading')) ?></h2>
    <?php if ($flash): ?>
        <div class="login-flash <?= $flash['type'] === 'success' ? 'login-flash-ok' : 'login-flash-err' ?>">
            <?= h((string)$flash['message']) ?>
        </div>
    <?php endif; ?>
    <?php if (!$loginAllowed): ?>
        <div class="login-flash login-flash-warn"><?= h($setupMessage) ?></div>
    <?php endif; ?>
    <form method="post" action="/index.php">
        <input type="hidden" name="action" value="login_submit">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
        <div>
            <label for="username"><?= h(__('auth.username')) ?></label>
            <input type="text" id="username" name="username" required autocomplete="username">
        </div>
        <div>
            <label for="password"><?= h(__('auth.password')) ?></label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="login-submit"<?= $loginAllowed ? '' : ' disabled' ?>><?= h(__('auth.login_button')) ?></button>
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
    $rows = $stmt->fetchAll() ?: [];
    $csrf = h((string) ($_SESSION['csrf_token'] ?? ''));

    $flash = $GLOBALS['flash'] ?? null;
    $GLOBALS['flash'] = null;

    renderHeader(__('operator.title'));
    renderPanelModalStyles();
    ?>
    <div class="pm-head">
        <h1><?= h(__('operator.heading')) ?></h1>
        <button type="button" class="pm-btn pm-btn-primary" data-pm-open="dlg-operator-create">+ <?= h(__('operator.create')) ?></button>
    </div>
    <p class="pm-hint"><?= h(__('operator.hint')) ?></p>

    <?php if ($rows === []): ?>
        <div class="pm-empty">
            <?= h(__('operator.empty')) ?><br>
            <button type="button" class="pm-btn pm-btn-primary" style="margin-top:10px" data-pm-open="dlg-operator-create">+ <?= h(__('operator.create')) ?></button>
        </div>
    <?php else: ?>
        <div class="pm-card pm-table-wrap">
            <table class="pm-table pm-list-table" id="operator-table" data-pm-table="1">
                <thead>
                <tr>
                    <th data-sort="username"><?= h(__('common.username')) ?></th>
                    <th data-sort="role"><?= h(__('common.role')) ?></th>
                    <th data-sort="active"><?= h(__('common.active')) ?></th>
                    <th data-sort="created"><?= h(__('common.created')) ?></th>
                    <th><?= h(__('common.actions')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $r):
                    $isMaster = (string) $r['role'] === 'master';
                    $isActive = (int) $r['active'] === 1;
                    ?>
                    <tr tabindex="0" class="<?= $i === 0 ? 'pm-sel' : '' ?>">
                        <td><?= h((string) $r['username']) ?></td>
                        <td><?= h((string) $r['role']) ?></td>
                        <td>
                            <?php if ($isActive): ?>
                                <span class="pm-chip pm-chip-ok"><?= h(__('common.yes')) ?></span>
                            <?php else: ?>
                                <span class="pm-chip pm-chip-off"><?= h(__('common.no')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="pm-mono"><?= h((string) $r['created_at']) ?></td>
                        <td>
                            <?php if ($isMaster): ?>
                                <button type="button" class="pm-btn pm-btn-sm pm-btn-disabled" disabled
                                        title="<?= h(__('operator.master_protected')) ?>">
                                    <?= h(__('operator.deactivate')) ?>
                                </button>
                                <span class="pm-help" style="display:block;margin-top:4px;font-size:12px;color:var(--pm-warn)">
                                    <?= h(__('operator.master_protected')) ?>
                                </span>
                            <?php elseif ($isActive): ?>
                                <button type="button" class="pm-btn pm-btn-sm pm-btn-danger"
                                        data-op-deactivate="<?= (int) $r['id'] ?>"
                                        data-op-name="<?= h((string) $r['username']) ?>">
                                    <?= h(__('operator.deactivate')) ?>
                                </button>
                            <?php else: ?>
                                <span class="pm-chip pm-chip-off"><?= h(__('operator.inactive')) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="pm-foot"><?= h(__('operator.foot')) ?></p>
    <?php endif; ?>

    <dialog class="pm-dialog" id="dlg-operator-create" aria-modal="true">
        <form method="post" action="/index.php">
            <div class="pm-mh">
                <h2><?= h(__('operator.add_heading')) ?></h2>
                <button type="button" class="pm-x" data-pm-close aria-label="<?= h(__('common.cancel')) ?>">×</button>
            </div>
            <div class="pm-mb"><div class="pm-fg">
                <input type="hidden" name="action" value="operator_create">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <div class="pm-f pm-full">
                    <label for="op_username"><?= h(__('common.username')) ?></label>
                    <input type="text" id="op_username" name="username" required maxlength="100" autocomplete="off">
                </div>
                <div class="pm-f pm-full">
                    <label for="op_password"><?= h(__('common.password')) ?></label>
                    <input type="password" id="op_password" name="password" required minlength="8" autocomplete="new-password">
                    <span class="pm-help"><?= h(__('operator.password_hint')) ?></span>
                </div>
            </div></div>
            <div class="pm-mf"><span></span><div class="pm-r">
                <button type="button" class="pm-btn" data-pm-close><?= h(__('common.cancel')) ?></button>
                <button type="submit" class="pm-btn pm-btn-primary"><?= h(__('operator.create')) ?></button>
            </div></div>
        </form>
    </dialog>

    <dialog class="pm-dialog" id="dlg-operator-deactivate" aria-modal="true" data-pm-nodirty="1">
        <form method="post" action="/index.php" id="form-operator-deactivate">
            <div class="pm-mh">
                <h2><?= h(__('operator.deactivate_title')) ?></h2>
                <button type="button" class="pm-x" data-pm-close aria-label="<?= h(__('common.cancel')) ?>">×</button>
            </div>
            <div class="pm-mb">
                <p style="margin:0" id="op-deactivate-text"><?= h(__('operator.deactivate_confirm')) ?></p>
                <input type="hidden" name="action" value="operator_deactivate">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="id" id="op_deactivate_id" value="">
            </div>
            <div class="pm-mf"><span></span><div class="pm-r">
                <button type="button" class="pm-btn" data-pm-close><?= h(__('common.cancel')) ?></button>
                <button type="submit" class="pm-btn pm-btn-danger-solid" data-pm-focus><?= h(__('operator.deactivate')) ?></button>
            </div></div>
        </form>
    </dialog>

    <script>
    (function () {
      var tpl = <?= json_encode(__('operator.deactivate_body'), JSON_UNESCAPED_UNICODE) ?>;
      document.querySelectorAll('[data-op-deactivate]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          var id = btn.getAttribute('data-op-deactivate') || '';
          var name = btn.getAttribute('data-op-name') || '';
          document.getElementById('op_deactivate_id').value = id;
          document.getElementById('op-deactivate-text').textContent =
            (tpl || '').replace('{name}', name || '—');
          if (window.PanelModal) window.PanelModal.open(document.getElementById('dlg-operator-deactivate'));
        });
      });
    })();
    </script>
    <?php
    renderPanelModalScripts(is_array($flash) ? $flash : null);
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
