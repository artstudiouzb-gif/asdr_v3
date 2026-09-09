<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Политика сложности паролей администраторов. Проверяет длину, минимальное
 * разнообразие символов и сверяет пароль с локальным словарём топ-10000
 * наиболее распространённых/скомпрометированных паролей (без внешних API).
 *
 * Словарь загружается лениво и кешируется в статике; поиск — по хеш-множеству
 * O(1). Файл: database/data/weak-passwords.txt (нижний регистр, по строке).
 */
final class PasswordPolicy
{
    private const MIN_LENGTH = 10;

    /** @var array<string, true>|null */
    private static ?array $weak = null;

    /**
     * Возвращает список ошибок (пустой массив — пароль допустим).
     *
     * @param array<int, string> $personal значения (логин, email), которые
     *        не должны встречаться в пароле.
     * @return array<int, string>
     */
    public static function validate(string $password, array $personal = []): array
    {
        $errors = [];

        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            $errors[] = 'Пароль должен быть не короче ' . self::MIN_LENGTH . ' символов.';
        }
        if ($length > 200) {
            $errors[] = 'Пароль слишком длинный (максимум 200 символов).';
        }

        // Разнообразие: минимум две категории из четырёх (буквы разного
        // регистра, цифры, спецсимволы) — препятствует «aaaaaaaaaa».
        $classes = 0;
        $classes += preg_match('/[a-zа-яё]/u', $password) ? 1 : 0;
        $classes += preg_match('/[A-ZА-ЯЁ]/u', $password) ? 1 : 0;
        $classes += preg_match('/\d/', $password) ? 1 : 0;
        $classes += preg_match('/[^\p{L}\p{N}]/u', $password) ? 1 : 0;
        if ($classes < 2) {
            $errors[] = 'Пароль должен содержать минимум две группы символов: буквы, цифры или спецсимволы.';
        }

        // Личные данные в пароле.
        $lower = mb_strtolower($password);
        foreach ($personal as $piece) {
            $piece = mb_strtolower(trim($piece));
            if ($piece !== '' && mb_strlen($piece) >= 4 && str_contains($lower, $piece)) {
                $errors[] = 'Пароль не должен содержать ваш логин или email.';
                break;
            }
        }

        if (self::isCompromised($password)) {
            $errors[] = 'Этот пароль входит в список часто используемых и скомпрометированных. Выберите другой.';
        }

        return $errors;
    }

    public static function isValid(string $password, array $personal = []): bool
    {
        return self::validate($password, $personal) === [];
    }

    /** Пароль встречается в локальном словаре слабых паролей. */
    public static function isCompromised(string $password): bool
    {
        $dict = self::loadDictionary();
        return isset($dict[mb_strtolower($password)]);
    }

    /** @return array<string, true> */
    private static function loadDictionary(): array
    {
        if (self::$weak !== null) {
            return self::$weak;
        }

        self::$weak = [];
        $file = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/database/data/weak-passwords.txt';
        if (is_file($file)) {
            $handle = @fopen($file, 'rb');
            if ($handle !== false) {
                while (($line = fgets($handle)) !== false) {
                    $word = rtrim($line, "\r\n");
                    if ($word !== '') {
                        self::$weak[$word] = true;
                    }
                }
                fclose($handle);
            }
        }

        return self::$weak;
    }
}
