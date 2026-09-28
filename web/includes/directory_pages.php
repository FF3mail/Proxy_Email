<?php
declare(strict_types=1);

/**
 * Read-only directory pages: Internet accounts, Local accounts, Clients.
 * Navigation into referent cards goes through a confirmation dialog.
 */

/**
 * Shared confirm dialog + row keyboard/open helpers for directories.
 */
function renderDirectoryTransitionShell(?array $flashToast = null): void
{
    renderPanelModalStyles();
    ?>
    <dialog class="pm-dialog" id="dlg-dir-goto" aria-modal="true" data-pm-nodirty="1">
        <form method="dialog" id="form-dir-goto">
            <div class="pm-mh">
                <h2><?= h(__('directory.confirm_title')) ?></h2>
                <button type="button" class="pm-x" data-pm-close aria-label="<?= h(__('common.cancel')) ?>">×</button>
            </div>
            <div class="pm-mb">
                <p style="margin:0" id="dir-goto-text"></p>
            </div>
            <div class="pm-mf">
                <span></span>
                <div class="pm-r">
                    <button type="button" class="pm-btn" data-pm-close><?= h(__('common.cancel')) ?></button>
                    <button type="submit" class="pm-btn pm-btn-primary" data-pm-focus id="dir-goto-confirm"><?= h(__('directory.continue')) ?></button>
                </div>
            </div>
        </form>
    </dialog>
    <script>
    (function () {
      var pendingHref = '';
      var dlg = document.getElementById('dlg-dir-goto');
      var textEl = document.getElementById('dir-goto-text');
      var form = document.getElementById('form-dir-goto');
      if (!dlg || !form) return;

      function askGoto(name, href) {
        if (!href) return;
        pendingHref = href;
        var tpl = <?= json_encode(__('directory.confirm_body'), JSON_UNESCAPED_UNICODE) ?>;
        textEl.textContent = tpl.replace('{name}', name || '—');
        if (window.PanelModal) window.PanelModal.open(dlg);
        else if (dlg.showModal) dlg.showModal();
      }

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var href = pendingHref;
        pendingHref = '';
        if (window.PanelModal) window.PanelModal.close(dlg, true);
        else dlg.close();
        if (href) window.location = href;
      });

      window.DirectoryGoto = { ask: askGoto };

      document.querySelectorAll('[data-dir-filter]').forEach(function (input) {
        input.addEventListener('input', function () {
          var q = (input.value || '').toLowerCase().trim();
          var tableId = input.getAttribute('data-dir-filter');
          var table = tableId ? document.getElementById(tableId) : null;
          if (!table) return;
          table.querySelectorAll('tbody tr[data-goto], tbody tr[data-orphan]').forEach(function (tr) {
            var hay = (tr.getAttribute('data-search') || tr.textContent || '').toLowerCase();
            tr.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
          });
        });
      });
    })();
    </script>
    <?php
    renderPanelModalScripts(is_array($flashToast) ? $flashToast : null);
}

/**
 * Internet accounts directory (external_accounts) — read-only.
 */
