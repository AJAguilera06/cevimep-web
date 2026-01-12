<?php
session_start();

if (!isset($_SESSION["user"])) {
  header("Location: /login.php");
  exit;
}

$user = $_SESSION["user"];
$year = date("Y");

$displayName = trim((string)($user["name"] ?? ""));
if ($displayName === "") $displayName = "Administrador";

$role = (string)($user["role"] ?? "admin");
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP - Dashboard</title>
  <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body style="min-height:100vh; display:flex; flex-direction:column;">

<main style="flex:1; padding:24px;">
  <h2 style="margin:0 0 10px;">Panel interno</h2>
  <p style="margin:0 0 14px;">Hola, <b><?= htmlspecialchars($displayName) ?></b> — Rol: <b><?= htmlspecialchars($role) ?></b></p>

  <div style="display:flex; gap:10px; flex-wrap:wrap;">
    <a href="/private/dashboard.php" style="text-decoration:none; padding:10px 12px; border:1px solid #ccc; border-radius:10px;">Dashboard</a>
    <a href="/logout.php" style="text-decoration:none; padding:10px 12px; border:1px solid #ccc; border-radius:10px;">Cerrar sesión</a>
  </div>
</main>

<footer class="footer">
  <div class="inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

</body>
</html>
