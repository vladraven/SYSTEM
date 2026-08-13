# MacroRisk Engine Core — JSON-Native Architecture

**Version:** 2.0.0-json-native  
**Status:** canonical implementation architecture

## 1. Hard constraints

MacroRisk is a Pure PHP 8.3+ application.

Forbidden:
- Composer
- vendor/
- frameworks
- third-party libraries
- database servers
- PDO
- SQL
- ORM/DBAL
- migration frameworks
- template engines
- runtime LLM decision engines

Required:
- native PHP
- native PSR-4-compatible autoloader
- ext-curl
- ext-json
- ext-bcmath
- PHP-rendered views
- JSON persistence

The previous COMPOSER LAYOUT and database architecture are obsolete.

## 2. Runtime layers

### Core
Pure technical primitives:
- Decimal
- JsonStore
- AtomicFileWriter
- HttpClient
- Hash
- Clock
- Validator

### Domain
No HTTP and no filesystem knowledge:
- Observation
- Series
- Indicator
- Snapshot
- RiskScore
- RiskBand
- Configuration
- ModelVersion

### Application
Orchestration:
- IngestionService
- SnapshotBuilder
- CalculationService
- ValidationService
- AuditService

### Infrastructure
Adapters:
- StatCan WDS client
- Bank of Canada Valet client
- Open Government CKAN client
- JSON repositories

### Controller/UI
Controllers prepare data for PHP views. Views contain presentation only.

## 3. JSON repository contracts

Every repository is responsible for one logical collection.

Examples:
- IndicatorRepository
- SeriesRepository
- SnapshotRepository
- CalculationRepository
- ConfigurationRepository
- AuditRepository

Repositories return domain objects or explicitly typed arrays. They must not leak arbitrary raw JSON structures into Domain code.

## 4. JSON schemas

### Series

```json
{
  "schema_version": 1,
  "indicator_key": "policy_rate",
  "source_key": "bank_of_canada",
  "source_series_id": "V39079",
  "title": "Policy Interest Rate",
  "frequency": "daily",
  "unit": "percent",
  "observations": [
    {
      "reference_period": "2026-08-12",
      "value": "2.75000000",
      "release_time": "2026-08-12T00:00:00Z",
      "status": "valid",
      "raw_hash": "..."
    }
  ]
}
```

Values are strings, never floats.

### Snapshot

A snapshot identifies exactly which observations were available at a vintage date.

### Calculation

A calculation stores the complete deterministic decision path, including inputs, configuration, eligibility, weights, normalized scores, contributions, result and hash.

## 5. Atomic JSON writes

No direct file_put_contents() to a production target file.

The storage layer must:
- lock;
- write temp;
- flush;
- close;
- rename atomically.

## 6. HTTP

All external requests go through one native HttpClient abstraction.

The source-specific adapters must not call curl directly outside that infrastructure layer.

The HttpClient enforces:
- HTTPS;
- connect timeout;
- total timeout;
- maximum response size;
- HTTP status validation;
- JSON decoding where applicable;
- response hashing;
- redirect policy.

## 7. Ingestion state machine

pending
-> fetching
-> received
-> parsed
-> validated
-> stored

Failure states:
- source_unavailable
- source_timeout
- source_schema_mismatch
- access_denied
- stale_mapping
- data_pending

No failure state is converted into a valid observation.

## 8. Source adapter contract

Each source adapter exposes:
- source metadata;
- series discovery;
- metadata retrieval;
- observation retrieval;
- raw response preservation;
- deterministic parsing;
- source-specific validation.

The exact public source contracts are defined in DATA_SOURCES.md.

## 9. Calculation boundary

The RiskEngine accepts only validated domain inputs.

It does not:
- perform HTTP requests;
- read JSON files;
- know filesystem paths;
- know source URLs;
- create audit files;
- render HTML.

This makes the mathematical core deterministic and testable.

## 10. Views

PHP views are rendered with native require/include.

All output is escaped with htmlspecialchars() unless a value is explicitly designated as trusted markup.

No template engine is permitted.

## 11. Testing

Every module must have native PHP tests.

A module is not complete until:
- happy path passes;
- malformed input is rejected;
- deterministic output is asserted;
- failure states are asserted;
- no float enters the calculation path.
