<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Готовые сборки страниц: набор блоков с уже расставленными отступами, фонами
 * и текстами-рыбой, который редактор применяет одной кнопкой и дальше просто
 * заменяет содержимое.
 *
 * Формат совпадает со снимком BlockSnippet::captureFromPage(), поэтому
 * применение идёт через BlockSnippet::applyToPage() — своего кода вставки нет.
 *
 * ПРАВИЛА РИТМА (почему у блоков именно такие _bg/_spacing):
 *  - Фоны чередуются: none → light/tint → none. Две подряд подложки читаются
 *    как одна большая секция и ломают ритм страницы.
 *  - Тёмная секция (navy) на странице одна и стоит последней — это призыв к
 *    действию, а не рядовой блок.
 *  - _fullwidth ставится только вместе с фоном: без подложки растягивать
 *    нечего, а контент всё равно остаётся в контейнере.
 *  - Отступы: hero — max, смысловые секции — premium, вспомогательные
 *    (контакты, документы под текстом) — small.
 *  - Анимация появления — только у карточных секций ниже первого экрана;
 *    hero анимировать нельзя, он виден сразу и мигал бы при загрузке.
 */
final class PagePresets
{
    /** Оформление секции: фон + отступы + анимация одной строкой. */
    private static function look(string $bg = 'none', string $spacing = 'premium', string $reveal = ''): array
    {
        return [
            '_bg' => $bg,
            '_fullwidth' => $bg !== 'none',
            '_spacing' => $spacing,
            '_reveal' => $reveal !== ''
                ? ['enabled' => true, 'type' => $reveal]
                : ['enabled' => false, 'type' => 'fade'],
        ];
    }

    /**
     * Автоматическая расстановка оформления по тем же правилам ритма — для
     * страниц, собранных не из сборки (демо-контент, импорт). Сборки выше
     * размечены вручную и точнее: здесь мы знаем только порядок и типы блоков.
     *
     * @param list<string> $types типы блоков страницы по порядку
     * @return list<array<string,mixed>> оформление для каждого блока
     */
    public static function rhythmFor(array $types): array
    {
        $looks = [];
        $previousBg = 'none';
        $tintTurn = false;
        $cardTypes = [
            'advantages', 'cards_grid', 'contact_cards',
            'counters', 'docs_list', 'media_gallery',
            'news_feature', 'news_latest', 'partners', 'person_cards',
            'projects_list', 'team_list', 'testimonials',
        ];

        foreach (array_values($types) as $index => $type) {
            // Первый блок — обложка: максимум воздуха и без анимации.
            if ($index === 0) {
                $looks[] = self::look('none', $type === 'hero' ? 'max' : 'premium');
                $previousBg = 'none';
                continue;
            }

            // Призыв к действию — единственная тёмная секция.
            if ($type === 'cta') {
                $looks[] = self::look('navy', 'premium', 'fade');
                $previousBg = 'navy';
                continue;
            }

            // Подложка через одну секцию и только после секции без фона —
            // так фоны не слипаются в одно пятно.
            $bg = 'none';
            if ($previousBg === 'none' && $index % 2 === 0) {
                $bg = $tintTurn ? 'tint' : 'light';
                $tintTurn = !$tintTurn;
            }
            $reveal = in_array($type, $cardTypes, true)
                ? 'stagger'
                : ($bg === 'none' ? 'fade' : 'slide-up');
            $looks[] = self::look($bg, 'premium', $reveal);
            $previousBg = $bg;
        }

        return $looks;
    }

    /**
     * @return array<string, array{name:string, description:string, outline:list<string>, blocks:list<array<string,mixed>>}>
     */
    public static function all(?string $lang = null): array
    {
        $lang = $lang ?? Locale::current();

        return [
            'home' => self::home($lang),
            'department' => self::department(),
            'service' => self::service(),
            'about' => self::about(),
            'press' => self::press(),
            'contacts' => self::contacts(),
            'project' => self::project(),
        ];
    }

