<?php

declare(strict_types=1);

use App\Core\Updater;
use App\Core\UpdateState;

/*
 * Обновление из релиза на GitHub (`scripts/update.php`, `App\Core\Updater`).
 *
 * Замена кода — самое опасное действие в CMS: ошибка здесь либо стирает
 * данные сайта, либо оставляет работать старый уязвимый обработчик, либо
 * выполняет на сервере чужой код. Каждое из трёх стережётся отдельно.
 */

/** Рекурсивно удаляет каталог без вызова внешней утилиты rm. */
function updater_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            updater_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/** Собирает дерево файлов во временном каталоге. */
function updater_tree(string $dir, array $files): string
{
    updater_rmdir($dir);
    foreach ($files as $path => $content) {
        $full = $dir . '/' . $path;
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0755, true);
        }
        file_put_contents($full, $content);
    }

    return $dir;
}

test('Обновление не трогает данные сайта', function () {
    // Конфиг с секретами, загруженные файлы и журналы обновление обязано
    // оставить как есть: их нет в архиве, и попасть в план они не должны
    // ни на замену, ни на удаление.
    foreach ([
        'config/config.php',
        'storage/logs/error.log',
        'storage/backups/backup.zip',
        'public/uploads/public/photo.jpg',
    ] as $path) {
        assert_true(Updater::isPreserved($path), 'не защищено от замены: ' . $path);
    }

    // А код — не данные, его меняем.
    foreach (['app/Core/Router.php', 'templates/blocks/text.php', 'public/index.php', 'config/config.example.php'] as $path) {
        assert_false(Updater::isPreserved($path), 'ошибочно защищено от обновления: ' . $path);
    }
});

test('План замены сохраняет данные и убирает устаревший код', function () {
    $base = sys_get_temp_dir() . '/updater-' . uniqid();
    $root = updater_tree($base . '/root', [
        'app/Core/Old.php' => 'старый класс',
        'app/Core/bootstrap.php' => 'старый',
        'public/index.php' => 'старый',
        'config/config.php' => 'СЕКРЕТЫ',
        'storage/logs/error.log' => 'журнал',
        'public/uploads/public/photo.jpg' => 'ФОТО',
    ]);
    $new = updater_tree($base . '/new', [
        'app/Core/bootstrap.php' => 'новый',
        'app/Core/Brand.php' => 'новый класс',
        'public/index.php' => 'новый',
    ]);

    try {
        $plan = Updater::plan($new, $root);

        // Устаревший файл в каталоге, которым владеет релиз, обязан уйти:
        // иначе старый обработчик продолжал бы отвечать после обновления.
        assert_true(in_array('app/Core/Old.php', $plan['delete'], true), 'устаревший файл остался бы на сервере');

        // Данные сайта не попадают ни в один список.
        $touched = array_merge($plan['copy'], $plan['delete']);
        foreach (['config/config.php', 'storage/logs/error.log', 'public/uploads/public/photo.jpg'] as $keep) {
            assert_false(in_array($keep, $touched, true), 'обновление затронуло данные сайта: ' . $keep);
        }

        Updater::apply($new, $plan, $root);
        assert_same('СЕКРЕТЫ', file_get_contents($root . '/config/config.php'));
        assert_same('ФОТО', file_get_contents($root . '/public/uploads/public/photo.jpg'));
        assert_same('новый', file_get_contents($root . '/app/Core/bootstrap.php'));
        assert_false(is_file($root . '/app/Core/Old.php'), 'устаревший файл не удалён');
        assert_true(is_file($root . '/app/Core/Brand.php'), 'новый файл не появился');
    } finally {
        updater_rmdir($base);
    }
});

