<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";

// ✅ Sesión (por si _guard no la inicia en algún entorno)
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if (!isset($_SESSION["user"])) { header("Location: /login.php"); exit; }

$user = $_SESSION["user"];
$year = date("Y");

$isAdmin  = (($user["role"] ?? "") === "admin");
$branchId = (int)($user["branch_id"] ?? 0);
$userId   = (int)($user["id"] ?? 0);

if (!$isAdmin && $branchId <= 0) { header("Location: /logout.php"); exit; }

date_default_timezone_set("America/Santo_Domingo");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtMoney($n){ return number_format((float)$n, 2, ".", ","); }

$today = date("Y-m-d");

/**
 * ✅ CONEXIÓN REAL
 * Tu conexión está en: private/config/db.php
 * Desde private/caja/index.php => ../config/db.php
 */
if (!isset($pdo) || !($pdo instanceof PDO)) {
  require_once __DIR__ . "/../config/db.php";
}

/**
 * ✅ Librería de caja (funciones de turno/sesión)
 */
require_once __DIR__ . "/caja_lib.php";

// ✅ Auto cerrar vencidas y abrir sesión actual (sin botones)
$activeSessionId = 0;
try {
  $activeSessionId = caja_get_or_open_current_session($pdo, $branchId, $userId);
} catch (Throwable $e) {
  $activeSessionId = 0; // no romper la página
}

// Nombre sucursal (si existe branches)
$branchName = "Sucursal #".$branchId;
try {
  $stB = $pdo->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branchId]);
  $bn = $stB->fetchColumn();
  if ($bn) $branchName = (string)$bn;
} catch (Throwable $e) {}

// ✅ Mantengo TUS funciones, pero seguras (try/catch) para que nunca rompan Railway
function getSession(PDO $pdo, int $branchId, int $cajaNum, string $date, string $shiftStart, string $shiftEnd){
  try {
    $st = $pdo->prepare("SELECT * FROM cash_sessions
                         WHERE branch_id=? AND date_open=? AND caja_num=? AND shift_start=? AND shift_end=?
                         ORDER BY id DESC LIMIT 1");
    $st->execute([$branchId, $date, $cajaNum, $shiftStart, $shiftEnd]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

function getTotals(PDO $pdo, int $sessionId){
  try {
    $st = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='efectivo' THEN amount END),0) AS efectivo,
        COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='tarjeta' THEN amount END),0) AS tarjeta,
        COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='transferencia' THEN amount END),0) AS transferencia,
        COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago IN ('cobertura','seguro','ars') THEN amount END),0) AS cobertura,
        COALESCE(SUM(CASE WHEN type='desembolso' THEN amount END),0) AS desembolso
      FROM cash_movements
      WHERE session_id=?");
    $st->execute([$sessionId]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"cobertura"=>0,"desembolso"=>0];
  } catch (Throwable $e) {
    $r = ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"cobertura"=>0,"desembolso"=>0];
  }

  $ing = (float)$r["efectivo"]+(float)$r["tarjeta"]+(float)$r["transferencia"];
  $net = $ing-(float)$r["desembolso"];
  return [$r,$ing,$net];
}

[$s1Start,$s1End] = ["08:00:00","13:00:00"];
[$s2Start,$s2End] = ["13:00:00","18:00:00"];

$caja1 = getSession($pdo, $branchId, 1, $today, $s1Start, $s1End);
$caja2 = getSession($pdo, $branchId, 2, $today, $s2Start, $s2End);

