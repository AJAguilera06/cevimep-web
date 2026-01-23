<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

/**
 * Página de entrada del área privada.
 * Si el usuario está autenticado, lo enviamos al dashboard.
 */
header("Location: /private/dashboard.php");
exit;



/* Nombre sucursal */
$branch_name = "";
try {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $branch_name = (string)($stB->fetchColumn() ?: "");
} catch (Throwable $e) {}
if ($branch_name === "") $branch_name = "Sede #".$branch_id;

/* Paciente */
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

/* Facturas del paciente en la sucursal */
$invoices = [];
$stI = $conn->prepare("
  SELECT id, invoice_date, payment_method, total, created_at
  FROM invoices
  WHERE branch_id = ? AND patient_id = ?
  ORDER BY id DESC
  LIMIT 50
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
  "OTRO" => 0.0
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

/* Flash */
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

  <!-- ✅ MISMO CSS QUE DASHBOARD -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=30">
  <!-- ✅ CSS FACTURACIÓN (para esta pantalla y las demás de facturación) -->
  <link rel="stylesheet" href="/assets/css/facturacion.css?v=1">
</head>

<body>

<!-- NAVBAR (IGUAL AL dashboard.php) -->
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

  <!-- SIDEBAR (IGUAL AL dashboard.php) -->
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

    <section class="hero">
      <h1>Facturación</h1>
      <p>Paciente: <strong><?= h($patient["full_name"] ?? "Paciente") ?></strong> · Sucursal: <strong><?= h($branch_name) ?></strong></p>
    </section>

    <?php if ($flash_ok): ?><div class="fact-flash-ok"><?= h($flash_ok) ?></div><?php endif; ?>
    <?php if ($flash_err): ?><div class="fact-flash-err"><?= h($flash_err) ?></div><?php endif; ?>

    <div class="fact-patient-grid">

      <!-- LEFT: PACIENTE -->
      <section class="fact-patient-card">
        <h2 class="fact-patient-title"><?= h($patient["full_name"] ?? "Paciente") ?></h2>
        <span class="fact-badge-branch">Sucursal: <?= h($branch_name) ?></span>

        <p class="fact-patient-sub">Historial de facturas del paciente en esta sucursal.</p>

        <div class="fact-patient-stats">
          <div class="fact-stat">
            <small>Total facturas</small>
            <strong><?= (int)$invoice_count ?></strong>
          </div>
          <div class="fact-stat">
            <small>Monto total</small>
            <strong>RD$ <?= number_format((float)$invoice_total, 2) ?></strong>
          </div>
        </div>

        <div class="fact-patient-actions">
          <a class="fact-btn secondary" href="/private/facturacion/index.php">← Volver</a>
          <a class="fact-btn primary" href="/private/facturacion/nueva.php?patient_id=<?= (int)$patient_id ?>">➕ Nueva factura</a>
        </div>
      </section>

      <!-- RIGHT: FACTURAS -->
      <section class="fact-card-wide">
        <div class="head">
          <h3>Facturas</h3>
        </div>

        <table class="fact-table">
          <thead>
            <tr>
              <th style="width:110px;">ID</th>
              <th style="width:140px;">Fecha</th>
              <th style="width:180px;">Método</th>
              <th style="width:170px;">Total</th>
              <th style="width:160px;">Detalle</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($invoices)): ?>
              <tr><td colspan="5" class="muted">Este paciente no tiene facturas en esta sucursal.</td></tr>
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
      </section>

    </div>

  </main>
</div>

<div class="footer">
  <div class="inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</div>

</body>
</html>
