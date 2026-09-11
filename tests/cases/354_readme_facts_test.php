<?php

declare(strict_types=1);

use App\Core\BlockTypeRegistry;
use App\Core\DesignSettings;
use App\Core\Media;

/**
 * README обязан сходиться с кодом.
 *
 * README — первое, что открывает новый разработчик и внешний ревьюер, и
 * расхождение в нём дороже, чем отсутствие строки: неверное число читается как
 * верное. Замерено на момент заведения сторожа: заявлен «31 тип блока» при 41,
 * «609 тестовых сценариев» при 1649, «WebP-варианты 800/1600/full» при
 * ширинах 400/800/1600, «22 семейства» шрифтов при 20 наборных и 4 рукописных,
 * а новичка отправляли объявлять тип блока в `BlockRenderer::DEFAULTS` —
 * константу, которой больше нет (типы объявляет схема полей).
 *
 * Ни одна из ошибок не ломала работу и потому прожила несколько релизов. Это
 * ровно тот тихий отказ, ради которого в проекте стоят остальные сторожа:
 * `schema.sql` обязан сходиться с миграциями, ключи t() — со словарём,
 * настройка «Дизайна» — с потребителем в CSS.
 */

/**
 * Числа из строки README, содержащей подстроку.
 *
 * @return list<int>
 */
function readme_numbers(string $needle): array
{
    static $lines = null;
    if ($lines === null) {
        $lines = explode("\n", (string) file_get_contents(APP_ROOT . '/README.md'));
    }

    foreach ($lines as $line) {
        if (str_contains($line, $needle) && preg_match_all('/\d[\d\s]*/u', $line, $m) > 0) {
            return array_map(static fn (string $n): int => (int) preg_replace('/\D/', '', $n), $m[0]);
        }
    }

    return [];
}

/** Первое число из строки README, содержащей подстроку. */
function readme_number(string $needle): ?int
{
    return readme_numbers($needle)[0] ?? null;
}

test('README: число типов блоков совпадает с реестром', function (): void {
    $actual = count(BlockTypeRegistry::BASE_DEFAULTS);

    assert_same(
        $actual,
        // Ищем по скобке с примерами, а не по «тип блока»: слово меняет
        // окончание вместе с числом («41 тип», «40 типов»), и сторож фактов
        // падал бы на грамматике, а не на расхождении.
        readme_number('блока (hero'),
        'в README заявлено другое число типов блоков, чем в BlockTypeRegistry::BASE_DEFAULTS'
    );
    // В дереве проекта строка называет два числа: файлов шаблонов и типов.
    assert_same(
        [count(glob(APP_ROOT . '/templates/blocks/*.php') ?: []), $actual],
        readme_numbers('шаблоны блоков конструктора'),
        'дерево проекта в README разошлось с каталогом шаблонов или реестром типов'
    );
});

test('README: число шаблонов блоков совпадает с каталогом', function (): void {
    $files = glob(APP_ROOT . '/templates/blocks/*.php') ?: [];

    // Контейнеры (columns, tabs) шаблона не имеют — их рендер программный,
    // поэтому файлов меньше, чем типов, и README называет оба числа.
    assert_same(
        count($files),
        readme_number('файлов на'),
        'число шаблонов в templates/blocks разошлось с README'
    );
    assert_true(
        count($files) < count(BlockTypeRegistry::BASE_DEFAULTS),
        'шаблонов не может быть больше, чем типов: у контейнеров рендер программный'
    );
});

test('README: каталоги шрифтов названы верно', function (): void {
    assert_same(
        count(DesignSettings::GOOGLE_FONTS),
        readme_number('наборных семейств'),
        'число наборных семейств в README разошлось с DesignSettings::GOOGLE_FONTS'
    );
    assert_same(
        count(DesignSettings::SCRIPT_FONTS),
        readme_number('рукописных для выделения'),
        'число рукописных семейств в README разошлось с DesignSettings::SCRIPT_FONTS'
    );
});

test('README: ширины вариантов изображений названы верно', function (): void {
    $readme = (string) file_get_contents(APP_ROOT . '/README.md');

    assert_contains(
        'WebP-варианты ' . implode('/', Media::VARIANT_WIDTHS),
        $readme,
        'ширины уменьшенных копий в README разошлись с Media::VARIANT_WIDTHS'
    );
});