    /** @return array{name:string, description:string, outline:list<string>, blocks:list<array<string,mixed>>}|null */
    public static function find(string $id, ?string $lang = null): ?array
    {
        return self::all($lang)[$id] ?? null;
    }

    /** Готовая сборка: Главная страница (эталонный макет из демо-комплекта). */
    private static function home(string $lang = 'ru'): array
    {
        $fixtureName = $lang === 'uz' ? 'home_blocks_uz.json' : 'home_blocks.json';
        $fixture = dirname(__DIR__, 2) . '/database/demo_assets/' . $fixtureName;
        $blocks = [];
        if (is_file($fixture)) {
            $raw = json_decode((string) file_get_contents($fixture), true);
            if (is_array($raw)) {
                $looks = self::rhythmFor(array_map(
                    static fn (array $block): string => (string) ($block['type'] ?? ''),
                    $raw
                ));
                foreach ($raw as $index => $b) {
                    if (isset($b['type'], $b['data'])) {
                        $data = (array) $b['data'];
                        $data += $looks[$index] ?? [];
                        if (in_array((string) ($data['source'] ?? 'manual'), ['projects', 'albums', 'videos', 'media'], true)) {
                            // Автоматический источник целиком заменяет ручные
                            // карточки; не храним в образце поля, которые
                            // редактор никогда не увидит на сайте.
                            $data['items'] = [];
                        }
                        $blocks[] = [
                            'type' => (string) $b['type'],
                            'title' => (string) ($b['title'] ?? ''),
                            'data' => $data,
                        ];
                    }
                }
            }
        }

        if (empty($blocks)) {
            $blocks = [
                [
                    'type' => 'hero',
                    'title' => 'Главный баннер (Hero)',
                    'data' => array_merge([
                        'eyebrow' => 'Стратегия. Реформы. Развитие.',
                        'title' => 'Строим будущее для процветающего Узбекистана',
                        'subtitle' => 'Разрабатываем и реализуем стратегические инициативы, направленные на устойчивый экономический рост.',
                        'button_text' => 'Об агентстве',
                        'button_url' => '/o-nas',
                        'button2_text' => 'Стратегия-2030',
                        'button2_url' => '/strategiya-2030',
                        'bg_type' => 'image',
                        'image' => '/uploads/public/demo-agency-hero.jpg',
                        'overlay_enabled' => true,
                        'overlay_mode' => 'gradient',
                        'overlay_direction' => 'auto',
                        'overlay_opacity' => 55,
                        'text_position' => 'left',
                    ], self::look('none', 'max')),
                ],
                [
                    'type' => 'counters',
                    'title' => 'Показатели и достижения',
                    'data' => array_merge([
                        'title' => 'Ключевые показатели развития',
                        'items' => [
                            ['value' => '100', 'suffix' => '+', 'label' => 'стратегических инициатив реализовано'],
                            ['value' => '20', 'suffix' => '+', 'label' => 'проектов в ключевых отраслях'],
                            ['value' => '30', 'suffix' => '+', 'label' => 'аналитических исследований ежегодно'],
                            ['value' => '10', 'suffix' => '+', 'label' => 'международных партнёров'],
                        ],
                    ], self::look('light', 'premium', 'slide-up')),
                ],
                [
                    'type' => 'news_feature',
                    'title' => 'Новости и события',
                    'data' => array_merge([
                        'title' => 'Новости и аналитика',
                        'limit' => 6,
                    ], self::look('none', 'premium', 'fade')),
                ],
                [
                    'type' => 'cards_grid',
                    'title' => 'Проекты и инициативы',
                    'data' => array_merge([
                        'title' => 'Проекты и инициативы',
                        'variant' => 'image',
                        'source' => 'projects',
                        'limit' => 6,
                    ], self::look('light', 'premium', 'slide-up')),
                ],
                self::ctaBand('Готовы начать сотрудничество?', 'Обратитесь к нашим специалистам или оставьте заявку онлайн.'),
            ];
        }

        return [
            'name' => 'Главная страница (Демо-макет)',
            'description' => 'Эталонный готовый макет главной страницы с демо-ресурсами: Баннер Hero, Счётчики, Приоритетные направления, Проекты, Новости и Медиа.',
            'outline' => ['Главный баннер (Hero)', 'Показатели и достижения', 'Приоритетные направления', 'Проекты и инициативы', 'Новости и аналитика', 'Медиагалерея'],
            'blocks' => $blocks,
        ];
    }

