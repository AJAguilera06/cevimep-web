<?php
declare(strict_types=1);

session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

if (empty($_SESSION["user"])) { header("Location: /login.php"); exit; }

require_once __DIR__ . "/../../config/db.php";
$conn = $pdo;

$user = $_SESSION["user"];
$year = (int)date("Y");
$branch_id = (int)($user["branch_id"] ?? 0);

$patient_id = (int)($_GET["patient_id"] ?? 0);
if ($patient_id <= 0) { header("Location: index.php"); exit; }

$branch_name = "";
if ($branch_id > 0) {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $branch_name = (string)($stB->fetchColumn() ?? "");
}
if ($branch_name === "") $branch_name = ($branch_id > 0) ? "Sede #".$branch_id : "CEVIMEP";

/* Paciente (solo de la sucursal) */
$stP = $conn->prepare("
  SELECT p.id, p.branch_id, TRIM(CONCAT(p.first_name,' ',p.last_name)) AS full_name
  FROM patients p
  WHERE p.id=? AND p.branch_id=?
  LIMIT 1
");
$stP->execute([$patient_id, $branch_id]);
$patient = $stP->fetch(PDO::FETCH_ASSOC);
if (!$patient) { echo "Paciente no encontrado en esta sucursal."; exit; }

/* Facturas del paciente en la sucursal */
$invoices = [];
if ($branch_id > 0) {
  $stI = $conn->prepare("
    SELECT id, invoice_date, payment_method, total, created_at
    FROM invoices
    WHERE branch_id = ? AND patient_id = ?
    ORDER BY id DESC
    LIMIT 50
  ");
  $stI->execute([$branch_id, $patient_id]);
  $invoices = $stI->fetchAll(PDO::FETCH_ASSOC);
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Facturación - Paciente</title>

  <!-- MISMO CSS Y VERSION QUE DASHBOARD -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=11">

  <style>
    .btnSmall{
      padding:8px 12px;
      border-radius:999px;
      font-weight:900;
      border:1px solid rgba(2,21,44,.12);
      background:#eef6ff;
      cursor:pointer;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center
    }
  </style>
</head>

<body>

<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right">
      <a class="btn-pill" href="/logout.php">Salir</a>
    </div>
  </div>
</header>

<div class="layout">

  <aside class="sidebar">
    <div class="menu-title">Menú</div>

    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👥</span> Pacientes</a>

      <a href="javascript:void(0)" style="opacity:.45; cursor:not-allowed;">
        <span class="ico">🗓️</span> Citas
      </a>

      <a class="active" href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadistica/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">

    <section class="hero">
      <h1><?php echo h($patient["full_name"] ?? "Paciente"); ?></h1>
      <p>Sucursal: <?php echo h($branch_name); ?></p>
    </section>

    <section class="card">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
        <div>
          <h2 style="margin:0 0 6px;"><?php echo h($patient["full_name"]); ?></h2>
          <p class="muted" style="margin:0;">Sucursal: <strong><?php echo h($branch_name); ?></strong></p>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <a class="btnSmall" href="index.php">Volver</a>
          <a class="btn" href="nueva.php?patient_id=<?php echo (int)$patient_id; ?>" style="text-decoration:none;">
            Registrar nueva factura
          </a>
        </div>
      </div>

      <div style="height:12px;"></div>

      <h3 style="margin:0 0 8px;">Facturas</h3>

      <table class="table">
        <thead>
          <tr>
            <th style="width:120px;">ID</th>
            <th style="width:140px;">Fecha</th>
            <th style="width:160px;">Método</th>
            <th style="width:160px;">Total</th>
            <th style="width:160px;">Detalle</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($invoices)): ?>
            <tr><td colspan="5" class="muted">Este paciente no tiene facturas en esta sucursal.</td></tr>
          <?php else: ?>
            <?php foreach ($invoices as $inv): ?>
              <tr>
                <td>#<?php echo (int)$inv["id"]; ?></td>
                <td><?php echo h($inv["invoice_date"]); ?></td>
                <td><?php echo h($inv["payment_method"]); ?></td>
                <td style="font-weight:900;"><?php echo number_format((float)$inv["total"], 2); ?></td>
                <td>
                  <a class="btnSmall" target="_blank" href="print.php?id=<?php echo (int)$inv["id"]; ?>">Detalle</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

    </section>

  </main>
</div>

<footer class="footer">
  <div class="footer-inner">© <?php echo $year; ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

</body>
</html>
