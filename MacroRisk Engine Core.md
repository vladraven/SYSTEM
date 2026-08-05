МАТРИЦА ИСПРАВЛЕНИЙ (v1.7.1 -> v1.7.2)
Устранено шесть пробелов, обнаруженных при проверке критерия "документ достаточен для воссоздания системы с нуля без домыслов":

Добавлено поле validation_error_code в source_endpoints.
Добавлен CHECK original_weight <= 100.0000 в indicator_configs.
Введён канонический список значений calculation_status (Файл 2, §8).
Определена формула calculation_hash для успешного расчёта и для каждого нестандартного статуса (Файл 2, §8).
Введён канонический список значений missing_reason (Файл 2, §8).
Полностью специфицирован алгоритм детерминированного выбора narrative-слота по seed_int, включая формулу построения входа хеша (Файл 1, §6; Файл 2, §11 — новый раздел).
Дополнительно: убраны дублирующиеся INDEX-объявления внутри risk_score_results — единственный источник истины по индексам теперь РЕЕСТР REQUIRED INDEX MATRIX в начале Файла 3, с явной инструкцией создавать все перечисленные там индексы.
ФАЙЛ 1. MASTER ARCHITECTURE AND TECHNICAL SPECIFICATION
Версия: 1.7.2-FINAL
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

Laravel (миграции, Eloquent, queues, jobs, policies, helpers).
Runtime LLM для принятия решений или генерации production-текстов.
Заброшенные Composer-пакеты.
GPL-зависимости.
Неофициальные wrappers (PyPI, GitHub) для StatCan, Bank of Canada, CMHC, CREA, OSFI.
Нативный PHP float для расчётов весов и баллов.
2. ПРАВИЛА ОБРАБОТКИ ВРЕМЕНИ И ДАННЫХ
Все входящие даты и времена должны быть нормализованы в таймзону UTC перед сохранением в базу данных.
Для хранения используется формат DATETIME(6) для обеспечения микросекундной точности, необходимой для дедупликации и предотвращения состояния гонки (race conditions).

3. ИСТОЧНИКИ ДАННЫХ И ЖИЗНЕННЫЙ ЦИКЛ
Официальные источники-кандидаты: Statistics Canada (WDS, Full Table Download), Bank of Canada (Valet API), OSFI.
Прямые источники CMHC и CREA запрещены как production default (используются эквиваленты StatCan).

SOURCE VALIDATION STATE MACHINE (ЕДИНЫЙ КАНОНИЧЕСКИЙ ENUM)
validation_status для series и series_validation_results — единый ENUM из 9 значений. Для source_endpoints — тот же набор плюс missing_no_historical_data (10 значений), поскольку у эндпоинта как технической точки интеграции отсутствие исторических данных — самостоятельный технический факт, а не свойство конкретного расчётного запроса (в отличие от серии, см. примечание ниже).
source_timeout и source_rate_limited НЕ являются значениями validation_status ни в одной из таблиц. Это значения отдельного поля validation_error_code (VARCHAR, не ENUM) — точный технический код причины перехода в temporary_unavailable. Поле validation_error_code присутствует и в series, и в source_endpoints (см. Файл 3).
Состояния:

pending_validation — начальное состояние для любой вновь зарегистрированной серии или эндпоинта.
Разрешённые переходы: → valid (успешная live-проверка), → unavailable (инфраструктурный отказ), → access_denied (проблема авторизации), → temporary_unavailable (таймаут/лимит), → schema_mismatch (расхождение схемы), → data_pending (grace period).
valid — рабочее состояние, разрешающее использование данных в production (при условии выполнения требований License Gate). Risk Officer не может установить valid вручную без проверки.
Разрешённые переходы: → temporary_unavailable (временные сетевые ошибки, HTTP 429/503), → series_mapping_stale (HTTP 404/410, удаление таблицы/вектора), → schema_mismatch (изменение формата ответа), → access_denied (отзыв/истечение credentials), → release_late (наступила ожидаемая дата релиза, данных нет).
temporary_unavailable — временная блокировка использования в расчётах из-за сетевых сбоев, таймаутов, лимитов или HTTP 500/503.
Правило записи причины: при таймауте validation_error_code = 'SOURCE_TIMEOUT'; при rate limit — validation_error_code = 'SOURCE_RATE_LIMITED'; при HTTP 500 — validation_error_code = 'HTTP_500'. Значение validation_status во всех этих случаях строго temporary_unavailable.
Разрешённые переходы: → valid (следующий успешный опрос), → series_mapping_stale или → unavailable (если временная ошибка переросла в постоянную).
schema_mismatch — HTTP 200 получен, но ожидаемые поля отсутствуют.
Разрешённые переходы: → valid (после обновления адаптера и успешной повторной проверки).
series_mapping_stale — HTTP 404/410, таблица/вектор удалены, API deprecation. Production use блокируется.
Разрешённые переходы: → valid исключительно после исправления маппинга и успешной live-проверки.
data_pending — для новых серий в grace period (схема верна, время релиза ещё не наступило). Production use запрещён.
Разрешённые переходы: → valid (успешная первая загрузка), → release_late, → temporary_unavailable.
unavailable — постоянный инфраструктурный сбой (перманентный отказ хоста, удаление сервиса без редиректа). Триггер: фиксируется после исчерпания retry_policy.
Разрешённые переходы: → valid (после исправления инфраструктуры и успешной перепроверки), → series_mapping_stale.
access_denied — HTTP 401/403 или истечение срока действия API-ключа.
Разрешённые переходы: → valid только после обновления учётных данных и успешной повторной проверки.
release_late — область применения строго ограничена уровнем серии в целом. Используется, только если задержка публикации подтверждена для текущего/последнего ожидаемого релиза серии.
Правило синхронизации: когда для конкретного периода в release_calendars.release_status устанавливается release_late, и этот период является последним ожидаемым для серии, series.validation_status автоматически переводится в release_late тем же фоновым процессом. Источник истины — таблица release_calendars; series.validation_status в этой части — производное значение, не независимое поле.
Отличие от series_mapping_stale: сам эндпоинт и маппинг живы и валидны, задерживается выпуск данных на стороне провайдера.
Разрешённые переходы: → valid (данные поступили и прошли ingestion), → series_mapping_stale (задержка сменилась удалением серии).
missing_no_historical_data (только уровень source_endpoints) — отсутствие ретроспективных данных у самого источника/эндпоинта в принципе.
Разрешённые переходы: → valid после успешной загрузки исторического ряда.
Примечание об отсутствии этого состояния у series: отсутствие исторических данных на конкретную дату винтажа НЕ является свойством серии целиком. Серия с нормальными актуальными данными не должна блокироваться в production из-за того, что конкретный backtest-запрос обратился к дате, предшествующей началу истории серии. Это свойство результата конкретного расчёта — отражается в risk_score_results.calculation_status = 'missing_no_historical_data' (полный список значений calculation_status см. Файл 2, §8), не в series.validation_status, который в этом случае остаётся valid.

LICENSE GATE STATE MACHINE (ENUM)
unverified: Начальное состояние.
public_open_candidate: Источник выглядит публичным, terms of use не проверены.
public_open: ТОЛЬКО после задокументированного review (Admin или Risk Officer).
requires_license: При обнаружении платной подписки или ограничений.
Инвариант безопасности: production_allowed = true возможно ТОЛЬКО если license_status = public_open (как на уровне series, так и на уровне data_sources). Система (или триггер) обязана блокировать или сбрасывать флаг production_allowed, если лицензия меняется на отличную от public_open.
RELEASE CALENDAR RULES
Каждая серия должна иметь записи в Release Calendar для production-использования.

expected: Дата релиза в будущем.
delayed: expected_release_date наступила, данных нет.
release_late: delay превышает tolerance.
missing: источник подтверждает отсутствие релиза (не означает stale_mapping).
revised: релиз относится к прошлому периоду.
Разрешение конфликта release_date: actual_release_date имеет абсолютный приоритет над estimated_release_date.
Если официальная дата релиза отсутствует: estimated_release_date = ingestion_date, release_date_quality = fallback_to_ingestion, release_date_source = system_inferred.
4. SNAPSHOT DEDUPLICATION И REVISION SELECTION
Все сохраняемые хеши (source_payload_hash, raw_content_hash, content_hash) ДОЛЖНЫ храниться как lowercase hexadecimal strings.
Разделение хешей и дедупликация:

