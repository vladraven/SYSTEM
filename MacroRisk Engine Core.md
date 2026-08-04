ФАЙЛ 1. MASTER ARCHITECTURE AND TECHNICAL SPECIFICATION

Версия: 1.5.0-FINAL
Тип проекта: Новая самостоятельная система
Язык: PHP 8.3+ (Framework-independent, Laravel запрещён)
СУБД: MySQL 8.4 LTS InnoDB utf8mb4
Локаль: en-CA (fr-CA в Phase 3)
Статус: Implementation-Ready Deterministic Source of Truth

1. АРХИТЕКТУРНЫЕ ПРИНЦИПЫ И ОГРАНИЧЕНИЯ

Система MacroRisk — это прозрачный, аудируемый и детерминированный механизм оценки макроэкономических рисков.
Приоритеты: Корректность важнее количества функций. Прозрачность важнее автоматизации. Детерминизм важнее эвристик. Аудируемость важнее удобства.

Обязательный принцип: Every production decision must be reproducible.

Запрещено использовать:

* Laravel (миграции, Eloquent, queues, jobs, policies, helpers).
* Runtime LLM для принятия решений или генерации production-текстов.
* Заброшенные Composer-пакеты.
* GPL-зависимости.
* Неофициальные wrappers (PyPI, GitHub) для StatCan, Bank of Canada, CMHC, CREA, OSFI.
* Нативный PHP float для расчётов весов и баллов.

2. ПРАВИЛА ОБРАБОТКИ ВРЕМЕНИ И ДАННЫХ

Все метки времени (timestamps), поступающие в систему из внешних источников, пользовательского ввода или системных событий, ОБЯЗАТЕЛЬНО должны конвертироваться в таймзону TIMEZONE (UTC) до сохранения в базу данных.
Для хранения используется формат DATETIME(6) для обеспечения микросекундной точности, необходимой для дедупликации и предотвращения состояния гонки (race conditions).

3. ИСТОЧНИКИ ДАННЫХ И ЖИЗНЕННЫЙ ЦИКЛ

Официальные источники-кандидаты: Statistics Canada (WDS, Full Table Download), Bank of Canada (Valet API), OSFI.
Прямые источники CMHC и CREA запрещены как production default (используются эквиваленты StatCan).

SOURCE VALIDATION STATE MACHINE

* pending_validation: Начальное состояние.
* valid: Переход из pending_validation или temporary_unavailable ИСКЛЮЧИТЕЛЬНО после успешной проверки (HTTP 200, схема верна, частота подтверждена). Risk Officer не может установить valid вручную без проверки.
* temporary_unavailable: HTTP 500, 503, timeout. Маппинг остаётся валидным.
* source_rate_limited: HTTP 429.
* schema_mismatch: HTTP 200, но ожидаемые поля отсутствуют.
* series_mapping_stale: HTTP 404, таблица/вектор удалены, API deprecation. Production use блокируется. Возврат в valid только после успешной новой проверки.
* data_pending: Для новых серий в grace period (схема верна, время релиза ещё не наступило). Production use запрещён.

LICENSE GATE STATE MACHINE

* unverified: Начальное состояние.
* public_open_candidate: Источник выглядит публичным, terms of use не проверены.
* public_open: ТОЛЬКО после задокументированного review (Admin или Risk Officer).
* requires_license: При обнаружении платной подписки или ограничений.
Только series со статусом public_open являются production-eligible.

RELEASE CALENDAR RULES
Каждая серия должна иметь записи в Release Calendar для production-использования.

* expected: Дата релиза в будущем.
* delayed: expected_release_date наступила, данных нет.
* release_late: delay превышает tolerance.
* missing: источник подтверждает отсутствие релиза.
* revised: релиз относится к прошлому периоду.
* Разрешение конфликта release_date: Если для наблюдения существуют и actual_release_date, и estimated_release_date, система ОБЯЗАНА использовать actual_release_date.
* Если данные нужны для production, а official release date отсутствует: estimated_release_date = ingestion_date, release_date_quality = fallback_to_ingestion.

4. SNAPSHOT DEDUPLICATION И REVISION SELECTION

Дедупликация (Snapshot Deduplication):

* source_payload_hash: HASH_ALGORITHM hex string от сырого ответа API/CSV.
* content_hash: HASH_ALGORITHM hex string от распарсенного значения, даты, юнита, статуса, номера ревизии.
* Если оба хеша совпадают с предыдущим снапшотом: is_duplicate = true. Новые data_observations не создаются.
* Если revision_number изменился, но content_hash без учёта ревизии тот же: создаётся data_revision_event (value_changed = false), новая data_observation НЕ создаётся.
* Если значение изменилось: создаётся новая data_observation, data_revision_event (value_changed = true), новый snapshot_observations. Уникальность в snapshot_observations строго по комбинации: snapshot_id + series_id + observation_id.

Выбор ревизий (Предотвращение Look-ahead bias):
Исторический расчёт (backtest) использует только ревизии, физически доступные на vintage_date.

1. Выбрать observation, где release_date <= vintage_date (с учётом правила разрешения actual vs estimated).
2. Tie-breaking (если даты совпадают): Выбрать максимальный revision_number. Если revision_number отсутствует/равен, выбрать минимальный observation_id.
3. Reproduction rule: Ранее сохранённый risk_score загружает строго связанные snapshot_observation_id, игнорируя новые данные.
4. ОПЕРАЦИОННЫЕ ГЕЙТЫ

BOOTSTRAP TIME WINDOW LOGIC