    /** Страница структурного подразделения. */
    private static function department(): array
    {
        return [
            'name' => 'Страница подразделения',
            'description' => 'Управление, департамент или отдел: чем занимается, кто руководит, документы и контакты.',
            'outline' => ['Заголовок раздела', 'О подразделении с фото', 'Руководство', 'Документы', 'Контакты', 'Призыв обратиться'],
            'blocks' => [
                [
                    'type' => 'hero',
                    'title' => 'Заголовок раздела',
                    'data' => array_merge([
                        'eyebrow' => 'Структура агентства',
                        'title' => 'Название подразделения',
                        'subtitle' => 'Одно предложение о том, чем занимается подразделение и за что отвечает.',
                        'height' => 'regular',
                        'width' => 'full',
                        'text_position' => 'left',
                        'overlay_enabled' => true,
                        'overlay_mode' => 'gradient',
                        'overlay_direction' => 'auto',
                        'overlay_opacity' => 55,
                    ], self::look('none', 'max')),
                ],
                [
                    'type' => 'text_image',
                    'title' => 'О подразделении',
                    'data' => array_merge([
                        'title' => 'Задачи и полномочия',
                        // Поле «текст» у этого блока — обычный текст (шаблон
                        // экранирует его и сам расставляет переносы), поэтому
                        // никакой разметки здесь быть не должно.
                        'text' => "Опишите основные направления работы подразделения: какие задачи решает, "
                            . "с какими организациями взаимодействует, за какие услуги отвечает.\n\n"
                            . "Два-три абзаца достаточно — детали лучше вынести в документы ниже.",
                        'items' => [
                            ['label' => 'Направление работы', 'icon_svg' => ''],
                            ['label' => 'Второе направление', 'icon_svg' => ''],
                            ['label' => 'Третье направление', 'icon_svg' => ''],
                        ],
                    ], self::look('none', 'premium', 'fade')),
                ],
                [
                    'type' => 'person_cards',
                    'title' => 'Руководство',
                    'data' => array_merge([
                        'title' => 'Руководство подразделения',
                        'items' => [
                            ['name' => 'Фамилия Имя Отчество', 'role' => 'Начальник управления', 'photo' => '', 'url' => ''],
                            ['name' => 'Фамилия Имя Отчество', 'role' => 'Заместитель начальника', 'photo' => '', 'url' => ''],
                        ],
                    ], self::look('light', 'premium', 'slide-up')),
                ],
                [
                    'type' => 'docs_list',
                    'title' => 'Документы',
                    'data' => array_merge([
                        'title' => 'Документы подразделения',
                        'columns' => 3,
                        'items' => [
                            ['title' => 'Положение о подразделении', 'meta' => 'PDF', 'url' => ''],
                            ['title' => 'Регламент работы', 'meta' => 'PDF', 'url' => ''],
                            ['title' => 'План мероприятий', 'meta' => 'PDF', 'url' => ''],
                        ],
                    ], self::look('none', 'premium', 'fade')),
                ],
                [
                    'type' => 'contact_cards',
                    'title' => 'Контакты',
                    'data' => array_merge([
                        'title' => 'Как связаться',
                        'items' => [
                            ['title' => 'Приёмная', 'lines' => "+998 (71) 000-00-00\nПн–Пт, 9:00–18:00", 'link_text' => '', 'link_url' => '', 'icon_svg' => ''],
                            ['title' => 'Электронная почта', 'lines' => 'info@example.uz', 'link_text' => 'Написать', 'link_url' => 'mailto:info@example.uz', 'icon_svg' => ''],
                        ],
                    ], self::look('none', 'small')),
                ],
                self::ctaBand('Не нашли нужную информацию?', 'Направьте обращение — ответим в установленный законом срок.'),
            ],
        ];
    }

