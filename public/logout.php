<?php
declare(strict_types=1);

session_start();

// Vaciar variables de sesión
$_SESSION = [];

// Borrar cookie de sesión (muy importante)
if (ini_get("session.use_cookies")) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000,
    $params["path"] ?? '/',
    $params["domain"] ?? '',
    (bool)($params["secure"] ?? false),
    (bool)($params["httponly"] ?? true)
  );
}

// Destruir sesión
session_destroy();

// Redirect absoluto
header("Location: login.php");
exit;
