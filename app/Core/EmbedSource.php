<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Разбор адреса внешней врезки: ролик YouTube, пост Telegram, форма Google.
 *
 * Источник **опознаётся по ссылке**, а не выбирается в отдельном поле:
 * поле-дубликат того, что и так видно в адресе, редактор рано или поздно
 * поставит не то, и блок покажет форму вместо ролика. Пусть лучше он вставит
 * ссылку — как в блоке «Карта».
 *
 * Набор источников закрытый, и это главное решение. `frame-src https:` в CSP
 * пропустил бы любой домен, но произвольный iframe — это чужой код на нашей
 * странице: реклама, счётчики, перехват клика. Произвольный код у нас
 * доступен только супер-админу (блок «HTML»), и эта дверь не должна
 * открываться в обход. Новый источник добавляется сюда — вместе с решением,
 * что ему можно доверять.
 */
final class EmbedSource
{
    /** @var list<string> опознаваемые источники, порядок значения не имеет */
    public const PROVIDERS = ['youtube', 'telegram', 'google_form'];

    /**
     * @return array{provider: string, src: string, title: string}|null
     *         null — ссылка не опознана: врезка не выводится вовсе
     */
    public static function parse(?string $url): ?array
    {
        $url = trim((string) $url);
        // Управляющие символы в адресе — признак склейки или подделки, а не
        // опечатки: разбирать такое незачем.
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        $videoId = Video::youtubeId($url);
        if ($videoId !== null) {
            // Домен без кук: ролик не заводит профиль посетителя до просмотра.
            return [
                'provider' => 'youtube',
                'src' => 'https://www.youtube-nocookie.com/embed/' . $videoId . '?rel=0&modestbranding=1',
                'title' => 'YouTube',
            ];
        }

        // Пост канала: t.me/channel/123 (ссылка «Поделиться» в Telegram).
        // Официальный виджет требует их скрипт на странице; iframe с ?embed=1
        // отдаёт тот же пост и обходится без чужого кода.
        if (preg_match('#^https://t\.me/(?:s/)?([A-Za-z0-9_]{4,32})/(\d{1,12})#', $url, $m) === 1) {
            return [
                'provider' => 'telegram',
                'src' => 'https://t.me/' . $m[1] . '/' . $m[2] . '?embed=1',
                'title' => 'Telegram',
            ];
        }

        // Форма Google: и «живая» ссылка, и уже готовая для встраивания.
        if (preg_match('#^https://docs\.google\.com/forms/[A-Za-z0-9_/-]+/viewform#', $url) === 1) {
            $src = (string) preg_replace('/[?#].*$/', '', $url);

            return [
                'provider' => 'google_form',
                'src' => $src . '?embedded=true',
                'title' => 'Google Forms',
            ];
        }

        return null;
    }
}
