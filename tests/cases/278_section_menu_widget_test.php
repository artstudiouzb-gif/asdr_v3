<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\SectionMenu;
use App\Core\WidgetRenderer;
use App\Models\MenuItem;
use App\Models\Widget;

/*
 * Виджет «Меню раздела»: страницы, соседние открытой.
 *
 * В шапке видно только название раздела, а внутри «Об Агентстве» десяток
 * страниц: без бокового меню читатель не видит ни где он, ни что рядом.
 *
 * Своего дерева разделов у виджета нет — это те же `menu_items`, что и в шапке:
 * второй список разъехался бы с меню при первой правке. По той же причине
 * правило подсветки текущего пункта одно на оба места.
 */

test('Подсветка ловит вложенный адрес, «вы здесь» — только точный', function () {
    assert_true(SectionMenu::isUrlActive('/about', '/about/history'), 'раздел подсвечен на своей странице');
    assert_false(SectionMenu::isCurrentPath('/about', '/about/history'), 'но текущей страницей не является');
    assert_true(SectionMenu::isCurrentPath('/about/', '/about'), 'завершающий слэш ничего не меняет');
});

test('Текущим считается сам адрес и вложенный, но не корень сайта', function () {
    assert_true(SectionMenu::isUrlActive('/about', '/about'));
    assert_true(SectionMenu::isUrlActive('/about/', '/about'), 'завершающий слэш ничего не меняет');
    assert_true(SectionMenu::isUrlActive('/about', '/about/history'), 'страница внутри раздела подсвечивает раздел');
    assert_false(SectionMenu::isUrlActive('/about', '/about-us'), 'совпадение по началу строки — не вложенность');
    // Корень сайта и корни языков — префикс для всего, они подсвечивали бы
    // каждый пункт меню на любой странице.
    assert_false(SectionMenu::isUrlActive('/', '/news'));
    assert_false(SectionMenu::isUrlActive('/uz', '/uz/news'));
    assert_true(SectionMenu::isUrlActive('/uz/about', '/uz/about/history'));
});

test('Шапка подсвечивает пункты по тому же правилу, что и боковое меню', function () {
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');

    assert_contains('SectionMenu::isUrlActive($targetUrl, $currentReqPath)', $header);
    assert_not_contains('str_starts_with($currentReqPath', $header, 'второй копии правила быть не должно');
});

test('Виджет зарегистрирован и не выводит пустую рамку', function () {
    assert_true(in_array('section_menu', Widget::TYPES, true));
    assert_true(isset(Widget::TYPE_LABELS['section_menu']));
    assert_true(is_file(APP_ROOT . '/templates/widgets/section_menu.php'));

    $renderer = (string) file_get_contents(APP_ROOT . '/app/Core/WidgetRenderer.php');
    // Виджету на странице вне разделов показывать нечего: рамка с одним
    // заголовком читается как поломка.
    assert_contains("if (trim(\$inner) === '') {", $renderer);

    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/widgets/form.php');
    assert_contains('data-wtype="section_menu"', $form, 'у типа есть своя подсказка в форме');
});

test('Ветка меню находится по открытой странице (БД)', function () {
    ensure_test_db();
    Database::pdo()->exec('DELETE FROM menu_items');

    $about = MenuItem::create([
        'lang' => 'ru', 'title' => 'Об Агентстве', 'url_type' => 'custom',
        'url_value' => '/about', 'is_active' => 1,
    ]);
    MenuItem::create([
        'lang' => 'ru', 'title' => 'История', 'url_type' => 'custom',
        'url_value' => '/about/history', 'parent_id' => $about, 'is_active' => 1,
    ]);
    MenuItem::create([
        'lang' => 'ru', 'title' => 'Черновик', 'url_type' => 'custom',
        'url_value' => '/about/draft', 'parent_id' => $about, 'is_active' => 0,
    ]);
    $press = MenuItem::create([
        'lang' => 'ru', 'title' => 'Пресс-служба', 'url_type' => 'custom',
        'url_value' => '/press', 'is_active' => 1,
    ]);
    MenuItem::create([
        'lang' => 'ru', 'title' => 'Новости', 'url_type' => 'custom',
        'url_value' => '/press/news', 'parent_id' => $press, 'is_active' => 1,
    ]);

    $branch = SectionMenu::branch('ru', '/about/history');
    assert_true($branch !== null, 'страница внутри раздела обязана найти свою ветку');
    assert_same('Об Агентстве', $branch['title']);
    assert_same(1, count($branch['items']), 'выключенный пункт в меню раздела не попадает');
    assert_same('История', $branch['items'][0]['title']);
    assert_true($branch['items'][0]['active'], 'открытая страница отмечена');
    assert_true($branch['items'][0]['current'], 'она же — «вы здесь» для диктора');
    assert_true($branch['active'], 'ветка подсвечена целиком');
    assert_false($branch['current'], 'но текущей страницей заголовок ветки не является');

    // Раздел, открытый по собственному адресу: отмечен сам заголовок.
    $root = SectionMenu::branch('ru', '/press');
    assert_true($root !== null);
    assert_same('Пресс-служба', $root['title']);
    assert_true($root['active']);
    assert_true($root['current'], 'на собственном адресе раздела «вы здесь» — заголовок');
    assert_false($root['items'][0]['current'], 'вложенная страница текущей не становится');

    // Страница вне разделов ветки не имеет — виджет не выводится.
    assert_same(null, SectionMenu::branch('ru', '/contacts'));

    Database::pdo()->exec('DELETE FROM menu_items');
});

test('Разметка виджета отмечает текущую страницу не только цветом', function () {
    $tpl = (string) file_get_contents(APP_ROOT . '/templates/widgets/section_menu.php');
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/frontend.css');

    assert_contains('aria-current="page"', $tpl, 'диктор обязан узнать текущую страницу');
    assert_contains('<nav class="widget-secmenu"', $tpl);
    assert_contains('.widget-secmenu__link.is-active {', $css);
    assert_contains('border-left-color:', $css, 'в чёрно-белом режиме цвета мало');
    assert_contains('font-weight: 700;', $css);

    // Умолчания виджета объявлены, иначе шаблон получит пустой $data.
    $renderer = (string) file_get_contents(APP_ROOT . '/app/Core/WidgetRenderer.php');
    assert_contains("'section_menu' => []", $renderer);
    assert_contains('SectionMenu::branch($lang, SectionMenu::currentPath())', $renderer);
    assert_true(class_exists(WidgetRenderer::class));
});
