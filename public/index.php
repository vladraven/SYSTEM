<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'MacroRisk\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$action = $_GET['action'] ?? 'dashboard';
$controller = new \MacroRisk\Controller\DashboardController();

switch ($action) {
    case 'dashboard':
        require __DIR__ . '/../views/dashboard.php';
        break;
    case 'help':
        require __DIR__ . '/../views/help.php';
        break;
    case 'scenario':
        $controller->handleScenario();
        break;
    case 'ingest':
        $controller->handleIngest();
        break;
    default:
        http_response_code(404);
        echo 'Not found';
}
