<?php
// config/db.php
declare(strict_types=1);

$host = getenv("DB_HOST") ?: (getenv("MYSQLHOST") ?: "localhost");
$dbname = getenv("DB_NAME")
  ?: (getenv("MYSQLDATABASE") ?: (getenv("MYSQL_DATABASE") ?: "cevimep-db")); // <- importante
$user = getenv("DB_USER") ?: (getenv("MYSQLUSER") ?: "root");
$pass = getenv("DB_PASS") ?: (getenv("MYSQLPASSWORD") ?: "");
$port = getenv("DB_PORT") ?: (getenv("MYSQLPORT") ?: "");

try {
  $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
  if ($port !== "") {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
  }

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
