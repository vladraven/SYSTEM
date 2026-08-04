MACRORISK MASTER SPECIFICATION: DETERMINISTIC SOURCE OF TRUTH
Версия: 1.4.0-FINAL
Дата: 2026-08-04
Тип проекта: Новая самостоятельная система (Framework-independent)
Основной язык: PHP 8.3+ (Laravel запрещён)
СУБД: MySQL 8.4 LTS InnoDB utf8mb4
Локаль: en-CA (fr-CA в Phase 3)
Статус: Implementation-Ready Source of Truth

ЧАСТЬ 1. АРХИТЕКТУРНЫЕ ПРИНЦИПЫ И НАУЧНАЯ ЧЕСТНОСТЬ

1.1 ФУНДАМЕНТАЛЬНЫЕ АКСИОМЫ

1. Correctness over feature count.
2. Transparency over automation.
3. Deterministic behavior over heuristics.
4. Auditability over convenience.
Обязательный принцип: Every production decision must be reproducible. Недетерминированные эвристики, случайная генерация и runtime-использование LLM для принятия решений или генерации отчётов строго запрещены. Нативный PHP float для расчётов весов и баллов запрещён (используется точная десятичная арифметика BCMath/Decimal Value Object).

1.2 SCIENTIFIC INTEGRITY GUARD
Система обязана разделять Наблюдение (факт), Трансформацию (математика), Модельный результат (оценка) и Интерпретацию (текст).
Перед выводом или сохранением любых текстовых отчётов (narrative slots) текст должен проходить:
А) HTML Sanitization & Escaping для предотвращения XSS уязвимостей.
Б) Scientific Integrity Guard — case-insensitive regex сканер запрещённых фраз.
Список блокируемых фраз: will enter recession, proves that, confirms 80% recall, guarantees, predicts with confidence, recession is certain, model proves, statistically confirms (если выборка бэктеста < 10), caused by.
При обнаружении фразы: публикация блокируется, возвращается ошибка SCIENTIFIC_INTEGRITY_VIOLATION, инцидент пишется в аудит.

ЧАСТЬ 2. ИСТОЧНИКИ ДАННЫХ И ЖИЗНЕННЫЙ ЦИКЛ

2.1 ОФИЦИАЛЬНЫЕ ИСТОЧНИКИ
Кандидаты в источники: Statistics Canada (WDS & Full Table Download), Bank of Canada (Valet API), OSFI (Open Government Canada).
Прямые источники CMHC и CREA запрещены как production default (используются StatCan-эквиваленты: NHPI, RPPI, Building Permits). Неофициальные обёртки (GitHub, PyPI) запрещены.

2.2 SOURCE VALIDATION STATE MACHINE (МАТРИЦА ПЕРЕХОДОВ)
Состояния: pending_validation, valid, series_mapping_stale, unavailable, access_denied, temporary_unavailable, data_pending, schema_mismatch, source_timeout, source_rate_limited, release_late, missing_no_historical_data.

Переходы:

* pending_validation -> valid: ИСКЛЮЧИТЕЛЬНО после успешной live validation (HTTP 200, схема совпадает, частота подтверждена). Risk Officer не может установить "valid" вручную без проверки.
* valid -> temporary_unavailable: HTTP 500/503/timeout. Не делает маппинг "stale".
* valid -> source_rate_limited: HTTP 429.
* valid -> schema_mismatch: HTTP 200, но ожидаемые поля исчезли.
* valid -> series_mapping_stale: HTTP 404, таблица/вектор удалены, API deprecation. Production use блокируется. Возврат в "valid" только после успешной новой проверки.
* pending_validation -> data_pending: Для новых серий в grace period (схема верна, но время релиза ещё не наступило). В production использовать нельзя.

2.3 LICENSE GATE STATE MACHINE
Состояния: unverified, requires_license, public_open_candidate, public_open.
Переходы:

* unverified -> public_open_candidate: Источник выглядит публичным, но terms of use ещё не проверены Risk Officer'ом.
* public_open_candidate -> public_open: ТОЛЬКО после задокументированного review (Admin или Risk Officer).
* public_open_candidate -> requires_license: При обнаружении платной подписки или ограничений.
* public_open -> unverified: При истечении срока review или изменении terms of use.
Индикатор production-eligible ТОЛЬКО при license_status = public_open.

2.4 RELEASE CALENDAR RULES
Состояния: expected, released, delayed, missing, revised, unknown.
Производные статусы для серии: release_late, fallback_to_ingestion, historical_estimate.
Правила:

* Если expected_release_date наступила, а данных нет -> delayed.
* Если delay превышает tolerance -> серия получает validation_status = release_late.
* Если данные нужны для production, а official release date отсутствует -> estimated_release_date = ingestion_date, release_date_quality = fallback_to_ingestion.
* Наличие Release Calendar (или утверждённой fallback-политики) обязательно для production eligibility.