    /** Страница услуги или направления деятельности. */
    private static function service(): array
    {
        return [
            'name' => 'Услуга или направление',
            'description' => 'Что это, кому положено, порядок получения, документы и частые вопросы.',
            'outline' => ['Заголовок услуги', 'Описание', 'Порядок получения', 'Необходимые документы', 'Частые вопросы', 'Призыв подать заявление'],
            'blocks' => [
                [
                    'type' => 'hero',
                    'title' => 'Заголовок услуги — укажите ссылку кнопки',
                    'data' => array_merge([
                        'eyebrow' => 'Услуги',
                        'title' => 'Название услуги',
                        'subtitle' => 'Кратко: кому услуга адресована и какой результат получает заявитель.',
                        'height' => 'regular',
                        'width' => 'full',
                        'text_position' => 'left',
                        'overlay_enabled' => true,
                        'overlay_mode' => 'gradient',
                        'overlay_direction' => 'auto',
                        'overlay_opacity' => 55,
                        'button_text' => 'Подать заявление',
                        'button_url' => '',
                    ], self::look('none', 'max')),
                ],
                [
                    'type' => 'text',
                    'title' => 'Описание',
                    'data' => array_merge([
                        'title' => 'Кому и на каких условиях',
                        'content' => "<p>Опишите, кто имеет право на услугу, в какой срок она оказывается и "
                            . "сколько стоит (или укажите, что бесплатно).</p>"
                            . "<p>Сложные условия лучше вынести в список — так их проще читать.</p>",
                    ], self::look('none', 'premium', 'fade')),
                ],
                [
                    'type' => 'stages',
                    'title' => 'Порядок получения',
                    'data' => array_merge([
                        'title' => 'Порядок получения',
                        'items' => [
                            ['stage' => 'Шаг 1', 'title' => 'Подготовьте документы', 'text' => 'Список — в разделе ниже.', 'year' => '', 'status' => '', 'status_text' => ''],
                            ['stage' => 'Шаг 2', 'title' => 'Подайте заявление', 'text' => 'Лично или через электронную форму.', 'year' => '', 'status' => '', 'status_text' => ''],
                            ['stage' => 'Шаг 3', 'title' => 'Получите результат', 'text' => 'Срок рассмотрения — до 15 рабочих дней.', 'year' => '', 'status' => '', 'status_text' => ''],
                        ],
                    ], self::look('light', 'premium', 'slide-up')),
                ],
                [
                    'type' => 'docs_list',
                    'title' => 'Необходимые документы',
                    'data' => array_merge([
                        'title' => 'Необходимые документы',
                        'columns' => 2,
                        'items' => [
                            ['title' => 'Заявление по форме', 'meta' => 'DOCX', 'url' => ''],
                            ['title' => 'Копия документа, удостоверяющего личность', 'meta' => '', 'url' => ''],
                        ],
                    ], self::look('none', 'premium', 'fade')),
                ],
                [
                    'type' => 'faq',
                    'title' => 'Частые вопросы',
                    'data' => array_merge([
                        'title' => 'Частые вопросы',
                        'items' => [
                            ['question' => 'Сколько рассматривается заявление?', 'answer' => 'Укажите срок в рабочих днях и основание.'],
                            ['question' => 'Можно ли подать документы онлайн?', 'answer' => 'Опишите электронный способ подачи, если он есть.'],
                            ['question' => 'В каком случае откажут?', 'answer' => 'Перечислите основания для отказа со ссылкой на норму.'],
                        ],
                    ], self::look('tint', 'premium', 'fade')),
                ],
                self::ctaBand('Готовы подать заявление?', 'Заполните электронную форму — это займёт несколько минут.', 'Подать заявление'),
            ],
        ];
    }