source_payload_hash: hex string от сырого ответа API/CSV.
raw_content_hash: hex string от распарсенного сырого значения, даты и юнита (БЕЗ учёта номера ревизии). Используется для дедупликации в таблице data_observations (уникальный индекс uq_obs_dedup).
content_hash: hex string от значения, даты, юнита, статуса И номера ревизии. Используется для трекинга версий в snapshot_observations и data_snapshots.
Правила дедупликации:

Если source_payload_hash и content_hash совпадают с предыдущим снапшотом: is_duplicate = true. Новые data_observations не создаются.
Если revision_number изменился, но raw_content_hash тот же: создаётся data_revision_event (value_changed = false), новая data_observation НЕ создаётся.
Если значение изменилось: создаётся новая data_observation, data_revision_event (value_changed = true), новый snapshot_observations.
Выбор ревизий (Предотвращение Look-ahead bias):

Выбрать observation, где release_date <= vintage_date (с учётом правила разрешения actual vs estimated).
Tie-breaking: Выбрать максимальный revision_number. Если revision_number отсутствует/равен, выбрать минимальный observation_id.
Reproduction rule: Ранее сохранённый risk_score загружает строго связанные snapshot_observation_id, игнорируя новые данные.
5. ОПЕРАЦИОННЫЕ ГЕЙТЫ
BOOTSTRAP TIME WINDOW LOGIC
Day 0-2: Разрешено создание Admin, sources, endpoints. Запрещены ingestion и расчеты risk_score.
Day 3-7: Разрешены ingestion, validation, draft calculations. Production заблокирован.
Day 8-14+: Production разрешён ТОЛЬКО после утверждения первого production System Preset (>= 5 valid series, >= 5 public_open, >= MINIMUM_AVAILABLE_INDICATORS доступных).
Прохождение 14 дней само по себе НЕ включает production автоматически. Risk Officer не имеет права override для pending_validation, unverified, requires_license, stale_mapping, или look-ahead bias.
CONFIGURATION PUBLICATION GATE
Переход конфигурации в status = published разрешён ТОЛЬКО если одновременно соблюдены условия:

Сумма original_weight всех индикаторов = 100.0000, все веса в диапазоне [0.0000, 100.0000].
Все thresholds валидны (very_low_min = 0.0000, severe_max = 100.0000, границы стыкуются).
Версия модели active.
Все индикаторы конфигурации имеют статус validation_status = 'valid'. Любой другой статус блокирует публикацию.
Все индикаторы имеют license_status = 'public_open' и production_allowed = true (и на уровне series, и на уровне indicator_configs).
Метаданные валидации свежие (последняя проверка не старше 30 дней).
Исполнитель имеет роль Admin или Risk Officer.
Любое изменение published конфигурации строго создаёт новую версию.
ERROR MAPPING (TAXONOMY)
0 доступных индикаторов: INSUFFICIENT_DATA (HTTP 422) — calculation_status: insufficient_data.
Покрытие < coverage_minimum: LOW_COVERAGE (HTTP 422) — calculation_status: low_coverage.
Отсутствует required индикатор: REQUIRED_INDICATOR_MISSING (HTTP 422) — calculation_status: required_indicator_missing.
Источник timeout: SOURCE_TIMEOUT (HTTP 503) — validation_status: temporary_unavailable.
Источник rate limited: SOURCE_RATE_LIMITED (HTTP 429) — validation_status: temporary_unavailable.
Ошибка схемы: SOURCE_SCHEMA_MISMATCH (HTTP 502) — validation_status: schema_mismatch.
Временная недоступность (HTTP 500/503): TEMPORARY_UNAVAILABLE (HTTP 503) — validation_status: temporary_unavailable.
Источник 404/удален: STALE_MAPPING (HTTP 503) — validation_status: series_mapping_stale.
Отказ доступа/авторизации (HTTP 401/403): ACCESS_DENIED (HTTP 403) — validation_status: access_denied.
Постоянная инфраструктурная недоступность: SOURCE_UNAVAILABLE (HTTP 503) — validation_status: unavailable.
Задержка релиза сверх tolerance: RELEASE_LATE (HTTP 503) — validation_status: release_late (уровень серии).
Нет исторических данных на запрошенную vintage_date: MISSING_HISTORICAL_DATA (HTTP 422) — calculation_status: missing_no_historical_data (не изменяет series.validation_status).
Источник требует лицензии: LICENSE_REQUIRED (HTTP 403).
Лицензия не проверена: LICENSE_UNVERIFIED (HTTP 403).
Ошибка порогов: INVALID_CONFIGURATION_THRESHOLDS (HTTP 422).
Нарушение детерминизма/XSS фраз: SCIENTIFIC_INTEGRITY_VIOLATION (HTTP 422).
Look-ahead bias обнаружен: LOOK_AHEAD_BIAS_BLOCKED (HTTP 422).
Потеря точности DECIMAL: DECIMAL_PRECISION_VIOLATION (HTTP 422).
Нативный float обнаружен: FLOAT_USAGE_FORBIDDEN (HTTP 500/422).
6. ИНТЕРФЕЙСЫ (API, CLI, UI)
API ENDPOINTS (RESTful, JSON)
Все API требуют проверки API Key/Session и конвертации входящих дат в UTC.

GET /api/v1/series: Роли: Все. Возвращает реестр индикаторов.
POST /api/v1/series/{id}/validate: Роли: Admin, Risk Officer. Запускает SourceValidationStateMachine. Возвращает новый validation_status.
GET /api/v1/configurations: Роли: Все.
POST /api/v1/calculations/production: Роли: Admin, Risk Officer. Payload: config_version, vintage_date. Возвращает RiskScoreResult.
GET /api/v1/situation-room: Роли: Viewer и выше. Только published production data.
POST /api/v1/backtests: Роли: Analyst, Risk Officer. Rate limit: 5/час. Запускает job.
DETERMINISTIC NARRATIVE SLOT SELECTION (алгоритм, обязателен к реализации без отклонений)
Для каждого сгенерированного отчёта (narrative_reports):

Вход хеша. seed_hash = SHA-256(risk_score_results.calculation_hash || '|' || narrative_reports.locale || '|' || narrative_reports.report_key), где || — конкатенация UTF-8 байтовых строк без разделителя внутри самих значений (разделитель | литерален). Результат — hex string по HASH_FORMAT (Файл 2, §1). Поле narrative_reports.seed_hash хранит именно это значение.
Seed Integer. seed_int = первые 8 байт бинарного (не hex) SHA-256-дайджеста из шага 1, интерпретированные как BIG_ENDIAN unsigned 64-bit integer (см. SEED_ENDIANNESS, Файл 2, §1).
Выбор варианта слота. Для каждого слота, который должен войти в отчёт (порядок и состав слотов на отчёт определяется типом отчёта — фиксированный список типов слотов на report_status, задаётся конфигурацией report-шаблона, вне области этого документа математически, но сам механизм выбора внутри уже определённого слота — ниже):
a. Получить упорядоченный по id ASC список одобренных вариантов (narrative_slot_translations для нужного locale, где родительский narrative_slots.status = 'approved' и scientific_integrity_status = 'approved') для данного slot_key. Пусть N — количество таких вариантов.
b. Если N = 0 — ошибка SCIENTIFIC_INTEGRITY_VIOLATION (нет одобренного текста для слота/языка — отчёт не может быть сгенерирован).
c. slot_seed = первые 8 байт SHA-256(seed_hash || '|' || slot_key), интерпретированные как BIG_ENDIAN unsigned 64-bit integer (отдельный, но детерминированный производный seed на каждый слот — предотвращает совпадение выбора между разными слотами при одинаковом N).
d. selected_index = slot_seed MOD N (0-based).
e. Выбранный вариант — вариант с порядковым номером selected_index в списке из шага (a).
Все выбранные narrative_slot_id фиксируются построчно в narrative_report_slots (уже присутствует в схеме) — это и есть аудируемый след того, какой именно вариант был выбран и почему (через воспроизведение шагов 1-3 по сохранённым seed_hash/slot_key).
Это единственный разрешённый механизм выбора текста. Любое отклонение (случайный выбор без детерминированного seed, выбор по времени запроса, ручной выбор без записи в audit) — нарушение §2 Файла 4 (Fake Certainty / LLM Нарративы) и должно приводить к SCIENTIFIC_INTEGRITY_VIOLATION при попытке публикации отчёта.

