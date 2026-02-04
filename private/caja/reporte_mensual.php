<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION["user"])) {
  header("Location: /login.php");
  exit;
}

require_once __DIR__ . "/../config/db.php";

date_default_timezone_set("America/Santo_Domingo");

$user = $_SESSION["user"];
$year = (int)date("Y");

$isAdmin  = (($user["role"] ?? "") === "admin");
$branchId = (int)($user["branch_id"] ?? 0);

if (!$isAdmin && $branchId <= 0) {
  header("Location: /logout.php");
  exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return number_format((float)$n, 2, ".", ","); }

// mes actual por defecto
$month = $_GET["month"] ?? date("Y-m");
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date("Y-m");

$start = $month . "-01";
$end   = date("Y-m-t", strtotime($start));

// Nombre sucursal
$branchName = $user["branch_name"] ?? $user["branch"] ?? ("Sucursal #".$branchId);
try {
  $stB = $pdo->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branchId]);
  $bn = $stB->fetchColumn();
  if ($bn) $branchName = (string)$bn;
} catch (Throwable $e) {}

$error = "";

// Totales del mes (sumando movimientos por sesiones del rango)
$tot = ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"cobertura"=>0,"desembolso"=>0,"ing"=>0,"net"=>0];
$byDay = []; // resumen por día

try {
  // 1) Buscar sesiones del mes
  $stS = $pdo->prepare("
    SELECT id, date_open, caja_num
    FROM cash_sessions
    WHERE branch_id=? AND date_open BETWEEN ? AND ?
    ORDER BY date_open ASC, caja_num ASC, id ASC
  ");
  $stS->execute([$branchId, $start, $end]);
  $sessions = $stS->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // 2) Para cada sesión, sumar movimientos
  $stT = $pdo->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='efectivo' THEN amount END),0) AS efectivo,
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='tarjeta' THEN amount END),0) AS tarjeta,
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='transferencia' THEN amount END),0) AS transferencia,
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago IN ('cobertura','seguro') THEN amount END),0) AS cobertura,
      COALESCE(SUM(CASE WHEN type='desembolso' THEN amount END),0) AS desembolso
    FROM cash_movements
    WHERE session_id=?
  ");

  foreach ($sessions as $s) {
    $sid = (int)$s["id"];
    $day = (string)$s["date_open"];

    $stT->execute([$sid]);
    $r = $stT->fetch(PDO::FETCH_ASSOC) ?: ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"cobertura"=>0,"desembolso"=>0];

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

    if (!isset($byDay[$day])) {
      $byDay[$day] = ["ing"=>0,"des"=>0,"net"=>0];
    }
    $byDay[$day]["ing"] += $ing;
    $byDay[$day]["des"] += $de;
    $byDay[$day]["net"] += $net;
  }

  $tot["ing"] = $tot["efectivo"] + $tot["tarjeta"] + $tot["transferencia"] + $tot["cobertura"];
  $tot["net"] = $tot["ing"] - $tot["desembolso"];

} catch (Throwable $e) {
  $error = "Error interno generando el reporte mensual. Verifica tablas/columnas (cash_sessions, cash_movements).";
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Reporte Mensual Caja</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=50">






  <style>
    .actions{display:flex; gap:10px; flex-wrap:wrap; align-items:center;}
    .


Local{
      display:inline-flex;align-items:center;justify-content:center;
      padding:10px 14px;border-radius:14px;
      border:1px solid #dbeafe;background:#fff;color:#052a7a;
      font-weight:900;text-decoration:none;cursor:pointer;
    }
    .


Local:hover{box-shadow:0 10px 25px rgba(2,6,23,.10);}
    .cardBox{
      background:#fff;border:1px solid #e6eef7;border-radius:22px;padding:18px;
      box-shadow:0 10px 30px rgba(2,6,23,.08);
    }
    table{width:100%; border-collapse:collapse; margin-top:10px; border:1px solid #e6eef7; border-radius:16px; overflow:hidden;}
    th,td{padding:10px; border-bottom:1px solid #eef2f7; text-align:left; font-size:13px;}
    thead th{background:#f7fbff; color:#0b3b9a; font-weight:900;}
    .muted{color:#6b7280; font-weight:700;}
    .pill{padding:8px 12px;border-radius:14px;border:1px solid #e6eef7;background:#fff;}
    .row{display:flex; gap:10px; flex-wrap:wrap; align-items:center;}
    .right{margin-left:auto;}
    .danger{background:#fff5f5;border:1px solid #fed7d7;color:#b91c1c;border-radius:14px;padding:10px 12px;font-weight:800;}
    .grid2{display:grid; grid-template-columns:1fr 1fr; gap:14px;}
    @media(max-width:900px){ .grid2{grid-template-columns:1fr;} }
  </style>
</head>

<body>
<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right">
      <a class="


-pill" href="/logout.php">Salir</a>
    </div>
  </div>
</header>

<div class="layout">
  <aside class="sidebar">
    <div class="menu-title">Menú</div>
    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👥</span> Pacientes</a>
      <a href="javascript:void(0)" style="opacity:.45; cursor:not-allowed;"><span class="ico">🗓️</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a class="active" href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadistica/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <section class="hero">
      <h1>Reporte Mensual</h1>
      <p><?= h($branchName) ?> · Mes: <?= h($month) ?> (<?= h($start) ?> a <?= h($end) ?>)</p>
    </section>

    <section class="card">
      <div class="row">
        <form class="row" method="GET" action="/private/caja/reporte_mensual.php">
          <div class="pill">
            <label class="muted" style="display:block;font-size:12px;margin-bottom:4px;">Mes</label>
            <input type="month" name="month" value="<?= h($month) ?>" style="border:0;outline:none;font-weight:800;">
          </div>
          <button class="


Local" type="submit">Ver</button>
        </form>

        <div class="right actions">
          <a class="


Local" href="/private/caja/index.php">Volver a Caja</a>
          <a class="


Local" href="javascript:void(0)" onclick="window.print()">Imprimir</a>
        </div>
      </div>

      <?php if($error): ?>
        <div style="margin-top:12px;" class="danger"><?= h($error) ?></div>
      <?php endif; ?>
    </section>

    <div style="height:14px;"></div>

    <div class="grid2">
      <section class="cardBox">
        <h3 style="margin:0; color:#052a7a;">Totales del mes</h3>
        <table>
          <thead><tr><th>Concepto</th><th>Monto</th></tr></thead>
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
        <h3 style="margin:0; color:#052a7a;">Resumen por día</h3>
        <table>
          <thead><tr><th>Fecha</th><th>Ingresos</th><th>Desembolsos</th><th>Neto</th></tr></thead>
          <tbody>
            <?php if (!$byDay): ?>
              <tr><td colspan="4" class="muted">No hay sesiones/movimientos en este mes.</td></tr>
            <?php else:
              foreach ($byDay as $d => $r): ?>
              <tr>
                <td><?= h($d) ?></td>
                <td>RD$ <?= money($r["ing"]) ?></td>
                <td>- RD$ <?= money($r["des"]) ?></td>
                <td>RD$ <?= money($r["net"]) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </section>
    </div>
  </main>
</div>

<footer class="footer">
  <div class="footer-inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>
</body>
</html>