    /** Страница «О ведомстве». */
    private static function about(): array
    {
        return [
            'name' => 'О ведомстве',
            'description' => 'Миссия, цифры, направления работы, история и руководство — представительская страница.',
            'outline' => ['Заголовок', 'Миссия', 'В цифрах', 'Направления работы', 'История', 'Руководство', 'Призыв к сотрудничеству'],
            'blocks' => [
                [
                    'type' => 'hero',
                    'title' => 'Заголовок',
                    'data' => array_merge([
                        'eyebrow' => 'Об агентстве',
                        'title' => 'Кратко о ведомстве',
                        'subtitle' => 'Одно предложение о роли ведомства и его главной задаче.',
                        'height' => 'regular',
                        'width' => 'full',
                        'text_position' => 'left',
                        'overlay_enabled' => true,
                        'overlay_mode' => 'gradient',
                        'overlay_direction' => 'auto',
                        'overlay_opacity' => 55,
                    ], self::look('none', 'max')),
                ],
                [
                    'type' => 'text',
                    'title' => 'Миссия',
                    'data' => array_merge([
                        'title' => 'Миссия и задачи',
                        'content' => "<p>Два-три абзаца о том, зачем создано ведомство, какие задачи решает "
                            . "и на основании каких документов действует.</p>",
                    ], self::look('none', 'premium', 'fade')),
                ],
                [
                    'type' => 'counters',
                    'title' => 'В цифрах',
                    'data' => array_merge([
                        'title' => 'Деятельность в цифрах',
                        'items' => [
                            ['value' => '120', 'suffix' => '+', 'label' => 'реализованных проектов', 'icon_svg' => ''],
                            ['value' => '14', 'suffix' => '', 'label' => 'регионов охвата', 'icon_svg' => ''],
                            ['value' => '35', 'suffix' => ' млрд', 'label' => 'привлечённых инвестиций', 'icon_svg' => ''],
                        ],
                    ], self::look('light', 'premium', 'slide-up')),
                ],
                [
                    'type' => 'advantages',
                    'title' => 'Направления работы',
                    'data' => array_merge([
                        'title' => 'Основные направления',
                        'variant' => 'band',
                        'items' => [
                            ['title' => 'Первое направление', 'text' => 'Короткое пояснение в одну-две строки.', 'icon_svg' => ''],
                            ['title' => 'Второе направление', 'text' => 'Короткое пояснение в одну-две строки.', 'icon_svg' => ''],
                            ['title' => 'Третье направление', 'text' => 'Короткое пояснение в одну-две строки.', 'icon_svg' => ''],
                        ],
                    ], self::look('none', 'premium', 'fade')),
                ],
                [
                    'type' => 'timeline',
                    'title' => 'История',
                    'data' => array_merge([
                        'title' => 'Этапы развития',
                        'items' => [
                            ['year' => '2019', 'text' => 'Событие, с которого началась работа ведомства.', 'status' => 'done'],
                            ['year' => '2022', 'text' => 'Значимое расширение полномочий или направлений.', 'status' => 'done'],
                            ['year' => '2025', 'text' => 'Текущий этап и приоритеты.', 'status' => 'active'],
                        ],
                    ], self::look('tint', 'premium', 'fade')),
                ],
                [
                    'type' => 'person_cards',
                    'title' => 'Руководство',
                    'data' => array_merge([
                        'title' => 'Руководство',
                        'items' => [
                            ['name' => 'Фамилия Имя Отчество', 'role' => 'Директор', 'photo' => '', 'url' => ''],
                            ['name' => 'Фамилия Имя Отчество', 'role' => 'Первый заместитель', 'photo' => '', 'url' => ''],
                            ['name' => 'Фамилия Имя Отчество', 'role' => 'Заместитель', 'photo' => '', 'url' => ''],
                        ],
                    ], self::look('none', 'premium', 'slide-up')),
                ],
                self::ctaBand('Открыты к сотрудничеству', 'Предложения о партнёрстве и инвестициях направляйте через форму обращения.'),
            ],
        ];
    }