ЧАСТЬ 3. VINTAGE, SNAPSHOTS И ДЕДУПЛИКАЦИЯ

3.1 SNAPSHOT DEDUPLICATION RULES

* source_payload_hash = SHA-256(сырой ответ API/CSV).
* content_hash = SHA-256(распарсенное значение, дата, юнит, статус, номер ревизии).
Правила дедупликации:
* Если source_payload_hash и content_hash совпадают с предыдущим снапшотом -> is_duplicate = true. Новые data_observations не создаются.
* Если revision_number изменился, но само value и content_hash без учёта ревизии те же -> создаётся data_revision_event (value_changed = false), новая data_observation НЕ создаётся.
* Если value изменилось -> создаётся новая data_observation, data_revision_event (value_changed = true), новый snapshot_observations.

3.2 REVISION SELECTION LOGIC (ПРЕДОТВРАЩЕНИЕ LOOK-AHEAD BIAS)
Исторический расчёт (backtest) или воспроизведение обязаны использовать только те ревизии, которые были физически доступны на historical vintage_date.
Правило выбора:

1. Выбрать observation, где release_date <= vintage_date (или estimated_release_date <= vintage_date).
2. Tie-breaking (если несколько ревизий имеют одинаковую release_date): Выбрать максимальный revision_number. Если revision_number отсутствует/равен, выбрать минимальный observation_id.
3. Reproduction rule: При запросе ранее сохранённого скора система загружает строго связанные snapshot_observation_id, игнорируя любые новые данные.

ЧАСТЬ 4. MATHEMATICAL RISK ENGINE CORE

4.1 ВЕСА И ПОКРЫТИЕ (COVERAGE)
Все сконфигурированные оригинальные веса (original_weight) в сумме строго равны 100.0000.
coverage_ratio = СУММА(original_weight доступных и eligible индикаторов). frequency_discount НЕ влияет на coverage_ratio.
Расчёт возможен, если: coverage_ratio >= 60.0000, минимум 3 индикатора, все required индикаторы доступны.

4.2 СТРАТЕГИИ НОРМАЛИЗАЦИЯ (NORMALIZATION ENGINE)
Для защиты от деления на ноль используется эпсилон = 0.00000001.

А) threshold_linear (higher_is_riskier): H > L.
Guard: Если |H - L| < эпсилон: score = 0.0000 (если x <= L), иначе 100.0000.
Иначе: x <= L -> 0.0000; x >= H -> 100.0000; внутри -> ((x - L) / (H - L)) * 100.0000.

Б) threshold_linear (lower_is_riskier): H < L.
Guard: Если |H - L| < эпсилон: score = 0.0000 (если x >= L), иначе 100.0000.
Иначе: x >= L -> 0.0000; x <= H -> 100.0000; внутри -> ((L - x) / (L - H)) * 100.0000.

В) distance_from_target_is_riskier:
Guard: M <= 0 -> INVALID_CONFIGURATION_THRESHOLDS.
Иначе: score = MIN(100.0000, (|x - T| / M) * 100.0000).

Г) outside_band_is_riskier:
Требуются: safe_min, safe_max, outside_band_min_boundary, outside_band_max_boundary.
Guard: outside_band_min_boundary < safe_min < safe_max < outside_band_max_boundary. (Иначе ошибка).
Если safe_min <= x <= safe_max -> 0.0000.
Если x <= outside_band_min_boundary или x >= outside_band_max_boundary -> 100.0000.
Интерполяция применяется на отрезках между outside_band и safe границами.

4.3 РАСЧЁТ ЭФФЕКТИВНЫХ ВЕСОВ И ROUNDING RECONCILIATION

1. Внутренние расчёты выполняются со scale = 8.
2. w_base_i = original_weight_i / СУММА(original_weight доступных) * 100
3. w_disc_i = w_base_i * frequency_discount_i
4. effective_weight_i_raw = w_disc_i / СУММА(w_disc доступных) * 100
5. Rounding Reconciliation: Каждое effective_weight округляется до DECIMAL(10,4).
6. Рассчитывается delta = 100.0000 - СУММА(округлённых effective_weights).
7. Delta детерминированно прибавляется к ОДНОМУ индикатору. Критерий выбора: максимальный original_weight -> (при равенстве) максимальный w_disc -> (при равенстве) алфавитный порядок indicator_key по возрастанию.
8. Contribution = (normalized_score * reconciled_effective_weight) / 100.0000.
9. Total Risk Score = СУММА(Contribution).

4.4 РАСЧЁТ КАТЕГОРИЙ (CATEGORY SCORE)
Для каждой категории (например, inflation, labour_market):

