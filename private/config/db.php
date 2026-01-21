<?php
// config/db.php
declare(strict_types=1);

// 1) Si existe MYSQL_URL (muy común en Railway), úsalo
$mysqlUrl = getenv('MYSQL_URL') ?: getenv('DATABASE_URL');

if ($mysqlUrl) {
  $parts = parse_url($mysqlUrl);

  $host = $parts['host'] ?? 'localhost';
  $port = $parts['port'] ?? 3306;
  $user = $parts['user'] ?? 'root';
  $pass = $parts['pass'] ?? '';
  $dbname = isset($parts['path']) ? ltrim($parts['path'], '/') : '';

  try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO(
      $dsn,
      $user,
      $pass,
      [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]
    );
  } catch (PDOException $e) {
    die("DB ERROR (URL): " . $e->getMessage());
  }

  return;
}

// 2) Fallback por variables separadas (MYSQLHOST, MYSQLPORT, etc.)
$host = getenv("DB_HOST") ?: (getenv("MYSQLHOST") ?: "localhost");
$dbname = getenv("DB_NAME") ?: (getenv("MYSQLDATABASE") ?: (getenv("MYSQL_DATABASE") ?: "cevimep-db"));
$user = getenv("DB_USER") ?: (getenv("MYSQLUSER") ?: "root");
$pass = getenv("DB_PASS") ?: (getenv("MYSQLPASSWORD") ?: "");
$port = getenv("DB_PORT") ?: (getenv("MYSQLPORT") ?: "3306");

try {
  $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
  $pdo = new PDO(
    $dsn,
    $user,
    $pass,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
} catch (PDOException $e) {
  die("DB ERROR: " . $e->getMessage());
}
