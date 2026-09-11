<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\BlockData\BlockFieldSchema;

/**
 * Единый реестр типов блоков.
 *
 * Здесь собраны ключи типов, их дефолтные данные и названия для редактора.
 * Шаблоны обычных блоков следуют соглашению templates/blocks/{type}.php;
 * columns рендерится программно, потому отдельного шаблона у него нет.
 */
final class BlockTypeRegistry
{
    /**
     * Умолчания типов, у которых поля описаны по-старому — списком здесь,
     * полем в `block_form.php` и веткой в `BlockController::collectData()`.
     *
     * Пустой массив означает, что тип переехал на схему полей
     * (`App\Core\BlockData\BlockFieldSchema`) и умолчания берутся оттуда;
     * ключ остаётся, чтобы не менялся порядок типов в редакторе. Полный
     * список даёт `defaults()`, обращаться нужно к нему.
     *
     * @var array<string, array<string, mixed>>
     */
    public const BASE_DEFAULTS = [
        'text' => [
            'variant' => 'default',
            'title' => '',
            'content' => '',
            'aside_title' => '',
            'items' => [],
            'quote' => '',
            // Оформление акцентной цитаты. Пустой цвет и нулевой размер — это
            // «как в теме»: у блоков, собранных до появления настроек, вид не
            // меняется. Знак кавычки бывает символом (любым, из документа) или
            // значком Tabler — пресетом «крупная/мелкая» нужный кегль не
            // угадать, поэтому размер числом, в пикселях.
            'quote_bg' => '',
            'quote_color' => '',
            'quote_mark' => 'text',
            'quote_mark_text' => "\u{201c}",
            'quote_mark_icon' => '',
            'quote_mark_size' => 0,
            'quote_mark_color' => '',
            'quote_mark_position' => 'top-left',
            'media_type' => 'none',
            'media_image' => '',
            'media_video' => '',
            'media_youtube' => '',
            'media_alt' => '',
            'media_caption' => '',
            'image_position' => 'center-center',
            'image_position_mobile' => 'center-center',
        ],
        'html' => ['html' => ''],
        'cta' => [], // схема: BlockFieldSchema
        'slider' => [], // схема: BlockFieldSchema
        'form' => ['form_id' => null],
        'columns' => [], // схема: BlockFieldSchema
        // Вкладки — такой же контейнер, как columns: содержимое вкладки это
        // вложенные блоки любого типа (column_index = номер вкладки), а сам
        // блок хранит только подписи вкладок и оформление.
        'tabs' => [], // схема: BlockFieldSchema
        'testimonials' => [], // схема: BlockFieldSchema
        'counters' => [], // схема: BlockFieldSchema
        'team_list' => [], // схема: BlockFieldSchema
        'projects_list' => [], // схема: BlockFieldSchema
        'news_latest' => [], // схема: BlockFieldSchema
        'partners' => [], // схема: BlockFieldSchema
        'subscribe' => [], // схема: BlockFieldSchema
        'faq' => [], // схема: BlockFieldSchema
        'contact_cards' => [], // схема: BlockFieldSchema
        // hero_id — ссылка на обложку (тип контента «Обложки»). Когда он задан,
        // блок только размещает обложку: содержимое и настройки берутся из неё,
        // а собственные поля блока не используются. Ноль — старая обложка,
        // собранная прямо в блоке; такие страницы продолжают работать.
        'hero' => ['hero_id' => 0, 'title' => '', 'eyebrow' => '', 'subtitle' => '', 'bg_type' => 'none', 'image' => '', 'image_mobile' => '', 'image_position' => 'center-center', 'image_position_mobile' => 'center-center', 'video_url' => '', 'video_mobile' => 'poster', 'youtube_url' => '', 'bg_color' => '', 'width' => 'full', 'height' => 'regular', 'custom_height' => '720px', 'height_mobile' => '', 'custom_height_mobile' => '', 'overlay_enabled' => false, 'overlay_mode' => 'gradient', 'overlay_direction' => 'auto', 'overlay_color' => '#0b1a30', 'overlay_opacity' => 35, 'text_position' => 'left', 'text_align_y' => 'center', 'text_width' => '', 'text_color' => '', 'art_image' => '', 'art_alt' => '', 'art_position' => 'above', 'art_size' => 'medium', 'button_color' => '', 'panel_enabled' => false, 'panel_color' => '#0b1a30', 'panel_opacity' => 0, 'button_text' => '', 'button_url' => '', 'button_icon' => '', 'button_icon_image' => '', 'button2_text' => '', 'button2_url' => '', 'button2_icon' => '', 'button2_icon_image' => '', 'video_button_text' => '', 'video_button_url' => '', 'slides' => [], 'autoplay' => 0],
        'cards_grid' => [], // схема: BlockFieldSchema
        'media_gallery' => [], // схема: BlockFieldSchema
        'news_feature' => [], // схема: BlockFieldSchema
        'person_cards' => [], // схема: BlockFieldSchema
        'news_docs' => [], // схема: BlockFieldSchema
        'person_profile' => [], // схема: BlockFieldSchema
        'bio_education' => [], // схема: BlockFieldSchema
        'anchor_nav' => [], // схема: BlockFieldSchema
        'stages' => [], // схема: BlockFieldSchema
        'text_image' => [], // схема: BlockFieldSchema
        'docs_list' => [], // схема: BlockFieldSchema
        'map_point' => [], // схема: BlockFieldSchema
        'org_structure' => [], // схема: BlockFieldSchema
        'leader_card' => [], // схема: BlockFieldSchema
        'icon_text' => [], // схема: BlockFieldSchema
        'collage' => [], // схема: BlockFieldSchema
        'table' => [], // схема: BlockFieldSchema
        'image' => [], // схема: BlockFieldSchema
        'embed' => [], // схема: BlockFieldSchema
        'chart' => [], // схема: BlockFieldSchema
        'divider' => [], // схема: BlockFieldSchema
        'buttons' => [], // схема: BlockFieldSchema
    ];

