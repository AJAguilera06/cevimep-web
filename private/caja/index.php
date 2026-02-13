<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if (!isset($_SESSION["user"])) { header("Location: /login.php"); exit; }

date_default_timezone_set("America/Santo_Domingo");

$user = $_SESSION["user"];
$year = date("Y");

$isAdmin  = (($user["role"] ?? "") === "admin");
$branchId = (int)($user["branch_id"] ?? 0);
$userId   = (int)($user["id"] ?? 0);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtMoney($n){ return number_format((float)$n, 2, ".", ","); }

$today = date("Y-m-d");

require_once __DIR__ . "/caja_lib.php";

/**
 * ✅ Branch efectivo:
 * - Si el usuario tiene branch_id > 0, usamos ese.
 * - Si es admin y branch_id = 0, usamos ?branch_id=... si viene
 * - Si no viene, usamos el primer branch de la BD.
 */
$effectiveBranchId = $branchId;

if ($effectiveBranchId <= 0 && $isAdmin) {
  $effectiveBranchId = (int)($_GET["branch_id"] ?? 0);

  if ($effectiveBranchId <= 0) {
    try {
      $st = $pdo->query("SELECT id FROM branches ORDER BY id ASC LIMIT 1");
      $effectiveBranchId = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
      $effectiveBranchId = 0;
    }
  }
}

// Si no es admin y no tiene sucursal, fuera.
if (!$isAdmin && $effectiveBranchId <= 0) {
  header("Location: /logout.php"); exit;
}

// Si es admin y aun así no encontró branch, no seguir
if ($isAdmin && $effectiveBranchId <= 0) {
  die("No hay sucursales configuradas.");
}

/**
 * Mantener comportamiento de auto-cerrar/abrir sesión actual (no lo quito)
 * ✅ IMPORTANTE: usar el branch efectivo
 */
try {
  if (function_exists("caja_get_or_open_current_session")) {
    caja_get_or_open_current_session($pdo, $effectiveBranchId, $userId);
  }
} catch (Throwable $e) {}

/**
 * ✅ Traer TODAS las sesiones del día por:
 * branch_id + date_open + caja_num
 */
function getSessionIds(PDO $pdo, int $branchId, string $dateOpen, int $cajaNum): array {
  try {
    $st = $pdo->prepare("
      SELECT id
      FROM cash_sessions
      WHERE branch_id = ?
        AND date_open  = ?
        AND caja_num   = ?
      ORDER BY id ASC
    ");
    $st->execute([$branchId, $dateOpen, $cajaNum]);
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
 * ✅ Sumar movimientos de todas las sesiones del día
 * (tu cash_movements usa: type, metodo_pago, amount)
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

$idsCaja1 = getSessionIds($pdo, $effectiveBranchId, $today, 1);
$idsCaja2 = getSessionIds($pdo, $effectiveBranchId, $today, 2);

[$r1, $ing1, $net1] = getTotalsBySessionIds($pdo, $idsCaja1);
[$r2, $ing2, $net2] = getTotalsBySessionIds($pdo, $idsCaja2);

$sum = [
  1 => ["r"=>$r1, "ing"=>$ing1, "net"=>$net1],
  2 => ["r"=>$r2, "ing"=>$ing2, "net"=>$net2],
];

// (Opcional) nombre de sucursal para admin (solo visual)
$branchName = "";
try {
  $st = $pdo->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $st->execute([$effectiveBranchId]);
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

      <div class="caja-header">
        <h1>Caja</h1>

        <?php if ($isAdmin && $branchName !== ""): ?>
          <div style="margin-top:6px; font-weight:800; opacity:.75;">
            Sucursal: <?= h($branchName) ?>
          </div>
        <?php endif; ?>

        <div class="caja-actions">
          <a class="btn-pill" href="/private/caja/desembolso.php">Desembolso</a>
          <a class="btn-pill" href="/private/caja/reporte_diario.php">Reporte diario</a>
          <a class="btn-pill" href="/private/caja/reporte_mensual.php">Reporte mensual</a>
        </div>
      </div>

      <div class="caja-grid">

        <div class="caja-card">
          <div class="caja-title">Caja 1 (08:00 AM - 01:00 PM)</div>
          <table class="caja-table">
            <thead><tr><th>Concepto</th><th>Monto</th></tr></thead>
            <tbody>
              <tr><td>Efectivo</td><td>RD$ <?= fmtMoney($sum[1]["r"]["efectivo"]) ?></td></tr>
              <tr><td>Tarjeta</td><td>RD$ <?= fmtMoney($sum[1]["r"]["tarjeta"]) ?></td></tr>
              <tr><td>Transferencia</td><td>RD$ <?= fmtMoney($sum[1]["r"]["transferencia"]) ?></td></tr>
              <tr><td>Cobertura</td><td>RD$ <?= fmtMoney($sum[1]["r"]["cobertura"]) ?></td></tr>
              <tr><td>Desembolsos</td><td>- RD$ <?= fmtMoney($sum[1]["r"]["desembolso"]) ?></td></tr>
              <tr class="strong"><td>Total ingresos</td><td>RD$ <?= fmtMoney($sum[1]["ing"]) ?></td></tr>
              <tr class="strong"><td>Neto</td><td>RD$ <?= fmtMoney($sum[1]["net"]) ?></td></tr>
            </tbody>
          </table>
        </div>

        <div class="caja-card">
          <div class="caja-title">Caja 2 (01:00 PM - 06:00 PM)</div>
          <table class="caja-table">
            <thead><tr><th>Concepto</th><th>Monto</th></tr></thead>
            <tbody>
              <tr><td>Efectivo</td><td>RD$ <?= fmtMoney($sum[2]["r"]["efectivo"]) ?></td></tr>
              <tr><td>Tarjeta</td><td>RD$ <?= fmtMoney($sum[2]["r"]["tarjeta"]) ?></td></tr>
              <tr><td>Transferencia</td><td>RD$ <?= fmtMoney($sum[2]["r"]["transferencia"]) ?></td></tr>
              <tr><td>Cobertura</td><td>RD$ <?= fmtMoney($sum[2]["r"]["cobertura"]) ?></td></tr>
              <tr><td>Desembolsos</td><td>- RD$ <?= fmtMoney($sum[2]["r"]["desembolso"]) ?></td></tr>
              <tr class="strong"><td>Total ingresos</td><td>RD$ <?= fmtMoney($sum[2]["ing"]) ?></td></tr>
              <tr class="strong"><td>Neto</td><td>RD$ <?= fmtMoney($sum[2]["net"]) ?></td></tr>
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
