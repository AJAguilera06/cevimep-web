<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

$user = $_SESSION["user"] ?? [];
$year = (int)date("Y");
$branch_id = (int)($user["branch_id"] ?? 0);

if ($branch_id <= 0) {
  http_response_code(400);
  die("Sucursal inválida (branch_id).");
}

$patient_id = (int)($_GET["patient_id"] ?? 0);
if ($patient_id <= 0) {
  header("Location: /private/facturacion/index.php");
  exit;
}

/* Nombre sucursal */
$branch_name = "";
try {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $branch_name = (string)($stB->fetchColumn() ?: "");
} catch (Throwable $e) {}
if ($branch_name === "") $branch_name = "Sede #".$branch_id;

/* Paciente (solo de la sucursal) */
$stP = $conn->prepare("
  SELECT p.id, p.branch_id, TRIM(CONCAT(p.first_name,' ',p.last_name)) AS full_name
  FROM patients p
  WHERE p.id=? AND p.branch_id=?
  LIMIT 1
");
$stP->execute([$patient_id, $branch_id]);
$patient = $stP->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
  http_response_code(404);
  die("Paciente no encontrado en esta sucursal.");
}

/* Facturas */
$stI = $conn->prepare("
  SELECT id, invoice_date, payment_method, total, created_at
  FROM invoices
  WHERE branch_id = ? AND patient_id = ?
  ORDER BY id DESC
  LIMIT 80
");
$stI->execute([$branch_id, $patient_id]);
$invoices = $stI->fetchAll(PDO::FETCH_ASSOC);

/* Resumen */
$invoice_count = count($invoices);
$invoice_total = 0.0;

$by_method = [
  "EFECTIVO" => 0.0,
  "TARJETA" => 0.0,
  "TRANSFERENCIA" => 0.0,
  "OTRO" => 0.0,
];

foreach ($invoices as $row) {
  $t = (float)($row["total"] ?? 0);
  $invoice_total += $t;

  $m = strtoupper(trim((string)($row["payment_method"] ?? "")));
  if ($m === "EFECTIVO" || $m === "TARJETA" || $m === "TRANSFERENCIA") {
    $by_method[$m] += $t;
  } else {
    $by_method["OTRO"] += $t;
  }
}

/* Flash (si usas) */
$flash_ok = $_SESSION["flash_success"] ?? "";
$flash_err = $_SESSION["flash_error"] ?? "";
unset($_SESSION["flash_success"], $_SESSION["flash_error"]);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Facturación - Paciente</title>

  <!-- Dashboard base -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=50">
  <!-- Facturación UI -->
  <link rel="stylesheet" href="/assets/css/facturacion.css?v=50">
</head>

<body>

<!-- TOPBAR -->
<div class="navbar">
  <div class="inner">
    <div class="brand">
      <span class="dot"></span>
      <strong>CEVIMEP</strong>
    </div>
    <div class="nav-right">
      <a class="btn-pill" href="/logout.php">Salir</a>
    </div>
  </div>
</div>

<div class="layout">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <h3 class="menu-title">Menú</h3>
    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👤</span> Pacientes</a>
      <a href="/private/citas/index.php"><span class="ico">📅</span> Citas</a>
      <a class="active" href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💳</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadisticas/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">

  <section class="fact-center">
    <div class="fact-center-head">
      <h1>Facturación</h1>
      <p>Historial del paciente en esta sucursal</p>
    </div>

    <div class="fact-center-card">

      <div class="fact-center-card-title">
        <h2><?= h($patient["full_name"] ?? "Paciente") ?></h2>
        <span class="fact-badge-branch">Sucursal: <?= h($branch_name) ?></span>
      </div>

      <table class="fact-table fact-table-center">
        <thead>
          <tr>
            <th style="width:110px;">ID</th>
            <th style="width:160px;">Fecha</th>
            <th style="width:190px;">Método</th>
            <th style="width:170px;">Total</th>
            <th style="width:140px;">Detalle</th>
          </tr>
        </thead>

        <tbody>
          <?php if (empty($invoices)): ?>
            <tr>
              <td colspan="5" class="muted">Este paciente no tiene facturas en esta sucursal.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($invoices as $inv): ?>
              <?php
                $pm_raw = strtoupper(trim((string)($inv["payment_method"] ?? "")));
                $pm_class = "otro";
                if ($pm_raw === "EFECTIVO") $pm_class = "efectivo";
                elseif ($pm_raw === "TARJETA") $pm_class = "tarjeta";
                elseif ($pm_raw === "TRANSFERENCIA") $pm_class = "transferencia";
              ?>
              <tr>
                <td>#<?= (int)$inv["id"] ?></td>
                <td><?= h($inv["invoice_date"]) ?></td>
                <td><span class="fact-method <?= h($pm_class) ?>"><?= h($pm_raw ?: "OTRO") ?></span></td>
                <td class="fact-money">RD$ <?= number_format((float)$inv["total"], 2) ?></td>
                <td>
                  <a class="fact-pill" target="_blank" href="/private/facturacion/print.php?id=<?= (int)$inv["id"] ?>">🧾 Ver</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

    </div>

    <div class="fact-center-actions">
      <a class="fact-btn secondary" href="/private/facturacion/index.php">← Volver</a>
      <a class="fact-btn primary" href="/private/facturacion/nueva.php?patient_id=<?= (int)$patient_id ?>">➕ Nueva factura</a>
    </div>

  </section>

</main>

</div>

<div class="footer">
  <div class="inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</div>

</body>
</html>
