(function () {
  'use strict';

  var STANDARD = {
    imap: { ssl: 993, tls: 143, none: 143 },
    smtp: { ssl: 465, tls: 587, none: 25 }
  };

  function standardPort(proto, enc) {
    var map = STANDARD[proto] || {};
    return map[enc] || map.tls || 587;
  }

  function isNonStandardPair(proto, port, enc) {
    var p = parseInt(String(port), 10);
    if (!p || enc === 'custom' || !enc) return false;
    return p !== standardPort(proto, enc);
  }

  function submittedEnc(proto, encSelect, hidden) {
    if (encSelect && encSelect.value === 'custom') {
      return hidden ? hidden.value : 'ssl';
    }
    return encSelect ? encSelect.value : 'ssl';
  }

  function bindBlock(root) {
    if (!root) return;
    var portEdited = { imap: false, smtp: false };
    var imapPort = root.querySelector('[data-mail-imap-port]');
    var smtpPort = root.querySelector('[data-mail-smtp-port]');
    var imapEnc = root.querySelector('[data-mail-imap-encryption]');
    var smtpEnc = root.querySelector('[data-mail-smtp-encryption]');
    var imapEncHidden = root.querySelector('[data-mail-imap-enc-submit]');
    var smtpEncHidden = root.querySelector('[data-mail-smtp-enc-submit]');
    var provider = root.querySelector('[data-mail-provider]');
    var presets = {};
    try {
      presets = JSON.parse(root.getAttribute('data-mail-presets') || '{}');
    } catch (e) {
      presets = {};
    }

    function syncHidden(proto) {
      var encEl = proto === 'imap' ? imapEnc : smtpEnc;
      var hidden = proto === 'imap' ? imapEncHidden : smtpEncHidden;
      if (!encEl || !hidden) return;
      if (encEl.value !== 'custom') {
        hidden.value = encEl.value;
      }
    }

    function fillStandardPort(proto) {
      var encEl = proto === 'imap' ? imapEnc : smtpEnc;
      var portEl = proto === 'imap' ? imapPort : smtpPort;
      var hidden = proto === 'imap' ? imapEncHidden : smtpEncHidden;
      if (!encEl || !portEl || portEdited[proto] || encEl.value === 'custom') return;
      var enc = submittedEnc(proto, encEl, hidden);
      portEl.value = String(standardPort(proto, enc));
      updateHint(proto);
    }

    function updateHint(proto) {
      var encEl = proto === 'imap' ? imapEnc : smtpEnc;
      var portEl = proto === 'imap' ? imapPort : smtpPort;
      var hidden = proto === 'imap' ? imapEncHidden : smtpEncHidden;
      var hint = root.querySelector('[data-mail-pair-hint="' + proto + '"]');
      if (!encEl || !portEl || !hint) return;
      var enc = submittedEnc(proto, encEl, hidden);
      if (isNonStandardPair(proto, portEl.value, enc)) {
        hint.textContent = hint.getAttribute('data-warn-text') || '';
        hint.hidden = hint.textContent === '';
      } else {
        hint.hidden = true;
      }
    }

    function onEncChange(proto) {
      syncHidden(proto);
      var encEl = proto === 'imap' ? imapEnc : smtpEnc;
      if (encEl && encEl.value !== 'custom') {
        fillStandardPort(proto);
      }
      updateHint(proto);
      if (provider) provider.value = '';
    }

    if (imapEnc) {
      imapEnc.addEventListener('change', function () {
        onEncChange('imap');
      });
    }
    if (smtpEnc) {
      smtpEnc.addEventListener('change', function () {
        onEncChange('smtp');
      });
    }
    if (imapPort) {
      imapPort.addEventListener('input', function () {
        portEdited.imap = true;
        updateHint('imap');
        if (provider) provider.value = '';
      });
    }
    if (smtpPort) {
      smtpPort.addEventListener('input', function () {
        portEdited.smtp = true;
        updateHint('smtp');
        if (provider) provider.value = '';
      });
    }
    root.querySelectorAll('[data-mail-port-reset]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var proto = btn.getAttribute('data-mail-port-reset');
        if (proto !== 'imap' && proto !== 'smtp') return;
        portEdited[proto] = false;
        fillStandardPort(proto);
      });
    });
    function updateAuthHint() {
      var hintEl = root.querySelector('[data-mail-auth-hint]');
      if (!hintEl || !provider) return;
      var code = provider.value;
      var key = code && presets[code] ? presets[code].auth_hint_key : '';
      if (key && hintEl.getAttribute('data-hint-' + key)) {
        hintEl.textContent = hintEl.getAttribute('data-hint-' + key);
        hintEl.hidden = false;
        return;
      }
      hintEl.hidden = true;
      hintEl.textContent = '';
    }

    if (provider) {
      provider.addEventListener('change', function () {
        var code = provider.value;
        if (!code || !presets[code]) {
          updateAuthHint();
          return;
        }
        var p = presets[code];
        var ih = root.querySelector('[data-mail-imap-host]');
        var sh = root.querySelector('[data-mail-smtp-host]');
        if (ih) ih.value = p.imap_host || '';
        if (sh) sh.value = p.smtp_host || '';
        if (imapEnc) imapEnc.value = p.imap_encryption || 'ssl';
        if (smtpEnc) smtpEnc.value = p.smtp_encryption || 'tls';
        syncHidden('imap');
        syncHidden('smtp');
        portEdited.imap = false;
        portEdited.smtp = false;
        if (imapPort) imapPort.value = String(p.imap_port || standardPort('imap', imapEncHidden.value));
        if (smtpPort) smtpPort.value = String(p.smtp_port || standardPort('smtp', smtpEncHidden.value));
        updateHint('imap');
        updateHint('smtp');
        updateAuthHint();
      });
    }
    updateAuthHint();
    ['imap', 'smtp'].forEach(updateHint);
  }

  document.querySelectorAll('[data-mail-form]').forEach(bindBlock);

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
      standardPort: standardPort,
      isNonStandardPair: isNonStandardPair,
      submittedEnc: submittedEnc
    };
  }
})();
