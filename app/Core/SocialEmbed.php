<?php

declare(strict_types=1);

namespace App\Core;

final class SocialEmbed
{
    /**
     * Преобразует отдельно стоящие ссылки на соцсети (Telegram, Instagram,
     * Facebook, YouTube)
     * внутри статей в интерактивные блоки встраивания (Embed) и карточки превью.
     */
    public static function transform(string $html): string
    {
        if (trim($html) === "") {
            return $html;
        }

        // Старый WXR-контент хранит абзацы как пустые строки и рассчитывает
        // на wpautop при выводе. Восстанавливаем семантические <p> до поиска
        // отдельно стоящих social-ссылок. Уже размеченный редактором HTML
        // LegacyContentFormatter не меняет.
        $html = LegacyContentFormatter::paragraphize($html);

        // 1. YouTube ссылки на отдельной строке или в абзаце
        $html = (string) preg_replace_callback(
            '/<p>\s*(?:<a[^>]*href="([^"]+)"[^>]*>)?\s*(https?:\/\/(?:www\.)?(?:youtube\.com|youtu\.be)\/[^\s<]+)\s*(?:<\/a>)?\s*<\/p>/i',
            static function (array $m): string {
                $id = Video::youtubeId($m[2]);
                if ($id === null) {
                    return $m[0];
                }
                $url = "https://www.youtube-nocookie.com/embed/" . $id;
                return '<div class="social-embed social-embed--youtube"><iframe src="' . htmlspecialchars($url, ENT_QUOTES) . '" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen loading="lazy"></iframe></div>';
            },
            $html
        );

        // 2. Telegram посты (https://t.me/username/1234)
        $html = (string) preg_replace_callback(
            '/<p>\s*(?:<a[^>]*href="([^"]+)"[^>]*>)?\s*(https?:\/\/t\.me\/([a-zA-Z0-9_]{3,})\/([0-9]+))\s*(?:<\/a>)?\s*<\/p>/i',
            static function (array $m): string {
                $channel = $m[3];
                $postId = $m[4];
                $postRef = $channel . "/" . $postId;
                $fullUrl = "https://t.me/" . $postRef;

                return '<div class="social-embed social-embed--telegram">'
                    . '<script async src="https://telegram.org/js/telegram-widget.js?22" data-telegram-post="' . htmlspecialchars($postRef, ENT_QUOTES) . '" data-width="100%"></script>'
                    . '<a class="social-embed-card social-embed-card--telegram" href="' . htmlspecialchars($fullUrl, ENT_QUOTES) . '" target="_blank" rel="noopener">'
                    . '<span class="social-embed-card__icon">' . Icon::render('brand-telegram', 22) . '</span>'
                    . '<span class="social-embed-card__body">'
                    . '<span class="social-embed-card__title">' . htmlspecialchars(t('Пост в Telegram'), ENT_QUOTES) . ' (@' . htmlspecialchars($channel, ENT_QUOTES) . ')</span>'
                    . '<span class="social-embed-card__url">' . htmlspecialchars($fullUrl, ENT_QUOTES) . '</span>'
                    . '</span>'
                    . '<span class="social-embed-card__btn">' . htmlspecialchars(t('Открыть в Telegram'), ENT_QUOTES) . '</span>'
                    . '</a>'
                    . '</div>';
            },
            $html
        );

        // 3. Instagram посты и Reels (https://instagram.com/p/CODE/)
        $html = (string) preg_replace_callback(
            '/<p>\s*(?:<a[^>]*href="([^"]+)"[^>]*>)?\s*(https?:\/\/(?:www\.)?instagram\.com\/(p|reel|tv)\/([a-zA-Z0-9_-]+)[^\s<]*)\s*(?:<\/a>)?\s*<\/p>/i',
            static function (array $m): string {
                $type = strtolower($m[3]);
                $code = $m[4];
                $fullUrl = "https://www.instagram.com/" . $type . "/" . $code . "/";
                $label = $type === "reel" ? t('Reel в Instagram') : t('Публикация в Instagram');

                return '<div class="social-embed social-embed--instagram">'
                    . '<a class="social-embed-card social-embed-card--instagram" href="' . htmlspecialchars($fullUrl, ENT_QUOTES) . '" target="_blank" rel="noopener">'
                    . '<span class="social-embed-card__icon">' . Icon::render('brand-instagram', 22) . '</span>'
                    . '<span class="social-embed-card__body">'
                    . '<span class="social-embed-card__title">' . htmlspecialchars($label, ENT_QUOTES) . '</span>'
                    . '<span class="social-embed-card__url">' . htmlspecialchars($fullUrl, ENT_QUOTES) . '</span>'
                    . '</span>'
                    . '<span class="social-embed-card__btn">' . htmlspecialchars(t('Смотреть в Instagram'), ENT_QUOTES) . '</span>'
                    . '</a>'
                    . '</div>';
            },
            $html
        );

        // 4. Facebook: приватный по умолчанию preview без внешнего SDK и
        // cookies. Публичная публикация открывается на исходной платформе.
        $html = (string) preg_replace_callback(
            '/<p>\s*(?:<a[^>]*href="([^"]+)"[^>]*>)?\s*(https?:\/\/(?:www\.|m\.)?(?:facebook\.com|fb\.watch)\/[^\s<]+)\s*(?:<\/a>)?\s*<\/p>/i',
            static function (array $m): string {
                $fullUrl = $m[2];

                return '<div class="social-embed social-embed--facebook">'
                    . '<a class="social-embed-card social-embed-card--facebook" href="' . htmlspecialchars($fullUrl, ENT_QUOTES) . '" target="_blank" rel="noopener">'
                    . '<span class="social-embed-card__icon">' . Icon::render('brand-facebook', 22) . '</span>'
                    . '<span class="social-embed-card__body">'
                    . '<span class="social-embed-card__title">' . htmlspecialchars(t('Публикация в Facebook'), ENT_QUOTES) . '</span>'
                    . '<span class="social-embed-card__url">' . htmlspecialchars($fullUrl, ENT_QUOTES) . '</span>'
                    . '</span>'
                    . '<span class="social-embed-card__btn">' . htmlspecialchars(t('Смотреть в Facebook'), ENT_QUOTES) . '</span>'
                    . '</a>'
                    . '</div>';
            },
            $html
        );

        return $html;
    }
}
