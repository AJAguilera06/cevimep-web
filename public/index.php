<?php
declare(strict_types=1);

// DEBUG (quita esto cuando funcione)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/**
 * Front Controller / Router
 * - Evita loops si Railway reescribe todo a index.php
 * - Incluye login/logout directamente
 * - Protege /private/*
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Configurar cookie ANTES de iniciar sesión
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Permitir assets (si por rewrite caen aquí por error)
if (strpos($uri, '/assets/') === 0) {
    http_response_code(404);
    echo "Asset no encontrado.";
    exit;
}

// Rutas públicas (INCLUDE para evitar loop)
if ($uri === '/login.php' || $uri === '/login') {
    require __DIR__ . '/login.php';
    exit;
}

if ($uri === '/logout.php' || $uri === '/logout') {
    require __DIR__ . '/logout.php';
    exit;
}

// Root
if ($uri === '/' || $uri === '/index.php') {
    if (!isset($_SESSION['user'])) {
        require __DIR__ . '/login.php';
        exit;
    }
    require __DIR__ . '/../private/dashboard.php';
    exit;
}

// Privado
if (strpos($uri, '/private/') === 0) {
    if (!isset($_SESSION['user'])) {
        header("Location: /login.php");
        exit;
    }

    // Mapea /private/... a ../private/...
    $target = realpath(__DIR__ . '/../' . ltrim($uri, '/'));
    $base   = realpath(__DIR__ . '/../private');

    if ($target && $base && strpos($target, $base) === 0 && is_file($target)) {
        require $target;
        exit;
    }

    http_response_code(404);
    echo "Página privada no encontrada.";
    exit;
}

// 404
http_response_code(404);
echo "Página no encontrada.";
exit;
