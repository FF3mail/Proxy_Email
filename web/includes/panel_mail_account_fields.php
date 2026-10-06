<?php
declare(strict_types=1);

require_once __DIR__ . '/mail_provider_presets.php';

/**
 * @param array<string,mixed>|null $row Existing account row for edit; null for create.
 */
function renderExternalAccountMailFields(?array $row, string $formKey): void
{
    $imapHost = $row !== null ? (string) ($row['imap_host'] ?? '') : '';
    $imapPort = $row !== null ? (int) ($row['imap_port'] ?? 993) : 993;
    $imapEncStored = $row !== null ? (string) ($row['imap_encryption'] ?? 'ssl') : 'ssl';
    $imapEncUi = mailEncryptionSelectUiValue($imapEncStored);
    $imapEncSubmit = mailEncryptionSubmittedValue($imapEncStored, 'ssl');
    $smtpHost = $row !== null ? (string) ($row['smtp_host'] ?? '') : '';
    $smtpPort = $row !== null ? (int) ($row['smtp_port'] ?? 587) : 587;
    $smtpEncStored = $row !== null ? (string) ($row['smtp_encryption'] ?? 'tls') : 'tls';
    $smtpEncUi = mailEncryptionSelectUiValue($smtpEncStored);
    $smtpEncSubmit = mailEncryptionSubmittedValue($smtpEncStored, 'tls');
    $pairWarn = h(__('mail_preset.port_mode_mismatch'));
    $presetsJson = json_encode(mailProviderPresets(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    if (!is_string($presetsJson)) {
        $presetsJson = '{}';
    }
    ?>
    <div class="pm-mail-account-fields" data-mail-form="<?= h($formKey) ?>" data-mail-presets="<?= h($presetsJson) ?>">
        <div class="pm-f pm-full">
            <label for="mail_provider_<?= h($formKey) ?>"><?= h(__('mail_preset.provider_label')) ?></label>
            <select id="mail_provider_<?= h($formKey) ?>" name="mail_provider" data-mail-provider>
                <option value=""><?= h(__('mail_preset.other')) ?></option>
                <?php foreach (mailProviderPresets() as $code => $preset): ?>
                    <option value="<?= h($code) ?>"><?= h(__($preset['label_key'])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="pm-sect"><?= h(__('mail_preset.imap_section')) ?></div>
        <div class="pm-f">
            <label><?= h(__('account.imap_host')) ?> *</label>
            <input type="text" name="imap_host" required value="<?= h($imapHost) ?>" data-mail-imap-host autocomplete="off">
        </div>
        <div class="pm-f">
            <label><?= h(__('account.imap_port')) ?></label>
            <input type="number" name="imap_port" min="1" max="65535" value="<?= (int) $imapPort ?>" data-mail-imap-port>
            <button type="button" class="pm-btn pm-btn-sm" data-mail-port-reset="imap"><?= h(__('mail_preset.reset_port')) ?></button>
        </div>
        <div class="pm-f pm-full">
            <label for="imap_enc_<?= h($formKey) ?>"><?= h(__('account.imap_encryption')) ?></label>
            <input type="hidden" name="imap_encryption" value="<?= h($imapEncSubmit) ?>" data-mail-imap-enc-submit>
            <select id="imap_enc_<?= h($formKey) ?>" data-mail-imap-encryption aria-describedby="imap_pair_hint_<?= h($formKey) ?>">
                <?php foreach (mailEncryptionSelectOptions($imapEncStored) as $val => $labelKey): ?>
                    <option value="<?= h($val) ?>" <?= $imapEncUi === $val ? 'selected' : '' ?>><?= h(__($labelKey)) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="pm-hint" id="imap_pair_hint_<?= h($formKey) ?>" data-mail-pair-hint="imap" data-warn-text="<?= $pairWarn ?>" hidden></p>
        </div>
        <div class="pm-sect"><?= h(__('mail_preset.smtp_section')) ?></div>
        <div class="pm-f">
            <label><?= h(__('account.smtp_host')) ?> *</label>
            <input type="text" name="smtp_host" required value="<?= h($smtpHost) ?>" data-mail-smtp-host autocomplete="off">
        </div>
        <div class="pm-f">
            <label><?= h(__('account.smtp_port')) ?></label>
            <input type="number" name="smtp_port" min="1" max="65535" value="<?= (int) $smtpPort ?>" data-mail-smtp-port>
            <button type="button" class="pm-btn pm-btn-sm" data-mail-port-reset="smtp"><?= h(__('mail_preset.reset_port')) ?></button>
        </div>
        <div class="pm-f pm-full">
            <label for="smtp_enc_<?= h($formKey) ?>"><?= h(__('account.smtp_encryption')) ?></label>
            <input type="hidden" name="smtp_encryption" value="<?= h($smtpEncSubmit) ?>" data-mail-smtp-enc-submit>
            <select id="smtp_enc_<?= h($formKey) ?>" data-mail-smtp-encryption aria-describedby="smtp_pair_hint_<?= h($formKey) ?>">
                <?php foreach (mailEncryptionSelectOptions($smtpEncStored) as $val => $labelKey): ?>
                    <option value="<?= h($val) ?>" <?= $smtpEncUi === $val ? 'selected' : '' ?>><?= h(__($labelKey)) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="pm-hint" id="smtp_pair_hint_<?= h($formKey) ?>" data-mail-pair-hint="smtp" data-warn-text="<?= $pairWarn ?>" hidden></p>
        </div>
    </div>
    <?php
}