* Day 0-2: Разрешено создание Admin, sources, endpoints. Запрещены ingestion и расчеты risk_score.
* Day 3-7: Разрешены ingestion, validation, draft calculations. Production заблокирован.
* Day 8-14+: Production разрешён ТОЛЬКО после утверждения первого production System Preset. Условия Preset: >= 5 valid series, >= 5 public_open, >= 3 доступных.
Прохождение 14 дней само по себе НЕ включает production автоматически. Включает только approved preset. Risk Officer не имеет права override для pending_validation, unverified, requires_license, stale_mapping.

CONFIGURATION PUBLICATION GATE
Переход конфигурации в status = published разрешён ТОЛЬКО если:

* Сумма original_weight = 100.0000, все веса >= 0.0000.
* Все thresholds валидны.
* Версия модели active.
* Все required индикаторы production-eligible.
* Отсутствуют: pending_validation, public_open_candidate, requires_license, unverified, access_denied, temporary_unavailable, stale_mapping.
* Метаданные валидации свежие.
* Исполнитель имеет роль Admin или Risk Officer.
Любое изменение published конфигурации строго создаёт новую версию.

ERROR MAPPING (TAXONOMY)

* 0 доступных индикаторов: INSUFFICIENT_DATA (HTTP 422, Hint: Проверить даты винтажа).
* Покрытие < MINIMUM_COVERAGE_REQUIRED: LOW_COVERAGE (HTTP 422).
* Отсутствует required индикатор: REQUIRED_INDICATOR_MISSING (HTTP 422).
* Источник 404/схема изменена: STALE_MAPPING (HTTP 503).
* Источник требует лицензии: LICENSE_REQUIRED (HTTP 403).
* Лицензия не проверена: LICENSE_UNVERIFIED (HTTP 403).
* Ошибка порогов: INVALID_CONFIGURATION_THRESHOLDS (HTTP 422).
* Нативный float обнаружен: FLOAT_USAGE_FORBIDDEN (HTTP 500/422).

6. ИНТЕРФЕЙСЫ (API, CLI, UI)

API ENDPOINTS (RESTful, JSON)
Все API требуют проверки API Key/Session и конвертации входящих дат в UTC.

* GET /api/v1/series: Роли: Все. Возвращает реестр индикаторов.
* POST /api/v1/series/{id}/validate: Роли: Admin, Risk Officer. Запускает SourceValidationStateMachine.
* GET /api/v1/configurations: Роли: Все.
* POST /api/v1/calculations/production: Роли: Admin, Risk Officer. Payload: config_version, vintage_date. Возвращает RiskScoreResult.
* GET /api/v1/situation-room: Роли: Viewer и выше. Только published production data.
* POST /api/v1/backtests: Роли: Analyst, Risk Officer. Rate limit: 5/час.

CLI COMMANDS

* macrorisk:schema:install: Запускает DDL миграции (Phinx).
* macrorisk:admin:create: Создаёт первого Admin. Разрешена только если Admin не существует. Интерактивный ввод email/password. Идемпотентна. Пишет в аудит.
* macrorisk:ingest:run: Запуск загрузки. Идемпотентна. File-based locks/concurrency controls.
* macrorisk:validate:sources: Массовая проверка endpoints.
* macrorisk:audit:export: Выгрузка логов аудита в CSV.

UI / HTML
Server-Rendered UI через Twig или Plates. Все графики обязаны визуально разделять Observed Data (сырые данные) и Model Output (расчётные скоры). Все пользовательские вводы текста проходят HTML Sanitization.

COMPOSER LAYOUT

* /src/Domain: Сущности, Value Objects (Math Decimal), Interfaces, Invariants, Constants.
* /src/Application: Use Cases, State Machines, RiskEngine.
* /src/Infrastructure: Репозитории (PDO/DBAL), HTTP Клиенты, Адаптеры (StatCanClient).
* /src/Api: PSR-15 Middlewares, Slim Framework Controllers.
* /src/Cli: Symfony Console команды.
* /src/Ui: Контроллеры и шаблоны.
* /tests: PHPUnit/Pest тесты.

=============================================================================

ФАЙЛ 2. MATHEMATICAL CORE SPECIFICATION

Версия: 1.5.0-FINAL
Статус: Mathematical Source of Truth

1. СИСТЕМНЫЕ И КАЛИБРОВОЧНЫЕ КОНСТАНТЫ (SYSTEM CONSTANTS)

Реализация должна использовать строго именованные константы.

Базовые системные константы:

* HASH_ALGORITHM: SHA-256
* HASH_FORMAT: hex string
* TIMEZONE: UTC
* SEED_ENDIANNESS: BIG_ENDIAN
* SCALE: 8 (Внутренняя точность вычислений BCMath)
* STORAGE_SCALE: 4 (Точность сохранения в БД DECIMAL 10,4)
* NORMALIZATION_EPSILON: 1e-8 (Guard-порог защиты от деления на ноль)

Бизнес-константы (Business Rules):

* MINIMUM_COVERAGE_REQUIRED: 60.0000
* MINIMUM_AVAILABLE_INDICATORS: 3

Калибровочные параметры (Calibration Parameters) для демографического расширения:

* GAMMA_SCALE: 0.9543 (Компенсирует дискретность годового старения когорты)
* g9: 1.0000 (Residual internal retention effect, default)
* g10: 1.0000 (Migration replenishment scale, default)
* LABOUR_RETENTION_SCALE: 0.0015 (Базовая размерность остаточного удержания рынка труда)
* MIGRATION_CHILD_SHARE: 0.15 (Доля детей в международной миграции)
* MIGRATION_WORKING_SHARE: 0.80 (Доля трудоспособного возраста в миграции)
* MIGRATION_SENIOR_SHARE: 0.05 (Доля пожилых в миграции)

