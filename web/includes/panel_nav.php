<?php
declare(strict_types=1);

require_once __DIR__ . '/panel_brand.php';

/**
 * Shared left sidebar for the panel (prototype order + collapse).
 */

/**
 * @return list<array{href: string, label: string, active: bool, icon: string, master_only?: bool, logout?: bool}>
 */
function panelNavItems(): array
{
    global $action;
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $a = (string) ($action ?? '');

    $isReferents = in_array($a, [
        'referent_list', 'referents', 'referent_form', 'referent_view',
        'relationship_form', 'relationship_backfill',
    ], true);
    $isClients = in_array($a, ['client_list', 'clients'], true);
    $isInternet = in_array($a, ['account_list', 'accounts', 'account_form', 'internet_accounts'], true);
    $isLocal = in_array($a, ['local_account_list', 'local_accounts'], true);
    $isProviders = in_array($a, ['provider_list', 'provider_form', 'providers'], true);
    $isJournal = $script === 'relationship-status.php';
    $isOperators = in_array($a, ['operator_list'], true);
    $isLogs = $script === 'logs.php';
    $isDashboard = ($script === 'index.php' || $script === '') && ($a === 'dashboard' || $a === '');

    $items = [
        [
            'href' => '/index.php?action=dashboard',
            'label' => __('nav.dashboard'),
            'active' => $isDashboard,
            'icon' => '▦',
        ],
        [
            'href' => '/index.php?action=referent_list',
            'label' => __('nav.referents'),
            'active' => $isReferents,
            'icon' => '◉',
        ],
        [
            'href' => '/index.php?action=client_list',
            'label' => __('nav.clients'),
            'active' => $isClients,
            'icon' => '☰',
        ],
        [
            'href' => '/index.php?action=account_list',
            'label' => __('nav.internet_accounts'),
            'active' => $isInternet,
            'icon' => '@',
        ],
        [
            'href' => '/index.php?action=local_account_list',
            'label' => __('nav.local_accounts'),
            'active' => $isLocal,
            'icon' => '✉',
        ],
        [
            'href' => '/index.php?action=provider_list',
            'label' => __('nav.providers'),
            'active' => $isProviders,
            'icon' => '⚿',
        ],
        [
            'href' => '/relationship-status.php',
            'label' => __('nav.journal'),
            'active' => $isJournal,
            'icon' => '≣',
        ],
        [
            'href' => '/index.php?action=operator_list',
            'label' => __('nav.user_accounts'),
            'active' => $isOperators,
            'icon' => '☺',
            'master_only' => true,
        ],
        [
            'href' => '/logs.php',
            'label' => __('nav.logs'),
            'active' => $isLogs,
            'icon' => '▤',
        ],
    ];

    return $items;
}

function renderPanelSidebar(): void
{
    $items = panelNavItems();
    ?>
    <aside id="side" class="pm-side" aria-label="<?= h(__('nav.menu')) ?>">
        <div class="pm-brand">
            <a href="/index.php?action=dashboard" class="pm-brand-link" title="<?= h(__('app.name')) ?>">
                <img class="pm-brand-mark" src="<?= h(panelBrandUrl('logo-mark.png')) ?>" width="32" height="26" alt="">
                <span class="pm-brand-wordmarks">
                    <img class="pm-brand-delta" src="<?= h(panelBrandUrl('wordmark-delta.png')) ?>" width="168" height="52" alt="Delta">
                    <img class="pm-brand-transit" src="<?= h(panelBrandUrl('wordmark-transit.png')) ?>" width="90" height="20" alt="TRANSIT">
                </span>
                <span class="pm-brand-text pm-brand-fallback"><?= h(__('app.name')) ?></span>
            </a>
            <button type="button" id="sideToggle" class="pm-side-toggle" title="<?= h(__('nav.collapse')) ?>" aria-label="<?= h(__('nav.collapse')) ?>">☰</button>
        </div>
        <?php if (!empty($_SESSION['admin_username_display'])): ?>
            <p class="pm-side-user"><?= h((string) $_SESSION['admin_username_display']) ?></p>
        <?php endif; ?>
        <div class="pm-side-lang" aria-label="<?= h(__('common.language')) ?>">
            <?php renderLanguageSelector(); ?>
        </div>
        <nav class="pm-side-nav">
            <?php foreach ($items as $item):
                if (!empty($item['master_only']) && !isPanelMasterDisplay()) {
                    continue;
                }
                $cls = $item['active'] ? 'active' : '';
                ?>
                <a href="<?= h($item['href']) ?>" class="<?= h($cls) ?>" title="<?= h($item['label']) ?>">
                    <span class="pm-ic" aria-hidden="true"><?= h($item['icon']) ?></span>
                    <span class="pm-lbl"><?= h($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
            <form method="post" action="/index.php" class="pm-side-exit">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                <button type="submit" title="<?= h(__('nav.logout')) ?>">
                    <span class="pm-ic" aria-hidden="true">⎋</span>
                    <span class="pm-lbl"><?= h(__('nav.logout')) ?></span>
                </button>
            </form>
        </nav>
    </aside>
    <script>
    (function () {
      var side = document.getElementById('side');
      var btn = document.getElementById('sideToggle');
      if (!side || !btn) return;
      try {
        if (localStorage.getItem('pm-side-collapsed') === '1') {
          side.classList.add('collapsed');
        }
      } catch (e) {}
      btn.addEventListener('click', function () {
        side.classList.toggle('collapsed');
        try {
          localStorage.setItem('pm-side-collapsed', side.classList.contains('collapsed') ? '1' : '0');
        } catch (e) {}
      });
    })();
    </script>
    <?php
}
