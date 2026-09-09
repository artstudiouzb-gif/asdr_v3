<?php

declare(strict_types=1);

use App\Core\Locale;
use App\Core\TranslationGroupHelper;

/**
 * Адреса языковых версий. У связанной записи-перевода свой slug, поэтому
 * общий путь `/uz/news/{русский-слаг}` отвечал редиректом: поисковику это
 * лишний шаг в hreflang, читателю — лишний запрос при смене языка.
 */

test('Пути языковых версий берутся из связанных записей (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $suffix = uniqid();

    $ruId = \App\Models\News::create([
        'title' => 'Русский заголовок', 'slug' => 'alt-ru-' . $suffix, 'excerpt' => 'Анонс',
        'content' => 'текст', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'), 'lang' => 'ru',
    ]);
    $uzId = \App\Models\News::create([
        'title' => 'Uzbek sarlavha', 'slug' => 'alt-uz-' . $suffix, 'excerpt' => 'Anons',
        'content' => 'matn', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'), 'lang' => 'uz',
    ]);
    $pdo->prepare('UPDATE news SET translation_group_id = :g WHERE id IN (:a, :b)')
        ->execute([':g' => $ruId, ':a' => $ruId, ':b' => $uzId]);

    $paths = TranslationGroupHelper::publishedPaths('news', $ruId, 'news/');
    assert_same('news/alt-ru-' . $suffix, $paths['ru'] ?? '');
    assert_same('news/alt-uz-' . $suffix, $paths['uz'] ?? '');

    // Черновик языковой версии в hreflang не объявляем: страницы ещё нет.
    $pdo->prepare("UPDATE news SET status = 'draft' WHERE id = :id")->execute([':id' => $uzId]);
    assert_false(isset(TranslationGroupHelper::publishedPaths('news', $ruId, 'news/')['uz']));
});

test('Locale отдаёт путь языковой версии, иначе — путь запроса', function () {
    Locale::setPath('/news/ru-slug');
    Locale::setAlternatePaths(['uz' => 'news/uz-slug', 'en' => '']);

    assert_same('news/uz-slug', Locale::alternatePath('uz'));
    // Пустой путь — не путь: такие записи пропускаем и остаёмся на текущем.
    assert_same('/news/ru-slug', Locale::alternatePath('en'));
    assert_same('/news/ru-slug', Locale::alternatePath('kk'));

    Locale::setAlternatePaths([]);
    assert_same('/news/ru-slug', Locale::alternatePath('uz'));
});

test('Шапка строит hreflang и переключатель по путям языковых версий', function () {
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');

    assert_contains('Locale::url(Locale::alternatePath((string) $hrefLang[\'code\'])', $header);
    // Путь языковой версии берётся из alternatePath — но только когда версия
    // на этом языке есть (см. тест ниже), поэтому через промежуточный $langPath.
    assert_contains('Locale::alternatePath($code)', $header);
    assert_contains('Locale::url($langPath, $code)', $header);
    // Прежний способ — путь текущего запроса под чужим префиксом — не должен
    // вернуться: он и давал редирект.
    assert_not_contains('Locale::url(Locale::path(), (string) $hrefLang[\'code\'])', $header);
});

test('Главная объявляет корневые адреса, а не slug страницы', function () {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/PageController.php');

    // Признак главной приходит от маршрута: у языковой версии-записи своего
    // флага is_home может не быть, а живёт она всё равно в корне языка.
    assert_contains('$this->renderPage($page, $lang, true)', $controller);
    assert_contains("\$isHome || !empty(\$page['is_home'])", $controller);
    assert_contains("array_fill_keys(\$langs, '/')", $controller);

    // Корневой путь под префиксом языка даёт «/uz», а не «/uz/home».
    Locale::setPath('/');
    Locale::setAlternatePaths(['ru' => '/', 'uz' => '/']);
    assert_same('/uz', Locale::url(Locale::alternatePath('uz'), 'uz'));
    assert_same('/', Locale::url(Locale::alternatePath('ru'), 'ru'));
    Locale::setAlternatePaths([]);
});

test('Переключатель языков не ведёт на несуществующую языковую версию', function () {
    // Новости и проекты переводятся отдельной записью: «тот же путь под другим
    // префиксом» у них не существует, и откат alternatePath на текущий путь
    // давал в переключателе ссылку на 404. Материал, которого нет на выбранном
    // языке, ведёт на главную этого языка — контроллеры объявляют реальный
    // список языков через Locale::setContentLangs.
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');

    assert_contains('$codeHasContent = $contentLangs === null || in_array($code, $contentLangs, true)', $header);
    assert_contains("\$langPath = \$codeHasContent ? Locale::alternatePath(\$code) : ''", $header);
    // Кириллический пункт «Ўзбекча» строится из того же пути, что и латиница:
    // отдельная сборка снова повела бы на несуществующий адрес.
    assert_contains("Locale::url(\$langPath, 'uz')", $header);
    assert_false(
        str_contains($header, "Locale::url(Locale::alternatePath('uz'), 'uz')"),
        'кириллический пункт собирает путь мимо проверки существования'
    );
});
