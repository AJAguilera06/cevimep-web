<?php
// private/caja/movimiento_diario.php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";

date_default_timezone_set("America/Santo_Domingo");

$user = $_SESSION["user"] ?? [];
$isAdmin = (($user["role"] ?? "") === "admin");
$userBranchId = (int)($user["branch_id"] ?? 0);
$branchId = $userBranchId;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function money($n): string { return number_format((float)$n, 2, ".", ","); }

function colExists(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$column]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return false;
  }
}

$today = date("Y-m-d");
$date = trim((string)($_GET["date"] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = $today;

$branchesList = [];
if ($isAdmin) {
  try {
    $stBranches = $pdo->query("SELECT id, name FROM branches ORDER BY name ASC");
    $branchesList = $stBranches->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $requestedBranchId = (int)($_GET["branch_id"] ?? 0);
    if ($requestedBranchId > 0) {
      $branchId = $requestedBranchId;
    } elseif ($branchId <= 0 && !empty($branchesList)) {
      $branchId = (int)$branchesList[0]["id"];
    }
  } catch (Throwable $e) {}
}

if ($branchId <= 0) {
  http_response_code(400);
  die("Sucursal inválida.");
}

$branchName = (string)($user["branch_name"] ?? $user["branch"] ?? "");
try {
  $stB = $pdo->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branchId]);
  $bn = $stB->fetchColumn();
  if ($bn) $branchName = (string)$bn;
} catch (Throwable $e) {}

$hasInvoiceCode = true; // columna existente en tu tabla invoices
$hasSubtotal = colExists($pdo, "invoices", "subtotal");
$hasCoverage = colExists($pdo, "invoices", "coverage_amount");
$hasCreatedAt = colExists($pdo, "invoices", "created_at");
$hasInvoiceDate = colExists($pdo, "invoices", "invoice_date");

$invoiceCodeSql = "COALESCE(NULLIF(i.invoice_code, ''), CONCAT('#', i.id))";
$subtotalSql = $hasSubtotal ? "i.subtotal" : "i.total";
$coverageSql = $hasCoverage ? "i.coverage_amount" : "0";
$dateSelectSql = $hasInvoiceDate ? "i.invoice_date" : ($hasCreatedAt ? "DATE(i.created_at)" : "CURDATE()");
// ✅ Filtrar por UNA sola fecha para evitar mezclar facturas de otros días.
// Prioridad: invoice_date (fecha elegida en la factura).
// Solo si no existe invoice_date, usar created_at.
if ($hasInvoiceDate) {
  $dateWhereSql = "i.invoice_date = :date1";
} elseif ($hasCreatedAt) {
  $dateWhereSql = "DATE(i.created_at) = :date2";
} else {
  $dateWhereSql = "1=1";
}

$patientNameExpr = "TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,'')))";
if (colExists($pdo, "patients", "full_name")) {
  $patientNameExpr = "p.full_name";
} elseif (colExists($pdo, "patients", "name")) {
  $patientNameExpr = "p.name";
} elseif (colExists($pdo, "patients", "nombre")) {
  $patientNameExpr = "p.nombre";
}

$error = "";
$rows = [];
$totFacturado = 0.0;
$totCobertura = 0.0;
$totGeneral = 0.0;
$totPagado = 0.0;
$totDesembolsos = 0.0;
$neto = 0.0;