2. МАТЕМАТИЧЕСКАЯ ТОЧНОСТЬ (DECIMAL POLICY)
Все расчёты выполняются с использованием BCMath.
Глобальное правило: реализация обязана либо вызывать bcscale(SCALE) при инициализации контекста, либо явно передавать параметр scale = SCALE в каждый вызов функций bcadd, bcsub, bcmul, bcdiv.
Любое использование функций floatval(), (float) при расчёте risk_score строго запрещено.
3. ТЕРМИНОЛОГИЯ: ELIGIBLE INDICATORS
Термин "eligible" имеет строгое математическое и системное определение:
eligible := available AND valid AND production_allowed
4. ПОКРЫТИЕ (COVERAGE RATIO)
Ограничения оригинального веса индикатора: 0.0000 <= original_weight <= 100.0000.
Сумма всех original_weight в конфигурации строго равна 100.0000.

Формула:
coverage_ratio = Сумма(original_weight) для всех eligible indicators.

Условия выполнения расчета risk_score:

* coverage_ratio >= MINIMUM_COVERAGE_REQUIRED
* Количество eligible indicators >= MINIMUM_AVAILABLE_INDICATORS
* Все индикаторы с флагом is_required = true являются eligible.

5. ЭФФЕКТИВНЫЕ ВЕСА И ROUNDING RECONCILIATION
Ограничения дисконта: 0.0000 < frequency_discount <= 1.0000.

Шаги расчета:

* w_base_i = (original_weight_i / coverage_ratio) * 100.0000
* w_disc_i = w_base_i * frequency_discount_i
* effective_weight_i_raw = (w_disc_i / Сумма(w_disc всех eligible indicators)) * 100.0000

Rounding Reconciliation (Приведение округлений):

* Каждое effective_weight_i_raw округляется до STORAGE_SCALE (4 знака).
* Рассчитывается delta = 100.0000 - Сумма(округленных effective_weights).
* GUARD ПРАВИЛО: Если delta == 0.0000, no reconciliation performed.
* Если delta != 0.0000, delta детерминированно прибавляется к ОДНОМУ индикатору.
* Критерий выбора индикатора для прибавления delta:
1. Максимальный original_weight.
2. При равенстве: максимальный w_disc.
3. При равенстве: алфавитный порядок indicator_key по возрастанию.



6. СТРАТЕГИИ НОРМАЛИЗАЦИИ (THRESHOLD NORMALIZATION)
Пусть H = high_risk_threshold, L = low_risk_threshold, x = transformed_value.

Guard (Защита от деления на ноль):
Если |H - L| < NORMALIZATION_EPSILON:

* Если x находится на "безопасной" стороне (x <= L для higher_is_riskier, x >= L для lower_is_riskier): score = 0.0000
* Во всех остальных случаях: score = 100.0000

higher_is_riskier (H > L):

* Если x <= L: score = 0.0000
* Если x >= H: score = 100.0000
* Иначе: score = ((x - L) / (H - L)) * 100.0000

lower_is_riskier (H < L):

* Если x >= L: score = 0.0000
* Если x <= H: score = 100.0000
* Иначе: score = ((L - x) / (L - H)) * 100.0000

distance_from_target_is_riskier:
Пусть T = target_value, M = max_deviation.
Guard: Если M <= 0, выбрасывается INVALID_CONFIGURATION_THRESHOLDS.

* score = MIN( 100.0000, (|x - T| / M) * 100.0000 )

outside_band_is_riskier:
Требуются: safe_min, safe_max, outside_band_min_boundary, outside_band_max_boundary.
Guard: outside_band_min_boundary < safe_min < safe_max < outside_band_max_boundary.

* Если safe_min <= x <= safe_max: score = 0.0000
* Если x <= outside_band_min_boundary ИЛИ x >= outside_band_max_boundary: score = 100.0000
* Левая интерполяция (outside_band_min_boundary < x < safe_min):
score = 100.0000 * (safe_min - x) / (safe_min - outside_band_min_boundary)
* Правая интерполяция (safe_max < x < outside_band_max_boundary):
score = 100.0000 * (x - safe_max) / (outside_band_max_boundary - safe_max)

7. ИТОГОВЫЕ РАСЧЕТЫ RISK SCORE И КАТЕГОРИЙ
Contribution индикатора:
contribution_i = (normalized_score_i * reconciled_effective_weight_i) / 100.0000

Итоговый риск:
risk_score = Сумма(contribution_i) для всех eligible indicators.

Category Score (Расчет по категориям):

* Для категории С, собирается множество A_c (eligible indicators в этой категории).
* Если A_c пусто, category_score = null.
* Сумма_весов_категории = Сумма(reconciled_effective_weight_i) для i в A_c.
* cat_weight_i = (reconciled_effective_weight_i / Сумма_весов_категории) * 100.0000.
* category_score = Сумма(normalized_score_i * cat_weight_i) / 100.0000.
* category_contribution = category_score * Сумма(original_weight_i для i в A_c) / 100.0000.

8. КЛАССИФИКАЦИЯ RISK BANDS
Назначение risk_band происходит путем строгого сравнения risk_score с диапазонами (хранящимися в risk_band_threshold_sets).

* very_low: risk_score >= very_low_min AND risk_score <= very_low_max
* low: risk_score > low_min AND risk_score <= low_max
* moderate: risk_score > moderate_min AND risk_score <= moderate_max
* high: risk_score > high_min AND risk_score <= high_max
* severe: risk_score > severe_min AND risk_score <= severe_max