    /** Короткие русские названия для сообщений редактору. */
    public const TYPE_LABELS = [
        'text' => 'Текст', 'html' => 'Произвольный HTML', 'cta' => 'Призыв к действию',
        'slider' => 'Слайдер',
        'form' => 'Форма', 'columns' => 'Колонки', 'tabs' => 'Вкладки', 'testimonials' => 'Отзывы',
        'counters' => 'Счётчики', 'team_list' => 'Команда', 'projects_list' => 'Проекты',
        'news_latest' => 'Последние новости', 'partners' => 'Партнёры',
        'subscribe' => 'Подписка', 'faq' => 'Вопросы и ответы', 'contact_cards' => 'Контакты',
        'hero' => 'Обложка',
        'cards_grid' => 'Карточки', 'media_gallery' => 'Медиагалерея',
        'news_feature' => 'Новости и аналитика', 'person_cards' => 'Карточки персон',
        'news_docs' => 'Новости и документы', 'person_profile' => 'Профиль персоны',
        'bio_education' => 'Биография и образование',
        'anchor_nav' => 'Якорная навигация', 'stages' => 'Хронология и этапы',
        'text_image' => 'Текст с фото',
        'docs_list' => 'Список документов', 'map_point' => 'Карта', 'org_structure' => 'Оргструктура',
        'leader_card' => 'Карточка руководителя',
        'icon_text' => 'Иконка и текст', 'collage' => 'Коллаж', 'table' => 'Таблица',
        'image' => 'Изображение', 'embed' => 'Внешняя врезка',
        'chart' => 'Диаграмма', 'divider' => 'Разделитель', 'buttons' => 'Кнопки',
    ];

