<?php
declare(strict_types=1);

/**
 * Operator password change and master reset (SEC-11 / DAT-9 / issue #138).
 */

function panelOperatorPasswordLengthValid(string $password): bool
{
    return $password !== '' && strlen($password) >= 8;
}

function panelAdminsHasMustChangePasswordColumn(): bool
{
    if (!panelAdminsTableExists()) {
        return false;
    }
    try {
        $stmt = getPdo()->query("SHOW COLUMNS FROM panel_admins LIKE 'must_change_password'");
        if ($stmt === false) {
            return false;
        }
        return $stmt->fetch() !== false;
    } catch (Throwable) {
        return false;
    }
}

function ensurePanelAdminsMustChangePasswordColumn(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!panelAdminsTableExists() || panelAdminsHasMustChangePasswordColumn()) {
        return;
    }

    try {
        getPdo()->exec(
            'ALTER TABLE panel_admins ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER active'
        );
        writeLog('Panel auth migration: added panel_admins.must_change_password column');
    } catch (Throwable $e) {
        writeLog(
            'Panel auth migration failed: could not add must_change_password — '
            . $e->getMessage()
        );
    }
}

function panelAdminMustChangePassword(array $adminRow): bool
{
    return (int) ($adminRow['must_change_password'] ?? 0) === 1;
}

function enforcePanelPasswordChangeRequired(): void
{
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    if ($adminId <= 0) {
        return;
    }

    $row = fetchPanelAdminById($adminId);
    if ($row === null || !panelAdminMustChangePassword($row)) {
        return;
    }

    setFlash('error', __('password.must_change_required'));
    redirectTo('password_change');
}

function renderPasswordChangeForm(): void
{
    global $flash;

    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $row = $adminId > 0 ? fetchPanelAdminById($adminId) : null;
    $forced = $row !== null && panelAdminMustChangePassword($row);
    $csrf = h((string) ($_SESSION['csrf_token'] ?? ''));

    renderHeader(__('password.change_title'));
    renderPanelModalStyles();
    ?>
    <div class="pm-head">
        <h1><?= h(__('password.change_heading')) ?></h1>
    </div>
    <?php if ($forced): ?>
        <p class="pm-hint"><?= h(__('password.must_change_banner')) ?></p>
    <?php else: ?>
        <p class="pm-hint"><?= h(__('password.change_hint')) ?></p>
    <?php endif; ?>
    <div class="pm-card" style="max-width:480px">
        <form method="post" action="/index.php">
            <input type="hidden" name="action" value="password_change_submit">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="pm-fg">
                <div class="pm-f pm-full">
                    <label for="pw_current"><?= h(__('password.current')) ?></label>
                    <input type="password" id="pw_current" name="current_password" required
                           autocomplete="current-password">
                </div>
                <div class="pm-f pm-full">
                    <label for="pw_new"><?= h(__('password.new')) ?></label>
                    <input type="password" id="pw_new" name="new_password" required minlength="8"
                           autocomplete="new-password">
                    <span class="pm-help"><?= h(__('operator.password_hint')) ?></span>
                </div>
            </div>
            <div class="pm-mf" style="margin-top:16px">
                <span></span>
                <div class="pm-r">
                    <?php if (!$forced): ?>
                        <a class="pm-btn" href="/index.php?action=dashboard"><?= h(__('common.cancel')) ?></a>
                    <?php endif; ?>
                    <button type="submit" class="pm-btn pm-btn-primary"><?= h(__('password.submit')) ?></button>
                </div>
            </div>
        </form>
    </div>
    <?php
    renderFooter();
}

function handlePasswordChangeSubmit(): void
{
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $user = (string) ($_SESSION['admin_username_display'] ?? '');
    $ip = getClientIp();

    if ($adminId <= 0) {
        redirectTo('login');
    }

    $row = fetchPanelAdminById($adminId);
    if ($row === null || !(int) $row['active']) {
        clearPanelSession();
        redirectTo('login');
    }

    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');

    if ($current === '' || !panelOperatorPasswordLengthValid($new)) {
        $current = '';
        $new = '';
        writeLog("Panel password_change rejected for user='{$user}' ip={$ip} reason=invalid_input");
        setFlash('error', __('password.invalid'));
        redirectTo('password_change');
    }

    if (!password_verify($current, (string) $row['password_hash'])) {
        $current = '';
        $new = '';
        writeLog("Panel password_change rejected for user='{$user}' ip={$ip} reason=bad_current");
        setFlash('error', __('password.bad_current'));
        redirectTo('password_change');
    }

    $current = '';

    if (password_verify($new, (string) $row['password_hash'])) {
        $new = '';
        writeLog("Panel password_change rejected for user='{$user}' ip={$ip} reason=same_as_current");
        setFlash('error', __('password.same_as_current'));
        redirectTo('password_change');
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $new = '';

    $stmt = getPdo()->prepare(
        'UPDATE panel_admins SET password_hash = ?, must_change_password = 0 WHERE id = ? AND active = 1'
    );
    $stmt->execute([$hash, $adminId]);

    writeLog("Panel password_change success for user='{$user}' ip={$ip}");
    setFlash('success', __('password.changed'));
    redirectTo('dashboard');
}

function handleOperatorResetPassword(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $new = (string) ($_POST['password'] ?? '');
    $masterUser = (string) ($_SESSION['admin_username_display'] ?? '');
    $ip = getClientIp();

    if ($id <= 0 || !panelOperatorPasswordLengthValid($new)) {
        $new = '';
        setFlash('error', __('operator.invalid_credentials'));
        redirectTo('operator_list');
    }

    $row = fetchPanelAdminById($id);
    if ($row === null || (string) $row['role'] === 'master' || !(int) $row['active']) {
        $new = '';
        setFlash('error', __('operator.cannot_reset'));
        redirectTo('operator_list');
    }

    $targetUser = (string) $row['username'];
    $hash = password_hash($new, PASSWORD_DEFAULT);
    $new = '';

    $stmt = getPdo()->prepare(
        'UPDATE panel_admins SET password_hash = ?, must_change_password = 1
         WHERE id = ? AND role = \'admin\' AND active = 1'
    );
    $stmt->execute([$hash, $id]);

    if ($stmt->rowCount() < 1) {
        writeLog(
            "Panel operator_reset_password failed by master='{$masterUser}' target='{$targetUser}' id={$id} ip={$ip}"
        );
        setFlash('error', __('operator.reset_failed'));
        redirectTo('operator_list');
    }

    writeLog(
        "Panel operator_reset_password by master='{$masterUser}' target='{$targetUser}' id={$id} ip={$ip}"
    );
    setFlash('success', __('operator.reset_success'));
    redirectTo('operator_list');
}
