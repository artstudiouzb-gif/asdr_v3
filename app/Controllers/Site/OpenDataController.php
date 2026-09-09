<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Models\ContentEntry;
use App\Models\ContentType;
use App\Models\News;

/**
 * «Открытые данные» (data.gov.uz): изолированная отдача контента госсайта в
 * машинночитаемом JSON. Датасеты: новости + все публичные типы контента.
 * Ответы кэшируются на диск на 24 часа (жёсткое кэширование) и отдаются с
 * Cache-Control/CORS-заголовками для автоматических интеграций.
 */
final class OpenDataController
{
    private const CACHE_TTL = 86400; // 24 часа
    private const MAX_ITEMS = 1000;

    /** Индекс: список доступных датасетов. */
    public function index(): void
    {
        $base = $this->baseUrl();
        $datasets = [
            ['id' => 'news', 'title' => 'Новости', 'url' => $base . '/opendata/news.json'],
        ];
        foreach (ContentType::allPublic() as $type) {
            $datasets[] = [
                'id' => (string) $type['slug'],
                'title' => (string) $type['name'],
                'url' => $base . '/opendata/' . $type['slug'] . '.json',
            ];
        }

        $this->respond([
            'generator' => 'ASDR CMS Open Data',
            'version' => '1.0',
            'generated_at' => gmdate('c'),
            'datasets' => $datasets,
        ], 'index');
    }

    /**
     * Датасет: /opendata/{dataset}.json или /opendata/{dataset}.csv
     * @param array<string, string> $params
     */
    public function dataset(array $params): void
    {
        $raw = (string) ($params['dataset'] ?? '');
        $isCsv = str_ends_with($raw, '.csv') || ($_GET['format'] ?? '') === 'csv';
        if (str_ends_with($raw, '.json')) {
            $raw = substr($raw, 0, -5);
        } elseif (str_ends_with($raw, '.csv')) {
            $raw = substr($raw, 0, -4);
        }
        $slug = strtolower((string) preg_replace('/[^a-z0-9\-_]/i', '', $raw));
        if ($slug === '') {
            $this->notFound();
            return;
        }

        $payload = $slug === 'news' ? $this->newsDataset() : $this->typeDataset($slug);
        if ($payload === null) {
            $this->notFound();
            return;
        }

        if ($isCsv) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $slug . '.csv"');
            $out = fopen('php://output', 'w');
            if ($out !== false) {
                fputs($out, "\xEF\xBB\xBF");
                $items = (array) ($payload['data'] ?? []);
                if ($items !== []) {
                    fputcsv($out, array_keys((array) $items[0]), ';');
                    foreach ($items as $row) {
                        fputcsv($out, array_values((array) $row), ';');
                    }
                }
                fclose($out);
            }
            return;
        }

        $cached = $this->cacheGet($slug);
        if ($cached !== null) {
            $this->output($cached);
            return;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->cachePut($slug, (string) $json);
        $this->output((string) $json);
    }

    /** @return array<string, mixed> */
    private function newsDataset(): array
    {
        $base = $this->baseUrl();
        $items = [];
        foreach (News::published(self::MAX_ITEMS) as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'slug' => (string) $row['slug'],
                'url' => $base . '/news/' . $row['slug'],
                'published_at' => (string) ($row['published_at'] ?? ''),
                'excerpt' => (string) ($row['excerpt'] ?? ''),
            ];
        }

        return $this->envelope('news', 'Новости', $items);
    }

    /** @return array<string, mixed>|null */
    private function typeDataset(string $slug): ?array
    {
        $type = ContentType::findBySlug($slug);
        if (!$type || (int) ($type['is_public'] ?? 0) !== 1) {
            return null;
        }

        $base = $this->baseUrl();
        $items = [];
        foreach (ContentEntry::forTypePublic((int) $type['id'], '', 'new', self::MAX_ITEMS, 0) as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'slug' => (string) $row['slug'],
                'url' => $base . '/' . ContentType::entryPath($type, (string) $row['slug']),
                'created_at' => (string) $row['created_at'],
                'fields' => json_decode((string) ($row['data'] ?? '{}'), true) ?: new \stdClass(),
            ];
        }

        return $this->envelope($slug, (string) $type['name'], $items);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function envelope(string $id, string $title, array $items): array
    {
        return [
            'dataset' => $id,
            'title' => $title,
            'generated_at' => gmdate('c'),
            'count' => count($items),
            'items' => $items,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function respond(array $payload, string $cacheKey): void
    {
        $cached = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            $this->output($cached);
            return;
        }
        // Данные приходят из контента, то есть кодировка бывает битой.
        // Приведение `(string)` отдало бы потребителю пустое тело **и
        // положило бы его в кэш** — до сброса раздел открытых данных был бы
        // пуст без единой ошибки в журнале. Замена негодного байта оставляет
        // выгрузку целой.
        $json = (string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        $this->cachePut($cacheKey, $json);
        $this->output($json);
    }

    private function output(string $json): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: public, max-age=' . self::CACHE_TTL);
        header('Access-Control-Allow-Origin: *');
        header('X-Content-Type-Options: nosniff');
        echo $json;
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'dataset not found'], JSON_UNESCAPED_UNICODE);
    }

    // --- Дисковый кэш с TTL 24 часа (изолирован от постраничного кэша) ---

    private function cacheDir(): string
    {
        return APP_ROOT . '/storage/cache/opendata';
    }

    private function cacheGet(string $key): ?string
    {
        $file = $this->cacheDir() . '/' . $key . '.json';
        if (!is_file($file) || time() - (int) filemtime($file) > self::CACHE_TTL) {
            return null;
        }
        $raw = file_get_contents($file);

        return $raw === false ? null : $raw;
    }

    private function cachePut(string $key, string $json): void
    {
        $dir = $this->cacheDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($dir . '/' . $key . '.json', $json, LOCK_EX);
    }

    private function baseUrl(): string
    {
        return \App\Core\AppUrl::base();
    }
}
