<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\NewsTranslation;

test('Новости: публичные списки, рубрики и связанные материалы изолированы по языку', function (): void {
    ensure_test_db();

    $pdo = Database::pdo();
    $suffix = bin2hex(random_bytes(4));
    $ids = [];
    $insert = $pdo->prepare(
        "INSERT INTO news
            (title, slug, badge, category_id, excerpt, content, status, published_at, lang, translation_group_id, views, created_at)
         VALUES
            (:title, :slug, :badge, :category_id, :excerpt, :content, :status, NOW(), :lang, :group_id, :views, NOW())"
    );

    // Рубрика у каждой записи своя: так фильтр по категории проверяется на
    // языковую изоляцию — рубрика одна на все языки, а видимость записи нет.
    $catIds = [];
    foreach (['iso-ru', 'iso-uz', 'legacy', 'shadow', 'draft'] as $key) {
        $catIds[$key] = (int) NewsCategory::create('Рубрика ' . $key . ' ' . $suffix);
    }

    try {
        $insert->execute([
            ':title' => 'RU independent ' . $suffix,
            ':slug' => 'news-lang-ru-' . $suffix,
            ':badge' => 'ISO-RU-' . $suffix,
            ':category_id' => $catIds['iso-ru'],
            ':excerpt' => 'RU excerpt',
            ':content' => '<p>RU content</p>',
            ':status' => 'published',
            ':lang' => 'ru',
            ':group_id' => null,
            ':views' => 999999901,
        ]);
        $ruId = (int) $pdo->lastInsertId();
        $ids[] = $ruId;

        $insert->execute([
            ':title' => 'UZ independent ' . $suffix,
            ':slug' => 'news-lang-uz-' . $suffix,
            ':badge' => 'ISO-UZ-' . $suffix,
            ':category_id' => $catIds['iso-uz'],
            ':excerpt' => 'UZ excerpt',
            ':content' => '<p>UZ content</p>',
            ':status' => 'published',
            ':lang' => 'uz',
            ':group_id' => $ruId,
            ':views' => 999999900,
        ]);
        $uzId = (int) $pdo->lastInsertId();
        $ids[] = $uzId;

        $insert->execute([
            ':title' => 'RU legacy ' . $suffix,
            ':slug' => 'news-lang-legacy-' . $suffix,
            ':badge' => 'LEGACY-RU-' . $suffix,
            ':category_id' => $catIds['legacy'],
            ':excerpt' => 'Legacy RU excerpt',
            ':content' => '<p>Legacy RU content</p>',
            ':status' => 'published',
            ':lang' => 'ru',
            ':group_id' => null,
            ':views' => 999999899,
        ]);
        $legacyId = (int) $pdo->lastInsertId();
        $ids[] = $legacyId;
        NewsTranslation::upsert($legacyId, 'uz', [
            'title' => 'UZ legacy ' . $suffix,
            'badge' => 'LEGACY-UZ-' . $suffix,
            'content' => '<p>Legacy UZ content</p>',
        ]);

        $insert->execute([
            ':title' => 'RU shadowed ' . $suffix,
            ':slug' => 'news-lang-shadowed-' . $suffix,
            ':badge' => 'SHADOW-RU-' . $suffix,
            ':category_id' => $catIds['shadow'],
            ':excerpt' => 'Shadow RU excerpt',
            ':content' => '<p>Shadow RU content</p>',
            ':status' => 'published',
            ':lang' => 'ru',
            ':group_id' => null,
            ':views' => 999999898,
        ]);
        $shadowRootId = (int) $pdo->lastInsertId();
        $ids[] = $shadowRootId;
        NewsTranslation::upsert($shadowRootId, 'uz', [
            'title' => 'UZ legacy must stay hidden ' . $suffix,
            'badge' => 'SHADOW-UZ-' . $suffix,
            'content' => '<p>Hidden legacy UZ content</p>',
        ]);

        $insert->execute([
            ':title' => 'UZ draft ' . $suffix,
            ':slug' => 'news-lang-draft-' . $suffix,
            ':badge' => 'DRAFT-UZ-' . $suffix,
            ':category_id' => $catIds['draft'],
            ':excerpt' => 'Draft UZ excerpt',
            ':content' => '<p>Draft UZ content</p>',
            ':status' => 'draft',
            ':lang' => 'uz',
            ':group_id' => $shadowRootId,
            ':views' => 999999897,
        ]);
        $draftUzId = (int) $pdo->lastInsertId();
        $ids[] = $draftUzId;

        $ruRows = News::published(10, 0, 'ru', $catIds['iso-ru']);
        assert_same([$ruId], array_map(static fn (array $row): int => (int) $row['id'], $ruRows));
        assert_same(1, News::publishedCount($catIds['iso-ru'], 'ru'));
        // Рубрика общая для языков, но RU-запись на UZ не показывается:
        // у неё есть самостоятельная узбекская версия.
        assert_same(0, News::publishedCount($catIds['iso-ru'], 'uz'));

        $uzRows = News::published(10, 0, 'uz', $catIds['iso-uz']);
        assert_same([$uzId], array_map(static fn (array $row): int => (int) $row['id'], $uzRows));
        assert_same(1, News::publishedCount($catIds['iso-uz'], 'uz'));
        assert_same(0, News::publishedCount($catIds['iso-uz'], 'ru'));

        $legacyRows = News::published(10, 0, 'uz', $catIds['legacy']);
        assert_same(1, count($legacyRows), 'legacy-перевод остаётся доступен до создания независимой записи');
        assert_same($legacyId, (int) $legacyRows[0]['id']);
        assert_same('UZ legacy ' . $suffix, (string) $legacyRows[0]['title']);

        assert_same([], News::published(10, 0, 'uz', $catIds['shadow']), 'legacy скрыт независимым черновиком');
        assert_same(0, News::publishedCount($catIds['shadow'], 'uz'));
        assert_same(0, News::publishedCount($catIds['draft'], 'uz'));

        $ruCats = array_map(
            static fn (array $row): int => (int) $row['id'],
            News::publishedCategories('ru')
        );
        $uzCats = array_map(
            static fn (array $row): int => (int) $row['id'],
            News::publishedCategories('uz')
        );
        assert_true(in_array($catIds['iso-ru'], $ruCats, true));
        assert_false(in_array($catIds['iso-uz'], $ruCats, true));
        assert_true(in_array($catIds['iso-uz'], $uzCats, true));
        assert_true(in_array($catIds['legacy'], $uzCats, true), 'legacy-перевод даёт рубрику узбекскому рубрикатору');
        assert_false(in_array($catIds['iso-ru'], $uzCats, true));
        assert_false(in_array($catIds['shadow'], $uzCats, true));

        $resolvedUz = News::findPublishedBySlug('news-lang-ru-' . $suffix, 'uz');
        assert_true($resolvedUz !== null);
        assert_same($uzId, (int) $resolvedUz['id'], 'чужой slug переводится в запись нужного языка');
        assert_same('news-lang-uz-' . $suffix, (string) $resolvedUz['slug']);

        $shadowed = News::findPublishedBySlug('news-lang-shadowed-' . $suffix, 'uz');
        assert_true($shadowed !== null);
        assert_same($shadowRootId, (int) $shadowed['id']);
        assert_same('RU shadowed ' . $suffix, (string) $shadowed['title'], 'черновик не обходится legacy-переводом');
        assert_false(in_array('uz', News::availableLangs($shadowRootId), true));
        assert_true(in_array('uz', News::availableLangs($ruId), true));

        $relatedUz = News::related($uzId, 12, 'uz');
        $relatedIds = array_map(static fn (array $row): int => (int) $row['id'], $relatedUz);
        assert_true(in_array($legacyId, $relatedIds, true), 'связанные новости используют допустимый legacy-перевод');
        assert_false(in_array($ruId, $relatedIds, true), 'RU-копия текущей новости не дублируется');
        assert_false(in_array($shadowRootId, $relatedIds, true), 'legacy с независимым черновиком не показывается');
        assert_false(in_array($draftUzId, $relatedIds, true), 'черновик не показывается');

        $topRuIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            News::mostViewed(0, 20, 'ru')
        );
        $topUzIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            News::mostViewed(0, 20, 'uz')
        );
        assert_true(in_array($ruId, $topRuIds, true));
        assert_false(in_array($uzId, $topRuIds, true));
        assert_true(in_array($uzId, $topUzIds, true));
        assert_false(in_array($ruId, $topUzIds, true));

        $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/NewsController.php');
        assert_contains('News::publishedCategories($lang)', $controller);
        assert_contains('News::publishedCount($categoryId > 0 ? $categoryId : null, $lang)', $controller);
        assert_contains('$requestedSlug !== $canonicalSlug', $controller, 'Чужой slug перенаправляется на canonical slug выбранного языка');
        assert_contains("Locale::url('news/' . \$canonicalSlug, \$lang)", $controller, 'Canonical redirect сохраняет языковой префикс');
    } finally {
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM news WHERE id IN ({$placeholders})")->execute($ids);
        }
        foreach ($catIds ?? [] as $catId) {
            NewsCategory::delete((int) $catId);
        }
    }
});