CLI COMMANDS
macrorisk:schema:install: Выполняет phinx migrate. Не создаёт пользователей.
macrorisk:admin:create: Создаёт первого Admin. Разрешена только если Admin не существует. Интерактивный ввод email/password. Идемпотентна. Пишет в аудит.
macrorisk:ingest:run: Запуск загрузки. Идемпотентна. File-based locks/concurrency controls.
macrorisk:validate:sources: Массовая проверка endpoints.
macrorisk:audit:export: Выгрузка логов аудита в CSV.
UI / HTML
Server-Rendered UI через Twig или Plates. Все графики обязаны визуально разделять Observed Data (сырые данные) и Model Output (расчётные скоры). Все пользовательские вводы текста проходят HTML Sanitization.

COMPOSER LAYOUT
/src/Domain: Сущности, Value Objects (Math Decimal), Interfaces, Invariants, Constants.
/src/Application: Use Cases, State Machines, RiskEngine.
/src/Infrastructure: Репозитории (PDO/DBAL), HTTP Клиенты, Адаптеры (StatCanClient).
/src/Api: PSR-15 Middlewares, Slim Framework Controllers.
/src/Cli: Symfony Console команды.
/src/Ui: Контроллеры и шаблоны.
/tests: PHPUnit/Pest тесты.
ФАЙЛ 2. MATHEMATICAL CORE SPECIFICATION
Версия: 1.7.2-FINAL
Статус: Mathematical Source of Truth

1. СИСТЕМНЫЕ И КАЛИБРОВОЧНЫЕ КОНСТАНТЫ (SYSTEM CONSTANTS)
Базовые системные константы:

HASH_ALGORITHM: SHA-256
HASH_FORMAT: lowercase hexadecimal string (Все Persisted Hashes ДОЛЖНЫ храниться как lowercase hexadecimal strings).
TIMEZONE: UTC
SEED_ENDIANNESS: BIG_ENDIAN (Seed Integer = BIG_ENDIAN unsigned 64-bit integer, constructed from the first 8 bytes of the binary SHA-256 digest — точная процедура построения seed_int и производных slot_seed см. Файл 1, раздел DETERMINISTIC NARRATIVE SLOT SELECTION).
SCALE: 8 (Внутренняя точность вычислений BCMath)
STORAGE_SCALE: 4 (Точность сохранения в БД DECIMAL 10,4. Округление: Half Up).
NORMALIZATION_EPSILON: 0.00000001 (Used exclusively as a numerical guard against division by zero and floating point equivalence issues).
Бизнес-константы (настраиваемые политики, не эмпирически калиброванные научные параметры):

MINIMUM_COVERAGE_REQUIRED: 60.0000 (системный минимум; значение coverage_minimum из конфигурации не может быть ниже него).
MINIMUM_AVAILABLE_INDICATORS: 3
Калибровочные параметры для ОПЦИОНАЛЬНОГО демографического расширения (Out of Scope для v1.7.x ядра, см. §10 — Country-specific calibration may override defaults):

CALIBRATION_GAMMA_SCALE: 0.9543 (Компенсирует дискретность годового старения когорты)
CALIBRATION_G9: 1.0000 (Residual internal retention effect. Default = 1.0000 означает отсутствие эффекта).
CALIBRATION_G10: 1.0000 (Migration replenishment scale. Default = 1.0000 означает отсутствие эффекта).
LABOUR_RETENTION_SCALE: 0.0015 (Базовая размерность остаточного удержания рынка труда)
MIGRATION_CHILD_SHARE: 0.15 (Доля детей в международной миграции)
MIGRATION_WORKING_SHARE: 0.80 (Доля трудоспособного возраста в миграции)
MIGRATION_SENIOR_SHARE: 0.05 (Доля пожилых в миграции)
Инвариант: MIGRATION_CHILD_SHARE + MIGRATION_WORKING_SHARE + MIGRATION_SENIOR_SHARE = 1.0000.
2. МАТЕМАТИЧЕСКАЯ ТОЧНОСТЬ (DECIMAL POLICY)
Все расчёты выполняются с использованием BCMath.
Глобальное правило: реализация обязана либо вызывать bcscale(SCALE) при инициализации контекста, либо явно передавать параметр scale = SCALE в каждый вызов функций bcadd, bcsub, bcmul, bcdiv.
Любое использование функций floatval(), (float) при расчёте risk_score строго запрещено. Выбрасывается исключение FLOAT_USAGE_FORBIDDEN.

3. ТЕРМИНОЛОГИЯ И ОПРЕДЕЛЕНИЯ
available (доступный): Наблюдение физически существует на дату винтажа (vintage_date), удовлетворяет правилу временной последовательности (release_date <= vintage_date), имеет непустое значение (NOT NULL) и прошел первичный фильтр данных.
eligible (пригодный для расчета): available AND (series.validation_status = 'valid') AND series.production_allowed AND indicator_configs.production_allowed AND (series.license_status = 'public_open').
4. ПОРЯДОК ВЫЧИСЛЕНИЙ (ORDER OF COMPUTATION)
Normalization: normalized_indicator_score для ВСЕХ доступных индикаторов конфигурации (для полного аудита).
Coverage: Фильтруется подмножество Eligible. Рассчитывается coverage_ratio, проверяются минимальные пороги. При провале — остановка с соответствующим calculation_status (см. §8).
Effective Weights: На подмножестве Eligible вычисляются ренормализованные и дисконтированные веса.
Rounding Reconciliation: Веса округляются до STORAGE_SCALE и детерминированно сводятся к 100.0000%.
Indicator Contribution: Физические вклады на подмножестве Eligible.
Category Score: Групповые баллы категорий и их взвешенные вклады.
Risk Score: Сумма вкладов индикаторов.
Risk Band: Определение диапазона риска.
5. ПОКРЫТИЕ (COVERAGE RATIO)
Ограничения оригинального веса: 0.0000 <= original_weight <= 100.0000 (оба предела ОБЯЗАНЫ быть защищены на уровне БД CHECK-constraint'ами, см. Файл 3, indicator_configs).
Сумма всех original_weight в конфигурации строго равна 100.0000.
Ограничения дисконта: 0.0000 < frequency_discount <= 1.0000. Violation MUST throw INVALID_CONFIGURATION_THRESHOLDS.
Формула: coverage_ratio = Сумма(original_weight) для всех eligible indicators.
Условия выполнения расчета risk_score:

coverage_ratio >= coverage_minimum (которое >= MINIMUM_COVERAGE_REQUIRED)
Количество eligible indicators >= MINIMUM_AVAILABLE_INDICATORS
Все индикаторы с флагом is_required = true являются eligible.
Важно: frequency_discount НЕ влияет на расчёт coverage_ratio.

6. СТРАТЕГИИ НОРМАЛИЗАЦИИ (THRESHOLD NORMALIZATION)
Источник истины — поле direction_of_deterioration. normalization_method для MVP всегда = 'threshold_linear'.
Пусть H = high_risk_threshold, L = low_risk_threshold, x = transformed_value.
Guard: Если |H - L| < NORMALIZATION_EPSILON:

Если x на "безопасной" стороне (x <= L для higher_is_riskier, x >= L для lower_is_riskier): score = 0.0000
Иначе: score = 100.0000
higher_is_riskier (H > L):

x <= L: score = 0.0000
x >= H: score = 100.0000
Иначе: score = ((x - L) / (H - L)) * 100.0000
lower_is_riskier (H < L):

x >= L: score = 0.0000
x <= H: score = 100.0000
Иначе: score = ((L - x) / (L - H)) * 100.0000
distance_from_target_is_riskier: T = target_value, M = max_deviation.
Guard: M <= 0.00000000 -> INVALID_CONFIGURATION_THRESHOLDS.
score = MIN(100.0000, (|x - T| / M) * 100.0000)
outside_band_is_riskier: safe_min, safe_max, outside_band_min_boundary, outside_band_max_boundary.
Guard: outside_band_min_boundary < safe_min < safe_max < outside_band_max_boundary.

safe_min <= x <= safe_max: score = 0.0000
x <= outside_band_min_boundary ИЛИ x >= outside_band_max_boundary: score = 100.0000
Левая интерполяция: score = 100.0000 * (safe_min - x) / (safe_min - outside_band_min_boundary)
Правая интерполяция: score = 100.0000 * (x - safe_max) / (outside_band_max_boundary - safe_max)
7. ЭФФЕКТИВНЫЕ ВЕСА И ROUNDING RECONCILIATION
Шаги (внутренний scale = 8):

w_base_i = (original_weight_i / coverage_ratio) * 100.0000
w_disc_i = w_base_i * frequency_discount_i
effective_weight_i_raw = (w_disc_i / Сумма(w_disc всех eligible indicators)) * 100.0000
Rounding Reconciliation:

Каждое effective_weight_i_raw округляется до STORAGE_SCALE по правилу HALF UP.
delta = 100.0000 - Сумма(округленных effective_weights).
GUARD: Если delta == 0.0000, no reconciliation performed.
Если delta != 0.0000, delta детерминированно прибавляется к ОДНОМУ индикатору.
Критерий выбора: 1) максимальный original_weight; 2) при равенстве — максимальный w_disc; 3) при равенстве — алфавитный порядок indicator_key по возрастанию.
8. ИТОГОВЫЕ РАСЧЕТЫ RISK SCORE, СТАТУСЫ И ХЕШИ
Contribution: contribution_i = (normalized_score_i * reconciled_effective_weight_i) / 100.0000
Risk score: risk_score = Сумма(contribution_i) для всех eligible indicators.