9. БЭКТЕСТИНГ (BACKTEST LOGIC)

* small_sample_warning вычисляется в конце прогона (на основе таблицы historical_episodes):
Если sample_size_n < 10, small_sample_warning = true.
Если sample_size_n >= 10, small_sample_warning = false.
До завершения расчета поле имеет значение NULL или FALSE.

10. ОПЦИОНАЛЬНОЕ РАСШИРЕНИЕ: ДЕМОГРАФИЧЕСКИЙ МЕТАБОЛИЗМ (ACMF)
Шаг времени t -> t+1 равен 1 ГОДУ.

Разделение режимов:
OBSERVED MODE (Исторический анализ):

* Births(t) является внешним наблюдаемым фактом (external input).
* Deaths_k(t) является внешним наблюдаемым фактом.

SIMULATION MODE (Моделирование):

* fertility_rate(t) определяется как annual_births_per_working_age_person.
* Births(t) = fertility_rate(t) * P2(t).
* mortality_rate_k(t) определяется как annual_mortality_probability.
* Deaths_k(t) = mortality_rate_k(t) * P_k(t).

Уравнения баланса:
P1(t+1) = MAX(0, P1(t) + Births(t) - Aging12(t) - Deaths1(t) + Migration1(t))
P2(t+1) = MAX(0, P2(t) + Aging12(t) - Aging23(t) - Deaths2(t) + Migration2(t) + LabourRetention(t))
P3(t+1) = MAX(0, P3(t) + Aging23(t) - Deaths3(t) + Migration3(t))

Потоки:
Aging12(t) = GAMMA_SCALE * P1(t) / 15
Aging23(t) = GAMMA_SCALE * P2(t) / 50

Миграционный слой:
IntlOther(t) = NetInternationalMigration(t) + OtherInternationalMigration(t)
Migration1_raw(t) = MIGRATION_CHILD_SHARE * IntlOther(t) + IP_0_17(t)
Migration2_raw(t) = MIGRATION_WORKING_SHARE * IntlOther(t) + IP_18_64(t)
Migration3_raw(t) = MIGRATION_SENIOR_SHARE * IntlOther(t) + IP_65plus(t)

Migration_k(t) = g10 * Migration_k_raw(t)
LabourRetention(t) = LABOUR_RETENTION_SCALE * (g9 - 1) * P2(t)

=============================================================================

ФАЙЛ 3. DATABASE SCHEMA CONTRACT

Версия: 1.5.0-FINAL
СУБД: MySQL 8.4 LTS
Engine: InnoDB
Charset: utf8mb4

ОБЩИЕ ПРАВИЛА:
Все id: BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY.
Все timestamps: DATETIME(6).
Все economic values: DECIMAL(24,8).
Все score-domain values: DECIMAL(10,4).
Политика Append-Only применяется к таблице audit_records. Данные не подлежат физическому удалению (только soft delete для сущностей).

ГРУППА 1: ИДЕНТИФИКАЦИЯ И ДОСТУП
ТАБЛИЦА users

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* user_key VARCHAR(64) NOT NULL UNIQUE
* email VARCHAR(255) NOT NULL UNIQUE
* password_hash VARCHAR(255) NOT NULL
* display_name VARCHAR(255) NOT NULL
* status VARCHAR(32) NOT NULL DEFAULT 'active'
* last_login_at DATETIME(6) NULL
* created_at DATETIME(6) NOT NULL
* updated_at DATETIME(6) NOT NULL
* deleted_at DATETIME(6) NULL

ТАБЛИЦА roles

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* role_key VARCHAR(64) NOT NULL UNIQUE
* display_name VARCHAR(255) NOT NULL
* description TEXT NULL
* created_at DATETIME(6) NOT NULL

ТАБЛИЦА user_roles

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* user_id BIGINT UNSIGNED NOT NULL
* role_id BIGINT UNSIGNED NOT NULL
* assigned_by BIGINT UNSIGNED NULL
* assigned_at DATETIME(6) NOT NULL
* UNIQUE KEY uq_user_role (user_id, role_id)
* FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
* FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
* FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL

ТАБЛИЦА api_keys

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* user_id BIGINT UNSIGNED NOT NULL
* key_prefix VARCHAR(32) NOT NULL UNIQUE
* key_hash CHAR(64) NOT NULL
* name VARCHAR(255) NOT NULL
* status VARCHAR(32) NOT NULL DEFAULT 'active'
* last_used_at DATETIME(6) NULL
* expires_at DATETIME(6) NULL
* created_at DATETIME(6) NOT NULL
* revoked_at DATETIME(6) NULL
* FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE

ГРУППА 2: ИСТОЧНИКИ ДАННЫХ
ТАБЛИЦА data_sources

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* source_key VARCHAR(64) NOT NULL UNIQUE
* display_name VARCHAR(255) NOT NULL
* source_type VARCHAR(64) NOT NULL
* base_url VARCHAR(1024) NOT NULL
* official_documentation_url VARCHAR(1024) NOT NULL
* terms_of_use_url VARCHAR(1024) NULL
* license_status ENUM('public_open', 'public_open_candidate', 'requires_license', 'unverified') NOT NULL DEFAULT 'unverified'
* production_allowed BOOLEAN NOT NULL DEFAULT FALSE
* notes TEXT NULL
* created_at DATETIME(6) NOT NULL
* updated_at DATETIME(6) NOT NULL

ТАБЛИЦА retry_policies

