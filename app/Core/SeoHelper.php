<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Language;
use App\Models\Setting;

final class SeoHelper
{
    /**
     * Генерирует hreflang-теги для всех активных языков сайта.
     */
    public static function hreflangTags(string $appUrl): string
    {
        if (!Database::isConnected()) {
            return "";
        }

        $activeCodes = Language::activeCodes();
        if (count($activeCodes) <= 1) {
            return "";
        }

        $path = Locale::path();
        $defaultLang = Language::defaultCode();
        $html = "";

        foreach ($activeCodes as $langCode) {
            $localizedUrl = rtrim($appUrl, "/") . Locale::url($path, $langCode);
            $html .= "<link rel=\"alternate\" hreflang=\"" . htmlspecialchars($langCode, ENT_QUOTES) . "\" href=\"" . htmlspecialchars($localizedUrl, ENT_QUOTES) . "\" />\n";
        }

        // x-default ссылается на язык по умолчанию
        $defaultUrl = rtrim($appUrl, "/") . Locale::url($path, $defaultLang);
        $html .= "<link rel=\"alternate\" hreflang=\"x-default\" href=\"" . htmlspecialchars($defaultUrl, ENT_QUOTES) . "\" />\n";

        return $html;
    }

    /**
     * Генерирует JSON-LD микроразметку организации (GovernmentOrganization / Organization).
     */
    public static function organizationSchema(string $appUrl): string
    {
        $siteName = Setting::getLocalized("site_name", Locale::current(), "Государственный портал Республики Узбекистан");
        $logo = Setting::get("logo_url", "");
        if ($logo !== "" && str_starts_with($logo, "/")) {
            $logo = rtrim($appUrl, "/") . $logo;
        }

        $phone = Setting::get("contact_phone", "") ?: Setting::get("site_phone", "");
        $email = Setting::get("contact_email", "") ?: Setting::get("site_email", "");
        $address = Setting::get("contact_address", "");

        $data = [
            "@context" => "https://schema.org",
            "@type" => "GovernmentOrganization",
            "name" => $siteName,
            "url" => $appUrl,
        ];

        if ($logo !== "") {
            $data["logo"] = $logo;
        }

        if ($phone !== "" || $email !== "") {
            $contactPoint = ["@type" => "ContactPoint"];
            if ($phone !== "") { $contactPoint["telephone"] = $phone; }
            if ($email !== "") { $contactPoint["email"] = $email; }
            $contactPoint["contactType"] = "customer service";
            $data["contactPoint"] = $contactPoint;
        }

        if ($address !== "") {
            $data["address"] = [
                "@type" => "PostalAddress",
                "streetAddress" => $address,
            ];
        }

        $sameAs = self::socialProfiles();
        if ($sameAs !== []) {
            $data["sameAs"] = $sameAs;
        }

        return "<script type=\"application/ld+json\">" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
    }

