<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Backup;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\DemoSeeder;
use App\Core\Flash;
use App\Core\ImageField;
use App\Core\SettingsValidator;
use App\Core\View;
use App\Models\Page;
use App\Models\Setting;

final class SettingsController
{
    private const DEMO_CONFIRM_CODE = 'DEMO';
    /**
     * Ключи Telegram сюда НЕ входят: их полей в этой форме больше нет (раздел
     * «Telegram»), а сохранение по списку затёрло бы токены пустой строкой и
     * выключило коды входа всем администраторам.
     */
    private const TEXT_KEYS = [
        'site_name',
        'contact_phone', 'contact_email', 'contact_address',
        'default_meta_description',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_from_email', 'smtp_from_name',
    ];
    private const SECRET_KEYS = ['ai_api_key', 'smtp_password'];

    public function index(): void
    {
        Auth::requireSuperAdmin();
        View::render('admin/settings/index', [
            'settings' => Setting::all(),
            'pages' => Page::all(),
            'languages' => \App\Models\Language::active(),
        ]);
    }

    public function update(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $activeLangs = \App\Models\Language::active();
        $normalized = [];
        $errors = [];
        foreach (self::TEXT_KEYS as $key) {
            $raw = (string) ($_POST[$key] ?? '');
            $normalized[$key] = match ($key) {
                'site_name' => SettingsValidator::plainText($raw, 160),
                'contact_phone' => SettingsValidator::plainText($raw, 80),
                'contact_address' => SettingsValidator::plainText($raw, 500),
                'default_meta_description' => SettingsValidator::plainText($raw, 320),
                'smtp_host' => SettingsValidator::smtpHost($raw),
                'smtp_port' => ($port = SettingsValidator::port($raw, 587)) === null
                    ? null
                    : (string) $port,
                'smtp_encryption' => in_array($raw, ['tls', 'ssl', 'none'], true) ? $raw : 'tls',
                'smtp_username' => SettingsValidator::plainText($raw, 254),
                'smtp_from_name' => SettingsValidator::plainText($raw, 120),
                // Оба ключа перечислены намеренно: список TEXT_KEYS растёт, и
                // привязка разбора к порядку веток сделала бы правку списка
                // молчаливой сменой проверки.
                // @phpstan-ignore match.alwaysTrue
                'contact_email', 'smtp_from_email' => SettingsValidator::optionalEmail($raw),
                default => SettingsValidator::plainText($raw),
            };
            if ($normalized[$key] === null) {
                $errors[] = match ($key) {
                    'smtp_host' => 'SMTP-сервер указан неверно: введите только имя хоста без https:// и номера порта.',
                    'smtp_port' => 'Порт SMTP должен быть целым числом от 1 до 65535.',
                    'contact_email' => 'Контактный email указан неверно.',
                    'smtp_from_email' => 'Email отправителя SMTP указан неверно.',
                    default => 'Проверьте значение поля «' . $key . '».',
                };
            }
        }
        if ($errors !== []) {
            Flash::error(implode(' ', $errors));
            header('Location: /admin/settings');
            exit;
        }
        foreach ($normalized as $key => $value) {
            Setting::set($key, (string) $value);
            foreach ($activeLangs as $lang) {
                $code = strtolower((string) $lang['code']);
                $locKey = $key . '_' . $code;
                if (isset($_POST[$locKey])) {
                    Setting::set($locKey, SettingsValidator::plainText(
                        (string) $_POST[$locKey],
                        $key === 'site_name' ? 160 : ($key === 'contact_address' ? 500 : 320)
                    ));
                }
            }
        }
        foreach (self::SECRET_KEYS as $key) {
            if (!empty($_POST['clear_' . $key])) {
                Setting::set($key, '');
                continue;
            }
            $newValue = mb_substr(trim((string) ($_POST[$key] ?? '')), 0, 10000);
            if ($newValue !== '') {
                Setting::set($key, $newValue);
            }
        }

        $logoUrl = ImageField::resolve('logo_file', 'logo_url', Setting::get('logo_url'), Auth::id());
        Setting::set('logo_url', $logoUrl ?? '');

        // --- Веб-аналитика: строгие ID вместо сырого JS (задача 116) ---
        Setting::set('analytics_ga_id', SettingsValidator::gaId((string) ($_POST['analytics_ga_id'] ?? '')));
        Setting::set('analytics_ym_id', SettingsValidator::ymId((string) ($_POST['analytics_ym_id'] ?? '')));

        // --- Приватность / GDPR ---
        Setting::set('cookie_consent_enabled', !empty($_POST['cookie_consent_enabled']) ? '1' : '0');
        $privacyPageId = (int) ($_POST['privacy_policy_page_id'] ?? 0);
        Setting::set('privacy_policy_page_id', $privacyPageId > 0 ? (string) $privacyPageId : '');
        Setting::set('pii_retention_days', (string) SettingsValidator::nonNegativeInt((string) ($_POST['pii_retention_days'] ?? ''), 0));

        // Согласие на обработку персональных данных в публичных формах.
        Setting::set('form_consent_enabled', !empty($_POST['form_consent_enabled']) ? '1' : '0');
        Setting::set('form_consent_text', mb_substr(trim((string) ($_POST['form_consent_text'] ?? '')), 0, 500));

        // Капча на публичных формах (включена по умолчанию).
        Setting::set('captcha_enabled', !empty($_POST['captcha_enabled']) ? '1' : '0');

        // --- Брендинг панели управления (white-label) ---
        Setting::set('admin_brand_name', mb_substr(trim((string) ($_POST['admin_brand_name'] ?? '')), 0, 60));
        $brandLogo = ImageField::resolve('admin_brand_logo_file', 'admin_brand_logo', Setting::get('admin_brand_logo'), Auth::id());
        Setting::set('admin_brand_logo', $brandLogo ?? '');
        Setting::set('admin_brand_accent', SettingsValidator::hexColor(
            (string) ($_POST['admin_brand_accent'] ?? ''),
            \App\Core\AdminBrand::DEFAULT_ACCENT
        ));

        // --- Webpush-уведомления о новостях ---
        Setting::set('webpush_enabled', !empty($_POST['webpush_enabled']) ? '1' : '0');
        Setting::set('webpush_auto_prompt', !empty($_POST['webpush_auto_prompt']) ? '1' : '0');
        $delaySec = max(1, min(300, (int) ($_POST['webpush_prompt_delay'] ?? 15)));
        Setting::set('webpush_prompt_delay', (string) $delaySec);
        if (!empty($_POST['webpush_enabled'])) {
            \App\Core\WebPush::ensureKeys(); // пара VAPID создаётся один раз
        }

        // --- Favicon / PWA / Theme Color ---
        $favicon = ImageField::resolve('favicon_file', 'favicon_url', Setting::get('favicon_url'), Auth::id());
        Setting::set('favicon_url', $favicon ?? '');
        Setting::set('pwa_short_name', SettingsValidator::shortName((string) ($_POST['pwa_short_name'] ?? '')));
        Setting::set('theme_color', SettingsValidator::hexColor((string) ($_POST['theme_color'] ?? ''), '#1a1a1a'));

        // --- Глобальное SEO / соцсети ---
        $ogImage = ImageField::resolve('default_og_image_file', 'default_og_image', Setting::get('default_og_image'), Auth::id());
        Setting::set('default_og_image', $ogImage ?? '');

        // Режим обслуживания.
        Setting::set('maintenance_mode', !empty($_POST['maintenance_mode']) ? '1' : '0');
        Setting::set('maintenance_message', SettingsValidator::plainText((string) ($_POST['maintenance_message'] ?? ''), 500));

        // Глобальный произвольный CSS/JS вне блоков (группа 6). Доступ уже
        // ограничен супер-администратором (requireSuperAdmin выше) — хранится
        // как есть (доверенный источник), выводится на фронте один раз.
        Setting::set('custom_css_global', mb_substr((string) ($_POST['custom_css_global'] ?? ''), 0, 200000));
        Setting::set('custom_js_global', mb_substr((string) ($_POST['custom_js_global'] ?? ''), 0, 200000));
        Setting::set('footer_counters', mb_substr((string) ($_POST['footer_counters'] ?? ''), 0, 100000));

        Cache::forgetPrefix('page:');
        Flash::success('Настройки сохранены.');
        header('Location: /admin/settings');
        exit;
    }