    /** Пресс-центр. */
    private static function press(): array
    {
        return [
            'name' => 'Пресс-центр',
            'description' => 'Лента новостей, фото- и видеоматериалы, подписка на рассылку.',
            'outline' => ['Заголовок', 'Новости и аналитика', 'Медиатека', 'Подписка'],
            'blocks' => [
                [
                    'type' => 'hero',
                    'title' => 'Заголовок',
                    'data' => array_merge([
                        'eyebrow' => 'Пресс-центр',
                        'title' => 'Новости и медиаматериалы',
                        'subtitle' => 'Официальные сообщения, фото- и видеохроника мероприятий.',
                        'height' => 'regular',
                        'width' => 'full',
                        'text_position' => 'left',
                        'overlay_enabled' => true,
                        'overlay_mode' => 'gradient',
                        'overlay_direction' => 'auto',
                        'overlay_opacity' => 55,
                    ], self::look('none', 'max')),
                ],
                [
                    'type' => 'news_feature',
                    'title' => 'Новости',
                    'data' => array_merge([
                        'title' => 'Новости и аналитика',
                        'all_text' => 'Все новости',
                        'limit' => 6,
                    ], self::look('none', 'premium', 'fade')),
                ],
                [
                    'type' => 'media_gallery',
                    'title' => 'Медиатека',
                    'data' => array_merge([
                        'title' => 'Фото и видео',
                        'source' => 'media',
                        'limit' => 8,
                    ], self::look('light', 'premium', 'slide-up')),
                ],
                [
                    'type' => 'subscribe',
                    'title' => 'Подписка',
                    'data' => array_merge([
                        'title' => 'Подписка на новости',
                        'text' => 'Получайте дайджест официальных сообщений на почту раз в неделю.',
                        'button_text' => 'Подписаться',
                    ], self::look('none', 'small')),
                ],
            ],
        ];
    }

    /** Контакты. */
    private static function contacts(): array
    {
        return [
            'name' => 'Контакты',
            'description' => 'Реквизиты и приёмная, карта проезда и форма обращения.',
            'outline' => ['Заголовок', 'Контактные данные', 'Карта проезда', 'Форма обращения'],
            'blocks' => [
                [
                    'type' => 'hero',
                    'title' => 'Заголовок',
                    'data' => array_merge([
                        'eyebrow' => 'Контакты',
                        'title' => 'Как с нами связаться',
                        'subtitle' => 'Приёмная, электронная почта и адрес для корреспонденции.',
                        'height' => 'regular',
                        'width' => 'full',
                        'text_position' => 'left',
                        'overlay_enabled' => true,
                        'overlay_mode' => 'gradient',
                        'overlay_direction' => 'auto',
                        'overlay_opacity' => 55,
                    ], self::look('none', 'max')),
                ],
                [
                    'type' => 'contact_cards',
                    'title' => 'Контактные данные',
                    'data' => array_merge([
                        'title' => 'Контактные данные',
                        'items' => [
                            ['title' => 'Приёмная', 'lines' => "+998 (71) 000-00-00\nПн–Пт, 9:00–18:00", 'link_text' => '', 'link_url' => '', 'icon_svg' => ''],
                            ['title' => 'Электронная почта', 'lines' => 'info@example.uz', 'link_text' => 'Написать', 'link_url' => 'mailto:info@example.uz', 'icon_svg' => ''],
                            ['title' => 'Адрес', 'lines' => "г. Ташкент, ул. Примерная, 1\nИндекс 100000", 'link_text' => '', 'link_url' => '', 'icon_svg' => ''],
                        ],
                    ], self::look('none', 'premium', 'fade')),
                ],
                [
                    'type' => 'map_point',
                    'title' => 'Карта проезда — вставьте ссылку и код карты',
                    'data' => array_merge([
                        'title' => 'Как добраться',
                        'card_title' => 'Главный офис',
                        'address' => 'г. Ташкент, ул. Примерная, 1',
                        'embed_url' => '',
                        'button_text' => 'Открыть в картах',
                        'button_url' => '',
                    ], self::look('light', 'premium', 'fade')),
                ],
                [
                    'type' => 'form',
                    'title' => 'Форма обращения — выберите форму',
                    'data' => array_merge([
                        'form_id' => null,
                    ], self::look('none', 'premium')),
                ],
            ],
        ];
    }

