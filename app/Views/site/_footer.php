<?php

use App\Models\Setting;

/** @var string $logo */
$logo = $logo ?? '';
$siteName = Setting::get('site_name', 'ASDR');
/** @var string $canonicalUrl — задаётся в _header.php (та же область видимости View) */
$printUrl = $canonicalUrl ?? '';

// --- Аналитика + Cookie-Consent (задача 116) ---
$analyticsInit = \App\Core\Analytics::hasAny() ? \App\Core\Analytics::initScript() : '';
$consentRequired = Setting::get('cookie_consent_enabled', '0') === '1';
$privacyUrl = '';
$privacyPageId = (int) Setting::get('privacy_policy_page_id', '');
if ($privacyPageId > 0) {
    $pp = \App\Models\Page::findById($privacyPageId);
    if ($pp && ($pp['status'] ?? '') === 'published') {
        $privacyUrl = \App\Core\Locale::url($pp['slug']);
    }
}
?>
<footer class="print-only print-footer">
    <?php if ($printUrl !== ''): ?><?= htmlspecialchars(t('Источник:'), ENT_QUOTES) ?> <?= htmlspecialchars($printUrl, ENT_QUOTES) ?> &nbsp;·&nbsp; <?php endif; ?>
    &copy; <?= date('Y') ?> <?= htmlspecialchars($siteName, ENT_QUOTES) ?>
</footer>
</main>
<?php if (empty($hideChrome)): // лендинг (группа 6) скрывает футер сайта ?>
<?php
$footerCfg = \App\Core\FooterConfig::get();
$footerStyle = $footerCfg['style'] ?? 'columns';
$phone = Setting::get('contact_phone', '');
$email = Setting::get('contact_email', '');
$address = Setting::get('contact_address', '');
$footerLang = \App\Core\Locale::current();
$footerMenu = [];
try {
    $footerMenu = \App\Models\MenuItem::activeForLang($footerLang);
} catch (\Throwable $e) {
    // Подвал не должен ронять страницу из-за меню, но исчезнувшая навигация
    // без единой записи в логе ничем не объясняется.
    \App\Core\Logger::swallowed('Подвал: не удалось загрузить меню для языка ' . $footerLang, $e);
}
$footerSocial = $hcfg['social_buttons'] ?? [];
$footerBottom = \App\Core\FooterConfig::renderBottom($footerCfg['bottom'], $siteName);

// Ссылки справа в строке копирайта. Собираются один раз: разметка нижней
// строки повторяется у обоих стилей подвала, и вторая копия разъехалась бы
// с первой при первой правке.
// Подпись приходит из конструктора, поэтому идёт через t() — как заголовки
// колонок; внутренний адрес получает языковой префикс тем же методом, что и
// произвольная ссылка пункта меню (второго такого разбора заводить не за чем).
$footerBottomLinks = '';
foreach ((array) ($footerCfg['bottom_links'] ?? []) as $footerLink) {
    $footerLinkUrl = \App\Models\MenuItem::resolveUrl(
        ['url_type' => 'custom', 'url_value' => (string) $footerLink['url']],
        $footerLang
    );
    $footerBottomLinks .= '<a href="' . htmlspecialchars($footerLinkUrl, ENT_QUOTES) . '">'
        . htmlspecialchars(t((string) $footerLink['label']), ENT_QUOTES) . '</a>';
}
if ($footerBottomLinks !== '') {
    $footerBottomLinks = '<nav class="site-footer__bottom-links" aria-label="'
        . htmlspecialchars(t('Дополнительные ссылки'), ENT_QUOTES) . '">' . $footerBottomLinks . '</nav>';
}

// Логотип подвала: тёмный фон → используем светлый (тёмный) вариант логотипа —
// сначала для текущего языка, затем общий, иначе обычный логотип.
$footerHcfg = \App\Core\HeaderConfig::get();
$footerLogo = trim((string) ($footerHcfg['logo_light_by_lang'][$footerLang] ?? ''));
if ($footerLogo === '') { $footerLogo = trim((string) ($footerHcfg['logo_light'] ?? '')); }
if ($footerLogo === '') { $footerLogo = $logo; }

