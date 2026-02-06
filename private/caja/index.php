<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";

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

if (!isset($pdo) || !($pdo instanceof PDO)) {
  require_once __DIR__ . "/../config/db.php";
}

require_once __DIR__ . "/caja_lib.php";

// Auto cerrar vencidas y abrir sesión actual (sin botones)
try { caja_get_or_open_current_session($pdo, $branchId, $userId); } catch (Throwable $e) {}

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

  $ing = (float)$r["efectivo"] + (float)$r["tarjeta"] + (float)$r["transferencia"];
  $net = $ing - (float)$r["desembolso"];
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
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Caja</title>

  <link rel="stylesheet" href="/assets/css/styles.css?v=50">

  <style>
    /* ✅ Contenedor centrado (esto es CLAVE para que se vea como tu 2da imagen) */
    .caja-container{
      max-width: 1080px;
      margin: 0 auto;
      width: 100%;
      padding: 12px 10px 6px;
    }

    /* Card general */
    .card-soft{
      background: rgba(255,255,255,.92);
      border: 1px solid rgba(255,255,255,.35);
      border-radius: 16px;
      padding: 16px 18px;
      box-shadow: 0 10px 24px rgba(0,0,0,.07);
    }

    /* Header (igual a tu imagen: título + botones centrados) */
    .header-card{
      display:flex;
      flex-direction:column;
      align-items:center;
      text-align:center;
      gap: 12px;
      padding: 18px 18px;
    }
    .header-card h1{
      margin:0;
      font-size: 34px;
      line-height: 1.1;
      font-weight: 900;
    }
    .actions{
      display:flex;
      gap: 12px;
      flex-wrap:wrap;
      justify-content:center;
    }
    .actions .btn-pill{
      background: var(--blue);
      border-color: var(--blue);
      color:#fff;
      padding: 10px 16px;
      border-radius: 999px;
      font-weight: 900;
      text-decoration:none;
      box-shadow: 0 10px 18px rgba(0,0,0,.10);
      transition: .15s ease;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      font-size:14px;
    }
    .actions .btn-pill:hover{ transform: translateY(-1px); filter: brightness(.96); }

    /* Grid 2 cards */
    .grid-2{
      margin-top: 14px;
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }
    @media (max-width: 980px){
      .grid-2{ grid-template-columns: 1fr; }
    }

    /* Títulos dentro de cards centrados como tu imagen */
    .box-title{
      margin: 0;
      text-align:center;
      font-size: 18px;
      font-weight: 900;
    }

    /* Tabla */
    table{
      width:100%;
      border-collapse:collapse;
      margin-top: 12px;
      border-radius:14px;
      overflow:hidden;
      background: rgba(255,255,255,.55);
      border: 1px solid rgba(0,0,0,.06);
    }
    th,td{
      padding: 10px 12px;
      border-bottom: 1px solid rgba(0,0,0,.06);
      font-size: 14px;
    }
    thead th{ font-weight: 900; }
    tbody tr:last-child td{ border-bottom:none; }
    .t-strong{ font-weight: 900; }

    /* Ajuste leve para pantallas bajitas (sin deformar) */
    @media (max-height: 900px){
      .header-card h1{ font-size: 30px; }
      .actions .btn-pill{ padding: 9px 14px; }
    }
  </style>
</head>

<body>

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

  <main class="content">
    <div class="caja-container">

      <!-- ✅ Header EXACTO como tu 2da imagen -->
      <div class="card-soft header-card">
        <h1>Caja</h1>
        <div class="actions">
          <a class="btn-pill" href="/private/caja/desembolso.php">Desembolso</a>
          <a class="btn-pill" href="/private/caja/reporte_diario.php">Reporte diario</a>
          <a class="btn-pill" href="/private/caja/reporte_mensual.php">Reporte mensual</a>
        </div>
      </div>

      <!-- ✅ Dos cards exactamente como tu 2da imagen -->
      <div class="grid-2">

        <div class="card-soft">
          <h2 class="box-title">Caja 1 (08:00 AM - 01:00 PM)</h2>
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
          <h2 class="box-title">Caja 2 (01:00 PM - 06:00 PM)</h2>
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
  © <?= (int)$year ?> CEVIMEP. Todos los derechos reservados.
</footer>

</body>
</html>
