<?php
declare(strict_types=1);

// IP allow-list before session_start() so a 403 never emits Set-Cookie (PROMPT-29).
require_once __DIR__ . '/includes/helpers.php';

$action = $_POST['action'] ?? $_GET['action'] ?? 'dashboard';

if ($action !== 'oauth_callback') {
    checkLocalNetworkAccess();
}

startPanelSession();

require_once __DIR__ . '/includes/i18n.php';
initPanelI18n();

// CSRF-токен — генерируется один раз за сессию
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Подключение централизованного файла конфигурации общих констант
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Cryptor.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/panel_migration.php';
require_once __DIR__ . '/includes/panel_auth_ui.php';
require_once __DIR__ . '/includes/oauth2.php';
require_once __DIR__ . '/includes/providers_ui.php';
require_once __DIR__ . '/includes/maildir_resolver.php';
require_once __DIR__ . '/includes/panel_local_mail.php';

use MailProxy\Cryptor;

sanitizeLegacyPanelSession();
bootstrapPanelAuth();

// Pre-auth actions: reachable without admin session, still behind IP allow-list
// (except oauth_callback, which skips the IP check but still requires admin_id below).
$preAuthActions = ['login', 'login_submit'];

// CSRF-защита для всех POST-действий (кроме OAuth callback — GET-запрос)
$postActionsRequiringCsrf = [
    'login_submit',
    'logout',
    'referent_save',
    'referent_delete',
    'account_save',
    'account_delete',
    'toggle_active',
    'provider_save',
    'provider_toggle',
    'oauth_initiate',
    'operator_create',
    'operator_deactivate',
];

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && in_array($action, $postActionsRequiringCsrf, true)
) {
    requireValidCsrfToken();
}

if (!in_array($action, $preAuthActions, true)) {
    // Includes oauth_callback: requires active $_SESSION['admin_id'] (any role).
    requirePanelAdmin();
}