test('README: число тестовых сценариев совпадает с набором', function (): void {
    // Сценарии считаются статически — по вызовам test() в файлах набора: то же
    // число печатает раннер в итоге. Добавил тест — поправь строку в README;
    // это одна цифра, зато документ не начинает врать с первого же коммита.
    $registered = 0;
    foreach (glob(APP_ROOT . '/tests/cases/*.php') ?: [] as $case) {
        $registered += (int) preg_match_all('/^\s*test\(/m', (string) file_get_contents($case));
    }

    assert_same(
        $registered,
        readme_number('тестовых сценариев'),
        'в README заявлено другое число сценариев, чем регистрирует набор'
    );
});

test('README не отправляет к константам, которых нет', function (): void {
    $readme = (string) file_get_contents(APP_ROOT . '/README.md');

    // Типы блоков объявляет схема полей; `BlockRenderer::DEFAULTS` не
    // существует с переезда реестра, и инструкция вела в никуда.
    assert_not_contains('BlockRenderer::DEFAULTS', $readme, 'README ссылается на удалённую константу');

    foreach (['BlockFieldSchema', 'BlockTypeRegistry::BASE_DEFAULTS'] as $needle) {
        assert_contains($needle, $readme, 'README не называет место, где объявляется тип блока');
    }
});

test('BLOCKS.md называет то же число типов, что и реестр', function (): void {
    $blocks = (string) file_get_contents(APP_ROOT . '/docs/BLOCKS.md');

    assert_contains(
        'Всего типов: **' . count(BlockTypeRegistry::BASE_DEFAULTS) . '**',
        $blocks,
        'справочник блоков разошёлся с реестром типов'
    );
});

test('Документация не носит чужих локальных путей', function (): void {
    // Путь вида file:///C:/Users/…/Codex/new-chat/ в docs/DEV.md — след
    // копирования из чужой среды: по ссылке нельзя перейти ни с одной машины,
    // кроме той, где документ писали. Такая строка не ломает работу и потому
    // живёт годами, а читается как «документ никто не открывал».
    $files = array_merge(
        glob(APP_ROOT . '/*.md') ?: [],
        glob(APP_ROOT . '/docs/*.md') ?: []
    );

    $leaks = [];
    foreach ($files as $file) {
        $text = (string) file_get_contents($file);
        // CLAUDE.md пишет про пути кода проекта, а не про чужие машины,
        // поэтому ищем именно абсолютные пути домашних каталогов и дисков.
        if (preg_match('#(file:///[A-Za-z]:/|/Users/[A-Za-z]|/home/[a-z]+/|/workspace/)#', $text, $m) === 1) {
            $leaks[] = basename($file) . ' → ' . $m[1];
        }
    }

    assert_same([], $leaks, 'в документации остался локальный путь: ' . implode(', ', $leaks));
});

test('Типы блоков вне схемы полей: число только уменьшается', function (): void {
    // Переезд на BlockFieldSchema идёт постепенно, и это осознанный долг:
    // у типа без схемы настройка объявлена в четырёх местах, которые молча
    // расходятся. Пока переехали не все, направление держит храповик —
    // добавить новый тип мимо схемы нельзя.
    $budget = quality_budget('blocks_off_schema');

    assert_true(
        $budget['value'] <= $budget['ceiling'],
        'типов вне BlockFieldSchema стало больше: ' . $budget['value'] . ' > ' . $budget['ceiling']
            . ' (' . $budget['detail'] . '). Новый тип объявляется схемой.'
    );
});

test('CLAUDE.md не заводит второй копии числа сценариев', function (): void {
    // Число объявлено в README и стережётся выше. Повтор здесь пришлось бы
    // править в двух местах на каждый новый тест, а значит рано или поздно
    // одно из них отстало бы — ровно так «1293 сценария» и прожили до 1665.
    $claude = (string) file_get_contents(APP_ROOT . '/CLAUDE.md');

    assert_true(
        preg_match('/Полный прогон:?\s*\d/u', $claude) !== 1,
        'в CLAUDE.md снова записано число сценариев — оно живёт в README'
    );
});

test('CLAUDE.md называет действующий уровень PHPStan', function (): void {
    // Уровень был поднят с 3-го до 7-го, а описание CI осталось на пятом:
    // читающий решил бы, что часть находок анализатор просто не ищет.
    $config = (string) file_get_contents(APP_ROOT . '/phpstan.neon');
    assert_true(
        preg_match('/^\s*level:\s*(\d+)$/m', $config, $m) === 1,
        'уровень не найден в phpstan.neon'
    );

    $claude = (string) file_get_contents(APP_ROOT . '/CLAUDE.md');
    assert_contains(
        'уровень ' . $m[1] . ')',
        $claude,
        'в CLAUDE.md назван другой уровень PHPStan, чем в phpstan.neon'
    );
});
