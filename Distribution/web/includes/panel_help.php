<?php
declare(strict_types=1);

function renderHelpPage(): void
{
    renderHeader(__('help.title'));
    ?>
    <div class="pm-head">
        <h1><?= h(__('help.heading')) ?></h1>
    </div>
    <p class="pm-hint"><?= h(__('help.intro')) ?></p>

    <div class="pm-card">
        <ul class="pm-steps">
            <li>
                <span class="pm-dot pm-dot-ok">1</span>
                <div class="pm-t"><?= h(__('nav.dashboard')) ?><small><?= h(__('help.dashboard')) ?></small></div>
            </li>
            <li>
                <span class="pm-dot pm-dot-ok">2</span>
                <div class="pm-t"><?= h(__('nav.referents')) ?><small><?= h(__('help.referents')) ?></small></div>
            </li>
            <li>
                <span class="pm-dot pm-dot-ok">3</span>
                <div class="pm-t"><?= h(__('nav.clients')) ?><small><?= h(__('help.clients')) ?></small></div>
            </li>
            <li>
                <span class="pm-dot pm-dot-ok">4</span>
                <div class="pm-t"><?= h(__('nav.internet_accounts')) ?> / <?= h(__('nav.local_accounts')) ?>
                    <small><?= h(__('help.accounts')) ?></small></div>
            </li>
            <li>
                <span class="pm-dot pm-dot-ok">5</span>
                <div class="pm-t"><?= h(__('nav.providers')) ?><small><?= h(__('help.providers')) ?></small></div>
            </li>
            <li>
                <span class="pm-dot pm-dot-ok">6</span>
                <div class="pm-t"><?= h(__('nav.journal')) ?><small><?= h(__('help.journal')) ?></small></div>
            </li>
            <li>
                <span class="pm-dot pm-dot-ok">7</span>
                <div class="pm-t"><?= h(__('nav.user_accounts')) ?><small><?= h(__('help.users')) ?></small></div>
            </li>
            <li>
                <span class="pm-dot pm-dot-ok">8</span>
                <div class="pm-t"><?= h(__('nav.logs')) ?><small><?= h(__('help.logs')) ?></small></div>
            </li>
        </ul>
    </div>
    <?php
    renderFooter();
}