    /** Проект или программа. */
    private static function project(): array
    {
        return [
            'name' => 'Проект или программа',
            'description' => 'Суть проекта, этапы реализации и результаты в цифрах. Фотоотчёт добавьте блоком «Галерея», когда снимки будут готовы.',
            'outline' => ['Заголовок проекта', 'О проекте с фото', 'Этапы реализации', 'Результаты', 'Призыв к участию'],
            'blocks' => [
                [
                    'type' => 'hero',
                    'title' => 'Заголовок проекта',
                    'data' => array_merge([
                        'eyebrow' => 'Проекты',
                        'title' => 'Название проекта',
                        'subtitle' => 'Одно предложение о цели проекта и сроках реализации.',
                        'height' => 'regular',
                        'width' => 'full',
                        'text_position' => 'left',
                        'overlay_enabled' => true,
                        'overlay_mode' => 'gradient',
                        'overlay_direction' => 'auto',
                        'overlay_opacity' => 55,
                    ], self::look('none', 'max')),
                ],
                [
                    'type' => 'text_image',
                    'title' => 'О проекте',
                    'data' => array_merge([
                        'title' => 'О проекте',
                        'text' => "Опишите задачу, которую решает проект, его масштаб и участников.\n\n"
                            . "Укажите источник финансирования и ожидаемый эффект.",
                        'items' => [
                            ['label' => 'Срок реализации: 2025–2027', 'icon_svg' => ''],
                            ['label' => 'Охват: 14 регионов', 'icon_svg' => ''],
                        ],
                    ], self::look('none', 'premium', 'fade')),
                ],
                [
                    'type' => 'stages',
                    'title' => 'Этапы реализации',
                    'data' => array_merge([
                        'title' => 'Этапы реализации',
                        'items' => [
                            ['stage' => 'Этап 1', 'title' => 'Подготовка', 'text' => 'Проектирование и согласования.', 'year' => '2025', 'status' => 'done', 'status_text' => 'Завершён'],
                            ['stage' => 'Этап 2', 'title' => 'Реализация', 'text' => 'Основные работы по проекту.', 'year' => '2026', 'status' => 'active', 'status_text' => 'В работе'],
                            ['stage' => 'Этап 3', 'title' => 'Ввод в эксплуатацию', 'text' => 'Запуск и оценка результатов.', 'year' => '2027', 'status' => '', 'status_text' => 'Запланирован'],
                        ],
                    ], self::look('light', 'premium', 'slide-up')),
                ],
                [
                    'type' => 'counters',
                    'title' => 'Результаты',
                    'data' => array_merge([
                        'title' => 'Результаты проекта',
                        'items' => [
                            ['value' => '12', 'suffix' => '', 'label' => 'построенных объектов', 'icon_svg' => ''],
                            ['value' => '4500', 'suffix' => '+', 'label' => 'человек охвачено', 'icon_svg' => ''],
                            ['value' => '98', 'suffix' => '%', 'label' => 'выполнение плана', 'icon_svg' => ''],
                        ],
                    ], self::look('none', 'premium', 'fade')),
                ],
                // Блок галереи в сборку не кладём: без загруженных фото он даёт
                // на странице пустую секцию. Редактор добавит его сам, когда
                // снимки появятся.
                self::ctaBand('Хотите участвовать в проекте?', 'Направьте заявку — рассмотрим предложения о партнёрстве.', 'Направить заявку'),
            ],
        ];
    }

    /**
     * Завершающая тёмная полоса. Единственная navy-секция на странице —
     * поэтому вынесена в общий метод, чтобы не расползлась по пресетам.
     */
    private static function ctaBand(string $title, string $text, string $buttonText = 'Написать обращение'): array
    {
        return [
            'type' => 'cta',
            // Название блока в редакторе подсказывает недостающий шаг: кнопка
            // без ссылки на сайте не появится.
            'title' => 'Призыв к действию — укажите ссылку кнопки',
            'data' => array_merge([
                'title' => $title,
                'text' => $text,
                'variant' => 'band',
                'button_text' => $buttonText,
                'button_url' => '',
                'icon_svg' => '',
            ], self::look('navy', 'premium', 'fade')),
        ];
    }
}
