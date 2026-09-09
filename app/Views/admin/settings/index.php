<?php

use App\Core\Csrf;
use App\Core\AdminUi;

$pageTitle = 'Настройки';
$activeNav = 'settings';
require __DIR__ . '/../layout/header.php';

/** @var array $settings */
?>
<p class="form-hint admin-section-intro">
    Общие параметры сайта, интеграции и служебные инструменты. Дизайн, шапка,
    подвал и Telegram управляются в собственных разделах.
</p>
<nav class="settings-jump-nav" aria-label="Разделы настроек">
    <a href="#settings-site"><?= AdminUi::icon('settings', 16) ?>Сайт и контакты</a>
    <a href="#settings-brand"><?= AdminUi::icon('sparkles', 16) ?>Брендинг</a>
    <a href="#smtp-section"><?= AdminUi::icon('email', 16) ?>Почта</a>
    <a href="#settings-privacy"><?= AdminUi::icon('shield', 16) ?>Приватность</a>
    <a href="#settings-integrations"><?= AdminUi::icon('plug', 16) ?>Интеграции</a>
    <a href="#settings-analytics"><?= AdminUi::icon('stats', 16) ?>Аналитика</a>
    <a href="#settings-ai"><?= AdminUi::icon('seo', 16) ?>ИИ (AI)</a>
    <a href="#import-section"><?= AdminUi::icon('database-import', 16) ?>Импорт новостей</a>
    <a href="#demo-section"><?= AdminUi::icon('database', 16) ?>Демо и RESET</a>
    <a href="#backup-section"><?= AdminUi::icon('save', 16) ?>Бэкап</a>
    <a href="/admin/database"><?= AdminUi::icon('database', 16) ?>Обновление БД</a>
    <a href="/admin/telegram"><?= AdminUi::icon('telegram', 16) ?>Telegram</a>
</nav>

