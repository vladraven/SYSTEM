# README.md

## Canada Macro Intelligence Platform (CMIP)

Specification Version: 1.2.0-draft | Specification Date: 2026-07-31 | Project Type: Standalone Macroeconomic Risk Diagnostic Engine | Target Market: Canada (en-CA primary, fr-CA starting Phase 3) | Technology Stack: PHP 8.3+, MySQL 8.4 LTS (InnoDB, utf8mb4), Slim Framework, Phinx, bcmath / Decimal VOs | Architecture Constraint: Framework-Independent (Laravel & Framework abstractions strictly forbidden)

---

## 📌 Executive Overview & Core Philosophy

The Canada Macro Intelligence Platform (CMIP) is a deterministic, fully auditable, and transparent macroeconomic risk monitoring platform. It collects official Canadian macroeconomic signals (Statistics Canada, Bank of Canada, OSFI, Open Government), normalizes data into sub-risk scores, and computes model-derived diagnostic risk metrics across customizable or preset configurations.

Architectural Invariant: Every production decision must be reproducible.

The Core Non-Goal: The system must not pretend to know what the data cannot support.

CMIP is NOT a predictive economic engine, an LLM-based forecaster, or an automated trading/policy tool. It does not perform causal inference, estimate unreleased historical data, or output probabilistic forecasts based on small sample sizes.

---

## 🚫 Critical Constraints & Forbidden Dependencies

### 1. Framework Restrictions

* NO LARAVEL / SYMFONY / WORDPRESS / DRUPAL: The project is a clean, PSR-compliant PHP application.
* NO ORMs / Magic Helpers: No Eloquent, Laravel Migrations, Laravel Queues, or framework-specific facades.
* NO Unofficial Wrappers: No unofficial Python/PHP wrappers or web scrapers for StatCan, Bank of Canada, CMHC, or CREA. Direct CMHC and CREA APIs are excluded from default production presets in favor of official StatCan/Open Government tables.

### 2. Scientific & Precision Integrity

* NO Native PHP Floats in Risk Engine: All calculations involving weights, coverage ratios, normalized scores, and final risk metrics MUST use bcmath or an explicit Decimal Value Object. Strings from MySQL DECIMAL columns must never be cast to native float.
* NO Runtime LLM in Production: Production scoring, decision-making, and narrative report generation must never call a generative AI API at runtime. Narrative reports are generated via deterministic, pre-approved slot templates.
* NO Fake Precision (N < 10): Historical backtesting across fewer than 10 stress episodes will never display isolated percentage metrics (e.g., "80% recall"). Only absolute counts and mandatory small-sample warnings are permitted.

---

## 🛠 System Architecture & Layer Separation

The application enforces a strict physical and logical separation across three layers:

DATA LAYER:

* Ingestion Jobs (CLI) & Health Checks
* Source Validation State Machine (validateSourceEndpoint)
* Release Calendar & Vintage Snapshots (SHA-256 deduplicated)
* License Gate Enforcement (public_open vs. requires_license)

MODEL LAYER:

* Frequency Alignment Matrix (Forward-Fill, validity windows)
* Missing Indicator Handling & Weight Re-Normalization
* NormalizationStrategy (threshold_linear: 0-100 clamping)
* Arbitrary-Precision Risk Engine Core (bcmath)

INTERPRETATION LAYER:

* Situation Room API & UI Dashboards
* Deterministic Slot-Based Narrative Reports (ScientificIntegrityGuard)
* Exportable Auditable Diagnostics & Warnings

---

## 🧮 Risk Engine Mathematics & Formulas

1. Indicator Normalization (threshold_linear)
Raw economic observations (x) are mapped to a normalized sub-risk score Si in the range [0, 100]:

For higher_is_riskier:
Si = clamp(((x - T_low) / (T_high - T_low)) * 100, 0, 100)
(Constraint: T_high > T_low. Equal thresholds throw INVALID_CONFIGURATION_THRESHOLDS)

For lower_is_riskier:
Si = clamp(((T_low - x) / (T_low - T_high)) * 100, 0, 100)
(Constraint: T_high < T_low)

2. Coverage Ratio Calculation
Coverage ratio evaluates data availability using original configured weights Wi_orig before applying frequency discounts or weight re-normalization:

Coverage Ratio = Sum of Wi_orig for all Available Eligible Indicators
(Production Gate: Requires Coverage Ratio >= 60.0000%, at least 3 available indicators, 0 missing required indicators, and 0 restricted/unverified indicators)

3. Weight Re-Normalization & Effective Weights
When an optional indicator is missing, available eligible weights are re-normalized to sum to 100.0000%. The frequency discount Di in the range (0, 1] is applied during effective weight distribution:

Wi_eff = ((Wi_orig * Di) / Sum(Wj_orig * Dj for all Available Eligible)) * 100

4. Overall Risk Score
Risk Score = Sum(Si * (Wi_eff / 100) for all Available Eligible)

Risk Bands: 0.0000-20.0000 (Very Low), 20.0001-40.0000 (Low), 40.0001-60.0000 (Moderate), 60.0001-80.0000 (High), 80.0001-100.0000 (Severe).

---

## 🗄 Storage Architecture & Deduplication Model

The storage engine is MySQL 8.4 LTS (InnoDB, utf8mb4). Monolithic tables or raw float data types are banned.

Key Database Conventions:

* Raw Observations: Stored as DECIMAL(24,8).
* Scores, Weights & Percentages: Stored as DECIMAL(10,4).
* Timestamps: UTC DATETIME(6).
* Hashes: CHAR(64) SHA-256 for snapshot payload deduplication and audit tracking.