    /** Загрузка демо-контента из настроек с явным подтверждением кода. */
    public function seedDemo(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $code = strtoupper(trim((string) ($_POST['demo_confirm_code'] ?? '')));
        if (!hash_equals(self::DEMO_CONFIRM_CODE, $code)) {
            Flash::error('Демо-контент не загружен: введите код DEMO для подтверждения.');
            header('Location: /admin/settings');
            exit;
        }

        $modules = isset($_POST['modules']) && is_array($_POST['modules'])
            ? array_values(array_filter(array_map('strval', $_POST['modules'])))
            : null;

        try {
            $c = DemoSeeder::run(Database::pdo(), $modules);
            Cache::forgetPrefix('page:');
            $added = array_sum($c);
            Flash::success($added > 0
                ? sprintf('Демо-контент загружен: новости +%d, документы +%d, проекты +%d, медиа +%d, формы +%d, вакансии +%d, тендеры +%d, мероприятия +%d, руководство +%d, страницы +%d, меню +%d.', $c['news'], $c['documenty'], $c['projects'], $c['albums'] + $c['videos'], $c['forms'], $c['vakansii'], $c['tendery'], $c['meropriyatiya'], $c['team'], $c['pages'], $c['menu'])
                : 'Демо-контент уже загружен — новых записей не добавлено.');
        } catch (\Throwable $e) {
            Flash::error('Не удалось загрузить демо-контент: ' . $e->getMessage());
        }

        header('Location: /admin/settings');
        exit;
    }

