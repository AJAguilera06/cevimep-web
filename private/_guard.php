<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    header("Location: /login.php");
    exit;
}


/* ===============================
   DB robusta (funciona en Railway)
   =============================== */
$db_candidates = [
  __DIR__ . "/../config/db.php",
  __DIR__ . "/../db.php",
  __DIR__ . "/config/db.php",
  __DIR__ . "/db.php",
  __DIR__ . "/../config/database.php",
];

$loaded = false;
foreach ($db_candidates as $p) {
  if (is_file($p)) {
    require_once $p;
    $loaded = true;
    break;
  }
}

if (!$loaded || !isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo "Error crítico: no se pudo cargar la conexión a la base de datos.";
  exit;
}
