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

// ✅ Mantengo TUS funciones, pero las hago seguras (try/catch) para que nunca rompan Railway
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

function cashMovementsCols(PDO $pdo): array {
  static $cached = null;
  if (is_array($cached)) return $cached;

  try {
    $st = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cash_movements'");
    $cols = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
  } catch (Throwable $e) {
    $cols = [];
  }

  $has = fn(string $c) => in_array($c, $cols, true);
  $pick = function(array $cands) use ($has){
    foreach ($cands as $c) if ($has($c)) return $c;
    return null;
  };

  $cached = [
    "session" => $pick(["session_id","cash_session_id","caja_session_id"]),
    "type"    => $pick(["type","tipo","movement_type"]),
    "method"  => $pick(["metodo_pago","payment_method","method","forma_pago"]),
    "amount"  => $pick(["amount","monto","total","importe","valor"]),
  ];
  return $cached;
}

function getTotals(PDO $pdo, int $sessionId){
  $cols = cashMovementsCols($pdo);

  // Si la tabla/columnas no coinciden, no romper UI
  if (!$cols["session"] || !$cols["type"] || !$cols["method"] || !$cols["amount"]) {
    $r = ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"cobertura"=>0,"desembolso"=>0];
    $ing = 0.0; $net = 0.0;
    return [$r,$ing,$net];
  }

  $cSession = $cols["session"];
  $cType    = $cols["type"];
  $cMethod  = $cols["method"];
  $cAmount  = $cols["amount"];

  try {
    $sql = "SELECT
        COALESCE(SUM(CASE WHEN `$cType`='ingreso' AND `$cMethod`='efectivo' THEN `$cAmount` END),0) AS efectivo,
        COALESCE(SUM(CASE WHEN `$cType`='ingreso' AND `$cMethod`='tarjeta' THEN `$cAmount` END),0) AS tarjeta,
        COALESCE(SUM(CASE WHEN `$cType`='ingreso' AND `$cMethod`='transferencia' THEN `$cAmount` END),0) AS transferencia,
        COALESCE(SUM(CASE WHEN `$cType`='ingreso' AND `$cMethod`='cobertura' THEN `$cAmount` END),0) AS cobertura,
        COALESCE(SUM(CASE WHEN `$cType`='desembolso' THEN `$cAmount` END),0) AS desembolso
      FROM cash_movements
      WHERE `$cSession`=?";
    $st = $pdo->prepare($sql);
    $st->execute([$sessionId]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"cobertura"=>0,"desembolso"=>0];
  } catch (Throwable $e) {
    $r = ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"cobertura"=>0,"desembolso"=>0];
  }

  $ing = (float)$r["efectivo"]+(float)$r["tarjeta"]+(float)$r["transferencia"]+(float)$r["cobertura"];
  $net = $ing-(float)$r["desembolso"];
  return [$r,$ing,$net];
}


