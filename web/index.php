<?php
declare(strict_types=1);

session_start();

// CSRF-токен — генерируется один раз за сессию
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Подключение централизованного файла конфигурации общих констант
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Cryptor.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/oauth2.php';
require_once __DIR__ . '/includes/providers_ui.php';

use MailProxy\Cryptor;

$action = $_GET['action'] ?? $_POST['action'] ?? 'dashboard';

if ($action !== 'oauth_callback') {
    checkLocalNetworkAccess();
}

// CSRF-защита для всех POST-действий (кроме OAuth callback — GET-запрос)
$postActionsRequiringCsrf = [
    'referent_save',
    'account_save',
    'toggle_active',
    'provider_save',
    'provider_toggle',
    'oauth_initiate',
];

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && in_array($action, $postActionsRequiringCsrf, true)
) {
    requireValidCsrfToken();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function redirectTo(string $action): void
{
    header('Location: index.php?action=' . urlencode($action));
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
    global $flash;

    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
<div class="flex min-h-screen">

    <aside class="w-64 bg-slate-800 text-white">
        <div class="p-6 border-b border-slate-700">
            <h1 class="text-xl font-bold">DELTA-транзит</h1>
        </div>

        <nav class="p-4 space-y-2">
            <a href="/index.php?action=dashboard"
               <?= in_array($action ?? '', ['dashboard', 'referents', 'accounts'], true) ? 'class="active"' : '' ?>>Референты</a>
            <a href="/index.php?action=dashboard"
               <?= ($action ?? '') === 'accounts' ? 'class="active"' : '' ?>>Аккаунты</a>
            <a href="/index.php?action=provider_list"
               <?= in_array($action ?? '', ['provider_list', 'provider_form', 'providers'], true) ? 'class="active"' : '' ?>>Провайдеры</a>
            <!-- Ссылка на страницу мониторинга — отдельный файл, не action в index.php -->
            <a href="/monitor.php"
               <?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'monitor.php' ? 'class="active"' : '' ?>>
               Мониторинг
            </a>
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
    case 'dashboard':
        renderDashboard();
        break;

    case 'referent_form':
        renderReferentForm();
        break;

    case 'referent_save':
        handleReferentSave();
        break;

    case 'account_form':
        renderAccountForm();
        break;

    case 'account_save':
        handleAccountSave();
        break;

    case 'toggle_active':
        handleToggleActive();
        break;

    case 'providers':
    case 'provider_list':
        renderProviderList();
        break;

    case 'referents':
    case 'accounts':
        renderDashboard();
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
				c.email as client_email, c.active as c_active,
				ea.id as ea_id, ea.email as ea_email, ea.auth_type, ea.provider,
				ea.imap_host, ea.imap_port, ea.smtp_host, ea.smtp_port, ea.active as ea_active,
				ot.expires_at, ot.updated_at as token_updated
		 FROM referents r
		 LEFT JOIN clients c ON c.referent_id = r.id
		 LEFT JOIN external_accounts ea ON ea.referent_id = r.id
		 LEFT JOIN oauth_tokens ot ON ot.account_id = ea.id
		 ORDER BY r.id'
	);
    $stmt->execute();

    $rows = $stmt->fetchAll();

    renderHeader('Dashboard');
    ?>
    <h2 class="text-2xl font-bold mb-6">Dashboard</h2>
	<div class="mb-6">
		<a href="index.php?action=referent_form"
		   class="bg-blue-600 text-white px-4 py-2 rounded">
			Создать референта
		</a>
	</div>
    <div class="bg-white rounded shadow overflow-x-auto">
        <table class="min-w-full">
            <thead class="bg-slate-100">
            <tr>
                <th class="px-4 py-2">Референт</th>
                <th class="px-4 py-2">Клиент</th>
                <th class="px-4 py-2">Внешний ящик</th>
                <th class="px-4 py-2">IMAP</th>
                <th class="px-4 py-2">SMTP</th>
                <th class="px-4 py-2">Auth</th>
                <th class="px-4 py-2">Статус токена</th>
                <th class="px-4 py-2">Активность</th>
                <th class="px-4 py-2">Действия</th>
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
                            echo $expires > time() ? 'Активен до ' . h($row['expires_at']) : 'Истёк';
                        } else {
                            echo '—';
                        }
                        ?>
                    </td>
                    <td class="px-4 py-2">
                        <?= (int)$row['r_active'] === 1 ? 'Референт: Вкл' : 'Референт: Выкл' ?><br>
                        <?= (int)$row['c_active'] === 1 ? 'Клиент: Вкл' : 'Клиент: Выкл' ?><br>
                        <?= (int)$row['ea_active'] === 1 ? 'Аккаунт: Вкл' : 'Аккаунт: Выкл' ?>
                    </td>
                    <td class="px-4 py-2">
                        <div class="flex gap-2 flex-wrap">
                            <a class="bg-amber-500 text-white px-3 py-1 rounded"
							   href="index.php?action=account_form&referent_id=<?= (int)$row['id'] ?><?= $row['ea_id'] ? '&account_id=' . (int)$row['ea_id'] : '' ?>">
								<?= $row['ea_id'] ? 'Редактировать' : 'Создать аккаунт' ?>
							</a>
                            <form method="post" class="inline">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="entity" value="referent">
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                                <button class="bg-slate-700 text-white px-3 py-1 rounded">
                                    Вкл/Выкл
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    renderFooter();
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

    renderHeader('Референт');

    ?>
    <h2 class="text-2xl font-bold mb-6">
        <?= !empty($referent['id']) ? 'Редактирование референта' : 'Новый референт' ?>
    </h2>

    <form method="post" class="bg-white rounded shadow p-6 space-y-4">
        <input type="hidden" name="action" value="referent_save">
        <input type="hidden" name="id" value="<?= h((string)$referent['id']) ?>">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">

        <div>
            <label class="block mb-1 font-medium">Username</label>
            <input
                type="text"
                name="username"
                required
                class="w-full border rounded px-3 py-2"
                value="<?= h((string)$referent['username']) ?>"
            >
        </div>

        <div>
            <label class="block mb-1 font-medium">Local Inbox</label>
            <input
                type="email"
                name="local_inbox"
                required
                class="w-full border rounded px-3 py-2"
                value="<?= h((string)$referent['local_inbox']) ?>"
            >
        </div>

        <div>
            <label class="block mb-1 font-medium">Local Outbox</label>
            <input
                type="text"
                name="local_outbox"
                required
                class="w-full border rounded px-3 py-2"
                placeholder="/var/vmail/vmail1/example.com/username/Maildir"
                value="<?= h((string)$referent['local_outbox']) ?>"
            >
            <p class="text-sm text-gray-600 mt-1">
                Абсолютный путь к корню Maildir референта на базовой почтовой системе
                (например iRedMail), не email-адрес.
            </p>
        </div>

        <div>
            <label class="inline-flex items-center gap-2">
                <input
                    type="checkbox"
                    name="active"
                    value="1"
                    <?= (int)$referent['active'] === 1 ? 'checked' : '' ?>
                >
                <span>Референт активен</span>
            </label>
        </div>

        <hr>

        <h3 class="text-lg font-semibold">Клиент</h3>

        <div>
            <label class="block mb-1 font-medium">Client Email</label>
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
                <span>Клиент активен</span>
            </label>
        </div>

        <button
            type="submit"
            class="bg-blue-600 text-white px-6 py-2 rounded">
            Сохранить
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
    $localOutbox = trim((string)($_POST['local_outbox'] ?? ''));

    $active = isset($_POST['active']) ? 1 : 0;

    $clientEmail = trim((string)($_POST['client_email'] ?? ''));
    $clientActive = isset($_POST['client_active']) ? 1 : 0;

    if ($localOutbox === '') {
        setFlash('error', 'Local Outbox: путь не может быть пустым');
        header('Location: index.php?action=referent_form' . ($id > 0 ? '&id=' . $id : ''));
        exit();
    }

    if ($localOutbox[0] !== '/') {
        setFlash('error', 'Local Outbox: укажите абсолютный путь (начинается с /)');
        header('Location: index.php?action=referent_form' . ($id > 0 ? '&id=' . $id : ''));
        exit();
    }

    if (str_contains($localOutbox, '..') || str_contains($localOutbox, "\0")) {
        setFlash('error', 'Local Outbox: некорректный путь');
        header('Location: index.php?action=referent_form' . ($id > 0 ? '&id=' . $id : ''));
        exit();
    }

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

        setFlash('success', 'Референт сохранён');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        writeLog('Referent save error: ' . $e->getMessage());

        setFlash('error', $e->getMessage());
    }

    redirectTo('dashboard');
}
function renderAccountForm(): void
{
    $pdo = getPdo();

    $referentId = (int)($_GET['referent_id'] ?? 0);

    if ($referentId <= 0) {
        setFlash('error', 'referent_id is required');
        redirectTo('dashboard');
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
			setFlash('error', 'Access denied');
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

    renderHeader('Внешний аккаунт');
    ?>
    <h2 class="text-2xl font-bold mb-6">
        Внешний почтовый аккаунт
    </h2>

    <form method="post" class="bg-white rounded shadow p-6 space-y-4">
        <input type="hidden" name="action" value="account_save">
        <input type="hidden" name="referent_id" value="<?= $referentId ?>">
        <input type="hidden" name="account_id" value="<?= h((string)$account['id']) ?>">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">

        <div>
            <label class="block mb-1">Email</label>
            <input
                type="email"
                name="email"
                required
                value="<?= h((string)$account['email']) ?>"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <div>
            <label class="block mb-1">Username</label>
            <input
                type="text"
                name="username"
                value="<?= h((string)$account['username']) ?>"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block mb-1">IMAP Host</label>
                <input
                    type="text"
                    name="imap_host"
                    required
                    value="<?= h((string)$account['imap_host']) ?>"
                    class="w-full border rounded px-3 py-2"
                >
            </div>

            <div>
                <label class="block mb-1">IMAP Port</label>
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
            <label class="block mb-1">IMAP Encryption</label>
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
                <label class="block mb-1">SMTP Host</label>
                <input
                    type="text"
                    name="smtp_host"
                    required
                    value="<?= h((string)$account['smtp_host']) ?>"
                    class="w-full border rounded px-3 py-2"
                >
            </div>

            <div>
                <label class="block mb-1">SMTP Port</label>
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
            <label class="block mb-1">SMTP Encryption</label>
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
            <label class="block mb-2 font-medium">Тип авторизации</label>

            <label class="mr-4">
                <input
                    type="radio"
                    name="auth_type"
                    value="plain"
                    <?= $account['auth_type'] === 'plain' ? 'checked' : '' ?>
                >
                Plain
            </label>

            <label>
                <input
                    type="radio"
                    name="auth_type"
                    value="oauth2"
                    <?= $account['auth_type'] === 'oauth2' ? 'checked' : '' ?>
                >
                OAuth2
            </label>
        </div>

        <div>
            <label class="block mb-1">Password (plain auth)</label>
            <input
                type="password"
                name="password"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <div>
            <label class="block mb-1">OAuth Provider</label>
            <select name="provider" class="w-full border rounded px-3 py-2">
                <option value="">-- Select --</option>
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
            <label class="block mb-1">Client ID</label>
            <input
                type="text"
                name="client_id"
                value="<?= h((string)$account['client_id']) ?>"
                class="w-full border rounded px-3 py-2"
            >
        </div>

        <div>
            <label class="block mb-1">Client Secret</label>
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
                <span>Аккаунт активен</span>
            </label>
        </div>

		<div class="flex gap-3">
			<button
				type="submit"
				class="bg-blue-600 text-white px-6 py-2 rounded">
				Сохранить
			</button>

			<?php if (!empty($account['id'])): ?>
				<button
					type="submit"
					form="oauth_initiate_form"
					class="bg-green-600 text-white px-6 py-2 rounded">
					Авторизовать OAuth2
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
        setFlash('error', 'referent_id is required');
        redirectTo('dashboard');
    }

    $email = trim((string)($_POST['email'] ?? ''));
	
    // Если email обязателен и он пустой, ЛИБО если он заполнен, но некорректен:
	
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setFlash('error', 'Указан некорректный или пустой Email-адрес');
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
                setFlash('error', 'Access denied');
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

        setFlash('success', 'Аккаунт сохранён');
    } catch (Throwable $e) {
        writeLog('External account save error: ' . $e->getMessage());
        setFlash('error', $e->getMessage());
    }

    redirectTo('dashboard');
}

function handleToggleActive(): void
{
    $pdo = getPdo();

    $entity = $_POST['entity'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if (!in_array($entity, ['referent', 'client', 'account', 'provider'], true) || $id <= 0) {
        setFlash('error', 'Invalid entity or id');
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
        setFlash('error', 'Record not found');
        redirectTo('dashboard');
    }

    $newActive = (int)!$row['active'];

    $stmt = $pdo->prepare("UPDATE {$table} SET active = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$newActive, $id]);

    writeLog("Toggled active for {$entity} ID {$id} → {$newActive}");

    setFlash('success', 'Статус изменён');
    redirectTo('dashboard');
}