* policy_key VARCHAR(64) NOT NULL UNIQUE
* max_retries INT UNSIGNED NOT NULL DEFAULT 3
* backoff_multiplier DECIMAL(10,4) NOT NULL DEFAULT 2.0000
* created_at DATETIME(6) NOT NULL

ТАБЛИЦА source_endpoints

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* data_source_id BIGINT UNSIGNED NOT NULL
* endpoint_key VARCHAR(128) NOT NULL
* endpoint_url VARCHAR(2048) NOT NULL
* method VARCHAR(16) NOT NULL DEFAULT 'GET'
* response_format VARCHAR(32) NOT NULL
* requires_auth BOOLEAN NOT NULL DEFAULT FALSE
* rate_limit_per_minute INT UNSIGNED NULL
* timeout_seconds INT UNSIGNED NOT NULL DEFAULT 30
* retry_policy_key VARCHAR(64) NULL
* production_allowed BOOLEAN NOT NULL DEFAULT FALSE
* validation_status VARCHAR(32) NOT NULL DEFAULT 'pending_validation'
* last_validated_at DATETIME(6) NULL
* created_at DATETIME(6) NOT NULL
* updated_at DATETIME(6) NOT NULL
* UNIQUE KEY uq_ds_endpoint (data_source_id, endpoint_key)
* FOREIGN KEY (data_source_id) REFERENCES data_sources(id) ON DELETE RESTRICT
* FOREIGN KEY (retry_policy_key) REFERENCES retry_policies(policy_key) ON DELETE SET NULL

ГРУППА 3: ИНДИКАТОРЫ И КАЛЕНДАРЬ
ТАБЛИЦА series

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* series_key VARCHAR(128) NOT NULL UNIQUE
* display_name VARCHAR(255) NOT NULL
* data_source_id BIGINT UNSIGNED NOT NULL
* source_endpoint_id BIGINT UNSIGNED NULL
* source_provider VARCHAR(128) NOT NULL
* underlying_origin VARCHAR(128) NULL
* external_series_identifier VARCHAR(255) NULL
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
* validation_checked_at DATETIME(6) NULL
* created_at DATETIME(6) NOT NULL
* updated_at DATETIME(6) NOT NULL
* deleted_at DATETIME(6) NULL
* FOREIGN KEY (data_source_id) REFERENCES data_sources(id) ON DELETE RESTRICT
* FOREIGN KEY (source_endpoint_id) REFERENCES source_endpoints(id) ON DELETE SET NULL

ТАБЛИЦА series_validation_results

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* series_id BIGINT UNSIGNED NOT NULL
* validation_run_key VARCHAR(128) NOT NULL
* validation_status VARCHAR(32) NOT NULL
* validation_error_code VARCHAR(64) NULL
* http_status_code INT NULL
* endpoint_url VARCHAR(2048) NOT NULL
* response_schema_hash CHAR(64) NULL
* latest_observation_date DATE NULL
* detected_frequency VARCHAR(32) NULL
* checked_at DATETIME(6) NOT NULL
* FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE

ТАБЛИЦА license_reviews

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* series_id BIGINT UNSIGNED NOT NULL
* review_status VARCHAR(32) NOT NULL
* license_status_result VARCHAR(32) NOT NULL
* reviewed_by BIGINT UNSIGNED NOT NULL
* reviewed_at DATETIME(6) NOT NULL
* FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
* FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT

ТАБЛИЦА release_calendars

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* series_id BIGINT UNSIGNED NOT NULL
* reference_period_start DATE NOT NULL
* reference_period_end DATE NOT NULL
* expected_release_date DATETIME(6) NULL
* actual_release_date DATETIME(6) NULL
* estimated_release_date DATETIME(6) NULL
* release_date_quality VARCHAR(64) NOT NULL DEFAULT 'unknown'
* release_status VARCHAR(32) NOT NULL DEFAULT 'unknown'
* created_at DATETIME(6) NOT NULL
* updated_at DATETIME(6) NOT NULL
* UNIQUE KEY uq_release_cal (series_id, reference_period_start, reference_period_end)
* FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE

ГРУППА 4: ВИНТАЖИ, SNAPSHOTS И НАБЛЮДЕНИЯ
ТАБЛИЦА ingestion_runs

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* ingestion_run_key VARCHAR(128) NOT NULL UNIQUE
* data_source_id BIGINT UNSIGNED NOT NULL
* started_at DATETIME(6) NOT NULL
* completed_at DATETIME(6) NULL
* status VARCHAR(32) NOT NULL DEFAULT 'running'
* records_inserted INT UNSIGNED NOT NULL DEFAULT 0
* error_code VARCHAR(64) NULL
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (data_source_id) REFERENCES data_sources(id) ON DELETE RESTRICT

ТАБЛИЦА data_snapshots

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* snapshot_key VARCHAR(128) NOT NULL UNIQUE
* ingestion_run_id BIGINT UNSIGNED NOT NULL
* series_id BIGINT UNSIGNED NOT NULL
* snapshot_timestamp DATETIME(6) NOT NULL
* vintage_date DATETIME(6) NOT NULL
* source_payload_hash CHAR(64) NOT NULL
* content_hash CHAR(64) NOT NULL
* is_duplicate BOOLEAN NOT NULL DEFAULT FALSE
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs(id) ON DELETE RESTRICT
* FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE RESTRICT

ТАБЛИЦА data_observations

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* series_id BIGINT UNSIGNED NOT NULL
* observation_date DATE NOT NULL
* frequency_at_observation VARCHAR(32) NOT NULL
* raw_value DECIMAL(24,8) NOT NULL
* unit VARCHAR(64) NOT NULL
* value_status VARCHAR(32) NOT NULL DEFAULT 'normal'
* content_hash CHAR(64) NOT NULL
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE RESTRICT