* Множество A_c = доступные индикаторы в категории. Если пусто -> category_score = null.
* Локальная нормализация: cat_weight_i = reconciled_effective_weight_i / СУММА(reconciled_effective_weight внутри A_c) * 100.0000.
* category_score = СУММА(normalized_score_i * cat_weight_i / 100.0000).
* category_contribution (вклад категории в общий скор) = category_score * СУММА(original_weight внутри A_c) / 100.0000.

ЧАСТЬ 5. ОПЕРАЦИОННЫЕ ГЕЙТЫ И ERROR TAXONOMY

5.1 BOOTSTRAP TIME WINDOW LOGIC
Запуск production-расчётов блокируется таймером и статусами.

* Day 0–2: Разрешено создание Admin, sources, endpoints, audit. Запрещены ingestion и любой score.
* Day 3–7: Разрешены ingestion, validation, draft calculations. Production заблокирован.
* Day 8–14+: Production разрешён ТОЛЬКО после утверждения первого production System Preset (требует >= 5 valid series, >= 5 public_open, >= 3 доступных).
Прохождение 14 дней само по себе НЕ включает production. Включает только approved preset. Risk Officer не имеет права override для pending_validation, unverified/requires_license, stale_mapping.

5.2 CONFIGURATION PUBLICATION GATE
Переход конфигурации в status = 'published' (и is_published = true) разрешён ТОЛЬКО если:

* Сумма original_weight = 100.0000, все веса >= 0.
* Все thresholds валидны (включая guards).
* Версия модели active.
* Все required индикаторы production_eligible (valid + public_open).
* Нет pending_validation, public_open_candidate, requires_license, unverified, access_denied, temporary_unavailable, stale_mapping.
* Метаданные валидации источника свежие (не старше лимита).
* Исполнитель имеет роль Admin или Risk Officer.
Любое изменение published конфигурации строго создаёт новую версию.

5.3 ERROR MAPPING (TAXONOMY)
Каждая ошибка возвращает стандартизированный ответ.

* 0 доступных индикаторов -> INSUFFICIENT_DATA (HTTP 422, Calculation: insufficient_data, Hint: Проверить даты винтажа).
* Покрытие < 60% -> LOW_COVERAGE (HTTP 422, Calculation: low_coverage).
* Отсутствует required индикатор -> REQUIRED_INDICATOR_MISSING (HTTP 422, Calculation: insufficient_data).
* Источник 404/схема изменена -> STALE_MAPPING (HTTP 503, Hint: Обновить endpoint).
* Источник требует лицензии -> LICENSE_REQUIRED (HTTP 403).
* Лицензия не проверена -> LICENSE_UNVERIFIED (HTTP 403).
* Ошибка порогов (напр. H=L) -> INVALID_CONFIGURATION_THRESHOLDS (HTTP 422).
* Нативный float обнаружен в Risk Engine -> FLOAT_USAGE_FORBIDDEN (HTTP 500/422, Hint: Переписать на Decimal).

ЧАСТЬ 6. ИНТЕРФЕЙСЫ (API, CLI, NARRATIVES)

6.1 API ENDPOINTS
Все API требуют проверки API Key/Session.

* GET /api/v1/series: Роли (Все). Возвращает реестр.
* POST /api/v1/series/{id}/validate: Роли (Admin, Risk Officer). Запускает синхронную SourceValidationStateMachine. Возвращает новый validation_status.
* GET /api/v1/configurations: Роли (Все). Возвращает список опубликованных и доступных draft.
* POST /api/v1/calculations/production: Роли (Admin, Risk Officer). Payload: {config_version, vintage_date}. Возвращает RiskScoreResult, indicator_contributions, warnings. Если bootstrap не пройден -> HTTP 422.
* GET /api/v1/situation-room: Роли (Viewer и выше). Только published production data. Без experimental.
* POST /api/v1/backtests: Роли (Analyst, Risk Officer). Rate limit: 5/час. Запускает job.

6.2 CLI COMMANDS

* macrorisk:schema:install: Запускает DDL миграции (Phinx). Не создаёт пользователей.
* macrorisk:admin:create: Создаёт первого Admin. Интерактивный ввод email/password. Идемпотентна. Создаёт запись в audit.
* macrorisk:ingest:run: Запуск загрузки. Идемпотентна. Использует file-based locks для предотвращения race conditions. Пишет в ingestion_runs.
* macrorisk:validate:sources: Массовая проверка.
* macrorisk:audit:export: Выгрузка аудита.

6.3 NARRATIVE DETERMINISTIC SELECTION
Генерация текстов (Narrative Reports) не использует LLM.

* Seed Hash = SHA-256(config_version + vintage_date + locale + risk_band + active_indicators_status_hash).
* Seed Integer = derived from first 8 bytes of Seed Hash.
* Алгоритм: Собирает все eligible narrative_slots (status=approved, scientific_integrity_status=passed). Сортирует по slot_key, version_number. Детерминированно выбирает конкретный текст на основе Seed Integer. Гарантируется 100% повторяемость отчёта при тех же исходных данных.

