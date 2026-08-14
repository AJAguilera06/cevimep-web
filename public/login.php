<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

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
    $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if ($email === '' || $password === '') {
        $error = 'Debe completar todos los campos.';
    } else {

        $limitStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM login_attempts
            WHERE email = ?
              AND ip_address = ?
              AND success = 0
              AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $limitStmt->execute([$email, $ip]);
        $failedAttempts = (int)$limitStmt->fetchColumn();

        if ($failedAttempts >= 5) {
            $error = 'Demasiados intentos fallidos. Intente nuevamente en 15 minutos.';
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

                $logStmt = $pdo->prepare("
                    INSERT INTO login_attempts (email, ip_address, success)
                    VALUES (?, ?, 0)
                ");
                $logStmt->execute([$email, $ip]);

                $error = 'Credenciales incorrectas.';

            } else {

                $logStmt = $pdo->prepare("
                    INSERT INTO login_attempts (email, ip_address, success)
                    VALUES (?, ?, 1)
                ");
                $logStmt->execute([$email, $ip]);

                session_regenerate_id(true);

                $_SESSION['user'] = [
                    'id'        => (int)$user['id'],
                    'full_name' => (string)$user['full_name'],
                    'email'     => (string)$user['email'],
                    'role'      => (string)$user['role'],
                    'branch_id' => (int)$user['branch_id'],
                ];

                $_SESSION['branch_id'] = (int)$user['branch_id'];
                $_SESSION['user_id']   = (int)$user['id'];
                $_SESSION['last_activity'] = time();

                header("Location: /private/dashboard.php");
                exit;
            }
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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root{
            --navy-deep:#071B32;
            --teal:#14B8A6;
            --teal-dark:#0D9488;
            --danger:#F87171;
            --danger-bg:rgba(248,113,113,.12);
        }
        *{ box-sizing:border-box; }
        html,body{ height:100%; margin:0; }
        body.auth-ui{
            font-family:'Inter',sans-serif;
            background:var(--navy-deep);
            min-height:100vh;
            display:flex;
            flex-direction:column;
            align-items:center;
            justify-content:center;
            position:relative;
            overflow:hidden;
            padding:24px;
        }
        .auth-bg{
            position:absolute; inset:0; width:100%; height:100%; z-index:0;
        }
        .auth-page{ position:relative; z-index:1; width:100%; display:flex; justify-content:center; }
        .auth-card{
            width:100%; max-width:380px;
            background:rgba(255,255,255,.08);
            backdrop-filter:blur(18px);
            -webkit-backdrop-filter:blur(18px);
            border:1px solid rgba(255,255,255,.14);
            border-radius:20px;
            padding:40px 36px;
        }
        .auth-logo{ display:flex; justify-content:center; margin-bottom:14px; }
        .auth-logo img{ height:48px; width:auto; }
        .auth-title{
            font-family:'Manrope',sans-serif; font-weight:800; font-size:21px;
            color:#fff; text-align:center; margin:0 0 4px; letter-spacing:.02em;
        }
        .auth-subtitle{
            text-align:center; font-size:13px; color:#AFC3D6; margin:0 0 26px;
        }
        .auth-alert.error{
            background:var(--danger-bg);
            border:1px solid rgba(248,113,113,.35);
            color:var(--danger);
            font-size:13px;
            font-weight:600;
            padding:11px 14px;
            border-radius:10px;
            margin-bottom:20px;
        }
        .form-group{ margin-bottom:18px; }
        .form-group label{
            display:block; font-weight:600; font-size:12.5px;
            color:#CBD9E6; margin:0 0 6px; letter-spacing:.02em;
        }
        .input{
            width:100%; padding:12px 14px; border-radius:10px;
            border:1.5px solid rgba(255,255,255,.18);
            background:rgba(255,255,255,.08);
            color:#fff;
            font-family:'Inter'; font-size:14px;
            outline:none; transition:.15s;
        }
        .input::placeholder{ color:#8FA3B8; }
        .input:focus{
            border-color:var(--teal);
            box-shadow:0 0 0 3px rgba(20,184,166,.22);
            background:rgba(255,255,255,.11);
        }
        .pass-wrap{ position:relative; }
        .pass-wrap .input{ padding-right:78px; }
        .pass-toggle{
            position:absolute; right:6px; top:50%; transform:translateY(-50%);
            background:none; border:0; color:var(--teal);
            font-family:'Inter'; font-weight:700; font-size:12.5px;
            cursor:pointer; padding:6px 8px;
        }
        .pass-toggle:hover{ color:#5EEAD4; }
        .check-row{ margin-top:12px; }
        .check-row label{
            display:flex; align-items:center; gap:8px;
            font-size:13px; color:#AFC3D6; font-weight:500 !important;
            cursor:pointer;
        }
        .check-row input[type=checkbox]{ width:auto; accent-color:var(--teal); }
        .btn{
            width:100%; padding:13px; border:0; border-radius:10px;
            font-family:'Inter'; font-weight:700; font-size:14.5px;
            cursor:pointer; transition:.15s; margin-top:6px;
        }
        .btn-primary{ background:var(--teal); color:#fff; }
        .btn-primary:hover{ background:var(--teal-dark); }
        .auth-footer{
            position:relative; z-index:1;
            margin-top:22px; font-size:11.5px; color:#5C7896; text-align:center;
        }
        @media (max-width:420px){
            .auth-card{ padding:32px 24px; }
        }
    </style>
</head>

<body class="auth-ui">

    <svg class="auth-bg" viewBox="0 0 900 640" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
        <circle cx="130" cy="120" r="220" fill="#0D3A5C" opacity=".6"/>
        <circle cx="800" cy="520" r="260" fill="#0F4A63" opacity=".5"/>
        <g fill="none" stroke="#1D5C7A" stroke-width="1" opacity=".8">
            <line x1="60" y1="60" x2="180" y2="130"/><line x1="180" y1="130" x2="140" y2="250"/>
            <line x1="780" y1="480" x2="700" y2="560"/><line x1="700" y1="560" x2="820" y2="600"/>
        </g>
        <g fill="#14B8A6" opacity=".9">
            <circle cx="60" cy="60" r="3"/><circle cx="180" cy="130" r="4"/><circle cx="140" cy="250" r="3"/>
            <circle cx="780" cy="480" r="3"/><circle cx="700" cy="560" r="4"/><circle cx="820" cy="600" r="3"/>
        </g>
    </svg>

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
  const rememberEmail = document.getElementById('rememberEmail');

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

})();
</script>

</body>
</html>