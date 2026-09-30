<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Точка входа к присланным данным запроса: `Input::post()->str('title')`.
 * Сами правила приведения — в `InputBag`, чтобы их можно было проверить без
 * суперглобалов.
 */
final class Input
{
    public static function post(): InputBag
    {
        return new InputBag($_POST);
    }

    public static function query(): InputBag
    {
        return new InputBag($_GET);
    }
}
