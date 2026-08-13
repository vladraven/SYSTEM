# MacroRisk

MacroRisk is a Canadian macroeconomic risk-monitoring system implemented as a standalone Pure PHP application.

## Canonical architecture

- PHP 8.3+
- no Composer
- no third-party libraries
- native PSR-4-compatible autoloading
- ext-curl
- ext-json
- ext-bcmath
- JSON-only persistence
- native PHP views
- deterministic calculations
- official first-party Canadian data sources

## Official data

- Statistics Canada WDS
- Bank of Canada Valet API
- Government of Canada Open Government / CKAN

See `DATA_SOURCES.md` for the frozen endpoint contracts and native PHP request implementations.

## Documentation

1. `promt.txt` — authoritative system specification.
2. `MacroRisk Engine Core.md` — implementation architecture.
3. `DATA_SOURCES.md` — official source contracts.
4. `IMPLEMENTATION_NOTES.md` — implementation order.
5. `MacroRisk Philosophical Foundations.md` — epistemological constraints.

## Storage

There is no database.

All persistent state is stored in versioned JSON files under `storage/`.

## Status

The existing implementation is being refactored toward the canonical specification. Legacy behavior is not authoritative.