Data Flow: data_snapshots (Logical Snapshot on Vintage Date) -> snapshot_observations (Many-to-Many Linking Table: Vintage Date + Release Quality) -> data_observations (Unique Raw Values: series_id + observation_date + content_hash)

Snapshot Deduplication Rule: Unchanged observation payloads across multiple ingestion runs reuse data_observations records while creating new snapshot_observations linkages, preserving exact historical vintage reproducibility without storage bloat.

---

## 🚀 Bootstrap / Cold Start Protocol

When newly installed, the platform enters bootstrap mode:

1. Days 0-2: Account creation (macrorisk:user:create-admin), source registration, endpoint definition. All calculations are locked to draft or sandbox. Production score API returns system_bootstrap.
2. Days 3-7: Candidate ingestion and live validateSourceEndpoint executions. Draft System Presets configured.
3. Days 8-14+ (First Production Preset Gate): Production publishing is unlocked ONLY IF:
* At least 5 series have validation_status = valid and license_status = public_open.
* All required indicators in the preset are available.
* Coverage Ratio >= 60.0000% with at least 3 available indicators.
* Risk Officer approves production eligibility with full audit logging.



---

## 🛡 Security, RBAC & Scientific Integrity

Role-Based Access Control (RBAC):

* admin: System configuration, users, source management, full audit inspection.
* risk_officer: Approves/publishes production configurations, presets, license reviews, and narrative slots.
* analyst: Creates draft/experimental configurations, runs sandbox calculations, and executes diagnostic backtests.
* viewer: Read-only access to published dashboards and reports.
* system: Automated CLI actor for ingestion, validation, and scheduled jobs.

ScientificIntegrityGuard:
All text outputs (narrative reports, dashboard summaries, export files) are validated through a case-insensitive regex guard. Banned phrases (e.g., "will enter recession", "proves that", "confirms 80% recall", "guarantees") trigger a SCIENTIFIC_INTEGRITY_VIOLATION error and block publication.

---

## 📦 Project Setup & Local Development

Prerequisites:

* PHP: ^8.3 (Required Extensions: pdo_mysql, bcmath, intl, mbstring, json, openssl, curl, dom, zip)
* Database: MySQL 8.4 LTS
* Composer: Installed locally
* Docker: Optional container wrapper

Installation Steps:

1. Clone the Repository & Install Dependencies:
git clone [https://github.com/organization/canada-macro-risk.git](https://www.google.com/search?q=https://github.com/organization/canada-macro-risk.git)
cd canada-macro-risk
composer install --no-dev --optimize-autoloader
2. Configure Environment:
cp .env.example .env
# Edit .env with MySQL 8.4 credentials and system parameters


3. Run Database Migrations (Phinx):
vendor/bin/phinx migrate
4. Bootstrap Initial Admin User:
php bin/console macrorisk:user:create-admin --email="admin@macrorisk.ca" --name="System Admin"
5. Run System Source Validation Health Check:
php bin/console macrorisk:source:validate-all

---

## 🧪 Testing & Quality Assurance

CMIP enforces strict CI gates via GitHub Actions. Commits with failing tests, static analysis errors, or native float usage in calculation paths will fail build checks.

Running Test Suites:

* Execute PHPUnit / Pest Test Suite: vendor/bin/phpunit --testsuite=Unit,Integration
* Run Worked Example (Appendix A Fixture Test): vendor/bin/phpunit --filter=WorkedExampleFixtureTest
* Static Analysis (PHPStan Level 8): vendor/bin/phpstan analyse src --level=8
* Code Style Checks: vendor/bin/phpcs --standard=PSR12 src/
* Composer Security Audit: composer audit

Mandatory CI Coverage Requirements:

* Domain Services: >= 85% line coverage.
* Critical Guards (100% Line Coverage Mandatory): LicenseGate, RiskScoreInvariantValidator, BacktestSmallSampleGuard, SourceValidationStateMachine, ScientificIntegrityGuard.

---

## 🗺 Implementation Roadmap & Milestone Gates

* Phase 1 (Foundation & Registry): Database Schema, Series Registry, License Gate, Source Validation, RBAC, Error Taxonomy.
* Phase 2a (Data Release Monitor & Vintage Audit): CLI Ingestion, Vintage Storage, Release Calendar, Revision Tracking, Deduplication.
* Phase 2b (Risk Engine Core & System Presets): threshold_linear Normalization, Dynamic Re-normalization, bcmath Math Engine, System Presets, Appendix A Worked Example Test.
* Phase 2c (Risk Configuration Studio): User Draft Configurations, Weight Versioning, Lifecycle States, Publish Validation. (V1.0 Target)
* Phase 2d (Historical Risk Backtest): Diagnostic Backtesting Engine, Detection Rules, Small-Sample Warnings (N < 10). (V1.1 Target)
* Phase 2e (Economic Situation Room): Dashboard API & View Components, Diagnostic Badging, Missing/Stale Indicator Alerts. (V1.0 Target)
* Phase 3 (Deterministic Narrative Reports): Multi-locale Slot Generator, Slot Versioning, ScientificIntegrityGuard. (V1.2 Target)

---

## 📄 License & Compliance

Confidential & Proprietary. All official data mappings comply with Statistics Canada WDS terms, Bank of Canada Valet API terms, and Open Government Licence - Canada. Third-party or commercial data mirrors require explicit license_reviews approval before entering production pipelines.