ТАБЛИЦА snapshot_observations

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* snapshot_id BIGINT UNSIGNED NOT NULL
* series_id BIGINT UNSIGNED NOT NULL
* observation_id BIGINT UNSIGNED NOT NULL
* vintage_date DATETIME(6) NOT NULL
* release_date DATETIME(6) NULL
* estimated_release_date DATETIME(6) NULL
* release_date_quality VARCHAR(64) NOT NULL
* reproducibility_allowed BOOLEAN NOT NULL DEFAULT TRUE
* is_revision BOOLEAN NOT NULL DEFAULT FALSE
* revision_number INT UNSIGNED NULL
* created_at DATETIME(6) NOT NULL
* UNIQUE KEY uq_snap_obs (snapshot_id, series_id, observation_id)
* FOREIGN KEY (snapshot_id) REFERENCES data_snapshots(id) ON DELETE CASCADE
* FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
* FOREIGN KEY (observation_id) REFERENCES data_observations(id) ON DELETE CASCADE

ТАБЛИЦА data_revision_events

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* series_id BIGINT UNSIGNED NOT NULL
* observation_id BIGINT UNSIGNED NOT NULL
* previous_value DECIMAL(24,8) NULL
* new_value DECIMAL(24,8) NOT NULL
* value_changed BOOLEAN NOT NULL
* revision_detected_at DATETIME(6) NOT NULL
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
* FOREIGN KEY (observation_id) REFERENCES data_observations(id) ON DELETE CASCADE

ТАБЛИЦА data_release_records

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* series_id BIGINT UNSIGNED NOT NULL
* snapshot_id BIGINT UNSIGNED NULL
* release_detected_at DATETIME(6) NOT NULL
* release_status VARCHAR(32) NOT NULL
* records_seen INT UNSIGNED NOT NULL
* records_changed INT UNSIGNED NOT NULL
* is_revision BOOLEAN NOT NULL DEFAULT FALSE
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
* FOREIGN KEY (snapshot_id) REFERENCES data_snapshots(id) ON DELETE SET NULL

ГРУППА 5: КОНФИГУРАЦИИ
ТАБЛИЦА model_versions

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* model_version VARCHAR(32) NOT NULL UNIQUE
* release_date DATE NOT NULL
* formula_key VARCHAR(64) NOT NULL
* status VARCHAR(32) NOT NULL DEFAULT 'draft'
* created_at DATETIME(6) NOT NULL

ТАБЛИЦА risk_configurations

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* configuration_key VARCHAR(128) NOT NULL UNIQUE
* owner_user_id BIGINT UNSIGNED NULL
* name VARCHAR(255) NOT NULL
* configuration_type VARCHAR(64) NOT NULL
* lifecycle_status VARCHAR(32) NOT NULL DEFAULT 'draft'
* created_at DATETIME(6) NOT NULL
* updated_at DATETIME(6) NOT NULL
* deleted_at DATETIME(6) NULL
* FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL

ТАБЛИЦА risk_configuration_versions

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* configuration_id BIGINT UNSIGNED NOT NULL
* version_number INT UNSIGNED NOT NULL
* version_key VARCHAR(128) NOT NULL UNIQUE
* model_version_id BIGINT UNSIGNED NOT NULL
* status VARCHAR(32) NOT NULL DEFAULT 'draft'
* is_published BOOLEAN NOT NULL DEFAULT FALSE
* coverage_minimum DECIMAL(10,4) NOT NULL DEFAULT 60.0000
* config_hash CHAR(64) NOT NULL
* created_at DATETIME(6) NOT NULL
* UNIQUE KEY uq_config_ver (configuration_id, version_number)
* FOREIGN KEY (configuration_id) REFERENCES risk_configurations(id) ON DELETE CASCADE
* FOREIGN KEY (model_version_id) REFERENCES model_versions(id) ON DELETE RESTRICT

ТАБЛИЦА indicator_configs

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* configuration_version_id BIGINT UNSIGNED NOT NULL
* series_id BIGINT UNSIGNED NOT NULL
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
* created_at DATETIME(6) NOT NULL
* UNIQUE KEY uq_ind_conf (configuration_version_id, indicator_key)
* FOREIGN KEY (configuration_version_id) REFERENCES risk_configuration_versions(id) ON DELETE CASCADE
* FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE RESTRICT

ТАБЛИЦА risk_configuration_overrides

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* indicator_config_id BIGINT UNSIGNED NOT NULL
* override_type VARCHAR(64) NOT NULL
* override_reason TEXT NOT NULL
* approved_by BIGINT UNSIGNED NOT NULL
* approved_at DATETIME(6) NOT NULL
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (indicator_config_id) REFERENCES indicator_configs(id) ON DELETE CASCADE
* FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT

ТАБЛИЦА risk_band_threshold_sets

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* threshold_set_key VARCHAR(128) NOT NULL UNIQUE
* version INT UNSIGNED NOT NULL
* very_low_min DECIMAL(10,4) NOT NULL
* very_low_max DECIMAL(10,4) NOT NULL
* low_min DECIMAL(10,4) NOT NULL
* low_max DECIMAL(10,4) NOT NULL
* moderate_min DECIMAL(10,4) NOT NULL
* moderate_max DECIMAL(10,4) NOT NULL
* high_min DECIMAL(10,4) NOT NULL
* high_max DECIMAL(10,4) NOT NULL
* severe_min DECIMAL(10,4) NOT NULL
* severe_max DECIMAL(10,4) NOT NULL
* created_at DATETIME(6) NOT NULL

