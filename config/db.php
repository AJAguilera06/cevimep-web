<?php
// config/db.php
declare(strict_types=1);

$host = "localhost";
$dbname = "cevimep-db"; // <- importante
$user = "root";
$pass = "";

try {
  $pdo = new PDO(
    "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
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