Канонический список значений calculation_status
Ровно шесть допустимых значений, ни одно другое не допускается:

ok — расчёт успешно завершён, risk_score и risk_band заполнены.
insufficient_data — 0 eligible indicators.
low_coverage — coverage_ratio < coverage_minimum.
required_indicator_missing — хотя бы один is_required индикатор не eligible.
missing_no_historical_data — для запрошенной vintage_date у иначе валидной серии нет ни одного наблюдения на или до этой даты (см. Файл 1, §3).
insufficient_data, low_coverage, required_indicator_missing, missing_no_historical_data — во всех этих четырёх случаях risk_score = null, risk_band = null.
Формула calculation_hash (для КАЖДОГО значения calculation_status)
Общий вид: calculation_hash = SHA-256(config_hash || '|' || active_indicators_status_hash || '|' || model_version || '|' || vintage_date_iso8601 || '|' || calculation_status || '|' || status_payload), где:

active_indicators_status_hash — SHA-256 от конкатенации indicator_key:validation_status:license_status:production_allowed, отсортированной по indicator_key ASC, для всех индикаторов конфигурации (не только eligible — для полной прослеживаемости состояния на момент расчёта).
status_payload зависит от calculation_status:ok: status_payload = risk_score (STORAGE_SCALE, как строка) || '|' || risk_band.
insufficient_data: status_payload = 'INSUFFICIENT_DATA'.
low_coverage: status_payload = 'LOW_COVERAGE:' || coverage_ratio (как строка).
required_indicator_missing: status_payload = 'REQUIRED_INDICATOR_MISSING:' || отсортированный по возрастанию список indicator_key отсутствующих required-индикаторов через запятую.
missing_no_historical_data: status_payload = 'MISSING_NO_HISTORICAL_DATA'.
Результат хешируется как единая строка через SHA-256, hex-представление сохраняется в calculation_hash (HASH_FORMAT).

Канонический список значений missing_reason (risk_score_indicator_contributions)
Ровно пять допустимых значений:

not_available — индикатор не available (Файл 2, §3) на данный vintage_date.
validation_invalid — series.validation_status != 'valid'.
production_blocked — series.production_allowed = false ИЛИ indicator_configs.production_allowed = false.
license_blocked — series.license_status != 'public_open'.
null (SQL NULL, не строка) — индикатор eligible, значение отсутствия неприменимо (indicator присутствовал в расчёте).
Если индикатор недоступен по нескольким причинам одновременно, применяется приоритет сверху вниз (первая подходящая причина из списка выше записывается в missing_reason).
Category Score:

Для категории С — множество A_c (eligible indicators в категории).
Если A_c пусто: category_score = null, category_contribution = 0.0000.
cat_weight_i = (reconciled_effective_weight_i / Сумма(reconciled_effective_weight внутри A_c)) * 100.0000.
category_score = Сумма(normalized_score_i * cat_weight_i) / 100.0000.
category_contribution = category_score * Сумма(original_weight_i для i в A_c) / 100.0000.
Примечание: Category Contribution — интерпретационный декомпозиционный показатель, сумма category_contribution не обязана строго равняться risk_score.
9. КЛАССИФИКАЦИЯ RISK BANDS И БЭКТЕСТИНГ (BACKTEST LOGIC)
Инвариант: разбиение 0.0000-100.0000 непрерывно, без дыр и перекрытий (very_low_min = 0.0000, severe_max = 100.0000, low_min = very_low_max и т.д.).
Интервалы left-open, right-closed, кроме первого:

very_low: risk_score >= very_low_min AND risk_score <= very_low_max
low: risk_score > low_min AND risk_score <= low_max
moderate: risk_score > moderate_min AND risk_score <= moderate_max
high: risk_score > high_min AND risk_score <= high_max
severe: risk_score > severe_min AND risk_score <= severe_max
Backtest Detection Rule: эпизод detected, если внутри detection_window_before/after существует не менее minimum_persistence_periods точек, где risk_score >= detection_threshold_score ИЛИ band_rank(risk_band) >= band_rank(detection_threshold_band). Ранги: very_low=1, low=2, moderate=3, high=4, severe=5. first_detection_date < start_date -> lead_days, иначе -> lag_days.
Расчётные точки со calculation_status = 'missing_no_historical_data' исключаются из знаменателя detection-статистики эпизода (учитываются отдельно как "не оценено", не как detected и не как missed).
Small Sample Warning: вычисляется в конце прогона. sample_size_n < 10 -> small_sample_warning = true; >= 10 -> false; до завершения расчёта — NULL.

10. ОПЦИОНАЛЬНОЕ РАСШИРЕНИЕ: ДЕМОГРАФИЧЕСКИЙ МЕТАБОЛИЗМ (ACMF)
Демографическое расширение является ОПЦИОНАЛЬНЫМ (OUT OF SCOPE for v1.7.x schema).
Шаг времени t -> t+1 равен 1 ГОДУ. P1 = 0-14 лет (знаменатель 15), P2 = 15-64 лет (знаменатель 50), P3 = 65+ лет.
OBSERVED MODE: Births(t) и Deaths_k(t) — внешние наблюдаемые факты.
SIMULATION MODE:

fertility_rate(t) = annual_births_per_working_age_person; Births(t) = fertility_rate(t) * P2(t).
mortality_rate_k(t) = annual_mortality_probability; Deaths_k(t) = mortality_rate_k(t) * P_k(t).
Уравнения баланса (Guard: P_k >= 0):
P1(t+1) = MAX(0, P1(t) + Births(t) - Aging12(t) - Deaths1(t) + Migration1(t))
P2(t+1) = MAX(0, P2(t) + Aging12(t) - Aging23(t) - Deaths2(t) + Migration2(t) + LabourRetention(t))
P3(t+1) = MAX(0, P3(t) + Aging23(t) - Deaths3(t) + Migration3(t))
Aging12(t) = CALIBRATION_GAMMA_SCALE * P1(t) / 15
Aging23(t) = CALIBRATION_GAMMA_SCALE * P2(t) / 50
IntlOther(t) = NetInternationalMigration(t) + OtherInternationalMigration(t)
Migration1_raw(t) = MIGRATION_CHILD_SHARE * IntlOther(t) + IP_0_17(t)
Migration2_raw(t) = MIGRATION_WORKING_SHARE * IntlOther(t) + IP_18_64(t)
Migration3_raw(t) = MIGRATION_SENIOR_SHARE * IntlOther(t) + IP_65plus(t)
Migration_k(t) = CALIBRATION_G10 * Migration_k_raw(t)
LabourRetention(t) = LABOUR_RETENTION_SCALE * (CALIBRATION_G9 - 1) * P2(t)
ФАЙЛ 3. DATABASE SCHEMA CONTRACT
Версия: 1.7.2-FINAL
СУБД: MySQL 8.4 LTS
Engine: InnoDB
Charset: utf8mb4
ОБЩИЕ ПРАВИЛА:
Все id: BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY.
Все timestamps: DATETIME(6). Все входящие даты нормализованы в UTC перед сохранением.
Все economic values: DECIMAL(24,8). Все score-domain values: DECIMAL(10,4).
Политика Append-Only применяется к таблице audit_records. Данные не подлежат физическому удалению (только soft delete для сущностей).

РЕЕСТР REQUIRED INDEX MATRIX (PRODUCTION INDEXES) — единственный источник истины по индексам
Каждый из перечисленных ниже индексов ОБЯЗАН быть создан в соответствующей таблице в дополнение к PRIMARY/UNIQUE/FOREIGN KEY, объявленным в описании таблицы. Отдельных дублирующих объявлений внутри описаний таблиц ниже намеренно нет — расхождение между двумя источниками было бы само по себе источником ошибок.

