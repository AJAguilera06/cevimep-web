<?php
declare(strict_types=1);

session_start();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Normaliza
if ($uri === '') $uri = '/';

// 1) Permitir assets sin tocar (si por alguna razón pasan por aquí)
if (strpos($uri, '/assets/') === 0) {
  http_response_code(404);
  echo "Asset no encontrado.";
  exit;
}

// 2) Rutas públicas (NO redirigir, INCLUDE para evitar loops)
if ($uri === '/login.php' || $uri === '/login') {
  require __DIR__ . '/login.php';
  exit;
}

if ($uri === '/logout.php' || $uri === '/logout') {
  require __DIR__ . '/logout.php';
  exit;
}

// 3) Root: si está logueado -> dashboard, si no -> login (INCLUDE)
if ($uri === '/' || $uri === '/index.php') {
  if (!isset($_SESSION['user'])) {
    require __DIR__ . '/login.php';
    exit;
  }
  // Dashboard vive fuera de /public
  require __DIR__ . '/../private/dashboard.php';
  exit;
}

// 4) Rutas privadas: /private/...
if (strpos($uri, '/private/') === 0) {
  if (!isset($_SESSION['user'])) {
    header("Location: /login.php");
    exit;
  }

  // Mapea /private/... a ../private/...
  $target = realpath(__DIR__ . '/../' . ltrim($uri, '/'));
  $base   = realpath(__DIR__ . '/../private');

  // Seguridad: solo permitir dentro de ../private
  if ($target && $base && strpos($target, $base) === 0 && is_file($target)) {
    require $target;
    exit;
  }

  http_response_code(404);
  echo "Página privada no encontrada";
  exit;
}

// 5) Fallback 404
http_response_code(404);
echo "Página no encontrada";
exit;