ЧАСТЬ 7. ОПЦИОНАЛЬНОЕ РАСШИРЕНИЕ: ACMF DEMOGRAPHIC EXTENSION

Демографическое ядро не является частью обязательного Risk Engine MVP. Оно не влияет напрямую на risk_score, если его индикаторы явно не добавлены в конфигурацию с соответствующими весами.
В UI и отчётах язык должен быть предельно осторожным: "The demographic extension suggests external-replenishment dependence under the configured assumptions" (запрещено писать "System is on life support").

Уравнения баланса когорт (шаг = 1 год):
P1(t+1) = MAX(0, P1(t) + Births(t) - Aging12(t) - Deaths1(t) + Migration1(t))
P2(t+1) = MAX(0, P2(t) + Aging12(t) - Aging23(t) - Deaths2(t) + Migration2(t) + LabourRetention(t))
P3(t+1) = MAX(0, P3(t) + Aging23(t) - Deaths3(t) + Migration3(t))

Потоки:
Aging12(t) = gamma_scale * P1(t) / 15
Aging23(t) = gamma_scale * P2(t) / 50
IntlOther(t) = NetInternationalMigration(t) + OtherInternationalMigration(t)
Migration2_raw(t) = 0.80 * IntlOther(t) + IP_18_64(t)
Migration2(t) = g10 * Migration2_raw(t)
LabourRetention(t) = 0.0015 * (g9 - 1) * P2(t)
(Здесь g10 и g9 — калибровочные residual-коэффициенты, демонстрирующие в рамках модели зависимость когорты P2 от внешней миграции).

ЧАСТЬ 8. FULL DDL CONTRACT & FOREIGN KEY MATRIX

Все таблицы InnoDB, utf8mb4. Все id — BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY. Все timestamps в UTC (DATETIME).

ГРУППА 1: ИДЕНТИФИКАЦИЯ
ТАБЛИЦА users

* user_key VARCHAR(64) NOT NULL UNIQUE
* email VARCHAR(255) NOT NULL UNIQUE
* password_hash VARCHAR(255) NOT NULL
* display_name VARCHAR(255) NOT NULL
* status VARCHAR(32) NOT NULL DEFAULT 'active'
* last_login_at DATETIME NULL
* created_at DATETIME NOT NULL
* updated_at DATETIME NOT NULL
* deleted_at DATETIME NULL (Soft delete. Аудит сохраняет actor_name даже при deleted_at IS NOT NULL).

ТАБЛИЦА roles

* role_key VARCHAR(64) NOT NULL UNIQUE
* display_name VARCHAR(255) NOT NULL
* description TEXT NULL
* created_at DATETIME NOT NULL

ТАБЛИЦА user_roles

* user_id BIGINT UNSIGNED NOT NULL (FK -> users(id) ON DELETE CASCADE)
* role_id BIGINT UNSIGNED NOT NULL (FK -> roles(id) ON DELETE CASCADE)
* assigned_by BIGINT UNSIGNED NULL (FK -> users(id) ON DELETE SET NULL)
* assigned_at DATETIME NOT NULL
* UNIQUE KEY uq_user_role (user_id, role_id)

ТАБЛИЦА api_keys

* user_id BIGINT UNSIGNED NOT NULL (FK -> users(id) ON DELETE CASCADE)
* key_prefix VARCHAR(32) NOT NULL UNIQUE
* key_hash CHAR(64) NOT NULL
* name VARCHAR(255) NOT NULL
* status VARCHAR(32) NOT NULL DEFAULT 'active'
* last_used_at DATETIME NULL
* expires_at DATETIME NULL
* created_at DATETIME NOT NULL
* revoked_at DATETIME NULL

ГРУППА 2: ИСТОЧНИКИ
ТАБЛИЦА data_sources

* source_key VARCHAR(64) NOT NULL UNIQUE
* display_name VARCHAR(255) NOT NULL
* source_type VARCHAR(64) NOT NULL
* base_url VARCHAR(1024) NOT NULL
* official_documentation_url VARCHAR(1024) NOT NULL
* terms_of_use_url VARCHAR(1024) NULL
* license_status VARCHAR(32) NOT NULL DEFAULT 'unverified'
* production_allowed BOOLEAN NOT NULL DEFAULT FALSE
* notes TEXT NULL
* created_at DATETIME NOT NULL
* updated_at DATETIME NOT NULL

ТАБЛИЦА source_endpoints

