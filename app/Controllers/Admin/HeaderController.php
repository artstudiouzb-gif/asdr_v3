<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\HeaderConfig;
use App\Core\ImageField;
use App\Core\View;

final class HeaderController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();
        View::render('admin/header/index', ['config' => HeaderConfig::get()]);
    }

    public function update(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $social = [];
        foreach ((array) ($_POST['social'] ?? []) as $btn) {
            $social[] = [
                'network' => trim((string) ($btn['network'] ?? '')),
                'url' => trim((string) ($btn['url'] ?? '')),
            ];
        }

        // Конструктор: порядок элементов по зонам (скрытые поля с CSV,
        // заполняемые drag-and-drop в админке) — отдельно для десктопа и мобильного.
        $parseZones = static function (string $key): array {
            $out = [];
            foreach (['left', 'center', 'right'] as $zone) {
                $raw = (string) ($_POST[$key][$zone] ?? '');
                $out[$zone] = array_values(array_filter(array_map('trim', explode(',', $raw))));
            }
            return $out;
        };

        // Светлый логотип: URL из поля, загрузка файла или ранее сохранённое.
        $current = HeaderConfig::get();
        $logoLight = ImageField::resolve(
            'logo_light_file',
            'logo_light',
            (string) ($current['logo_light'] ?? ''),
            Auth::id()
        );

        // Логотипы для каждого языка (основной + светлый), по активным языкам.
        $logoByLang = [];
        $logoLightByLang = [];
        foreach (\App\Models\Language::active() as $lng) {
            $code = (string) $lng['code'];
            $main = ImageField::resolve(
                'logo_lang_' . $code . '_file',
                'logo_lang_' . $code,
                (string) ($current['logo_by_lang'][$code] ?? ''),
                Auth::id()
            );
            if (trim((string) $main) !== '') {
                $logoByLang[$code] = trim((string) $main);
            }
            $light = ImageField::resolve(
                'logo_light_lang_' . $code . '_file',
                'logo_light_lang_' . $code,
                (string) ($current['logo_light_by_lang'][$code] ?? ''),
                Auth::id()
            );
            if (trim((string) $light) !== '') {
                $logoLightByLang[$code] = trim((string) $light);
            }
        }

        HeaderConfig::save([
            'logo_position' => $_POST['logo_position'] ?? 'left',
            'logo_width' => $_POST['logo_width'] ?? HeaderConfig::DEFAULTS['logo_width'],
            'logo_height' => $_POST['logo_height'] ?? HeaderConfig::DEFAULTS['logo_height'],
            'menu_position' => $_POST['menu_position'] ?? HeaderConfig::DEFAULTS['menu_position'],
            'sticky' => !empty($_POST['header_sticky']),
            'sticky_full_width' => !empty($_POST['header_sticky_full_width']),
            'transparent' => !empty($_POST['header_transparent']),
            'logo_light' => $logoLight ?? '',
            'logo_by_lang' => $logoByLang,
            'logo_light_by_lang' => $logoLightByLang,
            'elements' => $parseZones('elements'),
            'elements_mobile' => $parseZones('elements_mobile'),
            'language_switcher' => [
                'enabled' => !empty($_POST['ls_enabled']),
                'format' => $_POST['ls_format'] ?? 'code',
                'style' => $_POST['ls_style'] ?? 'dropdown',
                'always_show' => !empty($_POST['ls_always_show']),
            ],
            'social_buttons' => $social,
            'social_style' => $_POST['social_style'] ?? 'monochrome',
            'cta' => [
                'enabled' => !empty($_POST['cta_enabled']),
                'text' => $_POST['cta_text'] ?? '',
                'url' => $_POST['cta_url'] ?? '',
                'style' => $_POST['cta_style'] ?? 'filled',
                'icon' => $_POST['cta_icon'] ?? 'none',
            ],
            'search' => [
                'style' => $_POST['search_style'] ?? 'inline',
                'placeholder' => $_POST['search_placeholder'] ?? 'Поиск по сайту...',
            ],
            // Pro Max: секции top/bottom, контакты и сниппет.
            'topbar' => [
                'enabled' => !empty($_POST['topbar_enabled']),
                'style' => $_POST['topbar_style'] ?? 'navy',
                'show_mobile' => !empty($_POST['topbar_mobile']),
                'show_border' => !empty($_POST['topbar_border']),
                'height' => $_POST['topbar_height'] ?? 'normal',
                'zones' => $parseZones('topbar_zones'),
            ],
            // Свой фон секции применяется только при включённом чекбоксе
            // «Свой фон» (input type=color всегда присылает значение).
            'middlebar' => [
                'height' => $_POST['middlebar_height'] ?? 'normal',
                'bg' => !empty($_POST['middlebar_bg_use']) ? (string) ($_POST['middlebar_bg'] ?? '') : '',
            ],
            'bottombar' => [
                'height' => $_POST['bottombar_height'] ?? 'normal',
                'bg' => !empty($_POST['bottombar_bg_use']) ? (string) ($_POST['bottombar_bg'] ?? '') : '',
                'zones' => $parseZones('bottombar_zones'),
            ],
            'shadow' => [
                'enabled' => !empty($_POST['header_shadow']),
                'size' => $_POST['header_shadow_size'] ?? HeaderConfig::DEFAULTS['shadow']['size'],
            ],
            'borders' => $_POST['borders'] ?? 'full',
            'container_mode' => $_POST['container_mode'] ?? 'full',
            'contacts' => [
                'phone' => $_POST['contact_phone'] ?? '',
                'email' => $_POST['contact_email'] ?? '',
            ],
            'snippet' => (string) ($_POST['snippet'] ?? ''),
            'styles' => [
                'nav_color' => !empty($_POST['styles_nav_color_use']) ? (string) ($_POST['styles_nav_color'] ?? '') : '',
                'nav_hover' => !empty($_POST['styles_nav_hover_use']) ? (string) ($_POST['styles_nav_hover'] ?? '') : '',
                'nav_active' => !empty($_POST['styles_nav_active_use']) ? (string) ($_POST['styles_nav_active'] ?? '') : '',
                'nav_color_transparent' => !empty($_POST['styles_nav_color_transparent_use']) ? (string) ($_POST['styles_nav_color_transparent'] ?? '') : '',
                'nav_hover_transparent' => !empty($_POST['styles_nav_hover_transparent_use']) ? (string) ($_POST['styles_nav_hover_transparent'] ?? '') : '',
                'nav_active_transparent' => !empty($_POST['styles_nav_active_transparent_use']) ? (string) ($_POST['styles_nav_active_transparent'] ?? '') : '',
                'nav_pill_bg' => !empty($_POST['styles_nav_pill_bg_use']) ? (string) ($_POST['styles_nav_pill_bg'] ?? '') : '',
                'nav_font_size' => (string) ($_POST['styles_nav_font_size'] ?? 'normal'),
                'nav_transform' => (string) ($_POST['styles_nav_transform'] ?? 'uppercase'),
                'nav_letter_spacing' => (string) ($_POST['styles_nav_letter_spacing'] ?? 'normal'),
                'nav_style_type' => (string) ($_POST['styles_nav_style_type'] ?? 'underline'),
                'nav_padding' => (string) ($_POST['styles_nav_padding'] ?? 'normal'),
                'nav_icon_pos' => (string) ($_POST['styles_nav_icon_pos'] ?? 'left'),
                'nav_gap' => (string) ($_POST['styles_nav_gap'] ?? '18'),
                'nav_overflow' => (string) ($_POST['styles_nav_overflow'] ?? 'adaptive'),
                'nav_item_dividers' => !empty($_POST['styles_nav_item_dividers']),
                'nav_divider_color' => !empty($_POST['styles_nav_divider_color_use'])
                    ? (string) ($_POST['styles_nav_divider_color'] ?? '') : '',
                'nav_divider_color_transparent' => !empty($_POST['styles_nav_divider_color_transparent_use'])
                    ? (string) ($_POST['styles_nav_divider_color_transparent'] ?? '') : '',
                'nav_divider_width' => (string) ($_POST['styles_nav_divider_width'] ?? '1'),
                'nav_divider_height' => (string) ($_POST['styles_nav_divider_height'] ?? '18'),
                'hoverline_length' => (string) ($_POST['styles_hoverline_length'] ?? 'normal'),
                'hoverline_offset' => (string) ($_POST['styles_hoverline_offset'] ?? 'normal'),
                'hoverline_thickness' => (string) ($_POST['styles_hoverline_thickness'] ?? '2px'),
                'submenu_style' => (string) ($_POST['styles_submenu_style'] ?? 'lines'),
                'submenu_width' => (string) ($_POST['styles_submenu_width'] ?? 'normal'),
                'submenu_font_size' => (string) ($_POST['styles_submenu_font_size'] ?? '13.8'),
                'submenu_padding' => (string) ($_POST['styles_submenu_padding'] ?? 'normal'),
                'submenu_transform' => (string) ($_POST['styles_submenu_transform'] ?? 'none'),
                'submenu_radius' => (string) ($_POST['styles_submenu_radius'] ?? 'soft'),
                'submenu_shadow' => (string) ($_POST['styles_submenu_shadow'] ?? 'soft'),
                'submenu_divider' => (string) ($_POST['styles_submenu_divider'] ?? 'subtle'),
                'submenu_bg' => !empty($_POST['styles_submenu_bg_use']) ? (string) ($_POST['styles_submenu_bg'] ?? '') : '',
                'submenu_color' => !empty($_POST['styles_submenu_color_use']) ? (string) ($_POST['styles_submenu_color'] ?? '') : '',
                'submenu_hover' => !empty($_POST['styles_submenu_hover_use']) ? (string) ($_POST['styles_submenu_hover'] ?? '') : '',
                'submenu_divider_color' => !empty($_POST['styles_submenu_divider_color_use']) ? (string) ($_POST['styles_submenu_divider_color'] ?? '') : '',
                'topbar_bg' => !empty($_POST['styles_topbar_bg_use']) ? (string) ($_POST['styles_topbar_bg'] ?? '') : '',
                'topbar_text' => !empty($_POST['styles_topbar_text_use']) ? (string) ($_POST['styles_topbar_text'] ?? '') : '',
                'border_color' => !empty($_POST['styles_border_color_use']) ? (string) ($_POST['styles_border_color'] ?? '') : '',
                'border_width' => (string) ($_POST['styles_border_width'] ?? ''),
                'elements_gap' => (string) ($_POST['styles_elements_gap'] ?? 'normal'),
                'floating_radius' => (int) ($_POST['styles_floating_radius'] ?? 18),
                'floating_opacity' => (int) ($_POST['styles_floating_opacity'] ?? 25),
                'floating_gradient_angle' => (int) ($_POST['styles_floating_gradient_angle'] ?? 135),
                'floating_blur' => (int) ($_POST['styles_floating_blur'] ?? 14),
            ],
        ]);

        Flash::success('Настройки шапки сохранены.');
        header('Location: /admin/header');
        exit;
    }
}
