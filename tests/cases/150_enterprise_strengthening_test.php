<?php

declare(strict_types=1);

use App\Core\MediaOptimizer;
use App\Core\WafGuard;
use App\Core\AiAssistantService;
use App\Core\RbacGuard;
use App\Core\S3BackupAdapter;
use App\Controllers\Site\SitemapController;

test('Усиление системы: Sitemap & RSS генерация', function (): void {
    ensure_test_db();
    ob_start();
    $controller = new SitemapController();
    $controller->sitemap();
    $sitemapXml = ob_get_clean();

    assert_true(str_contains($sitemapXml, '<urlset'), 'Sitemap содержит urlset');
    assert_true(str_contains($sitemapXml, 'xmlns:xhtml'), 'Sitemap содержит xhtml теги для языков');

    ob_start();
    $controller->rss();
    $rssXml = ob_get_clean();

    assert_true(str_contains($rssXml, '<rss version="2.0"'), 'RSS содержит валидный элемент rss');
});

test('Sitemap использует canonical URL каждой языковой версии новости', function (): void {
    ensure_test_db();

    $defaultLang = \App\Models\Language::defaultCode();
    $targetLang = null;
    foreach (\App\Models\Language::active() as $language) {
        $code = (string) ($language['code'] ?? '');
        if ($code !== '' && $code !== $defaultLang) {
            $targetLang = $code;
            break;
        }
    }
    if ($targetLang === null) {
        skip_test('Нет второго активного языка');
    }

    $suffix = bin2hex(random_bytes(4));
    $originalSlug = 'canonical-news-' . $suffix;
    $translatedSlug = 'canonical-news-' . $targetLang . '-' . $suffix;
    $originalId = \App\Models\News::create([
        'title' => 'Canonical sitemap test',
        'slug' => $originalSlug,
        'excerpt' => null,
        'content' => '<p>Test</p>',
        'status' => 'published',
        'published_at' => date('Y-m-d H:i:s'),
        'lang' => $defaultLang,
    ]);
    $translatedId = \App\Core\TranslationGroupHelper::createTranslation('news', $originalId, $targetLang);

    try {
        \App\Core\Database::pdo()->prepare(
            "UPDATE news SET slug = :slug, status = 'published', published_at = NOW() WHERE id = :id"
        )->execute([':slug' => $translatedSlug, ':id' => $translatedId]);

        ob_start();
        (new SitemapController())->sitemap();
        $xml = (string) ob_get_clean();

        $defaultUrl = 'http://localhost' . \App\Core\Locale::url('news/' . $originalSlug, $defaultLang);
        $translatedUrl = 'http://localhost' . \App\Core\Locale::url('news/' . $translatedSlug, $targetLang);

        assert_contains('<loc>' . $defaultUrl . '</loc>', $xml, 'Основная версия использует canonical URL');
        assert_contains('<loc>' . $translatedUrl . '</loc>', $xml, 'Перевод использует свой slug и языковой префикс');
        assert_contains('hreflang="' . $targetLang . '" href="' . $translatedUrl . '"', $xml, 'hreflang совпадает с canonical перевода');
        assert_contains('hreflang="x-default" href="' . $defaultUrl . '"', $xml, 'x-default ведёт на основной язык');
    } finally {
        \App\Models\News::forceDelete($translatedId);
        \App\Models\News::forceDelete($originalId);
    }
});

test('Публичные шаблоны не содержат статические inline-стили компонентов', function (): void {
    foreach ([
        APP_ROOT . '/app/Views/site/news_show.php',
        APP_ROOT . '/templates/widgets/subscribe.php',
        APP_ROOT . '/templates/blocks/form.php',
        APP_ROOT . '/app/Views/site/_footer.php',
    ] as $file) {
        $source = (string) file_get_contents($file);
        assert_not_contains('style="display:', $source, basename($file) . ': display вынесен из разметки');
        assert_not_contains('style="margin-', $source, basename($file) . ': отступы вынесены из разметки');
        assert_not_contains('style="color:', $source, basename($file) . ': цвета вынесены из разметки');
    }
});

