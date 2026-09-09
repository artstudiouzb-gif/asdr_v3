<?php

declare(strict_types=1);

use App\Core\TranslationGroupHelper;
use App\Models\Page;

test('Админка: группы переводов не дублируют строки в списке и выводят кликабельные языковые баджи', function (): void {
    ensure_test_db();

    TranslationGroupHelper::ensureSchema();

    // Создаём тестовую первичную страницу RU
    $origId = Page::create([
        'title' => 'Тестовая страница RU',
        'slug' => 'test-page-ru',
        'meta_title' => null,
        'meta_description' => null,
        'lead' => null,
        'layout_type' => 'no_sidebar',
        'status' => 'published',
        'is_home' => 0,
        'hide_chrome' => 0,
        'transparent_header' => 0,
        'lang' => 'ru',
    ]);

    // Переводим на UZ
    $uzId = TranslationGroupHelper::createTranslation('pages', $origId, 'uz');
    assert_same(
        $uzId,
        TranslationGroupHelper::createTranslation('pages', $origId, 'uz'),
        'Повторный запрос с исходной записи возвращает уже созданный перевод'
    );
    assert_same(
        $uzId,
        TranslationGroupHelper::createTranslation('pages', $uzId, 'uz'),
        'Повторный запрос с языковой версии не создаёт дубль'
    );

    // Проверяем список без фильтра по языку (общий стандарт: выводит все записи)
    $adminList = Page::adminList(['lang' => '', 'status' => '', 'q' => 'test-page-ru', 'sort' => 'newest', 'per_page' => 20, 'offset' => 0]);
    
    $idsInList = array_map(static fn(array $item): int => (int) $item['id'], $adminList);
    assert_true(in_array($origId, $idsInList, true), 'Первичная запись присутствует в списке');
    assert_true(in_array($uzId, $idsInList, true), 'Перевод также присутствует в общем списке Все языки');

    // Фильтр конкретного языка возвращает только запись этого языка
    $uzOnly = Page::adminList(['lang' => 'uz', 'status' => '', 'q' => 'test-page-ru', 'sort' => 'newest', 'per_page' => 20, 'offset' => 0]);
    $uzOnlyIds = array_map(static fn(array $item): int => (int) $item['id'], $uzOnly);
    assert_true(in_array($uzId, $uzOnlyIds, true), 'UZ-фильтр показывает самостоятельную UZ-запись');
    assert_false(in_array($origId, $uzOnlyIds, true), 'UZ-фильтр не подмешивает RU-запись той же группы');

    // Проверяем карту языков
    $langList = Page::availableLangsForIds([$origId]);
    assert_true(in_array('ru', $langList[$origId], true), 'В списке языков есть RU');
    assert_true(in_array('uz', $langList[$origId], true), 'В списке языков есть UZ');

    $langMap = Page::availableLangsForIds([$origId], true);
    assert_true(isset($langMap[$origId]['ru']), 'Есть бадж RU');
    assert_true(isset($langMap[$origId]['uz']), 'Есть бадж UZ');
    assert_same($uzId, $langMap[$origId]['uz'], 'Бадж UZ ведет на ID перевода #uzId');

    // Очистка
    Page::forceDelete($uzId);
    Page::forceDelete($origId);
});

test('Создание перевода страницы не смешивает RU- и UZ-блоки из старого общего стека', function (): void {
    ensure_test_db();

    TranslationGroupHelper::ensureSchema();
    $pdo = \App\Core\Database::pdo();
    $slug = 'legacy-mixed-page-' . bin2hex(random_bytes(3));
    $origId = Page::create([
        'title' => 'Старая смешанная страница',
        'slug' => $slug,
        'status' => 'published',
        'lang' => 'ru',
    ]);

    $insertBlock = $pdo->prepare(
        "INSERT INTO blocks
            (page_id, parent_block_id, lang, type, title, data, sort_order, is_active, created_at)
         VALUES (:page_id, :parent_block_id, :lang, 'text', :title, :data, :sort_order, 1, NOW())"
    );
    $insertBlock->execute([
        ':page_id' => $origId,
        ':parent_block_id' => null,
        ':lang' => 'ru',
        ':title' => 'RU-блок',
        ':data' => '{"content":"RU"}',
        ':sort_order' => 1,
    ]);
    $insertBlock->execute([
        ':page_id' => $origId,
        ':parent_block_id' => null,
        ':lang' => 'uz',
        ':title' => 'UZ-блок',
        ':data' => '{"content":"UZ"}',
        ':sort_order' => 1,
    ]);
    $uzParentId = (int) $pdo->lastInsertId();
    $insertBlock->execute([
        ':page_id' => $origId,
        ':parent_block_id' => $uzParentId,
        ':lang' => 'ru',
        ':title' => 'Ошибочный RU-дочерний блок',
        ':data' => '{"content":"RU child"}',
        ':sort_order' => 1,
    ]);
    $insertBlock->execute([
        ':page_id' => $origId,
        ':parent_block_id' => $uzParentId,
        ':lang' => 'uz',
        ':title' => 'UZ-дочерний блок',
        ':data' => '{"content":"UZ child"}',
        ':sort_order' => 2,
    ]);

    $pdo->prepare('UPDATE pages SET translation_group_id = 0 WHERE id = :id')
        ->execute([':id' => $origId]);
    $uzId = TranslationGroupHelper::createTranslation('pages', $origId, 'uz');
    $targetBlocks = $pdo->prepare(
        'SELECT lang, title FROM blocks WHERE page_id = :page_id ORDER BY id'
    );
    $targetBlocks->execute([':page_id' => $uzId]);
    $copied = $targetBlocks->fetchAll(PDO::FETCH_ASSOC);

    assert_same(2, count($copied), 'В новую языковую страницу скопирован только целевой стек');
    assert_same('uz', (string) $copied[0]['lang'], 'Скопированный блок получил целевой язык');
    assert_same('UZ-блок', (string) $copied[0]['title'], 'Выбран существующий UZ-блок, а не RU-блок');
    assert_same('uz', (string) $copied[1]['lang'], 'Дочерний блок получил целевой язык');
    assert_same('UZ-дочерний блок', (string) $copied[1]['title'], 'RU-дочерний блок отфильтрован');
    assert_same(
        $origId,
        (int) Page::findById($origId)['translation_group_id'],
        'Нулевая группа исходной страницы восстановлена'
    );
    assert_same(
        $origId,
        (int) Page::findById($uzId)['translation_group_id'],
        'Перевод привязан к восстановленной группе'
    );

    Page::forceDelete($uzId);
    Page::forceDelete($origId);
});

