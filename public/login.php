<?php
session_start();
require_once __DIR__ . "/../config/db.php";

if (isset($_SESSION["user"])) {
  header("Location: /private/dashboard.php");
  exit;
}

$error = "";
$email = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $email = trim($_POST["email"] ?? "");
  $password = (string)($_POST["password"] ?? "");

  if ($email === "" || $password === "") {
    $error = "Completa correo y contraseña.";
  } else {
    try {
      $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
      $stmt->execute([$email]);
      $user = $stmt->fetch();

      if (!$user) {
        $error = "Correo o contraseña incorrectos.";
      } else {
        $dbHash = (string)($user["password_hash"] ?? "");

        if ($dbHash !== "" && password_verify($password, $dbHash)) {
          $_SESSION["user"] = [
            "id"        => $user["id"] ?? null,
            "name"      => $user["full_name"] ?? "Usuario",
            "email"     => $user["email"] ?? $email,
            "role"      => $user["role"] ?? "user",
            "branch_id" => $user["branch_id"] ?? null,
          ];

          header("Location: /private/dashboard.php");
          exit;
        } else {
          $error = "Correo o contraseña incorrectos.";
        }
      }
    } catch (Throwable $e) {
      $error = "Error interno: " . $e->getMessage();
    }
  }
}

$year = date("Y");
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP - Iniciar sesión</title>

  <!-- ✅ CSS correcto (está dentro de public/assets/...) -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=1">
</head>

<body style="min-height:100vh; display:flex; flex-direction:column;">

  <!-- ✅ Navbar igual al index -->
  <header class="navbar">
    <div class="inner">
      <div></div>
      <div class="brand"><span class="dot"></span> CEVIMEP</div>
      <div class="nav-right"><a class="active" href="/login.php">Iniciar sesión</a></div>
    </div>
  </header>

  <main class="container" style="flex:1; display:flex; align-items:center; justify-content:center;">
    <div class="card" style="max-width:460px; width:100%;">
      <h2 style="margin:0;">Iniciar sesión</h2>
      <p class="muted" style="margin-top:6px;">Accede al sistema interno</p>

      <?php if ($error !== ""): ?>
        <div class="alert error" style="margin-top:12px;">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="/login.php" style="margin-top:14px;">
        <div class="form-group">
          <label for="email">Correo</label>
          <input id="email" type="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
        </div>

        <div class="form-group" style="margin-top:10px;">
          <label for="password">Contraseña</label>
          <input id="password" type="password" name="password" required>
        </div>

        <button class="btn primary" type="submit" style="margin-top:14px; width:100%;">
          Entrar
        </button>
      </form>

      <div style="margin-top:14px;">
        <a href="/index.php">← Volver al inicio</a>
      </div>
    </div>
  </main>

  <footer class="footer">
    <div class="inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
  </footer>

</body>
</html>
