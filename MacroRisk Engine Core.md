# ФАЙЛ 1. MASTER ARCHITECTURE AND TECHNICAL SPECIFICATION

Версия: 1.7.0-FINAL

Тип проекта: Новая самостоятельная система

Язык: PHP 8.3+ (Framework-independent, Laravel запрещён)

СУБД: MySQL 8.4 LTS InnoDB utf8mb4

Локаль: en-CA (fr-CA в Phase 3)

Статус: Implementation-Ready Deterministic Source of Truth

## 1. АРХИТЕКТУРНЫЕ ПРИНЦИПЫ И ОГРАНИЧЕНИЯ

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

## 2. ПРАВИЛА ОБРАБОТКИ ВРЕМЕНИ И ДАННЫХ

Все входящие даты и времена должны быть нормализованы в таймзону TIMEZONE (UTC) перед сохранением в базу данных.

Для хранения используется формат DATETIME(6) для обеспечения микросекундной точности, необходимой для дедупликации и предотвращения состояния гонки (race conditions).

## 3. ИСТОЧНИКИ ДАННЫХ И ЖИЗНЕННЫЙ ЦИКЛ

Официальные источники-кандидаты: Statistics Canada (WDS, Full Table Download), Bank of Canada (Valet API), OSFI.

Прямые источники CMHC и CREA запрещены как production default (используются эквиваленты StatCan).

SOURCE VALIDATION STATE MACHINE (ENUM)

Формальные состояния базового автомата:

* pending_validation: Начальное состояние.
* valid: Переход ИСКЛЮЧИТЕЛЬНО после успешной проверки (HTTP 200, схема верна, частота подтверждена). Risk Officer не может установить valid вручную без проверки.
* temporary_unavailable: Ошибка (HTTP 500, 503, timeout) или HTTP 429 (rate limited). Маппинг остаётся валидным, но использование в расчетах заблокировано. В поле validation_error_code при этом явно записывается точный код ошибки (например, SOURCE_TIMEOUT или SOURCE_RATE_LIMITED).
* schema_mismatch: HTTP 200, но ожидаемые поля отсутствуют.
* series_mapping_stale: HTTP 404, таблица/вектор удалены, API deprecation. Production use блокируется. Возврат в valid только после новой автоматической проверки.
* data_pending: Для новых серий в grace period (схема верна, время релиза ещё не наступило). Production use запрещён.

Дополнительные операционные состояния, используемые в расширенных журналах и схеме БД (расширяющие ENUM): unavailable, access_denied, source_timeout, source_rate_limited, release_late, missing_no_historical_data.

LICENSE GATE STATE MACHINE (ENUM)

* unverified: Начальное состояние.
* public_open_candidate: Источник выглядит публичным, terms of use не проверены.
* public_open: ТОЛЬКО после задокументированного review (Admin или Risk Officer).
* requires_license: При обнаружении платной подписки или ограничений.

Инвариант безопасности: production_allowed = true возможно ТОЛЬКО если license_status = public_open (как на уровне таблицы series, так и на уровне data_sources). Система (или триггер) обязана блокировать или сбрасывать флаг production_allowed, если лицензия меняется на отличную от public_open.

RELEASE CALENDAR RULES

Каждая серия должна иметь записи в Release Calendar для production-использования.

* expected: Дата релиза в будущем.
* delayed: expected_release_date наступила, данных нет.
* release_late: delay превышает tolerance.
* missing: источник подтверждает отсутствие релиза (не означает stale_mapping).
* revised: релиз относится к прошлому периоду.
* Разрешение конфликта release_date: Если для наблюдения существуют и actual_release_date, и estimated_release_date, система ОБЯЗАНА использовать actual_release_date.
* Если данные нужны для production, а official release date отсутствует: estimated_release_date = ingestion_date, release_date_quality = fallback_to_ingestion, release_date_source = system_inferred.

Источники данных и жизненный цикл: Source Validation State Machine (Полная спецификация состояний и переходов)
Каждая серия и эндпоинт в системе находятся в одном из детерминированных состояний валидации. Все переходы строго детерминированы:

pending_validation

Начальное состояние для любой вновь зарегистрированной серии или эндпоинта.

Разрешённые переходы: в valid (при успешной live-проверке), в unavailable (при инфраструктурном отказе эндпоинта), в access_denied (при проблемах с авторизацией), в temporary_unavailable (при таймаутах/лимитах), в schema_mismatch (при расхождении схемы), в data_pending (в период grace period).

valid

Рабочее состояние, разрешающее использование данных в production (при условии выполнения требований License Gate).

Разрешённые переходы:

в temporary_unavailable (при временных сетевых ошибках или HTTP 429/503);

в series_mapping_stale (при HTTP 404/410, удалении таблицы/вектора или изменении эндпоинта);

в schema_mismatch (при изменении формата ответа API);

в access_denied (при отзыве или истечении credentials);

в release_late (если наступила ожидаемая дата релиза, но данные не поступили вовремя).

temporary_unavailable

Состояние временной блокировки из-за сетевых сбоев, таймаутов (validation_error_code = SOURCE_TIMEOUT) или превышения лимитов (validation_error_code = SOURCE_RATE_LIMITED), а также HTTP 500/503.

Использование серии в расчётах заблокировано.

Разрешённые переходы: обратно в valid (при следующем успешном опросе), в series_mapping_stale или unavailable, если временная ошибка переросла в постоянную.

unavailable

Постоянный инфраструктурный сбой (например, перманентный отказ хоста источника, удаление сервиса без редиректа).

Триггер: Постоянная недоступность эндпоинта (фиксируется после исчерпания retry_policy).

Разрешённые переходы: в valid (после исправления инфраструктуры и успешной перепроверки), в series_mapping_stale.

access_denied

Ошибка доступа к источнику данных.

Триггер: Получение HTTP 401 Unauthorized, HTTP 403 Forbidden, или истечение срока действия API-ключа при запросе к защищённому эндпоинту.

Разрешённые переходы: в valid только после обновления учетных данных и успешной повторной проверки.

schema_mismatch

Ошибка структуры данных.

Триггер: HTTP 200 получен, но парсер не обнаружил обязательных ключей, колонок или векторов в ответе эндпоинта.

Разрешённые переходы: в valid после обновления адаптера и успешной повторной проверки.

series_mapping_stale

