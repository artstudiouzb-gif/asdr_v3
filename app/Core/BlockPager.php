<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Постраничный вывод внутри блока страницы-конструктора.
 *
 * Блок живёт на обычной странице, поэтому номер страницы приходит отдельным
 * параметром адреса (`?mpage=2`), а не общим `page`: иначе полоса страниц
 * блока конфликтовала бы с пагинацией самого раздела, если страница назначена
 * шапкой ленты новостей.
 *
 * Ссылка полосы ведёт на якорь блока — без него переход на вторую страницу
 * выбрасывал бы читателя в начало длинной страницы, к чужому содержимому.
 *
 * Номер ограничен сверху (MAX_PAGE): он участвует в решении о кэше страницы,
 * и обходчик с `?mpage=100000` не должен превращаться в бесконечную работу.
 */
final class BlockPager
{
    public const PARAM = 'mpage';

    /**
     * Вкладка, к которой относится номер страницы.
     *
     * У смешанного источника («Видео» + «Фото») два списка разной длины, и
     * одна полоса страниц на оба показывала неправду: на вкладке «Фото»
     * стояли страницы видео, а переход по ним не менял ни одной карточки.
     * Номер страницы принадлежит вкладке, поэтому вместе с ним в адресе
     * едет и её имя; у второй вкладки при этом остаётся её первая страница.
     */
    public const TAB_PARAM = 'mtab';

    public const MAX_PAGE = 200;

    /** Запрошенная страница блока: 1, если параметра нет или он мусорный. */
    public static function current(): int
    {
        $raw = $_GET[self::PARAM] ?? null;
        if (!is_string($raw) && !is_int($raw)) {
            return 1;
        }
        $page = (int) $raw;

        return max(1, min(self::MAX_PAGE, $page));
    }

    /**
     * Запрошенная вкладка блока. Неизвестное значение и отсутствие параметра
     * дают первую из доступных: подделанный адрес не должен приводить к
     * вкладке, которой у блока нет.
     *
     * @param list<string> $allowed
     */
    public static function currentTab(array $allowed): string
    {
        $fallback = (string) ($allowed[0] ?? '');
        $raw = $_GET[self::TAB_PARAM] ?? null;
        if (!is_string($raw)) {
            return $fallback;
        }
        $tab = strtolower(trim($raw));

        return in_array($tab, $allowed, true) ? $tab : $fallback;
    }

    /**
     * В адресе назван раздел блока. Ответ отличается от общего даже на первой
     * странице (активна другая вкладка), поэтому такой запрос не берётся из
     * общего кэша страницы и не кладётся в него.
     */
    public static function tabRequested(): bool
    {
        return isset($_GET[self::TAB_PARAM]);
    }

    /** Адрес страницы блока: текущий путь + вкладка с номером + якорь блока. */
    public static function url(int $page, int $blockId, string $tab = ''): string
    {
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        // Путь пришёл из запроса: «//example.com/…» дало бы ссылку на чужой
        // домен прямо в полосе страниц.
        if (!UrlGuard::isSafeLink($path) || !str_starts_with($path, '/')) {
            $path = '/';
        }
        $page = max(1, min(self::MAX_PAGE, $page));
        $params = [];
        // Имя вкладки едет и на первую страницу: без него переход «Фото → 1»
        // возвращал бы читателя на вкладку «Видео».
        if ($tab !== '') {
            $params[self::TAB_PARAM] = $tab;
        }
        if ($page > 1) {
            $params[self::PARAM] = $page;
        }
        $query = $params === [] ? '' : '?' . http_build_query($params);

        return $path . $query . ($blockId > 0 ? '#block-' . $blockId : '');
    }

    /**
     * Разбивка на страницы для блока. Явный `$page` нужен неактивной вкладке:
     * номер из адреса принадлежит той, что открыта, а вторая показывает своё
     * начало — иначе переключение открывало бы её пустой серединой.
     *
     * @return array{page:int, pages:int, offset:int, per_page:int}
     */
    public static function slice(int $total, int $perPage, ?int $page = null): array
    {
        $perPage = max(1, $perPage);
        $pages = max(1, (int) ceil(max(0, $total) / $perPage));
        $page = min($page ?? self::current(), $pages);
        $page = max(1, $page);

        return [
            'page' => $page,
            'pages' => $pages,
            'offset' => ($page - 1) * $perPage,
            'per_page' => $perPage,
        ];
    }
}
