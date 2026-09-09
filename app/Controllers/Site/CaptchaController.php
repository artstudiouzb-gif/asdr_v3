<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\Captcha;

/** Отдаёт PNG-картинку капчи (код кладётся в сессию посетителя). */
final class CaptchaController
{
    public function image(): void
    {
        $png = Captcha::png(Captcha::issue());

        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Content-Length: ' . strlen($png));
        echo $png;
        exit;
    }
}
