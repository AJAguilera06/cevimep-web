<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

date_default_timezone_set("America/Santo_Domingo");

$user = $_SESSION["user"] ?? [];
$branchId = (int)($user["branch_id"] ?? ($_SESSION["branch_id"] ?? 0));

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtMoney($n){ return number_format((float)$n, 2, ".", ","); }

// Fecha (preferible MySQL)
try {
  $today = (string)$pdo->query("SELECT CURDATE()")->fetchColumn();
  if ($today === "") $today = date("Y-m-d");
} catch (Throwable $e) {
  $today = date("Y-m-d");
}

function getSessionIds(PDO $pdo, int $branchId, string $date, int $cajaNum): array {
  try {
    $st = $pdo->prepare("
      SELECT id
      FROM caja_sesiones
      WHERE branch_id = ?
        AND caja_num  = ?
        AND fecha     = ?
    ");
    $st->execute([$branchId, $cajaNum, $date]);
    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

function getTotalsBySessionIds(PDO $pdo, array $sessionIds): array {
  $base = ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"cobertura"=>0,"desembolso"=>0];

  if (empty($sessionIds)) return [$base, 0.0, 0.0];

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
      WHERE caja_sesion_id IN ($ph)
    ");
    $st->execute($sessionIds);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: $base;
  } catch (Throwable $e) {
    $r = $base;
  }

  $ing = (float)$r["efectivo"] + (float)$r["tarjeta"] + (float)$r["transferencia"] + (float)$r["cobertura"];
  $net = $ing + (float)$r["desembolso"]; // desembolso es negativo

  return [$r, $ing, $net];
}

$idsCaja1 = getSessionIds($pdo, $branchId, $today, 1);
$idsCaja2 = getSessionIds($pdo, $branchId, $today, 2);

[$r1, $ing1, $net1] = getTotalsBySessionIds($pdo, $idsCaja1);
[$r2, $ing2, $net2] = getTotalsBySessionIds($pdo, $idsCaja2);

// Nombre sucursal
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
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Caja</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=100">
  <style>
    .page-wrap{max-width: 1100px; margin: 0 auto;}
    .card-soft{background:#fff;border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:18px;}
    .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    .head-row{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:10px;}
    .title{margin:0;font-size:40px;line-height:1.1;}
    .sub{margin:6px 0 0 0;opacity:.85;}
    .pill-links{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;}
    .btn-ghost{
      display:inline-flex; align-items:center; gap:8px;
      padding: 10px 14px; border-radius: 999px;
      background:#fff; color:#0b5ed7 !important;
      border: 2px solid rgba(11,94,215,.35);
      font-weight: 800; text-decoration:none;
    }
    .btn-ghost:hover{background: rgba(11,94,215,.06);}
    table{width:100%;border-collapse:collapse;}
    td{padding:12px 10px;border-bottom:1px solid #eee;}
    .t-strong{font-weight:900;}
    .box-title{text-align:center;font-weight:900;margin:0 0 8px 0;}
    .muted{opacity:.75;font-weight:700;}
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
    <div class="page-wrap">

      <div class="head-row">
        <div>
          <h1 class="title">Caja</h1>
          <p class="sub">
            <span class="muted">Sucursal:</span> <?= h($branchName ?: '—') ?> ·
            <span class="muted">Fecha:</span> <?= h($today) ?>
          </p>
        </div>

        <div class="pill-links">
          <a class="btn-ghost" href="/private/caja/desembolso.php">💸 Desembolso</a>
          <a class="btn-ghost" href="/private/caja/reporte_diario.php">📄 Reporte diario</a>
          <a class="btn-ghost" href="/private/caja/reporte_mensual.php">📅 Reporte mensual</a>
        </div>
      </div>

      <div class="grid-2">

        <div class="card-soft">
          <h2 class="box-title">Caja 1 (7:00 AM - 12:59 PM)</h2>
          <table>
            <tr><td>Efectivo</td><td>RD$ <?= fmtMoney($r1["efectivo"]) ?></td></tr>
            <tr><td>Tarjeta</td><td>RD$ <?= fmtMoney($r1["tarjeta"]) ?></td></tr>
            <tr><td>Transferencia</td><td>RD$ <?= fmtMoney($r1["transferencia"]) ?></td></tr>
            <tr><td>Cobertura</td><td>RD$ <?= fmtMoney($r1["cobertura"]) ?></td></tr>
            <tr><td>Desembolsos</td><td>RD$ <?= fmtMoney($r1["desembolso"]) ?></td></tr>
            <tr><td class="t-strong">Total ingresos</td><td class="t-strong">RD$ <?= fmtMoney($ing1) ?></td></tr>
            <tr><td class="t-strong">Neto</td><td class="t-strong">RD$ <?= fmtMoney($net1) ?></td></tr>
          </table>
        </div>

        <div class="card-soft">
          <h2 class="box-title">Caja 2 (1:00 PM - 11:59 PM)</h2>
          <table>
            <tr><td>Efectivo</td><td>RD$ <?= fmtMoney($r2["efectivo"]) ?></td></tr>
            <tr><td>Tarjeta</td><td>RD$ <?= fmtMoney($r2["tarjeta"]) ?></td></tr>
            <tr><td>Transferencia</td><td>RD$ <?= fmtMoney($r2["transferencia"]) ?></td></tr>
            <tr><td>Cobertura</td><td>RD$ <?= fmtMoney($r2["cobertura"]) ?></td></tr>
            <tr><td>Desembolsos</td><td>RD$ <?= fmtMoney($r2["desembolso"]) ?></td></tr>
            <tr><td class="t-strong">Total ingresos</td><td class="t-strong">RD$ <?= fmtMoney($ing2) ?></td></tr>
            <tr><td class="t-strong">Neto</td><td class="t-strong">RD$ <?= fmtMoney($net2) ?></td></tr>
          </table>
        </div>

      </div>

    </div>
  </main>
</div>

<footer class="footer">
  © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>
