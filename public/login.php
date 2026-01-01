<?php
session_start();
require_once __DIR__ . "/../config/db.php";

if (isset($_SESSION["user"])) {
  header("Location: ../private/dashboard.php");
  exit;
}

$error = "";
$email = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $email = trim($_POST["email"] ?? "");
  $password = $_POST["password"] ?? "";

  if ($email === "" || $password === "") {
    $error = "Completa todos los campos.";
  } else {
    try {
      $stmt = $pdo->prepare("
        SELECT id, full_name, email, password_hash, role, branch_id, is_active
        FROM users
        WHERE email = :email
        LIMIT 1
      ");
      $stmt->execute(["email" => $email]);
      $user = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$user || (int)$user["is_active"] !== 1 || !password_verify($password, $user["password_hash"])) {
        $error = "Correo o contraseña incorrectos.";
      } else {
        $_SESSION["user"] = [
          "id" => (int)$user["id"],
          "name" => $user["full_name"],
          "email" => $user["email"],
          "role" => $user["role"],
          "branch_id" => $user["branch_id"] !== null ? (int)$user["branch_id"] : null
        ];

        header("Location: ../private/dashboard.php");
        exit;
      }
    } catch (Throwable $e) {
      // Para no exponer detalles en producción
      $error = "Ocurrió un error al iniciar sesión. Intenta de nuevo.";
      // Si quieres depurar: descomenta la línea de abajo temporalmente
      // $error = "Error: " . $e->getMessage();
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
  <link rel="stylesheet" href="../assets/css/styles.css">

  <style>
    /* ====== FIX: QUITAR SCROLL EN LOGIN ====== */
    html, body { height:100%; }
    body{
      margin:0;
      display:flex;
      flex-direction:column;
      overflow:hidden; /* NO scroll */
    }
    main{
      flex:1;
      display:flex;
      min-height:0; /* CLAVE para que no “empuje” */
    }

    /* Contenedor del login: ocupa el espacio entre navbar y footer */
    .login-wrap{
      flex:1;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:0; /* evita que se pase de alto */
    }

    /* Card */
    .login-card{
      width:100%;
      max-width:420px;
      background:#fff;
      border:1px solid var(--border);
      border-radius:var(--radius);
      padding:22px;
      box-shadow:var(--shadow);
    }

    .login-title{
      margin:0;
      color:var(--primary-2);
    }
    .login-sub{
      margin:8px 0 0;
      color:var(--muted);
      font-weight:600;
    }

    label{
      display:block;
      margin-top:14px;
      font-weight:900;
      color:var(--text);
    }

    input{
      width:100%;
      margin-top:6px;
      padding:12px 14px;
      border-radius:14px;
      border:1px solid var(--border);
      font-size:14px;
      outline:none;
      background:#fff;
    }
    input:focus{ border-color:rgba(28,100,242,.55); }

    .btn-login{
      width:100%;
      margin-top:16px;
      padding:12px;
      border:none;
      border-radius:16px;
      background:linear-gradient(135deg,var(--primary),var(--primary-2));
      color:#fff;
      font-weight:900;
      cursor:pointer;
    }
    .btn-login:hover{ filter:brightness(.95); }

    .error{
      margin-top:14px;
      padding:10px 12px;
      border-radius:14px;
      background:#ffe8e8;
      border:1px solid #ffb2b2;
      color:#7a1010;
      font-size:13px;
      font-weight:800;
    }

    .back{
      display:inline-block;
      margin-top:14px;
      text-decoration:none;
      color:var(--primary-2);
      font-weight:900;
    }
  </style>
</head>

<body>

<!-- ====== NAVBAR (TU CSS) ====== -->
<header class="navbar">
  <div class="inner">
    <div></div>

    <div class="brand">
      <span class="dot"></span>
      CEVIMEP
    </div>

    <div class="nav-right">
      <a href="#">Iniciar sesión</a>
    </div>
  </div>
</header>

<!-- ====== CONTENIDO ====== -->
<main>
  <div class="login-wrap">
    <div class="login-card">

      <h2 class="login-title">Iniciar sesión</h2>
      <p class="login-sub">Accede al sistema interno</p>

      <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="on">
        <label>Correo</label>
        <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>

        <label>Contraseña</label>
        <input type="password" name="password" required>

        <button class="btn-login" type="submit">Entrar</button>

        <?php
          $is_local =
            ($_SERVER['SERVER_NAME'] ?? '') === 'localhost'
            || ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
            || ($_SERVER['REMOTE_ADDR'] ?? '') === '::1';
        ?>

        <?php if ($is_local): ?>
          <div style="margin-top:12px; font-size:12px; color:var(--muted);">
            
          </div>
        <?php endif; ?>
      </form>

      <a href="index.php" class="back">← Volver al inicio</a>

    </div>
  </div>
</main>

<!-- ====== FOOTER (TU CSS) ====== -->
<footer class="footer">
  <div class="inner">
    © 2025 CEVIMEP. Todos los derechos reservados.
  </div>
</footer>

</body>
</html>
