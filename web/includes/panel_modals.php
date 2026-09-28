<?php
declare(strict_types=1);

/**
 * Shared modal shell + toast for panel UI (referent card).
 * Native <dialog>, no framework. CSRF and POST endpoints stay in forms.
 */

function panelAllowedReferentTabs(): array
{
    return ['overview', 'local', 'external', 'clients'];
}

function panelNormalizeTab(?string $tab, string $default = 'overview'): string
{
    $tab = (string) $tab;
    return in_array($tab, panelAllowedReferentTabs(), true) ? $tab : $default;
}

/**
 * After a mutation, honour return_to / tab when present.
 *
 * @param array<string, string|int> $defaultParams
 */
function redirectUsingReturnTo(
    string $defaultAction,
    array $defaultParams = [],
    int $referentIdFallback = 0
): void {
    $returnTo = (string) ($_POST['return_to'] ?? '');
    $tabRaw = $_POST['tab'] ?? $_POST['return_tab'] ?? null;
    $tab = panelNormalizeTab($tabRaw !== null ? (string) $tabRaw : null);

    if ($returnTo === 'backfill') {
        redirectTo('relationship_backfill');
    }

    if ($returnTo === 'referent_view') {
        $id = (int) ($_POST['return_id'] ?? 0);
        if ($id <= 0) {
            $id = (int) ($_POST['referent_id'] ?? 0);
        }
        if ($id <= 0) {
            $id = $referentIdFallback;
        }
        if ($id > 0) {
            redirectTo('referent_view', array_merge($defaultParams, [
                'id' => $id,
                'tab' => $tab,
            ]));
        }
    }

    if ($returnTo === 'referent_list') {
        redirectTo('referent_list');
    }

    redirectTo($defaultAction, $defaultParams);
}

/**
 * Prefer returning to the referent card when the POST carried return_to=referent_view.
 * Otherwise redirect to $fallbackAction with $fallbackParams.
 *
 * @param array<string, string|int|null> $fallbackParams
 */
function panelRedirectPreferReferentCard(int $referentId, string $fallbackAction, array $fallbackParams = []): void
{
    if ($referentId > 0 && (string) ($_POST['return_to'] ?? '') === 'referent_view') {
        redirectUsingReturnTo('referent_view', ['id' => $referentId], $referentId);
    }
    redirectTo($fallbackAction, array_filter(
        $fallbackParams,
        static fn ($v) => $v !== null && $v !== ''
    ));
}

function renderPanelModalStyles(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    echo '<link rel="stylesheet" href="/assets/panel-modal.css">';
}

function renderPanelModalScripts(?array $flashToast = null): void
{
    $toastMsg = (string) ($flashToast['message'] ?? '');
    $toastType = ($flashToast['type'] ?? '') === 'error' ? 'error' : 'success';
    $payload = json_encode([
        'message' => $toastMsg,
        'error' => $toastType === 'error',
        'unsaved' => 'Есть несохранённые изменения. Закрыть без сохранения?',
    ], JSON_UNESCAPED_UNICODE);
    echo '<div class="pm-toast' . ($toastType === 'error' ? ' pm-error' : '') . '" id="pm-toast" role="status" aria-live="polite"></div>';
    echo '<script src="/assets/panel-modal.js"></script>';
    echo '<script>window.PanelModal && window.PanelModal.boot(' . $payload . ');</script>';
}
