<?php

declare(strict_types=1);

use App\Core\BlockData\BlockPresentationNormalizer;
use App\Core\BlockTypeRegistry;
use App\Core\PageTemplateFile;

/*
 * Шаблон страницы как файл. Внутри системы шаблон уже был (`block_snippets`),
 * но жил только в базе: перенести сборку на другой сайт или прислать её
 * готовой было нечем.
 *
 * Присланный файл — не свои данные: тип блока сверяется с реестром, поля — с
 * умолчаниями типа, оформление проходит через нормализатор формы. Что не
 * прошло, попадает в предупреждения, а не пропадает молча.
 */

/** @param array<int, mixed> $blocks */
function template_roundtrip(array $blocks, bool $superAdmin = true): array
{
    return PageTemplateFile::parse(PageTemplateFile::export('Проба', $blocks), $superAdmin);
}

test('Шаблон переживает дорогу туда и обратно без потерь', function () {
    $result = template_roundtrip([[
        'type' => 'text',
        'title' => 'Заголовок секции',
        'data' => [
            'content' => '<p>Текст</p>',
            '_bg' => 'navy',
            '_bg_mode' => 'color',
            '_bg_color' => '#123456',
            '_fullwidth' => true,
            '_pad_top' => 'large',
            '_reveal' => ['enabled' => true, 'type' => 'stagger'],
            '_watermark' => 'ЦЕЛИ',
            '_watermark_size' => 30,
        ],
        'custom_css' => '#block-1 { color: red }',
        'is_active' => 1,
    ]]);

    assert_same('Проба', $result['name']);
    assert_same(1, count($result['blocks']));
    assert_same([], $result['warnings'], 'у honest-шаблона замечаний быть не должно');

    $data = $result['blocks'][0]['data'];
    assert_same('<p>Текст</p>', $data['content'], 'поле типа потерялось');
    assert_same('navy', $data['_bg']);
    assert_same('#123456', $data['_bg_color'], 'цвет фона не пережил дорогу');
    assert_same(true, $data['_fullwidth']);
    assert_same('large', $data['_pad_top']);
    assert_same('stagger', $data['_reveal']['type'], 'появление при скролле потерялось');
    assert_same('ЦЕЛИ', $data['_watermark']);
    assert_same(30, $data['_watermark_size']);
    assert_contains('color: red', (string) $result['blocks'][0]['custom_css']);
});

test('Оформление из файла чистится тем же нормализатором, что и форма', function () {
    // Перечислять ключи оформления руками нельзя: список молча разъезжается с
    // нормализатором, и импорт начинает либо терять оформление, либо
    // пропускать непроверенное значение внутрь.
    $result = template_roundtrip([[
        'type' => 'text',
        'data' => [
            'content' => 'x',
            '_bg_mode' => 'image',
            '_bg_image' => 'javascript:alert(1)',
            '_pad_top' => 'какой-то-мусор',
            '_visible_device' => 'телевизор',
        ],
    ]]);
    $data = $result['blocks'][0]['data'];

    assert_true(
        ($data['_bg_image'] ?? '') === '' || $data['_bg_image'] === null,
        'адрес с исполняемой схемой прошёл в фон секции'
    );
    assert_same('default', $data['_pad_top'], 'чужое значение отступа должно откатываться к умолчанию');
    assert_same('', $data['_visible_device'], 'чужое устройство должно откатываться к «всем»');

    // Ключи оформления печатает нормализатор, а не список в PageTemplateFile:
    // проверяем, что импорт отдаёт ровно его набор.
    $expected = array_keys(BlockPresentationNormalizer::normalize([]));
    foreach ($expected as $key) {
        assert_true(array_key_exists($key, $data), 'импорт потерял ключ оформления: ' . $key);
    }
});

test('Поля, которых нет у типа, отбрасываются и попадают в замечания', function () {
    // Такой ключ всё равно потерялся бы при первом сохранении в админке —
    // честнее отбросить сразу и сказать вслух.
    $result = template_roundtrip([[
        'type' => 'text',
        'data' => ['content' => 'x', 'выдуманное' => 1, 'ещё_одно' => 2],
    ]]);

    assert_true(!array_key_exists('выдуманное', $result['blocks'][0]['data']));
    assert_same(1, count($result['warnings']));
    assert_contains('выдуманное', $result['warnings'][0]);
});

