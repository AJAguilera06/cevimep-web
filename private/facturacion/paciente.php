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

/* =========================
   PAGINACIÓN + RESUMEN
   ========================= */
$per_page = 20;
$page = max(1, (int)($_GET["page"] ?? 1));
$offset = ($page - 1) * $per_page;

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

$total_pages = max(1, (int)ceil($total_invoices / $per_page));
if ($page > $total_pages) $page = $total_pages;

/* Facturas (página actual) */
$stI = $conn->prepare("
  SELECT id, invoice_date, payment_method, total, created_at
  FROM invoices
  WHERE branch_id = ? AND patient_id = ?
  ORDER BY id DESC
  LIMIT $per_page OFFSET $offset
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
  <link rel="stylesheet" href="/assets/css/styles.css?v=60">

  <!-- Estilos finos SOLO para esta pantalla -->
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
    .btn-ui:active{ transform: translateY(0); box-shadow: none; }

    .btn-primary-ui{ background:#0b4d87; color:#fff; }
    .btn-secondary-ui{ background:#eef2f6; color:#2b3b4a; }

    /* fila info paciente */
    .fact-meta{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 12px;
      flex-wrap:wrap;
      margin-bottom: 14px;
    }

    .fact-patient{
      display:flex;
      flex-direction:column;
      gap:4px;
      min-width: 260px;
    }
    .fact-patient .name{
      font-weight: 950;
      font-size: 16px;
      letter-spacing: -.2px;
    }
    .fact-patient .branch{
      opacity: .8;
      font-weight: 850;
      font-size: 13px;
    }

    .chips{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      align-items:center;
    }
    .chip{
      background:#f3f6fb;
      border:1px solid rgba(2,21,44,.10);
      padding: 8px 10px;
      border-radius: 999px;
      font-weight: 900;
      font-size: 12px;
      white-space: nowrap;
    }
    .chip strong{ font-weight: 950; }

    /* Card tabla */
    .table-card{
      background:#fff;
      border-radius:18px;
      box-shadow:0 12px 30px rgba(0,0,0,.08);
      padding:14px;
    }
    .table-card .card-head{
      display:flex;
      justify-content:space-between;
      align-items:flex-end;
      gap: 10px;
      flex-wrap:wrap;
      margin-bottom: 10px;
    }
    .table-card h3{
      margin:0;
      font-weight: 950;
    }

    /* Wrapper tabla: NO se rompe con muchas facturas */
    .table-wrap{
      width:100%;
      overflow-x:auto;
      overflow-y:auto;
      max-height: 520px;
      border-radius: 14px;
      border:1px solid rgba(2,21,44,.06);
      -webkit-overflow-scrolling: touch;
    }

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
      white-space: nowrap;
    }

    /* Paginación */
    .pagination{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      flex-wrap:wrap;
      margin-top:12px;
    }
    .pg-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:9px 12px;
      border-radius:12px;
      font-weight:900;
      text-decoration:none;
      border:1px solid rgba(2,21,44,.12);
      background:#eef6ff;
      color:#0b4d87;
      transition: filter .15s ease, transform .12s ease;
    }
    .pg-btn:hover{ filter: brightness(.98); transform: translateY(-1px); }
    .pg-btn:active{ transform: translateY(0); }
    .pg-btn.disabled{ pointer-events:none; opacity:.5; transform:none; }
    .pg-info{ font-weight:900; opacity:.85; }

    /* Flash */
    .flash-ok{ background:#e9fff1; border:1px solid #a7f0bf; color:#0a7a33; border-radius:12px; padding:10px 12px; font-size:13px; margin:12px 0 0; font-weight:800; }
    .flash-err{ background:#ffecec; border:1px solid #ffb6b6; color:#a40000; border-radius:12px; padding:10px 12px; font-size:13px; margin:12px 0 0; font-weight:800; }

    /* Responsive */
    @media (max-width: 980px){
      .fact-title h1{ font-size: 28px; }
      table{ min-width: 740px; }
    }
  </style>
</head>

<body>

<!-- NAVBAR (igual al dashboard) -->
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

  <!-- SIDEBAR (igual al dashboard) -->
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
    <div class="fact-page">

      <div class="fact-header">
        <div class="fact-title">
          <h1>Facturación</h1>
          <p>Historial del paciente en esta sucursal</p>
        </div>

        <div class="fact-actions">
          <a class="btn-ui btn-secondary-ui" href="/private/facturacion/index.php">← Volver</a>
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

        <?php if ($total_pages > 1): ?>
          <?php
            $base = "/private/facturacion/paciente.php?patient_id=".(int)$patient_id;
            $prev = max(1, $page - 1);
            $next = min($total_pages, $page + 1);
          ?>
          <div class="pagination">
            <a class="pg-btn <?= $page <= 1 ? "disabled" : "" ?>" href="<?= $base ?>&page=<?= (int)$prev ?>">← Anterior</a>
            <div class="pg-info">Página <strong><?= (int)$page ?></strong> de <strong><?= (int)$total_pages ?></strong></div>
            <a class="pg-btn <?= $page >= $total_pages ? "disabled" : "" ?>" href="<?= $base ?>&page=<?= (int)$next ?>">Siguiente →</a>
          </div>
        <?php endif; ?>

      </div>

    </div>
  </main>
</div>

<div class="footer">
  <div class="inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</div>

</body>
</html>
