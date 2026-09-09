<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\ContentLanguageNotice;
use App\Core\Locale;
use App\Core\View;
use App\Models\PhotoAlbum;

/** Публичные фотоальбомы: список галерей и просмотр альбома. */
final class AlbumController
{
    public function index(): void
    {
        $albums = PhotoAlbum::all(true, Locale::current());
        foreach ($albums as &$album) {
            $album['cover'] = PhotoAlbum::coverFor($album);
        }
        unset($album);

        View::render('site/albums', ['albums' => $albums]);
    }

    /** @param array<string, string> $params */
    public function show(array $params): void
    {
        $slug = (string) ($params['slug'] ?? '');
        $album = PhotoAlbum::findPublishedBySlug($slug, Locale::current());
        if (!$album) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $available = PhotoAlbum::availableLangsForIds([(int) $album['id']])[(int) $album['id']] ?? [];
        if (ContentLanguageNotice::renderIfMissing($available, '/albums/' . $slug)) {
            return;
        }

        View::render('site/album', [
            'album' => $album,
            'images' => PhotoAlbum::images((int) $album['id']),
        ]);
    }
}
