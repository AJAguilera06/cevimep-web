<?php
session_start();
if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }

require_once __DIR__ . "/../../config/db.php";
$conn = $pdo;

$user = $_SESSION["user"];
$year = date("Y");
$branch_id = (int)($user["branch_id"] ?? 0);

$patient_id = (int)($_GET["patient_id"] ?? 0);
if ($patient_id <= 0) { header("Location: index.php"); exit; }

$branch_name = "";
if ($branch_id > 0) {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $branch_name = (string)($stB->fetchColumn() ?? "");
}
if ($branch_name === "") $branch_name = ($branch_id>0) ? "Sede #".$branch_id : "CEVIMEP";

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
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Facturación - Paciente</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <style>
    .btnSmall{padding:8px 12px;border-radius:999px;font-weight:900;border:1px solid rgba(2,21,44,.12);background:#eef6ff;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
  </style>
</head>
<body>

<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right"><a href="../../public/logout.php">Salir</a></div>
  </div>
</header>

<main class="app">
  <!-- ✅ SIDEBAR EXACTA COMO TU PANEL -->
  <aside class="sidebar">
    <div class="title">Menú</div>

    <nav class="menu">
      <a href="../dashboard.php">
        <span class="ico">🏠</span> Panel
      </a>

      <a href="../patients/index.php">
        <span class="ico">🧑‍🤝‍🧑</span> Pacientes
      </a>

      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;">
        <span class="ico">🗓️</span> Citas
      </a>

      <a class="active" href="index.php">
        <span class="ico">🧾</span> Facturación
      </a>

      <a href="../caja/index.php" ><span class="ico">💳</span> Caja
      </a>

      <a href="../inventario/index.php">
        <span class="ico">📦</span> Inventario
      </a>

      <a href="../estadistica/index.php">
        <span class="ico">⏳</span> Estadística
      </a>
    </nav>
  </aside>

  <section class="main">
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
        <div>
          <h2 style="margin:0 0 6px;"><?= htmlspecialchars($patient["full_name"]) ?></h2>
          <p class="muted" style="margin:0;">Sucursal: <strong><?= htmlspecialchars($branch_name) ?></strong></p>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <a class="btnSmall" href="index.php">Volver</a>
          <a class="btn" href="nueva.php?patient_id=<?= (int)$patient_id ?>" style="text-decoration:none;">
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
                <td>#<?= (int)$inv["id"] ?></td>
                <td><?= htmlspecialchars($inv["invoice_date"]) ?></td>
                <td><?= htmlspecialchars($inv["payment_method"]) ?></td>
                <td style="font-weight:900;"><?= number_format((float)$inv["total"], 2) ?></td>
                <td><a class="btnSmall" target="_blank" href="print.php?id=<?= (int)$inv["id"] ?>">Detalle</a></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

    </div>
  </section>
</main>

<footer class="footer">
  <div class="inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

</body>
</html>
