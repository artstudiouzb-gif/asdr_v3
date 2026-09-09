<?php

declare(strict_types=1);

/**
 * Отказ `json_encode()` нельзя прятать.
 *
 * Функция объявлена как `string|false`, и `false` она отдаёт на негодных
 * данных — чаще всего на битой кодировке в тексте, который набрал редактор.
 * Дальше расходятся два способа спрятать этот отказ, и оба уже случались:
 *
 *  - результат уходит типизированным параметром (`Setting::set(string)`,
 *    `htmlspecialchars(string)`) — под `strict_types` это `TypeError` без
 *    единого слова о причине. Так падало сохранение пресетов «Дизайна»,
 *    конструкторов шапки и подвала;
 *  - результат приводится `(string)`, и `false` превращается в **пустую
 *    строку**. Это хуже: ошибки нет вовсе. Сторож молча забыл бы, о чём уже
 *    сообщал, а подписи к фотографиям новости исчезли бы все разом — вместе с
 *    `JSON.parse` в скрипте, которому досталась пустая строка.
 *
 * Решение зависит от происхождения данных, и это единственная развилка:
 * **свои данные — бросаем** (`JSON_THROW_ON_ERROR`), потому что записать
 * состояние неверно опаснее, чем не записать; **редакторские — подставляем
 * замену** (`JSON_INVALID_UTF8_SUBSTITUTE`), потому что ронять публичную
 * страницу или закрывать конструктор из-за одного байта нельзя.
 */

/*
 * Сборщик вызовов (`json_encode_call_sites`) и признак защищённости
 * (`json_encode_guarded`) объявлены в tests/budgets.php: по ним считает бюджет,
 * и отчёт `php .claude/skills/quality-budgets/report.php` обязан их видеть —
 * он загружает реестр, а не файлы набора.
 */

test('Число незащищённых json_encode с приведением только уменьшается', function (): void {
    // Оставшиеся — служебные данные, где битой кодировке взяться неоткуда:
    // адреса Cloudflare, claims Web Push, состояние ограничителя частоты.
    // Отдельный случай — `Integrity`: там json_encode считает хеш эталона,
    // и смена флагов сдвинула бы все контрольные суммы. Поэтому здесь не ноль,
    // а бюджет: он может только уменьшаться, как и остальные в проекте.
    $budget = quality_budget('json_encode_unguarded');

    assert_true(
        $budget['value'] <= $budget['ceiling'],
        'незащищённых json_encode с приведением стало больше: ' . $budget['value']
            . ' > ' . $budget['ceiling'] . '. Данные редактора — JSON_INVALID_UTF8_SUBSTITUTE, '
            . 'свои — JSON_THROW_ON_ERROR. Где: ' . $budget['detail']
    );
});

test('json_encode в Setting::set бросает вместо TypeError', function (): void {
    // Настройка — свои данные: записать её неверно опаснее, чем не записать.
    $bad = [];
    foreach (json_encode_call_sites() as $site) {
        if (!str_contains($site['expr'], 'json_encode')) {
            continue;
        }

        $src = (string) file_get_contents(APP_ROOT . '/' . $site['file']);
        $before = substr($src, 0, strpos($src, $site['expr']) ?: 0);
        // Вызов внутри Setting::set(...) — ищем открытый вызов слева.
        $tail = substr($before, -260);
        if (!str_contains($tail, 'Setting::set(')) {
            continue;
        }
        if (!str_contains($site['expr'], 'JSON_THROW_ON_ERROR')) {
            $bad[] = $site['file'] . ':' . $site['line'];
        }
    }

    assert_same([], $bad, 'json_encode уходит в Setting::set без JSON_THROW_ON_ERROR: ' . implode(', ', $bad));
});

test('Вывод редакторского JSON в разметку переживает битую кодировку', function (): void {
    // Здесь бросать нельзя: публичная страница и конструктор обязаны
    // открыться. Замена символа оставляет строку читаемой, а запасное
    // значение не даёт скрипту получить пустой атрибут.
    $views = [
        'app/Views/site/news_show.php' => 'подписи к фотографиям новости',
        'app/Views/admin/header/index.php' => 'подписи элементов конструктора шапки',
    ];

    foreach ($views as $file => $what) {
        $src = (string) file_get_contents(APP_ROOT . '/' . $file);
        assert_contains('JSON_INVALID_UTF8_SUBSTITUTE', $src, $what . ': нет замены негодного байта');
        assert_contains("?: '[]'", $src, $what . ': нет запасного значения для JSON.parse');
    }
});

test('Флаги json_encode делают то, ради чего поставлены', function (): void {
    // Проверка самого механизма, а не только его наличия в коде: если бы
    // JSON_INVALID_UTF8_SUBSTITUTE не спасал от `false`, все правила выше
    // были бы карго-культом.
    $broken = ["label" => "тест\xB1\x31\xB2"];

    assert_same(false, json_encode($broken, JSON_UNESCAPED_UNICODE), 'битая кодировка обязана давать false');
    assert_true(
        is_string(json_encode($broken, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)),
        'с заменой символа json_encode обязана вернуть строку'
    );

    $threw = false;
    try {
        json_encode($broken, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        $threw = true;
    }
    assert_true($threw, 'JSON_THROW_ON_ERROR обязан бросать JsonException');
});
