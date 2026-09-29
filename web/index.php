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
require_once __DIR__ . '/includes/relationship_editor.php';
require_once __DIR__ . '/includes/mailbox_verify.php';
require_once __DIR__ . '/includes/panel_local_mail.php';
require_once __DIR__ . '/includes/panel_modals.php';
require_once __DIR__ . '/includes/referent_card_ui.php';
require_once __DIR__ . '/includes/panel_nav.php';
require_once __DIR__ . '/includes/internet_status.php';
require_once __DIR__ . '/includes/directory_pages.php';
require_once __DIR__ . '/includes/dashboard_ui.php';

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
    'relationship_save',
    'relationship_delete',
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
    <link rel="stylesheet" href="/assets/panel-modal.css">
    <style>
        .lang-link { color: #94a3b8; font-size: 0.75rem; text-decoration: none; }
        .lang-link:hover { color: #fff; }
        .lang-active { color: #fff; font-size: 0.75rem; font-weight: 600; }
        .lang-sep { color: #64748b; font-size: 0.75rem; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
<div class="app-shell">
    <?php renderPanelSidebar(); ?>
    <main class="app-main">
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

    case 'internet_status':
        handleInternetStatusJson();
        break;

    case 'referent_list':
    case 'referents':
        renderReferentList();
        break;

    case 'account_list':
    case 'accounts':
    case 'internet_accounts':
        renderInternetAccountsDirectory();
        break;

    case 'local_account_list':
    case 'local_accounts':
        renderLocalAccountsDirectory();
        break;

    case 'client_list':
    case 'clients':
        renderClientsDirectory();
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

    case 'relationship_form':
        renderRelationshipForm();
        break;

    case 'relationship_save':
        handleRelationshipSave();
        break;

    case 'relationship_delete':
        handleRelationshipDelete();
        break;

    case 'relationship_backfill':
        renderLegacyRelationshipBackfill();
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
    renderDashboardUi();
}

function renderReferentList(): void
{
    renderReferentListUi();
}

function renderAccountList(): void
{
    renderInternetAccountsDirectory();
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
        <?php if (!empty($referent['id'])): ?>
        <a href="index.php?action=referent_view&id=<?= (int)$referent['id'] ?>" class="text-blue-600 hover:underline">← К карточке референта</a>
        · <a href="index.php?action=referent_list" class="text-blue-600 hover:underline">К списку</a>
        <?php else: ?>
        <a href="index.php?action=referent_list" class="text-blue-600 hover:underline">← К списку референтов</a>
        <?php endif; ?>
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

        <?php if (empty($referent['id'])): ?>
        <hr>

        <h3 class="text-lg font-semibold"><?= h(__('referent.client_section')) ?></h3>
        <p class="text-sm text-slate-600"><?= h(__('relationship.create_legacy_hint')) ?></p>

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
        <?php endif; ?>

        <button
            type="submit"
            class="bg-blue-600 text-white px-6 py-2 rounded">
            <?= h(__('common.save')) ?>
        </button>
    </form>
    <?php

    if (!empty($referent['id'])) {
        renderRelationshipListSection((int)$referent['id'], 'form');
    }

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

    $existingReferent = null;
    $existingInbox = '';
    $existingOutbox = '';

    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT local_inbox, local_outbox FROM referents WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if ($existing) {
            $existingReferent = $existing;
            $existingInbox = strtolower(trim((string)$existing['local_inbox']));
            $existingOutbox = trim((string)$existing['local_outbox']);
        }
    }

    try {
        $normalizedInbox = normalizeReferentEmail($localInbox);
    } catch (ReferentMaildirException $e) {
        setFlash('error', $e->getUserMessage());
        if ((string) ($_POST['return_to'] ?? '') === 'referent_view') {
            redirectUsingReturnTo('referent_view', [], (int) ($_POST['id'] ?? 0));
        }
        header('Location: index.php?action=referent_form' . ($id > 0 ? '&id=' . $id : ''));
        exit();
    }

    // PROMPT-80 — skip local verify when inactive or inbox unchanged.
    if (mailboxVerifyReferentNeedsLocalCheck($existingReferent, $normalizedInbox, $active)) {
        $localInboxCheck = verifyLocalPhysicalMailbox($normalizedInbox);
        if (empty($localInboxCheck['ok'])) {
            $msg = ($localInboxCheck['code'] ?? '') === 'mailbox_verify.local_missing'
                ? __('referent.mailbox_not_found', ['email' => $normalizedInbox])
                : mailboxVerifyMessage($localInboxCheck);
            setFlash('error', $msg);
            if ((string) ($_POST['return_to'] ?? '') === 'referent_view') {
                redirectUsingReturnTo('referent_view', [], (int) ($_POST['id'] ?? 0));
            }
            header('Location: index.php?action=referent_form' . ($id > 0 ? '&id=' . $id : ''));
            exit();
        }
    }

    if ($id > 0 && $normalizedInbox === $existingInbox && $existingOutbox !== '') {
        $localOutbox = $existingOutbox;
    } else {
        try {
            $localOutbox = resolveReferentMaildir($normalizedInbox);
        } catch (ReferentMaildirException $e) {
            setFlash('error', $e->getUserMessage());
            if ((string) ($_POST['return_to'] ?? '') === 'referent_view') {
                redirectUsingReturnTo('referent_view', [], (int) ($_POST['id'] ?? 0));
            }
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

    $savedId = isset($referentId) ? (int) $referentId : (int) ($_POST['id'] ?? 0);
    if ($savedId > 0 && (string) ($_POST['return_to'] ?? '') === 'referent_view') {
        redirectUsingReturnTo('referent_view', ['id' => $savedId], $savedId);
    }
    if ($savedId > 0) {
        redirectTo('referent_view', ['id' => $savedId, 'tab' => 'overview']);
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
        <a href="index.php?action=referent_view&id=<?= $referentId ?>&tab=external" class="text-blue-600 hover:underline">← К карточке референта</a>
        · <a href="index.php?action=account_list" class="text-blue-600 hover:underline">К списку аккаунтов</a>
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
        <input type="hidden" name="return_to" value="referent_view">
        <input type="hidden" name="return_id" value="<?= $referentId ?>">
        <input type="hidden" name="tab" value="external">

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
            <p class="text-xs text-slate-500 mt-1"><?= h(__('mailbox_verify.password_keep_hint')) ?></p>
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
        if ($referentId > 0) {
            panelRedirectPreferReferentCard($referentId, 'account_form', [
                'referent_id' => $referentId,
                'account_id' => $accountId > 0 ? $accountId : null,
            ]);
        }
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
            if ((string) ($_POST['return_to'] ?? '') === 'referent_view') {
                redirectUsingReturnTo('referent_view', ['id' => $referentId], $referentId);
            }
            header('Location: index.php?action=account_form&referent_id=' . $referentId);
            exit();
        }
        if ($authType === 'oauth2' && ($clientId === '' || $clientSecret === '')) {
            setFlash('error', 'Для OAuth2 необходимо указать Client ID и Client Secret при создании аккаунта');
            if ((string) ($_POST['return_to'] ?? '') === 'referent_view') {
                redirectUsingReturnTo('referent_view', ['id' => $referentId], $referentId);
            }
            header('Location: index.php?action=account_form&referent_id=' . $referentId);
            exit();
        }
    }

    // PROMPT-80 hardening — plan BEFORE any network I/O or persist.
    $incomingAccount = [
        'email' => $email,
        'username' => $username,
        'auth_type' => $authType,
        'password' => $password,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'imap_host' => $imapHost,
        'imap_port' => $imapPort,
        'imap_encryption' => $imapEncryption,
        'smtp_host' => $smtpHost,
        'smtp_port' => $smtpPort,
        'smtp_encryption' => $smtpEncryption,
    ];
    $existingAccount = null;
    if ($accountId > 0) {
        $existingAccount = mailboxVerifyLoadExistingAccount($pdo, $accountId, $referentId, $cryptor);
        if ($existingAccount === null) {
            setFlash('error', __('account.access_denied'));
            panelRedirectPreferReferentCard($referentId, 'dashboard');
        }
    }

    $plan = mailboxVerifyPlanAccountSave($incomingAccount, $existingAccount);
    $clearOauthTokens = !empty($plan['clear_oauth_tokens']);
    $accountVerifyWarning = '';

    if (($plan['action'] ?? '') === 'reject_reauth') {
        $code = (string) ($plan['code'] ?? 'mailbox_verify.password_reentry_required');
        setFlash('error', __($code));
        panelRedirectPreferReferentCard($referentId, 'account_form', [
            'referent_id' => $referentId,
            'account_id' => $accountId > 0 ? $accountId : null,
        ]);
    }

    if (($plan['action'] ?? '') === 'oauth_reauth_required') {
        $accountVerifyWarning = __((string) ($plan['code'] ?? 'mailbox_verify.oauth_reauth_required'));
        mailboxVerifyLog(
            'External account oauth_reauth_required referent_id=' . $referentId
            . ' account_id=' . $accountId
        );
    }

    if (($plan['action'] ?? '') === 'probe') {
        $probeSecret = '';
        $oauthExpiresAt = '';
        if (!empty($plan['use_stored_password'])) {
            if ($existingAccount === null || empty($existingAccount['password_enc'])) {
                setFlash('error', __('mailbox_verify.password_required'));
                panelRedirectPreferReferentCard($referentId, 'account_form', [
                    'referent_id' => $referentId,
                    'account_id' => $accountId > 0 ? $accountId : null,
                ]);
            }
            try {
                $probeSecret = $cryptor->decrypt((string) $existingAccount['password_enc']);
            } catch (Throwable $e) {
                mailboxVerifyLog('mailbox_verify decrypt password_enc failed: ' . get_class($e));
                setFlash('error', __('mailbox_verify.decrypt_failed'));
                panelRedirectPreferReferentCard($referentId, 'account_form', [
                    'referent_id' => $referentId,
                    'account_id' => $accountId > 0 ? $accountId : null,
                ]);
            }
        } elseif (!empty($plan['use_stored_oauth'])) {
            $probeSecret = (string) ($existingAccount['oauth_access_token'] ?? '');
            $oauthExpiresAt = (string) ($existingAccount['oauth_expires_at'] ?? '');
            if ($probeSecret === '') {
                setFlash('error', __('mailbox_verify.oauth_token_missing'));
                panelRedirectPreferReferentCard($referentId, 'account_form', [
                    'referent_id' => $referentId,
                    'account_id' => $accountId > 0 ? $accountId : null,
                ]);
            }
        } else {
            $probeSecret = $password;
        }

        $probe = verifyExternalReferentMailbox([
            'email' => $email,
            'username' => $username !== '' ? $username : $email,
            'auth_mode' => (string) ($plan['auth_mode'] ?? 'plain'),
            'secret' => $probeSecret,
            'oauth_expires_at' => $oauthExpiresAt,
            'imap_host' => $imapHost,
            'imap_port' => $imapPort,
            'imap_encryption' => $imapEncryption,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_encryption' => $smtpEncryption,
        ], $_SESSION);
        $probeSecret = '';

        if (empty($probe['ok'])) {
            mailboxVerifyLog(
                'External account probe failed referent_id=' . $referentId
                . ' account_id=' . $accountId
                . ' hop=' . ($probe['hop'] ?? '')
                . ' code=' . ($probe['code'] ?? '')
            );
            setFlash('error', mailboxVerifyMessage($probe));
            panelRedirectPreferReferentCard($referentId, 'account_form', [
                'referent_id' => $referentId,
                'account_id' => $accountId > 0 ? $accountId : null,
            ]);
        }
        if (!empty($probe['warning'])) {
            $accountVerifyWarning = mailboxVerifyMessage($probe);
            mailboxVerifyLog(
                'External account probe warning referent_id=' . $referentId
                . ' account_id=' . $accountId
                . ' code=' . ($probe['code'] ?? '')
            );
        }
    }

    try {
        $pdo->beginTransaction();

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
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                setFlash('error', __('account.access_denied'));
                panelRedirectPreferReferentCard($referentId, 'dashboard');
            }

            if ($clearOauthTokens) {
                mailboxVerifyDeleteOauthTokens($pdo, $accountId);
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

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        if ($accountVerifyWarning !== '') {
            setFlash('warning', __('account.saved') . ' — ' . $accountVerifyWarning);
        } else {
            setFlash('success', __('account.saved'));
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        mailboxVerifyLog('External account save error: ' . get_class($e));
        setFlash('error', exceptionUserMessage($e));
    }

    $rid = (int) ($_POST['referent_id'] ?? 0);
    if ((string) ($_POST['return_to'] ?? '') === 'referent_view' && $rid > 0) {
        redirectUsingReturnTo('referent_view', ['id' => $rid], $rid);
    }
    if ($rid > 0) {
        redirectTo('referent_view', ['id' => $rid, 'tab' => 'external']);
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
    $allowedReturns = [
        'dashboard',
        'referent_list',
        'referents',
        'account_list',
        'accounts',
        'referent_form',
        'referent_view',
    ];
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

    if ($return === 'referent_form' || $return === 'referent_view') {
        $refId = (int) ($_POST['referent_id'] ?? 0);
        if ($refId > 0) {
            $params = ['id' => $refId];
            if ($return === 'referent_view') {
                $params['tab'] = panelNormalizeTab(
                    isset($_POST['tab']) ? (string) $_POST['tab'] : null
                );
            }
            redirectTo($return, $params);
        }
        $return = 'referent_list';
    }

    redirectTo($return);
}

function renderEntityToggleButton(
    string $entity,
    int $id,
    int $active,
    string $label,
    string $returnAction = 'dashboard',
    ?int $referentId = null,
    ?string $tab = null
): void {
    $isOn = $active === 1;
    $actionLabel = $isOn ? 'Выкл' : 'Вкл';
    $btnClass = $isOn ? 'bg-slate-600' : 'bg-green-700';
    ?>
    <form method="post" action="index.php?action=toggle_active" class="inline">
        <input type="hidden" name="action" value="toggle_active">
        <input type="hidden" name="entity" value="<?= h($entity) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="return_action" value="<?= h($returnAction) ?>">
        <?php if ($referentId !== null && $referentId > 0): ?>
            <input type="hidden" name="referent_id" value="<?= $referentId ?>">
        <?php endif; ?>
        <?php if ($tab !== null && $tab !== ''): ?>
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
        <button type="submit" class="<?= $btnClass ?> text-white px-2 py-1 rounded text-sm" title="<?= h($label) ?>">
            <?= h($label) ?>: <?= h($actionLabel) ?>
        </button>
    </form>
    <?php
}

function renderReferentView(): void
{
    renderReferentCardUi();
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
    if ((string) ($_POST['return_to'] ?? '') === 'referent_view') {
        redirectUsingReturnTo('referent_view', ['id' => $referentId], $referentId);
    }
    redirectTo('referent_view', ['id' => $referentId, 'tab' => 'external']);
}

function renderRelationshipForm(): void
{
    $pdo = getPdo();
    $referentId = (int)($_GET['referent_id'] ?? 0);
    $id = (int)($_GET['id'] ?? 0);

    if ($referentId <= 0) {
        setFlash('error', __('relationship.error.referent_required'));
        redirectTo('referent_list');
    }

    $stmt = $pdo->prepare('SELECT id, username FROM referents WHERE id = ?');
    $stmt->execute([$referentId]);
    $referent = $stmt->fetch();
    if (!$referent) {
        setFlash('error', __('error.record_not_found'));
        redirectTo('referent_list');
    }

    $row = [
        'id' => '',
        'external_client_email' => '',
        'local_client_email' => '',
        'local_referent_email' => '',
        'external_account_id' => '',
        'local_client_maildir' => '',
        'active' => 1,
        'email' => '',
    ];

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'SELECT * FROM clients WHERE id = ? AND referent_id = ?'
        );
        $stmt->execute([$id, $referentId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            setFlash('error', __('error.record_not_found'));
            redirectTo('referent_view', ['id' => $referentId, 'tab' => 'clients']);
        }
        $row = $existing;
    }

    $accounts = fetchExternalAccountsForRelationshipForm(
        $pdo,
        $referentId,
        $id > 0 ? $id : null
    );

    // GET-only display prefill (PROMPT-57). Never written until relationship_save POST.
    $externalClientValue = relationshipExternalClientFormValue($row);
    $fromBackfill = (string)($_GET['from'] ?? '') === 'backfill';

    renderHeader(__('relationship.form_title'));
    ?>
    <div class="mb-4">
        <?php if ($fromBackfill): ?>
            <a href="index.php?action=relationship_backfill" class="text-blue-600 hover:underline">
                ← <?= h(__('backfill.back_to_list')) ?>
            </a>
        <?php else: ?>
            <a href="index.php?action=referent_view&id=<?= $referentId ?>&tab=clients" class="text-blue-600 hover:underline">
                ← <?= h(__('relationship.back_to_referent')) ?>
            </a>
        <?php endif; ?>
    </div>
    <h2 class="text-2xl font-bold mb-2">
        <?= $id > 0 ? h(__('relationship.edit')) : h(__('relationship.add')) ?>
    </h2>
    <p class="text-slate-600 mb-6">
        <?= h(__('relationship.form_for_referent', ['name' => (string)$referent['username']])) ?>
    </p>

    <form method="post" action="index.php?action=relationship_save"
          class="bg-white rounded shadow p-6 space-y-4" id="relationship-form"
          data-prefill-source="<?= relationshipIsLegacyOnly($row) ? 'legacy-email-get' : 'row' ?>">
        <input type="hidden" name="action" value="relationship_save">
        <input type="hidden" name="id" value="<?= h((string)$row['id']) ?>">
        <input type="hidden" name="referent_id" value="<?= $referentId ?>">
        <?php if ($fromBackfill): ?>
            <input type="hidden" name="return_to" value="backfill">
        <?php else: ?>
            <input type="hidden" name="return_to" value="referent_view">
            <input type="hidden" name="return_id" value="<?= $referentId ?>">
            <input type="hidden" name="tab" value="clients">
        <?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">

        <?php if (relationshipIsLegacyOnly($row)): ?>
            <div class="bg-slate-50 border border-slate-200 rounded p-3 text-sm text-slate-700"
                 data-testid="legacy-migrate-banner">
                <?= h(__('relationship.legacy_banner', ['email' => (string)$row['email']])) ?>
                <div class="mt-1 text-xs"><?= h(__('backfill.prefill_note')) ?></div>
            </div>
        <?php endif; ?>

        <p class="text-sm text-slate-600"><?= h(__('relationship.all_or_nothing_hint')) ?></p>

        <div>
            <label class="block mb-1 font-medium"><?= h(__('relationship.field.external_client_email')) ?></label>
            <input type="email" name="external_client_email" class="w-full border rounded px-3 py-2 font-mono"
                   value="<?= h($externalClientValue) ?>"
                   data-testid="external-client-prefill">
        </div>
        <div>
            <label class="block mb-1 font-medium"><?= h(__('relationship.field.local_client_email')) ?></label>
            <input type="email" name="local_client_email" class="w-full border rounded px-3 py-2 font-mono"
                   value="<?= h((string)($row['local_client_email'] ?? '')) ?>">
            <p class="text-xs text-slate-500 mt-1"><?= h(__('relationship.mailbox_precondition_hint')) ?></p>
        </div>
        <div>
            <label class="block mb-1 font-medium"><?= h(__('relationship.field.local_referent_email')) ?></label>
            <input type="email" name="local_referent_email" class="w-full border rounded px-3 py-2 font-mono"
                   value="<?= h((string)($row['local_referent_email'] ?? '')) ?>">
        </div>
        <div>
            <label class="block mb-1 font-medium"><?= h(__('relationship.field.external_account_id')) ?></label>
            <select name="external_account_id" class="w-full border rounded px-3 py-2">
                <option value=""><?= h(__('common.select')) ?></option>
                <?php foreach ($accounts as $acc):
                    $linkedId = $acc['linked_client_id'] ?? null;
                    $disabled = $linkedId !== null && (int)$linkedId > 0;
                    $selected = (int)($row['external_account_id'] ?? 0) === (int)$acc['id'];
                    $inactive = (int)$acc['active'] !== 1;
                    $label = (string)$acc['email'];
                    if ($inactive) {
                        $label .= ' (' . __('common.inactive') . ')';
                    }
                    if ($disabled) {
                        $linkedLabel = trim((string)($acc['linked_external_client'] ?? '')) !== ''
                            ? (string)$acc['linked_external_client']
                            : (string)($acc['linked_legacy_email'] ?? ('#' . (int)$linkedId));
                        $label .= ' — ' . __('relationship.account_linked_to', [
                            'id' => (string)(int)$linkedId,
                            'label' => $linkedLabel,
                        ]);
                    }
                    ?>
                    <option value="<?= (int)$acc['id'] ?>"
                        <?= $selected ? 'selected' : '' ?>
                        <?= $disabled ? 'disabled' : '' ?>>
                        <?= h($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($accounts === []): ?>
                <p class="text-sm text-amber-700 mt-1">
                    <?= h(__('relationship.no_accounts')) ?>
                    <a class="underline" href="index.php?action=account_form&referent_id=<?= $referentId ?>">
                        <?= h(__('relationship.create_account_first')) ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <div>
            <label class="block mb-1 font-medium"><?= h(__('relationship.field.local_client_maildir')) ?></label>
            <input type="text" name="local_client_maildir" class="w-full border rounded px-3 py-2 font-mono text-sm"
                   placeholder="/var/vmail/vmail1/..."
                   value="<?= h((string)($row['local_client_maildir'] ?? '')) ?>">
            <p class="text-xs text-slate-500 mt-1"><?= h(__('relationship.maildir_hint')) ?></p>
        </div>
        <div>
            <label class="inline-flex items-center gap-2">
                <input type="checkbox" name="active" value="1"
                    <?= (int)($row['active'] ?? 1) === 1 ? 'checked' : '' ?>>
                <span><?= h(__('relationship.field.active')) ?></span>
            </label>
        </div>

        <?php if (!empty($_SESSION['mailbox_verify_soft_client'])): ?>
        <div class="bg-amber-50 border border-amber-200 rounded p-3 text-sm">
            <label class="inline-flex items-start gap-2">
                <input type="checkbox" name="confirm_unverified_client" value="1" class="mt-1">
                <span><?= h(__('mailbox_verify.save_anyway_unverified')) ?></span>
            </label>
        </div>
        <?php endif; ?>

        <div class="flex gap-3">
            <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded">
                <?= h(__('common.save')) ?>
            </button>
            <a href="index.php?action=referent_view&id=<?= $referentId ?>&tab=clients"
               class="bg-slate-500 text-white px-6 py-2 rounded inline-block">
                <?= h(__('common.cancel')) ?>
            </a>
        </div>
    </form>
    <?php
    renderFooter();
}

function handleRelationshipSave(): void
{
    $pdo = getPdo();
    $id = (int)($_POST['id'] ?? 0);
    $referentId = (int)($_POST['referent_id'] ?? 0);

    if ($referentId <= 0) {
        setFlash('error', __('relationship.error.referent_required'));
        redirectTo('referent_list');
    }

    $stmt = $pdo->prepare('SELECT id FROM referents WHERE id = ?');
    $stmt->execute([$referentId]);
    if (!$stmt->fetch()) {
        setFlash('error', __('error.record_not_found'));
        redirectTo('referent_list');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'SELECT id, external_client_email, local_client_email, local_referent_email, active
             FROM clients WHERE id = ? AND referent_id = ?'
        );
        $stmt->execute([$id, $referentId]);
        $existingRelationship = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existingRelationship === null) {
            setFlash('error', __('error.record_not_found'));
            redirectTo('referent_view', ['id' => $referentId, 'tab' => 'clients']);
        }
    } else {
        $existingRelationship = null;
    }

    $data = parseRelationshipFormPost($_POST);

    if ($data['any_filled'] && !$data['all_filled']) {
        setFlash('error', __('relationship.error.all_or_nothing'));
        if ((string) ($_POST['return_to'] ?? '') === 'referent_view') {
            redirectUsingReturnTo('referent_view', ['id' => $referentId], $referentId);
        }
        redirectTo('relationship_form', array_filter([
            'referent_id' => $referentId,
            'id' => $id > 0 ? $id : null,
        ]));
    }

    if (!$data['all_filled']) {
        setFlash('error', __('relationship.error.all_or_nothing'));
        if ((string) ($_POST['return_to'] ?? '') === 'referent_view') {
            redirectUsingReturnTo('referent_view', ['id' => $referentId], $referentId);
        }
        redirectTo('relationship_form', array_filter([
            'referent_id' => $referentId,
            'id' => $id > 0 ? $id : null,
        ]));
    }

    // Email format
    foreach (
        [
            'external_client_email' => $data['external_client_email'],
            'local_client_email' => $data['local_client_email'],
            'local_referent_email' => $data['local_referent_email'],
        ] as $field => $value
    ) {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', __('relationship.error.invalid_email', [
                'field' => __('relationship.field.' . $field),
            ]));
            panelRedirectPreferReferentCard($referentId, 'relationship_form', [
                'referent_id' => $referentId,
                'id' => $id > 0 ? $id : null,
            ]);
        }
    }

    if ($data['local_client_email'] === $data['local_referent_email']) {
        setFlash('error', __('relationship.error.same_local_mailboxes'));
        if ((string) ($_POST['return_to'] ?? '') === 'referent_view') {
            redirectUsingReturnTo('referent_view', ['id' => $referentId], $referentId);
        }
        redirectTo('relationship_form', array_filter([
            'referent_id' => $referentId,
            'id' => $id > 0 ? $id : null,
        ]));
    }

    // Cheap DB checks BEFORE any network I/O (PROMPT-80 hardening).
    $accountError = validateRelationshipExternalAccount(
        $pdo,
        $referentId,
        (int)$data['external_account_id']
    );
    if ($accountError !== null) {
        setFlash('error', $accountError);
        if ((string) ($_POST['return_to'] ?? '') === 'referent_view') {
            redirectUsingReturnTo('referent_view', ['id' => $referentId], $referentId);
        }
        redirectTo('relationship_form', array_filter([
            'referent_id' => $referentId,
            'id' => $id > 0 ? $id : null,
        ]));
    }

    $collision = findRelationshipUniqueCollision(
        $pdo,
        $referentId,
        $id > 0 ? $id : null,
        $data
    );
    if ($collision !== null) {
        setFlash('error', $collision);
        if ((string) ($_POST['return_to'] ?? '') === 'referent_view') {
            redirectUsingReturnTo('referent_view', ['id' => $referentId], $referentId);
        }
        redirectTo('relationship_form', array_filter([
            'referent_id' => $referentId,
            'id' => $id > 0 ? $id : null,
        ]));
    }

    $needsChecks = mailboxVerifyRelationshipNeedsChecks(
        $existingRelationship,
        [
            'local_client' => $data['local_client_email'],
            'local_referent' => $data['local_referent_email'],
            'external_client' => $data['external_client_email'],
        ],
        (int) $data['active']
    );

    // Physical mailbox precondition (only when active and address new/changed).
    try {
        if (!empty($needsChecks['local_client'])) {
            $localClientCheck = verifyLocalPhysicalMailbox($data['local_client_email']);
            if (empty($localClientCheck['ok'])) {
                setFlash('error', mailboxVerifyMessage($localClientCheck));
                panelRedirectPreferReferentCard($referentId, 'relationship_form', [
                    'referent_id' => $referentId,
                    'id' => $id > 0 ? $id : null,
                ]);
            }
        }
        if (!empty($needsChecks['local_referent'])) {
            $localReferentCheck = verifyLocalPhysicalMailbox($data['local_referent_email']);
            if (empty($localReferentCheck['ok'])) {
                setFlash('error', mailboxVerifyMessage($localReferentCheck));
                panelRedirectPreferReferentCard($referentId, 'relationship_form', [
                    'referent_id' => $referentId,
                    'id' => $id > 0 ? $id : null,
                ]);
            }
        }
    } catch (ReferentMaildirException $e) {
        setFlash('error', $e->getUserMessage());
        if ((string) ($_POST['return_to'] ?? '') === 'referent_view') {
            redirectUsingReturnTo('referent_view', ['id' => $referentId], $referentId);
        }
        redirectTo('relationship_form', array_filter([
            'referent_id' => $referentId,
            'id' => $id > 0 ? $id : null,
        ]));
    } catch (Throwable $e) {
        mailboxVerifyLog('Relationship mailbox check failed: ' . get_class($e));
        setFlash('error', __('relationship.error.vmail_unavailable'));
        if ((string) ($_POST['return_to'] ?? '') === 'referent_view') {
            redirectUsingReturnTo('referent_view', ['id' => $referentId], $referentId);
        }
        redirectTo('relationship_form', array_filter([
            'referent_id' => $referentId,
            'id' => $id > 0 ? $id : null,
        ]));
    }

    // External Client MX + RCPT TO (hard refuse / soft + confirm override).
    if (!empty($needsChecks['external_client'])) {
        $externalClientProbe = verifyExternalClientMailbox($data['external_client_email'], $_SESSION);
        if (empty($externalClientProbe['ok'])) {
            $severity = (string) ($externalClientProbe['severity'] ?? 'hard');
            $confirmFlag = isset($_POST['confirm_unverified_client'])
                && (string) $_POST['confirm_unverified_client'] === '1';
            if ($severity === 'soft'
                && mailboxVerifyConsumeSoftOverride($data['external_client_email'], $confirmFlag, $_SESSION)
            ) {
                mailboxVerifyLog(
                    'External client soft override referent_id=' . $referentId
                    . ' hop=' . ($externalClientProbe['hop'] ?? '')
                    . ' code=' . ($externalClientProbe['code'] ?? '')
                );
            } else {
                mailboxVerifyLog(
                    'External client probe failed referent_id=' . $referentId
                    . ' hop=' . ($externalClientProbe['hop'] ?? '')
                    . ' code=' . ($externalClientProbe['code'] ?? '')
                    . ' severity=' . $severity
                );
                if ($severity === 'soft') {
                    mailboxVerifyRememberSoftFailure($data['external_client_email'], $_SESSION);
                    setFlash(
                        'error',
                        mailboxVerifyMessage($externalClientProbe)
                        . ' — ' . __('mailbox_verify.soft_failure_hint')
                    );
                } else {
                    unset($_SESSION['mailbox_verify_soft_client']);
                    setFlash('error', mailboxVerifyMessage($externalClientProbe));
                }
                panelRedirectPreferReferentCard($referentId, 'relationship_form', [
                    'referent_id' => $referentId,
                    'id' => $id > 0 ? $id : null,
                ]);
            }
        } else {
            unset($_SESSION['mailbox_verify_soft_client']);
        }
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE clients
                 SET email = ?,
                     external_client_email = ?,
                     local_client_email = ?,
                     local_referent_email = ?,
                     external_account_id = ?,
                     local_client_maildir = ?,
                     active = ?,
                     updated_at = NOW()
                 WHERE id = ? AND referent_id = ?'
            );
            $stmt->execute([
                $data['external_client_email'],
                $data['external_client_email'],
                $data['local_client_email'],
                $data['local_referent_email'],
                $data['external_account_id'],
                $data['local_client_maildir'],
                $data['active'],
                $id,
                $referentId,
            ]);
            writeLog("ClientRelationship updated: ID {$id} referent={$referentId}");
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO clients (
                    email, referent_id,
                    external_client_email, local_client_email, local_referent_email,
                    external_account_id, local_client_maildir, active
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['external_client_email'],
                $referentId,
                $data['external_client_email'],
                $data['local_client_email'],
                $data['local_referent_email'],
                $data['external_account_id'],
                $data['local_client_maildir'],
                $data['active'],
            ]);
            $id = (int)$pdo->lastInsertId();
            writeLog("ClientRelationship created: ID {$id} referent={$referentId}");
        }
        setFlash('success', __('relationship.saved'));
    } catch (PDOException $e) {
        writeLog('Relationship save PDO error: ' . $e->getMessage());
        $sqlState = $e->errorInfo[0] ?? '';
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        if ($sqlState === '23000' || $driverCode === 1062) {
            setFlash('error', __('relationship.error.unique_db'));
        } elseif ($sqlState === '23000' || $driverCode === 1452) {
            setFlash('error', __('relationship.error.account_missing'));
        } else {
            setFlash('error', __('relationship.error.save_failed'));
        }
        if ((string) ($_POST['return_to'] ?? '') === 'referent_view') {
            redirectUsingReturnTo('referent_view', ['id' => $referentId], $referentId);
        }
        redirectTo('relationship_form', array_filter([
            'referent_id' => $referentId,
            'id' => $id > 0 ? $id : null,
        ]));
    } catch (Throwable $e) {
        writeLog('Relationship save error: ' . $e->getMessage());
        setFlash('error', exceptionUserMessage($e));
        if ((string) ($_POST['return_to'] ?? '') === 'referent_view') {
            redirectUsingReturnTo('referent_view', ['id' => $referentId], $referentId);
        }
        redirectTo('relationship_form', array_filter([
            'referent_id' => $referentId,
            'id' => $id > 0 ? $id : null,
        ]));
    }

    if ((string) ($_POST['return_to'] ?? '') === 'backfill') {
        redirectTo('relationship_backfill');
    }
    if ((string) ($_POST['return_to'] ?? '') === 'referent_view') {
        redirectUsingReturnTo('referent_view', ['id' => $referentId], $referentId);
    }
    redirectTo('referent_view', ['id' => $referentId, 'tab' => 'clients']);
}

