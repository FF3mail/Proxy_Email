<?php
declare(strict_types=1);

/**
 * Referent list + card UI (prototype: Карточка референта).
 * Posts to existing action= handlers; modal shell from panel_modals.php.
 */

function panelReturnFields(int $referentId, string $tab): void
{
    echo '<input type="hidden" name="return_to" value="referent_view">';
    echo '<input type="hidden" name="return_id" value="' . (int) $referentId . '">';
    echo '<input type="hidden" name="tab" value="' . h($tab) . '">';
}

/**
 * Build read-only email/username suggestion lists for referent-card modals.
 *
 * Queries (getPdo):
 * - referents.local_inbox
 * - external_accounts.email, external_accounts.username
 * - clients.external_client_email, local_client_email, local_referent_email
 *
 * Consumed by:
 * - dlg-referent-edit → local_inbox (dl-sug-local-inbox)
 * - dlg-account-create / dlg-account-edit → email (dl-sug-ea-email), username (dl-sug-ea-username)
 * - dlg-rel-edit → external_client_email, local_client_email, local_referent_email
 *   (scoped lists prefer this referent, then global unique values)
 *
 * Intentionally without suggestions: display name (username on referent),
 * passwords/secrets, IMAP/SMTP hosts/ports, local_client_maildir (path, not email).
 *
 * @return array{
 *   local_inbox: list<string>,
 *   ea_email: list<string>,
 *   ea_username: list<string>,
 *   rel_external_client: list<string>,
 *   rel_local_client: list<string>,
 *   rel_local_referent: list<string>
 * }
 */
