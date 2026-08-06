ФАЙЛ 1. MASTER ARCHITECTURE AND TECHNICAL SPECIFICATION

Версия: 1.7.4-FINAL Тип проекта: Новая самостоятельная система Язык: PHP 8.3+ (Framework-independent, strict type-checking, native BCMath) СУБД: MySQL 8.4 LTS InnoDB utf8mb4 (utf8mb4_0900_as_cs для отображения; для indicator_key и всех полей, участвующих в детерминированной сортировке для построения хешей, значения ограничены ASCII — см. Файл 3, indicator_configs — сортировка в коде выполняется явным ASCII byte-wise сравнением независимо от коллации СУБД, коллация СУБД используется только для UI/отображения) Локаль: en-CA (см. §6.1 — суженный формат ^[a-z]{2}-[A-Z]{2}$, не полный BCP-47; fr-CA в Phase 3) Статус: Implementation-Ready Deterministic Source of Truth

1. АРХИТЕКТУРНЫЕ ПРИНЦИПЫ И ОГРАНИЧЕНИЯ

Система MacroRisk — прозрачный, аудируемый и детерминированный механизм оценки макроэкономических рисков. Приоритеты: Корректность важнее количества функций. Прозрачность важнее автоматизации. Детерминизм важнее эвристик. Аудируемость важнее удобства.

Обязательный принцип: Every production decision must be reproducible. Область действия принципа — decision path: risk_score, risk_band, calculation_hash, narrative-текст, backtest-результаты. Он не распространяется на transport path (сетевые операции ingestion — таймауты, ретраи), которые по природе взаимодействуют с внешним недетерминированным миром (см. §3.2).

Запрещено использовать:

Laravel, Symfony Full-Stack и другие полнофункциональные фреймворки. Допускаются независимые PSR-компоненты (PSR-7, PSR-11, PSR-15) и standalone-библиотеки, не требующие полного фреймворка вокруг себя — включая Symfony Console как автономный компонент (используется в CLI-слое, см. §6.4), поскольку он не является "full-stack" фреймворком и не тянет за собой Symfony DI/routing/HTTP-kernel.
Runtime LLM для принятия решений или генерации production-текстов.
Заброшенные Composer-пакеты.
GPL-зависимости.
Неофициальные wrappers (PyPI, GitHub) для StatCan, Bank of Canada, CMHC, CREA, OSFI.
Нативный PHP float для расчётов весов, баллов и промежуточных математических операций.
2. ПРАВИЛА ОБРАБОТКИ ВРЕМЕНИ И ДАННЫХ

Все входящие даты и времена нормализуются в UTC перед сохранением. Формат хранения — DATETIME(6) (микросекундная точность, нужна для дедупликации и предотвращения race conditions).

Драйвер PDO обязан быть сконфигурирован с PDO::ATTR_EMULATE_PREPARES => false (нативные prepared statements) — при этом типе конфигурации MySQL-драйвер уже возвращает значения DECIMAL как PHP-строки без дополнительных флагов. PDO::ATTR_STRINGIFY_FETCHES НЕ используется — этот флаг приводит к строке абсолютно все типы столбцов (включая INT/BIGINT), что создаёт непредсказуемые побочные эффекты за пределами денежных/скоровых полей. Взамен: тестовый набор Domain-слоя ОБЯЗАН содержать проверку, что каждое значение DECIMAL, извлечённое через репозиторий, приходит как PHP string до оборачивания в Value Object MathDecimal — на границе Infrastructure/Domain это единственная точка, где может произойти случайный каст в float, и именно она тестируется явно.

3. ИСТОЧНИКИ ДАННЫХ И ЖИЗНЕННЫЙ ЦИКЛ
3.1. Эффективная дата релиза (Effective Release Date)
get_effective_release_date(row):
    if row.actual_release_date IS NOT NULL:
        return row.actual_release_date
    if row.estimated_release_date IS NOT NULL:
        return row.estimated_release_date
    return row.ingestion_date

row — строка release_calendars, соответствующая обрабатываемому релизному периоду серии. row.ingestion_date — не отдельное физическое поле; при отсутствии обеих дат (actual_release_date и estimated_release_date) подставляется ingestion_runs.started_at того запуска ingestion, в рамках которого был создан снапшот, инициировавший обработку данного релизного периода (то есть data_snapshots.ingestion_run_id -> ingestion_runs.started_at).

При использовании estimated_release_date устанавливается release_date_quality = 'estimated', при использовании ingestion_date — release_date_quality = 'fallback_to_ingestion'. release_date_quality = 'official' устанавливается, когда используется actual_release_date (первая ветка функции). release_date_quality = 'unknown' — дефолтное значение строки release_calendars до первого прохода get_effective_release_date для неё (то есть до первой попытки классификации); ни одна production-запись не должна оставаться в 'unknown' после успешного ingestion соответствующего периода.

Значение, вычисленное get_effective_release_date, при создании/обновлении snapshot_observations копируется в snapshot_observations.release_date (вместе с соответствующим release_date_quality) — именно оно, а не поле release_calendars напрямую, используется в правиле выбора ревизий §4, поскольку look-ahead bias оценивается на уровне доступности конкретного наблюдения (snapshot_observations), а не абстрактного релизного периода.

3.2. Алгоритм Retry Policy и переход в unavailable

При сетевых ошибках (HTTP 500, 502, 503, 504, Timeout) применяется Exponential Backoff с Full Jitter:

delay_k = random(0, min(MAX_BACKOFF_SECONDS, INITIAL_BACKOFF_SECONDS * backoff_multiplier^k))

Это — операция transport path (см. §1): само время задержки между сетевыми попытками не является production-решением, подлежащим воспроизводимости, и намеренно недетерминировано (random()) — цель Full Jitter — избежать synchronized retry storms между параллельными worker'ами, что прямо требует случайности в тайминге. Детерминизм требуется от результата (какой именно validation_status/validation_error_code в итоге зафиксирован, какие данные приняты) — не от таймингов самих HTTP-попыток. Все использованные в формуле константы (MAX_BACKOFF_SECONDS, INITIAL_BACKOFF_SECONDS, backoff_multiplier — последняя берётся из retry_policies.backoff_multiplier) зафиксированы в Файле 2, §1.2.

Если попытка k <= max_retries успешна -> статус восстанавливается в valid.
Во время активного цикла повторов validation_status = temporary_unavailable с фиксацией validation_error_code (SOURCE_TIMEOUT, HTTP_500 и т.д.).
Если исчерпаны все max_retries -> validation_status переходит в unavailable.
3.3. Календарь релизов и переходы состояний

Допустимый период задержки публикации — константа RELEASE_LATENESS_TOLERANCE_DAYS (значение и точное имя — см. Файл 2, §1.2; переименование/иное значение возможно только по утверждённому изменению этой спецификации, а не по внешнему документу, отсутствующему в текущем пакете).

Правило перехода delayed -> release_late:

Текущая дата UTC > expected_release_date и данных нет -> release_calendars.release_status = 'delayed'.
Текущая дата UTC > expected_release_date + RELEASE_LATENESS_TOLERANCE_DAYS и данных нет -> release_calendars.release_status автоматически становится 'release_late'.

Канонический список значений release_calendars.release_status (и синхронизированного с ним data_release_records.release_status) — ровно пять: expected, delayed, release_late, missing, revised. Дополнительного значения published не существует: факт публикации данных уже однозначно выражается через actual_release_date IS NOT NULL, отдельный статус для этого избыточен и создавал бы два независимых источника истины для одного факта.

Область проекции в series.validation_status ограничена: из состояний release_calendars в series.validation_status синхронизируется только переход в release_late (по правилу: когда для последнего ожидаемого периода серии release_calendars.release_status становится release_late, фоновый процесс синхронизации проецирует series.validation_status = release_late). Остальные значения series.validation_status (temporary_unavailable, unavailable, access_denied, schema_mismatch, data_pending, series_mapping_stale, pending_validation, valid) возникают из независимого source validation state machine (§3, полный список переходов — см. ниже) и не являются проекцией release_calendars — release_calendars не источник истины для всего validation_status, а источник истины только для значения release_late конкретно.

3.4. Семантика production_allowed

license_status = public_open AND production_allowed = false — валидное состояние: технический карантин / Soft-Kill Switch (например, подозрение на повреждение схемы или ручной аудит качества данных).

