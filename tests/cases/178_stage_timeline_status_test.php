<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

test('Шкала этапов передаёт статус каждому маркеру и соединению', function () {
    $out = BlockRenderer::render([
        'id' => 1781,
        'type' => 'stages',
        'custom_css' => null,
        'data' => json_encode([
            'title' => 'Этапы',
            'items' => [
                ['title' => 'Подготовка', 'status' => 'done'],
                ['title' => 'Реализация', 'status' => 'active'],
                ['title' => 'Результат', 'status' => 'planned'],
            ],
        ]),
    ])['html'];

    assert_contains('stage--done stage--next-active', $out);
    assert_contains('stage--active stage--next-planned', $out);
    assert_contains('stage--planned', $out);
});

test('Хронология поддерживает статусы и совместима со старыми данными', function () {
    $explicit = BlockRenderer::render([
        'id' => 1782,
        'type' => 'stages',
        'custom_css' => null,
        'data' => json_encode([
            'layout' => 'list',
            'items' => [
                ['year' => '2024', 'text' => 'Готово', 'status' => 'done'],
                ['year' => '2025', 'text' => 'В работе', 'status' => 'active'],
                ['year' => '2026', 'text' => 'План', 'status' => 'planned'],
            ],
        ]),
    ])['html'];

    assert_contains('timeline-item--done timeline-item--next-active', $explicit);
    assert_contains('timeline-item--active timeline-item--next-planned', $explicit);
    assert_contains('timeline-item--planned', $explicit);

    // Тип `timeline` в базе остаётся у блоков, до которых ещё не дошла
    // миграция: код и база на сервере обновляются разными путями. Такой блок
    // обязан выйти прежним вертикальным списком, а не лентой карточек —
    // иначе вид собранной страницы меняется молча.
    $legacy = BlockRenderer::render([
        'id' => 1783,
        'type' => 'timeline',
        'custom_css' => null,
        'data' => json_encode([
            'items' => [
                ['year' => '2024', 'text' => 'Первое'],
                ['year' => '2025', 'text' => 'Второе'],
            ],
        ]),
    ])['html'];

    assert_contains('timeline-item--done timeline-item--next-active', $legacy);
    assert_contains('timeline-item--active', $legacy);
});

test('CSS различает завершённые, текущие и будущие участки', function () {
    $css = theme_css();

    assert_contains('.stage--done.stage--next-active::before', $css);
    assert_contains('.timeline-item--done.timeline-item--next-active::after', $css);
    assert_contains('.timeline-item--planned::before', $css);
});

test('Хронология сохраняет читаемые колонки и не наследует размер H3', function () {
    $css = theme_css();

    assert_contains('grid-template-columns: clamp(140px, 12vw, 176px) minmax(0, 1fr);', $css);
    assert_contains('.timeline-item__year { font-family:', $css);
    assert_contains('white-space: nowrap;', $css);
    assert_contains('@media (max-width: 640px)', $css);
    assert_not_contains('.timeline-item__year', \App\Core\DesignSettings::TYPO_SIZES['fs_h3'][1]);
    assert_not_contains('max-width: 860px;', $css);
});

test('Хронология и этапы — один тип: раскладка выбирается настройкой', function (): void {
    // Два блока-близнеца («Этапы» и «Хронология») показывали события во
    // времени и оба подписывались «таймлайн»: выбрать между ними по описанию
    // было нельзя, а правка одного молча расходилась со вторым.
    assert_false(
        \App\Core\BlockTypeRegistry::has('timeline'),
        'timeline больше не отдельный тип блока'
    );
    assert_false(
        is_file(APP_ROOT . '/templates/blocks/timeline.php'),
        'шаблон timeline не должен оставаться рядом с stages: вторая копия разъедется с первой'
    );

    $fields = \App\Core\BlockData\BlockFieldSchema::fields('stages');
    assert_true(isset($fields['layout']), 'раскладка не описана схемой');
    foreach (['cta_title', 'cta_text', 'cta_button_text', 'cta_button_url', 'cta_image'] as $key) {
        assert_true(isset($fields[$key]), "карточка рядом со списком: нет поля {$key}");
        // Карточку некуда поставить в ленте — там этапы идут в ряд, и она
        // стала бы шестым этапом без года.
        assert_same(['field' => 'layout', 'values' => ['list']], $fields[$key]->when);
    }
    foreach (['variant', 'columns', 'autoplay'] as $key) {
        assert_same(['field' => 'layout', 'values' => ['track']], $fields[$key]->when);
    }

    // Раскладка меняет вывод, а не только класс: лента строит карточки этапов,
    // список — годы слева и события справа.
    $data = [
        'items' => [
            ['year' => '2024', 'stage' => 'I этап', 'title' => 'Старт', 'text' => 'Описание', 'status' => 'done'],
            ['year' => '2025', 'title' => 'Продолжение', 'status' => 'active'],
        ],
    ];
    $track = BlockRenderer::render([
        'id' => 1784, 'type' => 'stages', 'custom_css' => null,
        'data' => json_encode($data + ['layout' => 'track']),
    ])['html'];
    $list = BlockRenderer::render([
        'id' => 1785, 'type' => 'stages', 'custom_css' => null,
        'data' => json_encode($data + ['layout' => 'list']),
    ])['html'];

    assert_contains('class="stages', $track);
    assert_contains('timeline-list', $list);
    assert_not_contains('timeline-list', $track);
    // Поля этапа видны в обеих раскладках: переключение вида не должно молча
    // терять часть содержимого.
    foreach (['I этап', 'Старт', 'Описание'] as $needle) {
        assert_contains($needle, $track, "лента: нет «{$needle}»");
        assert_contains($needle, $list, "список: нет «{$needle}»");
    }
});

test('Блок прежнего типа читается как хронология и до миграции', function (): void {
    $row = [
        'type' => 'timeline',
        'data' => ['button_text' => 'Вся история', 'button_url' => '/history', 'items' => []],
    ];

    assert_same('stages', \App\Core\BlockTypeRegistry::canonicalType($row['type']));
    $data = \App\Core\BlockTypeRegistry::canonicalData($row['type'], $row['data']);
    assert_same('list', $data['layout'], 'без раскладки старая хронология вышла бы лентой');
    // Кнопка под списком — та же ссылка «Все …», что у ленты: второго поля для
    // неё не нужно.
    assert_same('Вся история', $data['all_text']);
    assert_same('/history', $data['all_url']);

    // Сохранённое значение сильнее умолчания: блок, уже переехавший миграцией,
    // эта подстановка не трогает.
    $migrated = \App\Core\BlockTypeRegistry::canonicalData('timeline', ['layout' => 'track']);
    assert_same('track', $migrated['layout']);
    assert_same(['items' => []], \App\Core\BlockTypeRegistry::canonicalData('stages', ['items' => []]));
});

test('Миграция переводит блоки и сохранённые шаблоны страниц', function (): void {
    $sql = (string) file_get_contents(APP_ROOT . '/database/migrations/2026_09_11_timeline_into_stages.sql');

    assert_contains('-- @post-schema', $sql);
    assert_contains("SET type = 'stages'", $sql);
    assert_contains("'$.layout', 'list'", $sql);
    // Библиотека шаблонов хранит те же блоки отдельным JSON: без этого шага
    // сохранённый шаблон положил бы на страницу блок несуществующего типа.
    assert_contains('block_snippets', $sql);
});
