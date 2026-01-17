<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION["user"])) {
  header("Location: /login.php");
  exit;
}

require_once __DIR__ . "/../../config/db.php";

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

$today = date("Y-m-d");
$date  = $_GET["date"] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = $today;

// Nombre sucursal
$branchName = $user["branch_name"] ?? $user["branch"] ?? ("Sucursal #".$branchId);
try {
  $stB = $pdo->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branchId]);
  $bn = $stB->fetchColumn();
  if ($bn) $branchName = (string)$bn;
} catch (Throwable $e) {}

function getSession(PDO $pdo, int $branchId, int $cajaNum, string $date, string $start, string $end): ?array {
  $st = $pdo->prepare("
    SELECT * FROM cash_sessions
    WHERE branch_id=? AND date_open=? AND caja_num=? AND shift_start=? AND shift_end=?
    ORDER BY id DESC LIMIT 1
  ");
  $st->execute([$branchId, $date, $cajaNum, $start, $end]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function getTotals(PDO $pdo, int $sessionId): array {
  $st = $pdo->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='efectivo' THEN amount END),0) AS efectivo,
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='tarjeta' THEN amount END),0) AS tarjeta,
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='transferencia' THEN amount END),0) AS transferencia,
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago IN ('cobertura','seguro') THEN amount END),0) AS cobertura,
      COALESCE(SUM(CASE WHEN type='desembolso' THEN amount END),0) AS desembolso
    FROM cash_movements
    WHERE session_id=?
  ");
  $st->execute([$sessionId]);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"cobertura"=>0,"desembolso"=>0];

  $ing = (float)$r["efectivo"]+(float)$r["tarjeta"]+(float)$r["transferencia"]+(float)$r["cobertura"];
  $net = $ing-(float)$r["desembolso"];

  return [
    "efectivo" => (float)$r["efectivo"],
    "tarjeta" => (float)$r["tarjeta"],
    "transferencia" => (float)$r["transferencia"],
    "cobertura" => (float)$r["cobertura"],
    "desembolso" => (float)$r["desembolso"],
    "ing" => $ing,
    "net" => $net
  ];
}

