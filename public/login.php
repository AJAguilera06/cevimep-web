<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../private/config/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    die('Error crítico: no se pudo cargar la conexión a la base de datos.');
}

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
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- ✅ ESTILO AUTH OFICIAL -->
    <link rel="stylesheet" href="/assets/css/auth.css?v=2">
</head>

<body class="auth-ui">

<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <img src="/assets/img/CEVIMEP.png" alt="CEVIMEP">
        </div>

        <h1 class="auth-title">CEVIMEP</h1>
        <p class="auth-subtitle">Iniciar sesión</p>

        <?php if ($error): ?>
            <div class="auth-alert error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on" id="loginForm">

            <div class="form-group">
                <label for="email">Correo</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    class="input"
                    placeholder="correo@ejemplo.com"
                    autocomplete="username"
                    required
                >
            </div>

            <div class="form-group">
                <label for="password">Contraseña</label>

                <div class="pass-wrap">
                    <input
                        id="password"
                        type="password"
                        name="password"
                        class="input"
                        placeholder="••••••••"
                        autocomplete="current-password"
                        required
                    >
                    <button type="button" class="pass-toggle" id="togglePass">Mostrar</button>
                </div>

                <div class="check-row">
                    <label>
                        <input type="checkbox" id="rememberPass">
                        Recordar contraseña (esta pestaña)
                    </label>

                    <label style="font-weight:700; opacity:.85;">
                        <input type="checkbox" id="rememberEmail">
                        Recordar correo
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                Entrar
            </button>

        </form>

    </div>
</div>

<div class="auth-footer">
    © <?= date('Y') ?> CEVIMEP. Todos los derechos reservados.
</div>

<script>
(function(){
  const email = document.getElementById('email');
  const pass = document.getElementById('password');
  const toggle = document.getElementById('togglePass');
  const rememberPass = document.getElementById('rememberPass');
  const rememberEmail = document.getElementById('rememberEmail');
  const form = document.getElementById('loginForm');

  // ===== Mostrar/Ocultar =====
  toggle.addEventListener('click', () => {
    const isPass = pass.type === 'password';
    pass.type = isPass ? 'text' : 'password';
    toggle.textContent = isPass ? 'Ocultar' : 'Mostrar';
    pass.focus();
  });

  // ===== Recordar CORREO (localStorage) =====
  const savedEmail = localStorage.getItem('cevimep_login_email');
  if (savedEmail) {
    email.value = savedEmail;
    rememberEmail.checked = true;
  }

  rememberEmail.addEventListener('change', () => {
    if (rememberEmail.checked) {
      localStorage.setItem('cevimep_login_email', email.value || '');
    } else {
      localStorage.removeItem('cevimep_login_email');
    }
  });

  email.addEventListener('input', () => {
    if (rememberEmail.checked) {
      localStorage.setItem('cevimep_login_email', email.value || '');
    }
  });

  // ===== Recordar CONTRASEÑA (sessionStorage: solo esta pestaña) =====
  // Por seguridad: solo si el usuario marca la casilla
  const savedPass = sessionStorage.getItem('cevimep_login_pass');
  const savedFlag = sessionStorage.getItem('cevimep_login_pass_enabled');

  if (savedFlag === '1') {
    rememberPass.checked = true;
    if (savedPass) pass.value = savedPass;
  }

  rememberPass.addEventListener('change', () => {
    if (rememberPass.checked) {
      sessionStorage.setItem('cevimep_login_pass_enabled', '1');
      sessionStorage.setItem('cevimep_login_pass', pass.value || '');
    } else {
      sessionStorage.removeItem('cevimep_login_pass_enabled');
      sessionStorage.removeItem('cevimep_login_pass');
    }
  });

  pass.addEventListener('input', () => {
    if (rememberPass.checked) {
      sessionStorage.setItem('cevimep_login_pass', pass.value || '');
    }
  });

  // Si el usuario NO quiere recordar, limpiamos siempre al recargar
  if (!rememberPass.checked) {
    sessionStorage.removeItem('cevimep_login_pass');
    sessionStorage.removeItem('cevimep_login_pass_enabled');
  }

  // Si se envía el form y el usuario NO quiere recordar, limpia
  form.addEventListener('submit', () => {
    if (!rememberPass.checked) {
      sessionStorage.removeItem('cevimep_login_pass');
      sessionStorage.removeItem('cevimep_login_pass_enabled');
    }
  });
})();
</script>

</body>
</html>