test('Ставим только собранный архив релиза, и только с контрольной суммой', function () {
    $archive = ['name' => 'asdr-cms-2.0.0.zip', 'url' => 'https://github.com/x/y/releases/download/v2/asdr-cms-2.0.0.zip', 'size' => 10];
    $sum = ['name' => 'asdr-cms-2.0.0.zip.sha256', 'url' => 'https://github.com/x/y/releases/download/v2/asdr-cms-2.0.0.zip.sha256', 'size' => 1];
    $source = ['name' => 'Source code (zip)', 'url' => 'https://github.com/x/y/zipball/v2', 'size' => 10];

    assert_same('asdr-cms-2.0.0.zip', (Updater::pickAsset([$archive, $sum]) ?? ['archive' => ['name' => '']])['archive']['name']);

    // «Source code» не годится: в нём тесты, .github и composer.json — то,
    // чему на боевом сервере делать нечего. Ставить его нельзя.
    assert_same(null, Updater::pickAsset([$source]), 'принят Source code вместо собранного архива');

    // Архив без .sha256 не ставим: сверять целостность загрузки будет нечем.
    assert_same(null, Updater::pickAsset([$archive]), 'принят архив без контрольной суммы');
});

test('Контрольная сумма сверяется, а не имитируется', function () {
    $file = tempnam(sys_get_temp_dir(), 'upd');
    file_put_contents($file, 'содержимое архива');
    try {
        $real = hash_file('sha256', $file);
        assert_true(Updater::verifyChecksum($file, $real . '  asdr-cms.zip'));
        assert_false(Updater::verifyChecksum($file, str_repeat('0', 64) . '  asdr-cms.zip'), 'принята чужая сумма');
        assert_false(Updater::verifyChecksum($file, 'мусор без суммы'), 'принята строка без суммы');
    } finally {
        @unlink($file);
    }
});

test('Файлы workflow не содержат повторяющихся ключей верхнего уровня', function () {
    // Так уже ломался выпуск: правка вставила второй `on:` рядом с первым.
    // GitHub Actions такой файл отвергает целиком («'on' is already defined»),
    // то есть workflow перестаёт существовать — а заметно это только при
    // попытке его запустить. Локальная проверка через YAML-разбор поломку
    // пропускает: разборщики молча берут последний из повторяющихся ключей.
    // Поэтому сверяем строки, а не разобранное дерево.
    foreach (glob(APP_ROOT . '/.github/workflows/*.yml') ?: [] as $file) {
        $seen = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            // Ключ верхнего уровня — без отступа и не комментарий.
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_-]*):/', $line, $m) !== 1) {
                continue;
            }
            $key = $m[1];
            assert_false(
                in_array($key, $seen, true),
                'в ' . basename($file) . ' ключ «' . $key . '» объявлен дважды — GitHub отвергнет файл целиком'
            );
            $seen[] = $key;
        }
        assert_true(in_array('jobs', $seen, true), basename($file) . ': не найден раздел jobs');
    }
});

test('Пакет знает собственную версию', function () {
    // Без этого свежая установка отвечает `unknown`, а обновление считает
    // `available = (тег !== установленное)` — то есть панель вечно предлагает
    // поставить версию, которая уже стоит. `storage/` не в git, поэтому
    // `git archive` файл версии не принесёт: его кладёт сборка.
    $workflow = (string) file_get_contents(APP_ROOT . '/.github/workflows/release.yml');

    assert_contains('inject/asdr-cms/storage/release.json', $workflow, 'сборка не кладёт версию в пакет');
    assert_contains('storage/release.json \\', $workflow, 'файл версии не в списке обязательных для архива');
    // Регулярка, а не подстрока: в bash кавычки экранированы, и точное
    // совпадение зависело бы от способа их записи.
    assert_true(
        (bool) preg_match('#grep -q .*release.*\$\{release_id\}.*storage/release\.json#', $workflow),
        'содержимое файла версии не сверяется'
    );

    // Идентификатор в пакете обязан совпасть с именем тега: обновление
    // сравнивает установленную версию именно с ним, и расхождение хоть на
    // префикс `v` означало бы «доступно обновление» навсегда.
    assert_contains('release_id="${RELEASE_TAG}"', $workflow, 'версия в пакете считается мимо тега');

    // Чтение версии: переменная окружения главнее файла — так боевой сервер
    // может назвать выкладку сам, не переписывая пакет.
    $before = getenv('APP_RELEASE');
    try {
        putenv('APP_RELEASE=2.8');
        assert_same('2.8', Updater::installedVersion(), 'установленная версия не читается');
    } finally {
        if (is_string($before) && $before !== '') {
            putenv('APP_RELEASE=' . $before);
        } else {
            putenv('APP_RELEASE');
        }
    }
});