ГРУППА 6: РЕЗУЛЬТАТЫ И БЭКТЕСТЫ
ТАБЛИЦА risk_score_results

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* score_key VARCHAR(128) NOT NULL UNIQUE
* configuration_version_id BIGINT UNSIGNED NOT NULL
* vintage_date DATETIME(6) NOT NULL
* calculation_mode VARCHAR(32) NOT NULL
* calculation_status VARCHAR(64) NOT NULL
* risk_score DECIMAL(10,4) NULL
* risk_band VARCHAR(32) NULL
* coverage_ratio DECIMAL(10,4) NOT NULL
* available_indicator_count INT UNSIGNED NOT NULL
* required_indicator_missing BOOLEAN NOT NULL DEFAULT FALSE
* effective_weights_sum DECIMAL(10,4) NOT NULL
* calculation_hash CHAR(64) NOT NULL
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (configuration_version_id) REFERENCES risk_configuration_versions(id) ON DELETE RESTRICT

ТАБЛИЦА risk_score_warnings

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* risk_score_result_id BIGINT UNSIGNED NOT NULL
* warning_code VARCHAR(64) NOT NULL
* message TEXT NOT NULL
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (risk_score_result_id) REFERENCES risk_score_results(id) ON DELETE CASCADE

ТАБЛИЦА risk_score_indicator_contributions

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* risk_score_result_id BIGINT UNSIGNED NOT NULL
* indicator_config_id BIGINT UNSIGNED NOT NULL
* series_id BIGINT UNSIGNED NOT NULL
* raw_value DECIMAL(24,8) NULL
* transformed_value DECIMAL(24,8) NULL
* normalized_indicator_score DECIMAL(10,4) NULL
* original_weight DECIMAL(10,4) NOT NULL
* frequency_discount DECIMAL(10,4) NOT NULL DEFAULT 1.0000
* effective_weight DECIMAL(10,4) NULL
* contribution_value DECIMAL(10,4) NULL
* is_available BOOLEAN NOT NULL
* missing_reason VARCHAR(64) NULL
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (risk_score_result_id) REFERENCES risk_score_results(id) ON DELETE CASCADE
* FOREIGN KEY (indicator_config_id) REFERENCES indicator_configs(id) ON DELETE RESTRICT
* FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE RESTRICT

ТАБЛИЦА historical_episodes

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* episode_key VARCHAR(128) NOT NULL UNIQUE
* display_name VARCHAR(255) NOT NULL
* start_date DATE NOT NULL
* end_date DATE NOT NULL
* stress_type VARCHAR(64) NOT NULL
* severity_label VARCHAR(32) NOT NULL
* expected_macro_signature JSON NULL
* source_notes TEXT NULL
* inclusion_rationale TEXT NOT NULL
* detection_threshold_score DECIMAL(10,4) NOT NULL
* detection_threshold_band VARCHAR(32) NOT NULL
* detection_window_before_days INT UNSIGNED NOT NULL DEFAULT 90
* detection_window_after_days INT UNSIGNED NOT NULL DEFAULT 90
* minimum_persistence_periods INT UNSIGNED NOT NULL DEFAULT 1
* status VARCHAR(32) NOT NULL DEFAULT 'draft'
* approved_by BIGINT UNSIGNED NULL
* approved_at DATETIME(6) NULL
* created_at DATETIME(6) NOT NULL
* updated_at DATETIME(6) NOT NULL
* FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT

ТАБЛИЦА backtest_runs

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* backtest_run_key VARCHAR(128) NOT NULL UNIQUE
* configuration_version_id BIGINT UNSIGNED NOT NULL
* run_status VARCHAR(32) NOT NULL DEFAULT 'running'
* false_positive_count INT UNSIGNED NOT NULL DEFAULT 0
* small_sample_warning BOOLEAN NULL
* sample_size_n INT UNSIGNED NOT NULL
* started_at DATETIME(6) NOT NULL
* completed_at DATETIME(6) NULL
* FOREIGN KEY (configuration_version_id) REFERENCES risk_configuration_versions(id) ON DELETE RESTRICT

ТАБЛИЦА backtest_episode_results

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* backtest_run_id BIGINT UNSIGNED NOT NULL
* episode_id BIGINT UNSIGNED NOT NULL
* episode_key VARCHAR(128) NOT NULL
* detected BOOLEAN NOT NULL
* first_detection_date DATETIME(6) NULL
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (backtest_run_id) REFERENCES backtest_runs(id) ON DELETE CASCADE
* FOREIGN KEY (episode_id) REFERENCES historical_episodes(id) ON DELETE CASCADE

ТАБЛИЦА backtest_episode_score_points

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* backtest_episode_result_id BIGINT UNSIGNED NOT NULL
* risk_score_result_id BIGINT UNSIGNED NOT NULL
* detection_threshold_met BOOLEAN NOT NULL
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (backtest_episode_result_id) REFERENCES backtest_episode_results(id) ON DELETE CASCADE
* FOREIGN KEY (risk_score_result_id) REFERENCES risk_score_results(id) ON DELETE CASCADE

ГРУППА 7: NARRATIVES, JOBS И АУДИТ
ТАБЛИЦА narrative_slots

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* slot_key VARCHAR(128) NOT NULL
* version_number INT UNSIGNED NOT NULL
* status VARCHAR(32) NOT NULL DEFAULT 'draft'
* scientific_integrity_status VARCHAR(32) NOT NULL DEFAULT 'pending'
* approved_by BIGINT UNSIGNED NULL
* approved_at DATETIME(6) NULL
* created_at DATETIME(6) NOT NULL
* UNIQUE KEY uq_slot_ver (slot_key, version_number)
* FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL

ТАБЛИЦА narrative_slot_translations

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* narrative_slot_id BIGINT UNSIGNED NOT NULL
* locale VARCHAR(16) NOT NULL
* text TEXT NOT NULL
* text_hash CHAR(64) NOT NULL
* created_at DATETIME(6) NOT NULL
* updated_at DATETIME(6) NOT NULL
* UNIQUE KEY uq_slot_loc (narrative_slot_id, locale)
* FOREIGN KEY (narrative_slot_id) REFERENCES narrative_slots(id) ON DELETE CASCADE

ТАБЛИЦА narrative_reports

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* report_key VARCHAR(128) NOT NULL UNIQUE
* risk_score_result_id BIGINT UNSIGNED NOT NULL
* locale VARCHAR(16) NOT NULL
* report_status VARCHAR(32) NOT NULL
* seed_hash CHAR(64) NOT NULL
* seed_int BIGINT UNSIGNED NOT NULL
* full_text MEDIUMTEXT NOT NULL
* scientific_integrity_status VARCHAR(32) NOT NULL
* generated_at DATETIME(6) NOT NULL
* FOREIGN KEY (risk_score_result_id) REFERENCES risk_score_results(id) ON DELETE RESTRICT

ТАБЛИЦА narrative_report_slots

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* narrative_report_id BIGINT UNSIGNED NOT NULL
* narrative_slot_id BIGINT UNSIGNED NOT NULL
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (narrative_report_id) REFERENCES narrative_reports(id) ON DELETE CASCADE
* FOREIGN KEY (narrative_slot_id) REFERENCES narrative_slots(id) ON DELETE RESTRICT

ТАБЛИЦА job_runs

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* job_key VARCHAR(128) NOT NULL UNIQUE
* job_type VARCHAR(128) NOT NULL
* status VARCHAR(32) NOT NULL DEFAULT 'queued'
* started_at DATETIME(6) NOT NULL
* completed_at DATETIME(6) NULL
* error_code VARCHAR(64) NULL
* created_at DATETIME(6) NOT NULL

ТАБЛИЦА system_errors

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* error_key VARCHAR(128) NOT NULL UNIQUE
* error_code VARCHAR(64) NOT NULL
* http_status_code INT NULL
* human_message TEXT NOT NULL
* machine_message TEXT NOT NULL
* created_at DATETIME(6) NOT NULL

ТАБЛИЦА audit_records (Strictly Append-Only)

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* audit_key VARCHAR(128) NOT NULL UNIQUE
* actor_user_id BIGINT UNSIGNED NULL
* actor_name VARCHAR(255) NULL
* actor_role VARCHAR(64) NULL
* event_type VARCHAR(128) NOT NULL
* entity_type VARCHAR(128) NOT NULL
* entity_id BIGINT UNSIGNED NULL
* diff_json JSON NULL
* created_at DATETIME(6) NOT NULL

=============================================================================

ФАЙЛ 4. PHILOSOPHICAL AND SCIENTIFIC INTEGRITY FOUNDATION

Версия: 1.5.0-FINAL
Статус: Philosophical Truth

1. ЭПИСТЕМОЛОГИЯ ОТКАЗА ОТ ИЛЛЮЗИИ ЗНАНИЯ
Главный принцип системы: Система не должна притворяться, что знает то, чего не поддерживают данные.
MacroRisk — это система детерминированной диагностики структурной устойчивости, а не механизм прогнозирования (forecasting). Риск-скор является исключительно модельной оценкой (model-derived estimate), полученной на основе конфигурации и явно заданных порогов, а не эмпирически наблюдаемым фактом экономики.
2. ПРИНЦИПЫ НАУЧНОЙ ЧЕСТНОСТИ В ТЕКСТАХ И ОТЧЁТАХ
Система категорически запрещает:

* Causal Claims: Заявлять причинно-следственные связи без методологического доказательства (запрет слов "caused by").
* False Certainty: Генерировать "прогнозы" и использовать слова "гарантирует", "доказывает" или "неизбежно".
* Fake Precision: Показывать фальшивую статистическую точность на малых выборках. Бэктест с количеством исторических эпизодов менее 10 (N < 10) является исключительно диагностическим. Агрегированные метрики точности (recall, precision) для малых выборок запрещены и скрываются. Отображаются только абсолютные числа (detected count, missed count) с обязательным предупреждением.
* LLM Нарративы: Использовать Generative AI (LLM) для формирования финальных аналитических выводов в обход детерминированных текстовых слотов. Seed Hash (hex string) и Seed Integer (BIG_ENDIAN derived из первых 8 байт хеша) обеспечивают детерминированный выбор предварительно проверенных человеком текстов.

3. УРОК МАНИТОБЫ И ГРАНИЦЫ ИНТЕРПРЕТАЦИИ
На основе демографического анализа Манитобы система выводит важнейший методологический концепт: видимая стабильность системы не означает её внутреннее благополучие. Стабильность когорты P2 обеспечивается внешней миграцией (migration replenishment), компенсирующей падение внутреннего естественного воспроизводства.
Интерпретационные рамки (Boundaries): В отчётах запрещены публицистические или паникерские термины ("аппарат искусственного жизнеобеспечения", "крах системы"). Допустимая терминология: "The demographic extension suggests external-replenishment dependence under the configured assumptions." Модель разделяет внутренние механизмы удержания и внешние механизмы подпитки через прозрачные параметры калибровки.