Устаревшее или неверное сопоставление (например, несуществующий table_id в StatCan или series_id в Bank of Canada).

Триггер: Получение HTTP 404, HTTP 410, либо явный сигнал об удалении/переименовании ряда со стороны источника.

Использование в production строго заблокировано.

Разрешённые переходы: в valid исключительно после исправления маппинга и успешной live-проверки.

data_pending

Ожидание данных в рамках льготного периода (grace period для новых серий).

Триггер: Серия создана, базовая валидация пройдена, но время публикации по календарю ещё не наступило.

Разрешённые переходы: в valid (при успешной первой загрузке), в missing_no_historical_data (если календарь истёк, а данных нет), в release_late, в temporary_unavailable.

release_late

Задержка публикации официального релиза.

Триггер: expected_release_date уже прошла, но данные не поступили в систему в пределах допустимого допуска (tolerance window).

Отличие от stale_mapping: Сам эндпоинт и маппинг живы и валидны, задерживается выпуск данных на стороне провайдера.

Разрешённые переходы: в valid (как только данные поступают и проходят ingestion), в series_mapping_stale (если задержка сменяется удалением серии).

missing_no_historical_data

Отсутствие ретроспективных данных.

Триггер: Попытка расчёта на дату винтажа, для которой у валидной серии физически нет ни одного наблюдения на или до винтажа.

Разрешённые переходы: в valid после успешной загрузки исторического ряда.

## 4. SNAPSHOT DEDUPLICATION И REVISION SELECTION

Все сохраняемые хеши (source_payload_hash, content_hash, raw_content_hash) ДОЛЖНЫ храниться как lowercase hexadecimal strings.

Разделение Хешей и Дедупликация (Snapshot Deduplication):

* source_payload_hash: hex string от сырого ответа API/CSV.
* raw_content_hash: hex string от распарсенного сырого значения, даты и юнита (БЕЗ учёта номера ревизии). Используется для дедупликации в таблице data_observations.
* content_hash: hex string от значения, даты, юнита, статуса И номера ревизии. Используется для трекинга версий в snapshot_observations и data_snapshots.

Правила дедупликации:

* Если source_payload_hash и content_hash совпадают с предыдущим снапшотом: is_duplicate = true. Новые data_observations не создаются.
* Если revision_number изменился, но raw_content_hash (без учёта ревизии) тот же: создаётся data_revision_event (value_changed = false), новая data_observation НЕ создаётся. revision_number — это атрибут доступности на винтаж (snapshot_observations), а не канонической identity наблюдения (data_observations).
* Если значение изменилось: создаётся новая data_observation, data_revision_event (value_changed = true), новый snapshot_observations.
* Физическая дедупликация на уровне БД обеспечивается уникальным индексом uq_obs_dedup в таблице data_observations по полям (series_id, observation_date, raw_value, raw_content_hash).

Выбор ревизий (Предотвращение Look-ahead bias):

Исторический расчёт (backtest) использует только ревизии, физически доступные на vintage_date.

1. Выбрать observation, где release_date <= vintage_date (с учётом правила разрешения actual vs estimated).
2. Tie-breaking (если даты совпадают): Выбрать максимальный revision_number. Если revision_number отсутствует/равен, выбрать минимальный observation_id.
3. Reproduction rule: Ранее сохранённый risk_score загружает строго связанные snapshot_observation_id, игнорируя новые данные.

## 5. ОПЕРАЦИОННЫЕ ГЕЙТЫ

BOOTSTRAP TIME WINDOW LOGIC

* Day 0-2: Разрешено создание Admin, sources, endpoints. Запрещены ingestion и расчеты risk_score.
* Day 3-7: Разрешены ingestion, validation, draft calculations. Production заблокирован.
* Day 8-14+: Production разрешён ТОЛЬКО после утверждения первого production System Preset. Условия Preset: >= 5 valid series, >= 5 public_open, >= MINIMUM_AVAILABLE_INDICATORS доступных.
Прохождение 14 дней само по себе НЕ включает production автоматически. Включает только approved preset. Risk Officer не имеет права override для pending_validation, unverified, requires_license, stale_mapping, или look-ahead bias.

CONFIGURATION PUBLICATION GATE

Переход конфигурации в status = published разрешён ТОЛЬКО если одновременно соблюдены условия:

* Сумма original_weight всех индикаторов = 100.0000, все веса >= 0.0000.
* Все thresholds валидны (very_low_min = 0.0000, severe_max = 100.0000, границы стыкуются).
* Версия модели active.
* Все индикаторы конфигурации (как required, так и optional) имеют статус validation_status = 'valid'. Любой другой статус любого индикатора блокирует публикацию.
* Отсутствуют блокировки по лицензиям: все индикаторы имеют license_status = 'public_open' и production_allowed = true (как на уровне серии series.production_allowed, так и на уровне индикатора indicator_configs.production_allowed).
* Метаданные валидации свежие (последняя проверка не старше 30 дней).
* Исполнитель имеет роль Admin или Risk Officer.
Любое изменение published конфигурации строго создаёт новую версию.

ERROR MAPPING (TAXONOMY)

* 0 доступных индикаторов: INSUFFICIENT_DATA (HTTP 422).
* Покрытие < coverage_minimum: LOW_COVERAGE (HTTP 422).
* Отсутствует required индикатор: REQUIRED_INDICATOR_MISSING (HTTP 422).
* Источник timeout: SOURCE_TIMEOUT (HTTP 503).
* Источник rate limited: SOURCE_RATE_LIMITED (HTTP 429).
* Ошибка схемы: SOURCE_SCHEMA_MISMATCH (HTTP 502).
* Временная недоступность: TEMPORARY_UNAVAILABLE (HTTP 503).
* Источник 404/удален: STALE_MAPPING (HTTP 503).
* Источник требует лицензии: LICENSE_REQUIRED (HTTP 403).
* Лицензия не проверена: LICENSE_UNVERIFIED (HTTP 403).
* Ошибка порогов (напр. H=L): INVALID_CONFIGURATION_THRESHOLDS (HTTP 422).
* Нарушение детерминизма/XSS фраз: SCIENTIFIC_INTEGRITY_VIOLATION (HTTP 422).
* Look-ahead bias обнаружен: LOOK_AHEAD_BIAS_BLOCKED (HTTP 422).
* Потеря точности DECIMAL: DECIMAL_PRECISION_VIOLATION (HTTP 422).
* Нативный float обнаружен: FLOAT_USAGE_FORBIDDEN (HTTP 500/422).

