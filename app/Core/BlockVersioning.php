<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Block;
use App\Models\BlockRevision;

/**
 * Атомарное сохранение блока вместе со снимком предыдущей версии.
 */
final class BlockVersioning
{
    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $data
     */
    public static function updateWithSnapshot(
        array $current,
        ?string $title,
        array $data,
        string $customCss,
        ?int $userId,
        ?int $expectedLockVersion = null
    ): void {
        $blockId = (int) ($current['id'] ?? 0);
        if ($blockId <= 0) {
            throw new \InvalidArgumentException('Block id is required for a versioned update.');
        }

        $previousData = json_decode((string) ($current['data'] ?? '{}'), true);
        if (!is_array($previousData)) {
            $previousData = [];
        }

        Database::transaction(static function (\PDO $_pdo) use (
            $current,
            $blockId,
            $previousData,
            $title,
            $data,
            $customCss,
            $userId,
            $expectedLockVersion
        ): void {
            BlockRevision::snapshot(
                $blockId,
                $current['title'] !== null ? (string) $current['title'] : null,
                $previousData,
                $current['custom_css'] !== null ? (string) $current['custom_css'] : null,
                $userId
            );

            Block::update($blockId, $title, $data, $customCss, $expectedLockVersion);
        });
    }
}