test('Чужой и битый файл отклоняются с понятной причиной', function () {
    $cases = [
        '' => 'пуст',
        'не json' => 'JSON',
        '{"a":1}' => 'artstudio.page-template',
        '{"kind":"artstudio.page-template","version":1,"blocks":[]}' => 'нет блоков',
        '{"kind":"artstudio.page-template","version":99,"blocks":[{"type":"text"}]}' => 'новой версией',
    ];
    foreach ($cases as $json => $expect) {
        $failed = false;
        try {
            PageTemplateFile::parse((string) $json, true);
        } catch (InvalidArgumentException $e) {
            $failed = true;
            assert_contains($expect, $e->getMessage(), 'причина отказа непонятна для: ' . $json);
        }
        assert_true($failed, 'файл должен был быть отклонён: ' . $json);
    }

    // Ни один блок не подошёл — это тоже отказ, а не пустой шаблон в списке.
    $failed = false;
    try {
        template_roundtrip([['type' => 'такого-типа-нет', 'data' => []]]);
    } catch (InvalidArgumentException $e) {
        $failed = true;
        assert_contains('не подошёл', $e->getMessage());
    }
    assert_true($failed, 'шаблон без единого годного блока не должен создаваться');
});

test('Произвольный код из файла принимает только супер-админ', function () {
    $blocks = [
        ['type' => 'html', 'data' => ['html' => '<script>alert(1)</script>']],
        ['type' => 'text', 'data' => ['content' => 'ok'], 'custom_css' => '#block-1{color:red}'],
    ];

    // Редактор: блок «HTML» не проходит, «Свой CSS» снимается — те же правила,
    // что и в форме блока, иначе запрет обходится присланным файлом.
    $editor = template_roundtrip($blocks, false);
    assert_same(1, count($editor['blocks']));
    assert_same('text', $editor['blocks'][0]['type']);
    assert_same('', $editor['blocks'][0]['custom_css']);
    assert_same(2, count($editor['warnings']), 'об обоих снятиях нужно сказать');

    $super = template_roundtrip($blocks, true);
    assert_same(2, count($super['blocks']));
    assert_contains('color:red', (string) $super['blocks'][1]['custom_css']);
});

test('Вложенные блоки: только у контейнеров и без второго уровня', function () {
    $result = template_roundtrip([
        [
            'type' => 'columns',
            'data' => [],
            'children' => [
                ['type' => 'tabs', 'data' => []],
                ['type' => 'text', 'data' => ['content' => 'a'], 'column_index' => 1],
            ],
        ],
        ['type' => 'text', 'data' => ['content' => 'b'], 'children' => [['type' => 'text', 'data' => []]]],
    ]);

    assert_same(1, count($result['blocks'][0]['children']), 'контейнер в контейнер не вкладывается');
    assert_same(1, (int) $result['blocks'][0]['children'][0]['column_index']);
    assert_true(!isset($result['blocks'][1]['children']), 'у не-контейнера вложенных быть не может');
    assert_same(2, count($result['warnings']));
});

test('Имя файла годится для заголовка ответа', function () {
    // Кириллица в Content-Disposition доезжает не до всякого клиента.
    $name = PageTemplateFile::fileName('Главная страница Агентства');
    assert_true(preg_match('/^[a-z0-9-]+\.json$/', $name) === 1, 'имя файла не транслитерировано: ' . $name);
    // Переводить нечего — имя всё равно обязано получиться годным: пустое
    // имя дало бы заголовок `filename=".json"`.
    $fallback = PageTemplateFile::fileName('!!!');
    assert_true(preg_match('/^[a-z0-9-]+\.json$/', $fallback) === 1, 'у безымянного шаблона негодное имя: ' . $fallback);
});

test('Обмен файлами подключён в админке', function () {
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    assert_contains("'/admin/snippets/export'", $routes, 'нет адреса выгрузки');
    assert_contains("'/admin/snippets/import'", $routes, 'нет адреса загрузки');

    $editor = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/_block_editor.php');
    assert_contains('/admin/snippets/import', $editor, 'в редакторе нет формы загрузки');
    assert_contains('enctype="multipart/form-data"', $editor, 'без enctype файл не доедет');
    assert_contains('/admin/snippets/export', $editor, 'в редакторе нет кнопки выгрузки');

    // Формат описан: шаблоны присылают файлами, и без описания их не собрать.
    assert_true(is_file(APP_ROOT . '/docs/PAGE_TEMPLATES.md'), 'нет описания формата');
    $docs = (string) file_get_contents(APP_ROOT . '/docs/PAGE_TEMPLATES.md');
    assert_contains(PageTemplateFile::KIND, $docs, 'в описании нет пометки формата');
    // Список типов блоков в описании должен быть настоящим.
    foreach (['text', 'columns'] as $type) {
        assert_true(BlockTypeRegistry::has($type));
        assert_contains('`' . $type . '`', $docs, 'в описании нет типа ' . $type);
    }
});
