<?php

declare(strict_types=1);

namespace MacroRisk\Core\Audit;

use MacroRisk\Database;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Throwable;


/**
 * MacroRisk Audit Logger (v1.5.0-comprehensive / Pure PHP Native)
 *
 * Immutable audit recording mechanism. Writes structured audit records
 * into MySQL 8.4 InnoDB `audit_records` table with high-precision UTC timestamps.
 */
final class AuditLogger
{
    /**
     * Records an audit event into the database.
     *
     * @param string $eventType Descriptive event identifier (e.g., RISK_SCORE_CALCULATED, CONFIG_OVERRIDE).
     * @param string $entityType Entity category (e.g., risk_score_results, indicator_configs).
     * @param int|null $entityId Numeric ID of entity if applicable.
     * @param string|null $entityKey String key of entity if applicable.
     * @param array<string, mixed>|null $oldValue Previous entity state for diffing.
     * @param array<string, mixed>|null $newValue New entity state for diffing.
     * @param string|null $reason Officer or system reason for event.
     * @param string $actorType User type ('system', 'risk_officer', 'api_client', 'admin').
     * @param int|null $actorUserId Numeric user ID of actor.
     * @param string|null $actorName Name of actor.
     * @param string|null $actorRole Role of actor.
     * @return string Generated unique audit_key.
     * @throws Exception
     */
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
        ?string $actorRole = 'automated_service'
    ): string {

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $createdAt = $now->format('Y-m-d H:i:s.u');
        $auditKey = sprintf('audit_%s_%s', $now->format('YmdHis_u'), bin2hex(random_bytes(6)));

        $oldJson = $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $newJson = $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        
        $diffJson = null;
        if ($oldValue !== null && $newValue !== null) {
            $diff = self::computeDiff($oldValue, $newValue);
            $diffJson = json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }


        try {
            $pdo = Database::getConnection();
            $sql = "INSERT INTO audit_records (
                        audit_key, actor_user_id, actor_name, actor_role, actor_type,
                        event_type, entity_type, entity_id, entity_key,
                        old_value_json, new_value_json, diff_json, reason, created_at
                    ) VALUES (
                        :audit_key, :actor_user_id, :actor_name, :actor_role, :actor_type,
                        :event_type, :entity_type, :entity_id, :entity_key,
                        :old_value_json, :new_value_json, :diff_json, :reason, :created_at
                    )";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':audit_key'       => $auditKey,
                ':actor_user_id'   => $actorUserId,
                ':actor_name'      => $actorName,
                ':actor_role'      => $actorRole,
                ':actor_type'      => $actorType,
                ':event_type'      => $eventType,
                ':entity_type'     => $entityType,
                ':entity_id'       => $entityId,
                ':entity_key'      => $entityKey,
                ':old_value_json'  => $oldJson,
                ':new_value_json'  => $newJson,
                ':diff_json'       => $diffJson,
                ':reason'          => $reason,
                ':created_at'      => $createdAt,
            ]);

            return $auditKey;
        } catch (Throwable $e) {
            // Fail explicitly per architecture principles
            throw new Exception("AUDIT_LOG_FAILED: Could not persist audit record: " . $e->getMessage(), 500, $e);
        }
    }


    /**
     * Computes key-by-key diff between old and new state arrays.
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private static function computeDiff(array $old, array $new): array
    {
        $diff = [];
        $allKeys = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($allKeys as $key) {
            $oldVal = $old[$key] ?? null;
            $newVal = $new[$key] ?? null;

            if ($oldVal !== $newVal) {
                $diff[$key] = [
                    'old' => $oldVal,
                    'new' => $newVal,
                ];
            }
        }

        return $diff;
    }
}