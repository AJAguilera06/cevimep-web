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
  $password = $_POST["password"] ?? "";

  if ($email === "" || $password === "") {
    $error = "Completa correo y contraseña.";
  } else {
    try {
      $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
      $stmt->execute([$email]);
      $user = $stmt->fetch();

      if (!$user) {
        $error = "Correo o contraseña incorrectos.";
      } else {
        // intenta password_hash primero, si no, compara plano
        $dbPass = (string)($user["password"] ?? "");
        $ok = false;

        if ($dbPass !== "" && password_verify($password, $dbPass)) {
          $ok = true;
        } elseif ($dbPass !== "" && hash_equals($dbPass, $password)) {
          $ok = true;
        }

        if (!$ok) {
          $error = "Correo o contraseña incorrectos.";
        } else {
          // guarda en sesión lo que usas en dashboard
          $_SESSION["user"] = [
            "id"    => $user["id"] ?? null,
            "name"  => $user["name"] ?? ($user["full_name"] ?? "Usuario"),
            "email" => $user["email"] ?? $email,
            "role"  => $user["role"] ?? "admin"
          ];

          header("Location: /private/dashboard.php");
          exit;
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
  <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body style="min-height:100vh; display:flex; flex-direction:column;">

  <main style="flex:1; display:flex; align-items:center; justify-content:center; padding:24px;">
    <div style="width:100%; max-width:420px;">
      <h2 style="margin:0 0 14px;">Iniciar sesión</h2>

      <?php if ($error !== ""): ?>
        <div style="background:#ffe5e5; border:1px solid #ffb3b3; padding:10px; border-radius:8px; margin-bottom:12px;">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="/login.php" style="display:flex; flex-direction:column; gap:10px;">
        <label>
          Correo
          <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #ccc;">
        </label>

        <label>
          Contraseña
          <input type="password" name="password" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #ccc;">
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
