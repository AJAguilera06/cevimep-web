<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

header("Content-Type: text/plain; charset=UTF-8");

echo "=== DIAGNÓSTICO DE CONEXIÓN Y TABLA STOCK ===\n\n";

try {
    $db = (string)$conn->query("SELECT DATABASE()")->fetchColumn();
    echo "Base de datos conectada: {$db}\n\n";
} catch (Throwable $e) {
    echo "ERROR obteniendo DATABASE(): " . $e->getMessage() . "\n\n";
}

echo "--- SHOW TABLES LIKE 'inventory_stock' ---\n";
try {
    $st = $conn->prepare("SHOW TABLES LIKE ?");
    $st->execute(["inventory_stock"]);
    $result = $st->fetchColumn();
    var_dump($result);
} catch (Throwable $e) {
    echo "EXCEPCIÓN: " . $e->getMessage() . "\n";
}

echo "\n--- SHOW COLUMNS FROM inventory_stock ---\n";
try {
    $rows = $conn->query("SHOW COLUMNS FROM `inventory_stock`")->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch (Throwable $e) {
    echo "EXCEPCIÓN: " . $e->getMessage() . "\n";
}

echo "\n--- Verificando timestamp del archivo anular.php en disco ---\n";
$anularPath = __DIR__ . "/anular.php";
if (file_exists($anularPath)) {
    echo "Última modificación: " . date("Y-m-d H:i:s", filemtime($anularPath)) . "\n";
    echo "Tamaño: " . filesize($anularPath) . " bytes\n";
    // Buscar si contiene el candidato correcto
    $content = file_get_contents($anularPath);
    if (strpos($content, 'inventory_stock') !== false) {
        echo "El archivo SÍ contiene la cadena 'inventory_stock'.\n";
    } else {
        echo "⚠️ El archivo NO contiene 'inventory_stock'. El deploy no tomó el cambio.\n";
    }
} else {
    echo "No se encontró anular.php en esta ruta.\n";
}

echo "\n--- Info de PHP ---\n";
echo "opcache.enable: " . (ini_get('opcache.enable') ? 'ON' : 'OFF') . "\n";
echo "PHP version: " . phpversion() . "\n";