test('Релиз выходит с установочным архивом, а не без него', function () {
    // Обновлять нечем, если релиз опубликован без asdr-cms-*.zip и .sha256:
    // «Source code» ставить нельзя (в нём тесты и composer.json), архив без
    // суммы — тоже. Ровно это и случилось: сборка висела на `push: tags: v*`,
    // а теги в репозитории называются «2.4» и «2.7-final» — ни один не
    // начинается с `v`, поэтому workflow не запускался ни разу, и релизы
    // месяцами выходили пустыми. Отказ был невидимым: релиз публикуется,
    // ассетов нет, никто не узнаёт.
    $workflow = (string) file_get_contents(APP_ROOT . '/.github/workflows/release.yml');

    assert_contains('types: [published]', $workflow, 'сборка не запускается публикацией релиза');
    // Тег-триггер убран намеренно: публикация релиза через интерфейс создаёт
    // и тег, и оба события запустили бы сборку дважды на одно действие.
    assert_false(
        (bool) preg_match('/^\s+push:\s*$/m', $workflow),
        'тег-триггер вернулся — сборка будет запускаться дважды на один релиз'
    );
    // Имя тега не должно ни на что влиять: именно требование префикса `v`
    // и сломало выпуск. Проверяем ключ YAML, а не подстроку: слово «tags»
    // законно встречается в объяснении выше.
    assert_false(
        (bool) preg_match('/^\s+tags:/m', $workflow),
        'сборка снова зависит от имени тега'
    );

    // Путей два, и они не взаимозаменяемы. На событие `release` релиз уже
    // существует — им и запущена сборка, поэтому ассеты в него кладут;
    // `gh release create` там падал бы с «release already exists». А ручной
    // запуск из Actions релиза ещё не имеет — там как раз создают.
    // Проверяем шаг целиком, а не файл: обе команды в файле есть законно.
    $releaseStep = substr($workflow, (int) strpos($workflow, "if: github.event_name == 'release'"));
    $nextStep = strpos($releaseStep, "\n      - name:");
    $releaseStep = $nextStep !== false ? substr($releaseStep, 0, $nextStep) : $releaseStep;

    assert_contains('gh release upload', $releaseStep, 'архив не прикладывается к опубликованному релизу');
    assert_false(str_contains($releaseStep, 'gh release create'), 'на публикацию релиза он создаётся заново');
    // Повторный запуск после починки не должен падать на существующем файле.
    assert_contains('--clobber', $releaseStep, 'перезапуск сборки упрётся в уже загруженный архив');

    // Ручной выпуск: без него релиз нечем выпустить иначе как кнопкой в
    // интерфейсе — тег-триггера больше нет.
    assert_contains("if: github.event_name == 'workflow_dispatch' && inputs.publish", $workflow, 'релиз нельзя выпустить запуском из Actions');
    assert_contains('gh release create', $workflow, 'ручной выпуск не создаёт релиз');

    // Имена файлов должны совпадать с тем, что ищет Updater::pickAsset():
    // архив по маске asdr-cms-*.zip и сумма рядом с тем же именем.
    assert_contains('asdr-cms-${safe_name}.zip', $workflow, 'имя архива разъехалось с тем, что ищет обновление');
    assert_contains('sha256sum "${archive}" > "${archive}.sha256"', $workflow, 'сумма считается не тем именем');
});

