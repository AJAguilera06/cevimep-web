<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (empty($_SESSION["user"])) {
  header("Location: /login.php");
  exit;
}

require_once __DIR__ . "/../config/db.php";

date_default_timezone_set("America/Santo_Domingo");

$user = $_SESSION["user"];
$year = (int)date("Y");

$isAdmin = (($user["role"] ?? "") === "admin");
$userBranchId = (int)($user["branch_id"] ?? 0);
$branchId = $userBranchId;

if (!$isAdmin && $branchId <= 0) {
  header("Location: /logout.php");
  exit;
}

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

function money($n): string {
  return number_format((float)$n, 2, ".", ",");
}

$month = $_GET["month"] ?? date("Y-m");
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
  $month = date("Y-m");
}

$start = $month . "-01";
$end = date("Y-m-t", strtotime($start));

/* Sucursales disponibles */
$branchesList = [];

try {
  if ($isAdmin) {
    $stBranches = $pdo->query("SELECT id, name FROM branches ORDER BY name ASC");
    $branchesList = $stBranches->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $requestedBranchId = (int)($_GET["branch_id"] ?? 0);

    if ($requestedBranchId > 0) {
      $branchId = $requestedBranchId;
    } elseif ($branchId <= 0 && !empty($branchesList)) {
      $branchId = (int)$branchesList[0]["id"];
    }
  }
} catch (Throwable $e) {
  // No detener la página si falla la lista de sucursales
}

/* Nombre sucursal */
$branchName = $user["branch_name"] ?? $user["branch"] ?? ("Sucursal #" . $branchId);

try {
  $stB = $pdo->prepare("SELECT name FROM branches WHERE id = ? LIMIT 1");
  $stB->execute([$branchId]);
  $bn = $stB->fetchColumn();

  if ($bn) {
    $branchName = (string)$bn;
  }
} catch (Throwable $e) {
  // No detener la página si falla el nombre de sucursal
}

$error = "";

$tot = [
  "efectivo" => 0,
  "tarjeta" => 0,
  "transferencia" => 0,
  "cobertura" => 0,
  "desembolso" => 0,
  "ing" => 0,
  "net" => 0
];

$byDay = [];