function handleRelationshipDelete(): void
{
    $pdo = getPdo();
    $id = (int)($_POST['id'] ?? 0);
    $referentId = (int)($_POST['referent_id'] ?? 0);

    if ($id <= 0 || $referentId <= 0) {
        setFlash('error', __('error.invalid_entity'));
        redirectTo('referent_list');
    }

    $stmt = $pdo->prepare(
        'SELECT id, email FROM clients WHERE id = ? AND referent_id = ?'
    );
    $stmt->execute([$id, $referentId]);
    $row = $stmt->fetch();
    if (!$row) {
        setFlash('error', __('error.record_not_found'));
        redirectTo('referent_view', ['id' => $referentId, 'tab' => 'clients']);
    }

    $stmt = $pdo->prepare('DELETE FROM clients WHERE id = ? AND referent_id = ?');
    $stmt->execute([$id, $referentId]);
    writeLog('ClientRelationship deleted: ID ' . $id . ' email=' . (string)$row['email']);
    setFlash('success', __('relationship.deleted'));
    if ((string) ($_POST['return_to'] ?? '') === 'referent_view') {
        redirectUsingReturnTo('referent_view', ['id' => $referentId], $referentId);
    }
    redirectTo('referent_view', ['id' => $referentId, 'tab' => 'clients']);
}