## 6. ИНТЕРФЕЙСЫ (API, CLI, UI)

API ENDPOINTS (RESTful, JSON)

Все API требуют проверки API Key/Session и конвертации входящих дат в UTC.

* GET /api/v1/series: Роли: Все. Возвращает реестр индикаторов.
* POST /api/v1/series/{id}/validate: Роли: Admin, Risk Officer. Запускает SourceValidationStateMachine. Возвращает новый validation_status.
* GET /api/v1/configurations: Роли: Все.
* POST /api/v1/calculations/production: Роли: Admin, Risk Officer. Payload: config_version, vintage_date. Возвращает RiskScoreResult.
* GET /api/v1/situation-room: Роли: Viewer и выше. Только published production data.
* POST /api/v1/backtests: Роли: Analyst, Risk Officer. Rate limit: 5/час. Запускает job.

CLI COMMANDS

* macrorisk:schema:install: Выполняет phinx migrate. Не создаёт пользователей.
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

---

# ФАЙЛ 2. MATHEMATICAL CORE SPECIFICATION

Версия: 1.7.0-FINAL

Статус: Mathematical Source of Truth

## 1. СИСТЕМНЫЕ И КАЛИБРОВОЧНЫЕ КОНСТАНТЫ (SYSTEM CONSTANTS)

Реализация должна использовать строго именованные константы.

Базовые системные константы:

* HASH_ALGORITHM: SHA-256
* HASH_FORMAT: lowercase hexadecimal string (Все Persisted Hashes ДОЛЖНЫ храниться как lowercase hexadecimal strings).
* TIMEZONE: UTC
* SEED_ENDIANNESS: BIG_ENDIAN (Seed Integer = BIG_ENDIAN unsigned 64-bit integer, constructed from the first 8 bytes of the binary SHA-256 digest).
* SCALE: 8 (Внутренняя точность вычислений BCMath)
* STORAGE_SCALE: 4 (Точность сохранения в БД DECIMAL 10,4. Округление: Half Up).
* NORMALIZATION_EPSILON: 0.00000001 (Used exclusively as a numerical guard against division by zero and floating point equivalence issues).

Бизнес-константы (Business Rules):

* MINIMUM_COVERAGE_REQUIRED: 60.0000 (Или значение coverage_minimum из конфигурации, которое не может быть ниже этого системного минимума).
* MINIMUM_AVAILABLE_INDICATORS: 3

Калибровочные параметры (Calibration Parameters) для демографического расширения:

(Default calibration values. Country-specific calibration may override them).

* CALIBRATION_GAMMA_SCALE: 0.9543 (Компенсирует дискретность годового старения когорты)
* CALIBRATION_G9: 1.0000 (Residual internal retention effect. Default = 1.0000 означает отсутствие эффекта).
* CALIBRATION_G10: 1.0000 (Migration replenishment scale. Default = 1.0000 означает отсутствие эффекта).
* LABOUR_RETENTION_SCALE: 0.0015 (Базовая размерность остаточного удержания рынка труда)
* MIGRATION_CHILD_SHARE: 0.15 (Доля детей в международной миграции)
* MIGRATION_WORKING_SHARE: 0.80 (Доля трудоспособного возраста в миграции)
* MIGRATION_SENIOR_SHARE: 0.05 (Доля пожилых в миграции)
Инвариант: MIGRATION_CHILD_SHARE + MIGRATION_WORKING_SHARE + MIGRATION_SENIOR_SHARE = 1.0000.

## 2. МАТЕМАТИЧЕСКАЯ ТОЧНОСТЬ (DECIMAL POLICY)

Все расчёты выполняются с использованием BCMath.

Глобальное правило: реализация обязана либо вызывать bcscale(SCALE) при инициализации контекста, либо явно передавать параметр scale = SCALE в каждый вызов функций bcadd, bcsub, bcmul, bcdiv.

Любое использование функций floatval(), (float) при расчёте risk_score строго запрещено. Выбрасывается исключение FLOAT_USAGE_FORBIDDEN.

## 3. ТЕРМИНОЛОГИЯ И ОПРЕДЕЛЕНИЯ

* **available (доступный):** Наблюдение физически существует на дату винтажа (vintage_date), удовлетворяет правилу временной последовательности (release_date <= vintage_date), имеет непустое значение (NOT NULL) и прошел первичный фильтр данных.
* **eligible (пригодный для расчета):** Наблюдение одновременно удовлетворяет всем условиям:
eligible := available AND (series.validation_status = 'valid') AND series.production_allowed AND indicator_configs.production_allowed AND (series.license_status = 'public_open')

## 4. ПОРЯДОК ВЫЧИСЛЕНИЙ (ORDER OF COMPUTATION)

Детерминированный последовательный алгоритм:

1. **Normalization (Нормализация):** Рассчитываются индивидуальные нормализованные баллы normalized_indicator_score ($s_i$) для ВСЕХ доступных индикаторов конфигурации (для обеспечения полного аудита в таблице результатов).
2. **Coverage (Проверка покрытия):** Фильтруется подмножество $Eligible$. Рассчитывается coverage_ratio и проверяются минимальные пороги доступности. При провале — остановка с причиной insufficient_data или low_coverage.
3. **Effective Weights (Эффективные веса):** На подмножестве $Eligible$ вычисляются базовые ренормализованные и дисконтированные веса ($w_{i, \text{eff\_raw}}$).
4. **Rounding Reconciliation (Округление и примирение):** Веса округляются до STORAGE_SCALE и детерминированно сводятся к 100.0000%.
5. **Indicator Contribution (Вклад индикаторов):** Рассчитываются физические вклады $c_i$ на подмножестве $Eligible$.
6. **Category Score (Расчёт по категориям):** Вычисляются групповые баллы категорий и их взвешенные вклады.
7. **Risk Score (Итоговый риск):** Суммируются вклады индикаторов в итоговый показатель risk_score.
8. **Risk Band (Определение диапазона риска):** По итоговым баллам определяется итоговый диапазон риска.

## 5. ПОКРЫТИЕ (COVERAGE RATIO)

Ограничения оригинального веса индикатора: 0.0000 <= original_weight <= 100.0000.

Сумма всех original_weight в конфигурации строго равна 100.0000.