try {
  // Facturas de la sucursal por la fecha seleccionada, sin mezclar invoice_date con created_at.
  $sql = "
    SELECT
      i.id,
      {$invoiceCodeSql} AS factura,
      i.invoice_code AS invoice_code,
      {$patientNameExpr} AS cliente,
      {$dateSelectSql} AS fecha,
      COALESCE({$subtotalSql}, 0) AS subtotal,
      COALESCE({$coverageSql}, 0) AS cobertura_db,
      COALESCE(i.total, 0) AS total
    FROM invoices i
    LEFT JOIN patients p ON p.id = i.patient_id
    WHERE i.branch_id = :branch_id
      AND {$dateWhereSql}
    ORDER BY i.id ASC
  ";

  $st = $pdo->prepare($sql);
  $params = [':branch_id' => $branchId];
  if ($hasInvoiceDate) {
    $params[':date1'] = $date;
  } elseif ($hasCreatedAt) {
    $params[':date2'] = $date;
  }
  $st->execute($params);
  $invoices = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Pagos/coberturas por factura, según movimientos reales de caja.
  $stMov = $pdo->prepare(" 
    SELECT
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago IN ('cobertura','seguro') THEN amount END),0) AS cobertura_mov,
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago NOT IN ('cobertura','seguro') THEN amount END),0) AS pagado_mov
    FROM cash_movements
    WHERE branch_id = ?
      AND type = 'ingreso'
      AND DATE(created_at) = ?
      AND (motivo LIKE ? OR motivo LIKE ?)
  ");

  foreach ($invoices as $inv) {
    $invoiceId = (int)$inv["id"];
    $facturaCode = trim((string)($inv["invoice_code"] ?? ""));
    if ($facturaCode === "") {
      $facturaCode = trim((string)($inv["factura"] ?? ""));
    }
    $stMov->execute([$branchId, $date, "%factura #" . $invoiceId . "%", $facturaCode !== "" ? "%" . $facturaCode . "%" : "%factura #" . $invoiceId . "%"]);
    $mov = $stMov->fetch(PDO::FETCH_ASSOC) ?: ["cobertura_mov" => 0, "pagado_mov" => 0];

    $coberturaDb = (float)($inv["cobertura_db"] ?? 0);
    $coberturaMov = (float)($mov["cobertura_mov"] ?? 0);
    $cobertura = max($coberturaDb, $coberturaMov);

    $pagadoMov = (float)($mov["pagado_mov"] ?? 0);
    $pagado = $pagadoMov > 0 ? $pagadoMov : (float)($inv["total"] ?? 0);

    $subtotal = (float)($inv["subtotal"] ?? 0);
    $totalGeneral = $subtotal > 0 ? $subtotal : ($pagado + $cobertura);
    $montoFacturado = $totalGeneral;

    $rows[] = [
      "tipo" => "FACTURA",
      "factura" => $facturaCode !== "" ? $facturaCode : ("#" . $invoiceId),
      "cliente" => trim((string)($inv["cliente"] ?? "")) ?: "—",
      "fecha" => substr((string)($inv["fecha"] ?? $date), 0, 10),
      "monto_facturado" => $montoFacturado,
      "cobertura" => $cobertura,
      "total_general" => $totalGeneral,
      "pagado" => $pagado,
      "is_desembolso" => false,
    ];

    $totFacturado += $montoFacturado;
    $totCobertura += $cobertura;
    $totGeneral += $totalGeneral;
    $totPagado += $pagado;
  }

  // Desembolsos de la sucursal en la fecha seleccionada.
  $stDes = $pdo->prepare(" 
    SELECT id, motivo, amount, created_at
    FROM cash_movements
    WHERE branch_id = ?
      AND type = 'desembolso'
      AND DATE(created_at) = ?
    ORDER BY id ASC
  ");
  $stDes->execute([$branchId, $date]);
  $desembolsos = $stDes->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($desembolsos as $d) {
    $monto = abs((float)($d['amount'] ?? 0));
    $motivo = trim((string)($d['motivo'] ?? ''));
    $rows[] = [
      "tipo" => "DESEMBOLSO",
      "factura" => "DES-" . (int)$d['id'],
      "cliente" => $motivo !== '' ? $motivo : 'Desembolso',
      "fecha" => substr((string)($d['created_at'] ?? $date), 0, 10),
      "monto_facturado" => 0,
      "cobertura" => 0,
      "total_general" => -$monto,
      "pagado" => -$monto,
      "is_desembolso" => true,
    ];
    $totDesembolsos += $monto;
  }

  usort($rows, function($a, $b){
    return strcmp((string)$a['fecha'], (string)$b['fecha']) ?: strcmp((string)$a['factura'], (string)$b['factura']);
  });

  $neto = $totPagado - $totDesembolsos;

} catch (Throwable $e) {
  $error = "Error generando movimiento diario: " . $e->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Movimiento diario</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=130">
  <style>
    .page-wrap{max-width:1250px;width:100%;margin:0 auto;}
    .report-card{background:#fff;border-radius:22px;box-shadow:0 12px 34px rgba(0,0,0,.08);padding:18px;}
    .head-row{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:14px;}
    .title{margin:0;font-size:38px;line-height:1.1;font-weight:950;}
    .sub{margin:6px 0 0;color:#475569;font-weight:800;}
    .actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end;}
    .btn-local{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1px solid #dbeafe;background:#fff;color:#0b4d87!important;border-radius:999px;padding:10px 14px;font-weight:900;text-decoration:none;cursor:pointer;}
    .btn-local.primary{background:#0b4d87;color:#fff!important;border-color:#0b4d87;}
    .filter{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:14px;background:#f8fafc;border:1px solid #e6eef7;border-radius:16px;padding:12px;}
    .field label{display:block;font-size:12px;font-weight:900;color:#475569;margin-bottom:5px;}
    .field input,.field select{height:38px;border-radius:12px;border:1px solid #dbe3ea;padding:0 10px;font-weight:800;background:#fff;}
    .table-wrap{overflow:auto;border:1px solid #e6eef7;border-radius:18px;}
    table{width:100%;min-width:1050px;border-collapse:collapse;background:#fff;}
    th,td{padding:12px 10px;border-bottom:1px solid #eef2f7;text-align:left;font-size:13px;}
    th{background:#f7fbff;color:#0b4d87;font-weight:950;white-space:nowrap;}
    td.money,th.money{text-align:right;white-space:nowrap;font-weight:900;}
    .badge{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:950;border:1px solid #dbeafe;background:#eff6ff;color:#1e40af;}
    .badge.des{background:#fff7ed;border-color:#fed7aa;color:#9a3412;}
    .des-row td{background:#fffaf0;}
    tfoot td{background:#f8fafc;font-weight:950;}
    .empty{text-align:center;padding:20px!important;color:#64748b;font-weight:800;}
    .danger{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;border-radius:14px;padding:12px;margin-bottom:12px;font-weight:800;}
    .summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px;}
    .sum-card{background:#f8fafc;border:1px solid #e6eef7;border-radius:16px;padding:12px;font-weight:900;}
    .sum-card small{display:block;color:#64748b;margin-bottom:5px;font-weight:900;}
    @media(max-width:900px){.title{font-size:31px}.actions{justify-content:flex-start}.filter{align-items:stretch}.field,.field input,.field select,.btn-local{width:100%;}.summary{grid-template-columns:1fr;}}
    @media print{
      @page{margin:9mm;}
      body{background:#fff!important;}
      .navbar,.sidebar,.footer,.actions,.filter{display:none!important;}
      .layout{display:block!important;height:auto!important;}
      .content{padding:0!important;overflow:visible!important;}
      .report-card{box-shadow:none!important;border-radius:0!important;padding:0!important;}
      table{min-width:0!important;font-size:10px;}
      th,td{font-size:10px;padding:6px 5px;}
      .summary{grid-template-columns:repeat(4,1fr);}
    }
  </style>
</head>
<body>
<header class="navbar">
  <div class="inner">
    <div class="brand"><span class="dot"></span><span>CEVIMEP</span></div>
    <div class="nav-right"><a href="/logout.php" class="btn-pill">Salir</a></div>
  </div>
</header>

<div class="layout">
  <aside class="sidebar">
    <div class="menu-title">Menú</div>
    <nav class="menu">
      <a href="/private/dashboard.php">🏠 Panel</a>
      <a href="/private/patients/index.php">👤 Pacientes</a>
      <a href="#" onclick="return false;" style="opacity:.5;cursor:not-allowed;">📅 Citas (Próximamente)</a>
      <a href="/private/facturacion/index.php">🧾 Facturación</a>
      <a class="active" href="/private/caja/index.php">💳 Caja</a>
      <a href="/private/inventario/index.php">📦 Inventario</a>
      <a href="/private/estadistica/index.php">📊 Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <div class="page-wrap">
      <div class="head-row">
        <div>
          <h1 class="title">Movimiento diario</h1>
          <p class="sub">Sucursal: <?= h($branchName ?: '—') ?> · Fecha: <?= h($date) ?></p>
        </div>
        <div class="actions">
          <a class="btn-local" href="/private/caja/index.php">← Volver a Caja</a>
          <button class="btn-local primary" onclick="window.print()">🖨️ Imprimir</button>
        </div>
      </div>

      <?php if ($error): ?><div class="danger"><?= h($error) ?></div><?php endif; ?>

      <section class="report-card">
        <form class="filter" method="get" action="/private/caja/movimiento_diario.php">
          <?php if ($isAdmin && !empty($branchesList)): ?>
            <div class="field">
              <label>Sucursal</label>
              <select name="branch_id">
                <?php foreach ($branchesList as $br): ?>
                  <option value="<?= (int)$br['id'] ?>" <?= ((int)$br['id'] === (int)$branchId) ? 'selected' : '' ?>><?= h($br['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
          <div class="field">
            <label>Fecha</label>
            <input type="date" name="date" value="<?= h($date) ?>">
          </div>
          <button class="btn-local primary" type="submit">Ver</button>
        </form>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Tipo</th>
                <th>Factura</th>
                <th>Cliente / Motivo</th>
                <th>Fecha</th>
                <th class="money">Monto Facturado</th>
                <th class="money">Cobertura</th>
                <th class="money">Total General</th>
                <th class="money">Pagado</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="8" class="empty">No hay facturas ni desembolsos registrados para esta fecha.</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <tr class="<?= !empty($r['is_desembolso']) ? 'des-row' : '' ?>">
                    <td><span class="badge <?= !empty($r['is_desembolso']) ? 'des' : '' ?>"><?= h($r['tipo']) ?></span></td>
                    <td><?= h($r['factura']) ?></td>
                    <td><?= h($r['cliente']) ?></td>
                    <td><?= h($r['fecha']) ?></td>
                    <td class="money">RD$ <?= money($r['monto_facturado']) ?></td>
                    <td class="money">RD$ <?= money($r['cobertura']) ?></td>
                    <td class="money">RD$ <?= money($r['total_general']) ?></td>
                    <td class="money">RD$ <?= money($r['pagado']) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            <?php if ($rows): ?>
            <tfoot>
              <tr>
                <td colspan="4">Totales</td>
                <td class="money">RD$ <?= money($totFacturado) ?></td>
                <td class="money">RD$ <?= money($totCobertura) ?></td>
                <td class="money">RD$ <?= money($totGeneral - $totDesembolsos) ?></td>
                <td class="money">RD$ <?= money($neto) ?></td>
              </tr>
            </tfoot>
            <?php endif; ?>
          </table>
        </div>

        <?php if ($rows): ?>
          <div class="summary">
            <div class="sum-card"><small>Facturado</small>RD$ <?= money($totFacturado) ?></div>
            <div class="sum-card"><small>Cobertura</small>RD$ <?= money($totCobertura) ?></div>
            <div class="sum-card"><small>Desembolsos</small>RD$ <?= money($totDesembolsos) ?></div>
            <div class="sum-card"><small>Neto</small>RD$ <?= money($neto) ?></div>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </main>
</div>

<footer class="footer">© <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.</footer>
</body>
</html>