    /** Полный сброс контента и пересоздание эталонного демо-комплекта «с чистого листа». */
    public function resetDemo(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $code = strtoupper(trim((string) ($_POST['demo_confirm_code'] ?? '')));
        if (!hash_equals('RESET', $code)) {
            Flash::error('Полный сброс не выполнен: введите код RESET для подтверждения.');
            header('Location: /admin/settings');
            exit;
        }

        $modules = isset($_POST['modules']) && is_array($_POST['modules'])
            ? array_values(array_filter(array_map('strval', $_POST['modules'])))
            : null;

        try {
            $backupPath = Backup::create();
            $c = DemoSeeder::resetAndRun(Database::pdo(), $modules);
            Cache::forgetPrefix('page:');
            Flash::success(sprintf('Резервная копия %s создана. Выполнен полный сброс, загрузка и проверка нового демо-комплекта! Создано: новости %d, документы %d, проекты %d, медиа %d, формы %d, вакансии %d, тендеры %d, мероприятия %d, руководство %d, страницы %d, меню %d.', basename($backupPath), $c['news'], $c['documenty'], $c['projects'], $c['albums'] + $c['videos'], $c['forms'], $c['vakansii'], $c['tendery'], $c['meropriyatiya'], $c['team'], $c['pages'], $c['menu']));
        } catch (\Throwable $e) {
            Flash::error('Не удалось сбросить и загрузить демо-контент: ' . $e->getMessage());
        }

        header('Location: /admin/settings');
        exit;
    }

    /** Проверка отправки тестового письма по SMTP. */
    public function testMail(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $toEmail = trim((string) ($_POST['test_email'] ?? ''));
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            Flash::error('Укажите корректный e-mail адрес для отправки тестового письма.');
            header('Location: /admin/settings#smtp-section');
            exit;
        }

        $mailer = new \App\Core\Mailer();
        $ok = $mailer->send(
            $toEmail,
            'Тестовое письмо от ' . Setting::get('site_name', 'ASDR CMS'),
            '<h3>Проверка отправки почты CMS</h3><p>Здравствуйте!</p><p>Это тестовое сообщение от вашей CMS. Если вы его получили, значит настройки SMTP-сервера указаны верно и отправка почтовых уведомлений работает корректно.</p>'
        );

        if ($ok) {
            Flash::success('Тестовое письмо успешно отправлено на ' . htmlspecialchars($toEmail, ENT_QUOTES) . '. Проверьте папку «Входящие» или «Спам».');
        } else {
            $err = $mailer->lastError() ?: 'неизвестная ошибка подключения к SMTP';
            Flash::error('Ошибка отправки тестового письма: ' . $err);
        }

        header('Location: /admin/settings#smtp-section');
        exit;
    }
}
