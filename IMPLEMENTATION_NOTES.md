# IMPLEMENTATION_NOTES

## Canonical architecture

The project has been reset to:

**Pure PHP 8.3+ / no Composer / no database / JSON-only persistence.**

The canonical specification is `promt.txt`.

`MacroRisk Engine Core.md` defines implementation architecture.

`DATA_SOURCES.md` freezes official source access.

## Removed architecture

The following are no longer part of MacroRisk:
- MySQL
- PDO
- SQL schema
- Phinx
- Composer
- PHPUnit/PHPStan as project dependencies
- Twig/Plates
- Symfony Console
- legacy embedded database migration code

## Legacy files

Legacy files that implement obsolete architecture must not be treated as source of truth.

`bootstrap.php` must eventually become a small bootstrap only.

`databese-check.php` is legacy ingestion code and will be replaced by source adapters.

`src/Engine/RiskEngine.php` will be rebuilt against the canonical mathematical contract.

`src/Repository/RiskScoreRepository.php` will be replaced by JSON repositories.

## Build order

### Phase 0 — completed by this documentation reset

- canonical architecture;
- JSON persistence decision;
- source contracts;
- official API endpoints;
- native PHP request examples;
- domain boundaries.

### Phase 1 — Core

Create:
- `src/Core/Math/Decimal.php`
- `src/Core/Storage/JsonStore.php`
- `src/Core/Storage/AtomicJsonFile.php`
- `src/Core/Http/HttpClient.php`
- `src/Core/Hash/CanonicalHasher.php`
- `src/Core/Validation/SchemaValidator.php`
- exceptions.

### Phase 2 — source adapters

Create:
- `src/Infrastructure/Source/StatCan/StatCanClient.php`
- `src/Infrastructure/Source/StatCan/StatCanSeriesReader.php`
- `src/Infrastructure/Source/BankOfCanada/BankOfCanadaClient.php`
- `src/Infrastructure/Source/OpenGovernment/OpenGovernmentClient.php`

### Phase 3 — domain

Create:
- Observation
- Series
- Indicator
- Snapshot
- Configuration
- ModelVersion
- RiskScore

### Phase 4 — ingestion

Pipeline:
source -> raw JSON -> parsed observations -> validation -> series JSON -> snapshot JSON

### Phase 5 — mathematical engine

Implement:
- transformations;
- normalization;
- eligibility;
- coverage;
- effective weights;
- rounding reconciliation;
- contributions;
- category scores;
- risk score;
- risk band;
- calculation hash.

### Phase 6 — application

Implement use cases without filesystem/HTTP leakage into Domain.

### Phase 7 — UI

Native PHP views only.

### Phase 8 — audit/backtesting

Only after the deterministic calculation path is stable.

## Testing policy

Tests are native PHP scripts.

No feature is accepted without deterministic fixtures and invariant tests.

## Immediate first modules

The first code modules are:

1. `src/Core/Math/Decimal.php`
2. `src/Core/Storage/JsonStore.php`
3. `src/Core/Http/HttpClient.php`
4. `src/Infrastructure/Source/StatCan/StatCanClient.php`
5. `src/Infrastructure/Source/BankOfCanada/BankOfCanadaClient.php`
6. `src/Infrastructure/Source/OpenGovernment/OpenGovernmentClient.php`

Only after those are stable will the Risk Engine be rewritten.