test('Связывание переводов: findHome("uz") возвращает переведённую страницу и autoLink связывает неручные записи', function (): void {
    ensure_test_db();

    TranslationGroupHelper::ensureSchema();

    // Создаём главную страницу RU
    $homeRuId = Page::create([
        'title' => 'Тестовая главная',
        'slug' => 'translation-home',
        'meta_title' => null,
        'meta_description' => null,
        'status' => 'published',
        'is_home' => 1,
        'lang' => 'ru',
    ]);

    // Создаём перевод UZ
    $homeUzId = TranslationGroupHelper::createTranslation('pages', $homeRuId, 'uz');
    Page::update($homeUzId, [
        'title' => 'Тестовая главная (UZ)',
        'slug' => 'translation-home-uz',
        'meta_title' => null,
        'meta_description' => null,
        'status' => 'published',
        // Перевод главной сам объявляется главной для своего языка. Без этого
        // тест зависел от порядка запуска: демо-данные из соседнего файла
        // оставляют собственную узбекскую главную, и findHome('uz') находил
        // её раньше — прямое совпадение по is_home идёт в приоритете над
        // поиском через группу переводов. Сохранение с is_home снимает признак
        // у прочих узбекских страниц, поэтому состояние становится однозначным.
        'is_home' => 1,
        'lang' => 'uz',
    ]);

    // 1. Проверяем findHome('uz')
    $foundUz = Page::findHome('uz');
    assert_true($foundUz !== null, 'Найдена переведённая главная страница для uz');
    assert_same($homeUzId, (int) $foundUz['id'], 'findHome("uz") вернул именно страницу перевода #homeUzId');
    assert_same(1, (int) $foundUz['is_home'], 'Перевод главной сохраняет контекст главной и не выводит хлебные крошки');
    assert_true(in_array('uz', Page::availableLangs($homeRuId), true), 'RU-версия видит опубликованный UZ-перевод');
    assert_true(in_array('uz', Page::availableLangs($homeUzId), true), 'UZ-версия считается доступным переводом');

    // 2. Симулируем некорректную отвязанную запись (translation_group_id = id)
    \App\Core\Database::pdo()
        ->prepare('UPDATE pages SET translation_group_id = id WHERE id = :id')
        ->execute([':id' => $homeUzId]);
    
    // Вызываем автосвязывание
    TranslationGroupHelper::autoLinkStandaloneTranslations();

    $reloadedUz = Page::findById($homeUzId);
    assert_same($homeRuId, (int) $reloadedUz['translation_group_id'], 'Автосвязывание привязало отвязанную запись к первичной главной странице');

    Page::forceDelete($homeUzId);
    Page::forceDelete($homeRuId);
});

test('Единый slug (contacts, about, home) поддерживает одинаковое имя на разных языках', function (): void {
    ensure_test_db();

    TranslationGroupHelper::ensureSchema();

    // Создаём страницу "Контакты" RU со слагом "contacts"
    $ruPageId = Page::create([
        'title' => 'Контакты (RU)',
        'slug' => 'contacts',
        'meta_title' => null,
        'meta_description' => null,
        'status' => 'published',
        'lang' => 'ru',
    ]);

    // Создаём перевод "Контакты" UZ — он должен получить тот же самый slug "contacts"
    $uzPageId = TranslationGroupHelper::createTranslation('pages', $ruPageId, 'uz');
    Page::update($uzPageId, [
        'title' => 'Aloqa (UZ)',
        'slug' => 'contacts',
        'meta_title' => null,
        'meta_description' => null,
        'status' => 'published',
        'lang' => 'uz',
    ]);

    // Проверяем, что обе страницы имеют slug "contacts", но разный язык
    $ruPage = Page::findById($ruPageId);
    $uzPage = Page::findById($uzPageId);

    assert_same('contacts', $ruPage['slug'], 'Русская страница имеет slug = contacts');
    assert_same('contacts', $uzPage['slug'], 'Узбекская страница имеет ТОТ ЖЕ slug = contacts');

    // Проверяем резолв по findBySlug
    $resolvedRu = Page::findBySlug('contacts', 'ru');
    $resolvedUz = Page::findBySlug('contacts', 'uz');

    assert_same($ruPageId, (int) $resolvedRu['id'], 'findBySlug("contacts", "ru") вернул русскую страницу');
    assert_same($uzPageId, (int) $resolvedUz['id'], 'findBySlug("contacts", "uz") вернул узбекскую страницу');

    Page::forceDelete($uzPageId);
    Page::forceDelete($ruPageId);
});