    /**
     * Более подробные подписи только там, где список добавления блока требует
     * пояснения. Остальные берутся из TYPE_LABELS.
     */
    private const EDITOR_LABEL_OVERRIDES = [
        'cta' => 'Призыв к действию (CTA)',
        'tabs' => 'Вкладки (любые блоки внутри)',
        'team_list' => 'Список команды',
        'projects_list' => 'Список проектов',
        'partners' => 'Партнёры (логотипы)',
        'subscribe' => 'Подписка на дайджест',
        'contact_cards' => 'Контактные карточки',
        'hero' => 'Герой (титул + фото/видео)',
        'cards_grid' => 'Карточки (иконки / фото / категории)',
        'media_gallery' => 'Медиа-галерея (видео/фото)',
        'news_feature' => 'Новости и аналитика (крупная + список)',
        'person_cards' => 'Руководство (карточки персон)',
        'news_docs' => 'Новости + документы (2 колонки)',
        'person_profile' => 'Профиль руководителя',
        'bio_education' => 'Биография + образование',
        'anchor_nav' => 'Якорная навигация (вкладки)',
        // «Хронология» и «Этапы» были двумя блоками, и оба подписывались
        // словом «таймлайн» — выбрать между ними по описанию было нельзя.
        'stages' => 'Хронология / этапы (лента или список)',
        'text_image' => 'Текст + фото (о проекте)',
        'docs_list' => 'Документы (сетка)',
        'map_point' => 'Карта с меткой',
        'org_structure' => 'Структура организации (оргсхема)',
        'leader_card' => 'Карточка руководителя (с вкладками)',
        'icon_text' => 'Иконка и текст (контакты, телефоны)',
        'collage' => 'Коллаж (фото внахлёст, плитки, печать)',
        'table' => 'Таблица (строки текстом, ячейки через |)',
        'image' => 'Изображение (одно фото с подписью)',
        'embed' => 'Внешняя врезка (YouTube, Telegram, Google Формы)',
        'chart' => 'Диаграмма (столбцы, доли, показатель к цели)',
        'divider' => 'Разделитель (линия, знак или пустое место)',
        'buttons' => 'Кнопки (до трёх в ряд)',
        // Аккордеон и цитата — это FAQ и «Отзывы»: разметка, скрипт и стили у
        // них те же, и отдельные блоки-близнецы разъехались бы с ними при
        // первой правке. Названы так, чтобы редактор их нашёл по своему слову.
        'faq' => 'FAQ / аккордеон (свёрнутые разделы)',
        'testimonials' => 'Отзывы и цитаты (автор, должность, фото)',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $defaults = null;

    /**
     * Умолчания всех типов: у переехавших на схему — из неё, у остальных — из
     * BASE_DEFAULTS. Порядок типов сохраняется, он же порядок в редакторе.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaults(): array
    {
        if (self::$defaults !== null) {
            return self::$defaults;
        }

        $all = [];
        foreach (self::BASE_DEFAULTS as $type => $fields) {
            $all[$type] = BlockFieldSchema::has($type) ? BlockFieldSchema::defaults($type) : $fields;
        }

        return self::$defaults = $all;
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::BASE_DEFAULTS);
    }

    public static function has(string $type): bool
    {
        return array_key_exists($type, self::BASE_DEFAULTS);
    }

    /** @return array<string, mixed> */
    public static function defaultsFor(string $type): array
    {
        return self::defaults()[$type] ?? [];
    }

    /** @return array<string, string> */
    public static function editorLabels(): array
    {
        return array_replace(self::TYPE_LABELS, self::EDITOR_LABEL_OVERRIDES);
    }

    /**
     * Контейнеры: содержимое — вложенные блоки, а не поля формы. Шаблона у них
     * нет (рендер программный, с рекурсией), и вкладывать контейнер в контейнер
     * нельзя — иначе редактор получает дерево, которое некому показать.
     *
     * @var list<string>
     */
    public const CONTAINER_TYPES = ['columns', 'tabs'];

    public static function isContainer(string $type): bool
    {
        return in_array($type, self::CONTAINER_TYPES, true);
    }

    /**
     * Переименованные типы: ключ — как записано в старых блоках, значение —
     * нынешний тип.
     *
     * Миграция переписывает `blocks.type`, но полагаться только на неё нельзя:
     * база и код на сервере обновляются разными путями (архив релиза, ветка
     * `deploy`, `git pull`), и между ними бывает окно. Неизвестный тип
     * рендерится комментарием «Неизвестный тип блока», то есть страница
     * молча теряет секцию — а это как раз тот случай, когда лучше показать
     * содержимое по-старому. Знание о переименовании лежит здесь одно на всех
     * читателей: вывод, форма редактора и список блоков страницы.
     *
     * @var array<string, array{
     *     type: string,
     *     data: array<string, mixed>,
     *     rename: array<string, string>,
     *     values?: array<string, array<string, array<string, mixed>>>
     * }>
     */
    public const LEGACY_TYPES = [
        // «Хронология» и «Этапы» — один тип: оба показывали события во времени
        // и оба подписывались «таймлайн». Вертикальный список остался видом
        // (`layout = list`), а кнопка под ним — той же ссылкой «Все …», что у
        // ленты. Одного переименования типа тут мало: без раскладки старая
        // хронология вышла бы лентой карточек, то есть вид собранной страницы
        // поменялся бы молча — а это хуже, чем незнакомый тип.
        'timeline' => [
            'type' => 'stages',
            'data' => ['layout' => 'list'],
            'rename' => ['button_text' => 'all_text', 'button_url' => 'all_url'],
        ],
        // «Преимущества» и «Карточки» печатали одну и ту же карточку
        // (`.feature-card` с тем же нутром) из одних и тех же полей — иконка,
        // заголовок, текст, ссылка. Разошлись они только настройками: у
        // «Карточек» есть размер и фон иконки, стиль и цвета, у «Преимуществ»
        // — ряды без дыр и нумерация. Правка одного до второго не доходила:
        // подложка иконки у «Карточек» давно берёт тон акцента, а у
        // «Преимуществ» осталась синеватой — замерено.
        //
        // Вариант «в одну строку» у «Преимуществ» меняет ровно положение
        // иконки, поэтому переезжает в настройку «Положение иконки», а не во
        // второй вариант с тем же смыслом. Значение там своё: у «Карточек»
        // «слева» кладёт иконку слева от всего текста, а здесь она стоит на
        // одной линии с заголовком — замерено, вид разный.
        'advantages' => [
            'type' => 'cards_grid',
            // Номер печатался всегда и во всех вариантах, включая «Карточки»
            // без нумерации: правила, которое его прячет, в публичном CSS не
            // было вовсе. Настройка появилась включённой, чтобы вид собранных
            // страниц не менялся, а выключить её теперь можно.
            'data' => ['numbering' => true],
            'rename' => [],
            'values' => [
                'variant' => [
                    'grid' => ['variant' => 'icon'],
                    'indexed' => ['variant' => 'icon'],
                    'inline' => ['variant' => 'icon', 'icon_position' => 'inline'],
                    'band' => ['variant' => 'band'],
                ],
            ],
        ],
    ];

    /** Нынешнее имя типа: переименованный отдаёт новое, остальные — себя. */
    public static function canonicalType(string $type): string
    {
        return isset(self::LEGACY_TYPES[$type])
            ? (string) self::LEGACY_TYPES[$type]['type']
            : $type;
    }

    /**
     * Данные блока в терминах нынешнего типа: подставленная раскладка и
     * переименованные ключи. Сохранённое значение всегда сильнее умолчания —
     * блок, уже переехавший миграцией, эта функция не меняет.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function canonicalData(string $type, array $data): array
    {
        $legacy = self::LEGACY_TYPES[$type] ?? null;
        if ($legacy === null) {
            return $data;
        }

        foreach ($legacy['rename'] as $from => $to) {
            if (!array_key_exists($to, $data) && array_key_exists($from, $data)) {
                $data[$to] = $data[$from];
            }
        }

        // Значение поля тоже бывает переименовано, и не всегда один в один:
        // «в одну строку» у «Преимуществ» — это вариант «Иконка и текст» плюс
        // настройка «Положение иконки: слева». Поэтому карта отдаёт набор
        // полей, а не строку.
        $replacement = [];
        foreach ($legacy['values'] ?? [] as $field => $map) {
            $current = $data[$field] ?? null;
            if (is_string($current) && isset($map[$current])) {
                $replacement += $map[$current];
            }
        }

        return array_merge($legacy['data'], $data, $replacement);
    }

    public static function templateFile(string $type): ?string
    {
        if (!self::has($type) || self::isContainer($type)) {
            return null;
        }

        return dirname(__DIR__, 2) . '/templates/blocks/' . $type . '.php';
    }
}