Ограничения дисконта: 0.0000 < frequency_discount <= 1.0000. Violation MUST throw INVALID_CONFIGURATION_THRESHOLDS.

Формула:

coverage_ratio = Сумма(original_weight) для всех eligible indicators.

Условия выполнения расчета risk_score:

* coverage_ratio >= coverage_minimum (которое >= MINIMUM_COVERAGE_REQUIRED)
* Количество eligible indicators >= MINIMUM_AVAILABLE_INDICATORS
* Все индикаторы с флагом is_required = true являются eligible.

Важно: frequency_discount НЕ влияет на расчёт coverage_ratio.

## 6. СТРАТЕГИИ НОРМАЛИЗАЦИИ (THRESHOLD NORMALIZATION)

Источник истины для логики нормализации — поле `direction_of_deterioration` (определяет higher_is_riskier, lower_is_riskier, distance_from_target_is_riskier, outside_band_is_riskier). Поле `normalization_method` для MVP всегда = 'threshold_linear'.

Пусть H = high_risk_threshold, L = low_risk_threshold, x = transformed_value.

(Ограничение: Неприменимо, если x есть NaN/null — такие значения фильтруются до нормализации).

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

Guard: Если M <= 0.00000000, выбрасывается INVALID_CONFIGURATION_THRESHOLDS.

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

## 7. ЭФФЕКТИВНЫЕ ВЕСА И ROUNDING RECONCILIATION

Шаги расчета (внутренний scale = 8):

* w_base_i = (original_weight_i / coverage_ratio) * 100.0000
* w_disc_i = w_base_i * frequency_discount_i
* effective_weight_i_raw = (w_disc_i / Сумма(w_disc всех eligible indicators)) * 100.0000

Rounding Reconciliation (Приведение округлений):

* Каждое effective_weight_i_raw округляется до STORAGE_SCALE (4 знака) по правилу HALF UP.
* Рассчитывается delta = 100.0000 - Сумма(округленных effective_weights).
* GUARD ПРАВИЛО: Если delta == 0.0000, no reconciliation performed.
* Если delta != 0.0000, delta детерминированно прибавляется к ОДНОМУ индикатору.
* Критерий выбора индикатора для прибавления delta:
1. Максимальный original_weight.
2. При равенстве: максимальный w_disc.
3. При равенстве: алфавитный порядок indicator_key по возрастанию.



## 8. ИТОГОВЫЕ РАСЧЕТЫ RISK SCORE И КАТЕГОРИЙ

Contribution индикатора:

contribution_i = (normalized_score_i * reconciled_effective_weight_i) / 100.0000

Итоговый риск:

risk_score = Сумма(contribution_i) для всех eligible indicators.

Если calculation_status = 'insufficient_data', risk_score = null, а calculation_hash рассчитывается от комбинации: config_hash + active_indicators_status_hash + model_version + vintage_date + calculation_status + 'INSUFFICIENT_DATA'.

Category Score (Расчет по категориям):

* Для категории С, собирается множество A_c (eligible indicators в этой категории).
* Если A_c пусто, category_score = null, category_contribution = 0.0000.
* cat_weight_i = (reconciled_effective_weight_i / Сумма(reconciled_effective_weight внутри A_c)) * 100.0000.
* category_score = Сумма(normalized_score_i * cat_weight_i) / 100.0000.
* category_contribution = category_score * Сумма(original_weight_i для i в A_c) / 100.0000.
Примечание: Category Contribution является интерпретационным декомпозиционным показателем и не обязано строго суммироваться в итоговый глобальный risk_score.

## 9. КЛАССИФИКАЦИЯ RISK BANDS И БЭКТЕСТИНГ (BACKTEST LOGIC)

Инвариант: разбиение отрезка 0.0000 до 100.0000 должно быть непрерывным, без дыр и перекрытий (very_low_min = 0.0000, severe_max = 100.0000, low_min = very_low_max и т.д.).

Интервалы полуоткрытые слева, закрытые справа (left-open, right-closed), кроме первого интервала:

* very_low: risk_score >= very_low_min AND risk_score <= very_low_max
* low: risk_score > low_min AND risk_score <= low_max
* moderate: risk_score > moderate_min AND risk_score <= moderate_max
* high: risk_score > high_min AND risk_score <= high_max
* severe: risk_score > severe_min AND risk_score <= severe_max

Backtest Detection Rule:

Эпизод считается detected, если внутри detection_window_before/after существует не менее minimum_persistence_periods расчетных точек, где:

* risk_score >= detection_threshold_score, ИЛИ
* band_rank(risk_band) >= band_rank(detection_threshold_band)
Где числовые ранги диапозонов: very_low=1, low=2, moderate=3, high=4, severe=5.
Если first_detection_date < start_date -> lead_days. Если >= -> lag_days.

Small Sample Warning:

* Вычисляется в конце прогона.
* Если sample_size_n < 10, small_sample_warning = true.
* Если sample_size_n >= 10, small_sample_warning = false.
* До завершения расчета (или при ошибке) поле имеет значение NULL.

## 10. ОПЦИОНАЛЬНОЕ РАСШИРЕНИЕ: ДЕМОГРАФИЧЕСКИЙ МЕТАБОЛИЗМ (ACMF)

Демографическое расширение является ОПЦИОНАЛЬНЫМ (OUT OF SCOPE for v1.7 schema).

Шаг времени t -> t+1 равен 1 ГОДУ. P1 = 0–14 лет (знаменатель 15), P2 = 15–64 лет (знаменатель 50), P3 = 65+ лет.

Разделение режимов:

OBSERVED MODE (Исторический анализ):

* Births(t) является внешним наблюдаемым фактом (external input).
* Deaths_k(t) является внешним наблюдаемым фактом.

SIMULATION MODE (Моделирование):

* fertility_rate(t) определяется как annual_births_per_working_age_person.
* Births(t) = fertility_rate(t) * P2(t).
* mortality_rate_k(t) определяется как annual_mortality_probability.
* Deaths_k(t) = mortality_rate_k(t) * P_k(t).

Уравнения баланса (Guard: P_k >= 0):

P1(t+1) = MAX(0, P1(t) + Births(t) - Aging12(t) - Deaths1(t) + Migration1(t))

P2(t+1) = MAX(0, P2(t) + Aging12(t) - Aging23(t) - Deaths2(t) + Migration2(t) + LabourRetention(t))

