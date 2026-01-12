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
      // Solo usuarios activos
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
  <title>CEVIMEP - Login</title>

  <!-- ✅ Tu CSS está en public/assets/... -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=1">
</head>
<body style="min-height:100vh; display:flex; flex-direction:column;">

  <main style="flex:1; display:flex; align-items:center; justify-content:center; padding:24px;">
    <div style="width:100%; max-width:420px;">

      <h2 style="margin:0 0 14px; text-align:center;">Iniciar sesión</h2>

      <?php if ($error !== ""): ?>
        <div style="background:#ffe5e5; border:1px solid #ffb3b3; padding:10px; border-radius:8px; margin-bottom:12px;">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="/login.php" style="display:flex; flex-direction:column; gap:10px;">
        <label>
          Correo
          <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required
                 style="width:100%; padding:10px; border-radius:8px; border:1px solid #ccc;">
        </label>

        <label>
          Contraseña
          <input type="password" name="password" required
                 style="width:100%; padding:10px; border-radius:8px; border:1px solid #ccc;">
        </label>

        <button type="submit" style="padding:11px; border-radius:10px; border:0; cursor:pointer;">
          Entrar
        </button>
      </form>

    </div>
  </main>

  <footer class="footer">
    <div class="inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
  </footer>

</body>
</html>
