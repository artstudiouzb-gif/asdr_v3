<?php

declare(strict_types=1);

namespace App\Core\Design;

use App\Models\Setting;

/**
 * Каталоги шрифтов «Дизайна»: отобранные Google Fonts, рукописные, индекс
 * остальных семейств с узбекской кириллицей и локальные пресеты.
 *
 * Вынесено из DesignSettings (там остался фасад с теми же именами).
 */
final class FontCatalog
{
    /**
     * Локальный каталог шрифтов из Google Fonts с полной поддержкой узбекской
     * кириллицы (cyrillic-ext + cyrillic). Третий элемент используется только
     * локальным установщиком при сохранении, браузер к Google Fonts не обращается.
     * slug => [подпись, CSS-стек, параметр family для css2 API].
     */
    public const GOOGLE_FONTS = [
        'pt-serif' => ['PT Serif (антиква)', "'PT Serif', 'PT Serif Fallback', Georgia, serif", 'PT+Serif:wght@400;700'],
        'lora' => ['Lora (антиква)', "'Lora', Georgia, serif", 'Lora:wght@400;600;700'],
        'merriweather' => ['Merriweather (антиква)', "'Merriweather', Georgia, serif", 'Merriweather:wght@400;700'],
        'noto-serif' => ['Noto Serif (антиква)', "'Noto Serif', 'Noto Serif Fallback', Georgia, serif", 'Noto+Serif:wght@400;600;700'],
        'ibm-plex-serif' => ['IBM Plex Serif (антиква)', "'IBM Plex Serif', Georgia, serif", 'IBM+Plex+Serif:wght@400;600;700'],
        'cormorant' => ['Cormorant Garamond (антиква)', "'Cormorant Garamond', Georgia, serif", 'Cormorant+Garamond:wght@500;600;700'],
        'pt-sans' => ['PT Sans', "'PT Sans', 'PT Sans Fallback', system-ui, sans-serif", 'PT+Sans:wght@400;700'],
        'inter' => ['Inter', "'Inter', 'Inter Fallback', system-ui, sans-serif", 'Inter:wght@400;600;700'],
        'inter-tight' => ['Inter Tight', "'Inter Tight', 'Inter Tight Fallback', system-ui, sans-serif", 'Inter+Tight:wght@400;500;600;700'],
        'montserrat' => ['Montserrat', "'Montserrat', 'Montserrat Fallback', system-ui, sans-serif", 'Montserrat:wght@400;600;700'],
        'roboto' => ['Roboto', "'Roboto', system-ui, sans-serif", 'Roboto:wght@400;500;700'],
        'open-sans' => ['Open Sans', "'Open Sans', system-ui, sans-serif", 'Open+Sans:wght@400;600;700'],
        'noto-sans' => ['Noto Sans', "'Noto Sans', 'Noto Sans Fallback', system-ui, sans-serif", 'Noto+Sans:wght@400;600;700'],
        'source-sans' => ['Source Sans 3', "'Source Sans 3', system-ui, sans-serif", 'Source+Sans+3:wght@400;600;700'],
        'ibm-plex-sans' => ['IBM Plex Sans', "'IBM Plex Sans', system-ui, sans-serif", 'IBM+Plex+Sans:wght@400;600;700'],
        'manrope' => ['Manrope', "'Manrope', 'Manrope Fallback', system-ui, sans-serif", 'Manrope:wght@400;600;700'],
        'rubik' => ['Rubik', "'Rubik', system-ui, sans-serif", 'Rubik:wght@400;500;700'],
        'raleway' => ['Raleway', "'Raleway', system-ui, sans-serif", 'Raleway:wght@400;600;700'],
        'exo2' => ['Exo 2', "'Exo 2', system-ui, sans-serif", 'Exo+2:wght@400;600;700'],
        'golos' => ['Golos Text', "'Golos Text', system-ui, sans-serif", 'Golos+Text:wght@400;600;700'],
    ];

    /**
     * Рукописные семейства — отдельный каталог, а не строки в GOOGLE_FONTS.
     *
     * Тот каталог предлагается для текста и заголовков всего сайта, и
     * рукописное семейство там было бы ловушкой: выбрал «красиво» — получил
     * нечитаемый сайт. Здесь у шрифта одна роль: выделенное слово в заголовке
     * (`*слово*`) и подпись под текстом. Обе — короткие куски в несколько слов.
     *
     * В списке только семейства с подмножествами cyrillic-ext + cyrillic +
     * latin: без cyrillic-ext не рисуются узбекские Ғғ Ққ Ҳҳ, а установка
     * такого шрифта отвалится проверкой покрытия (Marck Script поэтому и не
     * попал в список, хотя кириллица у него есть).
     */
    public const SCRIPT_FONTS = [
        'caveat' => ['Caveat (от руки, разборчивый)', "'Caveat', 'Segoe Script', cursive", 'Caveat:wght@400;600;700'],
        'bad-script' => ['Bad Script (почерк ручкой)', "'Bad Script', 'Segoe Script', cursive", 'Bad+Script:wght@400'],
        'great-vibes' => ['Great Vibes (каллиграфия)', "'Great Vibes', cursive", 'Great+Vibes:wght@400'],
        'pacifico' => ['Pacifico (вывеска)', "'Pacifico', cursive", 'Pacifico:wght@400'],
    ];