    /**
     * Официальные аккаунты организации для поля sameAs.
     *
     * Отдельной настройки для них нет и заводить её незачем: ссылки уже
     * набраны в конструкторах шапки и подвала, а второй список разъехался бы
     * с ними при первой правке. Берём только внешние http(s)-адреса — «sameAs»
     * означает «тот же субъект в другом месте», ссылка внутрь сайта туда не
     * относится.
     *
     * @return list<string>
     */
    private static function socialProfiles(): array
    {
        $urls = [];
        foreach ([HeaderConfig::get(), FooterConfig::get()] as $config) {
            $buttons = $config['social_buttons'] ?? [];
            if (!is_array($buttons)) {
                continue;
            }
            foreach ($buttons as $button) {
                if (!is_array($button)) {
                    continue;
                }
                $url = trim((string) ($button['url'] ?? ''));
                if (preg_match('#^https?://#i', $url) !== 1 || in_array($url, $urls, true)) {
                    continue;
                }
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Описание страницы из её же текста — последний рубеж перед пустым
     * <meta name="description">.
     *
     * Пустое описание не «ничего»: поиск всё равно соберёт сниппет, но из
     * случайного куска страницы — у нас это оказывалось меню или подпись к
     * фотографии. Берём первый содержательный абзац: заголовки, подписи и
     * цифры описанием страницы не являются, а сплошной strip_tags склеил бы
     * их в одну строку.
     */
    public static function autoDescription(string $html, int $limit = 200): string
    {
        if ($html === '' || !str_contains($html, '<p')) {
            return "";
        }
        if (preg_match_all('#<p\b[^>]*>(.*?)</p>#si', $html, $matches) < 1) {
            return "";
        }

        foreach ($matches[1] as $paragraph) {
            $text = html_entity_decode(strip_tags((string) $paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));
            if (mb_strlen($text) < 40) {
                continue;
            }

            return self::clip($text, $limit);
        }

        return "";
    }

    /**
     * Значащие параметры адреса, которые попадают в canonical.
     *
     * Порядок здесь — это и порядок в готовой ссылке: `?page=2&category=x` и
     * `?category=x&page=2` — один и тот же набор записей, и объявлять для него
     * два разных canonical значило бы своими руками сделать дубль, ради
     * борьбы с которым canonical и существует.
     *
     * Чего в списке намеренно нет:
     *  - `sort` — те же записи в другом порядке. Это и есть дубль, canonical
     *    обязан вести на список без сортировки.
     *  - `q` — свободный ввод, пространство значений бесконечно.
     *  - `token`, `to`, `format` — служебные, к содержимому страницы отношения
     *    не имеют.
     */
    private const CANONICAL_PARAMS = ['category', 'page', 'mtab', 'mpage', 'm'];

    /**
     * Первые страницы списков: значение по умолчанию из адреса убираем.
     *
     * `/news` и `/news?page=1` — побайтно одна страница, и разный canonical
     * у них снова развёл бы её на два адреса.
     */
    private const CANONICAL_DEFAULTS = ['page' => '1', 'mpage' => '1'];

    /**
     * Canonical текущей страницы: путь плюс значащие параметры адреса.
     *
     * Раньше здесь был только путь, и каждый адрес с параметром объявлял себя
     * дублем: `/news?page=7` вело на `/news`, а рубрикатор новостей
     * (`?category=<slug>`) не имел в выдаче ни одного индексируемого адреса
     * вовсе. Ссылки `rel="prev|next"` это не компенсируют — как сигнал
     * индексации поиск их больше не использует.
     *
     * Обратное тоже неверно: пускать в canonical всю query-строку значит
     * плодить адреса на каждую метку рекламной кампании (`utm_*`, `fbclid`) и
     * на каждый порядок сортировки. Поэтому список закрытый — см.
     * CANONICAL_PARAMS.
     *
     * @param string $query сырая query-строка запроса (без «?»)
     */
    public static function canonicalUrl(string $appUrl, string $path, string $query): string
    {
        $base = $appUrl . $path;
        if (trim($query) === '') {
            return $base;
        }

        $parsed = [];
        parse_str($query, $parsed);

        $kept = [];
        foreach (self::CANONICAL_PARAMS as $name) {
            $value = $parsed[$name] ?? null;
            // `?page[]=1` — массив: в canonical такому значению взяться неоткуда,
            // а http_build_query() развернул бы его в `page%5B0%5D=1`.
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '' || $value === (self::CANONICAL_DEFAULTS[$name] ?? null)) {
                continue;
            }
            $kept[$name] = $value;
        }

        return $kept === [] ? $base : $base . '?' . http_build_query($kept);
    }

    /** Обрезка по границе слова: половина слова в сниппете читается как сбой. */
    public static function clip(string $text, int $limit): string
    {
        $text = trim($text);
        if ($limit <= 0 || mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > (int) ($limit * 0.6)) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " ,.;:—-") . '…';
    }

    /**
     * JSON-LD WebSite с поиском по сайту (sitelinks searchbox).
     *
     * Разметка описывает сайт целиком, поэтому выводится только на главной:
     * на каждой странице она повторяла бы одно и то же и спорила бы с
     * разметкой самой страницы. Адрес поиска — реальный маршрут /search?q=,
     * иначе поисковик проверит его и проигнорирует всю разметку.
     */
    public static function websiteSchema(string $appUrl, string $siteName): string
    {
        if (trim(Locale::path(), '/') !== '') {
            return "";
        }

        $lang = Locale::current();
        $home = rtrim($appUrl, "/") . Locale::url('', $lang);
        $search = rtrim($appUrl, "/") . Locale::url('search', $lang);

        $data = [
            "@context" => "https://schema.org",
            "@type" => "WebSite",
            "name" => $siteName,
            "url" => $home,
            "inLanguage" => $lang,
            "potentialAction" => [
                "@type" => "SearchAction",
                "target" => [
                    "@type" => "EntryPoint",
                    "urlTemplate" => $search . "?q={search_term_string}",
                ],
                "query-input" => "required name=search_term_string",
            ],
        ];

        return "<script type=\"application/ld+json\">" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
    }

    /**
     * Генерирует JSON-LD микроразметку хлебных крошек (BreadcrumbList).
     *
     * @param array<int, array{name: string, url?: string}> $items
     */
    public static function breadcrumbSchema(string $appUrl, array $items): string
    {
        if ($items === []) {
            return "";
        }

        $list = [];
        $pos = 1;

        foreach ($items as $item) {
            $name = trim((string) ($item["name"] ?? ""));
            if ($name === "") { continue; }

            $url = (string) ($item["url"] ?? "");
            if ($url !== "" && str_starts_with($url, "/")) {
                $url = rtrim($appUrl, "/") . $url;
            }

            $listItem = [
                "@type" => "ListItem",
                "position" => $pos++,
                "name" => $name,
            ];

            if ($url !== "") {
                $listItem["item"] = $url;
            }

            $list[] = $listItem;
        }

        if ($list === []) {
            return "";
        }

        $data = [
            "@context" => "https://schema.org",
            "@type" => "BreadcrumbList",
            "itemListElement" => $list,
        ];

        return "<script type=\"application/ld+json\">" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
    }

    /** Внешних шрифтовых ресурсов больше нет: весь каталог самохостится. */
    public static function resourceHintsHtml(): string
    {
        return '';
    }

    /**
     * Подключает иконки устройств и веб-манифест. Ссылки на файлы иконок
     * выводятся только если файлы действительно лежат в public/: иначе
     * браузер на каждой странице получал три ответа 404. Загруженная в
     * настройках фавиконка подключается отдельно (в шапке сайта), манифест
     * собирается из настроек контроллером.
     */
    public static function faviconsHtml(string $appUrl): string
    {
        $html = '';
        foreach (Favicon::staticIcons() as $tag) {
            $html .= $tag . "\n";
        }

        return $html . "<link rel=\"manifest\" href=\"/manifest.webmanifest\">\n";
    }
}

