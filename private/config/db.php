<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CEVIMEP - Conexión a Base de Datos
| Compatible XAMPP + Railway
|--------------------------------------------------------------------------
*/

try {
    // Railway usa variables de entorno
    $db_host = $_ENV['MYSQLHOST']     ?? $_ENV['DB_HOST']     ?? 'localhost';
    $db_name = $_ENV['MYSQLDATABASE'] ?? $_ENV['DB_DATABASE']?? 'cevimep';
    $db_user = $_ENV['MYSQLUSER']     ?? $_ENV['DB_USERNAME']?? 'root';
    $db_pass = $_ENV['MYSQLPASSWORD'] ?? $_ENV['DB_PASSWORD']?? '';
    $db_port = $_ENV['MYSQLPORT']     ?? '3306';

    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";

    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo "Error de conexión a la base de datos.";
    exit;
}
