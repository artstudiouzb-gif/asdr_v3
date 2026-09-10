<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Language;
use App\Models\Setting;

/**
 * Правила упреждающей загрузки (Speculation Rules API).
 *
 * Переход между страницами у нас серверный: каждая ссылка — новый документ,
 * и посетитель ждёт полный круг «запрос → сборка → сеть → отрисовка». Правила
 * позволяют браузеру начать этот круг заранее — по наведению курсора, — и к
 * моменту нажатия страница уже готова. SPA для этого не нужен: приём
 * декларативный, браузер без поддержки просто переходит по ссылке как раньше.
 *
 * **Разметка не содержит nonce, и это не мелочь.** Инлайн-скрипт с nonce
 * менялся бы от запроса к запросу, а `PublicResponseCache::sendConditional()`
 * считает ETag от готового тела — тег перестал бы совпадать никогда, и вместо
 * пустого `304` посетитель получал бы всю страницу заново. Поэтому
 * `<script type="speculationrules">` разрешён в CSP отдельным источником
 * `'inline-speculation-rules'`: он допускает ровно этот тип скрипта и ничего
 * исполняемого.
 *
 * **Нагрузка на сервер — часть настройки, а не побочный эффект.** Пререндер
 * это полноценный запрос плюс отрисовка в скрытой вкладке, поэтому он идёт
 * по `conservative` (нажатие кнопки мыши, до отпускания), а более дешёвый
 * префетч — по `moderate` (наведение ~200 мс). Владелец может опустить режим
 * до одного префетча или выключить приём совсем.
 *
 * **Список исключений не заводится второй раз.** Адреса, которым упреждающий
 * запрос противопоказан, — это те же приватные и служебные пути, что уже
 * перечислены в `PublicResponseCache`: их ответ зависит от сессии, ставит
 * cookie или отдаёт файл. Свой список разъехался бы с тем при первой правке.
 */
final class SpeculationRules
{
    public const SETTING = 'perf_speculation';

    /** off — приём выключен; prefetch — только префетч; prerender — префетч плюс пререндер. */
    public const MODES = ['off', 'prefetch', 'prerender'];

    /**
     * Служебные адреса, которых нет в списке приватных путей: они публичны и
     * кэшируются, но упреждать их нечего или вредно.
     *
     * `/script/{code}` меняет письменность и ставит cookie — упреждающий
     * запрос переключил бы её без ведома посетителя. Остальное — машинные
     * ответы и файлы: браузер по ним не «переходит», а скачивает.
     *
     * @var list<string>
     */
    private const EXTRA_EXCLUDED = [
        '/script',
        '/goals',
        '/sitemap.xml',
        '/robots.txt',
        '/manifest.webmanifest',
        '/rss.xml',
        '/rss',
        '/uploads',
        '/assets',
        '/media',
    ];

    /**
     * Ссылки, которые нельзя упреждать по самой разметке: файлы, внешние
     * вкладки, помеченные редактором и служебные элементы управления
     * (переключатели языка и письменности — они меняют cookie).
     */
    private const EXCLUDED_SELECTOR =
        '[download], [target="_blank"], [rel~="nofollow"], [rel~="external"], '
        . '[data-no-speculation], [data-lang-switch] a, [data-script-switch] a';

    public static function mode(): string
    {
        $mode = (string) Setting::get(self::SETTING, 'prefetch');

        return in_array($mode, self::MODES, true) ? $mode : 'prefetch';
    }

    /**
     * Правила в том виде, в каком они уходят в JSON.
     *
     * @param list<string>|null $activeCodes
     * @return array<string, list<array<string, mixed>>>
     */
    public static function rules(?string $mode = null, ?array $activeCodes = null): array
    {
        $mode = $mode ?? self::mode();
        if ($mode === 'off') {
            return [];
        }

        $where = [
            'and' => [
                // Относительный шаблон — это тот же origin: чужие домены
                // упреждать нельзя ни по трафику, ни по приватности.
                ['href_matches' => '/*'],
                ['not' => ['href_matches' => self::excludedPatterns($activeCodes)]],
                ['not' => ['selector_matches' => self::EXCLUDED_SELECTOR]],
            ],
        ];

        // Префетч по наведению: один GET, отрисовки нет — это дёшево и для
        // сервера, и для батареи посетителя.
        $rules = ['prefetch' => [['where' => $where, 'eagerness' => 'moderate']]];

        if ($mode === 'prerender') {
            // Пререндер по нажатию: браузер держит не больше двух таких
            // документов, а сам запрос приходит уже после явного намерения
            // посетителя — наведение курсора им не считается.
            $rules['prerender'] = [['where' => $where, 'eagerness' => 'conservative']];
        }

        return $rules;
    }

    /** Каталоги статики: языкового префикса у них не бывает. */
    private const STATIC_ROOTS = ['/assets', '/uploads', '/media'];

    /**
     * Шаблоны адресов, которые упреждать нельзя.
     *
     * Три правила, и все три нужны, чтобы список не раздувал каждую страницу
     * лишними килобайтами разметки:
     *
     *  - URLPattern не считает `/admin` совпадением с `/admin/*`, поэтому у
     *    пути-раздела два шаблона; у пути-файла (`/captcha.png`,
     *    `/sitemap.xml`) вложенных адресов не бывает — второй шаблон был бы
     *    мусором;
     *  - языковой префикс берётся по списку активных языков, а не шаблоном
     *    `/xx`: иначе под исключение попала бы обычная страница со slug из
     *    двух букв. Основной язык пропускается — его адреса на сайте
     *    печатаются без префикса (`Locale::url`), ссылки на `/ru/...`
     *    в разметке не появляются;
     *  - файлам и каталогам статики префикс не приписывается вовсе: до
     *    разрешения языка такие запросы не доходят.
     *
     * @param list<string>|null $activeCodes
     * @return list<string>
     */
    public static function excludedPatterns(?array $activeCodes = null, ?string $defaultCode = null): array
    {
        $paths = array_merge(PublicResponseCache::privatePaths(), self::EXTRA_EXCLUDED);
        $default = strtolower(trim($defaultCode ?? Language::defaultCode()));

        $prefixes = [''];
        foreach ($activeCodes ?? Language::activeCodes() as $code) {
            $code = strtolower(trim((string) $code));
            if ($code !== '' && $code !== $default) {
                $prefixes[] = '/' . $code;
            }
        }

        $patterns = [];
        foreach ($prefixes as $prefix) {
            foreach ($paths as $path) {
                $isFile = str_contains($path, '.');
                if ($prefix !== '' && ($isFile || in_array($path, self::STATIC_ROOTS, true))) {
                    continue;
                }
                $patterns[] = $prefix . $path;
                if (!$isFile) {
                    $patterns[] = $prefix . $path . '/*';
                }
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * Разметка для подвала. Пустая строка — приём выключен.
     *
     * JSON печатается без экранирования слэшей: шаблоны адресов читаются в
     * «просмотре кода страницы», а `\/` там выглядит поломкой.
     */
    public static function scriptHtml(): string
    {
        $rules = self::rules();
        if ($rules === []) {
            return '';
        }

        $json = json_encode(
            $rules,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );
        if (!is_string($json)) {
            return '';
        }

        return '<script type="speculationrules">' . $json . '</script>';
    }
}