<div id="settings-general">
    <form method="post" action="/admin/settings" enctype="multipart/form-data">
        <?= Csrf::field() ?>

        <?php $defaultLangCode = strtolower(\App\Models\Language::defaultCode()); ?>

        <!-- 1. Сайт и контактные данные -->
        <section class="settings-card" id="settings-site">
            <div class="settings-card__header">
                <div class="settings-card__icon"><?= AdminUi::icon('settings', 20) ?></div>
                <div>
                    <h3 class="settings-card__title">Сайт и контактные данные</h3>
                    <p class="settings-card__subtitle">Основные сведения об организации, телефоны, адреса и официальный логотип.</p>
                </div>
            </div>

            <div class="form-grid-12">
                <div class="form-field col-12">
                    <label for="site_name">Название сайта <span class="badge badge--small">Основное / <?= strtoupper($defaultLangCode) ?></span></label>
                    <input type="text" id="site_name" name="site_name" maxlength="160" value="<?= htmlspecialchars($settings['site_name'] ?? '', ENT_QUOTES) ?>">
                </div>

                <?php foreach (($languages ?? []) as $lang): ?>
                    <?php 
                    $code = strtolower((string) ($lang['code'] ?? ''));
                    if ($code === $defaultLangCode) {
                        continue;
                    }
                    ?>
                    <div class="form-field col-12">
                        <label for="site_name_<?= $code ?>">Название сайта <span class="badge badge--small"><?= htmlspecialchars($lang['name'], ENT_QUOTES) ?> (<?= strtoupper($code) ?>)</span></label>
                        <input type="text" id="site_name_<?= $code ?>" name="site_name_<?= $code ?>" maxlength="160" value="<?= htmlspecialchars($settings['site_name_' . $code] ?? '', ENT_QUOTES) ?>" placeholder="Название на языке <?= htmlspecialchars($lang['name'], ENT_QUOTES) ?>">
                    </div>
                <?php endforeach; ?>

                <div class="col-12">
                    <?= \App\Core\AdminUi::imageField('logo_url', $settings['logo_url'] ?? '', [
                        'label' => 'Логотип сайта',
                        'file' => 'logo_file',
                    ]) ?>
                </div>

                <div class="settings-design-link col-12">
                    <strong>Цвета, шрифты и тема оформления</strong>
                    <span>Теперь настраиваются в одном месте — в разделе «Дизайн сайта».</span>
                    <a href="/admin/design" class="btn btn--small">Открыть управление дизайном</a>
                </div>

                <div class="form-field col-6">
                    <label for="contact_phone">Контактный телефон</label>
                    <input type="tel" id="contact_phone" name="contact_phone" maxlength="80" value="<?= htmlspecialchars($settings['contact_phone'] ?? '', ENT_QUOTES) ?>" placeholder="+998 (71) 123-45-67">
                </div>

                <div class="form-field col-6">
                    <label for="contact_email">Email для обращений</label>
                    <input type="email" id="contact_email" name="contact_email" maxlength="254" value="<?= htmlspecialchars($settings['contact_email'] ?? '', ENT_QUOTES) ?>" placeholder="info@agency.gov.uz">
                </div>

                <div class="form-field col-12">
                    <label for="contact_address">Физический адрес <span class="badge badge--small">Основной / <?= strtoupper($defaultLangCode) ?></span></label>
                    <input type="text" id="contact_address" name="contact_address" maxlength="500" value="<?= htmlspecialchars($settings['contact_address'] ?? '', ENT_QUOTES) ?>">
                </div>

                <?php foreach (($languages ?? []) as $lang): ?>
                    <?php
                    $code = strtolower((string) ($lang['code'] ?? ''));
                    if ($code === $defaultLangCode) {
                        continue;
                    }
                    ?>
                    <div class="form-field col-12">
                        <label for="contact_address_<?= $code ?>">Адрес <span class="badge badge--small"><?= htmlspecialchars($lang['name'], ENT_QUOTES) ?> (<?= strtoupper($code) ?>)</span></label>
                        <input type="text" id="contact_address_<?= $code ?>" name="contact_address_<?= $code ?>" maxlength="500" value="<?= htmlspecialchars($settings['contact_address_' . $code] ?? '', ENT_QUOTES) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- 2. Брендинг панели управления -->
        <section class="settings-card" id="settings-brand">
            <div class="settings-card__header">
                <div class="settings-card__icon"><?= AdminUi::icon('sparkles', 20) ?></div>
                <div>
                    <h3 class="settings-card__title">Брендинг панели управления</h3>
                    <p class="settings-card__subtitle">Индивидуальное название, акцентный цвет и фирменный логотип в админке.</p>
                </div>
            </div>

            <div class="form-grid-12">
                <div class="form-field col-8">
                    <label for="admin_brand_name">Название панели управления</label>
                    <input type="text" id="admin_brand_name" name="admin_brand_name" maxlength="60"
                           value="<?= htmlspecialchars($settings['admin_brand_name'] ?? '', ENT_QUOTES) ?>"
                           placeholder="<?= \App\Core\AdminBrand::DEFAULT_NAME ?>">
                    <span class="form-hint">Показывается в шапке админки, заголовке вкладки и на странице входа. Пусто — «<?= \App\Core\AdminBrand::DEFAULT_NAME ?>».</span>
                </div>

                <div class="form-field col-4">
                    <label for="admin_brand_accent">Акцентный цвет кнопок и меню</label>
                    <input class="u-inline-cb38de4646" type="color" id="admin_brand_accent" name="admin_brand_accent"
                           value="<?= htmlspecialchars(\App\Core\AdminBrand::accent(), ENT_QUOTES) ?>">
                    <span class="form-hint">Стандартный синий: <?= \App\Core\AdminBrand::DEFAULT_ACCENT ?>.</span>
                </div>

                <div class="col-12">
                    <?= \App\Core\AdminUi::imageField('admin_brand_logo', $settings['admin_brand_logo'] ?? '', [
                        'label' => 'Логотип панели управления',
                        'file' => 'admin_brand_logo_file',
                    ]) ?>
                    <span class="form-hint">Горизонтальный логотип на прозрачном фоне (SVG или PNG).</span>
                </div>
            </div>
        </section>

        <!-- 3. Настройки почты (SMTP) -->
        <section class="settings-card" id="smtp-section">
            <div class="settings-card__header">
                <div class="settings-card__icon"><?= AdminUi::icon('email', 20) ?></div>
                <div>
                    <h3 class="settings-card__title">Настройки почты (SMTP / Email-уведомления)</h3>
                    <p class="settings-card__subtitle">Параметры подключения к почтовому серверу для отправки писем и восстановления паролей.</p>
                </div>
            </div>

            <div class="form-grid-12">
                <div class="form-field col-8">
                    <label for="smtp_host">SMTP Сервер (Host)</label>
                    <input type="text" id="smtp_host" name="smtp_host" maxlength="253"
                           value="<?= htmlspecialchars($settings['smtp_host'] ?? '', ENT_QUOTES) ?>"
                           placeholder="smtp.yandex.ru / smtp.gmail.com">
                </div>

                <div class="form-field col-4">
                    <label for="smtp_port">Порт SMTP</label>
                    <input type="number" id="smtp_port" name="smtp_port" min="1" max="65535" inputmode="numeric"
                           value="<?= htmlspecialchars($settings['smtp_port'] ?? '587', ENT_QUOTES) ?>"
                           placeholder="587 или 465">
                </div>

                <div class="form-field col-6">
                    <label for="smtp_encryption">Тип шифрования</label>
                    <select id="smtp_encryption" name="smtp_encryption">
                        <option value="tls" <?= ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS / STARTTLS (порт 587)</option>
                        <option value="ssl" <?= ($settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL / Implicit TLS (порт 465)</option>
                        <option value="none" <?= ($settings['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>Без шифрования (порт 25)</option>
                    </select>
                </div>

                <div class="form-field col-6">
                    <label for="smtp_username">Логин SMTP (Email)</label>
                    <input type="text" id="smtp_username" name="smtp_username" maxlength="254"
                           value="<?= htmlspecialchars($settings['smtp_username'] ?? '', ENT_QUOTES) ?>"
                           placeholder="no-reply@agency.gov.uz">
                </div>

                <div class="form-field col-12">
                    <label for="smtp_password">Пароль SMTP (или пароль приложения)</label>
                    <input type="password" id="smtp_password" name="smtp_password"
                           value="" autocomplete="new-password"
                           placeholder="<?= !empty($settings['smtp_password']) ? 'Сохранён — оставьте пустым без изменений' : 'Введите пароль SMTP' ?>">
                    <?php if (!empty($settings['smtp_password'])): ?>
                        <label class="form-hint"><input type="checkbox" name="clear_smtp_password" value="1"> Удалить сохранённый пароль</label>
                    <?php endif; ?>
                </div>

                <div class="form-field col-6">
                    <label for="smtp_from_email">Email отправителя (From Email)</label>
                    <input type="email" id="smtp_from_email" name="smtp_from_email" maxlength="254"
                           value="<?= htmlspecialchars($settings['smtp_from_email'] ?? '', ENT_QUOTES) ?>"
                           placeholder="no-reply@agency.gov.uz">
                </div>

                <div class="form-field col-6">
                    <label for="smtp_from_name">Имя отправителя (From Name)</label>
                    <input type="text" id="smtp_from_name" name="smtp_from_name" maxlength="120"
                           value="<?= htmlspecialchars($settings['smtp_from_name'] ?? 'ASDR CMS', ENT_QUOTES) ?>"
                           placeholder="ASDR CMS">
                </div>
            </div>
        </section>

        <!-- 4. Push-уведомления -->
        <section class="settings-card" id="settings-integrations">
            <div class="settings-card__header">
                <div class="settings-card__icon"><?= AdminUi::icon('plug', 20) ?></div>
                <div>
                    <h3 class="settings-card__title">Push-уведомления в браузере</h3>
                    <p class="settings-card__subtitle">Мгновенная доставка новостей подписчикам сайта через Web Push API.</p>
                </div>
            </div>

            <div class="form-grid-12">
                <div class="form-field form-field--checkbox col-12">
                    <input type="checkbox" id="webpush_enabled" name="webpush_enabled" value="1" <?= ($settings['webpush_enabled'] ?? '') === '1' ? 'checked' : '' ?>>
                    <label for="webpush_enabled">Включить подписку на Web Push уведомления</label>
                </div>

                <div class="form-field form-field--checkbox col-8">
                    <input type="checkbox" id="webpush_auto_prompt" name="webpush_auto_prompt" value="1" <?= ($settings['webpush_auto_prompt'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <label for="webpush_auto_prompt">Автоматически показывать всплывающую плашку подписки (Prompt Banner)</label>
                </div>

                <div class="form-field col-4">
                    <label for="webpush_prompt_delay">Задержка показа (сек.)</label>
                    <input type="number" id="webpush_prompt_delay" name="webpush_prompt_delay" value="<?= htmlspecialchars((string) ($settings['webpush_prompt_delay'] ?? '15'), ENT_QUOTES) ?>" min="1" max="300" placeholder="15">
                </div>

                <div class="col-12">
                    <span class="form-hint">
                        Внизу сайта появится интерактивная кнопка-колокольчик.
                        Подписчиков сейчас: <strong><?= \App\Models\WebPushSubscription::count() ?></strong>.
                    </span>
                </div>
            </div>
        </section>

        <!-- 5. Веб-аналитика -->
        <section class="settings-card" id="settings-analytics">
            <div class="settings-card__header">
                <div class="settings-card__icon"><?= AdminUi::icon('stats', 20) ?></div>
                <div>
                    <h3 class="settings-card__title">Веб-аналитика и трекинг</h3>
                    <p class="settings-card__subtitle">Безопасное подключение систем метрики Google Analytics 4 и Яндекс.Метрики.</p>
                </div>
            </div>

            <div class="form-grid-12">
                <div class="form-field col-6">
                    <label for="analytics_ga_id">Google Analytics ID</label>
                    <input type="text" id="analytics_ga_id" name="analytics_ga_id" value="<?= htmlspecialchars($settings['analytics_ga_id'] ?? '', ENT_QUOTES) ?>" placeholder="G-XXXXXXXXXX">
                    <span class="form-hint">Формат <code>G-XXXXXXXXXX</code>. Скрипт подключается автоматически.</span>
                </div>

                <div class="form-field col-6">
                    <label for="analytics_ym_id">Яндекс.Метрика ID (Номер счётчика)</label>
                    <input type="text" id="analytics_ym_id" name="analytics_ym_id" value="<?= htmlspecialchars($settings['analytics_ym_id'] ?? '', ENT_QUOTES) ?>" placeholder="12345678" inputmode="numeric">
                </div>
            </div>
        </section>

        <!-- 6. Приватность и GDPR -->
        <section class="settings-card" id="settings-privacy">
            <div class="settings-card__header">
                <div class="settings-card__icon"><?= AdminUi::icon('shield', 20) ?></div>
                <div>
                    <h3 class="settings-card__title">Приватность и обработка данных (GDPR / 152-ФЗ)</h3>
                    <p class="settings-card__subtitle">Управление согласиями на обработку персональных данных, капчей и cookie-баннером.</p>
                </div>
            </div>

            <div class="form-grid-12">
                <div class="form-field form-field--checkbox col-12">
                    <input type="checkbox" id="cookie_consent_enabled" name="cookie_consent_enabled" value="1" <?= ($settings['cookie_consent_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <label for="cookie_consent_enabled">Показывать Cookie-Consent баннер (счётчики грузятся только после согласия)</label>
                </div>

                <div class="form-field col-8">
                    <label for="privacy_policy_page_id">Страница «Политика конфиденциальности»</label>
                    <select id="privacy_policy_page_id" name="privacy_policy_page_id">
                        <option value="">— не выбрано —</option>
                        <?php foreach (($pages ?? []) as $p): ?>
                            <?php if (($p['status'] ?? '') !== 'published') { continue; } ?>
                            <option value="<?= (int) $p['id'] ?>" <?= (string) ($settings['privacy_policy_page_id'] ?? '') === (string) $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $p['title'], ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field col-4">
                    <label for="pii_retention_days">Срок хранения ПДн (дней)</label>
                    <input type="number" id="pii_retention_days" name="pii_retention_days" min="0" value="<?= htmlspecialchars($settings['pii_retention_days'] ?? '0', ENT_QUOTES) ?>" placeholder="0 — бессрочно">
                </div>

                <div class="form-field form-field--checkbox col-12">
                    <input type="checkbox" id="form_consent_enabled" name="form_consent_enabled" value="1" <?= ($settings['form_consent_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <label for="form_consent_enabled">Требовать согласие на обработку персональных данных во всех публичных формах</label>
                </div>

                <div class="form-field col-12">
                    <label for="form_consent_text">Текст согласия</label>
                    <input type="text" id="form_consent_text" name="form_consent_text" maxlength="500" value="<?= htmlspecialchars($settings['form_consent_text'] ?? 'Я согласен на обработку персональных данных', ENT_QUOTES) ?>">
                </div>

                <div class="form-field form-field--checkbox col-12">
                    <input type="checkbox" id="captcha_enabled" name="captcha_enabled" value="1" <?= ($settings['captcha_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <label for="captcha_enabled">Включить графическую капчу на формах обратной связи (локальная генерация без сторонних сервисов)</label>
                </div>
            </div>
        </section>

        <!-- 7. ИИ-Интеграция -->
        <section class="settings-card" id="settings-ai">
            <div class="settings-card__header">
                <div class="settings-card__icon"><?= AdminUi::icon('seo', 20) ?></div>
                <div>
                    <h3 class="settings-card__title">ИИ-Интеграция (Google Gemini API / AI)</h3>
                    <p class="settings-card__subtitle">Автоматическая генерация аннотаций новостей, тегов, SEO-заголовков и переводов через Gemini AI.</p>
                </div>
            </div>

            <div class="form-grid-12">
                <div class="form-field col-12">
                    <label for="ai_api_key">Ключ API (Google Gemini API Key)</label>
                    <input type="password" id="ai_api_key" name="ai_api_key" value=""
                           placeholder="<?= !empty($settings['ai_api_key']) ? 'Сохранён — оставьте пустым без изменений' : 'AIzaSy...' ?>">
                    <?php if (!empty($settings['ai_api_key'])): ?>
                        <label class="form-hint"><input type="checkbox" name="clear_ai_api_key" value="1"> Удалить сохранённый ключ</label>
                    <?php endif; ?>
                    <span class="form-hint">Вставьте ключ из <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">Google AI Studio</a>.</span>
                </div>
            </div>
        </section>

        <!-- 8. Favicon и PWA -->
        <section class="settings-card" id="settings-pwa">
            <div class="settings-card__header">
                <div class="settings-card__icon"><?= AdminUi::icon('sparkles', 20) ?></div>
                <div>
                    <h3 class="settings-card__title">Favicon и PWA</h3>
                    <p class="settings-card__subtitle">Иконка вкладки браузера, ярлык мобильного приложения и акцентный цвет темы устройства.</p>
                </div>
            </div>

            <div class="form-grid-12">
                <div class="col-12">
                    <?= \App\Core\AdminUi::imageField('favicon_url', $settings['favicon_url'] ?? '', [
                        'label' => 'Favicon иконка (.svg / .png)',
                        'file' => 'favicon_file',
                        'accept' => 'image/png,image/svg+xml',
                    ]) ?>
                </div>

                <div class="form-field col-8">
                    <label for="pwa_short_name">Короткое имя приложения (для экрана смартфона)</label>
                    <input type="text" id="pwa_short_name" name="pwa_short_name" maxlength="12" value="<?= htmlspecialchars($settings['pwa_short_name'] ?? '', ENT_QUOTES) ?>">
                </div>

                <div class="form-field col-4">
                    <label for="theme_color">Theme Color (HEX)</label>
                    <input type="color" id="theme_color" name="theme_color" value="<?= htmlspecialchars($settings['theme_color'] ?? '#1a1a1a', ENT_QUOTES) ?>">
                </div>
            </div>
        </section>

        <!-- 9. Глобальное SEO и соцсети -->
        <section class="settings-card" id="settings-seo">
            <div class="settings-card__header">
                <div class="settings-card__icon"><?= AdminUi::icon('globe', 20) ?></div>
                <div>
                    <h3 class="settings-card__title">Глобальное SEO и соцсети</h3>
                    <p class="settings-card__subtitle">Метаданные для поисковых систем и изображение для превью в мессенджерах (OpenGraph).</p>
                </div>
            </div>

            <div class="form-grid-12">
                <div class="form-field col-12">
                    <label for="default_meta_description">Meta Description по умолчанию</label>
                    <input type="text" id="default_meta_description" name="default_meta_description" maxlength="320" value="<?= htmlspecialchars($settings['default_meta_description'] ?? '', ENT_QUOTES) ?>">
                </div>

                <div class="col-12">
                    <?= \App\Core\AdminUi::imageField('default_og_image', $settings['default_og_image'] ?? '', [
                        'label' => 'OG:Image по умолчанию (для Telegram / соцсетей)',
                        'file' => 'default_og_image_file',
                    ]) ?>
                </div>
            </div>
        </section>

        <!-- 10. Режим обслуживания -->
        <section class="settings-card" id="settings-maintenance">
            <div class="settings-card__header">
                <div class="settings-card__icon"><?= AdminUi::icon('tools', 20) ?></div>
                <div>
                    <h3 class="settings-card__title">Режим обслуживания</h3>
                    <p class="settings-card__subtitle">Временное закрытие публичной части сайта во время технических работ.</p>
                </div>
            </div>

            <div class="form-grid-12">
                <div class="form-field form-field--checkbox col-12">
                    <input type="checkbox" id="maintenance_mode" name="maintenance_mode" value="1" <?= ($settings['maintenance_mode'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <label for="maintenance_mode">Закрыть публичный сайт для посетителей (доступ только администраторам)</label>
                </div>

                <div class="form-field col-12">
                    <label for="maintenance_message">Сообщение посетителям сайта</label>
                    <input type="text" id="maintenance_message" name="maintenance_message" maxlength="500"
                           value="<?= htmlspecialchars($settings['maintenance_message'] ?? '', ENT_QUOTES) ?>"
                           placeholder="Сайт временно закрыт на техническое обслуживание.">
                </div>
            </div>
        </section>

        <!-- 11. Произвольный код сайта -->
        <section class="settings-card" id="settings-code">
            <div class="settings-card__header">
                <div class="settings-card__icon"><?= AdminUi::icon('document', 20) ?></div>
                <div>
                    <h3 class="settings-card__title">Произвольный код сайта (CSS / JS / Счетчики)</h3>
                    <p class="settings-card__subtitle">Глобальные стили, скрипты и счетчики в подвале сайта.</p>
                </div>
            </div>

            <div class="form-grid-12">
                <div class="form-field col-12">
                    <label for="custom_css_global">Глобальный CSS</label>
                    <textarea id="custom_css_global" name="custom_css_global" rows="5" class="admin-code-input"><?= htmlspecialchars($settings['custom_css_global'] ?? '', ENT_QUOTES) ?></textarea>
                </div>

                <div class="form-field col-12">
                    <label for="custom_js_global">Глобальный JS (без тегов &lt;script&gt;)</label>
                    <textarea id="custom_js_global" name="custom_js_global" rows="5" class="admin-code-input"><?= htmlspecialchars($settings['custom_js_global'] ?? '', ENT_QUOTES) ?></textarea>
                </div>

                <div class="form-field col-12">
                    <label for="footer_counters">Счетчики в нижней строке подвала (HTML/JS-код)</label>
                    <textarea id="footer_counters" name="footer_counters" rows="6" class="admin-code-input" placeholder='Например: <a href="https://www.uz/"><img src="..."></a> или код Яндекс.Метрики'><?= htmlspecialchars($settings['footer_counters'] ?? '', ENT_QUOTES) ?></textarea>
                </div>
            </div>
        </section>

        <div class="form-actions form-actions--sticky">
            <button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('save') ?>Сохранить все настройки</button>
        </div>
    </form>
</div>

<!-- Блок проверки SMTP -->
<div class="settings-card admin-mt-24">
    <div class="settings-card__header">
        <div class="settings-card__icon"><?= AdminUi::icon('send', 20) ?></div>
        <div>
            <h3 class="settings-card__title">Проверка отправки писем (SMTP Test)</h3>
            <p class="settings-card__subtitle">Сохраните настройки SMTP выше, затем укажите ваш Email для контрольной отправки.</p>
        </div>
    </div>

    <form class="u-inline-a8022cb6d9" method="post" action="/admin/settings/test-email">
        <?= Csrf::field() ?>
        <input class="u-inline-65e3af54b5" type="email" name="test_email" placeholder="ваш_email@example.com" required>
        <button type="submit" class="btn btn--outline"><?= AdminUi::icon('send', 16) ?>Отправить тестовое письмо</button>
    </form>
</div>

<!-- Импорт новостей WXR / XML -->
<div class="settings-card admin-mt-24" id="import-section">
    <div class="settings-card__header">
        <div class="settings-card__icon"><?= AdminUi::icon('database-import', 20) ?></div>
        <div>
            <h3 class="settings-card__title">Импорт новостей (WXR / XML)</h3>
            <p class="settings-card__subtitle">Пошаговый перенос новостей, категорий, изображений и связей переводов из WXR/XML-экспорта старого сайта.</p>
        </div>
    </div>
    <div class="admin-mt-12">
        <a href="/admin/news/import" class="btn btn--primary"><?= AdminUi::icon('database-import', 16) ?>Перейти к мастеру импорта новостей</a>
    </div>
</div>

<!-- Демо-контент и Инициализация -->
<div class="settings-card admin-mt-24" id="demo-section">
    <div class="settings-card__header">
        <div class="settings-card__icon"><?= AdminUi::icon('database', 20) ?></div>
        <div>
            <h3 class="settings-card__title">Демо-контент и Инициализация</h3>
            <p class="settings-card__subtitle">Выборочная загрузка эталонных данных или полный сброс модулей сайта.</p>
        </div>
    </div>
    
    <div class="admin-card-grid">
        <div class="admin-demo-card">
            <h4 class="u-inline-ee3d7719a8">1. Безопасное дополнение (DEMO)</h4>
            <p class="form-hint u-inline-7ca9af90e8">Добавляет недостающие демо-записи в выбранные разделы. Ваши текущие записи, отредактированная главная страница и меню <strong>не изменяются и не удаляются</strong>.</p>
            <form method="post" action="/admin/settings/demo-content" data-confirm="Дополнить демо-контентом выбранные разделы сайта?">
                <?= Csrf::field() ?>
                
                <div class="demo-modules-selector">
                    <div class="demo-modules-head">
                        <span>Выберите разделы для загрузки:</span>
                    </div>
                    <div class="demo-modules-grid">
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="news" checked> Новости и рубрики</label>
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="pages" checked> Страницы</label>
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="projects" checked> Проекты</label>
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="entries" checked> Документы и тендеры</label>
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="team" checked> Руководство</label>
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="media" checked> Фотоальбомы и видео</label>
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="forms" checked> Формы</label>
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="menu" checked> Главное меню (стандарт)</label>
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="home" checked> Главная страница</label>
                    </div>
                </div>

                <div class="form-field u-inline-72d6c38e5f">
                    <label class="u-inline-f6909f80c2" for="demo_confirm_code">Код подтверждения</label>
                    <input type="text" id="demo_confirm_code" name="demo_confirm_code" required autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Введите DEMO">
                    <span class="form-hint">Для запуска введите <code>DEMO</code>.</span>
                </div>
                <button type="submit" class="btn btn--primary">Дополнить выбранными данными</button>
            </form>
        </div>

        <div class="admin-demo-card admin-demo-card--danger">
            <h4 class="u-inline-ee3d7719a8">2. Полный сброс и перезагрузка (RESET + DEMO)</h4>
            <p class="form-hint u-inline-7ca9af90e8"><strong>ВНИМАНИЕ!</strong> Сначала создаёт резервную копию, затем очищает все разделы сайта и создаёт заново выбранные демо-модули RU/UZ. Меню создаётся стандартным (без мега-колонок).</p>
            <form method="post" action="/admin/settings/demo-reset" data-confirm="ВНИМАНИЕ: Все текущие новости, страницы, меню и каталог будут полностью очищены и заменены эталонным демо-комплектом. Продолжить?">
                <?= Csrf::field() ?>

                <div class="demo-modules-selector">
                    <div class="demo-modules-head">
                        <span>Модули для создания после сброса:</span>
                    </div>
                    <div class="demo-modules-grid">
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="news" checked> Новости и рубрики</label>
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="pages" checked> Страницы</label>
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="projects" checked> Проекты</label>
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="entries" checked> Документы и тендеры</label>
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="team" checked> Руководство</label>
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="media" checked> Фотоальбомы и видео</label>
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="forms" checked> Формы</label>
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="menu" checked> Главное меню (стандарт)</label>
                        <label class="demo-module-item"><input type="checkbox" name="modules[]" value="home" checked> Главная страница</label>
                    </div>
                </div>

                <div class="form-field u-inline-72d6c38e5f">
                    <label class="u-inline-f6909f80c2" for="reset_confirm_code">Код подтверждения полным сбросом</label>
                    <input type="text" id="reset_confirm_code" name="demo_confirm_code" required autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Введите RESET">
                    <span class="form-hint">Для подтверждения полного сброса введите <code>RESET</code>.</span>
                </div>
                <button type="submit" class="btn btn--danger">RESET + DEMO (Сброс и замена)</button>
            </form>
        </div>
    </div>
</div>

<!-- Управление базой данных и миграции -->
<div class="settings-card admin-mt-24" id="database-section">
    <div class="settings-card__header">
        <div class="settings-card__icon"><?= AdminUi::icon('database', 20) ?></div>
        <div>
            <h3 class="settings-card__title">База данных и миграции</h3>
            <p class="settings-card__subtitle">Проверка актуальности схемы, применение новых миграций в 1 клик, метрики таблиц и диагностика.</p>
        </div>
    </div>
    <div class="admin-mt-12">
        <a href="/admin/database" class="btn btn--primary"><?= AdminUi::icon('database', 16) ?>Перейти к управлению базой данных</a>
    </div>
</div>

<!-- Резервное копирование -->
<div class="settings-card admin-mt-24" id="backup-section">
    <div class="settings-card__header">
        <div class="settings-card__icon"><?= AdminUi::icon('save', 20) ?></div>
        <div>
            <h3 class="settings-card__title">Резервное копирование</h3>
            <p class="settings-card__subtitle">Скачать полный архив сайта (дамп базы данных MySQL + все загруженные медиафайлы).</p>
        </div>
    </div>
    <form method="post" action="/admin/backup">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn--primary"><?= AdminUi::icon('save', 16) ?>Скачать полный бэкап (.zip)</button>
    </form>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