    /** Сгенерированный каталог из app/Core/data, прочитанный один раз за запрос. */
    /** @var array<string, array{0:string,1:string,2:string}>|null */
    private static ?array $googleIndex = null;

    /**
     * Остальные семейства Google Fonts с узбекской кириллицей.
     *
     * GOOGLE_FONTS — двадцать отобранных семейств, и до появления этого файла
     * ничего кроме них редактору не предлагалось: «каталог Google Fonts» в
     * форме означал двадцать строк. Полный список лежит сгенерированным файлом
     * (`npm run build:fonts-index`), потому что он меняется несколько раз в год,
     * а зависеть от доступности чужого сервиса в момент, когда администратор
     * открыл форму, незачем — по той же причине заранее собран индекс спрайта
     * иконок.
     *
     * Семейства, уже названные в GOOGLE_FONTS и SCRIPT_FONTS, отсюда убраны —
     * и по слугу, и по имени семейства: у «Exo 2» слуг в каталоге `exo2`, а в
     * индексе `exo-2`, и без сверки по имени один шрифт стоял бы в списке
     * дважды с разными подписями.
     *
     * @return array<string, array{0:string,1:string,2:string}>
     */
    public static function googleFontsExtra(): array
    {
        if (self::$googleIndex === null) {
            $file = dirname(__DIR__) . '/data/google-fonts-index.php';
            $index = is_file($file) ? require $file : [];
            /** @var array<string, array{0:string,1:string,2:string}> $rows */
            $rows = is_array($index) ? $index : [];

            $known = [];
            foreach (self::GOOGLE_FONTS + self::SCRIPT_FONTS as $entry) {
                $known[strtolower(explode(':', $entry[2])[0])] = true;
            }

            $extra = [];
            foreach ($rows as $slug => $entry) {
                if (isset(self::GOOGLE_FONTS[$slug]) || isset(self::SCRIPT_FONTS[$slug])) {
                    continue;
                }
                if (isset($known[strtolower(explode(':', $entry[2])[0])])) {
                    continue;
                }
                $extra[$slug] = $entry;
            }
            self::$googleIndex = $extra;
        }

        return self::$googleIndex;
    }

    /**
     * Каталог для ролей «текст» и «заголовки»: отобранные семейства первыми,
     * за ними остальные из индекса.
     *
     * @return array<string, array{0:string,1:string,2:string}>
     */
    public static function googleFontCatalog(): array
    {
        return self::GOOGLE_FONTS + self::googleFontsExtra();
    }

    /**
     * Все каталоги одним списком: тот, кто скачивает файлы и собирает
     * @font-face, различий между ролями не знает — ему нужен адрес.
     *
     * @return array<string, array{0:string,1:string,2:string}>
     */
    public static function fontCatalog(): array
    {
        return self::googleFontCatalog() + self::SCRIPT_FONTS;
    }

    /** Стек выбранного рукописного шрифта или '' — если он не выбран. */
    public static function scriptFontStack(): string
    {
        $slug = (string) Setting::get('design_font_script', '');

        return isset(self::SCRIPT_FONTS[$slug]) ? self::SCRIPT_FONTS[$slug][1] : '';
    }

    /**
     * Шрифтовые пресеты: значение опции font_style => [подпись, CSS-стек].
     *
     * В админке этот список подписан «Локальные — без внешних запросов»,
     * поэтому называть здесь можно только семейства из поставки (Noto Sans,
     * Noto Serif) и системные стеки. Пресет с чужим семейством выглядел бы
     * рабочим, а рисовался бы Arial: файла нет, скачать его через font_style
     * нечем — слуга каталога у пресета не бывает.
     */
    public const FONTS = [
        'noto' => ['Noto Sans', "'Noto Sans', 'Noto Sans Fallback', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"],
        'system' => ['Системный', "system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif"],
        'serif' => ['С засечками', "Georgia, 'Times New Roman', serif"],
        'custom' => ['Свой шрифт', ''],
    ];

    /**
     * Прежние значения font_style, оставшиеся в БД от набора шрифтов до
     * перехода на Noto. Читаются как ближайший живой пресет: без этого
     * сохранённый «inter» стал бы неизвестным ключом и молча превращался в
     * «свой шрифт» с пустым стеком.
     */
    private const LEGACY_FONT_STYLES = ['pt' => 'noto', 'inter' => 'noto'];

    /** Ключ пресета шрифта с учётом прежних значений. */
    public static function fontStyleKey(string $style): string
    {
        return self::LEGACY_FONT_STYLES[$style] ?? $style;
    }
}