* data_source_id BIGINT UNSIGNED NOT NULL (FK -> data_sources(id) ON DELETE RESTRICT)
* endpoint_key VARCHAR(128) NOT NULL
* endpoint_url VARCHAR(2048) NOT NULL
* method VARCHAR(16) NOT NULL DEFAULT 'GET'
* response_format VARCHAR(32) NOT NULL
* requires_auth BOOLEAN NOT NULL DEFAULT FALSE
* rate_limit_per_minute INT UNSIGNED NULL
* timeout_seconds INT UNSIGNED NOT NULL DEFAULT 30
* retry_policy_key VARCHAR(64) NULL (FK -> retry_policies(policy_key) ON DELETE SET NULL)
* production_allowed BOOLEAN NOT NULL DEFAULT FALSE
* validation_status VARCHAR(32) NOT NULL DEFAULT 'pending_validation'
* last_validated_at DATETIME NULL
* created_at DATETIME NOT NULL
* updated_at DATETIME NOT NULL
* UNIQUE KEY uq_ds_endpoint (data_source_id, endpoint_key)

ТАБЛИЦА retry_policies

* policy_key VARCHAR(64) NOT NULL UNIQUE
* max_retries INT UNSIGNED NOT NULL DEFAULT 3
* backoff_multiplier DECIMAL(5,2) NOT NULL DEFAULT 2.00
* created_at DATETIME NOT NULL

ГРУППА 3: ИНДИКАТОРЫ И КАЛЕНДАРЬ
ТАБЛИЦА series

* series_key VARCHAR(128) NOT NULL UNIQUE
* display_name VARCHAR(255) NOT NULL
* data_source_id BIGINT UNSIGNED NOT NULL (FK -> data_sources(id) ON DELETE RESTRICT)
* source_endpoint_id BIGINT UNSIGNED NULL (FK -> source_endpoints(id) ON DELETE SET NULL)
* source_provider VARCHAR(128) NOT NULL
* underlying_origin VARCHAR(128) NULL
* source_identifier VARCHAR(255) NULL
* table_id VARCHAR(64) NULL
* vector_id VARCHAR(64) NULL
* country VARCHAR(16) NOT NULL DEFAULT 'CA'
* category VARCHAR(64) NOT NULL
* frequency VARCHAR(32) NOT NULL
* unit VARCHAR(64) NOT NULL
* transformation_type VARCHAR(64) NOT NULL
* expected_update_lag_days INT UNSIGNED NULL
* license_status VARCHAR(32) NOT NULL DEFAULT 'unverified'
* production_allowed BOOLEAN NOT NULL DEFAULT FALSE
* validation_status VARCHAR(32) NOT NULL DEFAULT 'pending_validation'
* validation_checked_at DATETIME NULL
* created_at DATETIME NOT NULL
* updated_at DATETIME NOT NULL
* deleted_at DATETIME NULL

ТАБЛИЦА release_calendars

* series_id BIGINT UNSIGNED NOT NULL (FK -> series(id) ON DELETE CASCADE)
* reference_period_start DATE NOT NULL
* reference_period_end DATE NOT NULL
* expected_release_date DATETIME NULL
* actual_release_date DATETIME NULL
* estimated_release_date DATETIME NULL
* release_date_quality VARCHAR(64) NOT NULL DEFAULT 'unknown'
* release_status VARCHAR(32) NOT NULL DEFAULT 'unknown'
* created_at DATETIME NOT NULL
* updated_at DATETIME NOT NULL
* UNIQUE KEY uq_release_cal (series_id, reference_period_start, reference_period_end)

ГРУППА 4: ВИНТАЖИ И НАБЛЮДЕНИЯ
ТАБЛИЦА ingestion_runs

* ingestion_run_key VARCHAR(128) NOT NULL UNIQUE
* data_source_id BIGINT UNSIGNED NOT NULL (FK -> data_sources(id) ON DELETE RESTRICT)
* started_at DATETIME NOT NULL
* completed_at DATETIME NULL
* status VARCHAR(32) NOT NULL DEFAULT 'running'
* records_inserted INT UNSIGNED NOT NULL DEFAULT 0
* error_code VARCHAR(64) NULL
* created_at DATETIME NOT NULL

ТАБЛИЦА data_snapshots

* snapshot_key VARCHAR(128) NOT NULL UNIQUE
* ingestion_run_id BIGINT UNSIGNED NOT NULL (FK -> ingestion_runs(id) ON DELETE RESTRICT)
* series_id BIGINT UNSIGNED NOT NULL (FK -> series(id) ON DELETE RESTRICT)
* snapshot_timestamp DATETIME NOT NULL
* vintage_date DATETIME NOT NULL
* source_payload_hash CHAR(64) NOT NULL
* content_hash CHAR(64) NOT NULL
* is_duplicate BOOLEAN NOT NULL DEFAULT FALSE
* created_at DATETIME NOT NULL

ТАБЛИЦА data_observations

* series_id BIGINT UNSIGNED NOT NULL (FK -> series(id) ON DELETE RESTRICT)
* observation_date DATE NOT NULL
* frequency_at_observation VARCHAR(32) NOT NULL
* raw_value DECIMAL(24,8) NOT NULL
* unit VARCHAR(64) NOT NULL
* value_status VARCHAR(32) NOT NULL DEFAULT 'normal'
* content_hash CHAR(64) NOT NULL
* created_at DATETIME NOT NULL