test('Обновление ходит только на GitHub и только по https', function () {
    // Адрес приходит из ответа GitHub, но подставить туда чужой хост — значит
    // выполнить свой код на сервере. Список доменов закрытый.
    foreach ([
        'https://api.github.com/repos/a/b/releases/latest',
        'https://objects.githubusercontent.com/x',
        'https://release-assets.githubusercontent.com/x',
    ] as $good) {
        assert_true(Updater::isAllowedUrl($good), 'отклонён законный адрес: ' . $good);
    }
    foreach ([
        'http://api.github.com/x',
        'https://evil.example.com/x',
        'https://api.github.com.evil.example/x',
        'https://127.0.0.1/x',
        'file:///etc/passwd',
    ] as $bad) {
        assert_false(Updater::isAllowedUrl($bad), 'принят чужой адрес: ' . $bad);
    }
});

test('Архив не той формы к установке не допускается', function () {
    $base = sys_get_temp_dir() . '/updater-shape-' . uniqid();
    try {
        // Пустой каталог — не наш архив.
        assert_true(Updater::validateTree(updater_tree($base . '/empty', ['readme.md' => 'x'])) !== []);

        // Есть всё нужное — годен.
        $good = [
            'app/Core/bootstrap.php' => 'x',
            'public/index.php' => 'x',
            'database/schema.sql' => 'x',
            'config/config.example.php' => 'x',
        ];
        assert_same([], Updater::validateTree(updater_tree($base . '/good', $good)));

        // Боевой конфиг внутри архива — признак, что подсунули не то.
        $problems = Updater::validateTree(updater_tree($base . '/bad', $good + ['config/config.php' => 'x']));
        assert_contains('config/config.php', implode(' ', $problems));
    } finally {
        updater_rmdir($base);
    }
});

test('Репозиторий обновления берётся из окружения, а не из настроек', function () {
    // Настройка в панели дала бы редактору увести обновление на чужой
    // репозиторий — то есть выполнить свой код на сервере.
    $updater = (string) file_get_contents(APP_ROOT . '/app/Core/Updater.php');
    assert_contains("getenv('UPDATE_REPO')", $updater);
    assert_false(str_contains($updater, 'Setting::get'), 'адрес репозитория читается из БД');

    putenv('UPDATE_REPO=someone/evil repo');
    assert_same('artstudiouzb-gif/asdr_v2', Updater::repo(), 'принято мусорное имя репозитория');
    putenv('UPDATE_REPO=other-org/other-cms');
    assert_same('other-org/other-cms', Updater::repo());
    putenv('UPDATE_REPO');
});

test('Замену файлов не выполняет веб-запрос', function () {
    // Веб-запрос обрывается по таймауту, и обрыв посреди замены оставит сайт
    // с половиной старых и половиной новых файлов. Кнопка в панели поэтому
    // только ставит задачу, а работу делает CLI-воркер.
    foreach (glob(APP_ROOT . '/app/Controllers/Admin/*.php') ?: [] as $controller) {
        $source = (string) file_get_contents($controller);
        assert_false(
            str_contains($source, 'Updater::apply')
                || str_contains($source, 'Updater::download')
                || str_contains($source, 'UpdateRunner::run'),
            'замена файлов доступна из веб-запроса: ' . basename($controller)
        );
    }

    // Контроллер только пишет намерение — этим и ограничена его роль.
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/UpdateController.php');
    assert_contains('UpdateState::queue(', $controller, 'кнопка обязана ставить задачу');

    // Выполняет её отдельный CLI-воркер, а не общая очередь: у JobQueue
    // аренда строки 60 секунд, а обновление длиннее — вторая копия воркера
    // подхватила бы задачу посреди замены файлов.
    $worker = (string) file_get_contents(APP_ROOT . '/app/Console/update_worker.php');
    assert_contains('Cli::assertCli()', $worker, 'воркер запускается только из командной строки');
    assert_contains("ProcessLock::acquire('update_worker')", $worker, 'два запуска не должны совпасть');
    assert_contains('UpdateRunner::run(', $worker, 'замену делает воркер');
});

