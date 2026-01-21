<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../config/db.php';

if (!empty($_SESSION['user'])) {
  header('Location: /private/dashboard.php');
  exit;
}


$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string)($_POST['email'] ?? ''));
  $password = (string)($_POST['password'] ?? '');

  if ($email === '' || $password === '') {
    $error = 'Completa correo y contraseña.';
  } else {
    try {
      $stmt = $pdo->prepare("
        SELECT id, full_name, email, password_hash, role, branch_id, is_active
        FROM users
        WHERE email = :email
        LIMIT 1
      ");
      $stmt->execute([':email' => $email]);
      $u = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$u || (int)$u['is_active'] !== 1 || !password_verify($password, (string)$u['password_hash'])) {
        $error = 'Correo o contraseña incorrectos.';
      } else {
        $_SESSION['user'] = [
          'id' => (int)$u['id'],
          'full_name' => (string)$u['full_name'],
          'email' => (string)$u['email'],
          'role' => (string)$u['role'],
          'branch_id' => (int)$u['branch_id'],
        ];
        session_regenerate_id(true);

        header("Location: /private/dashboard.php");
        exit;
      }
    } catch (Throwable $e) {
      $error = 'Error interno. Intenta de nuevo.';
    }
  }
}