series (validation_status)
series (license_status)
series (production_allowed)
data_observations (series_id, observation_date)
snapshot_observations (series_id, vintage_date)
snapshot_observations (series_id, release_date)
risk_score_results (configuration_version_id, vintage_date)
risk_score_results (vintage_date)
audit_records (entity_type, entity_id)
audit_records (created_at)
audit_records (actor_name, actor_role)
ingestion_runs (data_source_id, started_at)
data_snapshots (series_id, vintage_date)
ГРУППА 1: ИДЕНТИФИКАЦИЯ И ДОСТУП
ТАБЛИЦА users

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
user_key VARCHAR(64) NOT NULL UNIQUE
email VARCHAR(255) NOT NULL UNIQUE
password_hash VARCHAR(255) NOT NULL
display_name VARCHAR(255) NOT NULL
status ENUM('active', 'disabled', 'locked') NOT NULL DEFAULT 'active'
last_login_at DATETIME(6) NULL
created_at DATETIME(6) NOT NULL
updated_at DATETIME(6) NOT NULL
deleted_at DATETIME(6) NULL
ТАБЛИЦА roles

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
role_key VARCHAR(64) NOT NULL UNIQUE
display_name VARCHAR(255) NOT NULL
description TEXT NULL
created_at DATETIME(6) NOT NULL
ТАБЛИЦА user_roles

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
user_id BIGINT UNSIGNED NOT NULL
role_id BIGINT UNSIGNED NOT NULL
assigned_by BIGINT UNSIGNED NULL
assigned_at DATETIME(6) NOT NULL
UNIQUE KEY uq_user_role (user_id, role_id)
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
ТАБЛИЦА api_keys

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
user_id BIGINT UNSIGNED NOT NULL
key_prefix VARCHAR(32) NOT NULL UNIQUE
key_hash CHAR(64) NOT NULL
name VARCHAR(255) NOT NULL
status ENUM('active', 'revoked', 'expired') NOT NULL DEFAULT 'active'
last_used_at DATETIME(6) NULL
expires_at DATETIME(6) NULL
created_at DATETIME(6) NOT NULL
revoked_at DATETIME(6) NULL
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
ТАБЛИЦА sessions

