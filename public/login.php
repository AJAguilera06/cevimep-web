<?php
declare(strict_types=1);

/**
 * CEVIMEP - Login (XAMPP + Railway OK)
 * - Sesión compartida para TODO el sitio (path=/)
 * - URLs correctas sin /public (porque /public es carpeta, no URL)
 */

session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/../config/db.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$error = '';

if (!empty($_SESSION['user'])) {
  header("Location: /private/dashboard.php");
  exit;
}

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
        // Guardar sesión
        $_SESSION['user'] = [
          'id' => (int)$u['id'],
          'full_name' => (string)$u['full_name'],
          'email' => (string)$u['email'],
          'role' => (string)$u['role'],
          'branch_id' => (int)$u['branch_id'],
        ];

        // (Opcional) Regenerar id de sesión por seguridad
        session_regenerate_id(true);

        header("Location: /private/dashboard.php");
        exit;
      }
    } catch (Throwable $e) {
      $error = 'Error interno. Intenta de nuevo.';
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Iniciar sesión</title>

  <!-- IMPORTANTE: sin /public -->
  <link rel="stylesheet" href="assets/css/styles.css?v=11">
</head>
<body class="auth-page">

  <div class="auth-wrap">
    <div class="auth-card">

      <div class="auth-header">
        <div class="auth-brand">
          <img src="assets/img/logo.png" alt="CEVIMEP" onerror="this.style.display='none'">
          <div class="auth-brand-text">
            <strong>CEVIMEP</strong>
            <span>Centro de Vacunación Integral y Medicina Preventiva</span>
          </div>
        </div>
      </div>

      <p class="muted" style="margin:0 0 6px;">Accede con tu correo y contraseña</p>

      <?php if ($error): ?>
        <div class="alert-danger"><?= h($error) ?></div>
      <?php endif; ?>

      <form method="post" action="login.php" autocomplete="on">
        <div class="field">
          <label>Correo</label>
          <input class="input" type="email" name="email" required autocomplete="email">
        </div>

        <div class="field">
          <label>Contraseña</label>
          <input class="input" type="password" name="password" required autocomplete="current-password">
        </div>

        <div class="actions">
          <button type="submit" class="btn-primary-pill">Entrar</button>
        </div>
      </form>

      <div class="auth-links">
        <span class="muted" style="font-size:12px;">© <?= (int)date("Y") ?> CEVIMEP</span>
        <a href="index.php">Ir al inicio</a>
      </div>

    </div>
  </div>

</body>
</html>
