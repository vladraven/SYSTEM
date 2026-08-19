# MacroRisk

MacroRisk is a Canadian macroeconomic risk-monitoring system. It is a standalone Pure PHP 8.3+ application: no Composer, no database, no frameworks.

It does not forecast recessions or investment outcomes. It calculates a model-derived risk score from official observations, deterministic transformations, and versioned configuration.

**As-built (2026-08-18):** the JSON-native rebuild from PR #1 is in production code. Five official indicators feed a BCMath risk engine and a native PHP dashboard. A Scenario/Hypothesis Engine can recompute a labelled simulation when the user moves indicator sliders.

## Requirements

- PHP 8.3+
- ext-curl
- ext-json
- ext-bcmath

Autoloading is a native `spl_autoload_register()` mapping `MacroRisk\` to `src/`.

## Run locally

From the repository root:

```sh
php -S localhost:8000 -t public
```

| URL | Purpose |
|---|---|
| `http://localhost:8000/` | Dashboard (production score + scenario sliders) |
| `http://localhost:8000/?action=help` | Methodology help |
| `POST http://localhost:8000/?action=scenario` | JSON simulation (`{"overrides":{"cpi_inflation":"4.20000000"}}`) |
| `http://localhost:8000/?action=ingest` | Force live refresh from official APIs |

Opening the dashboard with a snapshot older than six hours also triggers ingestion.

## Official data

Production ingestion uses:

| Indicator | Source | Series / vector |
|---|---|---|
| Policy rate | Bank of Canada Valet | V39079 |
| CPI inflation (YoY, derived) | StatCan WDS | 41690973 |
| Unemployment rate | StatCan WDS | 2062815 |
| 10-year bond yield | Bank of Canada Valet | V122487 |
| Housing starts (monthly × 12) | StatCan WDS | 729949 |

`OpenGovernmentClient` implements the Open Government CKAN contract but is **not** wired into `IngestionService`. See `DATA_SOURCES.md` for HTTP contracts.

## Storage

There is no database. Persistent state is JSON under `storage/`:

```
storage/
├── config/          system, indicators, sources, model_versions, scenario_rules
├── raw/             official HTTP payloads (statcan, bank_of_canada, open_government)
├── series/          normalized series per indicator
├── snapshots/       vintage snapshots (`latest.json` plus timestamped copies)
├── calculations/    persisted RiskEngine results
├── audit/           JSON audit entries
└── cache/
```

## Tests

Native PHP scripts (no PHPUnit dependency). From the repository root:

```sh
php tests/test_decimal.php
php tests/test_risk_engine.php
php tests/test_scenario.php
php tests/test_ingestion.php
```

Additional standalone scripts live under `tests/Core/`, `tests/Domain/`, and `tests/Infrastructure/`. GitHub Actions currently runs `php -l` syntax checks only.

## Documentation

1. `promt.txt` — target system specification (what the system must be).
2. `IMPLEMENTATION_NOTES.md` — as-built status versus that specification.
3. `MacroRisk Engine Core.md` — current code architecture.
4. `DATA_SOURCES.md` — official HTTP contracts.
5. `MacroRisk Philosophical Foundations.md` — epistemological constraints.

Where code and `promt.txt` disagree, `IMPLEMENTATION_NOTES.md` records the gap. Legacy behavior is not authoritative.