$sum = [
  1 => ["r"=>["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"cobertura"=>0,"desembolso"=>0], "ing"=>0, "net"=>0],
  2 => ["r"=>["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"cobertura"=>0,"desembolso"=>0], "ing"=>0, "net"=>0],
];

if ($caja1) { [$r,$ing,$net] = getTotals($pdo, (int)$caja1["id"]); $sum[1]=["r"=>$r,"ing"=>$ing,"net"=>$net]; }
if ($caja2) { [$r,$ing,$net] = getTotals($pdo, (int)$caja2["id"]); $sum[2]=["r"=>$r,"ing"=>$ing,"net"=>$net]; }

$currentCajaNum = 0;
try {
  $currentCajaNum = caja_get_current_caja_num();
} catch (Throwable $e) {
  $currentCajaNum = 0;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Caja</title>

  <!-- ✅ MISMO CSS DEL DASHBOARD -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=50">

  <!-- ✅ Solo estilos mínimos para tablas/cards sin romper el look -->
  <style>

    .page-wrap{display:flex; flex-direction:column; gap:14px;}

    /* Card */
    .card-soft{
      background: rgba(255,255,255,.92);
      border: 1px solid rgba(255,255,255,.35);
      border-radius: 16px;
      padding: 14px 16px;
      box-shadow: 0 10px 24px rgba(0,0,0,.07);
    }

    /* Titles */
    .card-soft h1{margin:0; font-size:34px; line-height:1.1; letter-spacing:.2px;}
    .card-soft h2{margin:0; font-size:20px; line-height:1.2; letter-spacing:.2px;}

    .muted{opacity:.85; font-size:14px;}

    /* Header (como tu screenshot): título centrado + botones centrados debajo */
    .header-card{padding:18px 16px;}
    .header-center{display:flex; flex-direction:column; align-items:center; text-align:center; gap:10px;}
    .actions{display:flex; gap:10px; flex-wrap:wrap; justify-content:center;}

    .btn-pill{
      display:inline-flex; align-items:center; justify-content:center;
      padding:10px 14px; border-radius:999px;
      border:1px solid rgba(255,255,255,.55);
      background: rgba(0,0,0,.08);
      color:#fff; text-decoration:none;
      font-weight:800; font-size:14px;
      transition:.15s ease;
      box-shadow: 0 10px 18px rgba(0,0,0,.10);
    }
    .btn-pill:hover{transform:translateY(-1px); filter:brightness(.98);}

    /* Two cards layout */
    .grid-2{
      display:grid;
      grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
      gap:14px;
    }
    @media(max-width: 980px){ .grid-2{grid-template-columns:1fr;} }

    /* Table */
    table{
      width:100%;
      border-collapse:collapse;
      margin-top:10px;
      overflow:hidden;
      border-radius:14px;
      background: rgba(255,255,255,.55);
      border: 1px solid rgba(0,0,0,.06);
    }
    th,td{
      padding:9px 10px;
      border-bottom:1px solid rgba(0,0,0,.06);
      font-size:14px;
    }
    thead th{font-weight:900;}
    tbody tr:last-child td{border-bottom:none;}
    .t-strong{font-weight:900;}

    /* Make header card less tall on short screens (1600x900 laptops) */
    @media (max-height: 900px){
      .card-soft{padding:12px 14px;}
      .card-soft h1{font-size:28px;}
      .card-soft h2{font-size:19px;}
      .btn-pill{padding:9px 12px; font-size:13.5px;}
      th,td{padding:8px 9px;}
    }

    /* ACTION BUTTONS (píldoras azul oscuro, centradas) */
    .actions .btn-pill{
      background: var(--blue);
      border-color: var(--blue);
      color:#fff;
      padding:10px 16px;
      font-weight:900;
    }
    .actions .btn-pill:hover{filter:brightness(0.95);}

  </style>
</head>

<body>

<!-- TOPBAR (igual dashboard) -->
<header class="navbar">
  <div class="inner">
    <div class="brand">
      <span class="dot"></span>
      <span>CEVIMEP</span>
    </div>

    <div class="nav-right">
      <a href="/logout.php" class="btn-pill">Salir</a>
    </div>
  </div>
</header>

<div class="layout">

  <!-- SIDEBAR (igual dashboard / mismo orden) -->
  <aside class="sidebar">
    <div class="menu-title">Menú</div>

    <nav class="menu">
      <a href="/private/dashboard.php">🏠 Panel</a>
      <a href="/private/patients/index.php">👤 Pacientes</a>
      <a href="/private/citas/index.php">📅 Citas</a>
      <a href="/private/facturacion/index.php">🧾 Facturación</a>
      <a class="active" href="/private/caja/index.php">💳 Caja</a>
      <a href="/private/inventario/index.php">📦 Inventario</a>
      <a href="/private/estadistica/index.php">📊 Estadísticas</a>
    </nav>
  </aside>

  <!-- CONTENIDO -->
  <main class="content">
    <div class="page-wrap">

      <!-- HEADER (como el screenshot) -->
      <div class="card-soft header-card">
        <div class="header-center">
          <h1>Caja</h1>

          <div class="actions">
            <a class="btn-pill" href="/private/caja/desembolso.php">Desembolso</a>
            <a class="btn-pill" href="/private/caja/reporte_diario.php">Reporte diario</a>
            <a class="btn-pill" href="/private/caja/reporte_mensual.php">Reporte mensual</a>
          </div>

          <div class="muted">
            <?= h($branchName) ?> · Hoy: <?= h($today) ?>
          </div>
        </div>
      </div>

      <!-- CAJA 1 / CAJA 2 -->
      <div class="grid-2">

        <div class="card-soft">
          <h2 style="margin:0;">Caja 1 (08:00 AM - 01:00 PM)</h2>

          <table>
            <thead>
              <tr><th>Concepto</th><th>Monto</th></tr>
            </thead>
            <tbody>
              <tr><td>Efectivo</td><td>RD$ <?= fmtMoney($sum[1]["r"]["efectivo"]) ?></td></tr>
              <tr><td>Tarjeta</td><td>RD$ <?= fmtMoney($sum[1]["r"]["tarjeta"]) ?></td></tr>
              <tr><td>Transferencia</td><td>RD$ <?= fmtMoney($sum[1]["r"]["transferencia"]) ?></td></tr>
              <tr><td>Cobertura</td><td>RD$ <?= fmtMoney($sum[1]["r"]["cobertura"] ?? 0) ?></td></tr>
              <tr><td>Desembolsos</td><td>- RD$ <?= fmtMoney($sum[1]["r"]["desembolso"]) ?></td></tr>
              <tr><td class="t-strong">Total ingresos</td><td class="t-strong">RD$ <?= fmtMoney($sum[1]["ing"]) ?></td></tr>
              <tr><td class="t-strong">Neto</td><td class="t-strong">RD$ <?= fmtMoney($sum[1]["net"]) ?></td></tr>
            </tbody>
          </table>
        </div>

        <div class="card-soft">
          <h2 style="margin:0;">Caja 2 (01:00 PM - 06:00 PM)</h2>

          <table>
            <thead>
              <tr><th>Concepto</th><th>Monto</th></tr>
            </thead>
            <tbody>
              <tr><td>Efectivo</td><td>RD$ <?= fmtMoney($sum[2]["r"]["efectivo"]) ?></td></tr>
              <tr><td>Tarjeta</td><td>RD$ <?= fmtMoney($sum[2]["r"]["tarjeta"]) ?></td></tr>
              <tr><td>Transferencia</td><td>RD$ <?= fmtMoney($sum[2]["r"]["transferencia"]) ?></td></tr>
              <tr><td>Cobertura</td><td>RD$ <?= fmtMoney($sum[2]["r"]["cobertura"] ?? 0) ?></td></tr>
              <tr><td>Desembolsos</td><td>- RD$ <?= fmtMoney($sum[2]["r"]["desembolso"]) ?></td></tr>
              <tr><td class="t-strong">Total ingresos</td><td class="t-strong">RD$ <?= fmtMoney($sum[2]["ing"]) ?></td></tr>
              <tr><td class="t-strong">Neto</td><td class="t-strong">RD$ <?= fmtMoney($sum[2]["net"]) ?></td></tr>
            </tbody>
          </table>
        </div>

      </div>

    </div>
  </main>
</div>

<footer class="footer">
  © <?= (int)$year ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>
