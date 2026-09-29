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
    <link rel="stylesheet" href="/assets/panel-modal.css">
    <style>
        .login-page{
          min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
          background:
            linear-gradient(145deg, rgba(15,23,42,.78), rgba(63,109,158,.55)),
            radial-gradient(ellipse at 20% 20%, #1e3a5f 0%, transparent 50%),
            radial-gradient(ellipse at 80% 80%, #0f766e 0%, transparent 45%),
            #0f172a;
          background-size:cover;
          position:relative;
        }
        .login-page::before{
          content:"";position:absolute;inset:0;opacity:.12;
          background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='80' height='80' viewBox='0 0 80 80'%3E%3Cpath fill='%23ffffff' d='M0 40h80M40 0v80'/%3E%3C/svg%3E");
          background-size:40px 40px;pointer-events:none;
        }
        .login-card{
          position:relative;z-index:1;width:100%;max-width:420px;
          background:rgba(255,255,255,.96);border-radius:14px;
          box-shadow:0 24px 60px rgba(0,0,0,.35);padding:32px 28px 24px;
        }
        .login-brand{display:flex;flex-direction:column;align-items:center;gap:10px;margin-bottom:18px;text-align:center}
        .login-logo{width:64px;height:64px}
        .login-brand h1{margin:0;font-size:22px;color:#0f172a;font-weight:700}
        .login-tag{margin:0;font-size:13px;color:#64748b;line-height:1.4}
        .login-lang{position:absolute;top:14px;right:16px}
        .login-lang .lang-link{color:#64748b;font-size:.875rem;text-decoration:none}
        .login-lang .lang-link:hover{color:#1e293b}
        .login-lang .lang-active{color:#1e293b;font-size:.875rem;font-weight:600}
        .login-lang .lang-sep{color:#94a3b8;font-size:.875rem}
        .login-flash{padding:10px 12px;border-radius:8px;margin-bottom:14px;font-size:14px}
        .login-flash-ok{background:#e4f1e8;color:#3f7a55;border:1px solid #c5dfcd}
        .login-flash-err{background:#f6e3e2;color:#a1443f;border:1px solid #e8c4c2}
        .login-flash-warn{background:#f7edd6;color:#9a6b12;border:1px solid #e8d7a8}
        .login-f{margin-bottom:14px}
        .login-f label{display:block;font-size:13px;font-weight:600;color:#475569;margin-bottom:4px}
        .login-f input{
          width:100%;box-sizing:border-box;border:1px solid #e2e8f0;border-radius:8px;
          padding:10px 12px;font:inherit;color:#0f172a;background:#fff
        }
        .login-f input:focus{outline:2px solid #3f6d9e;outline-offset:1px}
        .login-btn{
          width:100%;border:0;border-radius:8px;padding:11px 14px;cursor:pointer;
          background:#1e293b;color:#fff;font-weight:600;font-size:15px
        }
        .login-btn:hover{filter:brightness(1.08)}
        .login-btn:disabled{opacity:.55;cursor:not-allowed}
        .login-dev{margin:18px 0 0;text-align:center;font-size:12px;color:#94a3b8}
        .login-dev-mark{
          display:inline-flex;align-items:center;gap:6px;justify-content:center
        }
        .login-dev-mark svg{width:18px;height:18px;opacity:.85}
    </style>
</head>
<body>
<div class="login-page">
<div class="login-card">
    <div class="login-lang" aria-label="<?= h(__('common.language')) ?>"><?php renderLanguageSelector(); ?></div>
    <div class="login-brand">
        <svg class="login-logo" viewBox="0 0 64 64" aria-hidden="true" role="img">
            <defs>
                <linearGradient id="lg" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0%" stop-color="#3f6d9e"/>
                    <stop offset="100%" stop-color="#0f766e"/>
                </linearGradient>
            </defs>
            <rect width="64" height="64" rx="14" fill="url(#lg)"/>
            <path d="M14 32h22M36 22l14 10-14 10" fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="18" cy="32" r="3.5" fill="#fff"/>
        </svg>
        <h1><?= h(__('app.name')) ?></h1>
        <p class="login-tag"><?= h(__('auth.brand_tagline')) ?></p>
    </div>
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
        <div class="login-f">
            <label for="username"><?= h(__('auth.username')) ?></label>
            <input type="text" id="username" name="username" required autocomplete="username">
        </div>
        <div class="login-f">
            <label for="password"><?= h(__('auth.password')) ?></label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="login-btn"<?= $loginAllowed ? '' : ' disabled' ?>><?= h(__('auth.login_button')) ?></button>
    </form>
    <p class="login-dev">
        <span class="login-dev-mark">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2 3 7v10l9 5 9-5V7l-9-5zm0 2.2 6.5 3.6v7.4L12 19.8 5.5 15.2V7.8L12 4.2z"/><path fill="currentColor" d="M11 9h2v6h-2zm0-3h2v2h-2z"/></svg>
            <?= h(__('auth.developer')) ?>
        </span>
    </p>
</div>
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
                                <span class="pm-help" style="font-size:12px;color:var(--pm-muted)">
                                    <?= h(__('operator.master_protected')) ?>
                                </span>
                            <?php else: ?>
                                <button type="button" class="pm-btn pm-btn-sm"
                                        data-op-edit="<?= (int) $r['id'] ?>"
                                        data-op-name="<?= h((string) $r['username']) ?>">
                                    <?= h(__('operator.edit')) ?>
                                </button>
                                <?php if ($isActive): ?>
                                    <button type="button" class="pm-btn pm-btn-sm pm-btn-danger"
                                            data-op-deactivate="<?= (int) $r['id'] ?>"
                                            data-op-name="<?= h((string) $r['username']) ?>">
                                        <?= h(__('operator.deactivate')) ?>
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="pm-btn pm-btn-sm pm-btn-danger"
                                        data-op-delete="<?= (int) $r['id'] ?>"
                                        data-op-name="<?= h((string) $r['username']) ?>">
                                    <?= h(__('operator.delete')) ?>
                                </button>
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

    <dialog class="pm-dialog" id="dlg-operator-edit" aria-modal="true">
        <form method="post" action="/index.php">
            <div class="pm-mh">
                <h2><?= h(__('operator.edit_heading')) ?></h2>
                <button type="button" class="pm-x" data-pm-close aria-label="<?= h(__('common.cancel')) ?>">×</button>
            </div>
            <div class="pm-mb"><div class="pm-fg">
                <input type="hidden" name="action" value="operator_update">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="id" id="op_edit_id" value="">
                <div class="pm-f pm-full">
                    <label for="op_edit_username"><?= h(__('common.username')) ?></label>
                    <input type="text" id="op_edit_username" name="username" required maxlength="100" autocomplete="off">
                </div>
                <div class="pm-f pm-full">
                    <label for="op_edit_password"><?= h(__('operator.reset_password')) ?></label>
                    <input type="password" id="op_edit_password" name="password" minlength="8" autocomplete="new-password">
                    <span class="pm-help"><?= h(__('operator.reset_password_hint')) ?></span>
                </div>
            </div></div>
            <div class="pm-mf"><span></span><div class="pm-r">
                <button type="button" class="pm-btn" data-pm-close><?= h(__('common.cancel')) ?></button>
                <button type="submit" class="pm-btn pm-btn-primary"><?= h(__('common.save')) ?></button>
            </div></div>
        </form>
    </dialog>

    <dialog class="pm-dialog" id="dlg-operator-delete" aria-modal="true" data-pm-nodirty="1">
        <form method="post" action="/index.php" id="form-operator-delete">
            <div class="pm-mh">
                <h2><?= h(__('operator.delete_title')) ?></h2>
                <button type="button" class="pm-x" data-pm-close aria-label="<?= h(__('common.cancel')) ?>">×</button>
            </div>
            <div class="pm-mb">
                <p style="margin:0" id="op-delete-text"><?= h(__('operator.delete_body')) ?></p>
                <input type="hidden" name="action" value="operator_delete">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="id" id="op_delete_id" value="">
            </div>
            <div class="pm-mf"><span></span><div class="pm-r">
                <button type="button" class="pm-btn" data-pm-close><?= h(__('common.cancel')) ?></button>
                <button type="submit" class="pm-btn pm-btn-danger-solid" data-pm-focus><?= h(__('operator.delete')) ?></button>
            </div></div>
        </form>
    </dialog>

    <script>
    (function () {
      var tpl = <?= json_encode(__('operator.deactivate_body'), JSON_UNESCAPED_UNICODE) ?>;
      var delTpl = <?= json_encode(__('operator.delete_body'), JSON_UNESCAPED_UNICODE) ?>;
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
      document.querySelectorAll('[data-op-edit]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          document.getElementById('op_edit_id').value = btn.getAttribute('data-op-edit') || '';
          document.getElementById('op_edit_username').value = btn.getAttribute('data-op-name') || '';
          document.getElementById('op_edit_password').value = '';
          if (window.PanelModal) window.PanelModal.open(document.getElementById('dlg-operator-edit'));
        });
      });
      document.querySelectorAll('[data-op-delete]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          var id = btn.getAttribute('data-op-delete') || '';
          var name = btn.getAttribute('data-op-name') || '';
          document.getElementById('op_delete_id').value = id;
          document.getElementById('op-delete-text').textContent =
            (delTpl || '').replace('{name}', name || '—');
          if (window.PanelModal) window.PanelModal.open(document.getElementById('dlg-operator-delete'));
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

function handleOperatorUpdate(): void
{
    $id = (int)($_POST['id'] ?? 0);
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $masterUser = (string)($_SESSION['admin_username_display'] ?? '');
    $masterId = (int)($_SESSION['admin_id'] ?? 0);

    if ($id <= 0 || $id === $masterId || $username === '' || strlen($username) > 100) {
        $password = '';
        setFlash('error', __('operator.invalid'));
        redirectTo('operator_list');
    }
    if ($password !== '' && strlen($password) < 8) {
        $password = '';
        setFlash('error', __('operator.invalid_credentials'));
        redirectTo('operator_list');
    }

    $row = fetchPanelAdminById($id);
    if ($row === null || (string)$row['role'] === 'master') {
        $password = '';
        setFlash('error', __('operator.cannot_edit'));
        redirectTo('operator_list');
    }

    try {
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $password = '';
            $stmt = getPdo()->prepare(
                'UPDATE panel_admins SET username = ?, password_hash = ?, updated_at = NOW()
                 WHERE id = ? AND role = \'admin\''
            );
            $stmt->execute([$username, $hash, $id]);
        } else {
            $stmt = getPdo()->prepare(
                'UPDATE panel_admins SET username = ?, updated_at = NOW()
                 WHERE id = ? AND role = \'admin\''
            );
            $stmt->execute([$username, $id]);
        }
    } catch (PDOException $e) {
        $password = '';
        writeLog("Panel operator_update failed by master='{$masterUser}' id={$id} ip=" . getClientIp());
        setFlash('error', __('operator.create_failed'));
        redirectTo('operator_list');
    }

    writeLog(
        "Panel operator_update by master='{$masterUser}' target='{$username}' id={$id} ip="
        . getClientIp()
    );
    setFlash('success', __('operator.updated'));
    redirectTo('operator_list');
}

function handleOperatorDelete(): void
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
        setFlash('error', __('operator.cannot_delete'));
        redirectTo('operator_list');
    }

    $pdo = getPdo();
    $pdo->prepare(
        'UPDATE panel_admins SET active = 0, updated_at = NOW() WHERE id = ? AND role = \'admin\''
    )->execute([$id]);
    $pdo->prepare('DELETE FROM panel_admins WHERE id = ? AND role = \'admin\'')->execute([$id]);

    writeLog(
        "Panel operator_delete by master='{$masterUser}' target='{$row['username']}' id={$id} ip="
        . getClientIp()
    );
    setFlash('success', __('operator.deleted'));
    redirectTo('operator_list');
}
