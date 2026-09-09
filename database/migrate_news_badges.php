<?php

declare(strict_types=1);

/*
 * Перенос старых бейджей новостей в категории (разовый скрипт).
 *
 *   php database/migrate_news_badges.php --dry-run   — показать план
 *   php database/migrate_news_badges.php             — выполнить
 *
 * До появления категорий рубрику новости изображал свободный текст в
 * `news.badge`, а её перевод — `news_translations.badge` либо бейдж отдельной
 * языковой записи. Скрипт сводит эти строки в справочник категорий:
 *
 *   1) рубрика группы перевода берётся у записи основного языка;
 *   2) бейджи остальных языков группы становятся переводами названия;
 *   3) всем записям группы проставляется category_id;
 *   4) старые бейджи очищаются — иначе на карточке рядом оказались бы
 *      рубрика и одноимённая метка.
 *
 * Совпадения ищутся без учёта регистра и лишних пробелов: «Новости», «новости»
 * и «Новости » станут одной категорией, а не тремя.
 *
 * Скрипт идемпотентен: записи с уже проставленной категорией пропускаются.
 * Метка (`badge`) после переноса свободна для своей новой роли — пометки
 * вроде «Важно».
 */

require __DIR__ . '/../app/Core/Cli.php';
\App\Core\Cli::assertCli();
require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Models\Language;
use App\Models\NewsCategory;
use App\Models\NewsCategoryTranslation;

$dryRun = in_array('--dry-run', $argv, true);
$pdo = Database::pdo();
$defaultLang = Language::defaultCode();

$rows = $pdo->query(
    "SELECT id, lang, badge, category_id, COALESCE(NULLIF(translation_group_id, 0), id) AS group_id
     FROM news
     WHERE deleted_at IS NULL"
)->fetchAll();

if ($rows === []) {
    fwrite(STDOUT, "Новостей нет — переносить нечего.\n");
    exit(0);
}

// Бейджи переводов старого механизма (news_translations.badge).
$translationBadges = [];
foreach ($pdo->query(
    "SELECT news_id, lang, badge FROM news_translations WHERE TRIM(COALESCE(badge, '')) <> ''"
)->fetchAll() as $row) {
    $translationBadges[(int) $row['news_id']][(string) $row['lang']] = trim((string) $row['badge']);
}

/** @var array<int, array{ids: list<int>, base: string, langs: array<string,string>, hasCategory: bool}> $groups */
$groups = [];
foreach ($rows as $row) {
    $groupId = (int) $row['group_id'];
    $groups[$groupId] ??= ['ids' => [], 'base' => '', 'langs' => [], 'hasCategory' => false];

    $id = (int) $row['id'];
    $lang = (string) $row['lang'];
    $badge = trim((string) ($row['badge'] ?? ''));

    $groups[$groupId]['ids'][] = $id;
    if ((int) ($row['category_id'] ?? 0) > 0) {
        $groups[$groupId]['hasCategory'] = true;
    }

    if ($badge !== '') {
        if ($lang === $defaultLang && $groups[$groupId]['base'] === '') {
            $groups[$groupId]['base'] = $badge;
        } elseif ($lang !== $defaultLang) {
            $groups[$groupId]['langs'][$lang] ??= $badge;
        }
    }

    foreach ($translationBadges[$id] ?? [] as $tLang => $tBadge) {
        if ($tLang !== $defaultLang) {
            $groups[$groupId]['langs'][$tLang] ??= $tBadge;
        }
    }
}

/** @var array<string, int> $created ключ нормализованного названия → id категории */
$created = [];
$normalize = static fn (string $value): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? '');

// Уже существующие категории тоже участвуют в сопоставлении: повторный запуск
// не должен плодить «Новости-2».
foreach (NewsCategory::all() as $existing) {
    $created[$normalize((string) $existing['name'])] = (int) $existing['id'];
}

$planned = 0;
$skipped = 0;
$sortOrder = 0;

foreach ($groups as $groupId => $group) {
    if ($group['hasCategory']) {
        $skipped++;
        continue;
    }

    // Если у основного языка бейджа не было, берём первый попавшийся из группы:
    // рубрика у группы одна, и потерять её из-за пустого основного языка нельзя.
    $base = $group['base'];
    if ($base === '' && $group['langs'] !== []) {
        $base = (string) reset($group['langs']);
    }
    if ($base === '') {
        continue;
    }

    $key = $normalize($base);
    $categoryId = $created[$key] ?? null;
    $planned++;

    if ($dryRun) {
        fwrite(STDOUT, sprintf(
            "  группа #%d (%d записей): «%s»%s%s\n",
            $groupId,
            count($group['ids']),
            $base,
            $group['langs'] !== [] ? ' + переводы: ' . implode(', ', array_map(
                static fn (string $lang, string $value): string => $lang . '=' . $value,
                array_keys($group['langs']),
                array_values($group['langs'])
            )) : '',
            $categoryId !== null ? ' (категория уже создана)' : ' (новая категория)'
        ));
        $created[$key] ??= -1; // чтобы в плане не считать одну рубрику дважды
        continue;
    }

    if ($categoryId === null) {
        $sortOrder += 10;
        $categoryId = NewsCategory::create($base, '', true, $sortOrder);
        if ($categoryId === null) {
            continue;
        }
        $created[$key] = $categoryId;
    }

    foreach ($group['langs'] as $lang => $name) {
        NewsCategoryTranslation::upsert($categoryId, $lang, $name);
    }

    $placeholders = implode(',', array_fill(0, count($group['ids']), '?'));
    $pdo->prepare("UPDATE news SET category_id = ? WHERE id IN ({$placeholders})")
        ->execute([$categoryId, ...$group['ids']]);
    $pdo->prepare("UPDATE news SET badge = NULL WHERE id IN ({$placeholders})")
        ->execute($group['ids']);
    $pdo->prepare("UPDATE news_translations SET badge = NULL WHERE news_id IN ({$placeholders})")
        ->execute($group['ids']);
}

if ($dryRun) {
    fwrite(STDOUT, sprintf(
        "\nПлан: перенести %d групп новостей, пропустить %d (категория уже задана).\nЗапуск без --dry-run применит изменения.\n",
        $planned,
        $skipped
    ));
    exit(0);
}

\App\Core\Cache::forgetPrefix('page:');
fwrite(STDOUT, sprintf(
    "Готово: перенесено групп — %d, пропущено — %d, категорий в справочнике — %d.\n",
    $planned,
    $skipped,
    count(NewsCategory::all())
));