P3(t+1) = MAX(0, P3(t) + Aging23(t) - Deaths3(t) + Migration3(t))

Потоки:

Aging12(t) = CALIBRATION_GAMMA_SCALE * P1(t) / 15

Aging23(t) = CALIBRATION_GAMMA_SCALE * P2(t) / 50

Миграционный слой:

IntlOther(t) = NetInternationalMigration(t) + OtherInternationalMigration(t)

Migration1_raw(t) = MIGRATION_CHILD_SHARE * IntlOther(t) + IP_0_17(t)

Migration2_raw(t) = MIGRATION_WORKING_SHARE * IntlOther(t) + IP_18_64(t)

Migration3_raw(t) = MIGRATION_SENIOR_SHARE * IntlOther(t) + IP_65plus(t)

Migration_k(t) = CALIBRATION_G10 * Migration_k_raw(t)

LabourRetention(t) = LABOUR_RETENTION_SCALE * (CALIBRATION_G9 - 1) * P2(t)

---

# ФАЙЛ 3. DATABASE SCHEMA CONTRACT

Версия: 1.7.0-FINAL

СУБД: MySQL 8.4 LTS

Engine: InnoDB

Charset: utf8mb4

ОБЩИЕ ПРАВИЛА:

Все id: BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY.

Все timestamps: DATETIME(6). Все входящие даты должны быть нормализованы в UTC перед сохранением.

Все economic values: DECIMAL(24,8).

Все score-domain values: DECIMAL(10,4).

Политика Append-Only применяется к таблице audit_records. Данные не подлежат физическому удалению (только soft delete для сущностей).

РЕЕСТР REQUIRED INDEX MATRIX (PRODUCTION INDEXES)

* series.validation_status
* series.license_status
* series.production_allowed
* data_observations (series_id, observation_date)
* snapshot_observations (series_id, vintage_date)
* snapshot_observations (series_id, release_date)
* risk_score_results (configuration_version_id, vintage_date)
* risk_score_results (vintage_date)
* audit_records (entity_type, entity_id)
* audit_records (created_at)
* audit_records (actor_name, actor_role)
* ingestion_runs (data_source_id, started_at)
* data_snapshots (series_id, vintage_date)

ГРУППА 1: ИДЕНТИФИКАЦИЯ И ДОСТУП

ТАБЛИЦА users

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* user_key VARCHAR(64) NOT NULL UNIQUE
* email VARCHAR(255) NOT NULL UNIQUE
* password_hash VARCHAR(255) NOT NULL
* display_name VARCHAR(255) NOT NULL
* status ENUM('active', 'disabled', 'locked') NOT NULL DEFAULT 'active'
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
* status ENUM('active', 'revoked', 'expired') NOT NULL DEFAULT 'active'
* last_used_at DATETIME(6) NULL
* expires_at DATETIME(6) NULL
* created_at DATETIME(6) NOT NULL
* revoked_at DATETIME(6) NULL
* FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE

ТАБЛИЦА sessions

* id VARCHAR(128) NOT NULL PRIMARY KEY
* user_id BIGINT UNSIGNED NOT NULL
* ip_address VARCHAR(45) NULL
* user_agent TEXT NULL
* payload TEXT NOT NULL
* last_activity INT UNSIGNED NOT NULL
* FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE

ГРУППА 2: ИСТОЧНИКИ ДАННЫХ

ТАБЛИЦА data_sources

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* source_key VARCHAR(64) NOT NULL UNIQUE
* display_name VARCHAR(255) NOT NULL
* source_type VARCHAR(64) NOT NULL
* base_url VARCHAR(1024) NOT NULL
* official_documentation_url VARCHAR(1024) NULL
* terms_of_use_url VARCHAR(1024) NULL
* license_status ENUM('public_open', 'public_open_candidate', 'requires_license', 'unverified') NOT NULL DEFAULT 'unverified'
* production_allowed BOOLEAN NOT NULL DEFAULT FALSE
* notes TEXT NULL
* created_at DATETIME(6) NOT NULL
* updated_at DATETIME(6) NOT NULL

ТАБЛИЦА retry_policies

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
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
* retry_policy_id BIGINT UNSIGNED NULL
* production_allowed BOOLEAN NOT NULL DEFAULT FALSE
* validation_status ENUM('pending_validation', 'valid', 'series_mapping_stale', 'unavailable', 'access_denied', 'temporary_unavailable', 'data_pending', 'schema_mismatch', 'source_timeout', 'source_rate_limited', 'release_late', 'missing_no_historical_data') NOT NULL DEFAULT 'pending_validation'
* last_validated_at DATETIME(6) NULL
* created_at DATETIME(6) NOT NULL
* updated_at DATETIME(6) NOT NULL
* UNIQUE KEY uq_ds_endpoint (data_source_id, endpoint_key)
* FOREIGN KEY (data_source_id) REFERENCES data_sources(id) ON DELETE RESTRICT
* FOREIGN KEY (retry_policy_id) REFERENCES retry_policies(id) ON DELETE SET NULL

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
* transformation_type VARCHAR(64) NULL DEFAULT 'level'
* expected_update_lag_days INT UNSIGNED NULL
* terms_of_use_url VARCHAR(1024) NULL
* attribution_required BOOLEAN NOT NULL DEFAULT FALSE
* attribution_text TEXT NULL
* license_status ENUM('public_open', 'public_open_candidate', 'requires_license', 'unverified') NOT NULL DEFAULT 'unverified'
* production_allowed BOOLEAN NOT NULL DEFAULT FALSE
* validation_status ENUM('pending_validation', 'valid', 'series_mapping_stale', 'unavailable', 'access_denied', 'temporary_unavailable', 'data_pending', 'schema_mismatch', 'source_timeout', 'source_rate_limited', 'release_late', 'missing_no_historical_data') NOT NULL DEFAULT 'pending_validation'
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
* validation_status ENUM('pending_validation', 'valid', 'series_mapping_stale', 'unavailable', 'access_denied', 'temporary_unavailable', 'data_pending', 'schema_mismatch', 'source_timeout', 'source_rate_limited', 'release_late', 'missing_no_historical_data') NOT NULL
* validation_error_code VARCHAR(64) NULL
* http_status_code INT NULL
* endpoint_url VARCHAR(2048) NOT NULL
* response_schema_hash CHAR(64) NULL
* latest_observation_date DATE NULL
* detected_frequency VARCHAR(32) NULL
* license_status_at_validation ENUM('public_open', 'public_open_candidate', 'requires_license', 'unverified') NOT NULL
* production_allowed_at_validation BOOLEAN NOT NULL
* checked_at DATETIME(6) NOT NULL
* FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE

