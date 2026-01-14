<?php
declare(strict_types=1);

/**
 * CEVIMEP - Login (Railway OK)
 * - Sesión compartida para TODO el sitio (path=/)
 * - Redirects absolutos (sin /public)
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

if (!empty($_SESSION['user'])) {
  header('Location: /private/dashboard.php');
  exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
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
        session_regenerate_id(true);

        $_SESSION['user'] = [
          'id'        => (int)$u['id'],
          'full_name' => (string)$u['full_name'],
          'email'     => (string)$u['email'],
          'role'      => (string)$u['role'],
          'branch_id' => $u['branch_id'],
        ];

        header('Location: /private/dashboard.php');
        exit;
      }
    } catch (Throwable $e) {
      $error = 'Error al conectar con la base de datos.';
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Iniciar sesión | CEVIMEP</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=1">
</head>
<body class="auth">

  <div class="auth-card">
    <h1>CEVIMEP</h1>
    <p class="muted">Accede con tu correo y contraseña</p>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login.php" autocomplete="on">
      <label>Correo</label>
      <input type="email" name="email" required>

      <label>Contraseña</label>
      <input type="password" name="password" required>

      <button type="submit" class="btn btn-primary">Entrar</button>
    </form>

    <div class="auth-links">
      <a href="/index.php">Volver</a>
    </div>
  </div>

</body>
</html>
