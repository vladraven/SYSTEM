<?php

declare(strict_types=1);

namespace MacroRisk;

use PDO;
use PDOException;
use Exception;
use DateTimeImmutable;
use DateTimeZone;

/* STREAMING_CHUNK:Initializing PSR-4 Autoloader and environment loading... */

// -----------------------------------------------------------------------------
// 1. PSR-4 Native Autoloader (No Composer Required)
// -----------------------------------------------------------------------------
spl_autoload_register(function (string $class): void {
    $prefix = 'MacroRisk\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

/* STREAMING_CHUNK:Defining DotEnv Loader... */

// -----------------------------------------------------------------------------
// 2. Native Environment File (.env) Parser
// -----------------------------------------------------------------------------
final class Environment
{
    public static function load(string $path = __DIR__ . '/.env'): void
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);

                // Strip quotes if present
                if (preg_match('/^"(.*)"$/', $value, $matches) || preg_match('/^\'(.*)\'$/', $value, $matches)) {
                    $value = $matches[1];
                }

                $_ENV[$name] = $value;
                putenv("{$name}={$value}");
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
}

// Load .env configuration
Environment::load();

/* STREAMING_CHUNK:Defining DecimalMath precision class... */

// -----------------------------------------------------------------------------
// 3. High-Precision BCMath Decimal Math Helper (Zero Float Usage)
// -----------------------------------------------------------------------------
final class DecimalMath
{
    public const SCALE_RAW = 8;     // DECIMAL(24,8)
    public const SCALE_SCORE = 4;   // DECIMAL(10,4)

    public static function add(string $a, string $b, int $scale = self::SCALE_SCORE): string
    {
        return bcadd(self::clean($a), self::clean($b), $scale);
    }

    public static function sub(string $a, string $b, int $scale = self::SCALE_SCORE): string
    {
        return bcsub(self::clean($a), self::clean($b), $scale);
    }

    public static function mul(string $a, string $b, int $scale = self::SCALE_SCORE): string
    {
        return bcmul(self::clean($a), self::clean($b), $scale);
    }

    public static function div(string $a, string $b, int $scale = self::SCALE_SCORE): string
    {
        $bClean = self::clean($b);
        if (bccomp($bClean, '0', $scale) === 0) {
            throw new Exception("BCMath Division by Zero error.");
        }
        return bcdiv(self::clean($a), $bClean, $scale);
    }

    public static function comp(string $a, string $b, int $scale = self::SCALE_SCORE): int
    {
        return bccomp(self::clean($a), self::clean($b), $scale);
    }

    public static function abs(string $val, int $scale = self::SCALE_SCORE): string
    {
        $clean = self::clean($val);
        if (bccomp($clean, '0', $scale) < 0) {
            return bcmul($clean, '-1', $scale);
        }
        return bcmul($clean, '1', $scale);
    }

    public static function min(string $a, string $b, int $scale = self::SCALE_SCORE): string
    {
        return self::comp($a, $b, $scale) <= 0 ? self::clean($a, $scale) : self::clean($b, $scale);
    }

    public static function max(string $a, string $b, int $scale = self::SCALE_SCORE): string
    {
        return self::comp($a, $b, $scale) >= 0 ? self::clean($a, $scale) : self::clean($b, $scale);
    }

    public static function clean(string $val, int $scale = self::SCALE_SCORE): string
    {
        $trimmed = trim($val);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return sprintf('%.*f', $scale, 0);
        }
        return bcadd($trimmed, '0', $scale);
    }
}

/* STREAMING_CHUNK:Configuring PDO Database connection manager... */