function renderInternetAccountsDirectory(): void
{
    $pdo = getPdo();
    // LEFT JOIN so orphan accounts (no referent) still appear with empty referent.
    $stmt = $pdo->query(
        'SELECT ea.id, ea.referent_id, ea.email, ea.username, ea.auth_type, ea.provider,
                ea.active AS ea_active,
                r.username AS referent_name
         FROM external_accounts ea
         LEFT JOIN referents r ON r.id = ea.referent_id
         ORDER BY ea.email, ea.id'
    );
    $rows = $stmt->fetchAll() ?: [];

    $flash = $GLOBALS['flash'] ?? null;
    $GLOBALS['flash'] = null;

    renderHeader(__('nav.internet_accounts'));
    ?>
    <div class="pm-head">
        <h1><?= h(__('nav.internet_accounts')) ?></h1>
    </div>
    <p class="pm-hint"><?= h(__('directory.internet_hint')) ?></p>

    <?php if ($rows === []): ?>
        <div class="pm-empty">
            <?= h(__('directory.internet_empty')) ?><br>
            <a href="index.php?action=referent_list" class="pm-btn pm-btn-primary" style="margin-top:10px"><?= h(__('nav.referents')) ?> →</a>
        </div>
    <?php else: ?>
        <div class="pm-card" style="padding-bottom:12px">
            <div class="pm-f" style="max-width:360px">
                <label for="dir-inet-filter"><?= h(__('directory.search')) ?></label>
                <input id="dir-inet-filter" type="search" data-dir-filter="inet-table"
                       placeholder="<?= h(__('directory.search_internet_ph')) ?>" autocomplete="off">
            </div>
        </div>
        <div class="pm-card pm-table-wrap">
            <table class="pm-table pm-list-table" id="inet-table" data-pm-table="1">
                <thead>
                <tr>
                    <th data-sort="email"><?= h(__('directory.col_email')) ?></th>
                    <th data-sort="login"><?= h(__('directory.col_login')) ?></th>
                    <th data-sort="auth"><?= h(__('directory.col_auth')) ?></th>
                    <th data-sort="provider"><?= h(__('directory.col_provider')) ?></th>
                    <th data-sort="status"><?= h(__('common.status')) ?></th>
                    <th data-sort="referent"><?= h(__('directory.col_referent')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $row):
                    $refId = (int) ($row['referent_id'] ?? 0);
                    $refName = trim((string) ($row['referent_name'] ?? ''));
                    $hasRef = $refId > 0 && $refName !== '';
                    $goto = $hasRef
                        ? 'index.php?action=referent_view&id=' . $refId . '&tab=external'
                        : '';
                    $search = strtolower(
                        (string) $row['email'] . ' ' .
                        (string) ($row['username'] ?? '') . ' ' .
                        $refName
                    );
                    ?>
                    <?php if ($hasRef): ?>
                        <tr tabindex="0"
                            data-goto="<?= h($goto) ?>"
                            data-ref-name="<?= h($refName) ?>"
                            data-search="<?= h($search) ?>"
                            class="<?= $i === 0 ? 'pm-sel' : '' ?>">
                    <?php else: ?>
                        <tr data-orphan="1" data-search="<?= h($search) ?>">
                    <?php endif; ?>
                        <td class="pm-mono"><?= h((string) $row['email']) ?></td>
                        <td class="pm-mono"><?= h((string) ($row['username'] ?: $row['email'])) ?></td>
                        <td><?= h((string) $row['auth_type']) ?></td>
                        <td><?= h((string) ($row['provider'] ?: '—')) ?></td>
                        <td>
                            <?php if ((int) $row['ea_active'] === 1): ?>
                                <span class="pm-chip pm-chip-ok"><?= h(__('common.active')) ?></span>
                            <?php else: ?>
                                <span class="pm-chip pm-chip-off"><?= h(__('common.disabled')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($hasRef): ?>
                                <?= h($refName) ?>
                            <?php else: ?>
                                <span class="pm-chip pm-chip-warn"><?= h(__('directory.no_referent')) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="pm-foot"><?= h(__('directory.open_hint')) ?></p>
    <?php endif;
    renderDirectoryTransitionShell(is_array($flash) ? $flash : null);
    renderFooter();
}

/**
 * Local accounts directory — read-only, derived from DB fields.
 */
function renderLocalAccountsDirectory(): void
{
    $pdo = getPdo();
    $rows = [];

    $refStmt = $pdo->query(
        'SELECT id, username, local_inbox, local_outbox, active
         FROM referents
         ORDER BY username, id'
    );
    foreach ($refStmt->fetchAll() ?: [] as $r) {
        $inbox = trim((string) $r['local_inbox']);
        if ($inbox === '') {
            continue;
        }
        $rows[] = [
            'address' => $inbox,
            'path' => trim((string) ($r['local_outbox'] ?? '')),
            'kind' => 'referent_inbox',
            'kind_label' => __('directory.kind_referent_inbox'),
            'referent_id' => (int) $r['id'],
            'referent_name' => (string) $r['username'],
            'active' => (int) $r['active'] === 1,
            'tab' => 'local',
        ];
    }

    $relStmt = $pdo->query(
        'SELECT c.id, c.active, c.local_client_email, c.local_referent_email, c.local_client_maildir,
                c.referent_id, r.username AS referent_name
         FROM clients c
         INNER JOIN referents r ON r.id = c.referent_id
         ORDER BY r.username, c.id'
    );
    foreach ($relStmt->fetchAll() ?: [] as $c) {
        $refId = (int) $c['referent_id'];
        $refName = (string) $c['referent_name'];
        $active = (int) $c['active'] === 1;
        $localClient = trim((string) ($c['local_client_email'] ?? ''));
        $localRef = trim((string) ($c['local_referent_email'] ?? ''));
        $maildir = trim((string) ($c['local_client_maildir'] ?? ''));

        if ($localClient !== '') {
            $rows[] = [
                'address' => $localClient,
                'path' => $maildir,
                'kind' => 'client_local',
                'kind_label' => __('directory.kind_client_local'),
                'referent_id' => $refId,
                'referent_name' => $refName,
                'active' => $active,
                'tab' => 'clients',
            ];
        }
        if ($localRef !== '') {
            $rows[] = [
                'address' => $localRef,
                'path' => '',
                'kind' => 'referent_for_client',
                'kind_label' => __('directory.kind_referent_for_client'),
                'referent_id' => $refId,
                'referent_name' => $refName,
                'active' => $active,
                'tab' => 'clients',
            ];
        }
    }

    // De-dupe by address+referent+kind
    $seen = [];
    $unique = [];
    foreach ($rows as $row) {
        $key = $row['address'] . '|' . $row['referent_id'] . '|' . $row['kind'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $row;
    }
    $rows = $unique;

    usort($rows, static function (array $a, array $b): int {
        return strcmp($a['address'], $b['address']);
    });

    $flash = $GLOBALS['flash'] ?? null;
    $GLOBALS['flash'] = null;

    renderHeader(__('nav.local_accounts'));
    ?>
    <div class="pm-head">
        <h1><?= h(__('nav.local_accounts')) ?></h1>
    </div>
    <p class="pm-hint"><?= h(__('directory.local_hint')) ?></p>

    <?php if ($rows === []): ?>
        <div class="pm-empty">
            <?= h(__('directory.local_empty')) ?><br>
            <a href="index.php?action=referent_list" class="pm-btn pm-btn-primary" style="margin-top:10px"><?= h(__('nav.referents')) ?> →</a>
        </div>
    <?php else: ?>
        <div class="pm-card" style="padding-bottom:12px">
            <div class="pm-f" style="max-width:360px">
                <label for="dir-local-filter"><?= h(__('directory.search')) ?></label>
                <input id="dir-local-filter" type="search" data-dir-filter="local-table"
                       placeholder="<?= h(__('directory.search_local_ph')) ?>" autocomplete="off">
            </div>
        </div>
        <div class="pm-card pm-table-wrap">
            <table class="pm-table pm-list-table" id="local-table" data-pm-table="1">
                <thead>
                <tr>
                    <th data-sort="address"><?= h(__('directory.col_address')) ?></th>
                    <th data-sort="path"><?= h(__('directory.col_path')) ?></th>
                    <th data-sort="kind"><?= h(__('directory.col_kind')) ?></th>
                    <th data-sort="referent"><?= h(__('directory.col_referent')) ?></th>
                    <th data-sort="status"><?= h(__('common.status')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $row):
                    $goto = 'index.php?action=referent_view&id=' . (int) $row['referent_id']
                        . '&tab=' . rawurlencode((string) $row['tab']);
                    $search = strtolower(
                        $row['address'] . ' ' . $row['path'] . ' ' . $row['referent_name'] . ' ' . $row['kind_label']
                    );
                    ?>
                    <tr tabindex="0"
                        data-goto="<?= h($goto) ?>"
                        data-ref-name="<?= h($row['referent_name']) ?>"
                        data-search="<?= h($search) ?>"
                        class="<?= $i === 0 ? 'pm-sel' : '' ?>">
                        <td class="pm-mono"><?= h($row['address']) ?></td>
                        <td class="pm-mono" style="font-size:12px"><?= h($row['path'] !== '' ? $row['path'] : '—') ?></td>
                        <td><?= h($row['kind_label']) ?></td>
                        <td><?= h($row['referent_name']) ?></td>
                        <td>
                            <?php if ($row['active']): ?>
                                <span class="pm-chip pm-chip-ok"><?= h(__('common.active')) ?></span>
                            <?php else: ?>
                                <span class="pm-chip pm-chip-off"><?= h(__('common.disabled')) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="pm-foot"><?= h(__('directory.open_hint')) ?></p>
    <?php endif;
    renderDirectoryTransitionShell(is_array($flash) ? $flash : null);
    renderFooter();
}

/**
 * Clients directory — read-only relationships list.
 */
function renderClientsDirectory(): void
{
    $pdo = getPdo();
    $stmt = $pdo->query(
        'SELECT c.id, c.active, c.email AS legacy_email,
                c.external_client_email, c.local_client_email, c.local_referent_email,
                c.referent_id, r.username AS referent_name, r.active AS referent_active
         FROM clients c
         INNER JOIN referents r ON r.id = c.referent_id
         ORDER BY r.username, c.id'
    );
    $rows = $stmt->fetchAll() ?: [];

    $flash = $GLOBALS['flash'] ?? null;
    $GLOBALS['flash'] = null;

    renderHeader(__('nav.clients'));
    ?>
    <div class="pm-head">
        <h1><?= h(__('nav.clients')) ?></h1>
    </div>
    <p class="pm-hint"><?= h(__('directory.clients_hint')) ?></p>

    <?php if ($rows === []): ?>
        <div class="pm-empty">
            <?= h(__('directory.clients_empty')) ?><br>
            <a href="index.php?action=referent_list" class="pm-btn pm-btn-primary" style="margin-top:10px"><?= h(__('nav.referents')) ?> →</a>
        </div>
    <?php else: ?>
        <div class="pm-card" style="padding-bottom:12px">
            <div class="pm-f" style="max-width:360px">
                <label for="dir-clients-filter"><?= h(__('directory.search')) ?></label>
                <input id="dir-clients-filter" type="search" data-dir-filter="clients-table"
                       placeholder="<?= h(__('directory.search_clients_ph')) ?>" autocomplete="off">
            </div>
        </div>
        <div class="pm-card pm-table-wrap">
            <table class="pm-table pm-list-table" id="clients-table" data-pm-table="1">
                <thead>
                <tr>
                    <th data-sort="external"><?= h(__('directory.col_external_client')) ?></th>
                    <th data-sort="local"><?= h(__('directory.col_local_client')) ?></th>
                    <th data-sort="referent"><?= h(__('directory.col_referent')) ?></th>
                    <th data-sort="status"><?= h(__('common.status')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $row):
                    $ext = trim((string) ($row['external_client_email'] ?? ''));
                    if ($ext === '') {
                        $ext = trim((string) ($row['legacy_email'] ?? ''));
                    }
                    $local = trim((string) ($row['local_client_email'] ?? ''));
                    $refName = (string) $row['referent_name'];
                    $refId = (int) $row['referent_id'];
                    $goto = 'index.php?action=referent_view&id=' . $refId . '&tab=clients';
                    $search = strtolower($ext . ' ' . $local . ' ' . $refName);
                    $status = relationshipStatusLabel($row);
                    ?>
                    <tr tabindex="0"
                        data-goto="<?= h($goto) ?>"
                        data-ref-name="<?= h($refName) ?>"
                        data-search="<?= h($search) ?>"
                        class="<?= $i === 0 ? 'pm-sel' : '' ?>">
                        <td class="pm-mono"><?= h($ext !== '' ? $ext : '—') ?></td>
                        <td class="pm-mono"><?= h($local !== '' ? $local : '—') ?></td>
                        <td><?= h($refName) ?></td>
                        <td>
                            <?php if ((int) $row['active'] !== 1): ?>
                                <span class="pm-chip pm-chip-off"><?= h(__('common.disabled')) ?></span>
                            <?php elseif ($status['code'] === 'complete'): ?>
                                <span class="pm-chip pm-chip-ok"><?= h(__('directory.status_complete')) ?></span>
                            <?php else: ?>
                                <span class="pm-chip pm-chip-warn"><?= h(__('directory.status_attention')) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="pm-foot"><?= h(__('directory.open_hint')) ?></p>
    <?php endif;
    renderDirectoryTransitionShell(is_array($flash) ? $flash : null);
    renderFooter();
}
