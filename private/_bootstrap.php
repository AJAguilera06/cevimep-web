<?php
declare(strict_types=1);

/**
 * Bootstrap único para TODO lo que esté en /private/*
 * - Maneja sesión sin duplicados
 * - Protege acceso
 * - Carga $pdo de forma robusta (Railway / local)
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    // SOLO si la sesión NO está activa, puedes configurar cookies
    // (si está activa y lo intentas, sale el warning)
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // 'secure' => true, // Actívalo si siempre usas https (Railway sí)
    ]);
    session_start();
}

// Protección: si no hay sesión, fuera
if (!isset($_SESSION['user'])) {
    header("Location: /login.php");
    exit;
}

/* ===============================
   Cargar conexión a la BD (PDO)
   =============================== */
$db_candidates = [
    __DIR__ . '/../config/db.php',
    __DIR__ . '/../db.php',
    __DIR__ . '/config/db.php',
    __DIR__ . '/db.php',
];

$loaded = false;
foreach ($db_candidates as $path) {
    if (is_file($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded || !isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo "Error crítico: no se pudo cargar la conexión a la base de datos.";
    exit;
}

// Datos del usuario ya listos para usar
$user = $_SESSION['user'];