id VARCHAR(128) NOT NULL PRIMARY KEY
user_id BIGINT UNSIGNED NOT NULL
ip_address VARCHAR(45) NULL
user_agent TEXT NULL
payload TEXT NOT NULL
last_activity INT UNSIGNED NOT NULL
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
ГРУППА 2: ИСТОЧНИКИ ДАННЫХ
ТАБЛИЦА data_sources

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
source_key VARCHAR(64) NOT NULL UNIQUE
display_name VARCHAR(255) NOT NULL
source_type VARCHAR(64) NOT NULL
base_url VARCHAR(1024) NOT NULL
official_documentation_url VARCHAR(1024) NULL
terms_of_use_url VARCHAR(1024) NULL
license_status ENUM('public_open', 'public_open_candidate', 'requires_license', 'unverified') NOT NULL DEFAULT 'unverified'
production_allowed BOOLEAN NOT NULL DEFAULT FALSE
notes TEXT NULL
created_at DATETIME(6) NOT NULL
updated_at DATETIME(6) NOT NULL
ТАБЛИЦА retry_policies

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
policy_key VARCHAR(64) NOT NULL UNIQUE
max_retries INT UNSIGNED NOT NULL DEFAULT 3
backoff_multiplier DECIMAL(10,4) NOT NULL DEFAULT 2.0000
created_at DATETIME(6) NOT NULL
ТАБЛИЦА source_endpoints

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
data_source_id BIGINT UNSIGNED NOT NULL
endpoint_key VARCHAR(128) NOT NULL
endpoint_url VARCHAR(2048) NOT NULL
method VARCHAR(16) NOT NULL DEFAULT 'GET'
response_format VARCHAR(32) NOT NULL
requires_auth BOOLEAN NOT NULL DEFAULT FALSE
rate_limit_per_minute INT UNSIGNED NULL
timeout_seconds INT UNSIGNED NOT NULL DEFAULT 30
retry_policy_id BIGINT UNSIGNED NULL
production_allowed BOOLEAN NOT NULL DEFAULT FALSE
validation_status ENUM('pending_validation', 'valid', 'series_mapping_stale', 'unavailable', 'access_denied', 'temporary_unavailable', 'data_pending', 'schema_mismatch', 'release_late', 'missing_no_historical_data') NOT NULL DEFAULT 'pending_validation'
validation_error_code VARCHAR(64) NULL
last_validated_at DATETIME(6) NULL
created_at DATETIME(6) NOT NULL
updated_at DATETIME(6) NOT NULL
UNIQUE KEY uq_ds_endpoint (data_source_id, endpoint_key)
FOREIGN KEY (data_source_id) REFERENCES data_sources(id) ON DELETE RESTRICT
FOREIGN KEY (retry_policy_id) REFERENCES retry_policies(id) ON DELETE SET NULL
ГРУППА 3: ИНДИКАТОРЫ И КАЛЕНДАРЬ
ТАБЛИЦА series

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
series_key VARCHAR(128) NOT NULL UNIQUE
display_name VARCHAR(255) NOT NULL
data_source_id BIGINT UNSIGNED NOT NULL
source_endpoint_id BIGINT UNSIGNED NULL
source_provider VARCHAR(128) NOT NULL
underlying_origin VARCHAR(128) NULL
external_series_identifier VARCHAR(255) NULL
table_id VARCHAR(64) NULL
vector_id VARCHAR(64) NULL
country VARCHAR(16) NOT NULL DEFAULT 'CA'
category VARCHAR(64) NOT NULL
frequency VARCHAR(32) NOT NULL
unit VARCHAR(64) NOT NULL
transformation_type VARCHAR(64) NULL DEFAULT 'level'
expected_update_lag_days INT UNSIGNED NULL
terms_of_use_url VARCHAR(1024) NULL
attribution_required BOOLEAN NOT NULL DEFAULT FALSE
attribution_text TEXT NULL
license_status ENUM('public_open', 'public_open_candidate', 'requires_license', 'unverified') NOT NULL DEFAULT 'unverified'
production_allowed BOOLEAN NOT NULL DEFAULT FALSE
validation_status ENUM('pending_validation', 'valid', 'series_mapping_stale', 'unavailable', 'access_denied', 'temporary_unavailable', 'data_pending', 'schema_mismatch', 'release_late') NOT NULL DEFAULT 'pending_validation'
validation_error_code VARCHAR(64) NULL
validation_checked_at DATETIME(6) NULL
created_at DATETIME(6) NOT NULL
updated_at DATETIME(6) NOT NULL
deleted_at DATETIME(6) NULL
FOREIGN KEY (data_source_id) REFERENCES data_sources(id) ON DELETE RESTRICT
FOREIGN KEY (source_endpoint_id) REFERENCES source_endpoints(id) ON DELETE SET NULL
ТАБЛИЦА series_validation_results

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
series_id BIGINT UNSIGNED NOT NULL
validation_run_key VARCHAR(128) NOT NULL
validation_status ENUM('pending_validation', 'valid', 'series_mapping_stale', 'unavailable', 'access_denied', 'temporary_unavailable', 'data_pending', 'schema_mismatch', 'release_late') NOT NULL
validation_error_code VARCHAR(64) NULL
http_status_code INT NULL
endpoint_url VARCHAR(2048) NOT NULL
response_schema_hash CHAR(64) NULL
latest_observation_date DATE NULL
detected_frequency VARCHAR(32) NULL
license_status_at_validation ENUM('public_open', 'public_open_candidate', 'requires_license', 'unverified') NOT NULL
production_allowed_at_validation BOOLEAN NOT NULL
checked_at DATETIME(6) NOT NULL
FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
ТАБЛИЦА license_reviews

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
series_id BIGINT UNSIGNED NOT NULL
review_status VARCHAR(32) NOT NULL
license_status_result ENUM('public_open', 'public_open_candidate', 'requires_license', 'unverified') NOT NULL
evidence_url VARCHAR(1024) NULL
notes TEXT NULL
expires_at DATETIME(6) NULL
reviewed_by BIGINT UNSIGNED NOT NULL
reviewed_at DATETIME(6) NOT NULL
FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT
ТАБЛИЦА release_calendars

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
series_id BIGINT UNSIGNED NOT NULL
reference_period_start DATE NOT NULL
reference_period_end DATE NOT NULL
expected_release_date DATETIME(6) NULL
actual_release_date DATETIME(6) NULL
estimated_release_date DATETIME(6) NULL
release_date_quality VARCHAR(64) NOT NULL DEFAULT 'unknown'
release_date_source VARCHAR(64) NOT NULL DEFAULT 'system_inferred'
release_status VARCHAR(32) NOT NULL DEFAULT 'unknown'
approved_by BIGINT UNSIGNED NULL
approved_at DATETIME(6) NULL
created_at DATETIME(6) NOT NULL
updated_at DATETIME(6) NOT NULL
UNIQUE KEY uq_release_cal (series_id, reference_period_start, reference_period_end)
FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
ГРУППА 4: ВИНТАЖИ, SNAPSHOTS И НАБЛЮДЕНИЯ
ТАБЛИЦА ingestion_runs

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
ingestion_run_key VARCHAR(128) NOT NULL UNIQUE
data_source_id BIGINT UNSIGNED NOT NULL
source_endpoint_id BIGINT UNSIGNED NULL
series_id BIGINT UNSIGNED NULL
created_by BIGINT UNSIGNED NULL
job_id VARCHAR(128) NULL
source_payload_hash CHAR(64) NULL
records_seen INT UNSIGNED NOT NULL DEFAULT 0
records_inserted INT UNSIGNED NOT NULL DEFAULT 0
records_updated INT UNSIGNED NOT NULL DEFAULT 0
records_deduplicated INT UNSIGNED NOT NULL DEFAULT 0
started_at DATETIME(6) NOT NULL
completed_at DATETIME(6) NULL
status VARCHAR(32) NOT NULL DEFAULT 'running'
error_code VARCHAR(64) NULL
created_at DATETIME(6) NOT NULL
FOREIGN KEY (data_source_id) REFERENCES data_sources(id) ON DELETE RESTRICT
FOREIGN KEY (source_endpoint_id) REFERENCES source_endpoints(id) ON DELETE SET NULL
FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE SET NULL
FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
ТАБЛИЦА data_snapshots

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
snapshot_key VARCHAR(128) NOT NULL UNIQUE
ingestion_run_id BIGINT UNSIGNED NOT NULL
series_id BIGINT UNSIGNED NOT NULL
snapshot_timestamp DATETIME(6) NOT NULL
vintage_date DATETIME(6) NOT NULL
source_payload_hash CHAR(64) NOT NULL
content_hash CHAR(64) NOT NULL
is_duplicate BOOLEAN NOT NULL DEFAULT FALSE
created_at DATETIME(6) NOT NULL
FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs(id) ON DELETE RESTRICT
FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE RESTRICT
ТАБЛИЦА data_observations

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
series_id BIGINT UNSIGNED NOT NULL
observation_date DATE NOT NULL
frequency_at_observation VARCHAR(32) NOT NULL
raw_value DECIMAL(24,8) NOT NULL
unit VARCHAR(64) NOT NULL
value_status VARCHAR(32) NOT NULL DEFAULT 'normal'
raw_content_hash CHAR(64) NOT NULL
content_hash CHAR(64) NOT NULL
created_at DATETIME(6) NOT NULL
UNIQUE KEY uq_obs_dedup (series_id, observation_date, raw_value, raw_content_hash)
FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE RESTRICT
ТАБЛИЦА snapshot_observations

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
snapshot_id BIGINT UNSIGNED NOT NULL
series_id BIGINT UNSIGNED NOT NULL
observation_id BIGINT UNSIGNED NOT NULL
vintage_date DATETIME(6) NOT NULL
release_date DATETIME(6) NULL
estimated_release_date DATETIME(6) NULL
release_date_quality VARCHAR(64) NOT NULL
reproducibility_allowed BOOLEAN NOT NULL DEFAULT TRUE
is_revision BOOLEAN NOT NULL DEFAULT FALSE
revision_number INT UNSIGNED NULL
created_at DATETIME(6) NOT NULL
UNIQUE KEY uq_snap_obs (snapshot_id, series_id, observation_id)
FOREIGN KEY (snapshot_id) REFERENCES data_snapshots(id) ON DELETE CASCADE
FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
FOREIGN KEY (observation_id) REFERENCES data_observations(id) ON DELETE CASCADE
ТАБЛИЦА data_revision_events

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
series_id BIGINT UNSIGNED NOT NULL
observation_id BIGINT UNSIGNED NOT NULL
snapshot_id BIGINT UNSIGNED NULL
previous_revision_number INT UNSIGNED NULL
new_revision_number INT UNSIGNED NULL
previous_value DECIMAL(24,8) NULL
new_value DECIMAL(24,8) NOT NULL
value_changed BOOLEAN NOT NULL
revision_detected_at DATETIME(6) NOT NULL
created_at DATETIME(6) NOT NULL
FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
FOREIGN KEY (observation_id) REFERENCES data_observations(id) ON DELETE CASCADE
FOREIGN KEY (snapshot_id) REFERENCES data_snapshots(id) ON DELETE SET NULL
ТАБЛИЦА data_release_records

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
series_id BIGINT UNSIGNED NOT NULL
snapshot_id BIGINT UNSIGNED NULL
ingestion_run_id BIGINT UNSIGNED NULL
release_calendar_id BIGINT UNSIGNED NULL
reference_period_start DATE NULL
reference_period_end DATE NULL
release_detected_at DATETIME(6) NOT NULL
release_status VARCHAR(32) NOT NULL
records_seen INT UNSIGNED NOT NULL
records_changed INT UNSIGNED NOT NULL
is_revision BOOLEAN NOT NULL DEFAULT FALSE
created_at DATETIME(6) NOT NULL
FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
FOREIGN KEY (snapshot_id) REFERENCES data_snapshots(id) ON DELETE SET NULL
FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs(id) ON DELETE SET NULL
FOREIGN KEY (release_calendar_id) REFERENCES release_calendars(id) ON DELETE SET NULL
ГРУППА 5: КОНФИГУРАЦИИ
ТАБЛИЦА model_versions

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
model_version VARCHAR(32) NOT NULL UNIQUE
release_date DATE NOT NULL
formula_key VARCHAR(64) NOT NULL
invariants_changed BOOLEAN NOT NULL DEFAULT FALSE
backward_compatible BOOLEAN NOT NULL DEFAULT TRUE
activated_at DATETIME(6) NULL
status VARCHAR(32) NOT NULL DEFAULT 'draft'
created_at DATETIME(6) NOT NULL
ТАБЛИЦА risk_configurations

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
configuration_key VARCHAR(128) NOT NULL UNIQUE
owner_user_id BIGINT UNSIGNED NULL
source_configuration_id BIGINT UNSIGNED NULL
system_owned BOOLEAN NOT NULL DEFAULT FALSE
name VARCHAR(255) NOT NULL
description TEXT NULL
configuration_type VARCHAR(64) NOT NULL
lifecycle_status VARCHAR(32) NOT NULL DEFAULT 'draft'
created_at DATETIME(6) NOT NULL
updated_at DATETIME(6) NOT NULL
deleted_at DATETIME(6) NULL
FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
FOREIGN KEY (source_configuration_id) REFERENCES risk_configurations(id) ON DELETE SET NULL
ТАБЛИЦА risk_band_threshold_sets

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
threshold_set_key VARCHAR(128) NOT NULL
version INT UNSIGNED NOT NULL
model_version_id BIGINT UNSIGNED NOT NULL
very_low_min DECIMAL(10,4) NOT NULL
very_low_max DECIMAL(10,4) NOT NULL
low_min DECIMAL(10,4) NOT NULL
low_max DECIMAL(10,4) NOT NULL
moderate_min DECIMAL(10,4) NOT NULL
moderate_max DECIMAL(10,4) NOT NULL
high_min DECIMAL(10,4) NOT NULL
high_max DECIMAL(10,4) NOT NULL
severe_min DECIMAL(10,4) NOT NULL
severe_max DECIMAL(10,4) NOT NULL
created_at DATETIME(6) NOT NULL
UNIQUE KEY uq_band_ver (threshold_set_key, version)
FOREIGN KEY (model_version_id) REFERENCES model_versions(id) ON DELETE RESTRICT
ТАБЛИЦА risk_configuration_versions

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
configuration_id BIGINT UNSIGNED NOT NULL
version_number INT UNSIGNED NOT NULL
version_key VARCHAR(128) NOT NULL UNIQUE
model_version_id BIGINT UNSIGNED NOT NULL
risk_band_threshold_set_id BIGINT UNSIGNED NULL
status VARCHAR(32) NOT NULL DEFAULT 'draft'
validation_status VARCHAR(32) NOT NULL DEFAULT 'pending'
validation_error_code VARCHAR(64) NULL
is_published BOOLEAN NOT NULL DEFAULT FALSE
published_at DATETIME(6) NULL
published_by BIGINT UNSIGNED NULL
coverage_minimum DECIMAL(10,4) NOT NULL DEFAULT 60.0000
config_hash CHAR(64) NOT NULL
created_at DATETIME(6) NOT NULL
UNIQUE KEY uq_config_ver (configuration_id, version_number)
FOREIGN KEY (configuration_id) REFERENCES risk_configurations(id) ON DELETE CASCADE
FOREIGN KEY (model_version_id) REFERENCES model_versions(id) ON DELETE RESTRICT
FOREIGN KEY (risk_band_threshold_set_id) REFERENCES risk_band_threshold_sets(id) ON DELETE RESTRICT
FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
ТАБЛИЦА indicator_configs

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
configuration_version_id BIGINT UNSIGNED NOT NULL
series_id BIGINT UNSIGNED NOT NULL
indicator_key VARCHAR(128) NOT NULL
category VARCHAR(64) NOT NULL
original_weight DECIMAL(10,4) NOT NULL
is_required BOOLEAN NOT NULL DEFAULT FALSE
transformation_type VARCHAR(64) NOT NULL
normalization_method VARCHAR(64) NOT NULL DEFAULT 'threshold_linear'
direction_of_deterioration VARCHAR(64) NOT NULL
low_risk_threshold DECIMAL(24,8) NULL
high_risk_threshold DECIMAL(24,8) NULL
target_value DECIMAL(24,8) NULL
max_deviation DECIMAL(24,8) NULL
safe_min DECIMAL(24,8) NULL
safe_max DECIMAL(24,8) NULL
outside_band_min_boundary DECIMAL(24,8) NULL
outside_band_max_boundary DECIMAL(24,8) NULL
clamp_min DECIMAL(24,8) NULL
clamp_max DECIMAL(24,8) NULL
frequency_discount DECIMAL(10,4) NOT NULL DEFAULT 1.0000
production_allowed BOOLEAN NOT NULL DEFAULT TRUE
created_at DATETIME(6) NOT NULL
UNIQUE KEY uq_ind_conf (configuration_version_id, indicator_key)
CONSTRAINT chk_orig_weight CHECK (original_weight >= 0.0000 AND original_weight <= 100.0000)
CONSTRAINT chk_freq_discount CHECK (frequency_discount > 0.0000 AND frequency_discount <= 1.0000)
FOREIGN KEY (configuration_version_id) REFERENCES risk_configuration_versions(id) ON DELETE CASCADE
FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE RESTRICT
ТАБЛИЦА risk_configuration_overrides

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
indicator_config_id BIGINT UNSIGNED NOT NULL
override_type VARCHAR(64) NOT NULL
overridden_field VARCHAR(128) NOT NULL
override_value_json JSON NOT NULL
override_reason TEXT NOT NULL
approved_by BIGINT UNSIGNED NOT NULL
approved_at DATETIME(6) NOT NULL
created_at DATETIME(6) NOT NULL
FOREIGN KEY (indicator_config_id) REFERENCES indicator_configs(id) ON DELETE CASCADE
FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT
ГРУППА 6: РЕЗУЛЬТАТЫ И БЭКТЕСТЫ
ТАБЛИЦА risk_score_results

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
score_key VARCHAR(128) NOT NULL UNIQUE
configuration_version_id BIGINT UNSIGNED NOT NULL
model_version_id BIGINT UNSIGNED NOT NULL
vintage_date DATETIME(6) NOT NULL
calculation_mode VARCHAR(32) NOT NULL
calculation_status ENUM('ok', 'insufficient_data', 'low_coverage', 'required_indicator_missing', 'missing_no_historical_data') NOT NULL
risk_score DECIMAL(10,4) NULL
risk_band VARCHAR(32) NULL
coverage_ratio DECIMAL(10,4) NOT NULL
eligible_indicator_count INT UNSIGNED NOT NULL
configured_indicator_count INT UNSIGNED NOT NULL
required_indicator_missing BOOLEAN NOT NULL DEFAULT FALSE
effective_weights_sum DECIMAL(10,4) NOT NULL
calculation_hash CHAR(64) NOT NULL
created_at DATETIME(6) NOT NULL
FOREIGN KEY (configuration_version_id) REFERENCES risk_configuration_versions(id) ON DELETE RESTRICT
FOREIGN KEY (model_version_id) REFERENCES model_versions(id) ON DELETE RESTRICT
ТАБЛИЦА risk_score_warnings

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
risk_score_result_id BIGINT UNSIGNED NOT NULL
warning_level VARCHAR(32) NOT NULL DEFAULT 'warning'
warning_code VARCHAR(64) NOT NULL
message TEXT NOT NULL
entity_type VARCHAR(128) NULL
entity_id BIGINT UNSIGNED NULL
created_at DATETIME(6) NOT NULL
FOREIGN KEY (risk_score_result_id) REFERENCES risk_score_results(id) ON DELETE CASCADE
ТАБЛИЦА risk_score_indicator_contributions

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
risk_score_result_id BIGINT UNSIGNED NOT NULL
indicator_config_id BIGINT UNSIGNED NOT NULL
series_id BIGINT UNSIGNED NOT NULL
observation_id BIGINT UNSIGNED NULL
snapshot_observation_id BIGINT UNSIGNED NULL
raw_value DECIMAL(24,8) NULL
transformed_value DECIMAL(24,8) NULL
normalized_indicator_score DECIMAL(10,4) NULL
original_weight DECIMAL(10,4) NOT NULL
frequency_discount DECIMAL(10,4) NOT NULL DEFAULT 1.0000
effective_weight DECIMAL(10,4) NULL
contribution_value DECIMAL(10,4) NULL
is_available BOOLEAN NOT NULL
is_required BOOLEAN NOT NULL DEFAULT FALSE
missing_reason ENUM('not_available', 'validation_invalid', 'production_blocked', 'license_blocked') NULL
created_at DATETIME(6) NOT NULL
FOREIGN KEY (risk_score_result_id) REFERENCES risk_score_results(id) ON DELETE CASCADE
FOREIGN KEY (indicator_config_id) REFERENCES indicator_configs(id) ON DELETE RESTRICT
FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE RESTRICT
FOREIGN KEY (observation_id) REFERENCES data_observations(id) ON DELETE SET NULL
FOREIGN KEY (snapshot_observation_id) REFERENCES snapshot_observations(id) ON DELETE SET NULL
ТАБЛИЦА historical_episodes

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
episode_key VARCHAR(128) NOT NULL UNIQUE
display_name VARCHAR(255) NOT NULL
start_date DATE NOT NULL
end_date DATE NOT NULL
stress_type VARCHAR(64) NOT NULL
severity_label VARCHAR(32) NOT NULL
expected_macro_signature JSON NULL
source_notes TEXT NULL
inclusion_rationale TEXT NOT NULL
detection_threshold_score DECIMAL(10,4) NOT NULL
detection_threshold_band VARCHAR(32) NOT NULL
detection_window_before_days INT UNSIGNED NOT NULL DEFAULT 90
detection_window_after_days INT UNSIGNED NOT NULL DEFAULT 90
minimum_persistence_periods INT UNSIGNED NOT NULL DEFAULT 1
status VARCHAR(32) NOT NULL DEFAULT 'draft'
approved_by BIGINT UNSIGNED NULL
approved_at DATETIME(6) NULL
created_at DATETIME(6) NOT NULL
updated_at DATETIME(6) NOT NULL
FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT
ТАБЛИЦА backtest_runs

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
backtest_run_key VARCHAR(128) NOT NULL UNIQUE
configuration_version_id BIGINT UNSIGNED NOT NULL
model_version_id BIGINT UNSIGNED NOT NULL
run_status VARCHAR(32) NOT NULL DEFAULT 'running'
false_positive_count INT UNSIGNED NULL
small_sample_warning BOOLEAN NULL
sample_size_n INT UNSIGNED NULL
started_at DATETIME(6) NOT NULL
completed_at DATETIME(6) NULL
FOREIGN KEY (configuration_version_id) REFERENCES risk_configuration_versions(id) ON DELETE RESTRICT
FOREIGN KEY (model_version_id) REFERENCES model_versions(id) ON DELETE RESTRICT
ТАБЛИЦА backtest_episode_results

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
backtest_run_id BIGINT UNSIGNED NOT NULL
episode_id BIGINT UNSIGNED NOT NULL
episode_key VARCHAR(128) NULL
detected BOOLEAN NOT NULL
first_detection_date DATETIME(6) NULL
lead_days INT NULL
lag_days INT NULL
max_risk_score DECIMAL(10,4) NULL
max_risk_band VARCHAR(32) NULL
detection_window_start DATE NULL
detection_window_end DATE NULL
vintage_dates_tested_count INT UNSIGNED NOT NULL DEFAULT 0
vintage_dates_missing_data_count INT UNSIGNED NOT NULL DEFAULT 0
created_at DATETIME(6) NOT NULL
FOREIGN KEY (backtest_run_id) REFERENCES backtest_runs(id) ON DELETE CASCADE
FOREIGN KEY (episode_id) REFERENCES historical_episodes(id) ON DELETE CASCADE
ТАБЛИЦА backtest_episode_score_points

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
backtest_episode_result_id BIGINT UNSIGNED NOT NULL
risk_score_result_id BIGINT UNSIGNED NOT NULL
vintage_date DATETIME(6) NOT NULL
risk_score DECIMAL(10,4) NULL
risk_band VARCHAR(32) NULL
detection_threshold_met BOOLEAN NOT NULL
created_at DATETIME(6) NOT NULL
FOREIGN KEY (backtest_episode_result_id) REFERENCES backtest_episode_results(id) ON DELETE CASCADE
FOREIGN KEY (risk_score_result_id) REFERENCES risk_score_results(id) ON DELETE CASCADE
ГРУППА 7: NARRATIVES, JOBS И АУДИТ
ТАБЛИЦА narrative_slots

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
slot_key VARCHAR(128) NOT NULL
version_number INT UNSIGNED NOT NULL
status VARCHAR(32) NOT NULL DEFAULT 'draft'
scientific_integrity_status ENUM('pending', 'validated', 'approved', 'rejected') NOT NULL DEFAULT 'pending'
approved_by BIGINT UNSIGNED NULL
approved_at DATETIME(6) NULL
created_at DATETIME(6) NOT NULL
UNIQUE KEY uq_slot_ver (slot_key, version_number)
FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
ТАБЛИЦА narrative_slot_translations

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
narrative_slot_id BIGINT UNSIGNED NOT NULL
locale VARCHAR(16) NOT NULL
text TEXT NOT NULL
text_hash CHAR(64) NOT NULL
created_at DATETIME(6) NOT NULL
updated_at DATETIME(6) NOT NULL
UNIQUE KEY uq_slot_loc (narrative_slot_id, locale)
FOREIGN KEY (narrative_slot_id) REFERENCES narrative_slots(id) ON DELETE CASCADE
ТАБЛИЦА narrative_reports

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
report_key VARCHAR(128) NOT NULL UNIQUE
risk_score_result_id BIGINT UNSIGNED NOT NULL
configuration_version_id BIGINT UNSIGNED NOT NULL
locale VARCHAR(16) NOT NULL
report_status VARCHAR(32) NOT NULL
seed_hash CHAR(64) NOT NULL
seed_int BIGINT UNSIGNED NOT NULL
full_text MEDIUMTEXT NULL
scientific_integrity_status ENUM('pending', 'validated', 'approved', 'rejected') NOT NULL DEFAULT 'pending'
published_at DATETIME(6) NULL
published_by BIGINT UNSIGNED NULL
generated_at DATETIME(6) NOT NULL
FOREIGN KEY (risk_score_result_id) REFERENCES risk_score_results(id) ON DELETE RESTRICT
FOREIGN KEY (configuration_version_id) REFERENCES risk_configuration_versions(id) ON DELETE RESTRICT
FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
ТАБЛИЦА narrative_report_slots

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
narrative_report_id BIGINT UNSIGNED NOT NULL
narrative_slot_id BIGINT UNSIGNED NOT NULL
slot_key VARCHAR(128) NOT NULL
slot_type VARCHAR(64) NOT NULL
slot_version_number INT UNSIGNED NOT NULL
locale VARCHAR(16) NOT NULL
created_at DATETIME(6) NOT NULL
FOREIGN KEY (narrative_report_id) REFERENCES narrative_reports(id) ON DELETE CASCADE
FOREIGN KEY (narrative_slot_id) REFERENCES narrative_slots(id) ON DELETE RESTRICT
ТАБЛИЦА job_runs

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
job_key VARCHAR(128) NOT NULL UNIQUE
job_type VARCHAR(128) NOT NULL
status VARCHAR(32) NOT NULL DEFAULT 'queued'
started_at DATETIME(6) NOT NULL
completed_at DATETIME(6) NULL
error_code VARCHAR(64) NULL
error_message TEXT NULL
metadata_json JSON NULL
created_at DATETIME(6) NOT NULL
ТАБЛИЦА system_errors

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
error_key VARCHAR(128) NOT NULL UNIQUE
error_code VARCHAR(64) NOT NULL
http_status_code INT NULL
human_message TEXT NOT NULL
machine_message TEXT NOT NULL
remediation_hint TEXT NULL
context_json JSON NULL
created_at DATETIME(6) NOT NULL
ТАБЛИЦА audit_records (Strictly Append-Only)

