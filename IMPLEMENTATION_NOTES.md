# IMPLEMENTATION_NOTES

**As of:** 2026-08-18  
**Code:** `main` @ merge of PR #1 (`aae760f`)  
**Spec:** `promt.txt` remains the target contract. This file describes what the repository actually contains.

## Canonical architecture (in force)

Pure PHP 8.3+ / no Composer / no database / JSON-only persistence.

- `promt.txt` — target specification
- `MacroRisk Engine Core.md` — as-built architecture
- `DATA_SOURCES.md` — official source HTTP contracts

## Removed architecture

Not part of MacroRisk:

- MySQL, PDO, SQL schema, Phinx
- Composer, PHPUnit/PHPStan as project dependencies
- Twig / Plates
- Symfony Console
- embedded database migrators

The files `bootstrap.php` and `databese-check.php` named in earlier notes are gone.

## As-built tree

```
public/index.php          front controller + autoloader
src/
  Application/            IngestionService, ScenarioEngine
  Config/SystemPreset.php default configs + weight/probability checks
  Controller/             DashboardController
  Core/                   Decimal, JsonStore, AtomicJsonFile, HttpClient,
                          CanonicalHasher, ScientificIntegrityGuard, AuditLogger
  Domain/                 value objects (present; calculation path uses arrays)
  Engine/RiskEngine.php   BCMath risk calculation
  Infrastructure/Source/  StatCan, BankOfCanada, OpenGovernment clients
  Infrastructure/Storage/ JSON repositories
  Repository/             RiskScoreRepository (thin wrapper over CalculationRepository)
views/                    dashboard.php, help.php
storage/                  production JSON datastore
tests/                    native PHP scripts + HTTP fixtures
```

Not present (named in the original build plan):

- `src/Core/Validation/SchemaValidator.php`
- `src/Core/Time/` clock module
- `src/Application/Calculation/` use-case (controller calls `RiskEngine` directly)
- `ModelVersion` domain type (versions live in `storage/config/model_versions.json`)

## Phase status

| Phase | Spec intent | Status |
|---|---|---|
| 0 Documentation and source contracts | Frozen contracts | Done |
| 1 Core native infrastructure | Decimal, JSON, HTTP, hasher | Done except SchemaValidator |
| 2 Official source adapters | StatCan, BoC, CKAN | Clients exist; ingestion uses StatCan + BoC only. Ingestion talks to `StatCanClient` directly, not `StatCanSeriesReader` |
| 3 Domain types | Observation … RiskScore | Classes exist with validators. Runtime path uses associative arrays |
| 4 Ingestion | source → raw → series → snapshot | Done for the five live indicators |
| 5 Mathematical engine | normalize, coverage, weights, hash | Done. Category scores are not computed |
| 6 Application / dashboard | PHP views | Done (`dashboard`, `help`, `scenario`, `ingest`) |
| 7 Audit | JSON audit log | Done (`AuditLogger` writes `storage/audit/`) |
| 8 Backtesting | vintage replay | Not started |

## Production calculation (what the engine actually does)

`MacroRisk\Engine\RiskEngine` implements `promt.txt` §§9–13 for the five configured indicators:

- configured `original_weight` must sum to `100.0000`
- eligibility from snapshot `status` in `{ok, cached, user_override}` plus a non-empty string `value`
- coverage on eligible original weights, independent of frequency discounts
- statuses: `ok`, `insufficient_data`, `low_coverage`, `required_indicator_missing`, `missing_no_historical_data`
- non-`ok` results persist `risk_score = null` and `risk_band = null`
- effective weights after discounts, renormalized to `100.0000` with round-half-away-from-zero reconciliation
- normalization: `threshold_linear`, `distance_from_target_is_riskier`, `outside_band_is_riskier`
- risk bands from `storage/config/system.json` (not engine constants)
- canonical SHA-256 over the calculation payload

Not implemented from §10: production source/license gating as a separate eligibility check.

## Scenario / Hypothesis Engine

`ScenarioEngine` is production code, not a forecast:

- slider overrides are tagged `status: user_override` and `calculation_mode: simulation`
- rules in `storage/config/scenario_rules.json`
- branch probability weights must sum to `100.0000`
- all generated scenario text is screened by `ScientificIntegrityGuard`

## Tests

Runnable native scripts:

- `tests/test_decimal.php`
- `tests/test_risk_engine.php`
- `tests/test_scenario.php`
- `tests/test_ingestion.php` (HTTP fixtures, no live network)

Additional `*Test.php` scripts under `tests/Core`, `tests/Domain`, and `tests/Infrastructure` are also native PHP (they are not PHPUnit). CI (`.github/workflows/php.yml`) only runs `php -l`.

## Remaining shims (still on the request path)

- `src/Repository/RiskScoreRepository.php` — wrapper over `CalculationRepository` plus an audit event
- `src/Core/Audit/AuditLogger.php` requiring `audit_logger.php` — class-load split for case-sensitive filesystems

## Known gaps versus `promt.txt`

These remain true of the current tree:

1. Domain objects are not the calculation boundary; `RiskEngine` accepts arrays. `Domain\Risk\RiskScore` expects `vintage_date` as `Y-m-d`; the engine uses ISO-8601 timestamps.
2. StatCan ingestion fixtures are a single `{status, object}` envelope; `StatCanSeriesReader` expects a list. Live WDS responses are typically lists.
3. CPI year-over-year uses `observations[i - 12]`, not a calendar-month match. The ingestion e2e fixture does not assert the CPI transformed value.
4. Dashboard GET auto-ingests after six hours; `?action=ingest` is unauthenticated. Each dashboard render writes a calculation JSON file.
5. Open Government is a client only.
6. No SchemaValidator; JSON is accepted by shape.
7. HTTP max-response-size is enforced after `curl_exec` has already loaded the body.
