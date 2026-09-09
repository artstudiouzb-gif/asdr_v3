<?php

declare(strict_types=1);

/*
 * Проверяет фирменную эмблему из «Дизайна» и, если попросить, чинит её.
 *
 *   php scripts/check_emblem.php            # что не так с текущим файлом
 *   php scripts/check_emblem.php --fix      # достроить viewBox по размерам
 *
 * Эмблема выводится CSS-маской (--gov-emblem), а маска берёт от файла только
 * форму. Поэтому «файл загружен и сохранён» и «знак виден на сайте» — разные
 * вещи: без viewBox SVG нечем масштабировать, а файл, не разобравшийся при
 * загрузке, лежит на диске пустой заглушкой 1×1. Снаружи оба случая выглядят
 * одинаково — эмблемы просто нет.
 */

require __DIR__ . '/../app/Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Emblem;
use App\Core\Uploader;
use App\Models\Setting;

$fix = in_array('--fix', $argv, true);

$url = trim((string) Setting::get('design_emblem', ''));
if ($url === '') {
    echo 'Своя эмблема не задана — сайт рисует встроенный знак (public/assets/img/emblem.svg).' . PHP_EOL;
    exit(0);
}

echo 'Настройка design_emblem: ' . $url . PHP_EOL;
$path = Emblem::pathFor($url);
echo 'Файл на диске: ' . ($path ?? '— адрес не наш') . PHP_EOL;
if ($path !== null && is_file($path)) {
    echo 'Размер файла: ' . filesize($path) . ' байт' . PHP_EOL;
}

$verdict = Emblem::check($url);
if ($verdict['ok']) {
    echo 'Эмблема годится трафаретом — если знака не видно, сбросьте кэш страниц.' . PHP_EOL;
    exit(0);
}

echo 'Проблема: ' . $verdict['error'] . PHP_EOL;

if (!$fix || $path === null || !is_file($path)) {
    echo 'Запустите с --fix, чтобы достроить viewBox по размерам SVG (если они есть).' . PHP_EOL;
    exit(1);
}

$repaired = Uploader::sanitizeSvgString((string) file_get_contents($path));
if (!Emblem::checkSvg($repaired)['ok']) {
    echo 'Починить не вышло: в файле нет размеров, из которых можно собрать viewBox.' . PHP_EOL;
    echo 'Пересохраните эмблему из редактора («Обычный SVG», с viewBox) и загрузите заново.' . PHP_EOL;
    exit(1);
}

file_put_contents($path, $repaired);
@chmod($path, 0644);
echo 'Готово: viewBox достроен. Сбросьте кэш страниц в админке.' . PHP_EOL;
exit(0);
