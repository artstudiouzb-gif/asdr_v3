<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Пример наполнения для нового блока.
 *
 * Пустой блок ничего не объясняет: редактор добавляет «Этапы» или «Хронологию»
 * и видит пустое место, не понимая, из чего блок состоит. Поэтому при создании
 * блок приходит с образцом — текстами-заготовками и парой строк повторителя,
 * которые остаётся заменить своими.
 *
 * Правила образцов:
 *  - только обычный текст: поля, которые шаблон экранирует, не должны получать
 *    разметку (на этом уже обожглись в готовых сборках);
 *  - кнопки — либо с рабочей ссылкой, либо без подписи: кнопка с подписью, но
 *    без адреса на сайте не выводится;
 *  - изображения не подставляем: чужие пути ведут в никуда, а поле в форме и
 *    так видно.
 */
final class BlockSamples
{
    /** Текст «замените меня» единым тоном для всех блоков. */
    private const LEAD = 'Краткое пояснение в одну-две строки — замените своим текстом.';

    /**
     * @param string|null $lang язык стека блока: ссылки образца ведут в раздел
     *                          того же языка, иначе UZ-блок ссылался бы на
     *                          русскую версию
     * @return array<string,mixed> данные образца ([] — для типа образца нет)
     */
    public static function for(string $type, ?string $lang = null): array
    {
        return self::all($lang)[$type] ?? [];
    }

