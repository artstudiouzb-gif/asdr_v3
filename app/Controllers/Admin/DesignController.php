<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\DesignSettings;
use App\Core\Flash;
use App\Core\LocalGoogleFonts;
use App\Core\View;
use App\Models\Setting;

/**
 * Управление дизайном сайта: готовые конфигурации + точная настройка
 * (визуальные опции карточками, как в тема-билдере). Только супер-админ.
 */
final class DesignController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();
        View::render('admin/design/index', [
            'options' => DesignSettings::OPTIONS,
            // Встроенные конфигурации в интерфейс не выводим (см. вью):
            // остались только сохранённые администратором.
            'userPresets' => DesignSettings::userPresets(),
            'values' => DesignSettings::current(),
            'activePreset' => Setting::get('design_preset', ''),
        ]);
    }

    /**
     * Живое превью: рендерит главную страницу сайта с «примеренными»
     * значениями дизайна из query-строки, ничего не сохраняя. Открывается
     * в iframe панели «Дизайн»; доступно только супер-администратору.
     */
    public function preview(): void
    {
        Auth::requireSuperAdmin();

        $preview = $_GET;
        if (array_key_exists('font_body_choice', $preview)) {
            $preview = array_merge(
                $preview,
                DesignSettings::normalizeBodyFontChoice((string) $preview['font_body_choice'])
            );
        }

        // Примеряем каждую валидную опцию поверх сохранённых значений.
        foreach (DesignSettings::OPTIONS as $key => $opt) {
            if (!isset($preview[$key])) {
                continue;
            }
            $val = DesignSettings::sanitize($key, (string) $preview[$key]);
            if ($val !== null) {
                Setting::overrideInMemory('design_' . $key, $val);
            }
        }
        if (isset($preview['container_custom'])) {
            Setting::overrideInMemory(
                'design_container_custom',
                DesignSettings::normalizeWidth((string) $preview['container_custom'])
            );
        }
        if (array_key_exists('font_size_custom', $preview)) {
            Setting::overrideInMemory(
                'design_font_size_custom',
                DesignSettings::normalizeFontSize((string) $preview['font_size_custom'])
            );
        }
        if (array_key_exists('radius_custom', $preview)) {
            Setting::overrideInMemory(
                'design_radius_custom',
                DesignSettings::normalizeRadius((string) $preview['radius_custom'])
            );
        }
        if (array_key_exists('card_hover_lift', $preview)) {
            Setting::overrideInMemory(
                'design_card_hover_lift',
                (string) DesignSettings::normalizeCardHoverLift((string) $preview['card_hover_lift'])
            );
        }
        foreach (['top', 'bottom'] as $edge) {
            $key = 'newsdetail_padding_' . $edge;
            if (array_key_exists($key, $preview)) {
                Setting::overrideInMemory(
                    'design_' . $key,
                    DesignSettings::normalizeNewsDetailSpacing((string) $preview[$key])
                );
            }
        }
        if (array_key_exists('line_height_custom', $preview)) {
            Setting::overrideInMemory(
                'design_line_height_custom',
                DesignSettings::normalizeLineHeight((string) $preview['line_height_custom'])
            );
        }
        if (array_key_exists('heading_line_height_custom', $preview)) {
            Setting::overrideInMemory(
                'design_heading_line_height_custom',
                DesignSettings::normalizeLineHeight((string) $preview['heading_line_height_custom'])
            );
        }
        if (array_key_exists('meta_letter_spacing_custom', $preview)) {
            Setting::overrideInMemory(
                'design_meta_letter_spacing_custom',
                DesignSettings::normalizeMetaLetterSpacing((string) $preview['meta_letter_spacing_custom'])
            );
        }
        if (array_key_exists('typo_scale', $preview)) {
            $scale = (string) $preview['typo_scale'];
            Setting::overrideInMemory(
                'design_typo_scale',
                isset(DesignSettings::TYPO_SCALES[$scale]) ? $scale : 'classic'
            );
        }
        foreach (array_keys(DesignSettings::TYPO_SIZES) as $fsKey) {
            if (array_key_exists($fsKey, $preview)) {
                Setting::overrideInMemory(
                    'design_' . $fsKey,
                    DesignSettings::normalizeFsSize((string) $preview[$fsKey])
                );
            }
        }

        // Ручные значения превью берутся из той же формы, что и пресеты.
        // Они применяются только для соответствующих вариантов «Свои…».
        $customPrimary = \App\Core\SettingsValidator::hexColor(
            (string) ($preview['color_primary'] ?? ''),
            (string) Setting::get('color_primary', '#155182')
        );
        $customAccent = \App\Core\SettingsValidator::hexColor(
            (string) ($preview['color_accent'] ?? ''),
            (string) Setting::get('color_accent', '#00A0A6')
        );
        foreach (DesignSettings::semanticColors() as $key => $current) {
            if (array_key_exists($key, $preview)) {
                Setting::overrideInMemory(
                    'design_semantic_' . $key,
                    \App\Core\SettingsValidator::hexColor((string) $preview[$key], $current)
                );
            }
        }
        if (array_key_exists('shadow_color', $preview)) {
            Setting::overrideInMemory('design_shadow_color', \App\Core\SettingsValidator::hexColor(
                (string) $preview['shadow_color'],
                DesignSettings::shadowColor()
            ));
        }
        if (array_key_exists('shadow_strength', $preview)) {
            $previewStrength = (string) $preview['shadow_strength'];
            if (is_numeric($previewStrength)) {
                Setting::overrideInMemory(
                    'design_shadow_strength',
                    (string) max(0, min(300, (int) round((float) $previewStrength)))
                );
            }
        }
        foreach (DesignSettings::semanticSpacings() as $key => $current) {
            if (array_key_exists($key, $preview)) {
                Setting::overrideInMemory(
                    'design_spacing_' . $key,
                    \App\Core\SettingsValidator::safeCssValue((string) $preview[$key], $current)
                );
            }
        }

        // Палитра/шрифт материализуются тоже только в памяти.
        $palette = Setting::get('design_palette', 'custom');
        if ($palette !== 'custom' && isset(DesignSettings::PALETTES[$palette])) {
            Setting::overrideInMemory('color_primary', DesignSettings::PALETTES[$palette][1]);
            Setting::overrideInMemory('color_accent', DesignSettings::PALETTES[$palette][2]);
        } else {
            Setting::overrideInMemory('color_primary', $customPrimary);
            Setting::overrideInMemory('color_accent', $customAccent);
        }
        $font = DesignSettings::fontStyleKey((string) Setting::get('design_font_style', 'custom'));
        if ($font !== 'custom' && isset(DesignSettings::FONTS[$font])) {
            Setting::overrideInMemory('font_family', DesignSettings::FONTS[$font][1]);
        } elseif (trim((string) ($preview['font_family'] ?? '')) !== '') {
            Setting::overrideInMemory('font_family', mb_substr(trim((string) $preview['font_family']), 0, 200));
        }

        foreach (['body' => 'font_family', 'heading' => 'font_heading'] as $role => $target) {
            $slug = (string) ($preview['font_google_' . $role] ?? '');
            if ($slug !== '' && isset(DesignSettings::GOOGLE_FONTS[$slug])) {
                Setting::overrideInMemory('design_font_google_' . $role, $slug);
                Setting::overrideInMemory($target, DesignSettings::GOOGLE_FONTS[$slug][1]);
            } elseif (array_key_exists('font_google_' . $role, $preview)) {
                Setting::overrideInMemory('design_font_google_' . $role, '');
                if ($role === 'heading') {
                    $bodyFont = (string) Setting::get('font_family', \App\Core\SiteThemeCss::DEFAULT_BODY_FONT);
                    Setting::overrideInMemory('font_heading', $bodyFont);
                }
            }
        }

        if (array_key_exists('font_face_name', $preview)) {
            $face = preg_replace('/[^a-zA-Z0-9 _-]/', '', trim((string) $preview['font_face_name'])) ?? '';
            Setting::overrideInMemory('font_face_name', mb_substr($face, 0, 80));
        }
        if (array_key_exists('font_url', $preview)) {
            $url = mb_substr(trim((string) $preview['font_url']), 0, 500);
            Setting::overrideInMemory('font_url', $url === '' || \App\Core\UrlGuard::isSafeLink($url) ? $url : '');
        }
        if (isset($preview['default_theme']) && in_array($preview['default_theme'], ['light', 'dark', 'auto'], true)) {
            Setting::overrideInMemory('default_theme', (string) $preview['default_theme']);
        }

        (new \App\Controllers\Site\PageController())->home();
    }

    /** Сохранить текущие настройки как собственную конфигурацию. */
    public function savePreset(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $slug = DesignSettings::saveUserPreset((string) ($_POST['name'] ?? ''));
        if ($slug === null) {
            Flash::error('Не удалось сохранить: укажите название (до 40 символов); максимум 10 конфигураций.');
        } else {
            Flash::success('Конфигурация сохранена. Теперь её можно применить в один клик.');
        }
        header('Location: /admin/design');
        exit;
    }

    /** Удалить собственную конфигурацию. */
    public function deletePreset(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        if (DesignSettings::deleteUserPreset((string) ($_POST['slug'] ?? ''))) {
            Flash::success('Конфигурация удалена.');
        } else {
            Flash::error('Конфигурация не найдена.');
        }
        header('Location: /admin/design');
        exit;
    }

    public function update(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $selectedFonts = [];
        $bodyChoice = (string) ($_POST['font_body_choice'] ?? '');
        if (str_starts_with($bodyChoice, 'google:')) {
            $selectedFonts[] = substr($bodyChoice, 7);
        }
        $selectedFonts[] = (string) ($_POST['font_google_heading'] ?? '');
        $selectedFonts[] = (string) ($_POST['font_script'] ?? '');
        $fontInstall = LocalGoogleFonts::installSelected($selectedFonts);
        if (!$fontInstall['ok']) {
            Flash::error($fontInstall['error'] . ' Настройки не изменены.');
            header('Location: /admin/design');
            exit;
        }

        $warnings = DesignSettings::save($_POST);
        // Ручная правка снимает метку пресета (значения могли разойтись).
        Setting::set('design_preset', '');
        Cache::forgetPrefix('page:');
        Flash::success('Настройки дизайна сохранены.');
        foreach ($warnings as $warning) {
            Flash::error($warning);
        }
        header('Location: /admin/design');
        exit;
    }

    public function applyPreset(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $preset = (string) ($_POST['preset'] ?? '');
        // Встроенные наборы отключены: применять можно только сохранённые
        // конфигурации. Проверка защищает и от устаревшей открытой вкладки.
        if (!str_starts_with($preset, 'user:')) {
            Flash::error('Готовые конфигурации отключены — применять можно только сохранённые вами наборы.');
            header('Location: /admin/design');
            exit;
        }

        $presetSlug = substr($preset, 5);
        $presetData = DesignSettings::userPresets()[$presetSlug] ?? null;
        if (is_array($presetData)) {
            $appearance = (array) ($presetData['appearance'] ?? []);
            $fontInstall = LocalGoogleFonts::installSelected([
                (string) ($appearance['font_google_body'] ?? ''),
                (string) ($appearance['font_google_heading'] ?? ''),
                (string) ($appearance['font_script'] ?? ''),
            ]);
            if (!$fontInstall['ok']) {
                Flash::error($fontInstall['error'] . ' Конфигурация не применена.');
                header('Location: /admin/design');
                exit;
            }
        }

        // Применение переписывает все настройки разом, поэтому сначала
        // откладываем текущее состояние — как автокопию страницы перед заменой.
        $backup = DesignSettings::autoBackupPreset();

        if (DesignSettings::applyPreset($preset)) {
            Cache::forgetPrefix('page:');
            $label = DesignSettings::userPresets()[substr($preset, 5)]['label'] ?? $preset;
            Flash::success('Конфигурация «' . $label . '» применена.');
            if ($backup !== null) {
                Flash::success('Прежние настройки сохранены как «' . $backup . '» — примените её, чтобы вернуть как было.');
            }
        } else {
            Flash::error('Неизвестная конфигурация.');
        }
        header('Location: /admin/design');
        exit;
    }
}