license_status != public_open AND production_allowed = true — недопустимое состояние, сбрасывается в false системным инвариантом.

SOURCE VALIDATION STATE MACHINE (полный список, единый канонический ENUM)

validation_status для series и series_validation_results — 9 значений. Для source_endpoints — тот же набор плюс no_historical_data_at_source (10 значений) — отсутствие исторических данных у самого источника/эндпоинта в принципе, это состояние не совпадает по имени с calculation_status.missing_no_historical_data намеренно: первое — постоянное свойство технической точки интеграции, второе — свойство одного конкретного расчётного запроса на конкретную vintage_date (см. Файл 2, §8). Смешивать их в один код было бы объединением двух разных доменов под одинаковым названием.

source_timeout/source_rate_limited — не значения validation_status, а значения validation_error_code (VARCHAR).

pending_validation — начальное состояние. -> valid, unavailable, access_denied, temporary_unavailable, schema_mismatch, data_pending.
valid — рабочее состояние (при соблюдении License Gate). -> temporary_unavailable, series_mapping_stale, schema_mismatch, access_denied, release_late.
temporary_unavailable — временная блокировка (сетевые сбои/таймауты/лимиты/HTTP 500/503). validation_error_code: SOURCE_TIMEOUT / SOURCE_RATE_LIMITED / HTTP_500. -> valid, series_mapping_stale, unavailable.
schema_mismatch — HTTP 200, ожидаемые поля отсутствуют. -> valid.
series_mapping_stale — HTTP 404/410, маппинг устарел. Production блокируется. -> valid (только после исправления маппинга).
data_pending — grace period для новых серий. Production запрещён. -> valid, release_late, temporary_unavailable.
unavailable — постоянный инфраструктурный сбой (после исчерпания retry_policy). -> valid, series_mapping_stale.
access_denied — HTTP 401/403/истёкший ключ. -> valid (только после обновления credentials).
release_late — см. §3.3. -> valid, series_mapping_stale.
no_historical_data_at_source (только source_endpoints) — отсутствие ретроспективных данных у источника в принципе. -> valid.
LICENSE GATE STATE MACHINE (ENUM)

unverified -> public_open_candidate -> public_open | requires_license. Инвариант: production_allowed = true возможно ТОЛЬКО при license_status = public_open (и на уровне series, и на уровне data_sources).

4. SNAPSHOT DEDUPLICATION И REVISION SELECTION

Все хеши (source_payload_hash, raw_content_hash, content_hash) — lowercase hexadecimal strings, CHAR(64).

source_payload_hash: SHA-256 сырого ответа API/CSV.
raw_content_hash: SHA-256 распарсенного сырого значения, даты и юнита (БЕЗ номера ревизии). Дедупликация в data_observations (uq_obs_dedup).
content_hash: SHA-256 значения, даты, юнита, статуса И номера ревизии. Трекинг версий в snapshot_observations/data_snapshots.

Правила дедупликации:

source_payload_hash и content_hash совпадают с предыдущим снапшотом -> is_duplicate = true, новые data_observations не создаются.
revision_number изменился, raw_content_hash тот же -> data_revision_event(value_changed = false), новая data_observation НЕ создаётся.
Значение изменилось -> новая data_observation, data_revision_event(value_changed = true), новый snapshot_observations.

Выбор ревизий (Предотвращение Look-ahead bias) — алгоритм оперирует строками таблицы snapshot_observations (единственной таблицы, где одновременно присутствуют и release_date, и revision_number, и observation_id):

Из кандидатов snapshot_observations данной серии выбрать те, где snapshot_observations.release_date <= vintage_date.
Среди них выбрать строку с максимальным revision_number. При отсутствии/равенстве revision_number — строку с минимальным observation_id.
Reproduction rule: ранее сохранённый risk_score загружает строго связанный snapshot_observation_id (зафиксированный в risk_score_indicator_contributions.snapshot_observation_id), игнорируя новые данные.
5. ОПЕРАЦИОННЫЕ ГЕЙТЫ
5.1. BOOTSTRAP TIME WINDOW LOGIC
Day 0..BOOTSTRAP_LOCKOUT_DAYS: разрешено создание Admin/sources/endpoints. Запрещены ingestion и расчёты.
Day (BOOTSTRAP_LOCKOUT_DAYS+1)..BOOTSTRAP_DRAFT_DAYS: разрешены ingestion/validation/draft calculations. Production заблокирован.
Day (BOOTSTRAP_DRAFT_DAYS+1)..BOOTSTRAP_MIN_AGE_DAYS+: Production разрешён ТОЛЬКО после утверждения первого production System Preset — условия: >= BOOTSTRAP_MIN_VALID_SERIES valid-серий, >= BOOTSTRAP_MIN_PUBLIC_OPEN_SERIES public_open-серий, >= MINIMUM_AVAILABLE_INDICATORS доступных индикаторов (значения констант — Файл 2, §1.2).
5.2. CONFIGURATION PUBLICATION GATE

Переход в status = published разрешён ТОЛЬКО если одновременно:

Сумма original_weight всех индикаторов = 100.0000, все веса в [0.0000, 100.0000].
Все thresholds валидны.
Версия модели active.
Все индикаторы конфигурации имеют validation_status = 'valid'.
Все индикаторы имеют license_status = 'public_open' и production_allowed = true.
Метаданные валидации не старше VALIDATION_METADATA_TTL_DAYS.
Исполнитель — Admin или Risk Officer.

Явное правило про карантин (§3.4): если индикатор находится в soft-kill-карантине (production_allowed = false при license_status = public_open), он не может входить в публикуемую конфигурацию ни при каких условиях — карантинный индикатор ОБЯЗАН быть удалён из состава indicator_configs версии конфигурации (либо версия должна быть создана заново без него) до попытки публикации. Это не противоречие, а прямое следствие смысла карантина: временно исключённый из production индикатор не может одновременно быть частью production-конфигурации.

6. ИНТЕРФЕЙСЫ (API, CLI, UI)
6.1. DETERMINISTIC NARRATIVE SLOT SELECTION

Формат локали (MVP, суженное подмножество, НЕ полный BCP-47): ^[a-z]{2}-[A-Z]{2}$ (например en-CA, fr-CA). Полный BCP-47 (скрипт-теги, региональные варианты, приватные расширения) — вне текущего объёма; расширение потребует расширения этого regex и колонки locale за пределы текущей длины при необходимости.

Для каждого сгенерированного отчёта (narrative_reports):

report_key формируется детерминированно ДО генерации текста: report_key = HEX(SHA-256(risk_score_result_id || '|' || configuration_version_id || '|' || locale || '|' || report_type)), где report_type — тип отчёта (см. Файл 4/справочник типов отчётов). Идемпотентность: повторный запрос генерации отчёта с идентичными входами обязан вернуть уже существующую запись narrative_reports с этим report_key (по UNIQUE), а не выбросить ошибку дубликата и не создать новую запись.
Вход хеша: seed_hash = SHA-256(risk_score_results.calculation_hash || '|' || narrative_reports.locale) (report_key исключён из входа сознательно — для детерминизма независимо от того, когда именно был запрошен повторный запуск генерации). Результат — hex string CHAR(64).
Seed Integer: seed_int = первые 8 байт бинарного SHA-256-дайджеста из шага 2, BIG_ENDIAN unsigned 64-bit integer.
Выбор варианта слота, для каждого требуемого slot_key: a. Список одобренных вариантов — narrative_slot_translations, отфильтрованных по locale, где родительский narrative_slots.status = 'published' И narrative_slots.scientific_integrity_status = 'approved' (у самой narrative_slot_translations колонки status/scientific_integrity_status НЕТ — фильтрация всегда через родительскую narrative_slots), отсортированных по id ASC. N — их количество. b. N = 0 -> SCIENTIFIC_INTEGRITY_VIOLATION. c. slot_seed = первые 8 байт SHA-256(seed_hash || '|' || slot_key), BIG_ENDIAN unsigned 64-bit integer. d. selected_index = slot_seed MOD N (0-based). e. Фиксируется вариант с этим индексом; запись — в narrative_report_slots.