ТАБЛИЦА snapshot_observations

* snapshot_id BIGINT UNSIGNED NOT NULL (FK -> data_snapshots(id) ON DELETE CASCADE)
* series_id BIGINT UNSIGNED NOT NULL (FK -> series(id) ON DELETE CASCADE)
* observation_id BIGINT UNSIGNED NOT NULL (FK -> data_observations(id) ON DELETE CASCADE)
* vintage_date DATETIME NOT NULL
* release_date DATETIME NULL
* estimated_release_date DATETIME NULL
* release_date_quality VARCHAR(64) NOT NULL
* reproducibility_allowed BOOLEAN NOT NULL DEFAULT TRUE
* is_revision BOOLEAN NOT NULL DEFAULT FALSE
* revision_number INT UNSIGNED NULL
* created_at DATETIME NOT NULL
* UNIQUE KEY uq_snap_obs (snapshot_id, series_id, observation_id)

ТАБЛИЦА data_revision_events

* series_id BIGINT UNSIGNED NOT NULL (FK -> series(id) ON DELETE CASCADE)
* observation_id BIGINT UNSIGNED NOT NULL (FK -> data_observations(id) ON DELETE CASCADE)
* previous_value DECIMAL(24,8) NULL
* new_value DECIMAL(24,8) NOT NULL
* value_changed BOOLEAN NOT NULL
* revision_detected_at DATETIME NOT NULL
* created_at DATETIME NOT NULL

ТАБЛИЦА data_release_records

* series_id BIGINT UNSIGNED NOT NULL (FK -> series(id) ON DELETE CASCADE)
* snapshot_id BIGINT UNSIGNED NULL (FK -> data_snapshots(id) ON DELETE SET NULL)
* release_detected_at DATETIME NOT NULL
* release_status VARCHAR(32) NOT NULL
* records_seen INT UNSIGNED NOT NULL
* records_changed INT UNSIGNED NOT NULL
* is_revision BOOLEAN NOT NULL DEFAULT FALSE
* created_at DATETIME NOT NULL

ГРУППА 5: КОНФИГУРАЦИИ
ТАБЛИЦА model_versions

* model_version VARCHAR(32) NOT NULL UNIQUE
* release_date DATE NOT NULL
* formula_key VARCHAR(64) NOT NULL
* status VARCHAR(32) NOT NULL DEFAULT 'draft'
* created_at DATETIME NOT NULL

ТАБЛИЦА risk_configurations

* configuration_key VARCHAR(128) NOT NULL UNIQUE
* owner_user_id BIGINT UNSIGNED NULL (FK -> users(id) ON DELETE SET NULL)
* name VARCHAR(255) NOT NULL
* configuration_type VARCHAR(64) NOT NULL
* lifecycle_status VARCHAR(32) NOT NULL DEFAULT 'draft'
* created_at DATETIME NOT NULL
* updated_at DATETIME NOT NULL
* deleted_at DATETIME NULL

ТАБЛИЦА risk_configuration_versions

* configuration_id BIGINT UNSIGNED NOT NULL (FK -> risk_configurations(id) ON DELETE CASCADE)
* version_number INT UNSIGNED NOT NULL
* version_key VARCHAR(128) NOT NULL UNIQUE
* model_version_id BIGINT UNSIGNED NOT NULL (FK -> model_versions(id) ON DELETE RESTRICT)
* status VARCHAR(32) NOT NULL DEFAULT 'draft'
* is_published BOOLEAN NOT NULL DEFAULT FALSE
* coverage_minimum DECIMAL(10,4) NOT NULL DEFAULT 60.0000
* config_hash CHAR(64) NOT NULL
* created_at DATETIME NOT NULL
* UNIQUE KEY uq_config_ver (configuration_id, version_number)

ТАБЛИЦА indicator_configs

* configuration_version_id BIGINT UNSIGNED NOT NULL (FK -> risk_configuration_versions(id) ON DELETE CASCADE)
* series_id BIGINT UNSIGNED NOT NULL (FK -> series(id) ON DELETE RESTRICT)
* indicator_key VARCHAR(128) NOT NULL
* category VARCHAR(64) NOT NULL
* original_weight DECIMAL(10,4) NOT NULL
* is_required BOOLEAN NOT NULL DEFAULT FALSE
* transformation_type VARCHAR(64) NOT NULL
* normalization_method VARCHAR(64) NOT NULL
* direction_of_deterioration VARCHAR(64) NOT NULL
* low_risk_threshold DECIMAL(24,8) NULL
* high_risk_threshold DECIMAL(24,8) NULL
* target_value DECIMAL(24,8) NULL
* max_deviation DECIMAL(24,8) NULL
* safe_min DECIMAL(24,8) NULL
* safe_max DECIMAL(24,8) NULL
* outside_band_min_boundary DECIMAL(24,8) NULL
* outside_band_max_boundary DECIMAL(24,8) NULL
* frequency_discount DECIMAL(10,4) NOT NULL DEFAULT 1.0000
* created_at DATETIME NOT NULL
* UNIQUE KEY uq_ind_conf (configuration_version_id, indicator_key)