function fetchReferentCardEmailSuggestions(PDO $pdo, int $referentId, int $limit = 300): array
{
    $mergePrefer = static function (array $preferred, array $fallback, int $limit): array {
        $seen = [];
        $out = [];
        foreach (array_merge($preferred, $fallback) as $v) {
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            $key = strtolower($v);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $v;
            if (count($out) >= $limit) {
                break;
            }
        }
        natcasesort($out);
        return array_values($out);
    };

    // Global local inboxes (referent modal convenience; uniqueness enforced server-side).
    $localInboxAll = [];
    try {
        $stmt = $pdo->query('SELECT local_inbox FROM referents WHERE local_inbox IS NOT NULL AND local_inbox <> \'\'');
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) ?: [] as $row) {
            $v = trim((string) ($row[0] ?? ''));
            if ($v !== '') {
                $localInboxAll[] = $v;
            }
        }
    } catch (Throwable $e) {
        $localInboxAll = [];
    }

    // External accounts — emails and usernames (create/edit external modal).
    $eaEmails = [];
    $eaUsernames = [];
    try {
        $stmt = $pdo->query(
            'SELECT email, username FROM external_accounts
             WHERE (email IS NOT NULL AND email <> \'\')
                OR (username IS NOT NULL AND username <> \'\')'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $email = trim((string) ($row['email'] ?? ''));
            $user = trim((string) ($row['username'] ?? ''));
            if ($email !== '') {
                $eaEmails[] = $email;
                $eaUsernames[] = $email; // username often equals email
            }
            if ($user !== '') {
                $eaUsernames[] = $user;
            }
        }
    } catch (Throwable $e) {
        // leave empty
    }

    // Relationship fields — prefer this referent, then global.
    $scopedExt = [];
    $scopedLocalClient = [];
    $scopedLocalRef = [];
    $globalExt = [];
    $globalLocalClient = [];
    $globalLocalRef = [];

    try {
        $stmt = $pdo->prepare(
            'SELECT external_client_email, local_client_email, local_referent_email
             FROM clients WHERE referent_id = ?'
        );
        $stmt->execute([$referentId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $v = trim((string) ($row['external_client_email'] ?? ''));
            if ($v !== '') {
                $scopedExt[] = $v;
            }
            $v = trim((string) ($row['local_client_email'] ?? ''));
            if ($v !== '') {
                $scopedLocalClient[] = $v;
            }
            $v = trim((string) ($row['local_referent_email'] ?? ''));
            if ($v !== '') {
                $scopedLocalRef[] = $v;
            }
        }

        // This referent's own local_inbox is a strong suggestion for local_referent_email.
        $stmt = $pdo->prepare('SELECT local_inbox FROM referents WHERE id = ?');
        $stmt->execute([$referentId]);
        $mine = trim((string) ($stmt->fetchColumn() ?: ''));
        if ($mine !== '') {
            $scopedLocalRef[] = $mine;
        }

        $stmt = $pdo->query(
            'SELECT external_client_email, local_client_email, local_referent_email FROM clients'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $v = trim((string) ($row['external_client_email'] ?? ''));
            if ($v !== '') {
                $globalExt[] = $v;
            }
            $v = trim((string) ($row['local_client_email'] ?? ''));
            if ($v !== '') {
                $globalLocalClient[] = $v;
            }
            $v = trim((string) ($row['local_referent_email'] ?? ''));
            if ($v !== '') {
                $globalLocalRef[] = $v;
            }
        }
        // Also offer other referents' inboxes for local_referent_email fallback.
        foreach ($localInboxAll as $inbox) {
            $globalLocalRef[] = $inbox;
        }
    } catch (Throwable $e) {
        // leave empty
    }

    return [
        'local_inbox' => $mergePrefer([], $localInboxAll, $limit),
        'ea_email' => $mergePrefer([], $eaEmails, $limit),
        'ea_username' => $mergePrefer([], $eaUsernames, $limit),
        'rel_external_client' => $mergePrefer($scopedExt, $globalExt, $limit),
        'rel_local_client' => $mergePrefer($scopedLocalClient, $globalLocalClient, $limit),
        'rel_local_referent' => $mergePrefer($scopedLocalRef, $globalLocalRef, $limit),
    ];
}

/**
 * Render <datalist> elements for referent-card modal autocomplete.
 *
 * @param array<string, list<string>> $suggestions
 */
function renderReferentCardSuggestionDatalists(array $suggestions): void
{
    $map = [
        'dl-sug-local-inbox' => $suggestions['local_inbox'] ?? [],
        'dl-sug-ea-email' => $suggestions['ea_email'] ?? [],
        'dl-sug-ea-username' => $suggestions['ea_username'] ?? [],
        'dl-sug-rel-external-client' => $suggestions['rel_external_client'] ?? [],
        'dl-sug-rel-local-client' => $suggestions['rel_local_client'] ?? [],
        'dl-sug-rel-local-referent' => $suggestions['rel_local_referent'] ?? [],
    ];
    foreach ($map as $listId => $values) {
        echo '<datalist id="' . h($listId) . '">';
        foreach ($values as $v) {
            echo '<option value="' . h((string) $v) . '"></option>';
        }
        echo '</datalist>';
    }
}

/**
 * Clean referent list: ID, name, local_inbox, status, relationship count.
 */
function renderReferentListUi(): void
{
    $pdo = getPdo();
    $stmt = $pdo->query(
        'SELECT r.id, r.username, r.local_inbox, r.active,
                (SELECT COUNT(*) FROM clients c WHERE c.referent_id = r.id) AS rel_count
         FROM referents r
         ORDER BY r.id'
    );
    $rows = $stmt->fetchAll() ?: [];

    $flash = $GLOBALS['flash'] ?? null;
    $GLOBALS['flash'] = null;

    renderHeader(__('nav.referents'));
    renderPanelModalStyles();
    ?>
    <div class="pm-head">
        <h1><?= h(__('nav.referents')) ?></h1>
        <a href="index.php?action=referent_form" class="pm-btn pm-btn-primary">+ Создать референта</a>
    </div>
    <p class="pm-hint">Двойной щелчок, Enter или «Открыть» — карточка референта. Управление выполняется внутри карточки.</p>

    <?php if ($rows === []): ?>
        <div class="pm-empty">
            Референтов пока нет.<br>
            <a href="index.php?action=referent_form" class="pm-btn pm-btn-primary" style="margin-top:10px">+ Создать первого</a>
        </div>
    <?php else: ?>
        <div class="pm-card pm-table-wrap">
            <table class="pm-table pm-list-table" id="referent-list-table" data-pm-table="1">
                <thead>
                <tr>
                    <th data-sort="id">ID</th>
                    <th data-sort="username">Имя</th>
                    <th data-sort="local_inbox">local_inbox</th>
                    <th data-sort="active">Статус</th>
                    <th data-sort="rel_count">Связи</th>
                    <th>Действия</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $row):
                    $id = (int) $row['id'];
                    $url = 'index.php?action=referent_view&id=' . $id;
                    ?>
                    <tr tabindex="0" data-href="<?= h($url) ?>" class="<?= $i === 0 ? 'pm-sel' : '' ?>">
                        <td><?= $id ?></td>
                        <td><?= h((string) $row['username']) ?></td>
                        <td class="pm-mono"><?= h((string) $row['local_inbox']) ?></td>
                        <td>
                            <?php if ((int) $row['active'] === 1): ?>
                                <span class="pm-chip pm-chip-ok">Активен</span>
                            <?php else: ?>
                                <span class="pm-chip pm-chip-off">Отключён</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $row['rel_count'] ?></td>
                        <td>
                            <a class="pm-btn pm-btn-sm" href="<?= h($url) ?>" onclick="event.stopPropagation()">Открыть</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="pm-foot">Клавиши: <kbd>↑</kbd><kbd>↓</kbd> строки · <kbd>Enter</kbd> открыть · заголовки сортируют</p>
    <?php endif; ?>
    <?php
    renderPanelModalScripts(is_array($flash) ? $flash : null);
    renderFooter();
}

/**
 * Referent card with in-page tabs and modals.
 */
function renderReferentCardUi(): void
{
    $pdo = getPdo();
    $id = (int) ($_GET['id'] ?? 0);
    $tab = panelNormalizeTab(isset($_GET['tab']) ? (string) $_GET['tab'] : null);

    if ($id <= 0) {
        setFlash('error', 'Не указан референт');
        redirectTo('referent_list');
    }

    $stmt = $pdo->prepare(
        'SELECT r.id, r.username, r.local_inbox, r.local_outbox, r.active AS r_active,
                ea.id AS ea_id, ea.email AS ea_email, ea.username AS ea_username,
                ea.auth_type, ea.provider, ea.imap_host, ea.imap_port, ea.imap_encryption,
                ea.smtp_host, ea.smtp_port, ea.smtp_encryption, ea.active AS ea_active,
                ea.client_id AS ea_client_id,
                ot.expires_at
         FROM referents r
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

    $relationships = fetchRelationshipsForReferent($pdo, $id);
    $accounts = fetchExternalAccountsForRelationshipForm($pdo, $id, null);
    // Autocomplete sources for modal email fields (datalists rendered once per card).
    $emailSuggestions = fetchReferentCardEmailSuggestions($pdo, $id);
    $providersStmt = $pdo->query(
        'SELECT code, name FROM oauth_providers WHERE active = 1 ORDER BY name'
    );
    $providers = $providersStmt ? ($providersStmt->fetchAll() ?: []) : [];
    $localMail = loadLocalMailClientSettings();

    $hasAcc = !empty($row['ea_id']);
    $complete = 0;
    foreach ($relationships as $rel) {
        if (relationshipMissingFields($rel) === [] && !relationshipIsLegacyOnly($rel)) {
            $complete++;
        }
    }
    $totalRel = count($relationships);
    $needAttention = $totalRel - $complete;
    $csrf = h($_SESSION['csrf_token'] ?? '');
    $flash = $GLOBALS['flash'] ?? null;
    $GLOBALS['flash'] = null;

    $tabs = [
        'overview' => 'Обзор',
        'local' => 'Локальный ящик',
        'external' => 'Внешний аккаунт',
        'clients' => 'Клиенты и связи',
    ];

    renderHeader((string) $row['username']);
    renderPanelModalStyles();
    ?>
    <div class="pm-crumbs">
        <a href="index.php?action=referent_list"><?= h(__('nav.referents')) ?></a> › <?= h((string) $row['username']) ?>
    </div>
    <div class="pm-head">
        <h1>
            <?= h((string) $row['username']) ?>
            <?php if ((int) $row['r_active'] === 1): ?>
                <span class="pm-chip pm-chip-ok">Активен</span>
            <?php else: ?>
                <span class="pm-chip pm-chip-off">Отключён</span>
            <?php endif; ?>
        </h1>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="button" class="pm-btn" data-pm-open="dlg-referent-edit">✎ Изменить</button>
            <form method="post" action="index.php?action=toggle_active" style="display:inline">
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="entity" value="referent">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="return_action" value="referent_view">
                <input type="hidden" name="referent_id" value="<?= $id ?>">
                <input type="hidden" name="tab" value="<?= h($tab) ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <button type="submit" class="pm-btn"><?= (int) $row['r_active'] === 1 ? 'Отключить' : 'Включить' ?></button>
            </form>
            <button type="button" class="pm-btn pm-btn-danger" data-pm-open="dlg-referent-delete">Удалить</button>
        </div>
    </div>

    <div class="pm-tabs" role="tablist" aria-label="Разделы карточки референта">
        <?php $i = 0; foreach ($tabs as $key => $label): $i++; ?>
            <a class="pm-tab" role="tab" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>"
               href="index.php?action=referent_view&id=<?= $id ?>&tab=<?= h($key) ?>"
               data-tab-key="<?= h($key) ?>"><?= h($label) ?><kbd><?= $i ?></kbd></a>
        <?php endforeach; ?>
    </div>

    <div id="pm-panel" role="tabpanel">
    <?php if ($tab === 'overview'): ?>
        <div class="pm-stats">
            <div class="pm-stat"><b><?= $totalRel ?></b><span>клиентов у референта</span></div>
            <div class="pm-stat"><b><?= $complete ?></b><span>настроено полностью</span></div>
            <div class="pm-stat"><b><?= $needAttention ?></b><span>требуют внимания</span></div>
        </div>
        <div class="pm-card">
            <div class="pm-ch"><h3>Готовность к работе</h3></div>
            <p class="pm-hint">Что нужно, чтобы демон обрабатывал почту этого референта. Кнопки справа открывают настройку без ухода с карточки.</p>
            <ul class="pm-steps">
                <li>
                    <span class="pm-dot pm-dot-ok">✓</span>
                    <div class="pm-t">Локальный ящик<small><?= h((string) $row['local_inbox']) ?></small></div>
                    <button type="button" class="pm-btn pm-btn-sm" data-pm-open="dlg-referent-edit">Изменить</button>
                </li>
                <li>
                    <span class="pm-dot <?= $hasAcc ? 'pm-dot-ok' : 'pm-dot-todo' ?>"><?= $hasAcc ? '✓' : '!' ?></span>
                    <div class="pm-t">Внешний аккаунт
                        <small><?= $hasAcc
                            ? h((string) $row['ea_email']) . ' · ' . h((string) $row['auth_type'])
                            : 'Не настроен — демон не сможет забирать и отправлять почту' ?></small>
                    </div>
                    <?php if ($hasAcc): ?>
                        <button type="button" class="pm-btn pm-btn-sm" data-pm-open="dlg-account-edit">Изменить</button>
                    <?php else: ?>
                        <button type="button" class="pm-btn pm-btn-sm pm-btn-primary" data-pm-open="dlg-account-create">Настроить</button>
                    <?php endif; ?>
                </li>
                <li>
                    <span class="pm-dot <?= ($totalRel > 0 && $complete === $totalRel) ? 'pm-dot-ok' : 'pm-dot-todo' ?>">
                        <?= ($totalRel > 0 && $complete === $totalRel) ? '✓' : '!' ?>
                    </span>
                    <div class="pm-t">Клиенты и связи<small><?= $complete ?> из <?= $totalRel ?> заполнены полностью</small></div>
                    <a class="pm-btn pm-btn-sm" href="index.php?action=referent_view&id=<?= $id ?>&tab=clients">Открыть</a>
                </li>
            </ul>
        </div>
    <?php elseif ($tab === 'local'): ?>
        <div class="pm-card">
            <div class="pm-ch">
                <h3>Локальный ящик (iRedMail)</h3>
                <button type="button" class="pm-btn" data-pm-open="dlg-referent-edit">✎ Изменить</button>
            </div>
            <p class="pm-hint">Параметры для настройки Thunderbird, Outlook и других клиентов. Пароль в панели не хранится.</p>
            <dl class="pm-dl">
                <dt>Email / логин</dt>
                <dd class="pm-mono"><?= h((string) $row['local_inbox']) ?>
                    <button type="button" class="pm-copy" data-copy="<?= h((string) $row['local_inbox']) ?>">копировать</button>
                </dd>
                <dt>IMAP</dt>
                <dd class="pm-mono"><?= h($localMail['imap_host']) ?>:<?= (int) $localMail['imap_port'] ?> (<?= h(formatMailEncryption($localMail['imap_encryption'])) ?>)</dd>
                <dt>SMTP</dt>
                <dd class="pm-mono"><?= h($localMail['smtp_host']) ?>:<?= (int) $localMail['smtp_port'] ?> (<?= h(formatMailEncryption($localMail['smtp_encryption'])) ?>)</dd>
                <?php if (!empty($row['local_outbox'])): ?>
                <dt>Maildir (системный)</dt>
                <dd class="pm-mono" style="font-size:12px"><?= h((string) $row['local_outbox']) ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    <?php elseif ($tab === 'external'): ?>
        <?php if ($hasAcc): ?>
        <div class="pm-card">
            <div class="pm-ch">
                <h3>Внешний почтовый аккаунт</h3>
                <div style="display:flex;gap:8px">
                    <button type="button" class="pm-btn" data-pm-open="dlg-account-edit">✎ Изменить</button>
                    <button type="button" class="pm-btn pm-btn-danger" data-pm-open="dlg-account-delete">Удалить</button>
                </div>
            </div>
            <p class="pm-hint">Демон опрашивает внешний IMAP и отправляет исходящую почту через внешний SMTP. Пароли и токены хранятся зашифрованно и не показываются.</p>
            <dl class="pm-dl">
                <dt>Email</dt><dd class="pm-mono"><?= h((string) $row['ea_email']) ?></dd>
                <dt>Логин IMAP/SMTP</dt><dd class="pm-mono"><?= h((string) ($row['ea_username'] ?: $row['ea_email'])) ?></dd>
                <dt>Авторизация</dt><dd><?= h((string) $row['auth_type']) ?><?= $row['provider'] ? ' (' . h((string) $row['provider']) . ')' : '' ?></dd>
                <dt>Статус</dt>
                <dd><?php if ((int) $row['ea_active'] === 1): ?><span class="pm-chip pm-chip-ok">Активен</span><?php else: ?><span class="pm-chip pm-chip-off">Отключён</span><?php endif; ?></dd>
                <dt>IMAP</dt><dd class="pm-mono"><?= h((string) $row['imap_host']) ?>:<?= (int) $row['imap_port'] ?> (<?= h(formatMailEncryption((string) $row['imap_encryption'])) ?>)</dd>
                <dt>SMTP</dt><dd class="pm-mono"><?= h((string) $row['smtp_host']) ?>:<?= (int) $row['smtp_port'] ?> (<?= h(formatMailEncryption((string) $row['smtp_encryption'])) ?>)</dd>
                <?php if ($row['auth_type'] === 'oauth2' && !empty($row['expires_at'])): ?>
                <dt>OAuth2 токен</dt>
                <dd>до <?= h((string) $row['expires_at']) ?>
                    <span class="pm-chip <?= strtotime((string) $row['expires_at']) > time() ? 'pm-chip-ok' : 'pm-chip-warn' ?>">
                        <?= strtotime((string) $row['expires_at']) > time() ? 'активен' : 'истёк' ?>
                    </span>
                </dd>
                <?php endif; ?>
            </dl>
        </div>
        <?php else: ?>
        <div class="pm-empty">
            Внешний аккаунт не настроен. Без него демон не сможет синхронизировать почту с удалённым сервером.<br>
            <button type="button" class="pm-btn pm-btn-primary" style="margin-top:10px" data-pm-open="dlg-account-create">+ Создать внешний аккаунт</button>
        </div>
        <?php endif; ?>
    <?php else: /* clients */ ?>
        <div class="pm-card">
            <div class="pm-ch">
                <h3>Клиенты и связи</h3>
                <button type="button" class="pm-btn pm-btn-primary" data-rel-new="1">+ Добавить связь</button>
            </div>
            <p class="pm-hint">Двойной щелчок или Enter по строке открывает редактирование. Заголовки столбцов сортируют таблицу.</p>
            <?php if ($relationships === []): ?>
                <div class="pm-empty">
                    Связей пока нет.<br>
                    <button type="button" class="pm-btn pm-btn-primary" style="margin-top:10px" data-rel-new="1">+ Добавить первую</button>
                </div>
            <?php else: ?>
                <div class="pm-table-wrap">
                    <table class="pm-table" id="rel-table">
                        <thead>
                        <tr>
                            <th data-sort="external_client_email">Внешний клиент</th>
                            <th data-sort="local_client_email">Локальный клиент</th>
                            <th data-sort="local_referent_email">Локальный ящик референта</th>
                            <th data-sort="active">Статус</th>
                        </tr>
                        </thead>
                        <tbody id="rel-tb">
                        <?php foreach ($relationships as $ri => $rel):
                            $legacy = relationshipIsLegacyOnly($rel);
                            $missing = relationshipMissingFields($rel);
                            $isComplete = !$legacy && $missing === [];
                            $ext = $legacy
                                ? (string) $rel['email']
                                : (string) ($rel['external_client_email'] ?? '');
                            $payload = [
                                'id' => (int) $rel['id'],
                                'external_client_email' => relationshipExternalClientFormValue($rel),
                                'local_client_email' => (string) ($rel['local_client_email'] ?? ''),
                                'local_referent_email' => (string) ($rel['local_referent_email'] ?? ''),
                                'external_account_id' => (string) ($rel['external_account_id'] ?? ''),
                                'local_client_maildir' => (string) ($rel['local_client_maildir'] ?? ''),
                                'active' => (int) ($rel['active'] ?? 0) === 1 ? 1 : 0,
                            ];
                            ?>
                            <tr tabindex="0" data-open="1" data-rel='<?= h(json_encode($payload, JSON_UNESCAPED_UNICODE)) ?>'
                                class="<?= $ri === 0 ? 'pm-sel' : '' ?>">
                                <td><?= h($ext !== '' ? $ext : '—') ?></td>
                                <td><?= h($legacy ? '—' : ((string) ($rel['local_client_email'] ?: '—'))) ?></td>
                                <td><?= h($legacy ? '—' : ((string) ($rel['local_referent_email'] ?: '—'))) ?></td>
                                <td>
                                    <?php if ((int) $rel['active'] !== 1): ?>
                                        <span class="pm-chip pm-chip-off">Отключена</span>
                                    <?php elseif ($isComplete): ?>
                                        <span class="pm-chip pm-chip-ok">Полная</span>
                                    <?php else: ?>
                                        <span class="pm-chip pm-chip-warn">Не заполнено</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    </div>
    <p class="pm-foot">Клавиши: <kbd>1</kbd>–<kbd>4</kbd> вкладки · <kbd>↑</kbd><kbd>↓</kbd> строки таблицы · <kbd>Enter</kbd> открыть · <kbd>Esc</kbd> закрыть окно · <kbd>Ctrl</kbd>+<kbd>Enter</kbd> сохранить</p>
    <?php renderReferentCardSuggestionDatalists($emailSuggestions); ?>
    <!-- Modal: edit referent -->
    <dialog class="pm-dialog" id="dlg-referent-edit" aria-modal="true">
        <form method="post" action="index.php?action=referent_save">
            <div class="pm-mh"><h2>Референт</h2><button type="button" class="pm-x" data-pm-close aria-label="Закрыть">×</button></div>
            <div class="pm-mb"><div class="pm-fg">
                <input type="hidden" name="action" value="referent_save">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <?php panelReturnFields($id, $tab === 'local' ? 'local' : ($tab === 'overview' ? 'overview' : $tab)); ?>
                <div class="pm-f pm-full">
                    <label for="ref_username">ФИО референта *</label>
                    <input id="ref_username" type="text" name="username" required value="<?= h((string) $row['username']) ?>" autocomplete="off">
                </div>
                <div class="pm-f pm-full">
                    <label for="ref_inbox">Локальный ящик (логин iRedMail) *</label>
                    <input id="ref_inbox" type="email" name="local_inbox" required
                           list="dl-sug-local-inbox"
                           value="<?= h((string) $row['local_inbox']) ?>" autocomplete="off">
                    <span class="pm-help">Адрес ящика на сервере DELTA-транзит</span>
                </div>
                <?php if (!empty($row['local_outbox'])): ?>
                <div class="pm-f pm-full">
                    <label>Maildir (системный)</label>
                    <input type="text" readonly value="<?= h((string) $row['local_outbox']) ?>" class="pm-mono">
                </div>
                <?php endif; ?>
                <div class="pm-f pm-full">
                    <label class="pm-chk"><input type="checkbox" name="active" value="1" <?= (int) $row['r_active'] === 1 ? 'checked' : '' ?>> Референт активен</label>
                </div>
            </div></div>
            <div class="pm-mf"><span></span><div class="pm-r">
                <button type="button" class="pm-btn" data-pm-close>Отмена</button>
                <button type="submit" class="pm-btn pm-btn-primary">Сохранить</button>
            </div></div>
        </form>
    </dialog>

    <!-- Modal: delete referent -->
    <dialog class="pm-dialog" id="dlg-referent-delete" aria-modal="true" data-pm-nodirty="1">
        <form method="post" action="index.php?action=referent_delete">
            <div class="pm-mh"><h2>Удалить референта?</h2><button type="button" class="pm-x" data-pm-close aria-label="Закрыть">×</button></div>
            <div class="pm-mb">
                <p style="margin:0">Будет удалён референт «<?= h((string) $row['username']) ?>» вместе с внешним аккаунтом и связями с клиентами. Действие нельзя отменить.</p>
                <input type="hidden" name="action" value="referent_delete">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            </div>
            <div class="pm-mf"><span></span><div class="pm-r">
                <button type="button" class="pm-btn" data-pm-close>Отмена</button>
                <button type="submit" class="pm-btn pm-btn-danger-solid" data-pm-focus>Удалить</button>
            </div></div>
        </form>
    </dialog>

    <!-- Modal: create external account -->
    <dialog class="pm-dialog" id="dlg-account-create" aria-modal="true">
        <form method="post" action="index.php?action=account_save" id="form-account-create">
            <div class="pm-mh"><h2>Новый внешний аккаунт</h2><button type="button" class="pm-x" data-pm-close aria-label="Закрыть">×</button></div>
            <div class="pm-mb"><div class="pm-fg">
                <input type="hidden" name="action" value="account_save">
                <input type="hidden" name="referent_id" value="<?= $id ?>">
                <input type="hidden" name="account_id" value="">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <?php panelReturnFields($id, 'external'); ?>
                <div class="pm-f pm-full"><label>Email *</label><input type="email" name="email" required list="dl-sug-ea-email" autocomplete="off"></div>
                <div class="pm-f pm-full"><label>Логин IMAP/SMTP</label><input type="text" name="username" list="dl-sug-ea-username" autocomplete="off"><span class="pm-help">Если пусто — используется email</span></div>
                <div class="pm-f pm-full"><label>Авторизация</label>
                    <select name="auth_type" data-auth-toggle="create">
                        <option value="plain">Пароль</option>
                        <option value="oauth2">OAuth2</option>
                    </select>
                </div>
                <div class="pm-sect">Входящая почта (IMAP)</div>
                <div class="pm-f"><label>Сервер *</label><input type="text" name="imap_host" required></div>
                <div class="pm-f"><label>Порт</label><input type="number" name="imap_port" value="993"></div>
                <div class="pm-f pm-full"><label>Шифрование</label>
                    <select name="imap_encryption"><option value="ssl">SSL/TLS</option><option value="tls">STARTTLS</option><option value="none">Без шифрования</option></select>
                </div>
                <div class="pm-sect">Исходящая почта (SMTP)</div>
                <div class="pm-f"><label>Сервер *</label><input type="text" name="smtp_host" required></div>
                <div class="pm-f"><label>Порт</label><input type="number" name="smtp_port" value="587"></div>
                <div class="pm-f pm-full"><label>Шифрование</label>
                    <select name="smtp_encryption"><option value="tls">STARTTLS</option><option value="ssl">SSL/TLS</option><option value="none">Без шифрования</option></select>
                </div>
                <div class="pm-f pm-full" data-plain-only="create">
                    <label>Пароль *</label>
                    <input type="password" name="password" autocomplete="new-password">
                    <span class="pm-help">Хранится в зашифрованном виде и не показывается</span>
                </div>
                <div class="pm-f pm-full" data-oauth-only="create" style="display:none">
                    <label>OAuth provider</label>
                    <select name="provider"><option value="">— Выберите —</option>
                    <?php foreach ($providers as $p): ?>
                        <option value="<?= h((string) $p['code']) ?>"><?= h((string) $p['name']) ?></option>
                    <?php endforeach; ?>
                    </select>
                </div>
                <div class="pm-f" data-oauth-only="create" style="display:none"><label>Client ID</label><input type="text" name="client_id" autocomplete="off"></div>
                <div class="pm-f" data-oauth-only="create" style="display:none"><label>Client Secret</label><input type="password" name="client_secret" autocomplete="new-password"></div>
                <div class="pm-notice pm-notice-info" data-oauth-only="create" style="display:none">OAuth2: после сохранения можно авторизовать аккаунт на вкладке «Внешний аккаунт».</div>
                <div class="pm-f pm-full"><label class="pm-chk"><input type="checkbox" name="active" value="1" checked> Аккаунт активен</label></div>
            </div></div>
            <div class="pm-mf"><span></span><div class="pm-r">
                <button type="button" class="pm-btn" data-pm-close>Отмена</button>
                <button type="submit" class="pm-btn pm-btn-primary">Сохранить</button>
            </div></div>
        </form>
    </dialog>
    <?php if ($hasAcc): ?>
    <dialog class="pm-dialog" id="dlg-account-edit" aria-modal="true">
        <form method="post" action="index.php?action=account_save">
            <div class="pm-mh"><h2>Внешний аккаунт</h2><button type="button" class="pm-x" data-pm-close aria-label="Закрыть">×</button></div>
            <div class="pm-mb"><div class="pm-fg">
                <input type="hidden" name="action" value="account_save">
                <input type="hidden" name="referent_id" value="<?= $id ?>">
                <input type="hidden" name="account_id" value="<?= (int) $row['ea_id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <?php panelReturnFields($id, 'external'); ?>
                <div class="pm-f pm-full"><label>Email *</label><input type="email" name="email" required list="dl-sug-ea-email" value="<?= h((string) $row['ea_email']) ?>" autocomplete="off"></div>
                <div class="pm-f pm-full"><label>Логин IMAP/SMTP</label><input type="text" name="username" list="dl-sug-ea-username" value="<?= h((string) $row['ea_username']) ?>" autocomplete="off"><span class="pm-help">Если пусто — используется email</span></div>
                <div class="pm-f pm-full"><label>Авторизация</label>
                    <select name="auth_type" data-auth-toggle="edit">
                        <option value="plain" <?= $row['auth_type'] === 'plain' ? 'selected' : '' ?>>Пароль</option>
                        <option value="oauth2" <?= $row['auth_type'] === 'oauth2' ? 'selected' : '' ?>>OAuth2</option>
                    </select>
                </div>
                <div class="pm-sect">Входящая почта (IMAP)</div>
                <div class="pm-f"><label>Сервер *</label><input type="text" name="imap_host" required value="<?= h((string) $row['imap_host']) ?>"></div>
                <div class="pm-f"><label>Порт</label><input type="number" name="imap_port" value="<?= (int) $row['imap_port'] ?>"></div>
                <div class="pm-f pm-full"><label>Шифрование</label>
                    <select name="imap_encryption">
                        <?php foreach (['ssl' => 'SSL/TLS', 'tls' => 'STARTTLS', 'none' => 'Без шифрования'] as $k => $lbl): ?>
                            <option value="<?= $k ?>" <?= $row['imap_encryption'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pm-sect">Исходящая почта (SMTP)</div>
                <div class="pm-f"><label>Сервер *</label><input type="text" name="smtp_host" required value="<?= h((string) $row['smtp_host']) ?>"></div>
                <div class="pm-f"><label>Порт</label><input type="number" name="smtp_port" value="<?= (int) $row['smtp_port'] ?>"></div>
                <div class="pm-f pm-full"><label>Шифрование</label>
                    <select name="smtp_encryption">
                        <?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => 'Без шифрования'] as $k => $lbl): ?>
                            <option value="<?= $k ?>" <?= $row['smtp_encryption'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pm-f pm-full" data-plain-only="edit">
                    <label>Пароль</label>
                    <input type="password" name="password" placeholder="оставьте пустым, чтобы не менять" autocomplete="new-password">
                    <span class="pm-help">Хранится в зашифрованном виде и не показывается</span>
                </div>
                <div class="pm-f pm-full" data-oauth-only="edit" style="display:none">
                    <label>OAuth provider</label>
                    <select name="provider"><option value="">— Выберите —</option>
                    <?php foreach ($providers as $p): ?>
                        <option value="<?= h((string) $p['code']) ?>" <?= $row['provider'] === $p['code'] ? 'selected' : '' ?>><?= h((string) $p['name']) ?></option>
                    <?php endforeach; ?>
                    </select>
                </div>
                <div class="pm-f" data-oauth-only="edit" style="display:none"><label>Client ID</label><input type="text" name="client_id" value="<?= h((string) ($row['ea_client_id'] ?? '')) ?>" autocomplete="off"></div>
                <div class="pm-f" data-oauth-only="edit" style="display:none"><label>Client Secret</label><input type="password" name="client_secret" placeholder="оставьте пустым, чтобы не менять" autocomplete="new-password"></div>
                <div class="pm-f pm-full"><label class="pm-chk"><input type="checkbox" name="active" value="1" <?= (int) $row['ea_active'] === 1 ? 'checked' : '' ?>> Аккаунт активен</label></div>
            </div></div>
            <div class="pm-mf">
                <span>
                <?php if (!empty($row['ea_id'])): ?>
                    <button type="submit" form="oauth_initiate_form_card" class="pm-btn">OAuth2 авторизация</button>
                <?php endif; ?>
                </span>
                <div class="pm-r">
                    <button type="button" class="pm-btn" data-pm-close>Отмена</button>
                    <button type="submit" class="pm-btn pm-btn-primary">Сохранить</button>
                </div>
            </div>
        </form>
    </dialog>
    <form id="oauth_initiate_form_card" method="post" action="index.php" class="hidden" style="display:none">
        <input type="hidden" name="action" value="oauth_initiate">
        <input type="hidden" name="account_id" value="<?= (int) $row['ea_id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    </form>
    <dialog class="pm-dialog" id="dlg-account-delete" aria-modal="true" data-pm-nodirty="1">
        <form method="post" action="index.php?action=account_delete">
            <div class="pm-mh"><h2>Удалить внешний аккаунт?</h2><button type="button" class="pm-x" data-pm-close aria-label="Закрыть">×</button></div>
            <div class="pm-mb">
                <p style="margin:0">Аккаунт <?= h((string) $row['ea_email']) ?> будет удалён. Связи, использующие его, останутся без внешнего аккаунта.</p>
                <input type="hidden" name="action" value="account_delete">
                <input type="hidden" name="id" value="<?= (int) $row['ea_id'] ?>">
                <input type="hidden" name="referent_id" value="<?= $id ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <?php panelReturnFields($id, 'external'); ?>
            </div>
            <div class="pm-mf"><span></span><div class="pm-r">
                <button type="button" class="pm-btn" data-pm-close>Отмена</button>
                <button type="submit" class="pm-btn pm-btn-danger-solid" data-pm-focus>Удалить</button>
            </div></div>
        </form>
    </dialog>
    <?php endif; ?>
    <!-- Modal: relationship create/edit -->
    <dialog class="pm-dialog" id="dlg-rel-edit" aria-modal="true">
        <form method="post" action="index.php?action=relationship_save" id="form-rel-edit">
            <div class="pm-mh"><h2 id="rel-dlg-title">Новая связь с клиентом</h2><button type="button" class="pm-x" data-pm-close aria-label="Закрыть">×</button></div>
            <div class="pm-mb"><div class="pm-fg">
                <input type="hidden" name="action" value="relationship_save">
                <input type="hidden" name="id" id="rel_id" value="">
                <input type="hidden" name="referent_id" value="<?= $id ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <?php panelReturnFields($id, 'clients'); ?>
                <div class="pm-f pm-full">
                    <label>Внешний адрес клиента *</label>
                    <input type="email" name="external_client_email" id="rel_external_client_email" required
                           list="dl-sug-rel-external-client" autocomplete="off">
                    <span class="pm-help">Откуда/куда клиент пишет в интернете</span>
                </div>
                <div class="pm-f">
                    <label>Локальный адрес клиента *</label>
                    <input type="email" name="local_client_email" id="rel_local_client_email" required
                           list="dl-sug-rel-local-client" autocomplete="off">
                </div>
                <div class="pm-f">
                    <label>Локальный ящик референта для клиента *</label>
                    <input type="email" name="local_referent_email" id="rel_local_referent_email" required
                           list="dl-sug-rel-local-referent" autocomplete="off">
                </div>
                <div class="pm-f pm-full">
                    <label>Внешний аккаунт референта *</label>
                    <select name="external_account_id" id="rel_external_account_id" required>
                        <option value="">— выберите —</option>
                        <?php foreach ($accounts as $acc):
                            $linkedId = $acc['linked_client_id'] ?? null;
                            $disabled = $linkedId !== null && (int) $linkedId > 0;
                            $inactive = (int) $acc['active'] !== 1;
                            $label = (string) $acc['email'];
                            if ($inactive) { $label .= ' (неактивен)'; }
                            if ($disabled) { $label .= ' — занят'; }
                            ?>
                            <option value="<?= (int) $acc['id'] ?>"
                                    data-linked="<?= $disabled ? (int) $linkedId : 0 ?>"
                                    <?= $disabled ? 'disabled' : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($accounts === []): ?>
                        <span class="pm-help">Сначала создайте внешний аккаунт на вкладке «Внешний аккаунт»</span>
                    <?php endif; ?>
                </div>
                <div class="pm-f pm-full">
                    <label>Maildir клиента (необязательно)</label>
                    <input type="text" name="local_client_maildir" id="rel_local_client_maildir" class="pm-mono" autocomplete="off">
                </div>
                <div class="pm-f pm-full">
                    <label class="pm-chk"><input type="checkbox" name="active" id="rel_active" value="1" checked> Связь активна</label>
                </div>
            </div></div>
            <div class="pm-mf">
                <span id="rel-dlg-extra"></span>
                <div class="pm-r">
                    <button type="button" class="pm-btn" data-pm-close>Отмена</button>
                    <button type="submit" class="pm-btn pm-btn-primary">Сохранить</button>
                </div>
            </div>
        </form>
    </dialog>

    <!-- Confirm delete relationship (filled by JS) -->
    <dialog class="pm-dialog" id="dlg-rel-delete" aria-modal="true" data-pm-nodirty="1">
        <form method="post" action="index.php?action=relationship_delete" id="form-rel-delete">
            <div class="pm-mh"><h2>Удалить связь?</h2><button type="button" class="pm-x" data-pm-close aria-label="Закрыть">×</button></div>
            <div class="pm-mb">
                <p style="margin:0" id="rel-del-text">Связь будет удалена. Действие нельзя отменить.</p>
                <input type="hidden" name="action" value="relationship_delete">
                <input type="hidden" name="id" id="rel_del_id" value="">
                <input type="hidden" name="referent_id" value="<?= $id ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <?php panelReturnFields($id, 'clients'); ?>
            </div>
            <div class="pm-mf"><span></span><div class="pm-r">
                <button type="button" class="pm-btn" data-pm-close>Отмена</button>
                <button type="submit" class="pm-btn pm-btn-danger-solid" data-pm-focus>Удалить</button>
            </div></div>
        </form>
    </dialog>
    <script>
    (function () {
      var REF_ID = <?= (int) $id ?>;
      var TAB_KEYS = ['overview', 'local', 'external', 'clients'];

      function syncAuth(scope) {
        var sel = document.querySelector('[data-auth-toggle="' + scope + '"]');
        if (!sel) return;
        var oauth = sel.value === 'oauth2';
        document.querySelectorAll('[data-plain-only="' + scope + '"]').forEach(function (el) {
          el.style.display = oauth ? 'none' : '';
        });
        document.querySelectorAll('[data-oauth-only="' + scope + '"]').forEach(function (el) {
          el.style.display = oauth ? '' : 'none';
        });
      }
      document.querySelectorAll('[data-auth-toggle]').forEach(function (sel) {
        sel.addEventListener('change', function () { syncAuth(sel.getAttribute('data-auth-toggle')); });
        syncAuth(sel.getAttribute('data-auth-toggle'));
      });

      document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var v = btn.getAttribute('data-copy') || '';
          if (navigator.clipboard) navigator.clipboard.writeText(v);
          if (window.PanelModal) window.PanelModal.toast('Скопировано');
        });
      });

      function fillRel(data) {
        var isNew = !data || !data.id;
        document.getElementById('rel-dlg-title').textContent = isNew ? 'Новая связь с клиентом' : 'Связь с клиентом';
        document.getElementById('rel_id').value = isNew ? '' : String(data.id);
        document.getElementById('rel_external_client_email').value = data && data.external_client_email ? data.external_client_email : '';
        document.getElementById('rel_local_client_email').value = data && data.local_client_email ? data.local_client_email : '';
        document.getElementById('rel_local_referent_email').value = data && data.local_referent_email ? data.local_referent_email : '';
        document.getElementById('rel_local_client_maildir').value = data && data.local_client_maildir ? data.local_client_maildir : '';
        document.getElementById('rel_active').checked = !data || Number(data.active) === 1;
        var sel = document.getElementById('rel_external_account_id');
        var want = data && data.external_account_id ? String(data.external_account_id) : '';
        Array.prototype.forEach.call(sel.options, function (opt) {
          var linked = Number(opt.getAttribute('data-linked') || 0);
          // re-enable option if it is linked to the relationship being edited
          if (linked > 0 && data && Number(data.id) === linked) {
            opt.disabled = false;
          } else if (linked > 0) {
            opt.disabled = true;
          }
        });
        sel.value = want;
        var extra = document.getElementById('rel-dlg-extra');
        extra.innerHTML = '';
        if (!isNew) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'pm-btn pm-btn-danger';
          btn.textContent = 'Удалить связь';
          btn.addEventListener('click', function () {
            var editDlg = document.getElementById('dlg-rel-edit');
            if (window.PanelModal) window.PanelModal.close(editDlg, true);
            document.getElementById('rel_del_id').value = String(data.id);
            document.getElementById('rel-del-text').textContent =
              'Связь с клиентом ' + (data.external_client_email || '') + ' будет удалена. Действие нельзя отменить.';
            if (window.PanelModal) window.PanelModal.open(document.getElementById('dlg-rel-delete'));
          });
          extra.appendChild(btn);
        }
      }

      function openRel(data) {
        fillRel(data || null);
        if (window.PanelModal) window.PanelModal.open(document.getElementById('dlg-rel-edit'));
      }

      document.querySelectorAll('[data-rel-new="1"]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          openRel(null);
        });
      });

      window.ReferentCardRels = {
        open: openRel,
        parse: function (tr) {
          try { return JSON.parse(tr.getAttribute('data-rel') || '{}'); } catch (e) { return null; }
        }
      };

      document.addEventListener('keydown', function (e) {
        var openDlg = document.querySelector('dialog.pm-dialog[open]');
        if (openDlg || e.ctrlKey || e.metaKey || e.altKey) return;
        var tag = (document.activeElement && document.activeElement.tagName) || '';
        if (/^(INPUT|SELECT|TEXTAREA)$/.test(tag)) return;
        var i = '1234'.indexOf(e.key);
        if (i >= 0) {
          e.preventDefault();
          window.location = 'index.php?action=referent_view&id=' + REF_ID + '&tab=' + TAB_KEYS[i];
        }
      });
    })();
    </script>
    <?php
    renderPanelModalScripts(is_array($flash) ? $flash : null);
    ?>
    <script>
    (function () {
      var table = document.getElementById('rel-table');
      if (!table || !window.PanelTable || !window.ReferentCardRels) return;
      window.PanelTable.bind(table, {
        rowSelector: 'tr[data-rel]',
        onOpen: function (tr) {
          window.ReferentCardRels.open(window.ReferentCardRels.parse(tr));
        }
      });
    })();
    </script>
    <?php
    renderFooter();
}