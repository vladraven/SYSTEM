MacroRisk v1.7.3-FINAL
Amendment Package (изменения относительно v1.7.2-FINAL)

Ниже приведён набор обязательных изменений для перевода спецификации из v1.7.2-FINAL в v1.7.3-FINAL.

FILE 1. MASTER ARCHITECTURE AND TECHNICAL SPECIFICATION
Добавить раздел «Canonical Operational Constants»

Перенести в единый реестр констант следующие значения:

VALIDATION_FRESHNESS_DAYS = 30
BOOTSTRAP_SETUP_DAYS = 2
BOOTSTRAP_DRAFT_DAYS = 7
BOOTSTRAP_MINIMUM_AGE_DAYS = 14
BACKTEST_RATE_LIMIT_PER_HOUR = 5
RELEASE_LATE_TOLERANCE_DAYS = 30
DEFAULT_DETECTION_WINDOW_BEFORE_DAYS = 90
DEFAULT_DETECTION_WINDOW_AFTER_DAYS = 90
SMALL_SAMPLE_THRESHOLD = 10
Release Calendar Rules
Добавить определение Effective Release Date

Effective Release Date определяется следующим образом:

Если присутствует actual_release_date, используется actual_release_date.
Иначе если присутствует estimated_release_date, используется estimated_release_date.
Иначе используется ingestion_date.
Именно Effective Release Date используется во всех вычислениях vintage selection, revision selection и look-ahead validation.
Release Calendar State Machine
Добавить формализацию delayed → release_late

Статус delayed устанавливается если:

expected_release_date наступила;
данные отсутствуют.

Статус release_late устанавливается если:

статус delayed сохраняется более RELEASE_LATE_TOLERANCE_DAYS.
Release Status Synchronization
Заменить существующую формулировку

Вместо хранения независимого состояния в двух местах:

release_calendars является единственным источником истины.
series.validation_status = release_late является производным вычисляемым состоянием.
Ручное изменение данного состояния в таблице series запрещено.
Retry Policy
Добавить раздел

Retry Policy определяется объектом retry_policies.

Обязательные параметры:

max_retries
backoff_multiplier

После превышения max_retries состояние temporary_unavailable может быть переведено в unavailable.

Все реализации обязаны использовать одинаковую формулу расчёта повторных попыток.

Deterministic Locale Policy
Добавить раздел

Все locale:

хранятся в каноническом формате BCP-47;
регистр должен сохраняться в форме language-REGION;
пример: en-CA, fr-CA.

Любое отклонение должно нормализоваться до сохранения.

Deterministic Ordering Policy
Добавить раздел

Во всех алгоритмах:

indicator_key сортируется бинарно;
сортировка чувствительна к регистру;
одинаковый набор данных обязан формировать одинаковые хеши независимо от настроек СУБД.
Narrative Determinism
Изменить правило формирования seed

report_key не должен содержать случайных компонентов.

report_key обязан формироваться детерминированно на основании:

risk_score_result;
locale;
версии конфигурации.
FILE 2. MATHEMATICAL CORE SPECIFICATION
SYSTEM CONSTANTS
Добавить
VALIDATION_FRESHNESS_DAYS = 30
BOOTSTRAP_SETUP_DAYS = 2
BOOTSTRAP_DRAFT_DAYS = 7
BOOTSTRAP_MINIMUM_AGE_DAYS = 14
BACKTEST_RATE_LIMIT_PER_HOUR = 5
RELEASE_LATE_TOLERANCE_DAYS = 30
DEFAULT_DETECTION_WINDOW_BEFORE_DAYS = 90
DEFAULT_DETECTION_WINDOW_AFTER_DAYS = 90
SMALL_SAMPLE_THRESHOLD = 10
NORMALIZATION_EPSILON
Уточнить

NORMALIZATION_EPSILON определяется как минимальная различимая величина при SCALE.

Текущее значение должно оставаться согласованным с SCALE.

Coverage Rules
Уточнить источник истины

MINIMUM_COVERAGE_REQUIRED является единственным глобальным минимальным допустимым значением.

Поле coverage_minimum не может быть меньше MINIMUM_COVERAGE_REQUIRED.

Rounding Rules
Уточнить

Для всех положительных и отрицательных значений используется единая семантика округления Half Up.

Small Sample Rule
Заменить число 10

В тексте использовать SMALL_SAMPLE_THRESHOLD.

FILE 3. DATABASE SCHEMA CONTRACT
release_calendars.release_status
Изменить

Поле должно иметь ограниченный канонический перечень состояний:

expected
delayed
release_late
missing
revised

Произвольные значения запрещены.

release_calendars.release_date_quality
Добавить канонический перечень допустимых значений

Использование произвольных строк запрещено.

users.user_key
Зафиксировать формат

Использовать ULID как обязательный формат идентификатора.

scientific_integrity_status
Синхронизировать спецификацию

Статус validated должен быть документирован как обязательный промежуточный этап между:

pending
approved

Либо полностью удалён.

Рекомендуемый вариант: оставить и задокументировать.

risk_configuration_versions.coverage_minimum
Изменить правило

Значение по умолчанию не должно дублировать бизнес-константу.

Поле должно валидироваться относительно MINIMUM_COVERAGE_REQUIRED.

Строковые справочники
Перевести в канонические перечисления

Для следующих полей должен существовать фиксированный перечень допустимых значений:

source_type
frequency
category
report_status
job_type
status всех бизнес-сущностей
FILE 4. PHILOSOPHICAL AND SCIENTIFIC INTEGRITY FOUNDATION
Small Sample Policy
Изменить

Вместо фиксированного значения "меньше 10" использовать ссылку на SMALL_SAMPLE_THRESHOLD.

Результат

После внесения указанных изменений версия получает следующие улучшения:

устранён двойной источник истины для release_late;
устранён риск недетерминированного narrative generation;
устранён риск различной сортировки хешируемых данных;
устранены основные магические константы;
формализована логика release calendar;
формализована retry policy;
устранено дублирование coverage-правил;
повышена переносимость между окружениями;
повышена воспроизводимость расчётов и аудита.

Статус: MacroRisk v1.7.3-FINAL Ready for Implementation.