[$s1Start,$s1End] = caja_shift_times(1);
[$s2Start,$s2End] = caja_shift_times(2);

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
    .page-wrap{display:flex; flex-direction:column; gap:18px;}
    .card-soft{
      background: rgba(255,255,255,.92);
      border: 1px solid rgba(255,255,255,.35);
      border-radius: 18px;
      padding: 18px;
      box-shadow: 0 12px 30px rgba(0,0,0,.08);
    }
    .row-head{display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;}
    .muted{opacity:.85;}
    .pill{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      background: rgba(255,255,255,.18);
      border: 1px solid rgba(255,255,255,.25);
      font-weight:700;
    }
    .actions{display:flex; gap:10px; flex-wrap:wrap;}
    .grid-2{display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px;}
    @media(max-width: 980px){ .grid-2{grid-template-columns:1fr;} }

    table{width:100%; border-collapse:collapse; margin-top:12px; overflow:hidden; border-radius:14px;}
    th,td{padding:10px 12px; border-bottom:1px solid rgba(0,0,0,.06); font-size:14px;}
    thead th{font-weight:800;}
    tbody tr:last-child td{border-bottom:none;}
    .t-strong{font-weight:900;}
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

      <!-- HEADER / ACCIONES (tus botones) -->
      <div class="card-soft">
        <div class="row-head">
          <div>
            <h1 style="margin:0;">Caja</h1>
            <div class="muted" style="margin-top:6px;">
              <?= h($branchName) ?> · Hoy: <?= h($today) ?>
            </div>

            <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
              <span class="pill">Caja activa ahora: <?= (int)$currentCajaNum ?></span>
              <span class="pill">Sesión activa ID: <?= (int)$activeSessionId ?></span>
            </div>

            <div class="muted" style="margin-top:10px;">
              * Las cajas se abren y cierran automáticamente por horario (sin botones).
            </div>
          </div>

          <div class="actions">
            <!-- ✅ Mantengo tus links tal cual -->
            <a class="btn-pill" href="/private/caja/desembolso.php">Desembolsos</a>
            <a class="btn-pill" href="/private/caja/reporte_diario.php">Reporte diario</a>
            <a class="btn-pill" href="/private/caja/reporte_mensual.php">Reporte mensual</a>
          </div>
        </div>
      </div>

      <!-- CAJA 1 / CAJA 2 -->
      <div class="grid-2">

        <div class="card-soft">
          <h2 style="margin:0;">Caja 1 (08:00 AM - 01:00 PM)</h2>
          <div class="muted" style="margin-top:6px;">
            <?php if(!$caja1): ?>
              Sin sesión registrada hoy.
            <?php else: ?>
              Abierta: <?= h($caja1["opened_at"] ?? "—") ?> · Cerrada: <?= h($caja1["closed_at"] ?? "—") ?>
            <?php endif; ?>
          </div>

          <table>
            <thead>
              <tr><th>Concepto</th><th>Monto</th></tr>
            </thead>
            <tbody>
              <tr><td>Efectivo</td><td>RD$ <?= fmtMoney($sum[1]["r"]["efectivo"]) ?></td></tr>
              <tr><td>Tarjeta</td><td>RD$ <?= fmtMoney($sum[1]["r"]["tarjeta"]) ?></td></tr>
              <tr><td>Transferencia</td><td>RD$ <?= fmtMoney($sum[1]["r"]["transferencia"]) ?></td></tr>
              <tr><td>Cobertura</td><td>RD$ <?= fmtMoney($sum[1]["r"]["cobertura"]) ?></td></tr>
              <tr><td>Desembolsos</td><td>- RD$ <?= fmtMoney($sum[1]["r"]["desembolso"]) ?></td></tr>
              <tr><td class="t-strong">Total ingresos</td><td class="t-strong">RD$ <?= fmtMoney($sum[1]["ing"]) ?></td></tr>
              <tr><td class="t-strong">Neto</td><td class="t-strong">RD$ <?= fmtMoney($sum[1]["net"]) ?></td></tr>
            </tbody>
          </table>
        </div>

        <div class="card-soft">
          <h2 style="margin:0;">Caja 2 (01:00 PM - 06:00 PM)</h2>
          <div class="muted" style="margin-top:6px;">
            <?php if(!$caja2): ?>
              Sin sesión registrada hoy.
            <?php else: ?>
              Abierta: <?= h($caja2["opened_at"] ?? "—") ?> · Cerrada: <?= h($caja2["closed_at"] ?? "—") ?>
            <?php endif; ?>
          </div>

          <table>
            <thead>
              <tr><th>Concepto</th><th>Monto</th></tr>
            </thead>
            <tbody>
              <tr><td>Efectivo</td><td>RD$ <?= fmtMoney($sum[2]["r"]["efectivo"]) ?></td></tr>
              <tr><td>Tarjeta</td><td>RD$ <?= fmtMoney($sum[2]["r"]["tarjeta"]) ?></td></tr>
              <tr><td>Transferencia</td><td>RD$ <?= fmtMoney($sum[2]["r"]["transferencia"]) ?></td></tr>
              <tr><td>Cobertura</td><td>RD$ <?= fmtMoney($sum[2]["r"]["cobertura"]) ?></td></tr>
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
