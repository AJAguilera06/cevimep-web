<?php
declare(strict_types=1);

/**
 * CEVIMEP - DB
 * - Compatible XAMPP + Railway
 * - Soporta MYSQL_URL / DATABASE_URL
 * - Fallback a MYSQLHOST / MYSQLDATABASE / MYSQLUSER / MYSQLPASSWORD / MYSQLPORT
 */

function envv(string $k, $default = null) {
    $v = $_ENV[$k] ?? getenv($k);
    return ($v === false || $v === null || $v === '') ? $default : $v;
}

try {
    // 1) Railway a veces trae MYSQL_URL o DATABASE_URL
    $url = envv('MYSQL_URL') ?? envv('DATABASE_URL');

    if ($url) {
        $p = parse_url($url);
        $host = $p['host'] ?? 'localhost';
        $port = (string)($p['port'] ?? '3306');
        $user = $p['user'] ?? 'root';
        $pass = $p['pass'] ?? '';
        $db   = isset($p['path']) ? ltrim($p['path'], '/') : 'cevimep';
    } else {
        // 2) Variables típicas del plugin MySQL
        $host = envv('MYSQLHOST', envv('DB_HOST', 'localhost'));
        $port = (string)envv('MYSQLPORT', '3306');
        $db   = envv('MYSQLDATABASE', envv('DB_DATABASE', 'cevimep'));
        $user = envv('MYSQLUSER', envv('DB_USERNAME', 'root'));
        $pass = envv('MYSQLPASSWORD', envv('DB_PASSWORD', ''));
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo "Error de conexión a la base de datos.";
    exit;
}
