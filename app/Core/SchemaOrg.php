<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Нативная генерация микроразметки Schema.org (JSON-LD) для госсайта:
 * Organization, NewsArticle, Event, BreadcrumbList. Чистые функции — сборка
 * массивов тестируется без вывода; render() печатает <script type=ld+json>.
 */
final class SchemaOrg
{
    /**
     * @param list<string> $sameAs список ссылок на официальные профили в соцсетях
     * @return array<string, mixed>
     */
    public static function organization(
        string $name,
        string $url,
        string $phone = '',
        string $email = '',
        string $address = '',
        string $logo = '',
        array $sameAs = []
    ): array {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'GovernmentOrganization',
            'name' => $name,
            'url' => $url,
        ];
        if ($logo !== '') {
            $data['logo'] = $logo;
        }
        if ($phone !== '') {
            $data['telephone'] = $phone;
        }
        if ($email !== '') {
            $data['email'] = $email;
        }
        if ($address !== '') {
            $data['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $address];
        }
        if ($sameAs !== []) {
            $data['sameAs'] = array_values(array_filter($sameAs));
        }

        return $data;
    }

    /**
     * Разметка новостной статьи (Google Search / Discover / Yandex).
     *
     * @param string|list<string> $image URL изображения или массив изображений в разных пропорциях
     * @param string|array<string, mixed> $publisher издатель статьи
     * @param string $dateModified дата последнего обновления
     * @param string|array<string, mixed> $author автор статьи
     * @param string $inLanguage код языка статьи (uz, ru, en)
     * @param string $articleSection рубрика / категория новости
     * @return array<string, mixed>
     */
    public static function newsArticle(
        string $title,
        string $url,
        string $datePublished,
        string $description = '',
        string|array $image = '',
        string|array $publisher = '',
        string $dateModified = '',
        string|array $author = '',
        string $inLanguage = '',
        string $articleSection = ''
    ): array {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'NewsArticle',
            'headline' => mb_substr($title, 0, 110),
            'url' => $url,
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $url,
            ],
        ];
        if ($datePublished !== '') {
            $data['datePublished'] = date('c', (int) strtotime($datePublished));
        }
        if ($dateModified !== '') {
            $data['dateModified'] = date('c', (int) strtotime($dateModified));
        } elseif ($datePublished !== '') {
            $data['dateModified'] = date('c', (int) strtotime($datePublished));
        }
        if ($description !== '') {
            $data['description'] = mb_substr($description, 0, 300);
        }
        if ($image !== '' && $image !== []) {
            $data['image'] = is_array($image) ? array_values($image) : [$image];
        }
        if ($publisher !== '' && $publisher !== []) {
            $data['publisher'] = is_array($publisher)
                ? $publisher
                : ['@type' => 'Organization', 'name' => $publisher];
        }
        if ($author !== '' && $author !== []) {
            $data['author'] = is_array($author)
                ? $author
                : ['@type' => 'Person', 'name' => $author];
        }
        if ($inLanguage !== '') {
            $data['inLanguage'] = $inLanguage;
        }
        if ($articleSection !== '') {
            $data['articleSection'] = $articleSection;
        }

        return $data;
    }

    /**
     * Разметка государственной услуги / сервиса ведомства.
     *
     * @return array<string, mixed>
     */
    public static function governmentService(
        string $name,
        string $url,
        string $serviceType = '',
        string $provider = '',
        string $description = '',
        string $areaServed = 'Uzbekistan'
    ): array {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'GovernmentService',
            'name' => $name,
            'url' => $url,
            'areaServed' => [
                '@type' => 'AdministrativeArea',
                'name' => $areaServed,
            ],
        ];
        if ($serviceType !== '') {
            $data['serviceType'] = $serviceType;
        }
        if ($provider !== '') {
            $data['provider'] = [
                '@type' => 'GovernmentOrganization',
                'name' => $provider,
            ];
        }
        if ($description !== '') {
            $data['description'] = mb_substr($description, 0, 300);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function event(
        string $title,
        string $url,
        string $startDate,
        string $location = '',
        string $description = '',
        string $image = ''
    ): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $title,
            'url' => $url,
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        ];
        if ($startDate !== '') {
            $data['startDate'] = $startDate;
        }
        if ($location !== '') {
            $data['location'] = ['@type' => 'Place', 'name' => $location];
        }
        if ($description !== '') {
            $data['description'] = mb_substr($description, 0, 300);
        }
        if ($image !== '') {
            $data['image'] = [$image];
        }

        return $data;
    }

    /**
     * @param array<int, array{0: string, 1: string}> $items [[название, url], ...]
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $items): array
    {
        $list = [];
        foreach (array_values($items) as $i => [$name, $url]) {
            $item = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $name,
            ];
            if ($url !== '') {
                $item['item'] = $url;
            }
            $list[] = $item;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $list,
        ];
    }

    /**
     * Раскрывающиеся вопросы страницы.
     *
     * Разметка обязана совпадать с тем, что видит посетитель, поэтому ответ
     * берётся текстом с самой страницы: HTML в JSON-LD не допускается, а
     * пересказ своими словами поисковик считает подлогом.
     *
     * @param list<array{question: string, answer: string}> $items
     * @return array<string, mixed> пустой массив, если размечать нечего
     */
    public static function faqPage(array $items): array
    {
        $list = [];
        foreach ($items as $item) {
            $question = trim($item['question'] ?? '');
            // Тег → пробел, иначе «первый<br>второй» склеивается в одно слово.
            $answer = trim((string) preg_replace('/\s+/u', ' ', strip_tags(str_replace('<', ' <', $item['answer'] ?? ''))));
            if ($question === '' || $answer === '') {
                continue;
            }
            $list[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
            ];
        }

        if ($list === []) {
            return [];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $list,
        ];
    }

    /**
     * Разметка вопросов уже выведена на этой странице.
     *
     * FAQPage на страницу допускается ровно один: два блока «Вопросы и ответы»
     * дали бы две разметки, и поисковик отбросил бы обе. Флаг сбрасывает
     * BlockRenderer::renderPage, как и счётчик h1.
     */
    private static bool $faqRendered = false;

    public static function resetPageState(): void
    {
        self::$faqRendered = false;
    }

    /** Первый на странице блок вопросов получает разметку, следующие — нет. */
    public static function claimFaqPage(): bool
    {
        if (self::$faqRendered) {
            return false;
        }
        self::$faqRendered = true;

        return true;
    }

    /** Печатает готовый JSON-LD блок. @param array<string, mixed> $data */
    public static function render(array $data): string
    {
        if ($data === []) {
            return '';
        }

        return '<script type="application/ld+json">'
            . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)
            . '</script>';
    }
}