test('Без живого воркера обновление из панели не заказывается', function () {
    // Намерение записано, а выполнять его некому — нажатие выглядит как
    // «ничего не произошло». Это худший вид отказа, поэтому кнопка
    // спрашивает heartbeat воркера.
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/UpdateController.php');
    assert_contains("Heartbeat::lastRun('update')", $controller, 'свежесть воркера не проверяется');
    assert_contains("\$worker['alive']", $controller, 'заказ не зависит от живости воркера');

    // Версию выбирает не редактор: через выбор ассета в панель приехал бы
    // произвольный архив.
    assert_false(str_contains($controller, "\$_POST['release']"), 'версия приходит из формы');
    // Адрес репозитория контроллер не выбирает: он спрашивает Updater, а тот
    // читает окружение (стережёт тест выше).
    assert_contains('Updater::repo()', $controller, 'репозиторий берётся мимо Updater');
    assert_false(str_contains($controller, 'getenv('), 'контроллер читает окружение сам');
});

test('Режим обслуживания возвращается в то состояние, в каком его застали', function () {
    // Слепое «выключить в конце» открыло бы сайт, закрытый владельцем на
    // профилактику, — обновление не вправе решать это за него.
    $state = (string) file_get_contents(APP_ROOT . '/app/Core/UpdateState.php');
    assert_contains('maintenance_before', $state, 'прежнее значение не запоминается');
    assert_contains("Setting::set('maintenance_mode', \$state['maintenance_before'] === '1' ? '1' : '0')", $state);

    // Снимается он в finally — то есть и после отказа с откатом тоже.
    $runner = (string) file_get_contents(APP_ROOT . '/app/Core/UpdateRunner.php');
    assert_true(
        (bool) preg_match('/\} finally \{[^}]*UpdateState::releaseMaintenance\(\);/s', $runner),
        'после отказа сайт остался бы закрытым'
    );
});

test('Оборвавшееся обновление не оставляет сайт закрытым', function () {
    // Процесс могли убить (таймаут хостинга, OOM, перезагрузка) — снимать
    // флаг тогда некому. Спасают две вещи: воркер прибирает за прошлым
    // запуском, а публичная точка входа не показывает заглушку, если её
    // включило переставшее отчитываться обновление.
    $now = time();
    $fresh = ['status' => 'running', 'heartbeat' => $now, 'maintenance_owned' => true, 'maintenance_before' => '0'];
    $dead = ['status' => 'running', 'heartbeat' => $now - UpdateState::STALE_AFTER - 1, 'maintenance_owned' => true, 'maintenance_before' => '0'];

    assert_false(UpdateState::isStale($fresh), 'идущее обновление принято за сорвавшееся');
    assert_true(UpdateState::isStale($dead), 'молчание дольше предела не замечено');
    // Завершённое обновление сорвавшимся не бывает, сколько бы ни прошло.
    assert_false(UpdateState::isStale(['status' => 'done', 'heartbeat' => 0]), 'завершённое считается сорвавшимся');

    $worker = (string) file_get_contents(APP_ROOT . '/app/Console/update_worker.php');
    assert_contains('UpdateState::recoverStale()', $worker, 'воркер не прибирает за прошлым запуском');
    // Режимом обслуживания распоряжается бегунок. Снятие «на всякий случай»
    // в воркере отменяло бы решение recoverStale() оставить закрытым сайт,
    // собранный наполовину.
    assert_false(str_contains($worker, 'UpdateState::releaseMaintenance()'), 'воркер снимает режим обслуживания мимо бегунка');

    $entry = (string) file_get_contents(APP_ROOT . '/public/index.php');
    assert_contains('UpdateState::maintenanceStuck()', $entry, 'заглушка осталась бы висеть навсегда');
});

