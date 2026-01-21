<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Public: login/logout (INCLUDE para evitar loop si hay rewrite)
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

  $target = realpath(__DIR__ . '/../' . ltrim($uri, '/'));
  $base   = realpath(__DIR__ . '/../private');

  if ($target && $base && strpos($target, $base) === 0 && is_file($target)) {
    require $target;
    exit;
  }

  http_response_code(404);
  echo "Página privada no encontrada";
  exit;
}

http_response_code(404);
echo "Página no encontrada";
exit;