ТАБЛИЦА license_reviews

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* series_id BIGINT UNSIGNED NOT NULL
* review_status VARCHAR(32) NOT NULL
* license_status_result ENUM('public_open', 'public_open_candidate', 'requires_license', 'unverified') NOT NULL
* evidence_url VARCHAR(1024) NULL
* notes TEXT NULL
* expires_at DATETIME(6) NULL
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
* release_date_source VARCHAR(64) NOT NULL DEFAULT 'system_inferred'
* release_status VARCHAR(32) NOT NULL DEFAULT 'unknown'
* approved_by BIGINT UNSIGNED NULL
* approved_at DATETIME(6) NULL
* created_at DATETIME(6) NOT NULL
* updated_at DATETIME(6) NOT NULL
* UNIQUE KEY uq_release_cal (series_id, reference_period_start, reference_period_end)
* FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
* FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL

ГРУППА 4: ВИНТАЖИ, SNAPSHOTS И НАБЛЮДЕНИЯ

ТАБЛИЦА ingestion_runs

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* ingestion_run_key VARCHAR(128) NOT NULL UNIQUE
* data_source_id BIGINT UNSIGNED NOT NULL
* source_endpoint_id BIGINT UNSIGNED NULL
* series_id BIGINT UNSIGNED NULL
* created_by BIGINT UNSIGNED NULL
* job_id VARCHAR(128) NULL
* source_payload_hash CHAR(64) NULL
* records_seen INT UNSIGNED NOT NULL DEFAULT 0
* records_inserted INT UNSIGNED NOT NULL DEFAULT 0
* records_updated INT UNSIGNED NOT NULL DEFAULT 0
* records_deduplicated INT UNSIGNED NOT NULL DEFAULT 0
* started_at DATETIME(6) NOT NULL
* completed_at DATETIME(6) NULL
* status VARCHAR(32) NOT NULL DEFAULT 'running'
* error_code VARCHAR(64) NULL
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (data_source_id) REFERENCES data_sources(id) ON DELETE RESTRICT
* FOREIGN KEY (source_endpoint_id) REFERENCES source_endpoints(id) ON DELETE SET NULL
* FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE SET NULL
* FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL

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
* raw_content_hash CHAR(64) NOT NULL
* content_hash CHAR(64) NOT NULL
* created_at DATETIME(6) NOT NULL
* UNIQUE KEY uq_obs_dedup (series_id, observation_date, raw_value, raw_content_hash)
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
* snapshot_id BIGINT UNSIGNED NULL
* previous_revision_number INT UNSIGNED NULL
* new_revision_number INT UNSIGNED NULL
* previous_value DECIMAL(24,8) NULL
* new_value DECIMAL(24,8) NOT NULL
* value_changed BOOLEAN NOT NULL
* revision_detected_at DATETIME(6) NOT NULL
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
* FOREIGN KEY (observation_id) REFERENCES data_observations(id) ON DELETE CASCADE
* FOREIGN KEY (snapshot_id) REFERENCES data_snapshots(id) ON DELETE SET NULL

ТАБЛИЦА data_release_records

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* series_id BIGINT UNSIGNED NOT NULL
* snapshot_id BIGINT UNSIGNED NULL
* ingestion_run_id BIGINT UNSIGNED NULL
* release_calendar_id BIGINT UNSIGNED NULL
* reference_period_start DATE NULL
* reference_period_end DATE NULL
* release_detected_at DATETIME(6) NOT NULL
* release_status VARCHAR(32) NOT NULL
* records_seen INT UNSIGNED NOT NULL
* records_changed INT UNSIGNED NOT NULL
* is_revision BOOLEAN NOT NULL DEFAULT FALSE
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
* FOREIGN KEY (snapshot_id) REFERENCES data_snapshots(id) ON DELETE SET NULL
* FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs(id) ON DELETE SET NULL
* FOREIGN KEY (release_calendar_id) REFERENCES release_calendars(id) ON DELETE SET NULL

ГРУППА 5: КОНФИГУРАЦИИ

ТАБЛИЦА model_versions

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* model_version VARCHAR(32) NOT NULL UNIQUE
* release_date DATE NOT NULL
* formula_key VARCHAR(64) NOT NULL
* invariants_changed BOOLEAN NOT NULL DEFAULT FALSE
* backward_compatible BOOLEAN NOT NULL DEFAULT TRUE
* activated_at DATETIME(6) NULL
* status VARCHAR(32) NOT NULL DEFAULT 'draft'
* created_at DATETIME(6) NOT NULL

ТАБЛИЦА risk_configurations

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* configuration_key VARCHAR(128) NOT NULL UNIQUE
* owner_user_id BIGINT UNSIGNED NULL
* source_configuration_id BIGINT UNSIGNED NULL
* system_owned BOOLEAN NOT NULL DEFAULT FALSE
* name VARCHAR(255) NOT NULL
* description TEXT NULL
* configuration_type VARCHAR(64) NOT NULL
* lifecycle_status VARCHAR(32) NOT NULL DEFAULT 'draft'
* created_at DATETIME(6) NOT NULL
* updated_at DATETIME(6) NOT NULL
* deleted_at DATETIME(6) NULL
* FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
* FOREIGN KEY (source_configuration_id) REFERENCES risk_configurations(id) ON DELETE SET NULL

ТАБЛИЦА risk_band_threshold_sets

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* threshold_set_key VARCHAR(128) NOT NULL
* version INT UNSIGNED NOT NULL
* model_version_id BIGINT UNSIGNED NOT NULL
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
* UNIQUE KEY uq_band_ver (threshold_set_key, version)
* FOREIGN KEY (model_version_id) REFERENCES model_versions(id) ON DELETE RESTRICT