// Рендер одного виджета колонки подвала.
$renderFooterWidget = function (array $col) use ($footerLogo, $siteName, $address, $phone, $email, $footerMenu, $footerLang, $footerSocial): string {
    switch ($col['widget']) {
        case 'about':
            // Знак и подпись под ним. Контактов здесь нет намеренно: они —
            // отдельный виджет, и на сайте с обеими колонками адрес, телефон
            // и почта выводились дважды.
            $h = '';
            if ($footerLogo !== '') {
                $footerLogoSize = \App\Core\Media::dimensions($footerLogo);
                $h .= '<img class="site-footer__logo" src="' . htmlspecialchars($footerLogo, ENT_QUOTES) . '"'
                    . ($footerLogoSize === null ? '' : ' width="' . $footerLogoSize[0] . '" height="' . $footerLogoSize[1] . '"')
                    . ' loading="lazy" decoding="async" alt="' . htmlspecialchars($siteName, ENT_QUOTES) . '">';
            } else {
                $h .= '<div class="site-footer__name">' . htmlspecialchars($siteName, ENT_QUOTES) . '</div>';
            }
            // Уже очищено санитайзером при сохранении.
            if (trim($col['text']) !== '') { $h .= '<div class="site-footer__about">' . $col['text'] . '</div>'; }
            return $h;
        case 'menu':
            if (empty($footerMenu)) { return ''; }
            $h = '<ul>';
            foreach ($footerMenu as $mi) {
                if (!empty($mi['is_divider'])) { continue; }
                $h .= '<li><a href="' . htmlspecialchars(\App\Models\MenuItem::resolveUrl($mi, $footerLang), ENT_QUOTES) . '">' . htmlspecialchars((string) $mi['title'], ENT_QUOTES) . '</a></li>';
            }
            return $h . '</ul>';
        case 'contacts':
            // Иконка + значение: контакты в подвале ищут глазами, а не читают
            // подряд, и знак у строки находится быстрее самой строки.
            // Ссылки на политику здесь нет — она в нижней строке подвала.
            $contactRows = [];
            if ($address !== '') { $contactRows[] = ['map-pin', $address, '']; }
            if ($phone !== '') { $contactRows[] = ['phone', $phone, 'tel:' . (preg_replace('/[^0-9+]/', '', $phone) ?? '')]; }
            if ($email !== '') { $contactRows[] = ['mail', $email, 'mailto:' . $email]; }
            if ($contactRows === []) { return ''; }
            $h = '<ul class="footer-contacts">';
            foreach ($contactRows as [$contactIcon, $contactText, $contactHref]) {
                $value = htmlspecialchars($contactText, ENT_QUOTES);
                $h .= '<li class="footer-contacts__item">'
                    . '<span class="footer-contacts__icon">' . \App\Core\Icon::render($contactIcon, 18) . '</span>'
                    . ($contactHref === ''
                        ? '<span class="footer-contacts__value">' . $value . '</span>'
                        : '<a class="footer-contacts__value" href="' . htmlspecialchars($contactHref, ENT_QUOTES) . '">' . $value . '</a>')
                    . '</li>';
            }
            return $h . '</ul>';
        case 'social':
            if (empty($footerSocial)) { return ''; }
            $h = '<div class="site-footer__social">';
            foreach ($footerSocial as $btn) {
                // Диктору «telegram» само по себе ничего не говорит: подпись
                // должна читаться как действие, а не как ключ настройки.
                $network = (string) $btn['network'];
                $label = t('Мы в соцсети') . ': ' . mb_convert_case($network, MB_CASE_TITLE);
                $h .= '<a class="site-footer__social-link" href="' . htmlspecialchars((string) $btn['url'], ENT_QUOTES) . '" target="_blank" rel="noopener" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '">' . \App\Core\SocialIcons::glyph($network, 20) . '</a>';
            }
            return $h . '</div>';
        case 'subscribe':
            // Форма подписки в подвале (постит в /subscribe, как и блок).
            $footerConsent = '';
            if (\App\Models\Setting::get('form_consent_enabled', '0') === '1') {
                $footerConsent = '<label class="footer-subscribe__consent">'
                    . '<input type="checkbox" name="consent" value="1" required>'
                    . '<span>' . htmlspecialchars((string) \App\Models\Setting::get(
                        'form_consent_text',
                        t('Я даю согласие на обработку персональных данных')
                    ), ENT_QUOTES) . '</span></label>';
            }
            return '<p class="site-footer__line">' . htmlspecialchars(t('Будьте в курсе наших новостей и аналитических материалов.'), ENT_QUOTES) . '</p>'
                . '<form class="footer-subscribe" method="post" action="' . htmlspecialchars(\App\Core\Locale::url('subscribe', $footerLang), ENT_QUOTES) . '">'
                . \App\Core\Csrf::field()
                . \App\Core\Csrf::honeypotField()
                . '<input type="hidden" name="source" value="footer">'
                . '<input type="email" name="email" placeholder="' . htmlspecialchars(t('Ваш e-mail'), ENT_QUOTES) . '" aria-label="E-mail" autocomplete="email" required>'
                . '<button type="submit" aria-label="' . htmlspecialchars(t('Подписаться'), ENT_QUOTES) . '">' . \App\Core\Icon::render('arrow-right', 20) . '</button>'
                . $footerConsent
                . '</form>'
                . '<div data-push-optin></div>'; // сюда push.js добавляет кнопку уведомлений
        case 'text':
            // Уже очищено санитайзером при сохранении.
            return '<div class="site-footer__text">' . $col['text'] . '</div>';
        default:
            return '';
    }
};
?>
<?php if ($footerStyle === 'columns' && !empty($footerCfg['columns'])): ?>
<footer class="site-footer site-footer--columns">
    <div class="site-footer__inner">
        <?php foreach ($footerCfg['columns'] as $col): ?>
            <?php $inner = $renderFooterWidget($col); ?>
            <?php if ($inner === '' && $col['widget'] !== 'text') { continue; } // пустые колонки скрываем ?>
            <div class="site-footer__col site-footer__col--<?= htmlspecialchars($col['widget'], ENT_QUOTES) ?>">
                <?php // Заголовок приходит из конструктора подвала — переводим так же, как
                      // подпись поиска и текст кнопки шапки: умолчания есть в словаре,
                      // свой текст редактора t() оставит как есть. ?>
                <?php if ($col['heading'] !== ''): ?><div class="site-footer__heading"><?= htmlspecialchars(t($col['heading']), ENT_QUOTES) ?></div><?php endif; ?>
                <?= $inner ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="site-footer__bottom">
        <div class="site-footer__bottom-inner">
            <div class="site-footer__bottom-col site-footer__bottom-col--left">
                <?= htmlspecialchars($footerBottom, ENT_QUOTES) ?>
            </div>
            <div class="site-footer__bottom-col site-footer__bottom-col--middle">
                <?php if ($privacyUrl !== ''): ?>
                    <a href="<?= htmlspecialchars($privacyUrl, ENT_QUOTES) ?>"><?= htmlspecialchars(t('Политика конфиденциальности'), ENT_QUOTES) ?></a>
                <?php endif; ?>
            </div>
            <div class="site-footer__bottom-col site-footer__bottom-col--right">
                <?= $footerBottomLinks ?>
                <?php $footerCounters = \App\Core\SecurityHeaders::injectScriptNonce((string) Setting::get('footer_counters', '')); ?>
                <?php if (trim($footerCounters) !== ''): ?>
                    <div class="site-footer__counters">
                        <?= $footerCounters ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</footer>
