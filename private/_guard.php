<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Zona horaria RD (GMT-4)
date_default_timezone_set('America/Santo_Domingo');

// Si no hay sesión de usuario, enviar al login
if (!isset($_SESSION['user'])) {
    header("Location: /login.php");
    exit;
}

/**
 * ✅ FIX CLAVE:
 * Garantizar branch_id global en sesión.
 * (Muchos módulos usan $_SESSION['branch_id'] directamente)
 */
if (!isset($_SESSION['branch_id']) && isset($_SESSION['user']['branch_id'])) {
    $_SESSION['branch_id'] = (int)$_SESSION['user']['branch_id'];
}

// Opcional: si quieres tener user_id global también
if (!isset($_SESSION['user_id']) && isset($_SESSION['user']['id'])) {
    $_SESSION['user_id'] = (int)$_SESSION['user']['id'];
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
