<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config/db.php'; // /public/login.php -> /config/db.php

if (!empty($_SESSION['user'])) {
  header('Location: ../private/dashboard.php');
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

      if (!$u || (int)$u['is_active'] !== 1 || !password_verify($password, $u['password_hash'])) {
        $error = 'Correo o contraseña incorrectos.';
      } else {
        $_SESSION['user'] = [
          'id'        => (int)$u['id'],
          'full_name' => $u['full_name'],
          'email'     => $u['email'],
          'role'      => $u['role'],
          'branch_id' => $u['branch_id'],
        ];
        header('Location: ../private/dashboard.php');
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
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Iniciar sesión</title>

  <!-- ✅ desde /public -->
  <link rel="stylesheet" href="assets/css/styles.css?v=1" />
</head>
<body>

<header class="topbar">
  <div class="inner">
    <div class="brand">
      <span class="dot"></span>
      <span class="brand-name">CEVIMEP</span>
    </div>
    <div class="top-actions">
      <a class="pill" href="login.php">Iniciar sesión</a>
    </div>
  </div>
</header>

<main>
  <div class="login-wrap">
    <div class="login-card">
      <h2 class="login-title">Iniciar sesión</h2>
      <p class="login-sub">Accede al sistema interno</p>

      <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <form method="POST" action="login.php" class="login-form" autocomplete="off">
        <label class="label">Correo</label>
        <input class="input" type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required />

        <label class="label mt">Contraseña</label>
        <input class="input" type="password" name="password" required />

        <button class="btn-primary mt-lg" type="submit">Entrar</button>

        <div class="mt-lg">
          <a class="link" href="index.php">← Volver al inicio</a>
        </div>
      </form>
    </div>
  </div>
</main>

<footer class="footer">
  <div class="inner">© <?php echo date('Y'); ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

</body>
</html>