<?php else: ?>
<footer class="site-footer">
    <div class="site-footer__bottom">
        <div class="site-footer__bottom-inner">
            <div class="site-footer__bottom-col site-footer__bottom-col--left">
                <?= htmlspecialchars($footerBottom, ENT_QUOTES) ?>
            </div>
            <div class="site-footer__bottom-col site-footer__bottom-col--middle">
                <?php if ($privacyUrl !== ''): ?>
                    <a href="<?= htmlspecialchars($privacyUrl, ENT_QUOTES) ?>"><?= htmlspecialchars(t('Политика конфиденциальности'), ENT_QUOTES) ?></a>
                <?php endif; ?>
            </div>
            <div class="site-footer__bottom-col site-footer__bottom-col--right">
                <?= $footerBottomLinks ?>
                <?php $footerCounters = \App\Core\SecurityHeaders::injectScriptNonce((string) Setting::get('footer_counters', '')); ?>
                <?php if (trim($footerCounters) !== ''): ?>
                    <div class="site-footer__counters">
                        <?= $footerCounters ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</footer>
<?php endif; ?>
<?php // Панель настроек отображения: её открывает кнопка «Для слабовидящих».
      // $a11ySettings задаётся в _header.php (та же область видимости View). ?>
<?php require __DIR__ . '/_a11y_panel.php'; ?>
<?php endif; ?>
<?php // Плавающая кнопка «Наверх» — видимостью управляет класс body.design-scrolltop
      // (тумблер в «Дизайн») и JS (появляется после прокрутки). ?>
<button type="button" class="scroll-top" data-scroll-top aria-label="<?= htmlspecialchars(t('Наверх'), ENT_QUOTES) ?>" title="<?= htmlspecialchars(t('Наверх'), ENT_QUOTES) ?>">
    <?= \App\Core\Icon::render('arrow-up', 20) ?>
