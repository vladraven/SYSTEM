# Implementation bootstrap (aligned with promt.txt 1.5.0)

## Added in this drop

| Path | Purpose |
|------|---------|
| `.gitignore` | Exclude `.env`, vendor, storage dumps, caches |
| `.env.example` | Safe template (empty password) |
| `.github/workflows/ci.yml` | Composer, php -l, PHPUnit, PHPStan on PHP 8.3/8.4 |
| `src/Core/Math/Decimal.php` | BCMath-only decimal VO |
| `src/Core/Exception/*` | Error taxonomy + integrity exception |
| `src/Core/Security/ScientificIntegrityGuard.php` | Banned-phrase screen |
| `src/Risk/Normalization/ThresholdLinearNormalizer.php` | threshold_linear scores |
| `src/Risk/Weight/WeightRenormalizer.php` | fd + renorm to 100%, coverage gate |
| `config/indicator_catalog.php` | Official series map (no synthetic values) |
| `tests/Unit/*` | Unit tests for the above |

## Next (priority)

1. Rotate DB password — old `.env` was committed with real credentials.
2. Remove mock insolvency generator from legacy `macro_risk_data_collector.php`.
3. PSR-4 Ingestion connectors (StatCan WDS, BoC Valet, Open Gov) under `src/Ingestion/`.
4. Risk score aggregator (Σ score_i × w_eff_i) + snapshot persistence via Phinx schema.
5. Delete or quarantine `databese-check.php` duplicate; protect dashboards with auth.

## Local commands

```bash
cp .env.example .env
composer install
composer test
composer stan
```
