# MacroRisk Engine Core — JSON-Native Architecture

**Version:** 2.0.0-json-native  
**Status:** as-built implementation architecture (2026-08-18)  
**Target spec:** `promt.txt`

This document describes the code in this repository. Remaining gaps versus the spec are listed in `IMPLEMENTATION_NOTES.md`.

## 1. Hard constraints

MacroRisk is a Pure PHP 8.3+ application.

Forbidden:

- Composer, vendor/, Packagist
- frameworks and third-party libraries
- database servers, PDO, SQL, ORM/DBAL, migration frameworks
- template engines
- runtime LLM decision engines

Required:

- native PHP
- native PSR-4-compatible autoloader (`MacroRisk\` → `src/`)
- ext-curl, ext-json, ext-bcmath
- PHP-rendered views
- JSON persistence with atomic writes

The previous Composer and database architecture is obsolete.

## 2. Runtime layers (as built)

### Core

Technical primitives actually used:

- `MacroRisk\Core\Math\Decimal` — BCMath strings; scale 8 internal, scale 4 scores
- `MacroRisk\Core\Storage\JsonStore` + `AtomicJsonFile`
- `MacroRisk\Core\Http\HttpClient` + `CurlHttpTransport` + `HttpTransport`
- `MacroRisk\Core\Hash\CanonicalHasher`
- `MacroRisk\Core\Security\ScientificIntegrityGuard`
- `MacroRisk\Core\Audit\AuditLogger`

There is no separate Clock or SchemaValidator module.

### Domain

Types exist under `src/Domain/` (`Observation`, `Series`, `Indicator`, `Snapshot`, `RiskScore`, `Configuration`, `ScenarioRule`, `ScenarioBranch`).

The live calculation and ingestion path **does not instantiate these types**. `RiskEngine`, `IngestionService`, and the JSON repositories pass associative arrays. Domain classes remain validators for tests and future tightening.

### Application

- `IngestionService` — official fetch → raw JSON → series JSON → snapshot JSON
- `ScenarioEngine` — labelled hypothesis overlay on top of `RiskEngine`

There is no separate `CalculationService`. `DashboardController` calls `RiskEngine` directly.

### Engine

`MacroRisk\Engine\RiskEngine` is the mathematical core. It does not perform HTTP, filesystem I/O, or HTML rendering. Inputs are arrays of indicator config and snapshot values, not domain objects.

### Infrastructure

Adapters:

- `StatCan\StatCanClient` (+ `StatCanSeriesReader`, unused by ingestion)
- `BankOfCanada\BankOfCanadaClient`
- `OpenGovernment\OpenGovernmentClient` (not used by ingestion)

JSON repositories:

- `ConfigurationRepository`, `IndicatorRepository`
- `SeriesRepository`, `SnapshotRepository`
- `CalculationRepository`
- `RiskScoreRepository` — wrapper that writes via `CalculationRepository` and logs an audit event

### Controller / UI

`public/index.php` registers the autoloader and dispatches `?action=`.

| Action | Handler |
|---|---|
| `dashboard` (default) | `views/dashboard.php` via `getDashboardData()` |
| `help` | `views/help.php` |
| `scenario` | POST JSON → `handleScenario()` |
| `ingest` | `handleIngest()` |

Views are native PHP. Dashboard output is escaped with `htmlspecialchars()`.

## 3. Storage layout

Canonical root: `storage/` (see `StoragePath::storageRoot()`).

```
storage/
├── config/
│   ├── system.json
│   ├── indicators.json
│   ├── sources.json
│   ├── model_versions.json
│   └── scenario_rules.json
├── raw/{statcan,bank_of_canada,open_government}/
├── series/
├── snapshots/
├── calculations/
├── audit/
└── cache/
```

### Series (as stored)

Values are decimal strings. A typical series includes observation and transformed values:

```json
{
  "schema_version": 1,
  "indicator_key": "policy_rate",
  "status": "ok",
  "source_key": "bank_of_canada",
  "source_series_id": "V39079",
  "retrieved_at": "2026-08-14T19:05:34Z",
  "observations": [
    {
      "reference_period": "2026-08-12",
      "raw_value": "2.75000000",
      "observation_value": "2.75000000",
      "transformed_value": "2.75000000",
      "release_time": null,
      "source_status": "official"
    }
  ]
}
```

On source failure the series is still written, with `status: source_failure`, empty `observations`, and `missing_reason: source_failure`. Failures are not stored as zero.

### Snapshot

`SnapshotRepository` writes `latest.json` and a timestamped copy. Each indicator entry carries `status`, `value` (transformed), `observation_value`, vintage metadata, and source identity.

### Calculation

Each dashboard render currently persists a full `RiskEngine` result under `storage/calculations/`, including selected observations, validation statuses, effective weights, normalized scores, contributions, `calculation_status`, `risk_score`, `risk_band`, and `calculation_hash`.

## 4. Atomic JSON writes

Production writes go through `AtomicJsonFile`:

1. `tempnam` in the same directory
2. exclusive `flock`
3. write, `fflush`, `fsync` when available
4. unlock, close
5. `rename` over the target

Reads use a shared lock. Filenames cannot contain path separators.

## 5. HTTP

All official requests go through `HttpClient`. Adapters do not call curl themselves.

Enforced:

- HTTPS only
- connect timeout (default 10s) and total timeout (default 60s)
- TLS peer + host verification
- `CURLOPT_FOLLOWLOCATION = false`
- HTTP status 2xx required
- JSON decode with `JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING`

Maximum response size is configured (default 10 MiB) and checked after the body is received.

## 6. Ingestion (as built)

`IngestionService::ingest($force)`:

1. Load indicator config (`SystemPreset::validateIndicatorWeightSum`)
2. For each indicator, skip fetch when the series is still fresh for its frequency (unless `$force`)
3. Bank of Canada: `getObservations` + `getSeries`; identity transform (housing starts annualization is StatCan-only)
4. StatCan: `getSeriesInfo` + `getLatestPeriods`; optional `year_over_year_percent_change` or `annualize_monthly_total_units`
5. Write raw payload, series JSON, then a snapshot
6. Audit `INGESTION_COMPLETED`

Freshness: dashboard reuses a snapshot less than six hours old; otherwise it calls `ingest(false)`.

Transformations currently in config:

- CPI: YoY percent from the CPI index (`observations[i]` vs `observations[i - 12]`)
- Housing starts: monthly units × `12.00000000` (also special-cased when `indicator_key === housing_starts`)

## 7. Calculation boundary

`RiskEngine::calculate($indicatorConfigs, $snapshotIndicators, $systemConfig, $context, $calculationMode)` returns an array.

It does not:

- perform HTTP
- read or write files
- know source URLs
- write audit records
- render HTML

Production mode is `"production"`. Scenario overrides use `"simulation"`.

## 8. Views

PHP views are rendered with native `require`.

External text is escaped unless explicitly trusted markup. No template engine.

The dashboard scenario sliders convert values through JavaScript `Number` and `toFixed(8)` before POST; the engine then parses those strings as `Decimal`.

## 9. Testing

Native PHP test scripts are sufficient. The modules with dedicated runnable scripts are Decimal, RiskEngine, ScenarioEngine, and fixture-based ingestion.

A module is not complete until:

- happy path passes
- malformed input is rejected
- deterministic output is asserted
- failure states are asserted
- no float enters the calculation path (`CanonicalHasher` rejects floats)