    /** @return array<string, array<string,mixed>> */
    public static function all(?string $lang = null): array
    {
        $person = static fn (string $role): array => ['name' => 'Фамилия Имя Отчество', 'role' => $role, 'position' => $role, 'photo' => '', 'url' => ''];
        // Раздел новостей есть на любом сайте — это безопасный адрес для
        // кнопок образца (кнопка без ссылки на сайте не отображается).
        $news = Locale::url('news', $lang);

        return [
            'text' => ['title' => 'Заголовок раздела', 'content' => '<p>' . self::LEAD . '</p>'],
            'html' => ['html' => '<p>Здесь можно вставить произвольную безопасную разметку: таблицу, список, форму. Для карт и видео есть отдельные блоки.</p>'],
            'cta' => [
                'title' => 'Заголовок призыва к действию',
                'text' => self::LEAD,
                'button_text' => 'Перейти к новостям',
                'button_url' => $news,
            ],
            'advantages' => ['title' => 'Преимущества', 'description' => '<p>Краткое описание раздела с карточками.</p>', 'items' => [
                ['title' => 'Первое преимущество', 'text' => self::LEAD, 'icon_svg' => ''],
                ['title' => 'Второе преимущество', 'text' => self::LEAD, 'icon_svg' => ''],
                ['title' => 'Третье преимущество', 'text' => self::LEAD, 'icon_svg' => ''],
            ]],
            // Слайд без изображения не сохраняется (так устроен блок), поэтому
            // строку-образец не подставляем: она исчезла бы при первом
            // сохранении и только запутала бы.
            'slider' => ['slides' => []],
            'form' => ['form_id' => null],
            'columns' => ['columns' => 2, 'gap' => 'medium'],
            // Наполнение вкладок — вложенные блоки, их за редактора не создать:
            // образец задаёт только сами вкладки, чтобы на странице сразу
            // появились колонки с кнопкой «+ блок».
            'tabs' => ['variant' => 'segmented', 'title' => 'Раздел с вкладками', 'items' => [
                ['title' => 'Первая вкладка', 'icon' => ''],
                ['title' => 'Вторая вкладка', 'icon' => ''],
            ]],
            'testimonials' => ['title' => 'Отзывы', 'items' => [
                ['quote' => 'Короткая цитата о работе организации.', 'name' => 'Фамилия Имя', 'company' => 'Организация', 'photo' => ''],
                ['quote' => 'Вторая цитата — замените своей.', 'name' => 'Фамилия Имя', 'company' => 'Организация', 'photo' => ''],
            ]],
            'counters' => ['title' => 'В цифрах', 'items' => [
                ['value' => '120', 'suffix' => '+', 'label' => 'реализованных проектов', 'icon_svg' => ''],
                ['value' => '14', 'suffix' => '', 'label' => 'регионов охвата', 'icon_svg' => ''],
                ['value' => '35', 'suffix' => ' млрд', 'label' => 'привлечённых инвестиций', 'icon_svg' => ''],
            ]],
            // Блоки-обёртки наполняются из базы: образцу достаточно заголовка.
            // Группировка по секторам включена намеренно: на сайте, где у
            // сотрудников заполнен «Сектор», образец без неё сам вызывает
            // предупреждение редактору (якоря #team-… не создаются, и ссылки
            // со схемы оргструктуры никуда не ведут). Если секторов нет,
            // группировка ничего не меняет — выводится тот же общий список.
            'team_list' => ['title' => 'Руководящий состав', 'limit' => 0, 'group_by_department' => true],
            'projects_list' => ['title' => 'Проекты', 'limit' => 3],
            'news_latest' => ['title' => 'Последние новости', 'limit' => 3],
            'news_feature' => ['title' => 'Новости и аналитика', 'all_text' => 'Все новости', 'limit' => 6],
            'news_docs' => [
                'news_title' => 'Актуальные новости', 'news_all_text' => 'Все новости', 'limit' => 3,
                'docs_title' => 'Документы', 'docs_all_text' => '',
                'docs' => [
                    ['title' => 'Название документа', 'meta' => 'PDF', 'url' => ''],
                    ['title' => 'Второй документ', 'meta' => 'PDF', 'url' => ''],
                ],
            ],
            'partners' => ['title' => 'Партнёры', 'items' => [
                ['name' => 'Название организации', 'logo' => '', 'url' => ''],
                ['name' => 'Вторая организация', 'logo' => '', 'url' => ''],
            ]],
            'subscribe' => [
                'title' => 'Подписка на новости',
                'text' => 'Получайте дайджест новостей на почту раз в неделю.',
                'button_text' => 'Подписаться',
            ],
            'faq' => ['title' => 'Вопросы и ответы', 'items' => [
                ['question' => 'Как подать обращение?', 'answer' => 'Опишите порядок подачи и срок рассмотрения.'],
                ['question' => 'В какие сроки поступит ответ?', 'answer' => 'Укажите срок и ссылку на нормативный акт.'],
            ]],
            'contact_cards' => ['title' => 'Контакты', 'items' => [
                ['title' => 'Приёмная', 'lines' => "+998 (71) 000-00-00\nПн–Пт, 9:00–18:00", 'link_text' => '', 'link_url' => '', 'icon_svg' => ''],
                ['title' => 'Электронная почта', 'lines' => 'info@example.uz', 'link_text' => 'Написать', 'link_url' => 'mailto:info@example.uz', 'icon_svg' => ''],
            ]],
            'hero' => [
                'eyebrow' => 'Раздел сайта',
                'title' => 'Заголовок страницы',
                'subtitle' => 'Одно предложение о содержании страницы.',
                'height' => 'regular',
                'width' => 'full',
                'bg_type' => 'none',
                'text_position' => 'left',
                'overlay_enabled' => false,
                'overlay_mode' => 'gradient',
                'overlay_opacity' => 35,
            ],
            'cards_grid' => ['title' => 'Разделы', 'columns' => 3, 'items' => [
                ['title' => 'Название карточки', 'text' => self::LEAD, 'url' => $news, 'icon_svg' => ''],
                ['title' => 'Вторая карточка', 'text' => self::LEAD, 'url' => $news, 'icon_svg' => ''],
                ['title' => 'Третья карточка', 'text' => self::LEAD, 'url' => $news, 'icon_svg' => ''],
            ]],
            'media_gallery' => ['title' => 'Медиа', 'source' => 'manual', 'limit' => 8, 'items' => [
                ['kind' => 'photo', 'title' => 'Название материала', 'image' => '', 'url' => $news, 'meta' => '', 'text' => ''],
                ['kind' => 'photo', 'title' => 'Второй материал', 'image' => '', 'url' => $news, 'meta' => '', 'text' => ''],
            ]],
            'person_cards' => ['title' => 'Руководство', 'items' => [
                $person('Директор'), $person('Заместитель директора'),
            ]],
            'timeline' => ['title' => 'Хронология', 'description' => '<p>Краткое описание ключевых событий.</p>', 'items' => [
                ['year' => '2024', 'text' => 'Событие или этап — замените своим описанием.', 'status' => 'done'],
                ['year' => '2025', 'text' => 'Следующий этап.', 'status' => 'done'],
                ['year' => '2026', 'text' => 'Текущий этап.', 'status' => 'active'],
            ]],
            'icon_text' => [
                'title' => 'Полезные телефоны',
                'description' => '',
                'columns' => 3,
                'items' => [
                    ['icon_svg' => 'scale', 'icon_color' => '', 'rows' => "Телефон доверия | (71) 202-06-00"],
                    ['icon_svg' => 'id-badge', 'icon_color' => '', 'rows' => "Социальная карта | 1070\nТелефон доверия по вопросам насилия | 1146"],
                    ['icon_svg' => 'bell', 'icon_color' => '', 'rows' => "Канцелярия (для юридических лиц) | (71) 239-59-22"],
                ],
            ],
            // Коллаж: композиция «два кадра внахлёст + плитка с числом» —
            // ровно то, ради чего блок и заводился.
            'collage' => [
                'title' => '',
                'columns' => 6,
                'rows' => 4,
                'ratio' => '4-3',
                'gap' => 'medium',
                'items' => [
                    ['type' => 'photo', 'col' => 1, 'col_span' => 4, 'row' => 1, 'row_span' => 2,
                     'shape' => 'rounded', 'image' => '', 'alt' => 'Работа на объекте', 'focus' => 'center', 'link' => ''],
                    ['type' => 'stat', 'col' => 1, 'col_span' => 2, 'row' => 3, 'row_span' => 2,
                     'shape' => 'rounded', 'icon_svg' => 'users', 'value' => '25K+',
                     'label' => 'обращений рассмотрено', 'bg' => '', 'fg' => '', 'link' => ''],
                    ['type' => 'photo', 'col' => 3, 'col_span' => 4, 'row' => 2, 'row_span' => 3,
                     'shape' => 'rounded', 'image' => '', 'alt' => 'Специалист агентства', 'focus' => 'center', 'link' => ''],
                ],
            ],
            'divider' => [
                'variant' => 'short',
                'size' => 'medium',
            ],
            'buttons' => [
                'align' => 'left',
                'size' => 'normal',
                'items' => [
                    ['text' => 'Скачать бланк', 'url' => '/docs', 'style' => 'primary', 'icon_svg' => 'download', 'new_tab' => false],
                    ['text' => 'Подать заявление', 'url' => '/services', 'style' => 'outline', 'icon_svg' => '', 'new_tab' => false],
                ],
            ],
            'chart' => [
                'title' => 'Структура расходов',
                'variant' => 'stacked',
                'rows' => "Транспорт | 24\nОбразование | 18\nЗдравоохранение | 15\nЦифровизация | 12",
                'total' => 0,
                'unit' => '%',
                'caption' => 'По данным на 2026 год',
            ],
            'embed' => [
                'title' => 'Запись заседания',
                'url' => 'https://youtu.be/aqz-KE-bpKQ',
                'ratio' => '16-9',
                'height' => 600,
                'caption' => 'Трансляция коллегии Агентства',
            ],
            'image' => [
                'title' => '',
                'image' => '',
                'alt' => 'Совещание в Агентстве',
                'caption' => 'Заседание коллегии по итогам квартала',
                'credit' => 'Пресс-служба Агентства',
                'link' => '',
                'zoom' => true,
                'width' => 'container',
                'ratio' => 'auto',
            ],
            'table' => [
                'title' => 'Ключевые показатели',
                'rows' => "Показатель | 2024 | 2025\n"
                    . "Валовой продукт | 5,6 % | 6,1 %\n"
                    . "Экспорт услуг | 12,4 млрд | 14,1 млрд\n"
                    . "Доля МСБ | 54 % | 57 %",
                'header_row' => true,
                'header_col' => true,
                'variant' => 'lines',
                'density' => 'normal',
            ],
            'leader_card' => [
                'photo' => '', 'name' => 'Фамилия Имя Отчество',
                'position' => 'Должность руководителя',
                'phone' => '+998 (71) 000-00-00', 'email' => 'info@example.uz',
                'hours' => 'Пн–Пт 10:00 – 12:00',
                'facts_title' => 'Основная информация',
                'items' => [
                    ['icon_svg' => 'user', 'label' => 'Должность', 'value' => 'Должность руководителя'],
                    ['icon_svg' => 'calendar', 'label' => 'Назначен', 'value' => 'с 1 января 2024 года'],
                    ['icon_svg' => 'school', 'label' => 'Образование', 'value' => 'Название учебного заведения'],
                    ['icon_svg' => 'language', 'label' => 'Языки', 'value' => 'узбекский, русский, английский'],
                ],
                'bio_title' => 'Биография',
                'bio' => '<p>' . self::LEAD . '</p>',
                'duties_title' => 'Функции',
                'duties' => '<p>' . self::LEAD . '</p>',
            ],
            'person_profile' => [
                'photo' => '', 'name' => 'Фамилия Имя Отчество', 'position' => 'Должность',
                'text' => self::LEAD,
                'phone' => '+998 (71) 000-00-00', 'phone_label' => 'Приёмная:',
                'email' => 'info@example.uz', 'email_label' => 'E-mail:',
                'button_text' => '', 'button_url' => '',
            ],
            'bio_education' => [
                'bio_title' => 'Биография',
                'bio_text' => self::LEAD,
                'career_title' => 'Профессиональный путь',
                'career' => [
                    ['years' => '2020–2023', 'text' => 'Должность и организация.'],
                    ['years' => 'с 2023', 'text' => 'Текущая должность.'],
                ],
                'edu_title' => 'Образование',
                'edu_items' => [
                    ['years' => '2010–2015', 'org' => 'Название университета', 'title' => 'Специальность', 'text' => 'Специальность'],
                ],
                'extra_title' => '', 'extra_text' => '',
                'widgets_before' => [], 'widgets_after' => [],
                'quote_text' => '', 'quote_author' => '',
            ],
            'anchor_nav' => ['items' => [
                ['label' => 'Первый раздел', 'url' => '#sec-1'],
                ['label' => 'Второй раздел', 'url' => '#sec-2'],
            ]],
            'stages' => ['title' => 'Этапы', 'description' => '<p>Краткое описание этапов реализации.</p>', 'items' => [
                ['stage' => 'Шаг 1', 'title' => 'Подготовка', 'text' => 'Что происходит на этом этапе.', 'year' => '', 'status' => 'done', 'status_text' => 'Завершён'],
                ['stage' => 'Шаг 2', 'title' => 'Реализация', 'text' => 'Что происходит на этом этапе.', 'year' => '', 'status' => 'active', 'status_text' => 'В работе'],
                ['stage' => 'Шаг 3', 'title' => 'Результат', 'text' => 'Что происходит на этом этапе.', 'year' => '', 'status' => '', 'status_text' => 'Запланирован'],
            ]],
            'text_image' => [
                'title' => 'Заголовок раздела',
                'text' => self::LEAD . "\n\nВторой абзац отделяется пустой строкой.",
                'image' => '',
                'items' => [
                    ['label' => 'Короткий факт', 'icon_svg' => ''],
                    ['label' => 'Второй факт', 'icon_svg' => ''],
                ],
            ],
            'docs_list' => ['title' => 'Документы', 'columns' => 3, 'items' => [
                ['title' => 'Название документа', 'meta' => 'PDF', 'url' => ''],
                ['title' => 'Второй документ', 'meta' => 'PDF', 'url' => ''],
                ['title' => 'Третий документ', 'meta' => 'DOCX', 'url' => ''],
            ]],
            'map_point' => [
                'title' => 'Как добраться',
                'card_title' => 'Главный офис',
                'address' => 'г. Ташкент, ул. Примерная, 1',
                'image' => '', 'embed_url' => '',
                'button_text' => '', 'button_url' => '',
            ],
            'org_structure' => [
                'title' => 'Организационная структура',
                'layout' => 'tree',
                'columns' => 4,
                'council' => 'Координационный совет',
                'head_title' => 'Директор',
                'head_name' => '',
                'head_url' => '',
                'side_items' => 'Советник',
                'branches' => [
                    ['title' => 'Первый заместитель директора', 'name' => '', 'units' => "Отдел планирования\nОтдел анализа\n* Проектные офисы по развитию отраслей"],
                    ['title' => 'Заместитель директора', 'name' => '', 'units' => "Юридический отдел\nОтдел кадров\n- группа по работе с кадрами"],
                ],
                'collapsible' => false,
                'notes' => '',
                'footnote' => '',
            ],
        ];
    }
}
