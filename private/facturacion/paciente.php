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

/* Datos del paciente (en esta sucursal) */
$stP = $conn->prepare("
  SELECT id, full_name
  FROM patients
  WHERE branch_id = ? AND id = ?
  LIMIT 1
");
$stP->execute([$branch_id, $patient_id]);
$patient = $stP->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
  $_SESSION["flash_error"] = "Paciente no encontrado en esta sucursal.";
  header("Location: /private/facturacion/index.php");
  exit;
}

/* Nombre sucursal */
$branch_name = (string)($user["branch_name"] ?? "Sucursal");

/* Total facturas + monto total (global) */
$stSum = $conn->prepare("
  SELECT COUNT(*) AS c, COALESCE(SUM(total),0) AS s
  FROM invoices
  WHERE branch_id = ? AND patient_id = ?
");
$stSum->execute([$branch_id, $patient_id]);
$sumRow = $stSum->fetch(PDO::FETCH_ASSOC);

$total_invoices = (int)($sumRow["c"] ?? 0);
$total_amount   = (float)($sumRow["s"] ?? 0);

/* Facturas (todas) */
$stI = $conn->prepare("
  SELECT id, invoice_date, payment_method, total, created_at
  FROM invoices
  WHERE branch_id = ? AND patient_id = ?
  ORDER BY id DESC
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

  <!-- Dashboard base -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=<?= time() ?>">

  <style>
    /* Layout general de esta pantalla */
    .fact-page{
      max-width: 1280px;
      margin: 0 auto;
      padding: 22px 18px 10px;
    }

    .fact-header{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap: 14px;
      flex-wrap:wrap;
      margin-bottom: 14px;
    }
    .fact-title h1{
      margin:0;
      font-size: 34px;
      font-weight: 950;
      letter-spacing: -.3px;
    }
    .fact-title p{
      margin: 6px 0 0;
      opacity:.78;
      font-weight: 800;
    }

    .fact-actions{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      align-items:center;
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
      transition: transform .12s ease, filter .15s ease, box-shadow .18s ease;
      white-space: nowrap;
    }
    .btn-ui:hover{ filter: brightness(.98); transform: translateY(-1px); box-shadow: 0 10px 22px rgba(0,0,0,.08); }
    .btn-ui:active{ transform: translateY(0); box-shadow:none; }

    .btn-primary-ui{
      background: #0b4d87;
      color:#fff;
    }
    .btn-ghost-ui{
      background:#fff;
      color:#0b4d87;
      border:1px solid rgba(2,21,44,.12);
    }

    .flash-ok, .flash-err{
      margin: 10px 0 14px;
      padding: 12px 14px;
      border-radius: 14px;
      font-weight: 900;
      font-size: 13px;
    }
    .flash-ok{ background:#e8fff1; color:#0a7a3a; border:1px solid rgba(10,122,58,.15); }
    .flash-err{ background:#fff0f0; color:#b42318; border:1px solid rgba(180,35,24,.16); }

    .fact-meta{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap: 12px;
      flex-wrap:wrap;
      margin-bottom: 12px;
    }
    .fact-patient .name{
      font-size: 18px;
      font-weight: 950;
      margin-bottom: 4px;
    }
    .fact-patient .branch{
      font-size: 13px;
      opacity:.75;
      font-weight: 900;
    }

    .chips{
      display:flex;
      gap: 10px;
      flex-wrap:wrap;
      align-items:center;
      justify-content:flex-end;
    }
    .chip{
      background:#fff;
      border:1px solid rgba(2,21,44,.10);
      border-radius: 999px;
      padding: 8px 12px;
      font-weight: 950;
      font-size: 13px;
      box-shadow: 0 6px 18px rgba(0,0,0,.04);
      white-space: nowrap;
    }

    .table-card{
      background:#fff;
      border-radius: 18px;
      border:1px solid rgba(2,21,44,.10);
      box-shadow: 0 10px 30px rgba(0,0,0,.06);
      overflow:hidden;
    }
    .card-head{
      padding: 14px 16px;
      border-bottom:1px solid rgba(2,21,44,.08);
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
    }
    .card-head h3{
      margin:0;
      font-weight: 950;
      font-size: 16px;
    }

    /* ✅ AQUÍ el fix: solo ~5 filas visibles, luego scroll */
    .table-wrap{
      width:100%;
      overflow-x:auto;
      overflow-y:auto;
      max-height: 330px; /* ~5 filas aprox */
      border-radius: 14px;
      border:1px solid rgba(2,21,44,.06);
      -webkit-overflow-scrolling: touch;
    }

    /* Scroll bonito */
    .table-wrap::-webkit-scrollbar{ width: 8px; height: 8px; }
    .table-wrap::-webkit-scrollbar-track{ background: #f1f5f9; border-radius: 999px; }
    .table-wrap::-webkit-scrollbar-thumb{ background: rgba(11,77,135,.55); border-radius: 999px; }
    .table-wrap::-webkit-scrollbar-thumb:hover{ background: rgba(11,77,135,.8); }

    table{ width:100%; border-collapse:separate; border-spacing:0; min-width: 820px; }
    th, td{ padding:12px 10px; border-bottom:1px solid #eef2f6; font-size:13px; }
    th{
      color:#0b4d87; text-align:left; font-weight:950; font-size:12px;
      position: sticky; top: 0; background:#fff; z-index:2;
    }
    tr:last-child td{ border-bottom:none; }

    .money{ font-weight: 950; white-space: nowrap; }

    .pill{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:6px 10px;
      border-radius:999px;
      font-weight:900;
      font-size:12px;
      background:#eef6ff;
      color:#0b4d87;
      border:1px solid rgba(2,21,44,.12);
      text-decoration:none;
      transition: transform .12s ease, filter .15s ease;
      white-space: nowrap;
    }
    .pill:hover{ filter: brightness(.98); transform: translateY(-1px); }
    .muted{ color: rgba(2,21,44,.60); font-weight: 800; text-align:center; padding: 18px 10px; }

    @media (max-width: 560px){
      .fact-title h1{ font-size: 28px; }
      .table-wrap{ max-height: 320px; }
      table{ min-width: 720px; }
    }
  </style>
</head>

<body>

<div class="app">
  <?php include __DIR__ . "/../_sidebar.php"; ?>

  <main class="main">
    <?php include __DIR__ . "/../_topbar.php"; ?>

    <div class="fact-page">

      <div class="fact-header">
        <div class="fact-title">
          <h1>Facturación</h1>
          <p>Historial del paciente en esta sucursal</p>
        </div>

        <div class="fact-actions">
          <a class="btn-ui btn-ghost-ui" href="/private/facturacion/index.php">← Volver</a>
          <a class="btn-ui btn-primary-ui" href="/private/facturacion/nueva.php?patient_id=<?= (int)$patient_id ?>">➕ Nueva factura</a>
        </div>
      </div>

      <?php if ($flash_ok): ?><div class="flash-ok"><?= h($flash_ok) ?></div><?php endif; ?>
      <?php if ($flash_err): ?><div class="flash-err"><?= h($flash_err) ?></div><?php endif; ?>

      <div class="fact-meta">
        <div class="fact-patient">
          <div class="name"><?= h($patient["full_name"] ?? "Paciente") ?></div>
          <div class="branch">Sucursal: <strong><?= h($branch_name) ?></strong></div>
        </div>

        <div class="chips">
          <div class="chip">Total facturas: <strong><?= (int)$total_invoices ?></strong></div>
          <div class="chip">Monto total: <strong>RD$ <?= number_format((float)$total_amount, 2) ?></strong></div>
        </div>
      </div>

      <div class="table-card">
        <div class="card-head">
          <h3>Facturas</h3>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th style="width:110px;">ID</th>
                <th style="width:150px;">Fecha</th>
                <th style="width:170px;">Método</th>
                <th style="width:170px;">Total</th>
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
                    <td class="money">RD$ <?= number_format((float)$inv["total"], 2) ?></td>
                    <td>
                      <a class="pill" target="_blank" href="/private/facturacion/print.php?id=<?= (int)$inv["id"] ?>">🧾 Ver</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </main>
</div>

<div class="footer">
  <div class="inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</div>

</body>
</html>
