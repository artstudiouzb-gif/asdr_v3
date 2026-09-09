<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Goal;
use App\Models\News;
use App\Models\Project;
use App\Models\Setting;
use App\Models\TeamMember;
use App\Models\Widget;

/**
 * Модульный рендер виджетов сайдбара, по аналогии с BlockRenderer.
 * Шаблон каждого типа лежит в templates/widgets/{type}.php и подключается
 * изолированно (собственная область видимости переменных). Каждый виджет
 * оборачивается в <aside id="widget-{id}"> для изоляции.
 */
final class WidgetRenderer
{
    private const DEFAULTS = [
        'latest_news' => ['count' => 5],
        'contacts' => ['show_socials' => true],
        'custom_html' => ['html' => ''],
        'projects_list' => ['count' => 5],
        'team_list' => ['count' => 5],
        'subscribe' => ['text' => 'Eng muhim yangiliklar va tahliliy materiallarni pochtangizga oling.'],
        // Карусель фотографий без подписей и ссылок. Порядок по умолчанию
        // случайный — виджет для того и заводился, чтобы показывать набор
        // снимков вперемешку.
        'photo_slider' => [
            'source' => 'manual', 'slides' => [], 'shuffle' => true, 'autoplay' => 0, 'ratio' => '16-9',
            // Заполняются только источником «случайная цель»: у своих снимков
            // текста нет, виджет заводился как витрина кадров.
            'goal_name' => '', 'goal_description' => '',
        ],
        // Настроек нет: раздел определяется по открытой странице, а показывать
        // или нет — решает размещение виджета в колонке.
        'section_menu' => [],
    ];

    /**
     * Откуда карусель берёт кадры.
     *
     * `manual` — снимки лежат в самом виджете. `goals` — виджет показывает
     * одну случайную «Цель»: целей сотни, в JSON виджета они не помещаются, и
     * набор кадров у каждой свой.
     */
    public const SLIDER_SOURCES = [
        'manual' => 'Свои фотографии',
        'goals' => 'Случайная цель',
    ];


    /** Панель оформления виджета (хранится в data._design). */
    public const DESIGN_STYLES = ['default', 'card', 'tinted', 'navy'];
    public const DESIGN_PADS = ['compact', 'normal', 'spacious'];

    /**
     * Нормализует настройки оформления из data._design.
     * @return array{style:string, pad:string, accent:bool}
     */
    public static function normalizeDesign(array $data): array
    {
        $d = is_array($data['_design'] ?? null) ? $data['_design'] : [];

        return [
            'style' => in_array($d['style'] ?? '', self::DESIGN_STYLES, true) ? (string) $d['style'] : 'default',
            'pad' => in_array($d['pad'] ?? '', self::DESIGN_PADS, true) ? (string) $d['pad'] : 'normal',
            'accent' => !empty($d['accent']),
        ];
    }

    /**
     * Колонка виджетов для выбранного макета страницы/новости. Всё, кроме
     * left_sidebar/right_sidebar, означает «без виджетов». Inline-скрипты
     * виджета «Произвольный HTML» получают nonce — без него CSP их не
     * выполнит, и баннер молча ничего не делает.
     *
     * @return array{position:string, html:string}|null
     */
    public static function sidebarFor(?string $layout, string $lang): ?array
    {
        $position = match ($layout) {
            'left_sidebar' => 'left',
            'right_sidebar' => 'right',
            default => null,
        };
        if ($position === null) {
            return null;
        }

        return [
            'position' => $position,
            'html' => SecurityHeaders::injectScriptNonce(Widget::renderSidebar($position, $lang)),
        ];
    }

    /**
     * Рендер выбранных виджетов внутри блока страницы. Nonce добавляется
     * здесь же, поэтому custom_html сохраняет совместимость с CSP.
     *
     * @param array<int, mixed> $ids
     */
    public static function renderSelection(array $ids, string $lang): string
    {
        $html = '';
        foreach (Widget::activeByIds($ids, $lang) as $widget) {
            $html .= self::render($widget, $lang);
        }

        return SecurityHeaders::injectScriptNonce($html);
    }

    public static function resolveTitle(array $widget, string $lang): string
    {
        $data = json_decode((string) ($widget['data'] ?? '{}'), true);
        if (is_array($data) && !empty($data['titles'][$lang])) {
            return trim((string) $data['titles'][$lang]);
        }

        $rawTitle = trim((string) ($widget['title'] ?? ''));
        if ($rawTitle === '') {
            return '';
        }

        $translated = t($rawTitle, $lang);
        if ($translated !== $rawTitle) {
            return $translated;
        }

        return $rawTitle;
    }