try {
  /*
    CORREGIDO:
    Tu sistema usa las tablas caja_sesiones y cash_movements.
    En cash_movements el campo correcto es caja_sesion_id.
  */
  $sql = "
    SELECT
      cs.fecha AS dia,
      COALESCE(SUM(CASE WHEN cm.type = 'ingreso' AND cm.metodo_pago = 'efectivo' THEN cm.amount END), 0) AS efectivo,
      COALESCE(SUM(CASE WHEN cm.type = 'ingreso' AND cm.metodo_pago = 'tarjeta' THEN cm.amount END), 0) AS tarjeta,
      COALESCE(SUM(CASE WHEN cm.type = 'ingreso' AND cm.metodo_pago = 'transferencia' THEN cm.amount END), 0) AS transferencia,
      COALESCE(SUM(CASE WHEN cm.type = 'ingreso' AND cm.metodo_pago IN ('cobertura', 'seguro') THEN cm.amount END), 0) AS cobertura,
      COALESCE(SUM(CASE WHEN cm.type = 'desembolso' THEN cm.amount END), 0) AS desembolso
    FROM caja_sesiones cs
    LEFT JOIN cash_movements cm ON cm.caja_sesion_id = cs.id
    WHERE cs.branch_id = ?
      AND cs.fecha BETWEEN ? AND ?
    GROUP BY cs.fecha
    ORDER BY cs.fecha ASC
  ";

  $st = $pdo->prepare($sql);
  $st->execute([$branchId, $start, $end]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($rows as $r) {
    $dia = (string)$r["dia"];

    $ef = (float)$r["efectivo"];
    $ta = (float)$r["tarjeta"];
    $tr = (float)$r["transferencia"];
    $co = (float)$r["cobertura"];
    $de = (float)$r["desembolso"];

    $ing = $ef + $ta + $tr + $co;
    $net = $ing - $de;

    $tot["efectivo"] += $ef;
    $tot["tarjeta"] += $ta;
    $tot["transferencia"] += $tr;
    $tot["cobertura"] += $co;
    $tot["desembolso"] += $de;

    $byDay[$dia] = [
      "ing" => $ing,
      "des" => $de,
      "net" => $net
    ];
  }

  $tot["ing"] = $tot["efectivo"] + $tot["tarjeta"] + $tot["transferencia"] + $tot["cobertura"];
  $tot["net"] = $tot["ing"] - $tot["desembolso"];

} catch (Throwable $e) {
  $error = "Error interno generando el reporte mensual: " . $e->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Reporte Mensual Caja</title>

  <link rel="stylesheet" href="/assets/css/styles.css?v=50">

  <style>
    .reportWrap{
      max-width:1200px;
      margin:0 auto;
      padding:42px 18px;
    }

    .reportTitle{
      text-align:center;
      margin:0 0 18px;
      font-size:34px;
      font-weight:900;
      letter-spacing:.4px;
    }

    .reportTop{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:14px;
      flex-wrap:wrap;
      margin-bottom:18px;
    }

    .reportTopLeft{
      display:flex;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
    }

    .branchTag{
      font-weight:900;
      color:#0b3b9a;
    }

    .pill{
      padding:8px 12px;
      border-radius:14px;
      border:1px solid #e6eef7;
      background:#fff;
    }

    .muted{
      color:#6b7280;
      font-weight:700;
    }

    .btnLocal{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:10px 16px;
      border-radius:999px;
      border:1px solid #dbeafe;
      background:#fff;
      color:#052a7a;
      font-weight:900;
      text-decoration:none;
      cursor:pointer;
      transition:all .15s ease;
    }

    .btnLocal:hover{
      box-shadow:0 10px 25px rgba(2,6,23,.10);
      transform:translateY(-1px);
    }

    .btnPrimary{
      border:none;
      background:linear-gradient(180deg,#2f6dff,#0b3b9a);
      color:#fff;
      box-shadow:0 18px 40px rgba(11,59,154,.20);
    }

    .danger{
      background:#fff5f5;
      border:1px solid #fed7d7;
      color:#b91c1c;
      border-radius:14px;
      padding:10px 12px;
      font-weight:800;
      margin-bottom:14px;
      text-align:center;
    }

    .reportFrame{
      background:#fff;
      border:3px solid rgba(11,59,154,.55);
      border-radius:24px;
      padding:16px;
      box-shadow:0 14px 40px rgba(2,6,23,.10);
    }

    .grid2{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:14px;
      align-items:start;
    }

    .cardBox{
      background:#fff;
      border:1px solid #e6eef7;
      border-radius:20px;
      padding:16px;
    }

    .cardBox h3{
      margin:0 0 10px;
      text-align:center;
      color:#052a7a;
      font-size:20px;
      font-weight:900;
    }

    table{
      width:100%;
      border-collapse:collapse;
      margin-top:10px;
      border:1px solid #e6eef7;
      border-radius:16px;
      overflow:hidden;
    }

    th,td{
      padding:10px;
      border-bottom:1px solid #eef2f7;
      text-align:left;
      font-size:13px;
    }

    thead th{
      background:#f7fbff;
      color:#0b3b9a;
      font-weight:900;
    }

    .monthInfo{
      text-align:center;
      margin:0 0 16px;
      font-weight:700;
      color:#1f2937;
    }

    @media(max-width:900px){
      .grid2{grid-template-columns:1fr;}
      .reportTop{justify-content:center;}
    }

    @media print{
      @page{margin:10mm;}
      body{background:#fff !important;}
      body *{visibility:hidden !important;}
      #printArea,#printArea *{visibility:visible !important;}
      #printArea{
        position:absolute !important;
        left:0 !important;
        top:0 !important;
        width:100% !important;
        margin:0 !important;
        padding:0 !important;
      }
      .navbar,.sidebar,footer,.btnLocal,.pill{display:none !important;}
      .layout,.content{padding:0 !important;margin:0 !important;}
      .reportFrame{box-shadow:none !important;}
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
      <a href="/private/dashboard.php">🏠 Panel</a>
      <a href="/private/patients/index.php">👥 Pacientes</a>
      <a href="javascript:void(0)" style="opacity:.45; cursor:not-allowed;">🗓️ Citas</a>
      <a href="/private/facturacion/index.php">🧾 Facturación</a>
      <a class="active" href="/private/caja/index.php">💵 Caja</a>
      <a href="/private/inventario/index.php">📦 Inventario</a>
      <a href="/private/estadistica/index.php">📊 Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <section id="printArea" class="reportWrap">
      <h1 class="reportTitle">Reporte Mensual</h1>

      <div class="reportTop">
        <div class="reportTopLeft">
          <div class="branchTag"><?= h($branchName) ?>:</div>

          <form class="reportTopLeft" method="GET" action="/private/caja/reporte_mensual.php">
            <?php if ($isAdmin && !empty($branchesList)): ?>
              <div class="pill">
                <label class="muted" style="display:block;font-size:12px;margin-bottom:4px;">Sucursal</label>
                <select name="branch_id" style="border:0;outline:none;font-weight:900;background:#fff;">
                  <?php foreach ($branchesList as $br): ?>
                    <option value="<?= (int)$br["id"] ?>" <?= ((int)$br["id"] === (int)$branchId) ? "selected" : "" ?>>
                      <?= h($br["name"]) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <input type="hidden" name="branch_id" value="<?= (int)$branchId ?>">
            <?php endif; ?>

            <div class="pill">
              <label class="muted" style="display:block;font-size:12px;margin-bottom:4px;">Mes</label>
              <input type="month" name="month" value="<?= h($month) ?>" style="border:0;outline:none;font-weight:900;">
            </div>

            <button class="btnLocal" type="submit">Ver</button>
          </form>
        </div>

        <div class="reportTopLeft">
          <a class="btnLocal" href="/private/caja/index.php">Volver a Caja</a>
          <a class="btnLocal btnPrimary" href="javascript:void(0)" onclick="window.print()">Imprimir</a>
        </div>
      </div>

      <p class="monthInfo">
        Mes: <?= h($month) ?> (<?= h($start) ?> a <?= h($end) ?>)
      </p>

      <?php if ($error): ?>
        <div class="danger"><?= h($error) ?></div>
      <?php endif; ?>

      <div class="reportFrame">
        <div class="grid2">
          <section class="cardBox">
            <h3>Totales del mes</h3>
            <table>
              <thead>
                <tr>
                  <th>Concepto</th>
                  <th>Monto</th>
                </tr>
              </thead>
              <tbody>
                <tr><td>Efectivo</td><td>RD$ <?= money($tot["efectivo"]) ?></td></tr>
                <tr><td>Tarjeta</td><td>RD$ <?= money($tot["tarjeta"]) ?></td></tr>
                <tr><td>Transferencia</td><td>RD$ <?= money($tot["transferencia"]) ?></td></tr>
                <tr><td>Cobertura</td><td>RD$ <?= money($tot["cobertura"]) ?></td></tr>
                <tr><td>Desembolsos</td><td>- RD$ <?= money($tot["desembolso"]) ?></td></tr>
                <tr><td style="font-weight:900;">Total ingresos</td><td style="font-weight:900;">RD$ <?= money($tot["ing"]) ?></td></tr>
                <tr><td style="font-weight:900;">Neto</td><td style="font-weight:900;">RD$ <?= money($tot["net"]) ?></td></tr>
              </tbody>
            </table>
          </section>

          <section class="cardBox">
            <h3>Resumen por día</h3>
            <table>
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Ingresos</th>
                  <th>Desembolsos</th>
                  <th>Neto</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$byDay): ?>
                  <tr>
                    <td colspan="4" class="muted">No hay sesiones/movimientos en este mes.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($byDay as $d => $r): ?>
                    <tr>
                      <td><?= h($d) ?></td>
                      <td>RD$ <?= money($r["ing"]) ?></td>
                      <td>- RD$ <?= money($r["des"]) ?></td>
                      <td>RD$ <?= money($r["net"]) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </section>
        </div>
      </div>
    </section>
  </main>
</div>

<footer class="footer">
  <div class="footer-inner">
    © <?= $year ?> CEVIMEP. Todos los derechos reservados.
  </div>
</footer>

</body>
</html>
