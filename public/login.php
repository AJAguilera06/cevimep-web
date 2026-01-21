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

  <!-- Tu CSS global -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=11">

  <style>
    /* Fondo tipo dashboard */
    body.auth{
      min-height:100vh;
      margin:0;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:24px;
      background:
        radial-gradient(900px 500px at 70% 10%, rgba(64, 224, 208, .18), transparent 55%),
        radial-gradient(900px 600px at 20% 0%, rgba(0, 112, 255, .14), transparent 55%),
        linear-gradient(180deg, #0b2a4a 0px, #0b2a4a 88px, #f5f7fb 88px, #f5f7fb 100%);
    }

    /* Barra superior similar a navbar */
    .auth-top{
      position:fixed;
      top:0; left:0; right:0;
      height:88px;
      display:flex;
      align-items:center;
      justify-content:center;
      color:#fff;
      font-weight:900;
      letter-spacing:.5px;
      z-index:1;
      pointer-events:none;
    }
    .auth-top .brand{
      display:flex;
      align-items:center;
      gap:10px;
      opacity:.95;
    }
    .auth-top .dot{
      width:10px;height:10px;border-radius:999px;
      background:#27d3c7;
      box-shadow:0 0 0 5px rgba(39,211,199,.15);
    }

    /* Card */
    .login-wrap{
      width:100%;
      max-width:980px;
      display:grid;
      grid-template-columns: 1.1fr .9fr;
      gap:18px;
      z-index:2;
    }
    @media (max-width: 900px){
      .login-wrap{ grid-template-columns:1fr; max-width:560px; }
      .left-panel{ display:none; }
    }

    .left-panel{
      border-radius:22px;
      padding:26px;
      color:#fff;
      background:
        radial-gradient(600px 220px at 30% 10%, rgba(39,211,199,.35), transparent 60%),
        linear-gradient(135deg, #0f3a67, #0b2a4a);
      box-shadow: 0 18px 50px rgba(10, 35, 70, .25);
      border:1px solid rgba(255,255,255,.10);
      overflow:hidden;
      position:relative;
    }
    .left-panel h2{ margin:0 0 10px; font-size:26px; }
    .left-panel p{ margin:0; opacity:.9; font-weight:700; line-height:1.35; }
    .left-badges{
      display:flex; gap:10px; flex-wrap:wrap;
      margin-top:18px;
    }
    .badge{
      background:rgba(255,255,255,.12);
      border:1px solid rgba(255,255,255,.18);
      padding:8px 10px;
      border-radius:999px;
      font-weight:800;
      font-size:12px;
    }

    .auth-card{
      background:#fff;
      border:1px solid rgba(2,21,44,.12);
      border-radius:22px;
      padding:22px;
      box-shadow: 0 18px 55px rgba(15,42,80,.12);
    }

    .logo-row{
      display:flex;
      align-items:center;
      gap:14px;
      margin-bottom:12px;
    }
    .logo-row img{
      width:70px;
      height:auto;
      display:block;
    }
    .logo-row .title{
      display:flex;
      flex-direction:column;
      gap:4px;
    }
    .logo-row .title b{
      font-size:20px;
      color:#0b2a4a;
      letter-spacing:.3px;
    }
    .logo-row .title span{
      font-size:12px;
      color:#5b6b7b;
      font-weight:800;
    }

    .muted{ color:#5b6b7b; font-weight:700; }

    .field{
      display:flex;
      flex-direction:column;
      gap:8px;
      margin-top:12px;
    }
    .field label{ font-weight:900; color:#0b2a4a; font-size:13px; }

    .input{
      width:100%;
      padding:11px 12px;
      border-radius:14px;
      border:1px solid rgba(2,21,44,.12);
      outline:none;
      font-weight:800;
      background:#fff;
    }
    .input:focus{
      border-color:#7fb2ff;
      box-shadow:0 0 0 3px rgba(127,178,255,.20);
    }

    .alert-danger{
      margin-top:12px;
      background:#ffe8e8;
      border:1px solid #ffb2b2;
      color:#7a1010;
      padding:10px 12px;
      border-radius:14px;
      font-weight:900;
      font-size:13px;
    }

    .actions{
      display:flex;
      gap:10px;
      justify-content:flex-end;
      align-items:center;
      margin-top:16px;
      flex-wrap:wrap;
    }

    /* Botón estilo pill (por si tu CSS no lo tiene en login) */
    .
/assets/css/styles.css


-pill{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      border-radius:999px;
      padding:10px 14px;
      font-weight:900;
      text-decoration:none;
      cursor:pointer;
      border:1px solid rgba(255,255,255,.0);
      background:linear-gradient(135deg, rgba(127,178,255,.28), rgba(39,211,199,.18));
      color:#0b2a4a;
    }
    .
/assets/css/styles.css


-primary-pill{
      background:linear-gradient(135deg, #1c6cff, #0f3a67);
      color:#fff;
      border:1px solid rgba(255,255,255,.10);
      box-shadow:0 10px 24px rgba(28,108,255,.18);
    }

    .auth-links{
      margin-top:14px;
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:10px;
    }
    .auth-links a{
      color:#0f3a67;
      font-weight:900;
      text-decoration:none;
    }
    .auth-links a:hover{ text-decoration:underline; }
  </style>
</head>

<body class="auth">

  <div class="auth-top">
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
  </div>

  <div class="login-wrap">

    <div class="left-panel">
      <h2>Centro de Vacunación Integral</h2>
      <p>Accede al sistema para gestionar pacientes, caja, inventario y estadísticas por sucursal.</p>

      <div class="left-badges">
        <div class="badge">✅ Seguro</div>
        <div class="badge">🏥 Multi-sucursal</div>
        <div class="badge">📊 Reportes</div>
        <div class="badge">📦 Inventario</div>
      </div>
    </div>

    <div class="auth-card">
      <div class="logo-row">
        <!-- ✅ Logo desde public/assets/img -->
        <img src="/assets/img/CEVIMEP.png" alt="CEVIMEP">
        <div class="title">
          <b>Iniciar sesión</b>
          <span>Centro de Vacunación Integral y Medicina Preventiva</span>
        </div>
      </div>

      <p class="muted" style="margin:0 0 6px;">Accede con tu correo y contraseña</p>

      <?php if ($error): ?>
        <div class="alert-danger"><?= h($error) ?></div>
      <?php endif; ?>

      <form method="post" action="/login.php" autocomplete="on">
        <div class="field">
          <label>Correo</label>
          <input class="input" type="email" name="email" required autocomplete="email">
        </div>

        <div class="field">
          <label>Contraseña</label>
          <input class="input" type="password" name="password" required autocomplete="current-password">
        </div>

        <div class="actions">
          <a class="
/assets/css/styles.css


-pill" href="/index.php">Volver</a>
          <button type="submit" class="
/assets/css/styles.css


-pill 
/assets/css/styles.css


-primary-pill">Entrar</button>
        </div>
      </form>

      <div class="auth-links">
        <span class="muted" style="font-size:12px;">© <?= (int)date("Y") ?> CEVIMEP</span>
        <a href="/index.php">Ir al inicio</a>
      </div>
    </div>

  </div>

</body>
</html>
