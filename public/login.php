<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================
   CARGAR DB (RUTA REAL)
   ========================= */
require_once __DIR__ . '/../private/config/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    die('Error crítico: no se pudo cargar la conexión a la base de datos.');
}

/* Si ya está logueado, al dashboard */
if (isset($_SESSION['user'])) {
    header("Location: /private/dashboard.php");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Debe completar todos los campos.';
    } else {

        $stmt = $pdo->prepare("
            SELECT id, full_name, email, password_hash, role, branch_id
            FROM users
            WHERE email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Credenciales incorrectas.';
        } else {

            session_regenerate_id(true);

            $_SESSION['user'] = [
                'id'        => (int)$user['id'],
                'full_name' => (string)$user['full_name'],
                'email'     => (string)$user['email'],
                'role'      => (string)$user['role'],
                'branch_id' => (int)$user['branch_id'],
            ];

            header("Location: /private/dashboard.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>CEVIMEP | Iniciar sesión</title>
  <link rel="stylesheet" href="/assets/css/auth.css">
</head>

<body class="auth-ui">

<div class="auth-page">
  <div class="auth-card">

    <img src="/assets/img/logo.png" class="auth-logo" alt="CEVIMEP">

    <h2>Iniciar sesión</h2>

    <?php if ($error): ?>
      <div class="auth-alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <input type="email" name="email" placeholder="Correo" required>
      <input type="password" name="password" placeholder="Contraseña" required>
      <button type="submit">Entrar</button>
    </form>

  </div>
</div>

</body>
</html>
