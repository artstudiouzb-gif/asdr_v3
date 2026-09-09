<?php

declare(strict_types=1);

/*
 * Обновление сайта из релиза на GitHub.
 *
 *   php scripts/update.php --check                # что стоит и что доступно
 *   php scripts/update.php --dry-run              # показать план замены
 *   php scripts/update.php --yes https://site.uz  # обновить и проверить
 *
 * Порядок жёсткий и прерывается на первой же неудаче: сначала полная
 * резервная копия, потом загрузка и сверка суммы, потом замена файлов, и
 * только затем миграции и проверки. Если замена сорвалась на середине —
 * откатываемся из снимка, снятого перед ней.
 *
 * Замену файлов делает не этот скрипт, а общий `App\Core\UpdateRunner`: то же
 * самое умеет кнопка в панели (`/admin/update`), и две копии опасной
 * последовательности разъехались бы при первой правке. Скрипт остаётся ради
 * случая, когда панель недоступна — сайт лежит, а обновиться надо.
 *
 * В веб-запросе замена по-прежнему не выполняется никогда: запрос обрывается
 * по таймауту и оставил бы сайт с половиной старых и половиной новых файлов.
 * Кнопка в панели только ставит задачу, а выполняет её из командной строки
 * `app/Console/update_worker.php`.
 */

require __DIR__ . '/../app/Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\UpdateRunner;
use App\Core\Updater;
use App\Core\UpdateState;

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$checkOnly = in_array('--check', $args, true);
$dryRun = in_array('--dry-run', $args, true);
$assumeYes = in_array('--yes', $args, true);
$baseUrl = '';
foreach ($args as $arg) {
    if (str_starts_with($arg, 'http://') || str_starts_with($arg, 'https://')) {
        $baseUrl = rtrim($arg, '/');
    }
}

function updateFail(string $message): never
{
    fwrite(STDERR, 'Обновление остановлено: ' . $message . PHP_EOL);
    exit(1);
}

// --- 1. Что стоит и что доступно ------------------------------------------

$state = Updater::check();
fwrite(STDOUT, 'Репозиторий:      ' . Updater::repo() . PHP_EOL);
fwrite(STDOUT, 'Установлено:      ' . $state['installed'] . PHP_EOL);

if (!($state['ok'] ?? false)) {
    updateFail((string) ($state['error'] ?? 'не удалось узнать последний релиз.'));
}

fwrite(STDOUT, 'Последний релиз:  ' . $state['latest']
    . ($state['published_at'] !== '' ? ' (' . $state['published_at'] . ')' : '') . PHP_EOL);

if (!$state['available']) {
    fwrite(STDOUT, 'Установлена последняя версия — обновлять нечего.' . PHP_EOL);
    exit(0);
}
if (!$state['installable']) {
    updateFail((string) $state['reason']);
}

fwrite(STDOUT, 'Доступно обновление: ' . $state['installed'] . ' → ' . $state['latest'] . PHP_EOL);
if ($checkOnly) {
    exit(0);
}

// --- 2. План замены (для показа и пробного прогона) -------------------------

if ($dryRun) {
    fwrite(STDOUT, PHP_EOL . '== Пробный прогон ==' . PHP_EOL);
    try {
        $plan = UpdateRunner::preview($state, static function (string $line): void {
            fwrite(STDOUT, '  ' . $line . PHP_EOL);
        });
    } catch (\Throwable $e) {
        updateFail($e->getMessage());
    }
    fwrite(STDOUT, 'Заменить файлов: ' . count($plan['copy']) . PHP_EOL);
    fwrite(STDOUT, 'Удалить устаревших: ' . count($plan['delete']) . PHP_EOL);
    foreach (array_slice($plan['delete'], 0, 20) as $gone) {
        fwrite(STDOUT, '  - ' . $gone . PHP_EOL);
    }
    if (count($plan['delete']) > 20) {
        fwrite(STDOUT, '  … и ещё ' . (count($plan['delete']) - 20) . PHP_EOL);
    }
    fwrite(STDOUT, 'Данные сайта (config/config.php, storage, public/uploads) не затрагиваются.' . PHP_EOL);
    fwrite(STDOUT, PHP_EOL . 'Ничего не менялось.' . PHP_EOL);
    exit(0);
}

if (!$assumeYes) {
    fwrite(STDOUT, PHP_EOL . 'Обновить ' . $state['installed'] . ' → ' . $state['latest'] . '? Введите "да": ');
    $answer = trim((string) fgets(STDIN));
    if (mb_strtolower($answer) !== 'да') {
        fwrite(STDOUT, 'Отменено.' . PHP_EOL);
        exit(0);
    }
}

// --- 3. Обновление ---------------------------------------------------------
//
// Вся опасная часть — в UpdateRunner: загрузка, сверка суммы, проверка
// состава, резервная копия, снимок для отката, режим обслуживания, замена,
// миграции, эталон целостности, кэш. Здесь остаётся только показ хода.

fwrite(STDOUT, PHP_EOL . '== Обновление ==' . PHP_EOL);
// Отмечаемся в общем состоянии и отсюда: панель показывает ход любого
// обновления, а по тем же отметкам времени сорвавшееся отличается от идущего
// (см. UpdateState) — ручной запуск не должен быть исключением.
UpdateState::queue((string) $state['latest'], 'консоль');
UpdateState::markRunning();
try {
    $result = UpdateRunner::run($state, static function (string $line): void {
        fwrite(STDOUT, '  ' . $line . PHP_EOL);
    });
} catch (\Throwable $e) {
    UpdateState::finish(UpdateState::STATUS_FAILED, $e->getMessage());
    updateFail($e->getMessage());
}
UpdateState::finish(UpdateState::STATUS_DONE);
fwrite(STDOUT, 'Заменено ' . $result['copied'] . ', удалено ' . $result['deleted']
    . ', миграций ' . $result['migrations'] . '.' . PHP_EOL);

// --- 4. Проверки после обновления ------------------------------------------

function updateRun(string $label, string $script, array $arguments = []): void
{
    fwrite(STDOUT, PHP_EOL . '== ' . $label . ' ==' . PHP_EOL);
    $parts = array_merge([PHP_BINARY, $script], $arguments);
    passthru(implode(' ', array_map('escapeshellarg', $parts)), $code);
    if ($code !== 0) {
        fwrite(STDERR, 'Шаг «' . $label . '» завершился с кодом ' . $code . '.' . PHP_EOL);
        exit($code);
    }
}

updateRun('Проверка окружения', $root . '/scripts/release_check.php');
if ($baseUrl !== '') {
    updateRun('Smoke-обход сайта', $root . '/scripts/smoke.php', [$baseUrl, '--expect-release', (string) $state['latest']]);
}

fwrite(STDOUT, PHP_EOL . 'Обновлено до ' . $state['latest'] . '.' . PHP_EOL);
if ($baseUrl === '') {
    fwrite(STDOUT, 'Smoke-обход пропущен: адрес сайта не указан. Запустите php scripts/smoke.php https://ваш-домен' . PHP_EOL);
}
exit(0);