if (in_array($action, ['operator_list', 'operator_create', 'operator_deactivate'], true)) {
    requireMasterAdmin();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function redirectTo(string $action, array $params = []): void
{
    $params['action'] = $action;
    header('Location: index.php?' . http_build_query($params));
    exit();
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function renderHeader(string $title): void
{
    global $flash, $action;

    ?>
<!DOCTYPE html>
<html lang="<?= h(panelHtmlLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?> — <?= h(__('app.title_suffix')) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .lang-link { color: #94a3b8; font-size: 0.75rem; text-decoration: none; }
        .lang-link:hover { color: #fff; }
        .lang-active { color: #fff; font-size: 0.75rem; font-weight: 600; }
        .lang-sep { color: #64748b; font-size: 0.75rem; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
<div class="flex min-h-screen">

    <aside class="w-64 bg-slate-800 text-white">
        <div class="p-6 border-b border-slate-700">
            <h1 class="text-xl font-bold"><?= h(__('app.name')) ?></h1>
            <?php if (!empty($_SESSION['admin_username_display'])): ?>
                <p class="text-xs text-slate-300 mt-2"><?= h((string)$_SESSION['admin_username_display']) ?></p>
            <?php endif; ?>
            <div class="mt-3" aria-label="<?= h(__('common.language')) ?>">
                <?php renderLanguageSelector(); ?>
            </div>
        </div>

        <nav class="p-4 space-y-2">
            <a href="/index.php?action=dashboard"
               <?= ($action ?? '') === 'dashboard' ? 'class="active font-semibold text-white"' : 'class="text-slate-300 hover:text-white"' ?>><?= h(__('nav.dashboard')) ?></a>
            <a href="/index.php?action=referent_list"
               <?= in_array($action ?? '', ['referent_list', 'referents', 'referent_form', 'referent_view'], true) ? 'class="active font-semibold text-white"' : 'class="text-slate-300 hover:text-white"' ?>><?= h(__('nav.referents')) ?></a>
            <a href="/index.php?action=account_list"
               <?= in_array($action ?? '', ['account_list', 'accounts', 'account_form'], true) ? 'class="active font-semibold text-white"' : 'class="text-slate-300 hover:text-white"' ?>><?= h(__('nav.accounts')) ?></a>
            <a href="/index.php?action=provider_list"
               <?= in_array($action ?? '', ['provider_list', 'provider_form', 'providers'], true) ? 'class="active font-semibold text-white"' : 'class="text-slate-300 hover:text-white"' ?>><?= h(__('nav.providers')) ?></a>
            <a href="/monitor.php"
               <?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'monitor.php' ? 'class="active font-semibold text-white"' : 'class="text-slate-300 hover:text-white"' ?>>
               <?= h(__('nav.monitor')) ?>
            </a>
            <a href="/logs.php"
               <?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'logs.php' ? 'class="active font-semibold text-white"' : 'class="text-slate-300 hover:text-white"' ?>>
               Логи
            </a>
            <?php if (isPanelMasterDisplay()): ?>
            <a href="/index.php?action=operator_list"
               <?= in_array($action ?? '', ['operator_list'], true) ? 'class="active"' : '' ?>>
               <?= h(__('nav.operators')) ?>
            </a>
            <?php endif; ?>
            <form method="post" action="/index.php" class="pt-4">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                <button type="submit" class="text-left text-slate-300 hover:text-white text-sm"><?= h(__('nav.logout')) ?></button>
            </form>
        </nav>
    </aside>

    <main class="flex-1 p-6">
        <?php if ($flash): ?>
            <div class="<?= $flash['type'] === 'success'
                ? 'bg-green-100 border border-green-400 text-green-700'
                : 'bg-red-100 border border-red-400 text-red-700' ?> px-4 py-3 rounded mb-6">
                <?= h((string)$flash['message']) ?>
            </div>
        <?php endif; ?>
    <?php
}

function renderFooter(): void
{
    ?>
    </main>
</div>
</body>
</html>
<?php
}

switch ($action) {
    case 'login':
        renderLoginForm();
        break;

    case 'login_submit':
        handleLoginSubmit();
        break;

    case 'logout':
        handleLogout();
        break;

    case 'operator_list':
        renderOperatorList();
        break;

    case 'operator_create':
        handleOperatorCreate();
        break;

    case 'operator_deactivate':
        handleOperatorDeactivate();
        break;

    case 'dashboard':
        renderDashboard();
        break;

    case 'referent_list':
    case 'referents':
        renderReferentList();
        break;

    case 'account_list':
    case 'accounts':
        renderAccountList();
        break;

    case 'referent_form':
        renderReferentForm();
        break;

    case 'referent_save':
        handleReferentSave();
        break;

    case 'referent_delete':
        handleReferentDelete();
        break;

    case 'referent_view':
        renderReferentView();
        break;

    case 'account_form':
        renderAccountForm();
        break;

    case 'account_save':
        handleAccountSave();
        break;

    case 'account_delete':
        handleAccountDelete();
        break;

    case 'toggle_active':
        handleToggleActive();
        break;

    case 'providers':
    case 'provider_list':
        renderProviderList();
        break;

    case 'provider_form':
        renderProviderForm(isset($_GET['id']) ? (int)$_GET['id'] : null);
        break;

    case 'provider_save':
        handleProviderSave();
        break;
		
	case 'provider_toggle':
        handleProviderToggle();
        break;
		
    case 'oauth_initiate':
        \MailProxy\initiateOAuth2((int)($_POST['account_id'] ?? 0));
        break;

    case 'oauth_callback':
        \MailProxy\handleOAuth2Callback();
        break;

    default:
        renderDashboard();
        break;
}

// ======== Dashboard Function ========

function renderDashboard(): void
{
    $pdo = getPdo();

    $stmt = $pdo->prepare(
		'SELECT r.id, r.username, r.local_inbox, r.local_outbox, r.active as r_active,
				c.id as client_id, c.email as client_email, c.active as c_active,
				ea.id as ea_id, ea.email as ea_email, ea.username as ea_username,
				ea.auth_type, ea.provider,
				ea.imap_host, ea.imap_port, ea.imap_encryption,
				ea.smtp_host, ea.smtp_port, ea.smtp_encryption,
				ea.active as ea_active,
				ot.expires_at, ot.updated_at as token_updated
		 FROM referents r
		 LEFT JOIN clients c ON c.referent_id = r.id
		 LEFT JOIN external_accounts ea ON ea.referent_id = r.id
		 LEFT JOIN oauth_tokens ot ON ot.account_id = ea.id
		 ORDER BY r.id'
	);
    $stmt->execute();

    $rows = $stmt->fetchAll();

    renderHeader(__('dashboard.title'));
    ?>
    <h2 class="text-2xl font-bold mb-6"><?= h(__('dashboard.title')) ?></h2>
    <div class="flex flex-wrap gap-3 mb-6">
        <a href="index.php?action=referent_list" class="bg-slate-700 text-white px-4 py-2 rounded">Референты →</a>
        <a href="index.php?action=account_list" class="bg-slate-700 text-white px-4 py-2 rounded">Внешние аккаунты →</a>
        <a href="index.php?action=referent_form" class="bg-blue-600 text-white px-4 py-2 rounded"><?= h(__('dashboard.create_referent')) ?></a>
    </div>
    <div class="bg-white rounded shadow overflow-x-auto">
        <table class="min-w-full">
            <thead class="bg-slate-100">
            <tr>
                <th class="px-4 py-2"><?= h(__('dashboard.col_referent')) ?></th>
                <th class="px-4 py-2"><?= h(__('dashboard.col_client')) ?></th>
                <th class="px-4 py-2"><?= h(__('dashboard.col_external')) ?></th>
                <th class="px-4 py-2"><?= h(__('dashboard.col_imap')) ?></th>
                <th class="px-4 py-2"><?= h(__('dashboard.col_smtp')) ?></th>
                <th class="px-4 py-2"><?= h(__('dashboard.col_auth')) ?></th>
                <th class="px-4 py-2"><?= h(__('dashboard.col_token_status')) ?></th>
                <th class="px-4 py-2"><?= h(__('dashboard.col_activity')) ?></th>
                <th class="px-4 py-2"><?= h(__('common.actions')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr class="border-t">
                    <td class="px-4 py-2"><?= h($row['username']) ?></td>
                    <td class="px-4 py-2"><?= h((string)$row['client_email']) ?></td>
                    <td class="px-4 py-2"><?= h((string)$row['ea_email']) ?></td>
                    <td class="px-4 py-2"><?= h((string)$row['imap_host']) ?>:<?= h((string)$row['imap_port']) ?></td>
                    <td class="px-4 py-2"><?= h((string)$row['smtp_host']) ?>:<?= h((string)$row['smtp_port']) ?></td>
                    <td class="px-4 py-2"><?= h($row['auth_type']) ?></td>
                    <td class="px-4 py-2">
                        <?php
                        if ($row['auth_type'] === 'oauth2' && $row['expires_at']) {
                            $expires = strtotime($row['expires_at']);
                            echo $expires > time()
                                ? h(__('dashboard.token_active_until', ['date' => $row['expires_at']]))
                                : h(__('dashboard.token_expired'));
                        } else {
                            echo h(__('common.dash'));
                        }
                        ?>
                    </td>
                    <td class="px-4 py-2">
                        <?= (int)$row['r_active'] === 1 ? h(__('dashboard.referent_on')) : h(__('dashboard.referent_off')) ?><br>
                        <?= (int)$row['c_active'] === 1 ? h(__('dashboard.client_on')) : h(__('dashboard.client_off')) ?><br>
                        <?= (int)$row['ea_active'] === 1 ? h(__('dashboard.account_on')) : h(__('dashboard.account_off')) ?>
                    </td>
                            <?php renderReferentRowActions($row, 'dashboard'); ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    renderFooter();
}

function renderReferentList(): void
{
    $pdo = getPdo();
    $stmt = $pdo->query(
        'SELECT r.id, r.username, r.local_inbox, r.local_outbox, r.active as r_active,
                c.id as client_id, c.email as client_email, c.active as c_active,
                ea.id as ea_id, ea.email as ea_email, ea.active as ea_active
         FROM referents r
         LEFT JOIN clients c ON c.referent_id = r.id
         LEFT JOIN external_accounts ea ON ea.referent_id = r.id
         ORDER BY r.id'
    );
    $rows = $stmt->fetchAll();

    renderHeader(__('nav.referents'));
    ?>
    <h2 class="text-2xl font-bold mb-2"><?= h(__('nav.referents')) ?></h2>
    <p class="text-slate-600 mb-6">Все референты в системе. Референт — локальный почтовый ящик на iRedMail, связанный с внешним аккаунтом.</p>
    <div class="mb-6">
        <a href="index.php?action=referent_form" class="bg-blue-600 text-white px-4 py-2 rounded">Создать референта</a>
    </div>
    <?php if ($rows === []): ?>
        <div class="bg-white rounded shadow p-8 text-center text-slate-600">
            Референтов пока нет. <a href="index.php?action=referent_form" class="text-blue-600 underline">Создать первого</a>
        </div>
    <?php else: ?>
    <div class="bg-white rounded shadow overflow-x-auto">
        <table class="min-w-full">
            <thead class="bg-slate-100">
            <tr>
                <th class="px-4 py-2 text-left">ID</th>
                <th class="px-4 py-2 text-left">Имя</th>
                <th class="px-4 py-2 text-left">Email (local_inbox)</th>
                <th class="px-4 py-2 text-left">Клиент</th>
                <th class="px-4 py-2 text-left">Статус</th>
                <th class="px-4 py-2 text-left">Действия</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr class="border-t">
                    <td class="px-4 py-2"><?= (int)$row['id'] ?></td>
                    <td class="px-4 py-2"><?= h((string)$row['username']) ?></td>
                    <td class="px-4 py-2 font-mono text-sm"><?= h((string)$row['local_inbox']) ?></td>
                    <td class="px-4 py-2"><?= h((string)($row['client_email'] ?: '—')) ?></td>
                    <td class="px-4 py-2">
                        <?= (int)$row['r_active'] === 1 ? 'Активен' : 'Отключён' ?>
                    </td>
                    <td class="px-4 py-2"><?php renderReferentRowActions($row, 'referent_list'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif;
    renderFooter();
}

function renderAccountList(): void
{
    $pdo = getPdo();
    $stmt = $pdo->query(
        'SELECT ea.id, ea.referent_id, ea.email, ea.username, ea.auth_type, ea.provider,
                ea.imap_host, ea.imap_port, ea.smtp_host, ea.smtp_port, ea.active as ea_active,
                r.username as referent_name, r.local_inbox,
                ot.expires_at
         FROM external_accounts ea
         INNER JOIN referents r ON r.id = ea.referent_id
         LEFT JOIN oauth_tokens ot ON ot.account_id = ea.id
         ORDER BY ea.id'
    );
    $rows = $stmt->fetchAll();

    renderHeader(__('nav.accounts'));
    ?>
    <h2 class="text-2xl font-bold mb-2"><?= h(__('nav.accounts')) ?></h2>
    <p class="text-slate-600 mb-6">Внешние почтовые аккаунты (IMAP/SMTP или OAuth2). Демон использует их для синхронизации с локальным ящиком референта.</p>
    <?php if ($rows === []): ?>
        <div class="bg-white rounded shadow p-8 text-center text-slate-600">
            Внешних аккаунтов нет.
            <?php
            $refStmt = $pdo->query('SELECT id, username FROM referents ORDER BY id LIMIT 1');
            $firstRef = $refStmt->fetch();
            if ($firstRef): ?>
                <a href="index.php?action=account_form&referent_id=<?= (int)$firstRef['id'] ?>" class="text-blue-600 underline">Создать для референта «<?= h((string)$firstRef['username']) ?>»</a>
            <?php else: ?>
                Сначала <a href="index.php?action=referent_form" class="text-blue-600 underline">создайте референта</a>.
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="bg-white rounded shadow overflow-x-auto">
        <table class="min-w-full">
            <thead class="bg-slate-100">
            <tr>
                <th class="px-4 py-2 text-left">ID</th>
                <th class="px-4 py-2 text-left">Email</th>
                <th class="px-4 py-2 text-left">Референт</th>
                <th class="px-4 py-2 text-left">IMAP</th>
                <th class="px-4 py-2 text-left">SMTP</th>
                <th class="px-4 py-2 text-left">Auth</th>
                <th class="px-4 py-2 text-left">Статус</th>
                <th class="px-4 py-2 text-left">Действия</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr class="border-t">
                    <td class="px-4 py-2"><?= (int)$row['id'] ?></td>
                    <td class="px-4 py-2 font-mono text-sm"><?= h((string)$row['email']) ?></td>
                    <td class="px-4 py-2">
                        <a href="index.php?action=referent_view&id=<?= (int)$row['referent_id'] ?>" class="text-blue-600 hover:underline">
                            <?= h((string)$row['referent_name']) ?>
                        </a>
                        <div class="text-xs text-slate-500"><?= h((string)$row['local_inbox']) ?></div>
                    </td>
                    <td class="px-4 py-2 text-sm"><?= h((string)$row['imap_host']) ?>:<?= (int)$row['imap_port'] ?></td>
                    <td class="px-4 py-2 text-sm"><?= h((string)$row['smtp_host']) ?>:<?= (int)$row['smtp_port'] ?></td>
                    <td class="px-4 py-2"><?= h((string)$row['auth_type']) ?></td>
                    <td class="px-4 py-2"><?= (int)$row['ea_active'] === 1 ? 'Активен' : 'Отключён' ?></td>
                    <td class="px-4 py-2"><?php renderAccountRowActions($row, 'account_list'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif;
    renderFooter();
}

/**
 * @param array<string, mixed> $row
 */
function renderReferentRowActions(array $row, string $returnAction): void
{
    $id = (int)$row['id'];
    ?>
    <div class="flex gap-2 flex-wrap">
        <a class="bg-indigo-600 text-white px-3 py-1 rounded text-sm"
           href="index.php?action=referent_view&id=<?= $id ?>">Просмотр</a>
        <a class="bg-blue-600 text-white px-3 py-1 rounded text-sm"
           href="index.php?action=referent_form&id=<?= $id ?>">Редактировать</a>
        <a class="bg-amber-500 text-white px-3 py-1 rounded text-sm"
           href="index.php?action=account_form&referent_id=<?= $id ?><?= !empty($row['ea_id']) ? '&account_id=' . (int)$row['ea_id'] : '' ?>">
            <?= !empty($row['ea_id']) ? 'Внешний аккаунт' : 'Создать аккаунт' ?>
        </a>
    </div>
    <div class="flex gap-2 flex-wrap mt-2">
        <?php renderEntityToggleButton('referent', $id, (int)$row['r_active'], 'Реф.', $returnAction); ?>
        <?php if (!empty($row['client_id'])): ?>
            <?php renderEntityToggleButton('client', (int)$row['client_id'], (int)$row['c_active'], 'Клиент', $returnAction); ?>
        <?php endif; ?>
        <?php if (!empty($row['ea_id'])): ?>
            <?php renderEntityToggleButton('account', (int)$row['ea_id'], (int)$row['ea_active'], 'Внешн.', $returnAction); ?>
        <?php endif; ?>
    </div>
    <div class="flex gap-2 flex-wrap mt-2">
        <form method="post" action="index.php?action=referent_delete" class="inline"
              onsubmit="return confirm('Удалить референта <?= h((string)$row['username']) ?> и все связанные записи?');">
            <input type="hidden" name="action" value="referent_delete">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="return_action" value="<?= h($returnAction) ?>">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
            <button type="submit" class="bg-red-800 text-white px-3 py-1 rounded text-sm">Удалить</button>
        </form>
    </div>
    <?php
}

/**
 * @param array<string, mixed> $row
 */
function renderAccountRowActions(array $row, string $returnAction): void
{
    $accountId = (int)$row['id'];
    $referentId = (int)$row['referent_id'];
    ?>
    <div class="flex gap-2 flex-wrap">
        <a class="bg-blue-600 text-white px-3 py-1 rounded text-sm"
           href="index.php?action=account_form&referent_id=<?= $referentId ?>&account_id=<?= $accountId ?>">Редактировать</a>
        <a class="bg-indigo-600 text-white px-3 py-1 rounded text-sm"
           href="index.php?action=referent_view&id=<?= $referentId ?>">Референт</a>
    </div>
    <div class="flex gap-2 flex-wrap mt-2">
        <?php renderEntityToggleButton('account', $accountId, (int)$row['ea_active'], 'Аккаунт', $returnAction); ?>
        <form method="post" action="index.php?action=account_delete" class="inline"
              onsubmit="return confirm('Удалить внешний аккаунт <?= h((string)$row['email']) ?>?');">
            <input type="hidden" name="action" value="account_delete">
            <input type="hidden" name="id" value="<?= $accountId ?>">
            <input type="hidden" name="referent_id" value="<?= $referentId ?>">
            <input type="hidden" name="return_action" value="<?= h($returnAction) ?>">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
            <button type="submit" class="bg-red-600 text-white px-3 py-1 rounded text-sm">Удалить</button>
        </form>
    </div>
    <?php
}

function renderReferentForm(): void
{
    $pdo = getPdo();

    $referent = [
        'id' => '',
        'username' => '',
        'local_inbox' => '',
        'local_outbox' => '',
        'active' => 1,
    ];

    $client = [
        'email' => '',
        'active' => 1,
    ];

    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM referents
             WHERE id = ?'
        );
        $stmt->execute([(int)$_GET['id']]);

        $row = $stmt->fetch();

        if ($row) {
            $referent = $row;

            $stmt = $pdo->prepare(
                'SELECT *
                 FROM clients
                 WHERE referent_id = ?'
            );
            $stmt->execute([(int)$referent['id']]);

            $clientRow = $stmt->fetch();

            if ($clientRow) {
                $client = $clientRow;
            }
        }
    }

    renderHeader(__('referent.title'));

    ?>
    <div class="mb-4">
        <a href="index.php?action=referent_list" class="text-blue-600 hover:underline">← К списку референтов</a>
    </div>
    <h2 class="text-2xl font-bold mb-6">
        <?= !empty($referent['id']) ? h(__('referent.edit')) : h(__('referent.new')) ?>
    </h2>

    <form method="post" action="index.php?action=referent_save" class="bg-white rounded shadow p-6 space-y-4">
        <input type="hidden" name="action" value="referent_save">
        <input type="hidden" name="id" value="<?= h((string)$referent['id']) ?>">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">

        <div>
            <label class="block mb-1 font-medium"><?= h(__('referent.display_name')) ?></label>
            <input
                type="text"
                name="username"
                required
                class="w-full border rounded px-3 py-2"
                value="<?= h((string)$referent['username']) ?>"
            >
            <p class="text-sm text-gray-600 mt-1"><?= h(__('referent.display_name_hint')) ?></p>
        </div>

        <div>
            <label class="block mb-1 font-medium"><?= h(__('referent.email')) ?></label>
            <input
                type="email"
                name="local_inbox"
                required
                class="w-full border rounded px-3 py-2"
                placeholder="<?= h(__('referent.email_placeholder')) ?>"
                value="<?= h((string)$referent['local_inbox']) ?>"
            >
            <p class="text-sm text-gray-600 mt-1">
                <?= h(__('referent.email_hint')) ?>
            </p>
        </div>

        <?php if (!empty($referent['local_outbox'])): ?>
        <div>
            <label class="block mb-1 font-medium"><?= h(__('referent.maildir_auto')) ?></label>
            <input
                type="text"
                readonly
                class="w-full border rounded px-3 py-2 bg-slate-50 text-slate-700"
                value="<?= h((string)$referent['local_outbox']) ?>"
                aria-readonly="true"
            >
        </div>
        <?php endif; ?>

        <div>
            <label class="inline-flex items-center gap-2">
                <input
                    type="checkbox"
                    name="active"
                    value="1"
                    <?= (int)$referent['active'] === 1 ? 'checked' : '' ?>
                >
                <span><?= h(__('referent.active')) ?></span>
            </label>
        </div>

        <hr>

        <h3 class="text-lg font-semibold"><?= h(__('referent.client_section')) ?></h3>

        <div>
            <label class="block mb-1 font-medium"><?= h(__('referent.client_email')) ?></label>
            <input
                type="email"
                name="client_email"
                class="w-full border rounded px-3 py-2"
                value="<?= h((string)$client['email']) ?>"
            >
        </div>

        <div>
            <label class="inline-flex items-center gap-2">
                <input
                    type="checkbox"
                    name="client_active"
                    value="1"
                    <?= (int)$client['active'] === 1 ? 'checked' : '' ?>
                >
                <span><?= h(__('referent.client_active')) ?></span>
            </label>
        </div>

        <button
            type="submit"
            class="bg-blue-600 text-white px-6 py-2 rounded">
            <?= h(__('common.save')) ?>
        </button>
    </form>
    <?php

    renderFooter();
}

function handleReferentSave(): void
{
    $pdo = getPdo();

    $id = (int)($_POST['id'] ?? 0);

    $username = trim((string)($_POST['username'] ?? ''));
    $localInbox = trim((string)($_POST['local_inbox'] ?? ''));

    $active = isset($_POST['active']) ? 1 : 0;

    $clientEmail = trim((string)($_POST['client_email'] ?? ''));
    $clientActive = isset($_POST['client_active']) ? 1 : 0;

    $existingInbox = '';
    $existingOutbox = '';

    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT local_inbox, local_outbox FROM referents WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if ($existing) {
            $existingInbox = strtolower(trim((string)$existing['local_inbox']));
            $existingOutbox = trim((string)$existing['local_outbox']);
        }
    }

    try {
        $normalizedInbox = normalizeReferentEmail($localInbox);
    } catch (ReferentMaildirException $e) {
        setFlash('error', $e->getUserMessage());
        header('Location: index.php?action=referent_form' . ($id > 0 ? '&id=' . $id : ''));
        exit();
    }

    if ($id > 0 && $normalizedInbox === $existingInbox && $existingOutbox !== '') {
        $localOutbox = $existingOutbox;
    } else {
        try {
            $localOutbox = resolveReferentMaildir($normalizedInbox);
        } catch (ReferentMaildirException $e) {
            setFlash('error', $e->getUserMessage());
            header('Location: index.php?action=referent_form' . ($id > 0 ? '&id=' . $id : ''));
            exit();
        }
    }

    $localInbox = $normalizedInbox;

    try {
        $pdo->beginTransaction();

        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE referents
                 SET username = ?,
                     local_inbox = ?,
                     local_outbox = ?,
                     active = ?,
                     updated_at = NOW()
                 WHERE id = ?'
            );

            $stmt->execute([
                $username,
                $localInbox,
                $localOutbox,
                $active,
                $id,
            ]);

            $referentId = $id;

            writeLog("Referent updated: ID {$referentId}");
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO referents
                (
                    username,
                    local_inbox,
                    local_outbox,
                    active
                )
                VALUES (?, ?, ?, ?)'
            );

            $stmt->execute([
                $username,
                $localInbox,
                $localOutbox,
                $active,
            ]);

            $referentId = (int)$pdo->lastInsertId();

            writeLog("Referent created: ID {$referentId}");
        }

        if ($clientEmail !== '') {
            $stmt = $pdo->prepare(
                'SELECT id
                 FROM clients
                 WHERE referent_id = ?'
            );
            $stmt->execute([$referentId]);

            $clientRow = $stmt->fetch();

            if ($clientRow) {
                $stmt = $pdo->prepare(
                    'UPDATE clients
                     SET email = ?,
                         active = ?,
                         updated_at = NOW()
                     WHERE referent_id = ?'
                );

                $stmt->execute([
                    $clientEmail,
                    $clientActive,
                    $referentId,
                ]);

                writeLog("Client updated for referent {$referentId}");
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO clients
                    (
                        email,
                        referent_id,
                        active
                    )
                    VALUES (?, ?, ?)'
                );

                $stmt->execute([
                    $clientEmail,
                    $referentId,
                    $clientActive,
                ]);

                writeLog("Client created for referent {$referentId}");
            }
        // Если email пустой — ничего не делать с clients (не удалять)
		}
        $pdo->commit();

        setFlash('success', __('referent.saved'));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        writeLog('Referent save error: ' . $e->getMessage());

        setFlash('error', exceptionUserMessage($e));
    }

    redirectTo('referent_list');
}
function renderAccountForm(): void
{
    $pdo = getPdo();

    $referentId = (int)($_GET['referent_id'] ?? 0);

    if ($referentId <= 0) {
        setFlash('error', __('account.referent_id_required'));
        redirectTo('account_list');
    }
	$accountId = (int)($_GET['account_id'] ?? 0);

	$account = [
		'id' => '',
		'email' => '',
		'username' => '',
		'auth_type' => 'plain',
		'provider' => '',
		'imap_host' => '',
		'imap_port' => 993,
		'imap_encryption' => 'ssl',
		'smtp_host' => '',
		'smtp_port' => 587,
		'smtp_encryption' => 'tls',
		'client_id' => '',
		'active' => 1,
	];

	if ($accountId > 0) {
		$stmt = $pdo->prepare(
			'SELECT *
			 FROM external_accounts
			 WHERE id = ?
			   AND referent_id = ?'
		);
		$stmt->execute([
			$accountId,
			$referentId,
		]);
		$existing = $stmt->fetch();

		if (!$existing) {
			setFlash('error', __('account.access_denied'));
			redirectTo('dashboard');
		}
		$account = $existing;
	}

    $stmt = $pdo->prepare(
        'SELECT *
         FROM oauth_providers
         WHERE active = 1
         ORDER BY name'
    );
    $stmt->execute();

    $providers = $stmt->fetchAll();

    renderHeader(__('account.title'));
    ?>
    <div class="mb-4">
        <a href="index.php?action=account_list" class="text-blue-600 hover:underline">← К списку аккаунтов</a>
        · <a href="index.php?action=referent_view&id=<?= $referentId ?>" class="text-blue-600 hover:underline">Референт</a>
    </div>
    <h2 class="text-2xl font-bold mb-6">
        <?= h(__('account.heading')) ?>
    </h2>

    <div class="bg-blue-50 border border-blue-200 rounded p-4 mb-6 text-sm text-blue-900">
        <p class="font-medium mb-1">Как это работает</p>
        <p>Демон периодически опрашивает внешний IMAP и доставляет новые письма в локальный ящик референта.
        Исходящая почта из Maildir отправляется через указанный SMTP. Пароли хранятся в зашифрованном виде;
        при редактировании оставьте поле пароля пустым, чтобы не менять сохранённый секрет.</p>
    </div>

    <form method="post" action="index.php?action=account_save" class="bg-white rounded shadow p-6 space-y-4">
        <input type="hidden" name="action" value="account_save">
        <input type="hidden" name="referent_id" value="<?= $referentId ?>">
        <input type="hidden" name="account_id" value="<?= h((string)$account['id']) ?>">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">

        <div>
            <label class="block mb-1"><?= h(__('account.email')) ?></label>
            <input
                type="email"
                name="email"
                required
                value="<?= h((string)$account['email']) ?>"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <div>
            <label class="block mb-1"><?= h(__('account.login')) ?></label>
            <input
                type="text"
                name="username"
                value="<?= h((string)$account['username']) ?>"
                class="w-full border rounded px-3 py-2"
            >
            <p class="text-sm text-gray-600 mt-1"><?= h(__('account.login_hint')) ?></p>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block mb-1"><?= h(__('account.imap_host')) ?></label>
                <input
                    type="text"
                    name="imap_host"
                    required
                    value="<?= h((string)$account['imap_host']) ?>"
                    class="w-full border rounded px-3 py-2"
                >
            </div>

            <div>
                <label class="block mb-1"><?= h(__('account.imap_port')) ?></label>
                <input
                    type="number"
                    name="imap_port"
                    required
                    value="<?= h((string)$account['imap_port']) ?>"
                    class="w-full border rounded px-3 py-2"
                >
            </div>
        </div>

        <div>
            <label class="block mb-1"><?= h(__('account.imap_encryption')) ?></label>
            <select name="imap_encryption" class="w-full border rounded px-3 py-2">
                <?php foreach (['none', 'ssl', 'tls'] as $v): ?>
                    <option value="<?= $v ?>"
                        <?= $account['imap_encryption'] === $v ? 'selected' : '' ?>>
                        <?= h($v) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block mb-1"><?= h(__('account.smtp_host')) ?></label>
                <input
                    type="text"
                    name="smtp_host"
                    required
                    value="<?= h((string)$account['smtp_host']) ?>"
                    class="w-full border rounded px-3 py-2"
                >
            </div>

            <div>
                <label class="block mb-1"><?= h(__('account.smtp_port')) ?></label>
                <input
                    type="number"
                    name="smtp_port"
                    required
                    value="<?= h((string)$account['smtp_port']) ?>"
                    class="w-full border rounded px-3 py-2"
                >
            </div>
        </div>

        <div>
            <label class="block mb-1"><?= h(__('account.smtp_encryption')) ?></label>
            <select name="smtp_encryption" class="w-full border rounded px-3 py-2">
                <?php foreach (['none', 'ssl', 'tls'] as $v): ?>
                    <option value="<?= $v ?>"
                        <?= $account['smtp_encryption'] === $v ? 'selected' : '' ?>>
                        <?= h($v) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block mb-2 font-medium"><?= h(__('account.auth_type')) ?></label>

            <label class="mr-4">
                <input
                    type="radio"
                    name="auth_type"
                    value="plain"
                    <?= $account['auth_type'] === 'plain' ? 'checked' : '' ?>
                >
                <?= h(__('account.auth_plain')) ?>
            </label>

            <label>
                <input
                    type="radio"
                    name="auth_type"
                    value="oauth2"
                    <?= $account['auth_type'] === 'oauth2' ? 'checked' : '' ?>
                >
                <?= h(__('account.auth_oauth2')) ?>
            </label>
        </div>

        <div>
            <label class="block mb-1"><?= h(__('account.password_plain')) ?></label>
            <input
                type="password"
                name="password"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <div>
            <label class="block mb-1"><?= h(__('account.oauth_provider')) ?></label>
            <select name="provider" class="w-full border rounded px-3 py-2">
                <option value=""><?= h(__('common.select')) ?></option>
                <?php foreach ($providers as $provider): ?>
                    <option
                        value="<?= h($provider['code']) ?>"
                        <?= $account['provider'] === $provider['code'] ? 'selected' : '' ?>>
                        <?= h($provider['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block mb-1"><?= h(__('account.client_id')) ?></label>
            <input
                type="text"
                name="client_id"
                value="<?= h((string)$account['client_id']) ?>"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <div>
            <label class="block mb-1"><?= h(__('account.client_secret')) ?></label>
            <input
                type="password"
                name="client_secret"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <div>
            <label class="inline-flex items-center gap-2">
                <input
                    type="checkbox"
                    name="active"
                    value="1"
                    <?= (int)$account['active'] === 1 ? 'checked' : '' ?>
                >
                <span><?= h(__('account.active')) ?></span>
            </label>
        </div>

		<div class="flex gap-3">
			<button
				type="submit"
				class="bg-blue-600 text-white px-6 py-2 rounded">
				<?= h(__('common.save')) ?>
			</button>

			<?php if (!empty($account['id'])): ?>
				<button
					type="submit"
					form="oauth_initiate_form"
					class="bg-green-600 text-white px-6 py-2 rounded">
					<?= h(__('account.authorize_oauth2')) ?>
				</button>
			<?php endif; ?>
		</div>
    </form>
	
    <?php if (!empty($account['id'])): ?>
        <form id="oauth_initiate_form" method="post" action="index.php" class="hidden">
            <input type="hidden" name="action" value="oauth_initiate">
            <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
        </form>
    <?php endif; ?>

    <?php
    renderFooter();
}
function handleAccountSave(): void
{
    $pdo = getPdo();
    $cryptor = new MailProxy\Cryptor();

    $referentId = (int)($_POST['referent_id'] ?? 0);
    $accountId = (int)($_POST['account_id'] ?? 0);

    if ($referentId <= 0) {
        setFlash('error', __('account.referent_id_required'));
        redirectTo('account_list');
    }

    $email = trim((string)($_POST['email'] ?? ''));
	
    // Если email обязателен и он пустой, ЛИБО если он заполнен, но некорректен:
	
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setFlash('error', __('account.invalid_email'));
        redirectTo('dashboard');
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $imapHost = trim((string)($_POST['imap_host'] ?? ''));
    $imapPort = (int)($_POST['imap_port'] ?? 993);
    $imapEncryption = $_POST['imap_encryption'] ?? 'ssl';
    $smtpHost = trim((string)($_POST['smtp_host'] ?? ''));
    $smtpPort = (int)($_POST['smtp_port'] ?? 587);
    $smtpEncryption = $_POST['smtp_encryption'] ?? 'tls';
    $authType = $_POST['auth_type'] ?? 'plain';
    $provider = trim((string)($_POST['provider'] ?? ''));
    $clientId = trim((string)($_POST['client_id'] ?? ''));
    $clientSecret = trim((string)($_POST['client_secret'] ?? ''));
	$password = trim((string)($_POST['password'] ?? ''));
    $active = isset($_POST['active']) ? 1 : 0;

    $passwordEnc = null;
	$clientSecretEnc = null;

	if ($authType === 'plain' && $password !== '') {
		$passwordEnc = $cryptor->encrypt($password);
	}

	if ($authType === 'oauth2' && $clientSecret !== '') {
		$clientSecretEnc = $cryptor->encrypt($clientSecret);
	}

    if ($accountId === 0) {
        if ($authType === 'plain' && $password === '') {
            setFlash('error', 'Для plain-авторизации необходимо указать пароль при создании аккаунта');
            header('Location: index.php?action=account_form&referent_id=' . $referentId);
            exit();
        }
        if ($authType === 'oauth2' && ($clientId === '' || $clientSecret === '')) {
            setFlash('error', 'Для OAuth2 необходимо указать Client ID и Client Secret при создании аккаунта');
            header('Location: index.php?action=account_form&referent_id=' . $referentId);
            exit();
        }
    }

    try {
        if ($accountId > 0) {
            $stmt = $pdo->prepare(
				'UPDATE external_accounts
				SET email = ?,
					username = ?,
					auth_type = ?,
					provider = ?,
					password_enc = COALESCE(?, password_enc),
					client_id = ?,
					client_secret_enc = COALESCE(?, client_secret_enc),
					imap_host = ?,
					imap_port = ?,
					imap_encryption = ?,
					smtp_host = ?,
					smtp_port = ?,
					smtp_encryption = ?,
					active = ?,
					updated_at = NOW()
				WHERE id = ?
				  AND referent_id = ?'
			);

            $stmt->execute([
                $email,
                $username,
                $authType,
                $provider,
                $passwordEnc,
                $clientId,
                $clientSecretEnc,
                $imapHost,
                $imapPort,
                $imapEncryption,
                $smtpHost,
                $smtpPort,
                $smtpEncryption,
                $active,
                $accountId,
                $referentId,
            ]);

            if ($stmt->rowCount() === 0) {
                setFlash('error', __('account.access_denied'));
                redirectTo('dashboard');
            }

            writeLog("External account updated: ID {$accountId}");
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO external_accounts
                (
                    referent_id,
                    email,
                    username,
                    auth_type,
                    provider,
                    password_enc,
                    client_id,
                    client_secret_enc,
                    imap_host,
                    imap_port,
                    imap_encryption,
                    smtp_host,
                    smtp_port,
                    smtp_encryption,
                    active
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $referentId,
                $email,
                $username,
                $authType,
                $provider,
                $passwordEnc,
                $clientId,
                $clientSecretEnc,
                $imapHost,
                $imapPort,
                $imapEncryption,
                $smtpHost,
                $smtpPort,
                $smtpEncryption,
                $active,
            ]);

            $accountId = (int)$pdo->lastInsertId();

            writeLog("External account created: ID {$accountId}");
        }

        setFlash('success', __('account.saved'));
    } catch (Throwable $e) {
        writeLog('External account save error: ' . $e->getMessage());
        setFlash('error', exceptionUserMessage($e));
    }

    redirectTo('account_list');
}

function handleToggleActive(): void
{
    $pdo = getPdo();

    $entity = $_POST['entity'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if (!in_array($entity, ['referent', 'client', 'account', 'provider'], true) || $id <= 0) {
        setFlash('error', __('error.invalid_entity'));
        redirectTo('dashboard');
    }

    $tableMap = [
		'referent' => 'referents',
		'client'   => 'clients',
		'account'  => 'external_accounts',
		'provider' => 'oauth_providers',
	];

    $table = $tableMap[$entity];

    $stmt = $pdo->prepare("SELECT active FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);

    $row = $stmt->fetch();

    if (!$row) {
        setFlash('error', __('error.record_not_found'));
        redirectTo('dashboard');
    }

    $newActive = (int)!$row['active'];

    $stmt = $pdo->prepare("UPDATE {$table} SET active = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$newActive, $id]);

    writeLog("Toggled active for {$entity} ID {$id} → {$newActive}");

    $return = (string)($_POST['return_action'] ?? 'dashboard');
    $allowedReturns = ['dashboard', 'referent_list', 'referents', 'account_list', 'accounts'];
    if (!in_array($return, $allowedReturns, true)) {
        $return = 'dashboard';
    }
    if ($return === 'referents') {
        $return = 'referent_list';
    }
    if ($return === 'accounts') {
        $return = 'account_list';
    }

    setFlash('success', __('error.status_changed'));
    redirectTo($return);
}

function renderEntityToggleButton(string $entity, int $id, int $active, string $label, string $returnAction = 'dashboard'): void
{
    $isOn = $active === 1;
    $actionLabel = $isOn ? 'Выкл' : 'Вкл';
    $btnClass = $isOn ? 'bg-slate-600' : 'bg-green-700';
    ?>
    <form method="post" action="index.php?action=toggle_active" class="inline">
        <input type="hidden" name="action" value="toggle_active">
        <input type="hidden" name="entity" value="<?= h($entity) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="return_action" value="<?= h($returnAction) ?>">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
        <button type="submit" class="<?= $btnClass ?> text-white px-2 py-1 rounded text-sm" title="<?= h($label) ?>">
            <?= h($label) ?>: <?= h($actionLabel) ?>
        </button>
    </form>
    <?php
}

function renderReferentView(): void
{
    $pdo = getPdo();
    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        setFlash('error', 'Не указан референт');
        redirectTo('referent_list');
    }

    $stmt = $pdo->prepare(
        'SELECT r.id, r.username, r.local_inbox, r.local_outbox, r.active as r_active,
                c.id as client_id, c.email as client_email, c.active as c_active,
                ea.id as ea_id, ea.email as ea_email, ea.username as ea_username,
                ea.auth_type, ea.provider, ea.imap_host, ea.imap_port, ea.imap_encryption,
                ea.smtp_host, ea.smtp_port, ea.smtp_encryption, ea.active as ea_active,
                ot.expires_at
         FROM referents r
         LEFT JOIN clients c ON c.referent_id = r.id
         LEFT JOIN external_accounts ea ON ea.referent_id = r.id
         LEFT JOIN oauth_tokens ot ON ot.account_id = ea.id
         WHERE r.id = ?
         LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        setFlash('error', 'Референт не найден');
        redirectTo('referent_list');
    }

    $localMail = loadLocalMailClientSettings();

    renderHeader('Почтовый клиент — ' . (string)$row['username']);
    ?>
    <div class="mb-4">
        <a href="index.php?action=referent_list" class="text-blue-600 hover:underline">← К списку референтов</a>
    </div>

    <h2 class="text-2xl font-bold mb-2">Настройка почтового клиента</h2>
    <p class="text-slate-600 mb-6">
        Референт: <strong><?= h((string)$row['username']) ?></strong>
        — <?= (int)$row['r_active'] === 1 ? 'активен' : 'отключён' ?>
    </p>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="bg-white rounded shadow p-6">
            <h3 class="text-lg font-semibold mb-4">Локальный ящик (iRedMail)</h3>
            <p class="text-sm text-slate-600 mb-4">
                Эти параметры нужны для настройки Thunderbird, Outlook и других клиентов
                для доступа к локальному ящику референта на сервере DELTA-транзит.
            </p>
            <dl class="space-y-3 text-sm">
                <div><dt class="font-medium text-slate-500">Email / логин</dt>
                    <dd class="font-mono"><?= h((string)$row['local_inbox']) ?></dd></div>
                <div><dt class="font-medium text-slate-500">Пароль</dt>
                    <dd>Задаётся в iRedMail при создании почтового ящика. Панель не хранит и не показывает пароль.</dd></div>
                <div><dt class="font-medium text-slate-500">IMAP</dt>
                    <dd class="font-mono"><?= h($localMail['imap_host']) ?>:<?= (int)$localMail['imap_port'] ?> (<?= h(formatMailEncryption($localMail['imap_encryption'])) ?>)</dd></div>
                <div><dt class="font-medium text-slate-500">SMTP</dt>
                    <dd class="font-mono"><?= h($localMail['smtp_host']) ?>:<?= (int)$localMail['smtp_port'] ?> (<?= h(formatMailEncryption($localMail['smtp_encryption'])) ?>)</dd></div>
                <?php if (!empty($row['local_outbox'])): ?>
                <div><dt class="font-medium text-slate-500">Maildir (системный)</dt>
                    <dd class="font-mono text-xs break-all"><?= h((string)$row['local_outbox']) ?></dd></div>
                <?php endif; ?>
            </dl>
            <p class="text-xs text-slate-500 mt-4">
                Сервер IMAP/SMTP можно переопределить в <code>/etc/mail-proxy/panel.conf</code> секция <code>[local_mail]</code>.
            </p>
        </div>

        <div class="bg-white rounded shadow p-6">
            <h3 class="text-lg font-semibold mb-4">Маршрутизация входящей почты</h3>
            <p class="text-sm text-slate-600 mb-4">
                Демон доставляет входящие письма на локальный ящик, если в поле «Кому» указан email корреспондента.
            </p>
            <?php if (!empty($row['client_email'])): ?>
            <dl class="space-y-3 text-sm">
                <div><dt class="font-medium text-slate-500">Email корреспондента</dt>
                    <dd class="font-mono"><?= h((string)$row['client_email']) ?></dd></div>
                <div><dt class="font-medium text-slate-500">Статус</dt>
                    <dd><?= (int)$row['c_active'] === 1 ? 'Активен' : 'Отключён' ?></dd></div>
            </dl>
            <?php else: ?>
            <p class="text-sm text-amber-700">Email корреспондента не задан. <a href="index.php?action=referent_form&id=<?= $id ?>" class="underline">Редактировать референта</a></p>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded shadow p-6 lg:col-span-2">
            <h3 class="text-lg font-semibold mb-4">Внешний почтовый аккаунт (исходящая/входящая синхронизация)</h3>
            <?php if (!empty($row['ea_id'])): ?>
            <p class="text-sm text-slate-600 mb-4">
                Демон опрашивает внешний IMAP и отправляет исходящую почту через внешний SMTP.
                Пароли и OAuth-токены хранятся в зашифрованном виде и не отображаются.
            </p>
            <dl class="grid md:grid-cols-2 gap-4 text-sm">
                <div><dt class="font-medium text-slate-500">Email</dt><dd class="font-mono"><?= h((string)$row['ea_email']) ?></dd></div>
                <div><dt class="font-medium text-slate-500">Логин IMAP/SMTP</dt><dd class="font-mono"><?= h((string)($row['ea_username'] ?: $row['ea_email'])) ?></dd></div>
                <div><dt class="font-medium text-slate-500">Авторизация</dt><dd><?= h((string)$row['auth_type']) ?><?= $row['provider'] ? ' (' . h((string)$row['provider']) . ')' : '' ?></dd></div>
                <div><dt class="font-medium text-slate-500">Статус</dt><dd><?= (int)$row['ea_active'] === 1 ? 'Активен' : 'Отключён' ?></dd></div>
                <div><dt class="font-medium text-slate-500">IMAP</dt><dd class="font-mono"><?= h((string)$row['imap_host']) ?>:<?= (int)$row['imap_port'] ?> (<?= h(formatMailEncryption((string)$row['imap_encryption'])) ?>)</dd></div>
                <div><dt class="font-medium text-slate-500">SMTP</dt><dd class="font-mono"><?= h((string)$row['smtp_host']) ?>:<?= (int)$row['smtp_port'] ?> (<?= h(formatMailEncryption((string)$row['smtp_encryption'])) ?>)</dd></div>
                <?php if ($row['auth_type'] === 'oauth2' && $row['expires_at']): ?>
                <div><dt class="font-medium text-slate-500">OAuth2 токен</dt><dd><?= h((string)$row['expires_at']) ?> (<?= strtotime((string)$row['expires_at']) > time() ? 'активен' : 'истёк' ?>)</dd></div>
                <?php endif; ?>
            </dl>
            <div class="mt-4 flex gap-3">
                <a href="index.php?action=account_form&referent_id=<?= $id ?>&account_id=<?= (int)$row['ea_id'] ?>"
                   class="bg-amber-500 text-white px-4 py-2 rounded">Редактировать внешний аккаунт</a>
            </div>
            <?php else: ?>
            <p class="text-sm text-amber-700 mb-4">Внешний аккаунт не настроен. Без него демон не сможет синхронизировать почту с удалённым сервером.</p>
            <a href="index.php?action=account_form&referent_id=<?= $id ?>"
               class="bg-blue-600 text-white px-4 py-2 rounded inline-block">Создать внешний аккаунт</a>
            <?php endif; ?>
        </div>
    </div>
    <?php
    renderFooter();
}

function handleReferentDelete(): void
{
    $pdo = getPdo();
    $id = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        setFlash('error', 'Некорректный референт');
        redirectTo('dashboard');
    }

    $stmt = $pdo->prepare('SELECT id, username FROM referents WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        setFlash('error', 'Референт не найден');
        redirectTo('referent_list');
    }

    $stmt = $pdo->prepare('DELETE FROM referents WHERE id = ?');
    $stmt->execute([$id]);

    writeLog('Referent deleted: ID ' . $id . ' username=' . (string)$row['username']);
    setFlash('success', 'Референт удалён');
    redirectTo('referent_list');
}

function handleAccountDelete(): void
{
    $pdo = getPdo();
    $id = (int)($_POST['id'] ?? 0);
    $referentId = (int)($_POST['referent_id'] ?? 0);

    if ($id <= 0 || $referentId <= 0) {
        setFlash('error', 'Некорректный аккаунт');
        redirectTo('dashboard');
    }

    $stmt = $pdo->prepare('SELECT id, email FROM external_accounts WHERE id = ? AND referent_id = ?');
    $stmt->execute([$id, $referentId]);
    $row = $stmt->fetch();

    if (!$row) {
        setFlash('error', 'Внешний аккаунт не найден');
        redirectTo('dashboard');
    }

    $stmt = $pdo->prepare('DELETE FROM external_accounts WHERE id = ? AND referent_id = ?');
    $stmt->execute([$id, $referentId]);

    writeLog('External account deleted: ID ' . $id . ' email=' . (string)$row['email']);
    setFlash('success', 'Внешний аккаунт удалён');
    redirectTo('account_list');
}
