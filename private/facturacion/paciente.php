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

  <style>
    .toolbar{
      display:flex;
      justify-content:space-between;
      align-items:flex-start;
      gap:12px;
      flex-wrap:wrap;
      margin-top:14px;
    }

    .btn-ui{
      height:38px;
      border:none;
      border-radius:12px;
      padding:0 14px;
      font-weight:900;
      cursor:pointer;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
    }
    .btn-primary-ui{background:#0b4d87;color:#fff;}
    .btn-secondary-ui{background:#eef2f6;color:#2b3b4a;}

    .table-card{
      background:#fff;
      border-radius:16px;
      box-shadow:0 12px 30px rgba(0,0,0,.08);
      padding:14px;
      margin-top:14px;
    }

    table{width:100%;border-collapse:separate;border-spacing:0;}
    th,td{padding:12px 10px;border-bottom:1px solid #eef2f6;font-size:13px;}
    th{color:#0b4d87;text-align:left;font-weight:900;font-size:12px;}
    tr:last-child td{border-bottom:none;}

    .flash-ok{background:#e9fff1;border:1px solid #a7f0bf;color:#0a7a33;border-radius:12px;padding:10px 12px;font-size:13px;margin-top:12px;}
    .flash-err{background:#ffecec;border:1px solid #ffb6b6;color:#a40000;border-radius:12px;padding:10px 12px;font-size:13px;margin-top:12px;}

    .pill{
      display:inline-flex;align-items:center;gap:8px;
      padding:6px 10px;border-radius:999px;
      font-weight:900;font-size:12px;background:#eef6ff;color:#0b4d87;
      border:1px solid rgba(2,21,44,.12);
      text-decoration:none;
    }
  </style>
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
      <h1><?= h($patient["full_name"] ?? "Paciente") ?></h1>
      <p>Sucursal: <strong><?= h($branch_name) ?></strong></p>
    </section>

    <?php if ($flash_ok): ?><div class="flash-ok"><?= h($flash_ok) ?></div><?php endif; ?>
    <?php if ($flash_err): ?><div class="flash-err"><?= h($flash_err) ?></div><?php endif; ?>

    <div class="toolbar">
      <div>
        <h3 style="margin:0 0 6px;"><?= h($patient["full_name"]) ?></h3>
        <p class="muted" style="margin:0;">Historial de facturas del paciente en esta sucursal.</p>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a class="btn-ui btn-secondary-ui" href="/private/facturacion/index.php">← Volver</a>
        <a class="btn-ui btn-primary-ui" href="/private/facturacion/nueva.php?patient_id=<?= (int)$patient_id ?>">➕ Nueva factura</a>
      </div>
    </div>

    <div class="table-card">
      <h3 style="margin:0 0 10px;">Facturas</h3>

      <table>
        <thead>
          <tr>
            <th style="width:110px;">ID</th>
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
                <td><?= h($inv["invoice_date"]) ?></td>
                <td><?= h($inv["payment_method"]) ?></td>
                <td style="font-weight:900;white-space:nowrap;">RD$ <?= number_format((float)$inv["total"], 2) ?></td>
                <td>
                  <a class="pill" target="_blank" href="/private/facturacion/print.php?id=<?= (int)$inv["id"] ?>">🧾 Ver</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </main>
</div>

<div class="footer">
  <div class="inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</div>

</body>
</html>
