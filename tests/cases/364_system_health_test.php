<?php

declare(strict_types=1);

use App\Core\IntegrationStatus;
use App\Core\SystemHealth;
use App\Models\Setting;

/*
 * Раздел «Состояние системы» и память интеграций.
 *
 * Механизмы самопроверки были написаны давно и работали: сторож видел молчащие
 * воркеры, сверка целостности — подмену файлов, репетиция восстановления —
 * негодный бэкап. Жили они в CLI и cron на shared-хостинге, куда владелец не
 * заходит, поэтому в системе, где тихий отказ считается худшим видом отказа,
 * молча отказывали сами проверки: `release_check` годами повторял
 * «восстановление ни разу не проверялось», и не читал этого никто.
 *
 * Раздел ничего не считает заново — он делает уже посчитанное видимым.
 */

test('Успех не стирает последний отказ, а отказ — последний успех', function (): void {
    // Иначе одна удачная попытка затирала бы след суточного простоя («всё
    // работает» — а публикации за сутки не ушли), а одна неудача прятала бы
    // то, что связь была живой десять минут назад.
    Setting::set('integration_status_telegram', '');

    IntegrationStatus::fail('telegram', 'chat not found', 'публикация');
    $row = integration_row('telegram');
    assert_true($row['fail_at'] !== null, 'отказ записан');
    assert_same('chat not found', $row['error'], 'причина отказа сохранена как есть');
    assert_true($row['ok_at'] === null, 'успеха ещё не было');

    IntegrationStatus::ok('telegram', 'публикация');
    $row = integration_row('telegram');
    assert_true($row['ok_at'] !== null, 'успех записан');
    assert_true($row['fail_at'] !== null, 'прежний отказ обязан пережить успех');
    assert_same('chat not found', $row['error'], 'текст прежнего отказа не стирается');

    Setting::set('integration_status_telegram', '');
});

test('Интеграция, не сработавшая ни разу, видна отдельно', function (): void {
    // Это и есть тихий отказ, ради которого раздел заводится: без строки в
    // списке ненастроенная интеграция выглядит так же, как исправная.
    foreach (array_keys(IntegrationStatus::KNOWN) as $name) {
        Setting::set('integration_status_' . $name, '');
    }

    $rows = IntegrationStatus::all();
    assert_same(count(IntegrationStatus::KNOWN), count($rows), 'показываются все известные интеграции');
    foreach ($rows as $row) {
        assert_false($row['used'], 'без записей интеграция помечена как не использованная');
        assert_true($row['title'] !== '', 'у интеграции есть человеческое название');
    }
});

test('Каждая известная интеграция где-то записывает свой исход', function (): void {
    // Строка на экране без вызова в коде — обещание наблюдения, которого нет:
    // такая интеграция навсегда осталась бы «ни разу не использованной», что
    // неотличимо от «не настроена». Ровно тот случай, ради которого настройка
    // блока объявляется один раз в схеме.
    $sources = '';
    foreach (glob(APP_ROOT . '/app/Core/*.php') ?: [] as $file) {
        $sources .= (string) file_get_contents($file);
    }

    // Telegram — единственный, кого записывают не по литералу: публикатор
    // соцсетей зовёт запись с именем сети в переменной, и одна строка кода
    // покрывает все сети сразу. Поэтому у него проверяется сам механизм, а не
    // текст вызова; послабления «где-нибудь есть похожая строка» здесь нет —
    // иначе новый ключ в KNOWN проходил бы за записанный, ничего не записывая.
    $social = (string) file_get_contents(APP_ROOT . '/app/Core/SocialPublisher.php');

    foreach (array_keys(IntegrationStatus::KNOWN) as $name) {
        if ($name === 'telegram') {
            assert_contains('isset(IntegrationStatus::KNOWN[$network])', $social, 'публикатор не записывает исход');
            assert_contains("'telegram' => \$this->telegram(", $social, 'telegram не среди сетей публикатора');
            continue;
        }

        $recorded = str_contains($sources, "IntegrationStatus::ok('" . $name . "'")
            || str_contains($sources, "IntegrationStatus::fail('" . $name . "'")
            || str_contains($sources, "IntegrationStatus::record('" . $name . "'");
        assert_true($recorded, 'интеграция ' . $name . ' объявлена, но исход её вызовов никто не записывает');
    }
});

test('Витрина состояния в сеть не ходит', function (): void {
    // Страницу открывают в тревоге, и она обязана ответить сразу: полдесятка
    // запросов к чужим API растянули бы её на десятки секунд, а на упавшем
    // канале связи — до таймаута. Всё сетевое приходит из памяти.
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/SystemHealth.php');
    foreach (['Http::', 'curl_init', 'file_get_contents(\'http', 'fsockopen', 'dns_get_record'] as $call) {
        assert_not_contains($call, $source, 'проверка состояния обязана читать память, а не ходить в сеть');
    }
});

test('Факт состояния называет и ответ, и что с ним делать', function (): void {
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/SystemHealth.php');

    // Три состояния миграций вместо двух: «файлов нет вовсе» выглядело как
    // «неприменённых нет» — панель перечисляет файлы с диска и о недоехавшем
    // файле знать не может.
    assert_contains('файлов миграций на сервере нет', $source, 'слепой угол миграций обязан называться');
    assert_contains('база новее выложенного кода', $source, 'база, знающая больше файлов, — отдельный случай');

    // Способ выкладки: git-ветка и архив релиза на одном сервере не смешивают,
    // и путаница уже случалась.
    assert_contains('выложено из ветки deploy', $source, 'способ выкладки обязан быть назван');

    assert_same('меньше минуты', SystemHealth::age(30), 'возраст пишется словами');
    assert_same('2 ч', SystemHealth::age(7200));
    assert_same('3 дн', SystemHealth::age(3 * 86400));
});

test('Раздел объявлен в панели целиком: маршрут, пункт меню и иконка', function (): void {
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    assert_contains("'/admin/health'", $routes, 'маршрут раздела');

    $nav = (string) file_get_contents(APP_ROOT . '/app/Views/admin/layout/header.php');
    assert_contains("'health' => ['/admin/health'", $nav, 'пункт меню');

    // Неизвестный ключ Icon::render отдаёт пустой строкой, и пункт молча
    // остаётся без иконки — так было у 19 разделов из 30 (тест 248).
    $ui = (string) file_get_contents(APP_ROOT . '/app/Core/AdminUi.php');
    assert_contains("'health' => 'heartbeat'", $ui, 'иконка раздела');

    // Раздел называет версии, пути и причины отказов — это не для редактора.
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/HealthController.php');
    assert_contains('requireSuperAdmin', $controller, 'раздел доступен только супер-админу');
});

/** @return array{ok_at:?int, fail_at:?int, error:string} */
function integration_row(string $name): array
{
    foreach (IntegrationStatus::all() as $row) {
        if ($row['name'] === $name) {
            return ['ok_at' => $row['ok_at'], 'fail_at' => $row['fail_at'], 'error' => $row['error']];
        }
    }

    return ['ok_at' => null, 'fail_at' => null, 'error' => ''];
}