ТАБЛИЦА risk_configuration_overrides

* indicator_config_id BIGINT UNSIGNED NOT NULL (FK -> indicator_configs(id) ON DELETE CASCADE)
* override_type VARCHAR(64) NOT NULL
* override_reason TEXT NOT NULL
* approved_by BIGINT UNSIGNED NOT NULL (FK -> users(id) ON DELETE RESTRICT)
* approved_at DATETIME NOT NULL
* created_at DATETIME NOT NULL

ТАБЛИЦА risk_band_threshold_sets

* threshold_set_key VARCHAR(128) NOT NULL UNIQUE
* version INT UNSIGNED NOT NULL
* very_low_max DECIMAL(10,4) NOT NULL
* low_max DECIMAL(10,4) NOT NULL
* moderate_max DECIMAL(10,4) NOT NULL
* high_max DECIMAL(10,4) NOT NULL
* severe_max DECIMAL(10,4) NOT NULL
* created_at DATETIME NOT NULL

ГРУППА 6: РЕЗУЛЬТАТЫ И БЭКТЕСТЫ
ТАБЛИЦА risk_score_results

* score_key VARCHAR(128) NOT NULL UNIQUE
* configuration_version_id BIGINT UNSIGNED NOT NULL (FK -> risk_configuration_versions(id) ON DELETE RESTRICT)
* vintage_date DATETIME NOT NULL
* calculation_mode VARCHAR(32) NOT NULL
* calculation_status VARCHAR(64) NOT NULL
* risk_score DECIMAL(10,4) NULL
* risk_band VARCHAR(32) NULL
* coverage_ratio DECIMAL(10,4) NOT NULL
* available_indicator_count INT UNSIGNED NOT NULL
* required_indicator_missing BOOLEAN NOT NULL DEFAULT FALSE
* effective_weights_sum DECIMAL(10,4) NOT NULL
* calculation_hash CHAR(64) NOT NULL
* created_at DATETIME NOT NULL

ТАБЛИЦА risk_score_warnings

* risk_score_result_id BIGINT UNSIGNED NOT NULL (FK -> risk_score_results(id) ON DELETE CASCADE)
* warning_code VARCHAR(64) NOT NULL
* message TEXT NOT NULL
* created_at DATETIME NOT NULL

ТАБЛИЦА risk_score_indicator_contributions

* risk_score_result_id BIGINT UNSIGNED NOT NULL (FK -> risk_score_results(id) ON DELETE CASCADE)
* indicator_config_id BIGINT UNSIGNED NOT NULL (FK -> indicator_configs(id) ON DELETE RESTRICT)
* series_id BIGINT UNSIGNED NOT NULL (FK -> series(id) ON DELETE RESTRICT)
* raw_value DECIMAL(24,8) NULL
* transformed_value DECIMAL(24,8) NULL
* normalized_indicator_score DECIMAL(10,4) NULL
* original_weight DECIMAL(10,4) NOT NULL
* frequency_discount DECIMAL(10,4) NOT NULL DEFAULT 1.0000
* effective_weight DECIMAL(10,4) NULL
* contribution_value DECIMAL(10,4) NULL
* is_available BOOLEAN NOT NULL
* missing_reason VARCHAR(64) NULL
* created_at DATETIME NOT NULL

ТАБЛИЦА backtest_runs

* backtest_run_key VARCHAR(128) NOT NULL UNIQUE
* configuration_version_id BIGINT UNSIGNED NOT NULL (FK -> risk_configuration_versions(id) ON DELETE RESTRICT)
* run_status VARCHAR(32) NOT NULL DEFAULT 'running'
* false_positive_count INT UNSIGNED NOT NULL DEFAULT 0
* small_sample_warning BOOLEAN NOT NULL DEFAULT TRUE
* sample_size_n INT UNSIGNED NOT NULL
* started_at DATETIME NOT NULL
* completed_at DATETIME NULL

ТАБЛИЦА backtest_episode_results

* backtest_run_id BIGINT UNSIGNED NOT NULL (FK -> backtest_runs(id) ON DELETE CASCADE)
* episode_key VARCHAR(128) NOT NULL
* detected BOOLEAN NOT NULL
* first_detection_date DATETIME NULL
* created_at DATETIME NOT NULL

ТАБЛИЦА backtest_episode_score_points

* backtest_episode_result_id BIGINT UNSIGNED NOT NULL (FK -> backtest_episode_results(id) ON DELETE CASCADE)
* risk_score_result_id BIGINT UNSIGNED NOT NULL (FK -> risk_score_results(id) ON DELETE CASCADE)
* detection_threshold_met BOOLEAN NOT NULL
* created_at DATETIME NOT NULL