test('Форма состояния объявлена одинаково везде, где объявлена', function () {
    // Форма состояния расписана в трёх докблоках — read(), normalize() и
    // @var во вьюхе. Новое поле легко добавить в класс и забыть во вьюхе:
    // PHPStan тогда ругается на несуществующий ключ, а тесты молчат, потому
    // что на выводе всё работает. Здесь и сверяем.
    $keys = array_keys(UpdateState::read());
    assert_true(in_array('files_touched', $keys, true), 'состояние потеряло отметку о замене файлов');

    $sources = [
        'app/Core/UpdateState.php' => 2,      // read() и normalize()
        'app/Views/admin/update/index.php' => 1, // @var $state
    ];
    foreach ($sources as $file => $expected) {
        $text = (string) file_get_contents(APP_ROOT . '/' . $file);
        // Докблок формы узнаём по последнему полю: оно замыкает объявление.
        $found = substr_count($text, 'maintenance_before:string');
        assert_same($expected, $found, 'объявлений формы в ' . $file . ' стало другое число');
        foreach ($keys as $key) {
            assert_true(
                substr_count($text, $key . ':') >= $expected,
                'поле ' . $key . ' не объявлено во всех формах состояния в ' . $file
            );
        }
    }
});

test('Новый заказ не наследует чужой режим обслуживания', function () {
    // Обновление запоминает, каким застало режим обслуживания, и возвращает
    // именно это. Если прошлая попытка оборвалась и оставила сайт закрытым,
    // новое обновление сочло бы закрытый сайт нормой и вернуло бы его
    // закрытым после успешной установки — навсегда.
    $state = (string) file_get_contents(APP_ROOT . '/app/Core/UpdateState.php');
    assert_true(
        (bool) preg_match('/function queue\([^)]*\): void\s*\{.*?self::releaseMaintenance\(\);.*?self::write\(/s', $state),
        'заказ не отдаёт режим обслуживания прошлой попытки'
    );
});

test('Сайт открывается сам только тогда, когда файлы целы', function () {
    // Обрыв до замены оставляет сайт рабочим — открываем. Обрыв во время
    // замены оставляет половину старых файлов и половину новых: открытый,
    // такой сайт отдаёт 500 всем подряд, а закрытый — честные 503.
    $runner = (string) file_get_contents(APP_ROOT . '/app/Core/UpdateRunner.php');
    assert_true(
        (bool) preg_match('/UpdateState::markFilesTouched\(\);\s*\n\s*\$applied = Updater::apply\(/', $runner),
        'отметка ставится не вплотную к замене файлов'
    );

    $state = (string) file_get_contents(APP_ROOT . '/app/Core/UpdateState.php');
    // Именно отрицание: сайт открывается сам, только если файлы не трогали.
    assert_true(
        (bool) preg_match('/maintenanceStuck\(\): bool.*?!\$state\[.files_touched.\]/s', $state),
        'сайт открылся бы после обрыва посреди замены файлов'
    );
});

test('Опасная последовательность одна на панель и на консоль', function () {
    // Две копии разъедутся при первой правке: проверка суммы появится в
    // одной, снимок для отката — в другой.
    $script = (string) file_get_contents(APP_ROOT . '/scripts/update.php');
    assert_contains('UpdateRunner::run(', $script, 'скрипт несёт свою копию замены');
    assert_contains('UpdateRunner::preview(', $script, 'пробный прогон считает план сам');
    assert_false(str_contains($script, 'Updater::apply('), 'замена продублирована в скрипте');

    // Миграции — в том же процессе: запуск дочернего процесса на
    // shared-хостинге часто запрещён, и обновление обрывалось бы сразу
    // после замены файлов — в самом неудачном месте.
    $runner = (string) file_get_contents(APP_ROOT . '/app/Core/UpdateRunner.php');
    assert_contains('MigrationRunner::applyPending(', $runner);
    assert_false(str_contains($runner, 'passthru('), 'бегунок зовёт внешний процесс');
    assert_false(str_contains($runner, "exec('rm -rf"), 'бегунок зовёт внешнюю команду');
});