// -----------------------------------------------------------------------------
// 4. PDO MySQL 8.4 Connection Manager
// -----------------------------------------------------------------------------
final class Database
{
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO
    {
        if (self::$pdo === null) {
            $host    = Environment::get('MACRORISK_DB_HOST', 'localhost');
            $port    = Environment::get('MACRORISK_DB_PORT', '3306');
            $dbname  = Environment::get('MACRORISK_DB_NAME', 'webtest_economic_digital_twin');
            $user    = Environment::get('MACRORISK_DB_USER', 'webtest_macrorisk');
            $pass    = Environment::get('MACRORISK_DB_PASSWORD', '');
            $charset = Environment::get('MACRORISK_DB_CHARSET', 'utf8mb4');

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci",
            ];

            try {
                self::$pdo = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                throw new Exception("MacroRisk Database Connection Failed: " . $e->getMessage());
            }
        }

        return self::$pdo;
    }
}

/* STREAMING_CHUNK:Implementing Native Database DDL Migrator... */

// -----------------------------------------------------------------------------
// 5. Native DDL Schema Migrator (Pure PHP MySQL 8.4)
// -----------------------------------------------------------------------------
final class NativeMigrator
{
    public static function runMigrations(): array
    {
        $pdo = Database::getConnection();
        $executed = [];

        $queries = [
            "CREATE TABLE IF NOT EXISTS risk_score_results (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                score_key VARCHAR(128) NOT NULL UNIQUE,
                configuration_version_id BIGINT UNSIGNED NOT NULL,
                model_version_id BIGINT UNSIGNED NOT NULL,
                vintage_date DATETIME(6) NOT NULL,
                calculation_mode VARCHAR(32) NOT NULL,
                calculation_status VARCHAR(64) NOT NULL,
                risk_score DECIMAL(10,4) NULL,
                risk_band VARCHAR(32) NULL,
                coverage_ratio DECIMAL(10,4) NOT NULL,
                demographic_pressure_index DECIMAL(10,4) NULL,
                available_indicator_count INT UNSIGNED NOT NULL,
                configured_indicator_count INT UNSIGNED NOT NULL,
                required_indicator_missing BOOLEAN NOT NULL,
                effective_weights_sum DECIMAL(10,4) NOT NULL,
                data_cutoff_date DATETIME(6) NULL,
                active_indicators_status_hash CHAR(64) NOT NULL,
                calculation_hash CHAR(64) NOT NULL,
                calculation_explanation TEXT NULL,
                scientific_integrity_note TEXT NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME(6) NOT NULL,
                INDEX idx_config_vintage (configuration_version_id, vintage_date),
                INDEX idx_status (calculation_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            "CREATE TABLE IF NOT EXISTS risk_score_indicator_contributions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                risk_score_result_id BIGINT UNSIGNED NOT NULL,
                indicator_config_id BIGINT UNSIGNED NOT NULL,
                series_id BIGINT UNSIGNED NOT NULL,
                observation_id BIGINT UNSIGNED NULL,
                snapshot_observation_id BIGINT UNSIGNED NULL,
                raw_value DECIMAL(24,8) NULL,
                transformed_value DECIMAL(24,8) NULL,
                normalized_indicator_score DECIMAL(10,4) NULL,
                original_weight DECIMAL(10,4) NOT NULL,
                frequency_discount DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
                effective_weight DECIMAL(10,4) NULL,
                contribution_value DECIMAL(10,4) NULL,
                is_available BOOLEAN NOT NULL,
                is_required BOOLEAN NOT NULL,
                missing_reason VARCHAR(64) NULL,
                release_date_quality VARCHAR(64) NULL,
                warning_code VARCHAR(64) NULL,
                created_at DATETIME(6) NOT NULL,
                FOREIGN KEY (risk_score_result_id) REFERENCES risk_score_results(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            "CREATE TABLE IF NOT EXISTS indicator_configs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                configuration_version_id BIGINT UNSIGNED NOT NULL,
                series_id BIGINT UNSIGNED NOT NULL,
                indicator_key VARCHAR(128) NOT NULL,
                category ENUM('macro', 'financial', 'housing', 'demographics', 'fiscal') NOT NULL DEFAULT 'macro',
                original_weight DECIMAL(10,4) NOT NULL,
                is_required BOOLEAN NOT NULL DEFAULT FALSE,
                transformation_type VARCHAR(64) NOT NULL,
                normalization_method VARCHAR(64) NOT NULL,
                direction_of_deterioration VARCHAR(64) NOT NULL,
                low_risk_threshold DECIMAL(24,8) NULL,
                high_risk_threshold DECIMAL(24,8) NULL,
                target_value DECIMAL(24,8) NULL,
                max_deviation DECIMAL(24,8) NULL,
                safe_min DECIMAL(24,8) NULL,
                safe_max DECIMAL(24,8) NULL,
                clamp_min DECIMAL(24,8) NULL,
                clamp_max DECIMAL(24,8) NULL,
                maximum_allowed_weight DECIMAL(10,4) NULL,
                frequency_discount DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
                risk_officer_override BOOLEAN NOT NULL DEFAULT FALSE,
                override_reason TEXT NULL,
                override_approved_by BIGINT UNSIGNED NULL,
                production_allowed BOOLEAN NOT NULL DEFAULT TRUE,
                created_at DATETIME(6) NOT NULL,
                UNIQUE KEY uq_config_indicator (configuration_version_id, indicator_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            "CREATE TABLE IF NOT EXISTS snapshot_observations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                snapshot_id BIGINT UNSIGNED NOT NULL,
                series_id BIGINT UNSIGNED NOT NULL,
                observation_id BIGINT UNSIGNED NOT NULL,
                vintage_date DATETIME(6) NOT NULL,
                release_date DATETIME(6) NULL,
                estimated_release_date DATETIME(6) NULL,
                release_date_quality VARCHAR(64) NOT NULL,
                release_date_source VARCHAR(64) NOT NULL,
                reproducibility_allowed BOOLEAN NOT NULL DEFAULT TRUE,
                revision_number INT UNSIGNED NULL,
                is_revision BOOLEAN NOT NULL DEFAULT FALSE,
                previous_observation_id BIGINT UNSIGNED NULL,
                quality_flag VARCHAR(64) NULL,
                created_at DATETIME(6) NOT NULL,
                UNIQUE KEY uq_snapshot_series_obs (snapshot_id, series_id, observation_id),
                INDEX idx_series_vintage (series_id, vintage_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            "CREATE TABLE IF NOT EXISTS data_sources (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_key VARCHAR(64) NOT NULL UNIQUE,
                display_name VARCHAR(255) NOT NULL,
                source_type VARCHAR(64) NOT NULL,
                base_url VARCHAR(1024) NOT NULL,
                official_documentation_url VARCHAR(1024) NOT NULL,
                terms_of_use_url VARCHAR(1024) NULL,
                license_status ENUM('public_open', 'public_open_candidate', 'requires_license', 'unverified') NOT NULL DEFAULT 'unverified',
                production_allowed BOOLEAN NOT NULL DEFAULT FALSE,
                notes TEXT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            "CREATE TABLE IF NOT EXISTS audit_records (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                audit_key VARCHAR(128) NOT NULL UNIQUE,
                actor_user_id BIGINT UNSIGNED NULL,
                actor_name VARCHAR(255) NULL,
                actor_role VARCHAR(64) NULL,
                actor_type VARCHAR(32) NOT NULL,
                event_type VARCHAR(128) NOT NULL,
                entity_type VARCHAR(128) NOT NULL,
                entity_id BIGINT UNSIGNED NULL,
                entity_key VARCHAR(128) NULL,
                old_value_json JSON NULL,
                new_value_json JSON NULL,
                diff_json JSON NULL,
                reason TEXT NULL,
                created_at DATETIME(6) NOT NULL,
                INDEX idx_entity (entity_type, entity_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
        ];

        foreach ($queries as $idx => $sql) {
            $pdo->exec($sql);
            $executed[] = "Table DDL #" . ($idx + 1) . " verified/executed.";
        }

        return $executed;
    }
}