Жизненный цикл scientific_integrity_status: pending (черновик) -> validated (пройдена автоматическая проверка запрещённых фраз/XSS/ложной уверенности) -> approved (утверждено Risk Officer) ИЛИ rejected. Прямой переход pending -> approved, минуя validated, — нарушение процесса; на уровне СУБД это не ограничено CHECK-constraint'ом (MySQL CHECK не поддерживает предикаты по предыдущему значению строки), поэтому это гарантируется исключительно Application-слоем (Use Case, единственная точка записи scientific_integrity_status), аналогично тому, как переходы validation_status гарантируются Application-слоем, а не БД.

6.2. API ENDPOINTS (RESTful, JSON)

Все API требуют проверки API Key/Session и конвертации входящих дат в UTC.

GET /api/v1/series — Роли: Все. Реестр индикаторов.
POST /api/v1/series/{id}/validate — Роли: Admin, Risk Officer. Запускает SourceValidationStateMachine.
GET /api/v1/configurations — Роли: Все.
POST /api/v1/calculations/production — Роли: Admin, Risk Officer. Payload: config_version, vintage_date.
GET /api/v1/situation-room — Роли: Viewer и выше. Только published production data.
POST /api/v1/backtests — Роли: Analyst, Risk Officer. Rate limit: RATE_LIMIT_BACKTEST_PER_HOUR в час, применяется на уровне (user_id, endpoint) — не глобально на всю систему.
6.3. CLI COMMANDS
macrorisk:schema:install — выполняет phinx migrate. Не создаёт пользователей.
macrorisk:admin:create — создаёт первого Admin (только если Admin не существует). Интерактивный ввод email/password. Идемпотентна. Пишет в аудит.
macrorisk:ingest:run — запуск загрузки. Идемпотентна. File-based locks/concurrency controls.
macrorisk:validate:sources — массовая проверка endpoints.
macrorisk:audit:export — выгрузка логов аудита в CSV.
6.4. UI / HTML

Server-Rendered UI через Twig или Plates. Графики визуально разделяют Observed Data (сырые данные) и Model Output (расчётные скоры). Все пользовательские вводы текста проходят HTML Sanitization.

6.5. COMPOSER LAYOUT
/src/Domain — сущности, Value Objects (в т.ч. MathDecimal), интерфейсы, инварианты, константы.
/src/Application — Use Cases, State Machines, RiskEngine.
/src/Infrastructure — репозитории (PDO/DBAL), HTTP-клиенты, адаптеры (StatCanClient и т.п.).
/src/Api — PSR-15 Middlewares, контроллеры.
/src/Cli — Symfony Console команды (standalone-компонент, см. §1).
/src/Ui — контроллеры и шаблоны.
/tests — PHPUnit/Pest тесты.
ФАЙЛ 2. MATHEMATICAL CORE SPECIFICATION

Версия: 1.7.4-FINAL Статус: Mathematical Source of Truth

1. СИСТЕМНЫЕ И КАЛИБРОВОЧНЫЕ КОНСТАНТЫ (SYSTEM CONSTANTS)
1.1. Вычислительные и системные константы
HASH_ALGORITHM: SHA-256
HASH_FORMAT: lowercase hexadecimal string, CHAR(64).
TIMEZONE: UTC
SEED_ENDIANNESS: BIG_ENDIAN (uint64 из первых 8 байт бинарного дайджеста; полный алгоритм построения seed_int/slot_seed — Файл 1, §6.1).
SCALE: 8 (внутренняя точность BCMath).
STORAGE_SCALE: 4 (точность хранения DECIMAL(10,4)).
NORMALIZATION_EPSILON: 10^(-SCALE) = 0.00000001.
INDICATOR_KEY_SORT_ORDER: ASCII byte-wise comparison. indicator_key ограничен ASCII-подмножеством на уровне схемы (Файл 3, indicator_configs, CHECK), поэтому это сравнение всегда даёт один и тот же результат независимо от коллации СУБД, используемой для отображения в UI.
1.2. Бизнес-константы и пороги
MINIMUM_COVERAGE_REQUIRED: 60.0000 (единственный источник истины и Hard Floor для покрытия; risk_configuration_versions.coverage_minimum НЕ имеет собственного DEFAULT в схеме и обязано устанавливаться приложением из этой константы явно на момент создания версии — см. Файл 3).
MINIMUM_AVAILABLE_INDICATORS: 3
VALIDATION_METADATA_TTL_DAYS: 30
RELEASE_LATENESS_TOLERANCE_DAYS: 14
BOOTSTRAP_LOCKOUT_DAYS: 2
BOOTSTRAP_DRAFT_DAYS: 7
BOOTSTRAP_MIN_AGE_DAYS: 14
BOOTSTRAP_MIN_VALID_SERIES: 5
BOOTSTRAP_MIN_PUBLIC_OPEN_SERIES: 5
RATE_LIMIT_BACKTEST_PER_HOUR: 5 (область действия — пара (user_id, endpoint), см. Файл 1, §6.2)
DEFAULT_DETECTION_WINDOW_BEFORE_DAYS: 90
DEFAULT_DETECTION_WINDOW_AFTER_DAYS: 90
SMALL_SAMPLE_THRESHOLD_N: 10
MAX_BACKOFF_SECONDS: 300 (5 минут — верхний потолок задержки для Full Jitter, Файл 1, §3.2)
INITIAL_BACKOFF_SECONDS: 1
2. МАТЕМАТИЧЕСКАЯ ТОЧНОСТЬ И ОКРУГЛЕНИЕ (DECIMAL POLICY)

Все математические операции — исключительно через BCMath. Обязательна глобальная bcscale(8) либо явная передача scale = 8 в каждый вызов bcadd/bcsub/bcmul/bcdiv.

Семантика округления — Round Half Away From Zero (единственное название; ранее использовавшаяся параллельная метка "Half Up" исключена как вводящая в заблуждение — в некоторых источниках "half up" означает округление в сторону +бесконечности, что для отрицательных чисел даёт другой результат, чем ниже):

+1.5 -> +2.0, +1.4 -> +1.0
-1.5 -> -2.0, -1.4 -> -1.0

Любой вызов floatval() или каст (float) в домене вычислений выбрасывает FLOAT_USAGE_FORBIDDEN.

3. ТЕРМИНОЛОГИЯ И ОПРЕДЕЛЕНИЯ
available: наблюдение существует на дату винтажа (effective_release_date <= vintage_date, где effective_release_date берётся из snapshot_observations.release_date — см. Файл 1, §3.1), значение NOT NULL.
eligible: available AND (series.validation_status = 'valid') AND series.production_allowed AND indicator_configs.production_allowed AND (series.license_status = 'public_open').
4. ПОРЯДОК ВЫЧИСЛЕНИЙ (ORDER OF COMPUTATION)
Normalization: normalized_indicator_score для всех available индикаторов конфигурации (не только eligible — для полноты аудита в таблице результатов; нормализация для не-eligible индикаторов вычисляется исключительно в диагностических целях и не участвует в сумме risk_score на шаге 7).
Coverage Check: определение подмножества Eligible. Расчёт coverage_ratio. При несоблюдении порогов — остановка с соответствующим calculation_status (§8).
Effective Weights: дисконтированные и ренормализованные веса на подмножестве Eligible.
Rounding Reconciliation: округление весов до STORAGE_SCALE, сведение суммы к 100.0000%.
Indicator Contribution: вклад каждого индикатора.
Category Score: групповые баллы категорий.
Risk Score: сумма вкладов индикаторов (только eligible).
Risk Band: диапазон риска (§9).
5. ПОКРЫТИЕ (COVERAGE RATIO)

Ограничения original_weight: 0.0000 <= original_weight <= 100.0000 (оба предела защищены CHECK на уровне БД, Файл 3). Сумма всех original_weight в конфигурации строго равна 100.0000. Ограничения frequency_discount: 0.0000 < frequency_discount <= 1.0000. Нарушение -> INVALID_CONFIGURATION_THRESHOLDS.

coverage_ratio = СУММА(original_weight_i) для всех i из Eligible.

Условия выполнения расчёта:

coverage_ratio >= coverage_minimum (>= MINIMUM_COVERAGE_REQUIRED).
Количество eligible >= MINIMUM_AVAILABLE_INDICATORS.
Все is_required = true индикаторы — eligible.

