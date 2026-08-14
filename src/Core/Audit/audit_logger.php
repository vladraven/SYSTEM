<?php

declare(strict_types=1);

namespace MacroRisk\Core\Audit;

use DateTimeImmutable;
use DateTimeZone;
use MacroRisk\Core\Storage\AtomicJsonFile;
use MacroRisk\Core\Storage\JsonStore;
use MacroRisk\Infrastructure\Storage\StoragePath;

final class AuditLogger
{
    public static function log(
        string $eventType,
        string $entityType,
        ?int $entityId = null,
        ?string $entityKey = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $reason = null,
        string $actorType = 'system',
        ?int $actorUserId = null,
        ?string $actorName = 'MacroRisk System Engine',
        ?string $actorRole = 'automated_service',
        ?string $storageRoot = null
    ): string {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $auditKey = sprintf('audit_%s_%s', $now->format('Ymd_His_u'), bin2hex(random_bytes(6)));
        $root = StoragePath::storageRoot($storageRoot);
        $store = new JsonStore(new AtomicJsonFile($root . DIRECTORY_SEPARATOR . 'audit'));
        $entry = [
            'schema_version' => 1,
            'audit_key' => $auditKey,
            'created_at' => $now->format(DATE_ATOM),
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_key' => $entityKey,
            'reason' => $reason,
            'actor' => [
                'type' => $actorType,
                'user_id' => $actorUserId,
                'name' => $actorName,
                'role' => $actorRole,
            ],
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'diff' => self::computeDiff($oldValue, $newValue),
        ];

        $store->write($auditKey . '.json', $entry);

        return $auditKey;
    }

    private static function computeDiff(?array $old, ?array $new): array
    {
        if ($old === null || $new === null) {
            return [];
        }

        $diff = [];
        $allKeys = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));

        foreach ($allKeys as $key) {
            $previous = $old[$key] ?? null;
            $current = $new[$key] ?? null;

            if ($previous !== $current) {
                $diff[(string) $key] = [
                    'old' => $previous,
                    'new' => $current,
                ];
            }
        }

        return $diff;
    }
}
