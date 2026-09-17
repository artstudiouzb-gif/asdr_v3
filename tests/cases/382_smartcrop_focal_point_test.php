<?php

declare(strict_types=1);

use App\Core\Media;
use App\Core\SmartCrop;

/*
 * Фокальная точка кадра: пропорция снимка решает, что останется в кадре при
 * `object-fit: cover`. Приём существовал, но держался на `$_SERVER
 * ['DOCUMENT_ROOT']` — своя пара «путь плюс getimagesize» в SmartCrop собирала
 * несуществующий путь (`paths.public_uploads` уже оканчивается на `/public`, а
 * остаток URL после `/uploads/` с него же и начинается). В вебе спасала
 * запасная ветка, в CLI переменная пуста — и любая фотография молча получала
 * умолчание.
 */

test('Фокальная точка не зависит от DOCUMENT_ROOT', function () {
    $dir = APP_ROOT . '/public/uploads/public/_focal_test';
    @mkdir($dir, 0775, true);

    $shapes = ['wide' => [1600, 900], 'square' => [1000, 1000], 'portrait' => [800, 1200]];
    foreach ($shapes as $name => [$w, $h]) {
        $im = imagecreatetruecolor($w, $h);
        imagejpeg($im, $dir . '/' . $name . '.jpg', 70);
        unset($im);
    }

    $forget = static function (): void {
        foreach ([[SmartCrop::class, 'cache'], [Media::class, 'sizeMemo']] as [$class, $prop]) {
            (new ReflectionProperty($class, $prop))->setValue(null, []);
        }
    };

    $read = static function () use ($shapes, $forget): array {
        $forget();
        $out = [];
        foreach (array_keys($shapes) as $name) {
            $out[$name] = SmartCrop::focalPosition('/uploads/public/_focal_test/' . $name . '.jpg');
        }

        return $out;
    };

    $saved = $_SERVER['DOCUMENT_ROOT'] ?? null;

    try {
        // Так выглядит консольный проход: переменной веб-сервера нет вовсе.
        unset($_SERVER['DOCUMENT_ROOT']);
        $cli = $read();

        $_SERVER['DOCUMENT_ROOT'] = APP_ROOT . '/public';
        $web = $read();
    } finally {
        if ($saved === null) {
            unset($_SERVER['DOCUMENT_ROOT']);
        } else {
            $_SERVER['DOCUMENT_ROOT'] = $saved;
        }
        foreach (array_keys($shapes) as $name) {
            @unlink($dir . '/' . $name . '.jpg');
        }
        @rmdir($dir);
        $forget();
    }

    assert_same($web, $cli, 'в CLI и в вебе фокальная точка обязана совпадать');

    // Пропорция обязана что-то решать: если все три ответа одинаковы, значит
    // файл не нашёлся и приём снова молча выключен — ровно прежний дефект.
    assert_same(SmartCrop::DEFAULT_POSITION, $cli['wide'], 'альбомный кадр — верхняя треть');
    assert_same('50% 38%', $cli['square'], 'квадрат ниже альбомного');
    assert_same('50% 25%', $cli['portrait'], 'портрет выше остальных');
    assert_same(3, count(array_unique($cli)), 'три пропорции — три разных ответа');
});

test('Размеры картинки спрашиваются у одного источника', function () {
    // Вторая копия «URL → файл на диске» разъедется с первой при первой правке
    // путей: отображение объявлено парой настроек в конфиге и разрешается в
    // Media. SmartCrop обязан спрашивать размеры там же, откуда их берёт
    // picture() для width/height, — иначе фокальная точка и
    // зарезервированный бокс посчитаны от разных чисел.
    // Смотрим на КОД, а не на файл целиком: докблок объясняет снятый приём и
    // называет и `getimagesize`, и `DOCUMENT_ROOT` — по тексту объяснения
    // проверка запрещала бы описывать причину правки.
    $code = php_strip_whitespace(APP_ROOT . '/app/Core/SmartCrop.php');

    assert_contains('Media::dimensions(', $code, 'размеры приходят из общего источника');
    assert_true(!str_contains($code, 'getimagesize'), 'своего чтения файла в SmartCrop нет');
    assert_true(!str_contains($code, 'DOCUMENT_ROOT'), 'резолвация не зависит от переменной веб-сервера');
    assert_true(!str_contains($code, 'public_uploads'), 'своей копии отображения путей нет');

    // Память в пределах запроса: один и тот же адрес спрашивают дважды на
    // каждую картинку (picture() и focalPosition()), а дисковый кэш это
    // filemtime, md5 и чтение файла.
    $media = php_strip_whitespace(APP_ROOT . '/app/Core/Media.php');
    assert_contains('private static array $sizeMemo = [];', $media, 'ответ dimensions() запоминается');
    assert_contains('array_key_exists($url, self::$sizeMemo)', $media, 'память читается до дискового кэша');
});
