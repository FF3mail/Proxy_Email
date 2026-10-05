<?php
declare(strict_types=1);

/**
 * Dashboard world clocks widget (client-side, no network). Part E — replaces dashboard Logs button.
 */

function renderDashboardWorldClocksWidget(): void
{
    $serverEpoch = time();
    $zones = [
        ['zone' => 'America/New_York', 'label_key' => 'dashboard.clocks_ny', 'abbr_key' => 'dashboard.clocks_ny_abbr'],
        ['zone' => 'UTC', 'label_key' => 'dashboard.clocks_utc', 'abbr_key' => 'dashboard.clocks_utc_abbr'],
        ['zone' => 'Europe/Moscow', 'label_key' => 'dashboard.clocks_moscow', 'abbr_key' => 'dashboard.clocks_moscow_abbr'],
        ['zone' => 'Asia/Shanghai', 'label_key' => 'dashboard.clocks_beijing', 'abbr_key' => 'dashboard.clocks_beijing_abbr'],
    ];
    ?>
    <section class="pm-world-clocks" aria-labelledby="pm-world-clocks-title">
        <h2 id="pm-world-clocks-title" class="pm-world-clocks-heading"><?= h(__('dashboard.clocks_title')) ?></h2>
        <div class="pm-world-clocks-grid" data-world-clocks data-server-epoch="<?= (int) $serverEpoch ?>">
            <?php foreach ($zones as $card):
                $zone = $card['zone'];
                $isUtc = $zone === 'UTC';
                ?>
                <div class="pm-world-clock-card<?= $isUtc ? ' pm-world-clock-card-utc' : '' ?>"
                     role="group"
                     aria-label="<?= h(__($card['label_key']) . ' ' . $zone) ?>"
                     data-zone="<?= h($zone) ?>">
                    <div class="pm-world-clock-city"><?= h(__($card['label_key'])) ?></div>
                    <div class="pm-world-clock-time pm-tabular" data-clock-time>--:--:--</div>
                    <div class="pm-world-clock-date" data-clock-date></div>
                    <div class="pm-world-clock-meta">
                        <span data-clock-abbr><?= h(__($card['abbr_key'])) ?></span>
                        <span class="pm-world-clock-offset" data-clock-offset></span>
                        <span class="pm-world-clock-daynight" data-clock-daynight aria-hidden="true"></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
    renderDashboardWorldClocksScript();
}

function renderDashboardWorldClocksScript(): void
{
    $locale = panelActiveLocaleForIntl();
    ?>
    <script>
    (function () {
      var root = document.querySelector('[data-world-clocks]');
      if (!root) return;
      var serverEpoch = parseInt(root.getAttribute('data-server-epoch') || '0', 10);
      if (!serverEpoch) serverEpoch = Math.floor(Date.now() / 1000);
      var skewMs = (serverEpoch * 1000) - Date.now();
      var locale = <?= json_encode($locale, JSON_UNESCAPED_UNICODE) ?>;
      var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduced) {
        root.classList.add('pm-reduced-motion');
      }
      if (typeof Intl === 'undefined' || typeof Intl.DateTimeFormat === 'undefined') {
        root.setAttribute('data-clocks-unsupported', '1');
        root.querySelectorAll('[data-clock-time]').forEach(function (el) { el.textContent = <?= json_encode(__('system_info.na'), JSON_UNESCAPED_UNICODE) ?>; });
        return;
      }
      function partsForZone(ms, zone) {
        var dtf = new Intl.DateTimeFormat(locale, {
          timeZone: zone,
          hour: '2-digit', minute: '2-digit', second: '2-digit',
          hour12: false,
          year: 'numeric', month: 'short', day: '2-digit', weekday: 'short'
        });
        var parts = dtf.formatToParts(new Date(ms));
        var map = {};
        parts.forEach(function (p) { if (p.type !== 'literal') map[p.type] = p.value; });
        var time = (map.hour || '00') + ':' + (map.minute || '00') + ':' + (map.second || '00');
        var date = (map.weekday || '') + ', ' + (map.day || '') + ' ' + (map.month || '') + ' ' + (map.year || '');
        var hourNum = parseInt(map.hour || '0', 10);
        var day = hourNum >= 6 && hourNum <= 17;
        var offDtf = new Intl.DateTimeFormat(locale, { timeZone: zone, timeZoneName: 'shortOffset' });
        var offParts = offDtf.formatToParts(new Date(ms));
        var off = '';
        offParts.forEach(function (p) { if (p.type === 'timeZoneName') off = p.value; });
        return { time: time, date: date, off: off, day: day };
      }
      function tick() {
        var nowMs = Date.now() + skewMs;
        root.querySelectorAll('[data-zone]').forEach(function (card) {
          var zone = card.getAttribute('data-zone');
          if (!zone) return;
          var p = partsForZone(nowMs, zone);
          var tEl = card.querySelector('[data-clock-time]');
          var dEl = card.querySelector('[data-clock-date]');
          var oEl = card.querySelector('[data-clock-offset]');
          var nEl = card.querySelector('[data-clock-daynight]');
          if (tEl) tEl.textContent = p.time;
          if (dEl) dEl.textContent = p.date;
          if (oEl) oEl.textContent = p.off;
          if (nEl) {
            nEl.textContent = p.day ? '\u2600' : '\u263E';
            nEl.classList.toggle('pm-night', !p.day);
          }
        });
        var delay = 1000 - ((Date.now() + skewMs) % 1000);
        if (delay < 1) delay = 1000;
        if (!document.hidden) {
          setTimeout(tick, delay);
        }
      }
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) tick();
      });
      tick();
    })();
    </script>
    <style>
    .pm-world-clocks{margin:0 0 16px}
    .pm-world-clocks-heading{font-size:15px;margin:0 0 10px;font-weight:600}
    .pm-world-clocks-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
    @media (max-width:960px){.pm-world-clocks-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media (max-width:520px){.pm-world-clocks-grid{grid-template-columns:1fr}}
    .pm-world-clock-card{border:1px solid var(--pm-border);border-radius:8px;padding:12px 14px;background:var(--pm-surface2)}
    .pm-world-clock-card-utc{border-color:var(--pm-accent);box-shadow:0 0 0 1px var(--pm-accent-bg)}
    .pm-world-clock-city{font-size:13px;font-weight:600;margin-bottom:6px}
    .pm-world-clock-time{font-size:28px;font-weight:700;line-height:1.1;font-variant-numeric:tabular-nums}
    .pm-world-clock-date{font-size:13px;margin-top:6px;color:var(--pm-text)}
    .pm-world-clock-meta{font-size:11px;color:var(--pm-muted);margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .pm-world-clock-daynight{font-size:14px;transition:opacity .2s ease}
    .pm-reduced-motion .pm-world-clock-daynight{transition:none}
    .pm-tabular{font-variant-numeric:tabular-nums}
    </style>
    <?php
}

function panelActiveLocaleForIntl(): string
{
    $lang = function_exists('currentPanelLang') ? currentPanelLang() : 'en';
    return $lang === 'ru' ? 'ru-RU' : 'en-US';
}
