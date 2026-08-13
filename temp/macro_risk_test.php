<?php

declare(strict_types=1);

namespace MacroRisk\Ingestion;

use Exception;
use Throwable;

/**
 * MacroRisk Интерактивный Верстак и Резолвер АПИ (Канада)
 * Спецификация v1.5.0-comprehensive
 * 
 * Автономный модуль тестирования и валидации внешних источников данных:
 *  - Statistics Canada WDS API (Векторы и Метаданные кубов)
 *  - Bank of Canada Valet REST API
 *  - Open Government Canada CKAN API (OSB Статистика Банкротств)
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['action']) && $_GET['action'] === 'resolve')) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $sourceType = (string) ($input['source_type'] ?? 'statcan_vector');
        $identifier = trim((string) ($input['identifier'] ?? ''));
        $latestN    = max(1, (int) ($input['latest_n'] ?? 3));

        if ($identifier === '') {
            throw new Exception('Идентификатор (Вектор, ID Таблицы, Серия или ID Пакета) не может быть пустым.');
        }

        $resolver = new UpstreamResolver();
        $response = $resolver->resolve($sourceType, $identifier, $latestN);

        echo json_encode([
            'success' => true,
            'data'    => $response,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'success'    => false,
            'error_code' => 'RESOLVER_ERROR',
            'message'    => $e->getMessage(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

final class UpstreamResolver
{
    private const USER_AGENT = 'MacroRisk-Canada-Resolver/1.5.0 (en-CA; Official-Audit-Engine)';

    public function resolve(string $sourceType, string $identifier, int $latestN): array
    {
        return match ($sourceType) {
            'statcan_vector' => $this->resolveStatCanVector($identifier, $latestN),
            'statcan_table'  => $this->resolveStatCanTable($identifier),
            'boc_valet'      => $this->resolveBocValet($identifier, $latestN),
            'open_gov_ckan'  => $this->resolveOpenGovCkan($identifier),
            default          => throw new Exception("Неподдерживаемый тип источника: {$sourceType}"),
        };
    }

    private function resolveStatCanVector(string $vectorsRaw, int $latestN): array
    {
        $vectorList = array_map('intval', preg_split('/[\s,]+/', $vectorsRaw));
        $vectorList = array_filter($vectorList, fn($v) => $v > 0);

        if (empty($vectorList)) {
            throw new Exception('Указан неверный ID Вектора. Требуется положительное целое число (например, 41690973).');
        }

        $url = 'https://www150.statcan.gc.ca/t1/wds/rest/getDataFromVectorsAndLatestNPeriods';
        $payloadArray = array_map(fn($vid) => [
            'vectorId' => $vid,
            'latestN'  => $latestN,
        ], array_values($vectorList));

        $jsonPayload = json_encode($payloadArray, JSON_PRETTY_PRINT);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $httpResult = $this->executeCurl($url, 'POST', $jsonPayload, $headers);
        $decoded = json_decode($httpResult['body'], true);

        $phpSnippet = $this->generateVectorPhpSnippet($vectorList, $latestN);

        return [
            'source'           => 'Statistics Canada (WDS Векторы)',
            'endpoint_url'     => $url,
            'http_method'      => 'POST',
            'request_headers'  => $headers,
            'request_payload'  => $payloadArray,
            'http_status'      => $httpResult['status'],
            'response_raw'     => $decoded ?? $httpResult['body'],
            'php_code_snippet' => $phpSnippet,
        ];
    }

    private function resolveStatCanTable(string $tableId): array
    {
        $cleanPid = preg_replace('/[^0-9]/', '', $tableId);
        if (strlen($cleanPid) === 10) {
            $cleanPid = substr($cleanPid, 0, 8);
        }

        $url = "https://www150.statcan.gc.ca/t1/wds/rest/getCubeMetadata";
        $payloadArray = [['productId' => (int) $cleanPid]];
        $jsonPayload = json_encode($payloadArray, JSON_PRETTY_PRINT);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $httpResult = $this->executeCurl($url, 'POST', $jsonPayload, $headers);
        $decoded = json_decode($httpResult['body'], true);

        $phpSnippet = $this->generateTablePhpSnippet($cleanPid);

        return [
            'source'           => 'Statistics Canada (Метаданные Куба Таблицы)',
            'endpoint_url'     => $url,
            'http_method'      => 'POST',
            'request_headers'  => $headers,
            'request_payload'  => $payloadArray,
            'http_status'      => $httpResult['status'],
            'response_raw'     => $decoded ?? $httpResult['body'],
            'php_code_snippet' => $phpSnippet,
        ];
    }

    private function resolveBocValet(string $seriesName, int $recentCount): array
    {
        $cleanSeries = trim($seriesName);
        $url = sprintf('https://www.bankofcanada.ca/valet/observations/%s/json?recent=%d', urlencode($cleanSeries), $recentCount);
        $headers = ['Accept: application/json'];

        $httpResult = $this->executeCurl($url, 'GET', null, $headers);
        $decoded = json_decode($httpResult['body'], true);

        $phpSnippet = $this->generateBocPhpSnippet($cleanSeries, $recentCount);

        return [
            'source'           => 'Bank of Canada Valet API',
            'endpoint_url'     => $url,
            'http_method'      => 'GET',
            'request_headers'  => $headers,
            'request_payload'  => null,
            'http_status'      => $httpResult['status'],
            'response_raw'     => $decoded ?? $httpResult['body'],
            'php_code_snippet' => $phpSnippet,
        ];
    }

    private function resolveOpenGovCkan(string $packageId): array
    {
        $cleanPackage = trim($packageId) ?: '7da3f820-2f22-482f-8700-1a13e5124190';
        $url = 'https://open.canada.ca/data/api/3/action/package_show?id=' . urlencode($cleanPackage);
        $headers = ['Accept: application/json'];

        $httpResult = $this->executeCurl($url, 'GET', null, $headers);
        $decoded = json_decode($httpResult['body'], true);

        $phpSnippet = $this->generateOpenGovPhpSnippet($cleanPackage);

        return [
            'source'           => 'Open Government Canada (CKAN)',
            'endpoint_url'     => $url,
            'http_method'      => 'GET',
            'request_headers'  => $headers,
            'request_payload'  => null,
            'http_status'      => $httpResult['status'],
            'response_raw'     => $decoded ?? $httpResult['body'],
            'php_code_snippet' => $phpSnippet,
        ];
    }

    private function executeCurl(string $url, string $method, ?string $payload, array $headers): array
    {
        $ch = curl_init();

        $defaultHeaders = ['User-Agent: ' . self::USER_AGENT];
        $allHeaders = array_merge($defaultHeaders, $headers);

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => $allHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new Exception("cURL Ошибка транспорта ({$errno}): {$error}");
        }

        return [
            'status' => $httpCode,
            'body'   => (string) $body,
        ];
    }

    private function generateVectorPhpSnippet(array $vectors, int $latestN): string
    {
        $vListStr = implode(', ', $vectors);
        return <<<PHP
// Валидированный запрос векторов Statistics Canada WDS
\$url = 'https://www150.statcan.gc.ca/t1/wds/rest/getDataFromVectorsAndLatestNPeriods';
\$payload = json_encode([
    ['vectorId' => {$vectors[0]}, 'latestN' => {$latestN}]
]);

\$ch = curl_init(\$url);
curl_setopt_array(\$ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => \$payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
]);
\$response = curl_exec(\$ch);
\$httpCode = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);
curl_close(\$ch);
PHP;
    }

    private function generateTablePhpSnippet(string $pid): string
    {
        return <<<PHP
// Валидированный запрос метаданных куба Statistics Canada
\$url = 'https://www150.statcan.gc.ca/t1/wds/rest/getCubeMetadata';
\$payload = json_encode([['productId' => {$pid}]]);

\$ch = curl_init(\$url);
curl_setopt_array(\$ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => \$payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
]);
\$response = curl_exec(\$ch);
curl_close(\$ch);
PHP;
    }

    private function generateBocPhpSnippet(string $series, int $recent): string
    {
        return <<<PHP
// Валидированный запрос серий Bank of Canada Valet
\$url = 'https://www.bankofcanada.ca/valet/observations/{$series}/json?recent={$recent}';

\$ch = curl_init(\$url);
curl_setopt_array(\$ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);
\$response = curl_exec(\$ch);
\$httpCode = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);
curl_close(\$ch);
PHP;
    }

    private function generateOpenGovPhpSnippet(string $packageId): string
    {
        return <<<PHP
// Валидированный запрос метаданных пакета Open Government CKAN
\$url = 'https://open.canada.ca/data/api/3/action/package_show?id={$packageId}';

\$ch = curl_init(\$url);
curl_setopt_array(\$ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);
\$response = curl_exec(\$ch);
curl_close(\$ch);
PHP;
    }
}

?>
<!DOCTYPE html>
<html lang="ru" class="h-full bg-slate-900 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MacroRisk Canada — Отладчик и Резолвер АПИ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        pre, code { font-family: 'JetBrains Mono', monospace; }
    </style>
</head>
<body class="h-full flex flex-col">

    <!-- Шапка страницы -->
    <header class="bg-slate-950 border-b border-slate-800 px-6 py-4 flex items-center justify-between shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-lg bg-red-600/20 border border-red-500/30 flex items-center justify-center text-red-500 font-bold text-lg">
                🍁
            </div>
            <div>
                <h1 class="font-bold text-slate-100 text-base leading-tight">MacroRisk Canada — Отладчик Источников Данных</h1>
                <p class="text-xs text-slate-400">Спецификация v1.5.0-comprehensive • Пошаговая валидация АПИ</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 mr-1.5 animate-pulse"></span>
                Официальные API Подключены
            </span>
        </div>
    </header>

    <!-- Основной интерфейс -->
    <main class="flex-1 flex overflow-hidden">

        <!-- Левая панель управления -->
        <aside class="w-96 border-r border-slate-800 bg-slate-950/50 p-6 flex flex-col gap-6 overflow-y-auto shrink-0">

            <!-- Пресеты индикаторов -->
            <div>
                <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">
                    <i class="fa-solid fa-list-check text-slate-500 mr-1"></i> Готовые пресеты MacroRisk
                </label>
                <select id="presetSelect" onchange="applyPreset()" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-red-500 transition">
                    <option value="">-- Выберите индикатор --</option>

                    <optgroup label="1. Макро и Инфляция (StatCan)">
                        <option value="statcan_vector|41690973|3">Индекс потребительских цен CPI (Вектор v41690973)</option>
                        <option value="statcan_vector|2062815|3">Уровень безработицы Unemployment (Вектор v2062815)</option>
                        <option value="statcan_table|36-10-0206-01|0">Индекс производительности труда (Таблица 36-10-0206-01)</option>
                    </optgroup>

                    <optgroup label="2. Финансы и Ликвидность (Bank of Canada)">
                        <option value="boc_valet|FXCADUSD|5">Курс CAD/USD (FXCADUSD)</option>
                        <option value="boc_valet|V80691311|5">Доходность 10-летних бондов (V80691311)</option>
                        <option value="boc_valet|V80691307|5">Доходность 2-летних бондов (V80691307)</option>
                        <option value="boc_valet|V39079|5">Ключевая процентная ставка (V39079)</option>
                    </optgroup>

                    <optgroup label="3. Недвижимость и Долг (StatCan)">
                        <option value="statcan_vector|41692452|3">Индекс цен на новое жилье NHPI (v41692452)</option>
                        <option value="statcan_table|18-10-0205-01|0">Метаданные таблицы NHPI (18-10-0205-01)</option>
                        <option value="statcan_table|38-10-0238-01|0">Таблица обслуживания долга DSR (38-10-0238-01)</option>
                    </optgroup>

                    <optgroup label="4. Открытые Данные (OSB Банкротства)">
                        <option value="open_gov_ckan|746709f1-c729-44a1-ba84-7be5eadd3664|0">Пакет статистики банкротств OSB</option>
                    </optgroup>
                </select>
            </div>

            <hr class="border-slate-800">

            <!-- Форма ручного ввода -->
            <form id="resolveForm" onsubmit="handleResolve(event)" class="flex flex-col gap-4">

                <!-- Выбор провайдера -->
                <div>
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">
                        Целевой АПИ Провайдер
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="flex items-center gap-2 p-2.5 rounded-lg border border-slate-800 bg-slate-900 cursor-pointer hover:border-slate-700 transition">
                            <input type="radio" name="source_type" value="statcan_vector" checked onchange="updateFormPlaceholders()" class="accent-red-500">
                            <span class="text-xs font-medium text-slate-200">StatCan Вектор</span>
                        </label>
                        <label class="flex items-center gap-2 p-2.5 rounded-lg border border-slate-800 bg-slate-900 cursor-pointer hover:border-slate-700 transition">
                            <input type="radio" name="source_type" value="statcan_table" onchange="updateFormPlaceholders()" class="accent-red-500">
                            <span class="text-xs font-medium text-slate-200">StatCan Таблица</span>
                        </label>
                        <label class="flex items-center gap-2 p-2.5 rounded-lg border border-slate-800 bg-slate-900 cursor-pointer hover:border-slate-700 transition">
                            <input type="radio" name="source_type" value="boc_valet" onchange="updateFormPlaceholders()" class="accent-red-500">
                            <span class="text-xs font-medium text-slate-200">BoC Valet API</span>
                        </label>
                        <label class="flex items-center gap-2 p-2.5 rounded-lg border border-slate-800 bg-slate-900 cursor-pointer hover:border-slate-700 transition">
                            <input type="radio" name="source_type" value="open_gov_ckan" onchange="updateFormPlaceholders()" class="accent-red-500">
                            <span class="text-xs font-medium text-slate-200">Open Gov CKAN</span>
                        </label>
                    </div>
                </div>

                <!-- Ввод идентификатора -->
                <div>
                    <label id="identifierLabel" class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">
                        ID Вектора
                    </label>
                    <input type="text" id="identifierInput" value="41690973" required
                           class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-100 focus:outline-none focus:border-red-500 font-mono transition">
                    <p id="identifierHelp" class="text-[11px] text-slate-500 mt-1">Номера векторов через запятую (например, 41690973, 2062815)</p>
                </div>

                <!-- Количество периодов -->
                <div id="latestNGroup">
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">
                        Количество последних периодов (latestN)
                    </label>
                    <input type="number" id="latestNInput" value="3" min="1" max="50"
                           class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-100 focus:outline-none focus:border-red-500 font-mono transition">
                </div>

                <!-- Кнопка отправки -->
                <button type="submit" id="btnResolve" class="w-full bg-red-600 hover:bg-red-500 text-white font-semibold py-2.5 rounded-lg text-sm transition shadow-lg shadow-red-600/20 flex items-center justify-center gap-2">
                    <i class="fa-solid fa-bolt"></i> ВЫПОЛНИТЬ ЗАПРОС (RESOLVE)
                </button>

            </form>
        </aside>

        <!-- Рабочая область результатов -->
        <section class="flex-1 bg-slate-900 p-6 flex flex-col gap-6 overflow-y-auto">

            <!-- Заглушка (Начальное состояние) -->
            <div id="emptyState" class="flex-1 border-2 border-dashed border-slate-800 rounded-xl flex flex-col items-center justify-center text-center p-8">
                <div class="w-16 h-16 rounded-full bg-slate-800/80 flex items-center justify-center text-slate-500 text-2xl mb-4">
                    <i class="fa-solid fa-network-wired"></i>
                </div>
                <h2 class="text-slate-300 font-semibold text-lg">Выберите пресет или введите ID таблицы/вектора</h2>
                <p class="text-slate-500 text-sm max-w-md mt-1">Нажмите "ВЫПОЛНИТЬ ЗАПРОС" для отправки живого cURL-запроса, проверки HTTP-кода и генерации готового PHP-кода.</p>
            </div>

            <!-- Индикатор загрузки -->
            <div id="loadingState" class="hidden flex-1 border border-slate-800 rounded-xl bg-slate-950/40 flex flex-col items-center justify-center p-8">
                <div class="w-10 h-10 border-4 border-slate-700 border-t-red-500 rounded-full animate-spin mb-4"></div>
                <p class="text-slate-300 text-sm font-medium">Отправка запроса к официальному АПИ...</p>
                <p id="loadingTarget" class="text-xs text-slate-500 font-mono mt-1"></p>
            </div>

            <!-- Просмотр ответа -->
            <div id="resultView" class="hidden flex flex-col gap-6">

                <!-- Мета-панель HTTP -->
                <div class="bg-slate-950 border border-slate-800 rounded-xl p-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span id="httpStatusBadge" class="px-3 py-1 rounded-md text-xs font-bold font-mono">
                            --
                        </span>
                        <div>
                            <span id="httpMethodLabel" class="text-xs font-mono font-bold text-slate-400 mr-2">GET</span>
                            <span id="endpointUrlLabel" class="text-xs font-mono text-slate-200">https://...</span>
                        </div>
                    </div>
                    <button onclick="copySnippet()" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs rounded-lg font-medium transition flex items-center gap-1.5">
                        <i class="fa-regular fa-copy"></i> Скопировать PHP Код
                    </button>
                </div>

                <!-- Вкладки результатов -->
                <div class="grid grid-cols-2 gap-6">

                    <!-- Таб 1: Форматированный JSON -->
                    <div class="bg-slate-950 border border-slate-800 rounded-xl flex flex-col overflow-hidden">
                        <div class="bg-slate-900/80 px-4 py-2.5 border-b border-slate-800 flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">
                                <i class="fa-solid fa-code text-slate-500 mr-1"></i> Полученный JSON Ответ API
                            </span>
                            <span class="text-[11px] text-slate-500 font-mono">application/json</span>
                        </div>
                        <pre id="jsonViewer" class="p-4 text-xs font-mono text-emerald-400 overflow-x-auto max-h-[450px] leading-relaxed"></pre>
                    </div>

                    <!-- Таб 2: Валидированный PHP фрагмент -->
                    <div class="bg-slate-950 border border-slate-800 rounded-xl flex flex-col overflow-hidden">
                        <div class="bg-slate-900/80 px-4 py-2.5 border-b border-slate-800 flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">
                                <i class="fa-brands fa-php text-blue-400 mr-1"></i> Валидированный PHP Фрагмент Запроса
                            </span>
                            <span class="text-[11px] text-slate-500">Код для парсера MacroRisk</span>
                        </div>
                        <pre id="phpSnippetViewer" class="p-4 text-xs font-mono text-blue-300 overflow-x-auto max-h-[450px] leading-relaxed"></pre>
                    </div>

                </div>

            </div>

        </section>

    </main>

    <!-- JS Логика -->
    <script>
        function applyPreset() {
            const val = document.getElementById('presetSelect').value;
            if (!val) return;

            const [sourceType, identifier, latestN] = val.split('|');

            const radios = document.getElementsByName('source_type');
            radios.forEach(r => { r.checked = (r.value === sourceType); });

            updateFormPlaceholders();

            document.getElementById('identifierInput').value = identifier;
            if (latestN !== "0") {
                document.getElementById('latestNInput').value = latestN;
            }
        }

        function updateFormPlaceholders() {
            const sourceType = document.querySelector('input[name="source_type"]:checked').value;
            const label = document.getElementById('identifierLabel');
            const input = document.getElementById('identifierInput');
            const help = document.getElementById('identifierHelp');
            const latestGroup = document.getElementById('latestNGroup');

            if (sourceType === 'statcan_vector') {
                label.innerText = "ID Вектора(ов)";
                input.placeholder = "41690973";
                help.innerText = "Номера векторов через запятую (например, 41690973, 2062815)";
                latestGroup.classList.remove('hidden');
            } else if (sourceType === 'statcan_table') {
                label.innerText = "ID Таблицы (PID)";
                input.placeholder = "18-10-0205-01";
                help.innerText = "Формат ID таблицы StatCan (например, 18-10-0205-01 или 18100205)";
                latestGroup.classList.add('hidden');
            } else if (sourceType === 'boc_valet') {
                label.innerText = "Имя серии Valet";
                input.placeholder = "FXCADUSD";
                help.innerText = "Имя серии Bank of Canada Valet (например, FXCADUSD, V80691311)";
                latestGroup.classList.remove('hidden');
            } else if (sourceType === 'open_gov_ckan') {
                label.innerText = "UUID Пакета CKAN";
                input.placeholder = "746709f1-c729-44a1-ba84-7be5eadd3664";
                help.innerText = "UUID набора данных Open Gov";
                latestGroup.classList.add('hidden');
            }
        }

        async function handleResolve(e) {
            e.preventDefault();

            const sourceType = document.querySelector('input[name="source_type"]:checked').value;
            const identifier = document.getElementById('identifierInput').value.trim();
            const latestN = parseInt(document.getElementById('latestNInput').value) || 3;

            document.getElementById('emptyState').classList.add('hidden');
            document.getElementById('resultView').classList.add('hidden');
            document.getElementById('loadingState').classList.remove('hidden');
            document.getElementById('loadingTarget').innerText = `${sourceType} :: ${identifier}`;

            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        source_type: sourceType,
                        identifier: identifier,
                        latest_n: latestN
                    })
                });

                const result = await response.json();

                if (!result.success) {
                    throw new Error(result.message || 'Ошибка при получении данных.');
                }

                renderResult(result.data);
            } catch (err) {
                alert("Ошибка: " + err.message);
                document.getElementById('emptyState').classList.remove('hidden');
            } finally {
                document.getElementById('loadingState').classList.add('hidden');
            }
        }

        function renderResult(data) {
            document.getElementById('resultView').classList.remove('hidden');

            const badge = document.getElementById('httpStatusBadge');
            badge.innerText = `HTTP ${data.http_status}`;
            if (data.http_status >= 200 && data.http_status < 300) {
                badge.className = "px-3 py-1 rounded-md text-xs font-bold font-mono bg-emerald-500/10 text-emerald-400 border border-emerald-500/20";
            } else {
                badge.className = "px-3 py-1 rounded-md text-xs font-bold font-mono bg-red-500/10 text-red-400 border border-red-500/20";
            }

            document.getElementById('httpMethodLabel').innerText = data.http_method;
            document.getElementById('endpointUrlLabel').innerText = data.endpoint_url;

            document.getElementById('jsonViewer').innerText = JSON.stringify(data.response_raw, null, 2);
            document.getElementById('phpSnippetViewer').innerText = data.php_code_snippet;
        }

        function copySnippet() {
            const snippetText = document.getElementById('phpSnippetViewer').innerText;
            if (!snippetText) return;

            const textarea = document.createElement('textarea');
            textarea.value = snippetText;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);

            alert('PHP фрагмент скопирован в буфер обмена!');
        }
    </script>
</body>
</html>