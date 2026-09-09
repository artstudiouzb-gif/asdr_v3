<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Напоминания редактору: что в материале осталось незаполненным. Ничего не
 * запрещает и не блокирует сохранение — только показывает список.
 *
 * Правило одно: пункт попадает сюда, если его отсутствие видно посетителю или
 * ломает что-то за пределами страницы (лента, поиск, соцсети). «Заполните всё»
 * ради полноты — не повод: такой список перестают читать на второй неделе.
 */
final class ContentChecklist
{
    /**
     * @param array<string,mixed> $news строка новости
     * @param list<array<string,mixed>> $gallery снимки галереи
     * @param list<string> $translatedLangs языки, на которых есть перевод
     * @return list<array{key:string, text:string, why:string}>
     */
    public static function forNews(array $news, array $gallery = [], array $translatedLangs = []): array
    {
        $items = [];
        $empty = static fn (string $key): bool => trim((string) ($news[$key] ?? '')) === '';

        if ($empty('image')) {
            $items[] = [
                'key' => 'image',
                'text' => 'Нет главного фото',
                'why' => 'Лента новостей, поиск и соцсети возьмут заглушку вместо снимка.',
            ];
        }
        if ($empty('excerpt')) {
            $items[] = [
                'key' => 'excerpt',
                'text' => 'Пустой анонс',
                'why' => 'Он показывается в ленте и уходит в пост Telegram вместо текста.',
            ];
        }
        if ($empty('badge')) {
            $items[] = [
                'key' => 'badge',
                'text' => 'Не выбрана рубрика',
                'why' => 'По ней читатель ориентируется в ленте и в канале.',
            ];
        }
        if ($empty('meta_title') && $empty('meta_description')) {
            $items[] = [
                'key' => 'seo',
                'text' => 'Не заполнено описание для поисковиков',
                'why' => 'Без него поисковик покажет заголовок и случайный кусок текста.',
            ];
        }
        if ($empty('hashtags')) {
            $items[] = [
                'key' => 'hashtags',
                'text' => 'Нет хештегов',
                'why' => 'По ним материал ищут внутри Telegram-канала.',
            ];
        }

        $noCaption = 0;
        foreach ($gallery as $img) {
            if (trim((string) ($img['caption'] ?? '')) === '' && trim((string) ($img['credit'] ?? '')) === '') {
                $noCaption++;
            }
        }
        if ($noCaption > 0) {
            $items[] = [
                'key' => 'captions',
                'text' => 'Без подписи: ' . $noCaption . ' ' . self::plural($noCaption, 'фото', 'фото', 'фото'),
                'why' => 'Подпись и автор снимка выводятся под фото и уходят в пост Telegram.',
            ];
        }

        // Перевод считаем только у опубликованных: черновик переводить рано.
        if (($news['status'] ?? '') === 'published') {
            foreach (self::missingLangs($news, $translatedLangs) as $code) {
                $items[] = [
                    'key' => 'lang:' . $code,
                    'text' => 'Нет перевода: ' . mb_strtoupper($code),
                    'why' => 'На этой версии сайта читатель увидит текст на другом языке.',
                ];
            }
        }

        return $items;
    }

    /**
     * Языки сайта, для которых перевода нет. Основной язык материала в счёт
     * не идёт — он и есть оригинал.
     *
     * @param array<string,mixed> $news
     * @param list<string> $translatedLangs
     * @return list<string>
     */
    private static function missingLangs(array $news, array $translatedLangs): array
    {
        if (!Database::isConnected()) {
            return [];
        }
        $own = (string) ($news['lang'] ?? \App\Models\Language::defaultCode());
        $missing = [];
        foreach (\App\Models\Language::activeCodes() as $code) {
            if ($code !== $own && !in_array($code, $translatedLangs, true)) {
                $missing[] = $code;
            }
        }

        return $missing;
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $few;
        }

        return $many;
    }
}
