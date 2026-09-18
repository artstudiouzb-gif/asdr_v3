<?php

declare(strict_types=1);

/**
 * Публичная часть переводится словарём RU→UZ. Строка, которую забыли внести,
 * ничего не ломает — она просто выходит по-русски на узбекской версии, и
 * заметить это можно только глазами. Тест закрывает этот пробел.
 */

test('Все строки t() публичных шаблонов есть в узбекском словаре', function () {
    $dict = require APP_ROOT . '/app/Core/lang/uz.php';
    $missing = [];

    // Системные страницы (404/500/503/обслуживание) тоже видит посетитель, а
    // под проверку не попадали вовсе: пропуск в них выходил бы по-русски.
    foreach (['templates', 'app/Views/site', 'app/Views/errors'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/' . $dir));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            // Только строковые литералы: t($var) проверить статически нельзя.
            // tf() — перевод с подстановкой; его ключ так же обязан быть в
            // словаре, иначе подпись формы выйдет по-русски на /uz.
            if (preg_match_all("/\\btf?\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/u", $code, $matches) === 0) {
                continue;
            }
            foreach ($matches[1] as $key) {
                $key = str_replace(["\\'", '\\\\'], ["'", '\\'], $key);
                if ($key !== '' && !array_key_exists($key, $dict)) {
                    $missing[] = str_replace(APP_ROOT . '/', '', $file->getPathname()) . ' → ' . $key;
                }
            }
        }
    }

    assert_same([], array_values(array_unique($missing)), 'строка публичной части без перевода на узбекский');

    // Эти места прежде обходили t(): PHP/JS показывали русский литерал даже
    // на /uz и /en. Держим короткий сторож именно на пользовательском выводе.
    foreach ([
        'app/Core/Captcha.php' => ['>Код с картинки<', '>Введите символы с картинки (регистр не важен).<'],
        'templates/widgets/latest_news.php' => ['>Нет новостей.</li>'],
        'templates/widgets/projects_list.php' => ['>Нет проектов.</li>'],
        'templates/widgets/team_list.php' => ['>Список пуст.</li>'],
        'public/assets/js/consent.js' => ["textContent = 'Мы используем cookie", "textContent = 'Принять'"],
        'public/assets/js/forms.js' => ["textContent = 'Отправка", "showTopMessage(form, 'Сетевая ошибка"],
        'public/assets/js/news-share-gallery.js' => ["return 'Поделиться всеми фото'"],
        // Своя таблица языков рядом со словарём знает фиксированный набор
        // языков, и редактор переводов в админке её не видит.
        'app/Views/errors/404.php' => ["'ru' => ['Страница не найдена"],
    ] as $path => $needles) {
        $source = (string) file_get_contents(APP_ROOT . '/' . $path);
        foreach ($needles as $needle) {
            assert_not_contains($needle, $source, "публичная фраза снова захардкожена: {$path}");
        }
    }
});

test('Ключи словаря — русские: узбекский текст ключом даёт узбекский на RU-версии', function () {
    $dict = require APP_ROOT . '/app/Core/lang/uz.php';
    $suspicious = [];

    foreach (array_keys($dict) as $key) {
        // Фраза из нескольких слов без единой кириллической буквы почти
        // наверняка написана на узбекском. t() отдаёт ключ как есть, поэтому
        // такой текст увидел бы и русский посетитель. Отдельные слова не в
        // счёт: имена вроде «Telegram» одинаковы во всех языках.
        if (preg_match('/\p{Cyrillic}/u', (string) $key)) {
            continue;
        }
        if (preg_match_all('/\p{L}+/u', (string) $key) >= 3) {
            $suspicious[] = (string) $key;
        }
    }

    assert_same([], $suspicious, 'ключ словаря должен быть русской строкой');
});

test('Негодный перевод с подстановкой не роняет страницу, а откатывается на исходную строку', function () {
    // Формат приезжает из словаря, а словарь правит редактор в админке:
    // sprintf(t('…%s…')) превращал лишний «%» в переводе в ArgumentCountError,
    // то есть в 500 на отправке публичной формы. Замерено: «(100%)» падает.
    $key = 'Поле «%s» обязательно.';
    assert_same('Поле «Имя» обязательно.', tf($key, 'Имя'));

    foreach (['«%s» maydoni majburiy (100%).', '«%s» / «%s» majburiy.'] as $broken) {
        $threw = false;
        try {
            sprintf($broken, 'Имя');
        } catch (\Throwable $e) {
            $threw = true;
        }
        assert_true($threw, 'проверка бессмысленна, если такой формат больше не падает: ' . $broken);
    }

    // Перевод без подстановки законен — значение просто не показывается.
    assert_same('Maydon majburiy.', tf('Maydon majburiy.'));

    // Голый sprintf(t(…)) возвращать нельзя: он и есть та самая мина.
    foreach (['app/Controllers/Site', 'app/Core', 'app/Views/site', 'templates'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/' . $dir));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            // Комментарии снимаем: приём назван текстом там, где объясняется,
            // почему его нельзя писать, — и список исключений тут не нужен.
            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }
            assert_false(
                (bool) preg_match('/(?:v?s?printf)\(\s*(?:Lang::)?t\(/u', $code),
                'перевод с подстановкой пишется через tf(): ' . str_replace(APP_ROOT . '/', '', $file->getPathname())
            );
        }
    }
});
