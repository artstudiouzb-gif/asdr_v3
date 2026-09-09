<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\ContentLanguageNotice;
use App\Core\Locale;
use App\Core\View;
use App\Models\Project;

/** Публичный раздел «Проекты»: список и детальная страница. */
final class ProjectController
{
    public function index(): void
    {
        $lang = Locale::current();
        View::render('site/projects_index', [
            'items' => Project::published($lang),
            'sectionPage' => \App\Core\SectionPage::render('projects', $lang),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(array $params): void
    {
        $slug = (string) ($params['slug'] ?? '');
        $project = Project::findPublishedBySlug($slug, Locale::current());
        if (!$project) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $available = Project::availableLangs((int) $project['id']);
        if (ContentLanguageNotice::renderIfMissing($available, '/projects/' . $slug)) {
            return;
        }
        Locale::setContentLangs($available);
        Locale::setAlternatePaths(\App\Core\TranslationGroupHelper::publishedPaths('projects', (int) $project['id'], 'projects/'));

        // Содержимое проекта — блоки той же записи: проект это страница с
        // подтипом, и собирается он тем же конвейером, что и страница.
        $rendered = \App\Core\PageBlocks::compile((int) $project['id'], Locale::current());

        View::render('site/project_show', [
            'project' => $project,
            'content' => $rendered['html'],
            'blockCss' => $rendered['css'],
            'preloadImages' => $rendered['preload_images'],
        ]);
    }
}