id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
audit_key VARCHAR(128) NOT NULL UNIQUE
actor_user_id BIGINT UNSIGNED NULL
actor_name VARCHAR(255) NULL
actor_role VARCHAR(64) NULL
event_type VARCHAR(128) NOT NULL
entity_type VARCHAR(128) NOT NULL
entity_id BIGINT UNSIGNED NULL
old_value_json JSON NULL
new_value_json JSON NULL
diff_json JSON NULL
created_at DATETIME(6) NOT NULL
ФАЙЛ 4. PHILOSOPHICAL AND SCIENTIFIC INTEGRITY FOUNDATION
Версия: 1.7.2-FINAL
Статус: Philosophical Truth

1. ЭПИСТЕМОЛОГИЯ ОТКАЗА ОТ ИЛЛЮЗИИ ЗНАНИЯ
Главный принцип системы: Система не должна притворяться, что знает то, чего не поддерживают данные.
MacroRisk — это система детерминированной диагностики структурной устойчивости, а не механизм прогнозирования (forecasting). Риск-скор является исключительно модельной оценкой (model-derived estimate), полученной на основе конфигурации и явно заданных порогов, а не эмпирически наблюдаемым фактом экономики.

2. ПРИНЦИПЫ НАУЧНОЙ ЧЕСТНОСТИ В ТЕКСТАХ И ОТЧЁТАХ
Система категорически запрещает:

Causal Claims: Заявлять причинно-следственные связи без методологического доказательства (запрет слов "caused by", "model proves").
False Certainty: Генерировать "прогнозы" и использовать слова "гарантирует", "доказывает" или "неизбежно".
Fake Precision: Показывать фальшивую статистическую точность на малых выборках. Бэктест с количеством исторических эпизодов менее 10 (N < 10) является исключительно диагностическим. Агрегированные метрики точности (recall, precision) для малых выборок запрещены и скрываются. Отображаются только абсолютные числа (detected count, missed count) с обязательным предупреждением.
LLM Нарративы: Использовать Generative AI (LLM) для формирования финальных аналитических выводов в обход детерминированных текстовых слотов. Механизм детерминированного выбора см. Файл 1, раздел DETERMINISTIC NARRATIVE SLOT SELECTION.
3. УРОК МАНИТОБЫ И ГРАНИЦЫ ИНТЕРПРЕТАЦИИ
На основе демографического анализа Манитобы система выводит важнейший методологический концепт: видимая стабильность системы не означает её внутреннее благополучие. Стабильность когорты P2 обеспечивается внешней миграцией (migration replenishment), компенсирующей падение внутреннего естественного воспроизводства.
Интерпретационные рамки (Boundaries): В отчётах запрещены публицистические или паникерские термины ("аппарат искусственного жизнеобеспечения", "крах системы"). Допустимая терминология: "The demographic extension suggests external-replenishment dependence under the configured assumptions." Модель разделяет внутренние механизмы удержания и внешние механизмы подпитки через прозрачные параметры калибровки.