ТАБЛИЦА risk_configuration_versions

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* configuration_id BIGINT UNSIGNED NOT NULL
* version_number INT UNSIGNED NOT NULL
* version_key VARCHAR(128) NOT NULL UNIQUE
* model_version_id BIGINT UNSIGNED NOT NULL
* risk_band_threshold_set_id BIGINT UNSIGNED NULL
* status VARCHAR(32) NOT NULL DEFAULT 'draft'
* validation_status VARCHAR(32) NOT NULL DEFAULT 'pending'
* validation_error_code VARCHAR(64) NULL
* is_published BOOLEAN NOT NULL DEFAULT FALSE
* published_at DATETIME(6) NULL
* published_by BIGINT UNSIGNED NULL
* coverage_minimum DECIMAL(10,4) NOT NULL DEFAULT 60.0000
* config_hash CHAR(64) NOT NULL
* created_at DATETIME(6) NOT NULL
* UNIQUE KEY uq_config_ver (configuration_id, version_number)
* FOREIGN KEY (configuration_id) REFERENCES risk_configurations(id) ON DELETE CASCADE
* FOREIGN KEY (model_version_id) REFERENCES model_versions(id) ON DELETE RESTRICT
* FOREIGN KEY (risk_band_threshold_set_id) REFERENCES risk_band_threshold_sets(id) ON DELETE RESTRICT
* FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL

ТАБЛИЦА indicator_configs

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* configuration_version_id BIGINT UNSIGNED NOT NULL
* series_id BIGINT UNSIGNED NOT NULL
* indicator_key VARCHAR(128) NOT NULL
* category VARCHAR(64) NOT NULL
* original_weight DECIMAL(10,4) NOT NULL
* is_required BOOLEAN NOT NULL DEFAULT FALSE
* transformation_type VARCHAR(64) NOT NULL
* normalization_method VARCHAR(64) NOT NULL DEFAULT 'threshold_linear'
* direction_of_deterioration VARCHAR(64) NOT NULL
* low_risk_threshold DECIMAL(24,8) NULL
* high_risk_threshold DECIMAL(24,8) NULL
* target_value DECIMAL(24,8) NULL
* max_deviation DECIMAL(24,8) NULL
* safe_min DECIMAL(24,8) NULL
* safe_max DECIMAL(24,8) NULL
* outside_band_min_boundary DECIMAL(24,8) NULL
* outside_band_max_boundary DECIMAL(24,8) NULL
* clamp_min DECIMAL(24,8) NULL
* clamp_max DECIMAL(24,8) NULL
* frequency_discount DECIMAL(10,4) NOT NULL DEFAULT 1.0000
* production_allowed BOOLEAN NOT NULL DEFAULT TRUE
* created_at DATETIME(6) NOT NULL
* UNIQUE KEY uq_ind_conf (configuration_version_id, indicator_key)
* FOREIGN KEY (configuration_version_id) REFERENCES risk_configuration_versions(id) ON DELETE CASCADE
* FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE RESTRICT

ТАБЛИЦА risk_configuration_overrides

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* indicator_config_id BIGINT UNSIGNED NOT NULL
* override_type VARCHAR(64) NOT NULL
* overridden_field VARCHAR(128) NOT NULL
* override_value_json JSON NOT NULL
* override_reason TEXT NOT NULL
* approved_by BIGINT UNSIGNED NOT NULL
* approved_at DATETIME(6) NOT NULL
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (indicator_config_id) REFERENCES indicator_configs(id) ON DELETE CASCADE
* FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT

ГРУППА 6: РЕЗУЛЬТАТЫ И БЭКТЕСТЫ

ТАБЛИЦА risk_score_results

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* score_key VARCHAR(128) NOT NULL UNIQUE
* configuration_version_id BIGINT UNSIGNED NOT NULL
* model_version_id BIGINT UNSIGNED NOT NULL
* vintage_date DATETIME(6) NOT NULL
* calculation_mode VARCHAR(32) NOT NULL
* calculation_status VARCHAR(64) NOT NULL
* risk_score DECIMAL(10,4) NULL
* risk_band VARCHAR(32) NULL
* coverage_ratio DECIMAL(10,4) NOT NULL
* eligible_indicator_count INT UNSIGNED NOT NULL
* configured_indicator_count INT UNSIGNED NOT NULL
* required_indicator_missing BOOLEAN NOT NULL DEFAULT FALSE
* effective_weights_sum DECIMAL(10,4) NOT NULL
* calculation_hash CHAR(64) NOT NULL
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (configuration_version_id) REFERENCES risk_configuration_versions(id) ON DELETE RESTRICT
* FOREIGN KEY (model_version_id) REFERENCES model_versions(id) ON DELETE RESTRICT

ТАБЛИЦА risk_score_warnings

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* risk_score_result_id BIGINT UNSIGNED NOT NULL
* warning_level VARCHAR(32) NOT NULL DEFAULT 'warning'
* warning_code VARCHAR(64) NOT NULL
* message TEXT NOT NULL
* entity_type VARCHAR(128) NULL
* entity_id BIGINT UNSIGNED NULL
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (risk_score_result_id) REFERENCES risk_score_results(id) ON DELETE CASCADE

ТАБЛИЦА risk_score_indicator_contributions

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* risk_score_result_id BIGINT UNSIGNED NOT NULL
* indicator_config_id BIGINT UNSIGNED NOT NULL
* series_id BIGINT UNSIGNED NOT NULL
* observation_id BIGINT UNSIGNED NULL
* snapshot_observation_id BIGINT UNSIGNED NULL
* raw_value DECIMAL(24,8) NULL
* transformed_value DECIMAL(24,8) NULL
* normalized_indicator_score DECIMAL(10,4) NULL
* original_weight DECIMAL(10,4) NOT NULL
* frequency_discount DECIMAL(10,4) NOT NULL DEFAULT 1.0000
* effective_weight DECIMAL(10,4) NULL
* contribution_value DECIMAL(10,4) NULL
* is_available BOOLEAN NOT NULL
* is_required BOOLEAN NOT NULL DEFAULT FALSE
* missing_reason VARCHAR(64) NULL
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (risk_score_result_id) REFERENCES risk_score_results(id) ON DELETE CASCADE
* FOREIGN KEY (indicator_config_id) REFERENCES indicator_configs(id) ON DELETE RESTRICT
* FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE RESTRICT
* FOREIGN KEY (observation_id) REFERENCES data_observations(id) ON DELETE SET NULL
* FOREIGN KEY (snapshot_observation_id) REFERENCES snapshot_observations(id) ON DELETE SET NULL

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
* model_version_id BIGINT UNSIGNED NOT NULL
* run_status VARCHAR(32) NOT NULL DEFAULT 'running'
* false_positive_count INT UNSIGNED NULL
* small_sample_warning BOOLEAN NULL
* sample_size_n INT UNSIGNED NULL
* started_at DATETIME(6) NOT NULL
* completed_at DATETIME(6) NULL
* FOREIGN KEY (configuration_version_id) REFERENCES risk_configuration_versions(id) ON DELETE RESTRICT
* FOREIGN KEY (model_version_id) REFERENCES model_versions(id) ON DELETE RESTRICT

