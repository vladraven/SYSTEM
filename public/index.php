<?php

declare(strict_types=1);

/* STREAMING_CHUNK:Activating output buffering to prevent PHP warnings from contaminating JSON responses... */

// Start output buffering to catch any stray warnings or errors
ob_start();

// Handle AJAX / API endpoints cleanly
if (isset($_GET['action'])) {
    // Clear any previous output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');

    try {
        require_once __DIR__ . '/../bootstrap.php';

        $controller = new \MacroRisk\Controller\DashboardController();
        $action = $_GET['action'];

        if ($action === 'verify_worked_example') {
            $result = $controller->verifyWorkedExample();
            $controller->renderJson(['success' => true, 'data' => $result]);
            exit;
        }

        if ($action === 'calculate_custom_risk') {
            $rawInput = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $observations = $rawInput['observations'] ?? [];
            $mode = (string)($rawInput['mode'] ?? 'production');

            $result = $controller->calculateRisk($observations, \MacroRisk\Config\SystemPreset::DEFAULT_VERSION_ID, $mode);
            $controller->renderJson(['success' => true, 'data' => $result]);
            exit;
        }

        if ($action === 'screen_text') {
            $rawInput = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $text = (string)($rawInput['text'] ?? '');

            $guard = new \MacroRisk\Core\Security\ScientificIntegrityGuard();
            $guard->screen($text);

            $controller->renderJson(['success' => true, 'message' => 'Text passed Scientific Integrity check without violations.']);
            exit;
        }

        if ($action === 'run_migrations') {
            $logs = \MacroRisk\NativeMigrator::runMigrations();
            $controller->renderJson(['success' => true, 'message' => 'Database migrations completed successfully.', 'logs' => $logs]);
            exit;
        }

        if ($action === 'get_audit_logs') {
            $pdo = \MacroRisk\Database::getConnection();
            $stmt = $pdo->query("SELECT * FROM audit_records ORDER BY id DESC LIMIT 50");
            $records = $stmt->fetchAll();
            $controller->renderJson(['success' => true, 'data' => $records]);
            exit;
        }

        throw new Exception("Unknown action: {$action}");
    } catch (\Throwable $e) {
        http_response_code(200); // Return HTTP 200 with JSON error details
        echo json_encode([
            'success'    => false,
            'error_code' => method_exists($e, 'getErrorCode') ? $e->getErrorCode() : 'SYSTEM_ERROR',
            'message'    => $e->getMessage(),
            'file'       => basename($e->getFile()),
            'line'       => $e->getLine()
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/* STREAMING_CHUNK:Bootstrapping dashboard interface for browser requests... */

try {
    require_once __DIR__ . '/../bootstrap.php';
} catch (\Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo "<div style='font-family:sans-serif; padding:20px; background:#0f172a; color:#f87171;'>";
    echo "<h1>MacroRisk Engine Initialisation Error</h1>";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . " (Line " . $e->getLine() . ")</p>";
    echo "</div>";
    exit;
}

?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MacroRisk Canada — Risk Monitoring Engine</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        pre, code, .font-mono { font-family: 'JetBrains Mono', monospace; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(15, 23, 42, 0.6); }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
    </style>
</head>
<body class="h-full flex flex-col bg-slate-950 text-slate-100 custom-scrollbar">

    <!-- Header Navigation -->
    <header class="bg-slate-900/90 border-b border-slate-800 px-6 py-4 flex items-center justify-between shrink-0 sticky top-0 z-50 backdrop-blur-md">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-red-600 to-amber-600 flex items-center justify-center text-white font-bold text-xl shadow-lg shadow-red-600/20">
                🍁
            </div>
            <div>
                <h1 class="font-bold text-slate-100 text-lg leading-tight flex items-center gap-2">
                    MacroRisk Engine <span class="text-xs px-2 py-0.5 rounded bg-red-500/10 text-red-400 border border-red-500/20 font-mono">v1.5.0-comprehensive</span>
                </h1>
                <p class="text-xs text-slate-400">Pure PHP Native • Deterministic Macroeconomic Monitoring</p>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button onclick="runDatabaseMigrations()" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-lg text-xs font-semibold transition border border-slate-700 flex items-center gap-2">
                <i class="fa-solid fa-database text-amber-500"></i> Run Migrations
            </button>
            <button onclick="runWorkedExampleVerification()" class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white font-semibold rounded-lg text-xs transition shadow-lg shadow-red-600/20 flex items-center gap-2">
                <i class="fa-solid fa-vial"></i> Verify Appendix A Example
            </button>
        </div>
    </header>

    <!-- Tab Navigation -->
    <div class="bg-slate-900 border-b border-slate-800 px-6 py-3 flex items-center justify-between shrink-0">
        <nav class="flex gap-2">
            <button onclick="switchTab('calculator')" id="tabBtn-calculator" class="px-4 py-2 rounded-lg text-xs font-semibold transition flex items-center gap-2 bg-red-600/20 text-red-400 border border-red-500/30">
                <i class="fa-solid fa-calculator"></i> 1. Risk Engine & Calculation
            </button>
            <button onclick="switchTab('integrity')" id="tabBtn-integrity" class="px-4 py-2 rounded-lg text-xs font-semibold transition flex items-center gap-2 bg-slate-800/50 text-slate-400 hover:bg-slate-800 border border-transparent">
                <i class="fa-solid fa-shield-halved"></i> 2. ScientificIntegrity Guard
            </button>
            <button onclick="switchTab('audit')" id="tabBtn-audit" class="px-4 py-2 rounded-lg text-xs font-semibold transition flex items-center gap-2 bg-slate-800/50 text-slate-400 hover:bg-slate-800 border border-transparent">
                <i class="fa-solid fa-list-check"></i> 3. Database Audit Records
            </button>
        </nav>

        <div class="flex items-center gap-3 text-xs font-mono text-slate-400">
            <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-400"></span> BCMath Active</span>
            <span class="text-slate-700">|</span>
            <span>Scale: DECIMAL(10,4)</span>
        </div>
    </div>

    <!-- Main Content -->
    <main class="flex-1 overflow-hidden relative p-6">

        <!-- Tab 1: Risk Calculator -->
        <section id="tab-calculator" class="h-full flex flex-col gap-6 overflow-y-auto">
            <div class="grid grid-cols-12 gap-6">
                <!-- Left Input Panel -->
                <div class="col-span-5 bg-slate-900 border border-slate-800 rounded-xl p-5 flex flex-col gap-4">
                    <h2 class="text-sm font-bold text-slate-200 uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-sliders text-red-500"></i> Macroeconomic Indicator Snapshot
                    </h2>
                    <p class="text-xs text-slate-400">Values are parsed strictly as DECIMAL(24,8) without float conversion.</p>

                    <form id="calculatorForm" onsubmit="calculateCustomRisk(event)" class="flex flex-col gap-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Household DSR (%) [Required]</label>
                            <input type="text" id="input_dsr" value="15.20000000" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-1.5 text-xs font-mono text-slate-100 focus:outline-none focus:border-red-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Housing Starts / Pop Ratio</label>
                            <input type="text" id="input_housing" value="4.50000000" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-1.5 text-xs font-mono text-slate-100 focus:outline-none focus:border-red-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Yield Spread (10Y-2Y) (%)</label>
                            <input type="text" id="input_yield" value="-0.50000000" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-1.5 text-xs font-mono text-slate-100 focus:outline-none focus:border-red-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Labor Productivity Index</label>
                            <input type="text" id="input_productivity" value="104.00000000" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-1.5 text-xs font-mono text-slate-100 focus:outline-none focus:border-red-500">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-300 mb-1">Business Insolvencies YoY (%)</label>
                            <input type="text" id="input_insolvencies" value="25.00000000" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-1.5 text-xs font-mono text-slate-100 focus:outline-none focus:border-red-500">
                        </div>

                        <button type="submit" class="mt-2 w-full bg-red-600 hover:bg-red-500 text-white font-semibold py-2 rounded-lg text-xs transition shadow-lg shadow-red-600/20">
                            Calculate Risk Score
                        </button>
                    </form>
                </div>

                <!-- Right Calculation Results View -->
                <div class="col-span-7 bg-slate-900 border border-slate-800 rounded-xl p-5 flex flex-col gap-4">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <h2 class="text-sm font-bold text-slate-200 uppercase tracking-wider">Risk Score Output</h2>
                        <span id="scoreBandBadge" class="px-2.5 py-1 rounded text-xs font-bold font-mono bg-slate-800 text-slate-400">
                            AWAITING CALCULATION
                        </span>
                    </div>

                    <div class="flex items-baseline gap-3">
                        <span id="scoreValueDisplay" class="text-4xl font-extrabold font-mono text-slate-100">0.0000</span>
                        <span class="text-xs text-slate-400 font-mono">/ 100.0000</span>
                        <span id="coverageDisplay" class="ml-auto text-xs font-mono text-emerald-400">Coverage: 0.0000%</span>
                    </div>

                    <!-- Indicator Contributions Table -->
                    <div class="overflow-x-auto mt-2">
                        <table class="w-full text-left text-xs font-mono border-collapse">
                            <thead>
                                <tr class="bg-slate-950 text-slate-400 border-b border-slate-800">
                                    <th class="p-2">Indicator</th>
                                    <th class="p-2">Raw Val</th>
                                    <th class="p-2">Norm Score</th>
                                    <th class="p-2">Eff Weight</th>
                                    <th class="p-2">Contrib</th>
                                </tr>
                            </thead>
                            <tbody id="contributionsTableBody" class="divide-y divide-slate-800/60 text-slate-300">
                                <tr>
                                    <td colspan="5" class="p-4 text-center text-slate-500 italic">Click "Calculate Risk Score" or "Verify Appendix A Example"</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- Tab 2: Scientific Integrity Guard -->
        <section id="tab-integrity" class="hidden h-full flex flex-col gap-6 overflow-y-auto">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 flex flex-col gap-4">
                <h2 class="text-sm font-bold text-slate-200 uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-shield-halved text-red-500"></i> ScientificIntegrityGuard Phrase Screener
                </h2>
                <p class="text-xs text-slate-400">Test text for spec violations. Prohibited phrases (e.g. "will enter recession", "kondratiev", "proves that") trigger HTTP 422 block.</p>

                <div class="flex flex-col gap-3">
                    <textarea id="integrityTestText" rows="4" class="w-full bg-slate-950 border border-slate-700 rounded-lg p-3 text-xs font-mono text-slate-100 focus:outline-none focus:border-red-500" placeholder="Type text to screen..."></textarea>
                    
                    <button onclick="testScientificIntegrity()" class="self-start px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold rounded-lg transition border border-slate-700">
                        Screen Text
                    </button>
                </div>

                <div id="integrityResultBox" class="hidden p-4 rounded-lg border text-xs font-mono"></div>
            </div>
        </section>

        <!-- Tab 3: Database Audit Records -->
        <section id="tab-audit" class="hidden h-full flex flex-col gap-6 overflow-y-auto">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 flex flex-col gap-4">
                <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                    <h2 class="text-sm font-bold text-slate-200 uppercase tracking-wider">Database Audit Log</h2>
                    <button onclick="loadAuditLogs()" class="px-3 py-1 bg-slate-800 text-xs text-slate-300 rounded hover:bg-slate-700">Refresh</button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs font-mono border-collapse">
                        <thead>
                            <tr class="bg-slate-950 text-slate-400 border-b border-slate-800">
                                <th class="p-2">Audit Key</th>
                                <th class="p-2">Event</th>
                                <th class="p-2">Entity</th>
                                <th class="p-2">Actor</th>
                                <th class="p-2">Created At</th>
                            </tr>
                        </thead>
                        <tbody id="auditLogsTableBody" class="divide-y divide-slate-800/60 text-slate-300">
                            <tr>
                                <td colspan="5" class="p-4 text-center text-slate-500">Click Refresh to load audit records from MySQL.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

    </main>

    <!-- Dashboard JS Engine with Diagnostic Fetch Error Handling -->
    <script>
        function switchTab(tabKey) {
            ['calculator', 'integrity', 'audit'].forEach(t => {
                const sec = document.getElementById(`tab-${t}`);
                const btn = document.getElementById(`tabBtn-${t}`);
                if (t === tabKey) {
                    sec.classList.remove('hidden');
                    btn.className = "px-4 py-2 rounded-lg text-xs font-semibold transition flex items-center gap-2 bg-red-600/20 text-red-400 border border-red-500/30";
                } else {
                    sec.classList.add('hidden');
                    btn.className = "px-4 py-2 rounded-lg text-xs font-semibold transition flex items-center gap-2 bg-slate-800/50 text-slate-400 hover:bg-slate-800 border border-transparent";
                }
            });
            if (tabKey === 'audit') loadAuditLogs();
        }

        async function safeFetch(url, options = {}) {
            const resp = await fetch(url, options);
            const rawText = await resp.text();
            
            try {
                return JSON.parse(rawText);
            } catch (e) {
                throw new Error("Server returned non-JSON response (" + resp.status + "): " + rawText.substring(0, 300));
            }
        }

        async function runWorkedExampleVerification() {
            try {
                const json = await safeFetch('index.php?action=verify_worked_example');
                if (json.success) {
                    renderCalculationResults(json.data);
                    alert(`Appendix A Verification: ${json.data.status}! Computed Score: ${json.data.calculated_score} (Expected 72.3415)`);
                } else {
                    alert(`Verification Error: ${json.message}`);
                }
            } catch (e) {
                alert(e.message);
            }
        }

        async function calculateCustomRisk(e) {
            e.preventDefault();
            const obs = {
                debt_service_ratio: document.getElementById('input_dsr').value,
                housing_starts: document.getElementById('input_housing').value,
                bond_yield_10y: document.getElementById('input_yield').value,
                labor_productivity: document.getElementById('input_productivity').value,
                business_insolvencies: document.getElementById('input_insolvencies').value,
            };

            try {
                const json = await safeFetch('index.php?action=calculate_custom_risk', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ observations: obs, mode: 'production' })
                });
                if (!json.success) throw new Error(json.message);
                renderCalculationResults(json.data);
            } catch (err) {
                alert("Calculation Error: " + err.message);
            }
        }

        function renderCalculationResults(data) {
            document.getElementById('scoreValueDisplay').innerText = data.risk_score || data.calculated_score;
            document.getElementById('coverageDisplay').innerText = `Coverage: ${data.coverage_ratio}%`;
            
            const badge = document.getElementById('scoreBandBadge');
            const band = (data.risk_band || 'UNKNOWN').toUpperCase();
            badge.innerText = `BAND: ${band}`;
            
            if (band === 'HIGH' || band === 'CRITICAL') {
                badge.className = "px-2.5 py-1 rounded text-xs font-bold font-mono bg-red-500/10 text-red-400 border border-red-500/20";
            } else {
                badge.className = "px-2.5 py-1 rounded text-xs font-bold font-mono bg-emerald-500/10 text-emerald-400 border border-emerald-500/20";
            }

            const tbody = document.getElementById('contributionsTableBody');
            let rows = '';
            const contribs = data.contributions || {};

            for (const [k, c] of Object.entries(contribs)) {
                rows += `
                    <tr class="hover:bg-slate-800/40">
                        <td class="p-2 font-semibold text-slate-200">${k}</td>
                        <td class="p-2 font-mono text-slate-400">${c.raw_value || '-'}</td>
                        <td class="p-2 font-mono text-amber-400">${c.normalized_indicator_score || '-'}</td>
                        <td class="p-2 font-mono text-slate-300">${c.effective_weight || '-'}%</td>
                        <td class="p-2 font-mono font-bold text-emerald-400">${c.contribution_value || '-'}</td>
                    </tr>
                `;
            }

            tbody.innerHTML = rows;
        }

        async function testScientificIntegrity() {
            const text = document.getElementById('integrityTestText').value;
            const box = document.getElementById('integrityResultBox');
            box.classList.remove('hidden');

            try {
                const json = await safeFetch('index.php?action=screen_text', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ text: text })
                });
                
                if (json.success) {
                    box.className = "p-4 rounded-lg border text-xs font-mono bg-emerald-500/10 text-emerald-400 border-emerald-500/20";
                    box.innerText = "[PASSED]: " + json.message;
                } else {
                    box.className = "p-4 rounded-lg border text-xs font-mono bg-red-500/10 text-red-400 border-red-500/20";
                    box.innerText = `[BLOCKED (${json.error_code})]: ${json.message}`;
                }
            } catch (e) {
                box.className = "p-4 rounded-lg border text-xs font-mono bg-red-500/10 text-red-400 border-red-500/20";
                box.innerText = "[ERROR]: " + e.message;
            }
        }

        async function runDatabaseMigrations() {
            try {
                const json = await safeFetch('index.php?action=run_migrations');
                alert(json.message);
            } catch (e) {
                alert("Migration Error: " + e.message);
            }
        }

        async function loadAuditLogs() {
            try {
                const json = await safeFetch('index.php?action=get_audit_logs');
                const tbody = document.getElementById('auditLogsTableBody');
                
                if (!json.success || !json.data.length) {
                    tbody.innerHTML = '<tr><td colspan="5" class="p-4 text-center text-slate-500">No audit records found.</td></tr>';
                    return;
                }

                let rows = '';
                json.data.forEach(r => {
                    rows += `
                        <tr class="hover:bg-slate-800/40">
                            <td class="p-2 text-slate-400 font-mono">${r.audit_key}</td>
                            <td class="p-2 text-emerald-400 font-semibold">${r.event_type}</td>
                            <td class="p-2 text-slate-300">${r.entity_type} (#${r.entity_id || '-'})</td>
                            <td class="p-2 text-slate-400">${r.actor_name}</td>
                            <td class="p-2 text-slate-500 font-mono">${r.created_at}</td>
                        </tr>
                    `;
                });
                tbody.innerHTML = rows;
            } catch (e) {
                console.error(e);
            }
        }
    </script>
</body>
</html>