test('Усиление системы: MediaOptimizer srcset generation', function (): void {
    $html = MediaOptimizer::renderImg('/assets/images/hero.jpg', 'Hero Image', 'hero-class');
    assert_true(str_contains($html, '<img src="/assets/images/hero.jpg"'), 'Рендерит базовый src');
    assert_true(str_contains($html, 'loading="lazy"'), 'Добавляет атрибут lazy loading');
});

test('Усиление системы: AI Assistant metadata extraction', function (): void {
    $metaTitle = AiAssistantService::generateMetaTitle('Заголовок тестовой статьи для проверки SEO');
    assert_true(mb_strlen($metaTitle) <= 60, 'Meta title ограничен 60 символами');

    $desc = AiAssistantService::generateMetaDescription('Это тестовое описание новости для генерации метаданных Яндекс и Google.');
    assert_true(mb_strlen($desc) <= 160, 'Meta description ограничен 160 символами');
});

test('ИИ-редактор: локальный резерв анализирует весь текст и соблюдает SEO-лимиты', function (): void {
    $fields = AiAssistantService::generateLocalNewsFields(
        'Агентство запустило программу цифровизации регионов',
        'Пресс-служба сообщает о состоявшемся мероприятии. '
        . 'В обсуждении приняли участие представители разных организаций. '
        . 'Программа цифровизации регионов охватит 12 областей и создаст 40 новых электронных услуг для жителей.'
    );

    assert_contains('12 областей', $fields['excerpt'], 'В анонс попадает ключевой факт из всего материала');
    assert_not_contains('Пресс-служба сообщает', $fields['excerpt'], 'Анонс не копирует служебное начало');
    assert_true(mb_strlen($fields['meta_title']) <= 60, 'Meta Title не превышает 60 символов');
    assert_true(mb_strlen($fields['meta_description']) <= 160, 'Meta Description не превышает 160 символов');
    assert_true(substr_count($fields['hashtags'], '#') >= 3, 'Локальный резерв формирует тематические хештеги');
});

test('ИИ-редактор: форма содержит отдельные действия и не дублирует HTTP-запрос', function (): void {
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/news/form.php');
    $adminJs = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');
    $service = (string) file_get_contents(APP_ROOT . '/app/Core/AiAssistantService.php');

    assert_contains('data-ai-generate="summary"', $form, 'Есть генерация аннотации');
    assert_contains('data-ai-generate="meta_title"', $form, 'Есть генерация Meta Title');
    assert_contains('data-ai-generate="meta_description"', $form, 'Есть генерация Meta Description');
    assert_not_contains("fetch('/admin/news/ai-summary'", $form, 'Во вью нет второго обработчика запроса');
    assert_same(1, substr_count($adminJs, "fetch('/admin/news/ai-summary'"), 'Запрос выполняет единый глобальный обработчик');
    assert_contains("'responseJsonSchema' =>", $service, 'Gemini получает строгую JSON-схему ответа');
    assert_not_contains(':generateContent?key=', $service, 'API-ключ не передаётся в URL');
});

test('Усиление системы: RBAC права доступа', function (): void {
    assert_true(RbacGuard::roleCan('admin', 'manage_users'), 'Администратор может управлять пользователями');
    assert_true(RbacGuard::roleCan('admin', 'publish_content'), 'Администратор может публиковать контент');
    assert_false(RbacGuard::roleCan('editor', 'manage_users'), 'Редактор не управляет пользователями');
    assert_false(RbacGuard::roleCan('editor', 'manage_submissions'), 'Редактор не читает персональные данные заявок');
    assert_true(RbacGuard::roleCan('editor', 'publish_content'), 'Редактор может публиковать контент');
});

test('Усиление системы: S3 Backup Adapter проверка инициализации', function (): void {
    $result = S3BackupAdapter::upload('/non/existent/file.zip');
    assert_false($result, 'Ненесуществующий файл отклоняется');
});
