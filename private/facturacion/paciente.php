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
  http_response_code(400);
  die("Paciente inválido (patient_id).");
}

/** Datos del paciente (solo en esta sucursal) */
$stmt = $conn->prepare("
  SELECT p.id, p.first_name, p.last_name, b.name AS branch_name
  FROM patients p
  INNER JOIN branches b ON b.id = p.branch_id
  WHERE p.id = :pid AND p.branch_id = :bid
  LIMIT 1
");
$stmt->execute(["pid" => $patient_id, "bid" => $branch_id]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
  http_response_code(404);
  die("Paciente no encontrado en esta sucursal.");
}

/** Facturas del paciente en esta sucursal */
$stmt = $conn->prepare("
  SELECT i.id,
         DATE(i.created_at) AS invoice_date,
         i.payment_method,
         i.total
  FROM invoices i
  WHERE i.patient_id = :pid
    AND i.branch_id  = :bid
  ORDER BY i.id DESC
");
$stmt->execute(["pid" => $patient_id, "bid" => $branch_id]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

/** Totales */
$total_count = count($invoices);
$total_amount = 0.0;
foreach ($invoices as $inv) {
  $total_amount += (float)$inv["total"];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Facturación</title>

  <link rel="stylesheet" href="../assets/css/style.css">
  <link rel="stylesheet" href="../assets/css/dashboard.css">

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
      gap:10px;
      transition: transform .08s ease;
    }
    .btn-ui:active{ transform: scale(.98); }
    .btn-light{ background:#fff; border:1px solid #dbe8f7; color:#0b4d87; }
    .btn-primary{ background:#0b63b6; color:#fff; }

    .cards{
      display:grid;
      grid-template-columns: 1fr;
      gap: 12px;
    }

    .card{
      background: rgba(255,255,255,.88);
      border: 1px solid rgba(210,230,250,.9);
      border-radius: 18px;
      box-shadow: 0 14px 36px rgba(0,0,0,.08);
      padding: 16px;
    }

    .patient-head{
      text-align:center;
      margin-bottom: 10px;
    }
    .patient-name{
      font-weight: 950;
      font-size: 18px;
      margin: 0;
    }
    .patient-branch{
      margin: 2px 0 0;
      opacity: .75;
      font-weight: 850;
      font-size: 13px;
    }

    .totals{
      display:flex;
      justify-content:flex-end;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 8px;
    }
    .chip{
      border: 1px solid rgba(210,230,250,.95);
      border-radius: 999px;
      padding: 8px 12px;
      background:#fff;
      font-weight: 950;
      color:#0b4d87;
      font-size: 13px;
      display:inline-flex;
      gap: 8px;
      align-items:center;
      white-space: nowrap;
    }

    .table-wrap{
      margin-top: 8px;
    }
    .table-title{
      margin: 0 0 10px;
      font-size: 18px;
      font-weight: 950;
      color:#0b4d87;
    }

    table{ width:100%; border-collapse:separate; border-spacing:0; min-width: 820px; }
    th, td{ padding:12px 10px; border-bottom:1px solid #eef2f6; font-size:13px; }
    th{
      color:#0b4d87; text-align:left; font-weight:950; font-size:12px;
      position: sticky; top: 0; background:#fff; z-index:2;
    }
    tr:last-child td{ border-bottom:none; }

    /* Scroll: mostrar máx. 5 facturas visibles y luego scroll */
    .table-scroll{
      max-height: 360px; /* ~5 filas + encabezado */
      overflow: auto;
      border: 1px solid #eef2f6;
      border-radius: 16px;
      background:#fff;
      -webkit-overflow-scrolling: touch;
    }

    .money{ font-weight: 950; white-space: nowrap; }

    .pill{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding: 7px 12px;
      border-radius: 999px;
      border:1px solid #dbe8f7;
      background:#f7fbff;
      font-weight: 900;
      color:#0b4d87;
      font-size: 12px;
      text-decoration:none;
    }
    .muted{ opacity:.75; font-weight: 800; text-align:center; padding: 18px 10px; }

    .content-inner{
      padding: 0 14px 16px;
    }
  </style>
</head>

<body>

<?php include __DIR__ . "/../partials/sidebar.php"; ?>

<div class="main-content">
  <?php include __DIR__ . "/../partials/topbar.php"; ?>

  <div class="content-inner">
    <div class="fact-page">

      <div class="fact-header">
        <div class="fact-title">
          <h1>Facturación</h1>
          <p>Historial del paciente en esta sucursal</p>
        </div>

        <div class="fact-actions">
          <a class="btn-ui btn-light" href="index.php">← Volver</a>
          <a class="btn-ui btn-primary" href="nueva.php?patient_id=<?= (int)$patient_id ?>">➕ Nueva factura</a>
        </div>
      </div>

      <div class="cards">
        <div class="card">
          <div class="patient-head">
            <p class="patient-name">
              <?= h(($patient["first_name"] ?? "") . " " . ($patient["last_name"] ?? "")) ?>
            </p>
            <p class="patient-branch">Sucursal: <?= h($patient["branch_name"] ?? "") ?></p>

            <div class="totals">
              <span class="chip">Total facturas: <strong><?= (int)$total_count ?></strong></span>
              <span class="chip">Monto total: <strong>RD$ <?= number_format((float)$total_amount, 2) ?></strong></span>
            </div>
          </div>

          <div class="table-wrap">
            <h3 class="table-title">Facturas</h3>

            <div class="table-scroll">
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
                      <a class="pill" href="ver.php?id=<?= (int)$inv["id"] ?>">📄 Ver</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
                    </table>
        </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

</body>
</html>
