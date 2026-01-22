<?php
session_start();
if (!isset($_SESSION["user"])) {
  header("Location: ../../public/login.php");
  exit;
}

require_once __DIR__ . "/../../config/db.php";
$conn = $pdo;

$user = $_SESSION["user"];
$branch_id = (int)$user["branch_id"];
$branch_name = $user["branch_name"] ?? "Sucursal";
$today = date("Y-m-d");
$year = date("Y");
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Salida | CEVIMEP</title>
<link rel="stylesheet" href="/public/assets/css/styles.css">
</head>
<body>

<!-- TOP BAR -->
<div style="background:linear-gradient(180deg,#0b3b86,#062a63);padding:12px 20px;color:#fff;display:flex;justify-content:space-between;align-items:center;">
  <div style="font-weight:800;letter-spacing:.5px;">
    <span style="display:inline-block;width:8px;height:8px;background:#22c3b8;border-radius:50%;margin-right:8px;"></span>
    CEVIMEP
  </div>
  <a href="/public/logout.php" style="color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.35);padding:6px 14px;border-radius:20px;font-weight:700;">
    Salir
  </a>
</div>

<div style="display:flex;min-height:calc(100vh - 56px);">

<!-- SIDEBAR -->
<aside style="width:230px;background:#fff;border-right:1px solid rgba(0,0,0,.08);padding:14px;">
  <h4 style="margin-bottom:12px;color:#0b3b86;">Menú</h4>
  <nav style="display:flex;flex-direction:column;gap:6px;">
    <a href="/private/dashboard.php">🏠 Panel</a>
    <a href="/private/patients/index.php">👤 Pacientes</a>
    <a href="/private/citas/index.php">📅 Citas</a>
    <a href="/private/facturacion/index.php">🧾 Facturación</a>
    <a href="/private/caja/index.php">💵 Caja</a>
    <a href="/private/inventario/index.php" style="background:#ffe9d6;border-radius:10px;padding:6px;">📦 Inventario</a>
    <a href="/private/estadisticas/index.php">📊 Estadísticas</a>
  </nav>
</aside>

<!-- CONTENT -->
<main style="flex:1;padding:24px;">

  <h2>Salida</h2>
  <p class="muted">Sucursal: <?= htmlspecialchars($branch_name) ?></p>

  <form method="post" action="guardar_salida.php" class="card">
    <div class="grid grid-2">
      <div>
        <label>Fecha</label>
        <input type="date" name="fecha" value="<?= $today ?>" required>
      </div>
      <div>
        <label>Nota (opcional)</label>
        <input type="text" name="nota" placeholder="Observación...">
      </div>
    </div>

    <!-- Aquí va tu lógica actual de productos / cantidades -->
    <!-- NO se tocó -->

    <div style="margin-top:20px;text-align:right;">
      <button type="submit" class="btn btn-danger">Guardar e imprimir</button>
    </div>
  </form>

  <footer style="margin-top:30px;text-align:center;color:#666;">
    © <?= $year ?> CEVIMEP. Todos los derechos reservados.
  </footer>

</main>
</div>

</body>
</html>