    public static function render(array $widget, string $lang): string
    {
        $type = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $widget['type'])) ?? '';
        $widgetId = (int) $widget['id'];

        $data = json_decode((string) ($widget['data'] ?? '{}'), true);
        if (!is_array($data)) {
            $data = [];
        }
        // Смердживание с дефолтами: старые/неполные JSON не ломают шаблон.
        $data = array_merge(self::DEFAULTS[$type] ?? [], $data);

        $view = self::buildViewData($type, $data, $lang);

        $templateFile = dirname(__DIR__, 2) . '/templates/widgets/' . $type . '.php';
        if (!is_file($templateFile)) {
            return '';
        }

        $inner = self::renderTemplate($templateFile, $view, $lang);
        // Виджет, которому нечего показать, не выводится вовсе: рамка с одним
        // заголовком читается как поломка. Так ведёт себя «Меню раздела» на
        // странице вне разделов.
        if (trim($inner) === '') {
            return '';
        }
        $title = self::resolveTitle($widget, $lang);

        $design = self::normalizeDesign($data);
        $classes = 'widget widget--' . $type;
        if ($design['style'] !== 'default') {
            $classes .= ' widget--style-' . $design['style'];
        }
        if ($design['pad'] !== 'normal') {
            $classes .= ' widget--pad-' . $design['pad'];
        }
        if ($design['accent']) {
            $classes .= ' widget--accent';
        }

        return sprintf(
            '<aside id="widget-%d" class="%s">%s%s</aside>',
            $widgetId,
            htmlspecialchars($classes, ENT_QUOTES),
            $title !== '' ? '<h3 class="widget__title">' . htmlspecialchars($title, ENT_QUOTES) . '</h3>' : '',
            $inner
        );
    }

    private static function buildViewData(string $type, array $data, string $lang): array
    {
        switch ($type) {
            case 'latest_news':
                $data['items'] = News::published((int) ($data['count'] ?? 5), 0, $lang);
                break;
            case 'projects_list':
                $data['items'] = array_slice(Project::published($lang), 0, (int) ($data['count'] ?? 5));
                break;
            case 'team_list':
                $data['items'] = array_slice(TeamMember::published($lang), 0, (int) ($data['count'] ?? 5));
                break;
            case 'photo_slider':
                // Случайная цель выбирается на сервере только ради запасного
                // варианта: этот кадр уедет в кэш страницы и застынет для всех.
                // Свежую цель у каждого посетителя запрашивает скрипт (см.
                // /goals/random) — иначе «случайная» была бы случайной ровно
                // один раз, до сброса кэша.
                if (($data['source'] ?? 'manual') === 'goals') {
                    $random = Goal::random($lang);
                    $data['slides'] = $random === null ? [] : array_map(
                        static fn (array $img): array => [
                            'image' => (string) $img['image'],
                            'alt' => (string) $img['alt'],
                        ],
                        $random['images']
                    );
                    // Название и описание цели идут над каруселью: набор снимков
                    // без единого слова не сообщает, что за объект показан.
                    $data['goal_name'] = $random === null ? '' : (string) ($random['goal']['name'] ?? '');
                    $data['goal_description'] = $random === null ? '' : (string) ($random['goal']['description'] ?? '');
                    // Порядок кадров внутри цели — авторский: случайной бывает
                    // сама цель, а не история, которую рассказывают её слайды.
                    $data['shuffle'] = false;
                }
                break;
            case 'section_menu':
                // Ветка считается по адресу текущей страницы. Кэш блоков этому
                // не мешает: его ключ и так включает страницу.
                $data['branch'] = SectionMenu::branch($lang, SectionMenu::currentPath());
                break;
            case 'contacts':
                $data['phone'] = Setting::get('contact_phone');
                $data['email'] = Setting::get('contact_email');
                $data['address'] = Setting::get('contact_address');
                $data['social'] = HeaderConfig::get()['social_buttons'];
                break;
        }

        return $data;
    }

    private static function renderTemplate(string $file, array $data, string $lang): string
    {
        $render = static function (string $__file, array $data, string $lang): void {
            extract(['data' => $data, 'lang' => $lang], EXTR_SKIP);
            require $__file;
        };

        ob_start();
        $render($file, $data, $lang);

        return (string) ob_get_clean();
    }
}
