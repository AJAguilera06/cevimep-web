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
$branchIdSession = (int)($user["branch_id"] ?? 0);
$userId   = (int)($user["id"] ?? 0);

date_default_timezone_set("America/Santo_Domingo");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtMoney($n){ return number_format((float)$n, 2, ".", ","); }

$today = date("Y-m-d");

if (!isset($pdo) || !($pdo instanceof PDO)) {
  require_once __DIR__ . "/../config/db.php";
}

require_once __DIR__ . "/caja_lib.php";

/**
 * ✅ Branch efectivo:
 * - Normal: usar branch_id de la sesión
 * - Admin: permitir ?branch_id= (para ver caja por sucursal)
 * - Admin con branch_id=0: tomar el primer branch como default
 */
$branchId = $branchIdSession;

// Admin puede escoger sucursal por URL
if ($isAdmin && isset($_GET["branch_id"])) {
  $tmp = (int)$_GET["branch_id"];
  if ($tmp > 0) $branchId = $tmp;
}

// Si es admin y no tiene branch_id válido, usar el primero
if ($isAdmin && $branchId <= 0) {
  try {
    $st = $pdo->query("SELECT id FROM branches ORDER BY id ASC LIMIT 1");
    $branchId = (int)($st->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    $branchId = 0;
  }
}

// Si no es admin y no tiene sucursal, fuera
if (!$isAdmin && $branchId <= 0) { header("Location: /logout.php"); exit; }
// Si es admin y aun así no hay sucursales, cortar
if ($isAdmin && $branchId <= 0) { die("No hay sucursales configuradas."); }

// Mantengo tu comportamiento (no lo quito), pero con branchId efectivo
try { caja_get_or_open_current_session($pdo, $branchId, $userId); } catch (Throwable $e) {}

/**
 * ✅ obtener TODAS las sesiones del día por:
 * branch_id + date_open + caja_num (sin shift_start/end)
 */
function getSessionIds(PDO $pdo, int $branchId, string $date, int $cajaNum): array {
  try {
    $st = $pdo->prepare("
      SELECT id
      FROM cash_sessions
      WHERE branch_id = ?
        AND date_open  = ?
        AND caja_num   = ?
      ORDER BY id ASC
    ");
    $st->execute([$branchId, $date, $cajaNum]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $ids = [];
    foreach ($rows as $r) {
      $id = (int)($r["id"] ?? 0);
      if ($id > 0) $ids[] = $id;
    }
    return $ids;
  } catch (Throwable $e) {
    return [];
  }
}

/**
 * ✅ sumar movimientos de TODAS las sesiones encontradas
 * (tu tabla usa type = ingreso/desembolso, metodo_pago, amount)
 */
function getTotalsBySessionIds(PDO $pdo, array $sessionIds): array {
  $base = ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"cobertura"=>0,"desembolso"=>0];

  if (empty($sessionIds)) {
    return [$base, 0.0, 0.0];
  }

  try {
    $ph = implode(",", array_fill(0, count($sessionIds), "?"));

    $st = $pdo->prepare("
      SELECT
        COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='efectivo' THEN amount END),0) AS efectivo,
        COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='tarjeta' THEN amount END),0) AS tarjeta,
        COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='transferencia' THEN amount END),0) AS transferencia,
        COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='cobertura' THEN amount END),0) AS cobertura,
        COALESCE(SUM(CASE WHEN type='desembolso' THEN amount END),0) AS desembolso
      FROM cash_movements
      WHERE session_id IN ($ph)
    ");
    $st->execute($sessionIds);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: $base;
  } catch (Throwable $e) {
    $r = $base;
  }

  // ingresos = efectivo + tarjeta + transferencia + cobertura
  $ing = (float)$r["efectivo"] + (float)$r["tarjeta"] + (float)$r["transferencia"] + (float)$r["cobertura"];
  $net = $ing - (float)$r["desembolso"];

  return [$r, $ing, $net];
}

$idsCaja1 = getSessionIds($pdo, $branchId, $today, 1);
$idsCaja2 = getSessionIds($pdo, $branchId, $today, 2);

[$r1, $ing1, $net1] = getTotalsBySessionIds($pdo, $idsCaja1);
[$r2, $ing2, $net2] = getTotalsBySessionIds($pdo, $idsCaja2);

$sum = [
  1 => ["r"=>$r1, "ing"=>$ing1, "net"=>$net1],
  2 => ["r"=>$r2, "ing"=>$ing2, "net"=>$net2],
];

// (Opcional) nombre de sucursal (no cambia estilo, solo por si quieres mostrarlo)
$branchName = "";
try {
  $st = $pdo->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $st->execute([$branchId]);
  $branchName = (string)($st->fetchColumn() ?: "");
} catch (Throwable $e) {}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Caja</title>

  <link rel="stylesheet" href="/assets/css/styles.css?v=50">

  <style>
    .caja-container{
      max-width: 1080px;
      margin: 0 auto;
      width: 100%;
      padding: 12px 10px 6px;
    }

    .card-soft{
      background: rgba(255,255,255,.92);
      border: 1px solid rgba(255,255,255,.35);
      border-radius: 16px;
      padding: 16px 18px;
      box-shadow: 0 10px 24px rgba(0,0,0,.07);
    }

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

    .grid-2{
      margin-top: 14px;
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }
    @media (max-width: 980px){
      .grid-2{ grid-template-columns: 1fr; }
    }

    .box-title{
      margin: 0;
      text-align:center;
      font-size: 18px;
      font-weight: 900;
    }

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

      <div class="card-soft header-card">
        <h1>Caja</h1>

        <?php if ($branchName !== ""): ?>
          <div style="font-weight:800; opacity:.75; margin-top:-6px;">
            Sucursal: <?= h($branchName) ?>
          </div>
        <?php endif; ?>

        <div class="actions">
          <a class="btn-pill" href="/private/caja/desembolso.php">Desembolso</a>
          <a class="btn-pill" href="/private/caja/reporte_diario.php">Reporte diario</a>
          <a class="btn-pill" href="/private/caja/reporte_mensual.php">Reporte mensual</a>
        </div>
      </div>

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
              <tr><td>Cobertura</td><td>RD$ <?= fmtMoney($sum[1]["r"]["cobertura"]) ?></td></tr>
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
  © <?= (int)$year ?> CEVIMEP. Todos los derechos reservados.
</footer>

</body>
</html>
