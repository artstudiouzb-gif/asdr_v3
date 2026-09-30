<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Редирект как значение, а не `exit`. Бросает `Redirect::to()`, ловит роутер
 * (`Router::invoke`) и превращает в заголовок. Контроллер, дошедший до
 * редиректа, заканчивает работу так же, как прежде, но тест может поймать
 * исключение и проверить, куда его увели, — `exit` оборвал бы весь прогон.
 *
 * Наследует `\Exception`, а не `RuntimeException`: адресный `catch
 * (\RuntimeException)` встречается в контроллерах чаще общего и его не
 * заденет. Но код, ловящий `\Throwable` или `\Exception` вокруг
 * редиректа, его проглотит: при переводе контроллера такие блоки
 * просматриваются (или ловят `RedirectException` отдельно и пробрасывают).
 */
final class RedirectException extends \Exception
{
    public function __construct(public readonly string $url, public readonly int $status = 302)
    {
        parent::__construct('Redirect to ' . $url);
    }

    /** Строка заголовка: перевод строки в адресе означал бы подстановку своих заголовков. */
    public function headerLine(): string
    {
        return 'Location: ' . str_replace(["\r", "\n", "\0"], '', $this->url);
    }
}