ТАБЛИЦА backtest_episode_results

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* backtest_run_id BIGINT UNSIGNED NOT NULL
* episode_id BIGINT UNSIGNED NOT NULL
* episode_key VARCHAR(128) NULL
* detected BOOLEAN NOT NULL
* first_detection_date DATETIME(6) NULL
* lead_days INT NULL
* lag_days INT NULL
* max_risk_score DECIMAL(10,4) NULL
* max_risk_band VARCHAR(32) NULL
* detection_window_start DATE NULL
* detection_window_end DATE NULL
* vintage_dates_tested_count INT UNSIGNED NOT NULL DEFAULT 0
* created_at DATETIME(6) NOT NULL
* FOREIGN KEY (backtest_run_id) REFERENCES backtest_runs(id) ON DELETE CASCADE
* FOREIGN KEY (episode_id) REFERENCES historical_episodes(id) ON DELETE CASCADE

ТАБЛИЦА backtest_episode_score_points

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* backtest_episode_result_id BIGINT UNSIGNED NOT NULL
* risk_score_result_id BIGINT UNSIGNED NOT NULL
* vintage_date DATETIME(6) NOT NULL
* risk_score DECIMAL(10,4) NULL
* risk_band VARCHAR(32) NULL
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
* scientific_integrity_status ENUM('pending', 'validated', 'approved', 'rejected') NOT NULL DEFAULT 'pending'
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
* configuration_version_id BIGINT UNSIGNED NOT NULL
* locale VARCHAR(16) NOT NULL
* report_status VARCHAR(32) NOT NULL
* seed_hash CHAR(64) NOT NULL
* seed_int BIGINT UNSIGNED NOT NULL
* full_text MEDIUMTEXT NULL
* scientific_integrity_status ENUM('pending', 'validated', 'approved', 'rejected') NOT NULL DEFAULT 'pending'
* published_at DATETIME(6) NULL
* published_by BIGINT UNSIGNED NULL
* generated_at DATETIME(6) NOT NULL
* FOREIGN KEY (risk_score_result_id) REFERENCES risk_score_results(id) ON DELETE RESTRICT
* FOREIGN KEY (configuration_version_id) REFERENCES risk_configuration_versions(id) ON DELETE RESTRICT
* FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL

ТАБЛИЦА narrative_report_slots

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* narrative_report_id BIGINT UNSIGNED NOT NULL
* narrative_slot_id BIGINT UNSIGNED NOT NULL
* slot_key VARCHAR(128) NOT NULL
* slot_type VARCHAR(64) NOT NULL
* slot_version_number INT UNSIGNED NOT NULL
* locale VARCHAR(16) NOT NULL
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
* error_message TEXT NULL
* metadata_json JSON NULL
* created_at DATETIME(6) NOT NULL

ТАБЛИЦА system_errors

* id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
* error_key VARCHAR(128) NOT NULL UNIQUE
* error_code VARCHAR(64) NOT NULL
* http_status_code INT NULL
* human_message TEXT NOT NULL
* machine_message TEXT NOT NULL
* remediation_hint TEXT NULL
* context_json JSON NULL
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
* old_value_json JSON NULL
* new_value_json JSON NULL
* diff_json JSON NULL
* created_at DATETIME(6) NOT NULL

---

# ФАЙЛ 4. PHILOSOPHICAL AND SCIENTIFIC INTEGRITY FOUNDATION

Версия: 1.7.0-FINAL

Статус: Philosophical Truth

## 1. ЭПИСТЕМОЛОГИЯ ОТКАЗА ОТ ИЛЛЮЗИИ ЗНАНИЯ

Главный принцип системы: Система не должна притворяться, что знает то, чего не поддерживают данные.

MacroRisk — это система детерминированной диагностики структурной устойчивости, а не механизм прогнозирования (forecasting). Риск-скор является исключительно модельной оценкой (model-derived estimate), полученной на основе конфигурации и явно заданных порогов, а не эмпирически наблюдаемым фактом экономики.

## 2. ПРИНЦИПЫ НАУЧНОЙ ЧЕСТНОСТИ В ТЕКСТАХ И ОТЧЁТАХ

Система категорически запрещает:

* Causal Claims: Заявлять причинно-следственные связи без методологического доказательства (запрет слов "caused by", "model proves").
* False Certainty: Генерировать "прогнозы" и использовать слова "гарантирует", "доказывает" или "неизбежно".
* Fake Precision: Показывать фальшивую статистическую точность на малых выборках. Бэктест с количеством исторических эпизодов менее 10 (N < 10) является исключительно диагностическим. Агрегированные метрики точности (recall, precision) для малых выборок запрещены и скрываются. Отображаются только абсолютные числа (detected count, missed count) с обязательным предупреждением.
* LLM Нарративы: Использовать Generative AI (LLM) для формирования финальных аналитических выводов в обход детерминированных текстовых слотов. Seed Hash (hex string) и Seed Integer (BIG_ENDIAN derived из первых 8 байт хеша) обеспечивают детерминированный выбор предварительно проверенных человеком текстов.

## 3. УРОК МАНИТОБЫ И ГРАНИЦЫ ИНТЕРПРЕТАЦИИ

На основе демографического анализа Манитобы система выводит важнейший методологический концепт: видимая стабильность системы не означает её внутреннее благополучие. Стабильность когорты P2 обеспечивается внешней миграцией (migration replenishment), компенсирующей падение внутреннего естественного воспроизводства.

Интерпретационные рамки (Boundaries): В отчётах запрещены публицистические или паникерские термины ("аппарат искусственного жизнеобеспечения", "крах системы"). Допустимая терминология: "The demographic extension suggests external-replenishment dependence under the configured assumptions." Модель разделяет внутренние механизмы удержания и внешние механизмы подпитки через прозрачные параметры калибровки.