frequency_discount НЕ влияет на расчёт coverage_ratio.

6. СТРАТЕГИИ НОРМАЛИЗАЦИИ (THRESHOLD NORMALIZATION)

H = high_risk_threshold, L = low_risk_threshold, x = transformed_value.

Guard: если |H - L| < NORMALIZATION_EPSILON: x на безопасной стороне -> score = 0.0000; иначе -> score = 100.0000.

higher_is_riskier (H > L): x <= L -> 0.0000; x >= H -> 100.0000; иначе ((x-L)/(H-L))*100.0000. lower_is_riskier (H < L): x >= L -> 0.0000; x <= H -> 100.0000; иначе ((L-x)/(L-H))*100.0000.

distance_from_target_is_riskier: T = target_value, M = max_deviation. Guard: M <= 0 -> INVALID_CONFIGURATION_THRESHOLDS. score = MIN(100.0000, (|x-T|/M)*100.0000).

outside_band_is_riskier: safe_min, safe_max, outside_band_min_boundary, outside_band_max_boundary. Guard: outside_band_min_boundary < safe_min < safe_max < outside_band_max_boundary. safe_min <= x <= safe_max -> 0.0000; x <= outside_band_min_boundary ИЛИ x >= outside_band_max_boundary -> 100.0000; левая интерполяция 100*(safe_min-x)/(safe_min-outside_band_min_boundary); правая 100*(x-safe_max)/(outside_band_max_boundary-safe_max).

7. ЭФФЕКТИВНЫЕ ВЕСА И ROUNDING RECONCILIATION
w_base_i = (original_weight_i / coverage_ratio) * 100.0000
w_disc_i = w_base_i * frequency_discount_i
effective_weight_raw_i = (w_disc_i / СУММА(w_disc_j, j из Eligible)) * 100.0000

Rounding Reconciliation: каждое effective_weight_raw_i округляется до 4 знаков (Round Half Away From Zero, §2). delta = 100.0000 - СУММА(округлённых весов). Если delta == 0 — ничего не делать. Если delta != 0, прибавить к одному индикатору по приоритету: (1) максимальный original_weight; (2) при равенстве — максимальный w_disc; (3) при равенстве — минимальный indicator_key по ASCII byte-wise сравнению.

8. ИТОГОВЫЕ РАСЧЁТЫ RISK SCORE, СТАТУСЫ И ХЕШИ

contribution_i = (normalized_score_i * reconciled_effective_weight_i) / 100.0000 risk_score = СУММА(contribution_i) для всех i из Eligible.

Канонический список значений calculation_status (ровно пять)
ok — расчёт успешно завершён, risk_score/risk_band заполнены.
insufficient_data — 0 eligible indicators.
low_coverage — coverage_ratio < coverage_minimum.
required_indicator_missing — хотя бы один is_required индикатор не eligible.
missing_no_historical_data — на запрошенную vintage_date у иначе валидной серии нет ни одного наблюдения на или до этой даты (см. Файл 1, §3 — не путать с source_endpoints.validation_status = 'no_historical_data_at_source', это другой домен).

Во всех случаях, кроме ok: risk_score = NULL, risk_band = NULL.

Формула calculation_hash (для каждого значения calculation_status)
calculation_hash = SHA-256(
    config_hash || '|' ||
    active_indicators_status_hash || '|' ||
    model_version || '|' ||
    vintage_date_iso8601 || '|' ||
    calculation_status || '|' ||
    status_payload
)

active_indicators_status_hash = SHA-256 конкатенации indicator_key:validation_status:license_status:production_allowed, отсортированной по indicator_key ASC (ASCII byte-wise), для всех индикаторов конфигурации.

status_payload по значению calculation_status:

ok: risk_score (STORAGE_SCALE, как строка) || '|' || risk_band.
insufficient_data: 'INSUFFICIENT_DATA'.
low_coverage: 'LOW_COVERAGE:' || coverage_ratio (как строка).
required_indicator_missing: 'REQUIRED_INDICATOR_MISSING:' || отсортированный по возрастанию (ASCII) список indicator_key отсутствующих required-индикаторов через запятую.
missing_no_historical_data: 'MISSING_NO_HISTORICAL_DATA'.
Канонический список значений missing_reason (ровно четыре строковых + NULL)
not_available — индикатор не available на данный vintage_date.
validation_invalid — series.validation_status != 'valid'.
production_blocked — series.production_allowed = false ИЛИ indicator_configs.production_allowed = false.
license_blocked — series.license_status != 'public_open'.
NULL (SQL NULL) — индикатор eligible, причина отсутствия неприменима.

Приоритет при нескольких одновременных причинах — сверху вниз по списку (первая подходящая записывается).

Category Score

Для категории C — множество A_c (eligible indicators в категории). Если A_c пусто: category_score = NULL, category_contribution = 0.0000. cat_weight_i = (reconciled_effective_weight_i / СУММА(reconciled_effective_weight в A_c)) * 100.0000 category_score = СУММА(normalized_score_i * cat_weight_i) / 100.0000 category_contribution = category_score * СУММА(original_weight_i, i в A_c) / 100.0000 Сумма category_contribution не обязана строго равняться risk_score (интерпретационная декомпозиция).

9. КЛАССИФИКАЦИЯ RISK BANDS И БЭКТЕСТИНГ (BACKTEST LOGIC)

Инвариант: разбиение 0.0000-100.0000 непрерывно, без дыр и перекрытий (very_low_min = 0.0000, severe_max = 100.0000, low_min = very_low_max и т.д.). Интервалы left-open, right-closed, кроме первого:

very_low: risk_score >= very_low_min AND risk_score <= very_low_max
low: risk_score > low_min AND risk_score <= low_max
moderate: risk_score > moderate_min AND risk_score <= moderate_max
high: risk_score > high_min AND risk_score <= high_max
severe: risk_score > severe_min AND risk_score <= severe_max

Backtest Detection Rule: эпизод считается detected, если внутри окна detection_window_before/after есть не менее minimum_persistence_periods расчётных точек, где risk_score >= detection_threshold_score ИЛИ band_rank(risk_band) >= band_rank(detection_threshold_band). Ранги: very_low=1, low=2, moderate=3, high=4, severe=5. first_detection_date < start_date -> lead_days; иначе -> lag_days.

Расчётные точки с calculation_status = 'missing_no_historical_data' исключаются из знаменателя detection-статистики эпизода (не считаются ни detected, ни missed — учитываются отдельно как "не оценено", сохраняются в backtest_episode_results.vintage_dates_missing_data_count).

Small Sample Warning: вычисляется в конце прогона. sample_size_n < SMALL_SAMPLE_THRESHOLD_N -> small_sample_warning = true; >= SMALL_SAMPLE_THRESHOLD_N -> false. До завершения расчёта (или при ошибке) поле backtest_runs.small_sample_warning имеет значение NULL — это единственные три состояния поля, других значений быть не может (не "ошибка" отдельно — ошибочный прогон также оставляет поле NULL, статус ошибки отражается в backtest_runs.run_status = 'failed').

Согласно Файлу 4, §2 (Fake Precision): при small_sample_warning = true агрегированные метрики точности (precision, recall) НЕ показываются — только абсолютные числа (detected count, missed count) с обязательным предупреждением, ссылающимся на SMALL_SAMPLE_THRESHOLD_N.

10. ОПЦИОНАЛЬНОЕ РАСШИРЕНИЕ: ДЕМОГРАФИЧЕСКИЙ МЕТАБОЛИЗМ (ACMF)

Опционально, OUT OF SCOPE для ядра v1.7.x. Шаг времени t -> t+1 = 1 ГОД. P1 = 0-14 лет (знаменатель 15), P2 = 15-64 лет (знаменатель 50), P3 = 65+ лет.

OBSERVED MODE: Births(t) и Deaths_k(t) — внешние наблюдаемые факты.

SIMULATION MODE: fertility_rate(t) = annual_births_per_working_age_person; Births(t) = fertility_rate(t) * P2(t). mortality_rate_k(t) = annual_mortality_probability; Deaths_k(t) = mortality_rate_k(t) * P_k(t).

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