function getMovements(PDO $pdo, int $sessionId): array {
  $st = $pdo->prepare("
    SELECT id, created_at, type, metodo_pago, concept, amount
    FROM cash_movements
    WHERE session_id=?
    ORDER BY created_at ASC, id ASC
  ");
  $st->execute([$sessionId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$s1 = ["08:00:00","13:00:00"];
$s2 = ["13:00:00","18:00:00"];

$error = "";
$caja1 = $caja2 = null;
$t1 = ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"cobertura"=>0,"desembolso"=>0,"ing"=>0,"net"=>0];
$t2 = $t1;
$m1 = $m2 = [];

try {
  $caja1 = getSession($pdo, $branchId, 1, $date, $s1[0], $s1[1]);
  $caja2 = getSession($pdo, $branchId, 2, $date, $s2[0], $s2[1]);

  if ($caja1) { $t1 = getTotals($pdo, (int)$caja1["id"]); $m1 = getMovements($pdo, (int)$caja1["id"]); }
  if ($caja2) { $t2 = getTotals($pdo, (int)$caja2["id"]); $m2 = getMovements($pdo, (int)$caja2["id"]); }
} catch (Throwable $e) {
  $error = "Error interno generando el reporte. Verifica tablas/columnas (cash_sessions, cash_movements).";
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Reporte Diario Caja</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=11">

  <style>
    .actions{display:flex; gap:10px; flex-wrap:wrap; align-items:center;}
    .btnLocal{
      display:inline-flex;align-items:center;justify-content:center;
      padding:10px 14px;border-radius:14px;
      border:1px solid #dbeafe;background:#fff;color:#052a7a;
      font-weight:900;text-decoration:none;cursor:pointer;
    }
    .btnLocal:hover{box-shadow:0 10px 25px rgba(2,6,23,.10);}
    .gridBox{display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:14px;}
    @media(max-width:900px){ .gridBox{grid-template-columns:1fr;} }
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

  <!-- Sidebar igual al dashboard -->
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
      <h1>Reporte Diario</h1>
      <p><?= h($branchName) ?> · Fecha: <?= h($date) ?></p>
    </section>

    <section class="card">
      <div class="row">
        <form class="row" method="GET" action="/private/caja/reporte_diario.php">
          <div class="pill">
            <label class="muted" style="display:block;font-size:12px;margin-bottom:4px;">Fecha</label>
            <input type="date" name="date" value="<?= h($date) ?>" style="border:0;outline:none;font-weight:800;">
          </div>
          <button class="btnLocal" type="submit">Ver</button>
        </form>

        <div class="right actions">
          <a class="btnLocal" href="/private/caja/index.php">Volver a Caja</a>
          <a class="btnLocal" href="javascript:void(0)" onclick="window.print()">Imprimir</a>
        </div>
      </div>

      <?php if($error): ?>
        <div style="margin-top:12px;" class="danger"><?= h($error) ?></div>
      <?php endif; ?>
    </section>

    <div style="height:14px;"></div>

    <div class="gridBox">
      <section class="cardBox">
        <h3 style="margin:0; color:#052a7a;">Caja 1 (08:00 AM - 01:00 PM)</h3>
        <div class="muted" style="margin-top:6px;"><?= $caja1 ? "Sesión ID: ".(int)$caja1["id"] : "Sin sesión registrada" ?></div>

        <table>
          <thead><tr><th>Concepto</th><th>Monto</th></tr></thead>
          <tbody>
            <tr><td>Efectivo</td><td>RD$ <?= money($t1["efectivo"]) ?></td></tr>
            <tr><td>Tarjeta</td><td>RD$ <?= money($t1["tarjeta"]) ?></td></tr>
            <tr><td>Transferencia</td><td>RD$ <?= money($t1["transferencia"]) ?></td></tr>
            <tr><td>Cobertura</td><td>RD$ <?= money($t1["cobertura"]) ?></td></tr>
            <tr><td>Desembolsos</td><td>- RD$ <?= money($t1["desembolso"]) ?></td></tr>
            <tr><td style="font-weight:900;">Total ingresos</td><td style="font-weight:900;">RD$ <?= money($t1["ing"]) ?></td></tr>
            <tr><td style="font-weight:900;">Neto</td><td style="font-weight:900;">RD$ <?= money($t1["net"]) ?></td></tr>
          </tbody>
        </table>

        <div style="height:10px;"></div>
        <h4 style="margin:0;color:#052a7a;">Movimientos</h4>

        <table>
          <thead><tr><th>Hora</th><th>Tipo</th><th>Método</th><th>Concepto</th><th>Monto</th></tr></thead>
          <tbody>
            <?php if (!$m1): ?>
              <tr><td colspan="5" class="muted">Sin movimientos.</td></tr>
            <?php else: foreach ($m1 as $mv): ?>
              <tr>
                <td><?= h(substr((string)$mv["created_at"], 11, 5)) ?></td>
                <td><?= h($mv["type"]) ?></td>
                <td><?= h($mv["metodo_pago"]) ?></td>
                <td><?= h($mv["concept"] ?? "") ?></td>
                <td><?= ($mv["type"] === "desembolso" ? "- " : "") ?>RD$ <?= money($mv["amount"]) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </section>

      <section class="cardBox">
        <h3 style="margin:0; color:#052a7a;">Caja 2 (01:00 PM - 06:00 PM)</h3>
        <div class="muted" style="margin-top:6px;"><?= $caja2 ? "Sesión ID: ".(int)$caja2["id"] : "Sin sesión registrada" ?></div>

        <table>
          <thead><tr><th>Concepto</th><th>Monto</th></tr></thead>
          <tbody>
            <tr><td>Efectivo</td><td>RD$ <?= money($t2["efectivo"]) ?></td></tr>
            <tr><td>Tarjeta</td><td>RD$ <?= money($t2["tarjeta"]) ?></td></tr>
            <tr><td>Transferencia</td><td>RD$ <?= money($t2["transferencia"]) ?></td></tr>
            <tr><td>Cobertura</td><td>RD$ <?= money($t2["cobertura"]) ?></td></tr>
            <tr><td>Desembolsos</td><td>- RD$ <?= money($t2["desembolso"]) ?></td></tr>
            <tr><td style="font-weight:900;">Total ingresos</td><td style="font-weight:900;">RD$ <?= money($t2["ing"]) ?></td></tr>
            <tr><td style="font-weight:900;">Neto</td><td style="font-weight:900;">RD$ <?= money($t2["net"]) ?></td></tr>
          </tbody>
        </table>

        <div style="height:10px;"></div>
        <h4 style="margin:0;color:#052a7a;">Movimientos</h4>

        <table>
          <thead><tr><th>Hora</th><th>Tipo</th><th>Método</th><th>Concepto</th><th>Monto</th></tr></thead>
          <tbody>
            <?php if (!$m2): ?>
              <tr><td colspan="5" class="muted">Sin movimientos.</td></tr>
            <?php else: foreach ($m2 as $mv): ?>
              <tr>
                <td><?= h(substr((string)$mv["created_at"], 11, 5)) ?></td>
                <td><?= h($mv["type"]) ?></td>
                <td><?= h($mv["metodo_pago"]) ?></td>
                <td><?= h($mv["concept"] ?? "") ?></td>
                <td><?= ($mv["type"] === "desembolso" ? "- " : "") ?>RD$ <?= money($mv["amount"]) ?></td>
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