ГРУППА 7: NARRATIVES, JOBS И АУДИТ
ТАБЛИЦА narrative_slots

* slot_key VARCHAR(128) NOT NULL
* version_number INT UNSIGNED NOT NULL
* status VARCHAR(32) NOT NULL DEFAULT 'draft'
* scientific_integrity_status VARCHAR(32) NOT NULL DEFAULT 'pending'
* approved_by BIGINT UNSIGNED NULL (FK -> users(id) ON DELETE SET NULL)
* approved_at DATETIME NULL
* created_at DATETIME NOT NULL
* UNIQUE KEY uq_slot_ver (slot_key, version_number)

ТАБЛИЦА narrative_slot_translations

* narrative_slot_id BIGINT UNSIGNED NOT NULL (FK -> narrative_slots(id) ON DELETE CASCADE)
* locale VARCHAR(16) NOT NULL
* text TEXT NOT NULL
* text_hash CHAR(64) NOT NULL
* created_at DATETIME NOT NULL
* updated_at DATETIME NOT NULL
* UNIQUE KEY uq_slot_loc (narrative_slot_id, locale)

ТАБЛИЦА narrative_reports

* report_key VARCHAR(128) NOT NULL UNIQUE
* risk_score_result_id BIGINT UNSIGNED NOT NULL (FK -> risk_score_results(id) ON DELETE RESTRICT)
* locale VARCHAR(16) NOT NULL
* report_status VARCHAR(32) NOT NULL
* seed_hash CHAR(64) NOT NULL
* seed_int BIGINT UNSIGNED NOT NULL
* full_text MEDIUMTEXT NOT NULL
* scientific_integrity_status VARCHAR(32) NOT NULL
* generated_at DATETIME NOT NULL

ТАБЛИЦА narrative_report_slots

* narrative_report_id BIGINT UNSIGNED NOT NULL (FK -> narrative_reports(id) ON DELETE CASCADE)
* narrative_slot_id BIGINT UNSIGNED NOT NULL (FK -> narrative_slots(id) ON DELETE RESTRICT)
* created_at DATETIME NOT NULL

ТАБЛИЦА job_runs

* job_key VARCHAR(128) NOT NULL UNIQUE
* job_type VARCHAR(128) NOT NULL
* status VARCHAR(32) NOT NULL DEFAULT 'queued'
* started_at DATETIME NOT NULL
* completed_at DATETIME NULL
* error_code VARCHAR(64) NULL
* created_at DATETIME NOT NULL

ТАБЛИЦА system_errors

* error_key VARCHAR(128) NOT NULL UNIQUE
* error_code VARCHAR(64) NOT NULL
* http_status_code INT NULL
* human_message TEXT NOT NULL
* machine_message TEXT NOT NULL
* created_at DATETIME NOT NULL

ТАБЛИЦА audit_records (Append-Only)

* audit_key VARCHAR(128) NOT NULL UNIQUE
* actor_user_id BIGINT UNSIGNED NULL (Остается NULL, если system/api)
* actor_name VARCHAR(255) NULL (Денормализовано для сохранения после soft delete юзера)
* actor_role VARCHAR(64) NULL
* event_type VARCHAR(128) NOT NULL
* entity_type VARCHAR(128) NOT NULL
* entity_id BIGINT UNSIGNED NULL
* diff_json JSON NULL
* created_at DATETIME NOT NULL

ЧАСТЬ 9. PHILOSOPHICAL FOUNDATIONS

9.1 ЭПИСТЕМОЛОГИЯ ОТКАЗА ОТ ИЛЛЮЗИИ ЗНАНИЯ
MacroRisk не является хрустальным шаром. Риск-скор — это строго модельная оценка на основе явно заданных конфигураций. Он не измеряет будущее.
В интерфейсе и отчётах запрещены формулировки, предполагающие уверенность (causal proof, forecast guarantees).

9.2 УРОК МАНИТОБЫ И ВНЕШНЕ ПОДДЕРЖИВАЕМАЯ УСТОЙЧИВОСТЬ
Общество моделируется как адаптивная метаболическая система. Видимая демографическая стабильность (сохранение объёма когорты P2) не гарантирует внутреннего благополучия, если естественное воспроизводство деградирует (падение рождаемости, отток кадров).
Наблюдаемая на данных Манитобы (2020-2024) стабильность обеспечивается мощным внешним миграционным притоком.
Философский закон модели: Устойчивость может быть внешней, а не внутренней. Модель обязана разделять эти два состояния и делать их прозрачными для аналитика. Терминология должна оставаться строгой: "The model demonstrates structural dependence on migration replenishment", избегая публицистических терминов ("аппарат искусственного жизнеобеспечения") в production-текстах.