Калибровочные константы (дефолты, country-specific override допустим): CALIBRATION_GAMMA_SCALE: 0.9543, CALIBRATION_G9: 1.0000, CALIBRATION_G10: 1.0000, LABOUR_RETENTION_SCALE: 0.0015, MIGRATION_CHILD_SHARE: 0.15, MIGRATION_WORKING_SHARE: 0.80, MIGRATION_SENIOR_SHARE: 0.05 (инвариант: сумма долей миграции = 1.0000).

ФАЙЛ 3. DATABASE SCHEMA CONTRACT

Версия: 1.7.4-FINAL СУБД: MySQL 8.4 LTS, Engine: InnoDB, Charset: utf8mb4 (utf8mb4_0900_as_cs)

РЕЕСТР REQUIRED INDEX MATRIX
sql
CREATE INDEX idx_series_val_status ON series (validation_status);
CREATE INDEX idx_series_lic_status ON series (license_status);
CREATE INDEX idx_series_prod_allowed ON series (production_allowed);
CREATE INDEX idx_obs_series_date ON data_observations (series_id, observation_date);
CREATE INDEX idx_snap_obs_series_vdate ON snapshot_observations (series_id, vintage_date);
CREATE INDEX idx_snap_obs_series_rdate ON snapshot_observations (series_id, release_date);
CREATE INDEX idx_rsr_config_vdate ON risk_score_results (configuration_version_id, vintage_date);
CREATE INDEX idx_rsr_vdate ON risk_score_results (vintage_date);
CREATE INDEX idx_audit_entity ON audit_records (entity_type, entity_id);
CREATE INDEX idx_audit_created ON audit_records (created_at);
CREATE INDEX idx_audit_actor ON audit_records (actor_name, actor_role);
CREATE INDEX idx_ingest_ds_start ON ingestion_runs (data_source_id, started_at);
CREATE INDEX idx_dsnap_series_vdate ON data_snapshots (series_id, vintage_date);
ГРУППА 1: ИДЕНТИФИКАЦИЯ И ДОСТУП
sql
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_key CHAR(36) NOT NULL UNIQUE COMMENT 'RFC 4122 / RFC 9562 UUID v4 lowercase string',
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    status ENUM('active', 'disabled', 'locked') NOT NULL DEFAULT 'active',
    last_login_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    deleted_at DATETIME(6) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_key VARCHAR(64) NOT NULL UNIQUE,
    display_name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    created_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE user_roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NULL,
    assigned_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_user_role (user_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE api_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    key_prefix VARCHAR(32) NOT NULL UNIQUE,
    key_hash CHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    status ENUM('active', 'revoked', 'expired') NOT NULL DEFAULT 'active',
    last_used_at DATETIME(6) NULL,
    expires_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE sessions (
    id VARCHAR(128) NOT NULL PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    payload TEXT NOT NULL,
    last_activity INT UNSIGNED NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;
ГРУППА 2: ИСТОЧНИКИ ДАННЫХ
sql
CREATE TABLE data_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key VARCHAR(64) NOT NULL UNIQUE,
    display_name VARCHAR(255) NOT NULL,
    source_type ENUM('statcan_wds', 'statcan_bulk', 'bank_of_canada_valet', 'osfi_direct', 'system_internal') NOT NULL,
    base_url VARCHAR(1024) NOT NULL,
    official_documentation_url VARCHAR(1024) NULL,
    terms_of_use_url VARCHAR(1024) NULL,
    license_status ENUM('public_open', 'public_open_candidate', 'requires_license', 'unverified') NOT NULL DEFAULT 'unverified',
    production_allowed BOOLEAN NOT NULL DEFAULT FALSE,
    notes TEXT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE retry_policies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    policy_key VARCHAR(64) NOT NULL UNIQUE,
    max_retries INT UNSIGNED NOT NULL DEFAULT 3,
    backoff_multiplier DECIMAL(10,4) NOT NULL DEFAULT 2.0000,
    created_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE source_endpoints (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    data_source_id BIGINT UNSIGNED NOT NULL,
    endpoint_key VARCHAR(128) NOT NULL,
    endpoint_url VARCHAR(2048) NOT NULL,
    method ENUM('GET', 'POST') NOT NULL DEFAULT 'GET',
    response_format ENUM('json', 'csv', 'xml') NOT NULL DEFAULT 'json',
    requires_auth BOOLEAN NOT NULL DEFAULT FALSE,
    rate_limit_per_minute INT UNSIGNED NULL,
    timeout_seconds INT UNSIGNED NOT NULL DEFAULT 30,
    retry_policy_id BIGINT UNSIGNED NULL,
    production_allowed BOOLEAN NOT NULL DEFAULT FALSE,
    validation_status ENUM('pending_validation', 'valid', 'series_mapping_stale', 'unavailable', 'access_denied', 'temporary_unavailable', 'data_pending', 'schema_mismatch', 'release_late', 'no_historical_data_at_source') NOT NULL DEFAULT 'pending_validation',
    validation_error_code VARCHAR(64) NULL,
    last_validated_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_ds_endpoint (data_source_id, endpoint_key),
    FOREIGN KEY (data_source_id) REFERENCES data_sources(id) ON DELETE RESTRICT,
    FOREIGN KEY (retry_policy_id) REFERENCES retry_policies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;
ГРУППА 3: ИНДИКАТОРЫ И КАЛЕНДАРЬ
sql
CREATE TABLE series (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    series_key VARCHAR(128) NOT NULL UNIQUE,
    display_name VARCHAR(255) NOT NULL,
    data_source_id BIGINT UNSIGNED NOT NULL,
    source_endpoint_id BIGINT UNSIGNED NULL,
    source_provider VARCHAR(128) NOT NULL,
    underlying_origin VARCHAR(128) NULL,
    external_series_identifier VARCHAR(255) NULL,
    table_id VARCHAR(64) NULL,
    vector_id VARCHAR(64) NULL,
    country VARCHAR(16) NOT NULL DEFAULT 'CA',
    category ENUM('labor_market', 'housing', 'monetary', 'fiscal', 'demographic', 'macro_aggregate') NOT NULL,
    frequency ENUM('daily', 'weekly', 'monthly', 'quarterly', 'annual') NOT NULL,
    unit VARCHAR(64) NOT NULL,
    transformation_type ENUM('level', 'mom_change', 'yoy_change', 'percent_change', 'log_difference') NOT NULL DEFAULT 'level',
    expected_update_lag_days INT UNSIGNED NULL,
    terms_of_use_url VARCHAR(1024) NULL,
    attribution_required BOOLEAN NOT NULL DEFAULT FALSE,
    attribution_text TEXT NULL,
    license_status ENUM('public_open', 'public_open_candidate', 'requires_license', 'unverified') NOT NULL DEFAULT 'unverified',
    production_allowed BOOLEAN NOT NULL DEFAULT FALSE,
    validation_status ENUM('pending_validation', 'valid', 'series_mapping_stale', 'unavailable', 'access_denied', 'temporary_unavailable', 'data_pending', 'schema_mismatch', 'release_late') NOT NULL DEFAULT 'pending_validation',
    validation_error_code VARCHAR(64) NULL,
    validation_checked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    deleted_at DATETIME(6) NULL,
    FOREIGN KEY (data_source_id) REFERENCES data_sources(id) ON DELETE RESTRICT,
    FOREIGN KEY (source_endpoint_id) REFERENCES source_endpoints(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE series_validation_results (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    series_id BIGINT UNSIGNED NOT NULL,
    validation_run_key VARCHAR(128) NOT NULL,
    validation_status ENUM('pending_validation', 'valid', 'series_mapping_stale', 'unavailable', 'access_denied', 'temporary_unavailable', 'data_pending', 'schema_mismatch', 'release_late') NOT NULL,
    validation_error_code VARCHAR(64) NULL,
    http_status_code INT NULL,
    endpoint_url VARCHAR(2048) NOT NULL,
    response_schema_hash CHAR(64) NULL,
    latest_observation_date DATE NULL,
    detected_frequency ENUM('daily', 'weekly', 'monthly', 'quarterly', 'annual') NULL,
    license_status_at_validation ENUM('public_open', 'public_open_candidate', 'requires_license', 'unverified') NOT NULL,
    production_allowed_at_validation BOOLEAN NOT NULL,
    checked_at DATETIME(6) NOT NULL,
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE license_reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    series_id BIGINT UNSIGNED NOT NULL,
    review_status ENUM('pending', 'approved', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
    license_status_result ENUM('public_open', 'public_open_candidate', 'requires_license', 'unverified') NOT NULL,
    evidence_url VARCHAR(1024) NULL,
    notes TEXT NULL,
    expires_at DATETIME(6) NULL,
    reviewed_by BIGINT UNSIGNED NOT NULL,
    reviewed_at DATETIME(6) NOT NULL,
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE release_calendars (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    series_id BIGINT UNSIGNED NOT NULL,
    reference_period_start DATE NOT NULL,
    reference_period_end DATE NOT NULL,
    expected_release_date DATETIME(6) NULL,
    actual_release_date DATETIME(6) NULL,
    estimated_release_date DATETIME(6) NULL,
    release_date_quality ENUM('official', 'estimated', 'fallback_to_ingestion', 'unknown') NOT NULL DEFAULT 'unknown',
    release_date_source ENUM('provider_api', 'provider_calendar', 'system_inferred', 'manual_override') NOT NULL DEFAULT 'system_inferred',
    release_status ENUM('expected', 'delayed', 'release_late', 'missing', 'revised') NOT NULL DEFAULT 'expected',
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_release_cal (series_id, reference_period_start, reference_period_end),
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;
ГРУППА 4: ВИНТАЖИ, SNAPSHOTS И НАБЛЮДЕНИЯ
sql
CREATE TABLE ingestion_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ingestion_run_key VARCHAR(128) NOT NULL UNIQUE,
    data_source_id BIGINT UNSIGNED NOT NULL,
    source_endpoint_id BIGINT UNSIGNED NULL,
    series_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    job_id VARCHAR(128) NULL,
    source_payload_hash CHAR(64) NULL,
    records_seen INT UNSIGNED NOT NULL DEFAULT 0,
    records_inserted INT UNSIGNED NOT NULL DEFAULT 0,
    records_updated INT UNSIGNED NOT NULL DEFAULT 0,
    records_deduplicated INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    status ENUM('queued', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'running',
    error_code VARCHAR(64) NULL,
    created_at DATETIME(6) NOT NULL,
    FOREIGN KEY (data_source_id) REFERENCES data_sources(id) ON DELETE RESTRICT,
    FOREIGN KEY (source_endpoint_id) REFERENCES source_endpoints(id) ON DELETE SET NULL,
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE data_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    snapshot_key VARCHAR(128) NOT NULL UNIQUE,
    ingestion_run_id BIGINT UNSIGNED NOT NULL,
    series_id BIGINT UNSIGNED NOT NULL,
    snapshot_timestamp DATETIME(6) NOT NULL,
    vintage_date DATETIME(6) NOT NULL,
    source_payload_hash CHAR(64) NOT NULL,
    content_hash CHAR(64) NOT NULL,
    is_duplicate BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME(6) NOT NULL,
    FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs(id) ON DELETE RESTRICT,
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

-- Составной UNIQUE намеренно включает raw_value наряду с raw_content_hash: криптографическая
-- коллизия SHA-256 практически невозможна, но защита в глубину (defense-in-depth) стоит
-- дёшево, а изолированный баг в коде вычисления raw_content_hash (не в самом SHA-256, а в
-- сборке входной строки) не приведёт к тихой потере ревизии с иным значением.
CREATE TABLE data_observations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    series_id BIGINT UNSIGNED NOT NULL,
    observation_date DATE NOT NULL,
    frequency_at_observation ENUM('daily', 'weekly', 'monthly', 'quarterly', 'annual') NOT NULL,
    raw_value DECIMAL(24,8) NOT NULL,
    unit VARCHAR(64) NOT NULL,
    value_status ENUM('normal', 'estimated', 'provisional', 'revised', 'flagged') NOT NULL DEFAULT 'normal',
    raw_content_hash CHAR(64) NOT NULL,
    content_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_obs_dedup (series_id, observation_date, raw_value, raw_content_hash),
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE snapshot_observations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    snapshot_id BIGINT UNSIGNED NOT NULL,
    series_id BIGINT UNSIGNED NOT NULL,
    observation_id BIGINT UNSIGNED NOT NULL,
    vintage_date DATETIME(6) NOT NULL,
    release_date DATETIME(6) NULL,
    estimated_release_date DATETIME(6) NULL,
    release_date_quality ENUM('official', 'estimated', 'fallback_to_ingestion', 'unknown') NOT NULL,
    reproducibility_allowed BOOLEAN NOT NULL DEFAULT TRUE,
    is_revision BOOLEAN NOT NULL DEFAULT FALSE,
    revision_number INT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_snap_obs (snapshot_id, series_id, observation_id),
    FOREIGN KEY (snapshot_id) REFERENCES data_snapshots(id) ON DELETE CASCADE,
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
    FOREIGN KEY (observation_id) REFERENCES data_observations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE data_revision_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    series_id BIGINT UNSIGNED NOT NULL,
    observation_id BIGINT UNSIGNED NOT NULL,
    snapshot_id BIGINT UNSIGNED NULL,
    previous_revision_number INT UNSIGNED NULL,
    new_revision_number INT UNSIGNED NULL,
    previous_value DECIMAL(24,8) NULL,
    new_value DECIMAL(24,8) NOT NULL,
    value_changed BOOLEAN NOT NULL,
    revision_detected_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
    FOREIGN KEY (observation_id) REFERENCES data_observations(id) ON DELETE CASCADE,
    FOREIGN KEY (snapshot_id) REFERENCES data_snapshots(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE data_release_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    series_id BIGINT UNSIGNED NOT NULL,
    snapshot_id BIGINT UNSIGNED NULL,
    ingestion_run_id BIGINT UNSIGNED NULL,
    release_calendar_id BIGINT UNSIGNED NULL,
    reference_period_start DATE NULL,
    reference_period_end DATE NULL,
    release_detected_at DATETIME(6) NOT NULL,
    release_status ENUM('expected', 'delayed', 'release_late', 'missing', 'revised') NOT NULL,
    records_seen INT UNSIGNED NOT NULL,
    records_changed INT UNSIGNED NOT NULL,
    is_revision BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME(6) NOT NULL,
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
    FOREIGN KEY (snapshot_id) REFERENCES data_snapshots(id) ON DELETE SET NULL,
    FOREIGN KEY (ingestion_run_id) REFERENCES ingestion_runs(id) ON DELETE SET NULL,
    FOREIGN KEY (release_calendar_id) REFERENCES release_calendars(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;
ГРУППА 5: КОНФИГУРАЦИИ
sql
CREATE TABLE model_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    model_version VARCHAR(32) NOT NULL UNIQUE,
    release_date DATE NOT NULL,
    formula_key VARCHAR(64) NOT NULL,
    invariants_changed BOOLEAN NOT NULL DEFAULT FALSE,
    backward_compatible BOOLEAN NOT NULL DEFAULT TRUE,
    activated_at DATETIME(6) NULL,
    status ENUM('draft', 'active', 'deprecated', 'archived') NOT NULL DEFAULT 'draft',
    created_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE risk_configurations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    configuration_key VARCHAR(128) NOT NULL UNIQUE,
    owner_user_id BIGINT UNSIGNED NULL,
    source_configuration_id BIGINT UNSIGNED NULL,
    system_owned BOOLEAN NOT NULL DEFAULT FALSE,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    configuration_type ENUM('system_preset', 'custom_user', 'backtest_preset') NOT NULL,
    lifecycle_status ENUM('draft', 'active', 'archived') NOT NULL DEFAULT 'draft',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    deleted_at DATETIME(6) NULL,
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (source_configuration_id) REFERENCES risk_configurations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE risk_band_threshold_sets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    threshold_set_key VARCHAR(128) NOT NULL,
    version INT UNSIGNED NOT NULL,
    model_version_id BIGINT UNSIGNED NOT NULL,
    very_low_min DECIMAL(10,4) NOT NULL,
    very_low_max DECIMAL(10,4) NOT NULL,
    low_min DECIMAL(10,4) NOT NULL,
    low_max DECIMAL(10,4) NOT NULL,
    moderate_min DECIMAL(10,4) NOT NULL,
    moderate_max DECIMAL(10,4) NOT NULL,
    high_min DECIMAL(10,4) NOT NULL,
    high_max DECIMAL(10,4) NOT NULL,
    severe_min DECIMAL(10,4) NOT NULL,
    severe_max DECIMAL(10,4) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_band_ver (threshold_set_key, version),
    FOREIGN KEY (model_version_id) REFERENCES model_versions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

-- coverage_minimum НЕ имеет DEFAULT: приложение обязано явно передать значение
-- (как правило, равное MINIMUM_COVERAGE_REQUIRED из Файла 2, §1.2) при создании
-- версии конфигурации. Отсутствие DEFAULT исключает случайное дублирование
-- бизнес-константы в схеме, оставляя единственным источником истины Файл 2.
CREATE TABLE risk_configuration_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    configuration_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    version_key VARCHAR(128) NOT NULL UNIQUE,
    model_version_id BIGINT UNSIGNED NOT NULL,
    risk_band_threshold_set_id BIGINT UNSIGNED NULL,
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    validation_status ENUM('pending', 'valid', 'invalid') NOT NULL DEFAULT 'pending',
    validation_error_code VARCHAR(64) NULL,
    is_published BOOLEAN NOT NULL DEFAULT FALSE,
    published_at DATETIME(6) NULL,
    published_by BIGINT UNSIGNED NULL,
    coverage_minimum DECIMAL(10,4) NOT NULL,
    config_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_config_ver (configuration_id, version_number),
    CONSTRAINT chk_coverage_min CHECK (coverage_minimum >= 60.0000),
    FOREIGN KEY (configuration_id) REFERENCES risk_configurations(id) ON DELETE CASCADE,
    FOREIGN KEY (model_version_id) REFERENCES model_versions(id) ON DELETE RESTRICT,
    FOREIGN KEY (risk_band_threshold_set_id) REFERENCES risk_band_threshold_sets(id) ON DELETE RESTRICT,
    FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE indicator_configs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    configuration_version_id BIGINT UNSIGNED NOT NULL,
    series_id BIGINT UNSIGNED NOT NULL,
    indicator_key VARCHAR(128) NOT NULL,
    category ENUM('labor_market', 'housing', 'monetary', 'fiscal', 'demographic', 'macro_aggregate') NOT NULL,
    original_weight DECIMAL(10,4) NOT NULL,
    is_required BOOLEAN NOT NULL DEFAULT FALSE,
    transformation_type ENUM('level', 'mom_change', 'yoy_change', 'percent_change', 'log_difference') NOT NULL,
    normalization_method ENUM('threshold_linear') NOT NULL DEFAULT 'threshold_linear',
    direction_of_deterioration ENUM('higher_is_riskier', 'lower_is_riskier', 'distance_from_target_is_riskier', 'outside_band_is_riskier') NOT NULL,
    low_risk_threshold DECIMAL(24,8) NULL,
    high_risk_threshold DECIMAL(24,8) NULL,
    target_value DECIMAL(24,8) NULL,
    max_deviation DECIMAL(24,8) NULL,
    safe_min DECIMAL(24,8) NULL,
    safe_max DECIMAL(24,8) NULL,
    outside_band_min_boundary DECIMAL(24,8) NULL,
    outside_band_max_boundary DECIMAL(24,8) NULL,
    clamp_min DECIMAL(24,8) NULL,
    clamp_max DECIMAL(24,8) NULL,
    frequency_discount DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    production_allowed BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_ind_conf (configuration_version_id, indicator_key),
    CONSTRAINT chk_orig_weight CHECK (original_weight >= 0.0000 AND original_weight <= 100.0000),
    CONSTRAINT chk_freq_discount CHECK (frequency_discount > 0.0000 AND frequency_discount <= 1.0000),
    CONSTRAINT chk_indicator_key_ascii CHECK (indicator_key REGEXP '^[a-z0-9_]+$'),
    FOREIGN KEY (configuration_version_id) REFERENCES risk_configuration_versions(id) ON DELETE CASCADE,
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

-- override_type намеренно VARCHAR, не ENUM: расширяемый реестр типов оверрайдов,
-- пополняемый на уровне приложения (в т.ч. плагинами, Post-MVP) без DDL-миграции.
CREATE TABLE risk_configuration_overrides (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    indicator_config_id BIGINT UNSIGNED NOT NULL,
    override_type VARCHAR(64) NOT NULL,
    overridden_field VARCHAR(128) NOT NULL,
    override_value_json JSON NOT NULL,
    override_reason TEXT NOT NULL,
    approved_by BIGINT UNSIGNED NOT NULL,
    approved_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    FOREIGN KEY (indicator_config_id) REFERENCES indicator_configs(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;
ГРУППА 6: РЕЗУЛЬТАТЫ И БЭКТЕСТЫ
sql
CREATE TABLE risk_score_results (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    score_key VARCHAR(128) NOT NULL UNIQUE,
    configuration_version_id BIGINT UNSIGNED NOT NULL,
    model_version_id BIGINT UNSIGNED NOT NULL,
    vintage_date DATETIME(6) NOT NULL,
    calculation_mode ENUM('production', 'backtest', 'simulation', 'draft') NOT NULL,
    calculation_status ENUM('ok', 'insufficient_data', 'low_coverage', 'required_indicator_missing', 'missing_no_historical_data') NOT NULL,
    risk_score DECIMAL(10,4) NULL,
    risk_band ENUM('very_low', 'low', 'moderate', 'high', 'severe') NULL,
    coverage_ratio DECIMAL(10,4) NOT NULL,
    eligible_indicator_count INT UNSIGNED NOT NULL,
    configured_indicator_count INT UNSIGNED NOT NULL,
    required_indicator_missing BOOLEAN NOT NULL DEFAULT FALSE,
    effective_weights_sum DECIMAL(10,4) NOT NULL,
    calculation_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    FOREIGN KEY (configuration_version_id) REFERENCES risk_configuration_versions(id) ON DELETE RESTRICT,
    FOREIGN KEY (model_version_id) REFERENCES model_versions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE risk_score_warnings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    risk_score_result_id BIGINT UNSIGNED NOT NULL,
    warning_level ENUM('info', 'warning', 'error') NOT NULL DEFAULT 'warning',
    warning_code VARCHAR(64) NOT NULL,
    message TEXT NOT NULL,
    entity_type VARCHAR(128) NULL,
    entity_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    FOREIGN KEY (risk_score_result_id) REFERENCES risk_score_results(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE risk_score_indicator_contributions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    risk_score_result_id BIGINT UNSIGNED NOT NULL,
    indicator_config_id BIGINT UNSIGNED NOT NULL,
    series_id BIGINT UNSIGNED NOT NULL,
    observation_id BIGINT UNSIGNED NULL,
    snapshot_observation_id BIGINT UNSIGNED NULL,
    raw_value DECIMAL(24,8) NULL,
    transformed_value DECIMAL(24,8) NULL,
    normalized_indicator_score DECIMAL(10,4) NULL,
    original_weight DECIMAL(10,4) NOT NULL,
    frequency_discount DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    effective_weight DECIMAL(10,4) NULL,
    contribution_value DECIMAL(10,4) NULL,
    is_available BOOLEAN NOT NULL,
    is_required BOOLEAN NOT NULL DEFAULT FALSE,
    missing_reason ENUM('not_available', 'validation_invalid', 'production_blocked', 'license_blocked') NULL,
    created_at DATETIME(6) NOT NULL,
    FOREIGN KEY (risk_score_result_id) REFERENCES risk_score_results(id) ON DELETE CASCADE,
    FOREIGN KEY (indicator_config_id) REFERENCES indicator_configs(id) ON DELETE RESTRICT,
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE RESTRICT,
    FOREIGN KEY (observation_id) REFERENCES data_observations(id) ON DELETE SET NULL,
    FOREIGN KEY (snapshot_observation_id) REFERENCES snapshot_observations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE historical_episodes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    episode_key VARCHAR(128) NOT NULL UNIQUE,
    display_name VARCHAR(255) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    stress_type VARCHAR(64) NOT NULL,
    severity_label VARCHAR(32) NOT NULL,
    expected_macro_signature JSON NULL,
    source_notes TEXT NULL,
    inclusion_rationale TEXT NOT NULL,
    detection_threshold_score DECIMAL(10,4) NOT NULL,
    detection_threshold_band ENUM('very_low', 'low', 'moderate', 'high', 'severe') NOT NULL,
    detection_window_before_days INT UNSIGNED NOT NULL DEFAULT 90,
    detection_window_after_days INT UNSIGNED NOT NULL DEFAULT 90,
    minimum_persistence_periods INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('draft', 'active', 'archived') NOT NULL DEFAULT 'draft',
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE backtest_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    backtest_run_key VARCHAR(128) NOT NULL UNIQUE,
    configuration_version_id BIGINT UNSIGNED NOT NULL,
    model_version_id BIGINT UNSIGNED NOT NULL,
    run_status ENUM('queued', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'running',
    false_positive_count INT UNSIGNED NULL,
    small_sample_warning BOOLEAN NULL,
    sample_size_n INT UNSIGNED NULL,
    started_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    FOREIGN KEY (configuration_version_id) REFERENCES risk_configuration_versions(id) ON DELETE RESTRICT,
    FOREIGN KEY (model_version_id) REFERENCES model_versions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE backtest_episode_results (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    backtest_run_id BIGINT UNSIGNED NOT NULL,
    episode_id BIGINT UNSIGNED NOT NULL,
    episode_key VARCHAR(128) NULL,
    detected BOOLEAN NOT NULL,
    first_detection_date DATETIME(6) NULL,
    lead_days INT NULL,
    lag_days INT NULL,
    max_risk_score DECIMAL(10,4) NULL,
    max_risk_band ENUM('very_low', 'low', 'moderate', 'high', 'severe') NULL,
    detection_window_start DATE NULL,
    detection_window_end DATE NULL,
    vintage_dates_tested_count INT UNSIGNED NOT NULL DEFAULT 0,
    vintage_dates_missing_data_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL,
    FOREIGN KEY (backtest_run_id) REFERENCES backtest_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (episode_id) REFERENCES historical_episodes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE backtest_episode_score_points (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    backtest_episode_result_id BIGINT UNSIGNED NOT NULL,
    risk_score_result_id BIGINT UNSIGNED NOT NULL,
    vintage_date DATETIME(6) NOT NULL,
    risk_score DECIMAL(10,4) NULL,
    risk_band ENUM('very_low', 'low', 'moderate', 'high', 'severe') NULL,
    detection_threshold_met BOOLEAN NOT NULL,
    created_at DATETIME(6) NOT NULL,
    FOREIGN KEY (backtest_episode_result_id) REFERENCES backtest_episode_results(id) ON DELETE CASCADE,
    FOREIGN KEY (risk_score_result_id) REFERENCES risk_score_results(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;
ГРУППА 7: NARRATIVES, JOBS И АУДИТ
sql
CREATE TABLE narrative_slots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slot_key VARCHAR(128) NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    scientific_integrity_status ENUM('pending', 'validated', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_slot_ver (slot_key, version_number),
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

-- У этой таблицы НЕТ колонок status/scientific_integrity_status: фильтрация "одобренный
-- вариант" всегда идёт через родительскую narrative_slots (см. Файл 1, §6.1).
CREATE TABLE narrative_slot_translations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    narrative_slot_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NOT NULL COMMENT 'Суженный формат ^[a-z]{2}-[A-Z]{2}$, не полный BCP-47',
    text TEXT NOT NULL,
    text_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_slot_loc (narrative_slot_id, locale),
    FOREIGN KEY (narrative_slot_id) REFERENCES narrative_slots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE narrative_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_key VARCHAR(128) NOT NULL UNIQUE COMMENT 'HEX(SHA-256(risk_score_result_id|configuration_version_id|locale|report_type)) - см. Файл 1, §6.1',
    risk_score_result_id BIGINT UNSIGNED NOT NULL,
    configuration_version_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NOT NULL,
    report_status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    seed_hash CHAR(64) NOT NULL,
    seed_int BIGINT UNSIGNED NOT NULL,
    full_text MEDIUMTEXT NULL,
    scientific_integrity_status ENUM('pending', 'validated', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    published_at DATETIME(6) NULL,
    published_by BIGINT UNSIGNED NULL,
    generated_at DATETIME(6) NOT NULL,
    FOREIGN KEY (risk_score_result_id) REFERENCES risk_score_results(id) ON DELETE RESTRICT,
    FOREIGN KEY (configuration_version_id) REFERENCES risk_configuration_versions(id) ON DELETE RESTRICT,
    FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE narrative_report_slots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    narrative_report_id BIGINT UNSIGNED NOT NULL,
    narrative_slot_id BIGINT UNSIGNED NOT NULL,
    slot_key VARCHAR(128) NOT NULL,
    slot_type VARCHAR(64) NOT NULL,
    slot_version_number INT UNSIGNED NOT NULL,
    locale VARCHAR(16) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    FOREIGN KEY (narrative_report_id) REFERENCES narrative_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (narrative_slot_id) REFERENCES narrative_slots(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE job_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_key VARCHAR(128) NOT NULL UNIQUE,
    job_type ENUM('ingestion', 'validation', 'calculation', 'backtest', 'narrative_generation') NOT NULL,
    status ENUM('queued', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'queued',
    started_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    error_code VARCHAR(64) NULL,
    error_message TEXT NULL,
    metadata_json JSON NULL,
    created_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

-- Эта таблица САМА ЯВЛЯЕТСЯ каноническим реестром error_code (seed-данные из Файла 1, §5
-- ERROR MAPPING); ENUM-ограничение на её собственной колонке error_code было бы циклическим.
-- Любой error_code/validation_error_code, встречающийся где-либо ещё в системе,
-- обязан соответствовать строке этого реестра.
CREATE TABLE system_errors (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    error_key VARCHAR(128) NOT NULL UNIQUE,
    error_code VARCHAR(64) NOT NULL,
    http_status_code INT NULL,
    human_message TEXT NOT NULL,
    machine_message TEXT NOT NULL,
    remediation_hint TEXT NULL,
    context_json JSON NULL,
    created_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;

CREATE TABLE audit_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audit_key VARCHAR(128) NOT NULL UNIQUE,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_name VARCHAR(255) NULL,
    actor_role VARCHAR(64) NULL,
    event_type VARCHAR(128) NOT NULL,
    entity_type VARCHAR(128) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    old_value_json JSON NULL,
    new_value_json JSON NULL,
    diff_json JSON NULL,
    created_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs;
ФАЙЛ 4. PHILOSOPHICAL AND SCIENTIFIC INTEGRITY FOUNDATION

Версия: 1.7.4-FINAL Статус: Philosophical Truth

1. ЭПИСТЕМОЛОГИЯ ОТКАЗА ОТ ИЛЛЮЗИИ ЗНАНИЯ

Главный принцип: система не должна притворяться, что знает то, чего не поддерживают данные. MacroRisk — система детерминированной диагностики структурной устойчивости, а не механизм прогнозирования. Риск-скор — исключительно модельная оценка, а не эмпирически наблюдаемый факт экономики.

2. ПРИНЦИПЫ НАУЧНОЙ ЧЕСТНОСТИ В ТЕКСТАХ И ОТЧЁТАХ

Запрещено:

Causal Claims — причинно-следственные связи без методологического доказательства ("caused by", "model proves").
False Certainty — "гарантирует", "доказывает", "неизбежно".
Fake Precision — бэктест с числом эпизодов менее SMALL_SAMPLE_THRESHOLD_N — исключительно диагностический; агрегированные precision/recall для малых выборок запрещены и скрываются, показываются только абсолютные числа с предупреждением.
LLM Нарративы — генерация production-текстов в обход детерминированных слотов (алгоритм — Файл 1, §6.1).

Жизненный цикл проверки текстов (scientific_integrity_status): pending -> validated (автоматическая проверка запрещённых слов/XSS/ложной уверенности) -> approved (Risk Officer) ИЛИ rejected.

3. УРОК МАНИТОБЫ И ГРАНИЦЫ ИНТЕРПРЕТАЦИИ

Видимая стабильность системы не означает её внутреннее благополучие. Стабильность когорты P2 обеспечивается внешней миграцией, компенсирующей падение внутреннего естественного воспроизводства.

Запрещены публицистические/паникерские термины ("аппарат искусственного жизнеобеспечения", "крах системы"). Допустимо: "The demographic extension suggests external-replenishment dependence under the configured assumptions."
