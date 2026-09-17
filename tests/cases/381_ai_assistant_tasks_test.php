<?php

declare(strict_types=1);

use App\Core\Ai\AiClient;
use App\Core\Ai\AiPageDraft;
use App\Core\BlockTypeRegistry;
use App\Core\Database;

/*
 * Помощник редактора: задачи, которые делает модель.
 *
 * ИИ был ровно один — кнопка анонса в форме новости, — и вызов к API лежал
 * прямо в ней вместе с забытым переводчиком: две копии одного разговора с
 * Gemini, уже разошедшиеся моделями и таймаутами. Задач стало больше (alt-текст
 * снимка, вычитка, рубрикация, SEO страницы, разбор записи журнала, каркас
 * страницы), и цена копии выросла соответственно.
 *
 * Поэтому здесь проверяется не «умно ли отвечает модель» — этого тестом не
 * узнать, — а границы, которые делают её вызовы безопасными: один транспорт,
 * оговорка о происхождении материала, доступ и CSRF у каждой задачи, и полное
 * отсутствие ИИ на публичном рендере.
 */

function ai_source(string $path): string
{
    return (string) file_get_contents(APP_ROOT . '/' . $path);
}

/** @return list<string> */
function ai_php_files(string $dir): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(APP_ROOT . '/' . $dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (str_ends_with($path, '.php')) {
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

test('Обращение к модели описано в одном месте', function (): void {
    // Пока вызов жил в сервисе новостей, рядом успел появиться второй —
    // переводчик со своей моделью и своим таймаутом, который не писал исход в
    // «Состояние системы». Новая задача обязана брать клиента, а не копировать
    // запрос.
    $callers = [];
    foreach (ai_php_files('app') as $path) {
        if (str_contains((string) file_get_contents($path), 'generativelanguage.googleapis.com')) {
            $callers[] = str_replace(APP_ROOT . '/', '', $path);
        }
    }

    assert_same(['app/Core/Ai/AiClient.php'], $callers, 'адрес API назван вне клиента');
});

test('Присланный материал объявлен данными, а не инструкциями', function (): void {
    $client = ai_source('app/Core/Ai/AiClient.php');

    assert_true(AiClient::DATA_RULE !== '', 'оговорка объявлена');
    // Оговорка добавляется клиентом ко всем задачам сразу: забытая строка в
    // одной из них — дыра, о которой узнают последними.
    assert_contains("trim(\$system) . ' ' . self::DATA_RULE", $client, 'оговорка добавляется к системной части запроса');
});

test('Каждая задача ИИ — POST с проверкой доступа и CSRF', function (): void {
    $routes = ai_source('public/index.php');
    $controller = ai_source('app/Controllers/Admin/AiController.php');

    preg_match_all('/public function (\w+)\(\): void/', $controller, $methods);
    $tasks = $methods[1];
    assert_true(count($tasks) >= 5, 'задач в контроллере: ' . count($tasks));

    // Начало у всех одно: доступ, CSRF, ограничитель частоты и заголовок
    // ответа. Задача, начинающаяся иначе, — это забытая проверка.
    assert_same(
        count($tasks),
        substr_count($controller, '$this->begin('),
        'каждая задача начинается общей проверкой доступа'
    );
    assert_contains('Csrf::verifyRequest();', $controller, 'CSRF проверяется');
    assert_contains('RateLimiter::throttle(', $controller, 'частота обращений ограничена');

    // Разбор записи журнала — супер-админу: журнал называет пути на диске,
    // имена таблиц и внутренние сообщения.
    assert_contains('$this->begin(true);', $controller, 'разбор журнала закрыт супер-админом');
    assert_contains('Auth::requireSuperAdmin();', $controller, 'строгий доступ существует');

    foreach (['alt', 'seo', 'review', 'classify', 'explain', 'page-draft'] as $task) {
        assert_contains("\$router->post('/admin/ai/" . $task . "'", $routes, 'маршрут задачи ' . $task);
        assert_not_contains("\$router->get('/admin/ai/" . $task . "'", $routes, 'задача ' . $task . ' не доступна по ссылке');
    }
});

test('Кнопка в разметке зовёт существующую задачу', function (): void {
    // Кнопка без маршрута — самый тихий отказ: она есть, нажимается и молча
    // ничего не делает.
    $routes = ai_source('public/index.php');
    $tasks = [];
    foreach (ai_php_files('app/Views/admin') as $path) {
        preg_match_all('/data-ai-task="([a-z-]+)"/', (string) file_get_contents($path), $found);
        foreach ($found[1] as $task) {
            $tasks[$task] = true;
        }
    }
    preg_match_all('/data-ai-task\', \'([a-z-]+)\'\)/', ai_source('public/assets/js/admin.js'), $fromJs);
    foreach ($fromJs[1] as $task) {
        $tasks[$task] = true;
    }

    assert_true($tasks !== [], 'кнопки задач в разметке есть');
    foreach (array_keys($tasks) as $task) {
        assert_contains("\$router->post('/admin/ai/" . $task . "'", $routes, 'у кнопки «' . $task . '» есть маршрут');
    }
});

test('Публичный рендер про ИИ не знает', function (): void {
    // Страница в сеть не ходит вовсе (тест 110), и ИИ — самый дорогой способ
    // это правило нарушить: ответ модели измеряется секундами.
    $offenders = [];
    foreach (['app/Views/site', 'app/Controllers/Site', 'templates'] as $dir) {
        foreach (ai_php_files($dir) as $path) {
            $source = (string) file_get_contents($path);
            if (str_contains($source, 'App\\Core\\Ai') || str_contains($source, 'AiClient')) {
                $offenders[] = str_replace(APP_ROOT . '/', '', $path);
            }
        }
    }

    assert_same([], $offenders, 'ИИ зовут из публичного вывода: ' . implode(', ', $offenders));
});

test('Каркас страницы предлагает только существующие и самодостаточные блоки', function (): void {
    foreach (AiPageDraft::TYPES as $type) {
        assert_true(BlockTypeRegistry::has($type), 'тип ' . $type . ' есть в реестре');
        assert_false(BlockTypeRegistry::isContainer($type), 'контейнер ' . $type . ' не предлагается: его содержимое — отдельные блоки');
    }

    // «HTML» — чужой код, и его принимает только супер-админ; обложка-запись
    // хранит ссылку на запись, которой у каркаса нет.
    assert_false(in_array('html', AiPageDraft::TYPES, true), 'блок сырого HTML моделью не предлагается');
    assert_false(in_array('hero', AiPageDraft::TYPES, true), 'обложка-запись моделью не предлагается');
});

test('Каркас проверяется тем же разбором, что и присланный файл', function (): void {
    $draft = ai_source('app/Core/Ai/AiPageDraft.php');

    // Ответ модели — такой же внешний источник, как чужая выгрузка: свой
    // список проверок разъехался бы с нормализатором при первой правке.
    assert_contains('PageTemplateFile::parse(', $draft, 'ответ модели проходит разбор шаблона');
    assert_contains('PageTemplateFile::export(', $draft, 'конверт собирается общим кодом');
});

test('Пакетную подпись изображений ведёт тот же бегунок, что и остальные проходы', function (): void {
    $runner = ai_source('public/assets/js/admin-media-batch.js');
    $view = ai_source('app/Views/admin/performance/index.php');
    $routes = ai_source('public/index.php');

    assert_contains("scopeName === 'alt'", $runner, 'бегунок знает про подписи');
    assert_contains('data-batch-task="alt"', $view, 'кнопки прохода есть в разделе');
    assert_contains('data-endpoint="/admin/performance/alt-texts"', $view, 'кнопка знает адрес прохода');
    assert_contains("\$router->post('/admin/performance/alt-texts'", $routes, 'маршрут прохода объявлен');
});

test('Без ключа задача отвечает объяснением, а не поломкой', function (): void {
    if (!Database::isConnected()) {
        skip_test('TEST_DB_* не заданы');
    }

    // Ключ в тестовой базе не настроен, поэтому это ровно тот случай, который
    // видит владелец свежей установки: кнопка обязана сказать словами, что
    // интеграция не настроена, а не исчезнуть и не упасть.
    assert_false(AiClient::configured(), 'в тестовой среде ключ не задан');

    $alt = \App\Core\Ai\AiAltText::forPath(APP_ROOT . '/public/assets/img/emblem.svg');
    assert_same('', $alt['alt'], 'без ключа подписи нет');
    assert_true($alt['notice'] !== '', 'причина названа словами');

    $review = \App\Core\Ai\AiEditor::review('Заголовок', 'Лид', 'Текст материала.');
    assert_same([], $review['remarks'], 'без ключа замечаний нет');
    assert_true($review['notice'] !== '', 'вычитка объясняет, почему не сработала');

    // А локальный разбор новости работает и без модели: кнопка анонса была
    // такой с самого начала, и терять это при переезде на общего клиента
    // нельзя.
    $fields = \App\Core\AiAssistantService::generateField('Заголовок новости', 'Первое предложение. Второе предложение текста.', 'summary');
    assert_true($fields['excerpt'] !== '', 'локальный анонс собран');
    assert_same('local', $fields['provider'], 'источник назван честно');
});