</button>
<?php $cspNonce = \App\Core\SecurityHeaders::nonce(); ?>
<script type="application/json" id="frontend-labels"><?= json_encode([
    'linkCopied' => t('Ссылка скопирована'),
    'photoCredit' => t('Фото:'),
    'mediaViewer' => t('Просмотр медиа'),
    'imageViewer' => t('Просмотр изображения'),
    'close' => t('Закрыть'),
    'previous' => t('Предыдущее'),
    'next' => t('Следующее'),
    'goToSlide' => t('Перейти к слайду'),
    'video' => t('Видео'),
    'downloadPhoto' => t('Скачать фото'),
    'download' => t('Скачать'),
    'previousPhoto' => t('Предыдущее фото'),
    'nextPhoto' => t('Следующее фото'),
    'zoomImage' => t('Нажмите для увеличения'),
    'copy' => t('Копировать'),
    'copied' => t('Скопировано'),
    'totalVotes' => t('Всего голосов:'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
</script>
<?php foreach (\App\Core\FrontendAssets::scripts() as $script): ?>
<script src="<?= htmlspecialchars(\App\Core\Asset::url($script), ENT_QUOTES) ?>" defer></script>
<?php endforeach; ?>
<?php if (\App\Core\WebPush::isEnabled()): ?>
<script type="application/json" id="push-config"><?= json_encode([
    'enabled' => true,
    'autoPrompt' => \App\Models\Setting::get('webpush_auto_prompt', '1') === '1',
    'promptDelay' => (int) \App\Models\Setting::get('webpush_prompt_delay', '15'),
    'labels' => [
        'off' => t('Уведомления о новостях'),
        'on' => t('Уведомления включены'),
        // Карточка предложения подписки рисуется скриптом, поэтому её текст
        // тоже приходит переведённым отсюда, а не литералом в JS.
        'promptText' => t('Будьте в курсе главных событий! Подпишитесь на мгновенные push-уведомления о новых публикациях.'),
        'enable' => t('Включить уведомления'),
        'later' => t('Позже'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
</script>
<script src="<?= htmlspecialchars(\App\Core\Asset::url('/assets/js/push.js'), ENT_QUOTES) ?>" defer></script>
<?php endif; ?>
<?= \App\Core\AssetCollector::renderScripts() /* JS блоков — по одному разу */ ?>
<?php // Core Web Vitals с реальных посетителей. Лабораторные замеры не
      // показывают INP вовсе, поэтому поле — единственный источник правды
      // по отзывчивости. Отправляется одним sendBeacon при скрытии вкладки;
      // ни адреса страницы, ни идентификатора посетителя не собирается. ?>
<?php if (\App\Core\WebVitals::enabled()): ?>
<script type="application/json" id="web-vitals-config"><?= json_encode([
    'endpoint' => '/_vitals',
    'rate' => \App\Core\WebVitals::sampleRate(),
    'page' => \App\Core\WebVitals::pageKind($viewTemplate ?? ''),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script src="<?= htmlspecialchars(\App\Core\Asset::url('/assets/vendor/web-vitals/web-vitals.js'), ENT_QUOTES) ?>" defer></script>
<script src="<?= htmlspecialchars(\App\Core\Asset::url('/assets/js/web-vitals-report.js'), ENT_QUOTES) ?>" defer></script>
<?php endif; ?>
<?php if ($analyticsInit !== ''): ?>
<?php // Код счётчиков инертен (type text/plain); consent.js активирует его,
      // перенося nonce с держателя на создаваемый <script> (CSP). ?>
<script type="text/plain" id="analytics-init" nonce="<?= $cspNonce ?>"><?= $analyticsInit ?></script>
<script type="application/json" id="consent-config"><?= json_encode([
    'required' => $consentRequired,
    'privacyUrl' => $privacyUrl,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
<script src="<?= htmlspecialchars(\App\Core\Asset::url('/assets/js/consent.js'), ENT_QUOTES) ?>" defer></script>
<?php endif; ?>
<?php // Глобальный произвольный JS (группа 6, супер-админ). ?>
<?php $globalJs = Setting::get('custom_js_global', ''); ?>
<?php if (trim($globalJs) !== ''): ?>
<script nonce="<?= $cspNonce ?>"><?= $globalJs ?></script>
<?php endif; ?>
<?php if (!empty($page['custom_js']) && !empty($page['id'])): ?>
<?php
$pageJsUrls = \App\Core\CustomAssetHelper::resolveJsUrls((string) $page['custom_js'], (int) $page['id']);
\App\Core\SecurityHeaders::allowPageAssets([], \App\Core\CustomAssetHelper::originsOf($pageJsUrls));
?>
<?php foreach ($pageJsUrls as $pageJsUrl): ?>
<script src="<?= htmlspecialchars($pageJsUrl, ENT_QUOTES) ?>" defer></script>
<?php endforeach; ?>
<?php endif; ?>
</body>
</html>
