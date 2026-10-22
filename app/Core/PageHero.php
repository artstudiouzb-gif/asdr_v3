<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Первый блок, который сам является шапкой страницы.
 *
 * Обложка (и профиль руководителя) несут заголовок уровня h1 и собственную
 * композицию. Там, где такой блок стоит первым, шапка самой записи — заголовок,
 * лид, обложка-картинка — не выводится: иначе на странице два заголовка подряд
 * и две вводные части, а сама обложка уезжает вниз вторым экраном.
 *
 * Правило одно на все типы записей: у страницы и у проекта отличается только
 * разметка шапки, а решение «шапку рисует блок» — общее. Вторая копия этих
 * трёх регулярок разъехалась бы с первой при первой же правке разметки блока.
 */
final class PageHero
{
    /**
     * Обложка бывает двух видов, и разметка у них разная: запись типа
     * «Обложки» рисуется HeroRenderer'ом (`<div class="hero …">`), старая —
     * собранная прямо в блоке — шаблоном `blocks/hero.php`
     * (`<div class="block-hero …">`). Тип блока при этом один и тот же.
     */
    private const HERO_ROOT = '/(<div class="(?:block-)?hero[\s"][^>]*>)/';

    /** Модификатор крошек, разложенных поверх обложки (`_crumbs.php`). */
    public const ON_HERO_CLASS = 'content-crumbs--on-hero';

    /** Модификатор крошек, встроенных первой строкой в секцию «Колонок». */
    public const IN_BLOCK_CLASS = 'content-crumbs--in-block';

    /** Первый блок — обложка: страница отдаёт ей и шапку, и крошки. */
    public static function isHero(string $content): bool
    {
        return (bool) preg_match('/^\s*<section\b[^>]*\bcms-block--hero\b/', $content);
    }

    /**
     * Первый блок сам печатает h1 страницы. Кроме обложки так делает профиль
     * руководителя: у него свой заголовок, но собственной композиции во всю
     * ширину нет.
     */
    public static function ownsHeading(string $content): bool
    {
        return (bool) preg_match(
            '/^\s*<section\b[^>]*\bcms-block--hero\b/',
            $content
        );
    }

    /**
     * Первый блок — «Колонки». Их собирают шапкой страницы (текст с фото,
     * заголовок на фоне секции), и тогда отдельная полоса крошек над ними
     * стоит чужой строкой — тот же случай, что у обложки.
     */
    public static function isColumns(string $content): bool
    {
        return (bool) preg_match('/^\s*<section\b[^>]*\bcms-block--columns\b/', $content);
    }

    /**
     * Крошки первой строкой внутри секции «Колонок»: на её фоне и в её
     * отступах, а не полосой над ней. В отличие от обложки — в потоке, а не
     * поверх: у колонок нет композиции, рассчитанной на слой сверху, и
     * absolute наехал бы на текст первой колонки.
     *
     * Звать только когда заголовок страницы скрыт: видимый h1 стоит между
     * полосой крошек и первым блоком, и крошки внутри блока оказались бы под
     * заголовком — навигация «откуда я пришёл» ниже того, куда пришёл.
     *
     * @return array{0: string, 1: string} содержимое и неиспользованные крошки
     */
    public static function withCrumbsInColumns(string $content, string $crumbsHtml): array
    {
        if (trim($crumbsHtml) === '') {
            return [$content, ''];
        }

        $crumbsHtml = str_replace(
            'class="content-crumbs"',
            'class="content-crumbs ' . self::IN_BLOCK_CLASS . '"',
            $crumbsHtml
        );
        $injected = (string) preg_replace(
            '/^(\s*<section\b[^>]*\bcms-block--columns\b[^>]*>)/',
            '$1' . addcslashes($crumbsHtml, '\\$'),
            $content,
            1,
            $count
        );

        return $count > 0
            ? [$injected, '']
            : [$content, str_replace(' ' . self::IN_BLOCK_CLASS, '', $crumbsHtml)];
    }

    /**
     * Крошки как часть обложки: поверх фона, вверху секции, вместо отдельной
     * серой полосы над ней.
     *
     * Встроить удаётся не всегда (разметка обложки могла поменяться), и тогда
     * крошки возвращаются вызывающему нетронутыми — печатать их полосой выше
     * правильнее, чем потерять: именно так они пропадали на странице с
     * обложкой-записью, потому что искали корень только старого шаблона.
     *
     * @return array{0: string, 1: string} содержимое и неиспользованные крошки
     */
    public static function withCrumbs(string $content, string $crumbsHtml): array
    {
        // Класс нужен CSS: под обложкой-шапкой снимается верхний отступ
        // контента, иначе между шапкой сайта и обложкой остаётся полоса фона.
        $content = (string) preg_replace(
            '/(class="[^"]*\bcms-block--hero\b)/',
            '$1 cms-block--page-hero',
            $content,
            1
        );

        if (trim($crumbsHtml) === '') {
            return [$content, ''];
        }

        $injected = (string) preg_replace(
            self::HERO_ROOT,
            '$1' . addcslashes($crumbsHtml, '\\$'),
            $content,
            1,
            $count
        );

        // Не встроились — крошки печатает вызывающий обычной полосой, и
        // модификатор «поверх обложки» им там не нужен: он раскладывает
        // навигацию absolute, то есть вне потока полоса схлопнулась бы.
        return $count > 0
            ? [$injected, '']
            : [$content, str_replace(' ' . self::ON_HERO_CLASS, '', $crumbsHtml)];
    }
}
