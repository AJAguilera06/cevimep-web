<?php
declare(strict_types=1);

session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/caja_lib.php";

if (empty($_SESSION["user"])) {
  header("Location: /login.php");
  exit;
}

date_default_timezone_set("America/Santo_Domingo");

$user = $_SESSION["user"];
$year = (int)date("Y");
$today = date("Y-m-d");

$isAdmin  = (($user["role"] ?? "") === "admin");
$branchId = (int)($user["branch_id"] ?? 0);
$userId   = (int)($user["id"] ?? 0);

if (!$isAdmin && $branchId <= 0) { header("Location: /logout.php"); exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtMoney($n){ return number_format((float)$n, 2, ".", ","); }

// ✅ Auto cerrar vencidas y abrir sesión actual (sin botones)
$activeSessionId = caja_get_or_open_current_session($pdo, $branchId, $userId);
$currentCajaNum  = caja_get_current_caja_num();

// Nombre sucursal
$branchName = $user["branch_name"] ?? $user["branch"] ?? ("Sucursal #".$branchId);
try {
  $stB = $pdo->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branchId]);
  $bn = $stB->fetchColumn();
  if ($bn) $branchName = (string)$bn;
} catch (Throwable $e) {}

function getSession(PDO $pdo, int $branchId, int $cajaNum, string $date, string $shiftStart, string $shiftEnd){
  $st = $pdo->prepare("SELECT * FROM cash_sessions
                       WHERE branch_id=? AND date_open=? AND caja_num=? AND shift_start=? AND shift_end=?
                       ORDER BY id DESC LIMIT 1");
  $st->execute([$branchId, $date, $cajaNum, $shiftStart, $shiftEnd]);
  return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ✅ Cobertura real (ARS/Seguro) desde invoice_adjustments + invoices
function getCoverageFromInvoicesRange(PDO $pdo, int $branchId, string $startDT, string $endDT): float {
  try {
    $sql = "
      SELECT COALESCE(SUM(ia.amount),0) AS total
      FROM invoice_adjustments ia
      INNER JOIN invoices i ON i.id = ia.invoice_id
      WHERE i.branch_id = ?
        AND i.created_at >= ?
        AND i.created_at <= ?
        AND ia.type IN ('coverage','insurance','cobertura')
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$branchId, $startDT, $endDT]);
    return (float)$st->fetchColumn();
  } catch (Throwable $e) {
    return 0.0;
  }
}

function sessionRange(array $session, string $fallbackDate, string $fallbackStart): array {
  $start = $session['opened_at'] ?? null;
  $end   = $session['closed_at'] ?? null;
  if (!$start) $start = $fallbackDate . ' ' . $fallbackStart;
  if (!$end)   $end   = date('Y-m-d H:i:s');
  return [$start, $end];
}

function getTotals(PDO $pdo, int $sessionId, int $branchId, string $rangeStart, string $rangeEnd){
  $st = $pdo->prepare("SELECT
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='efectivo' THEN amount END),0) AS efectivo,
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='tarjeta' THEN amount END),0) AS tarjeta,
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='transferencia' THEN amount END),0) AS transferencia,
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago IN ('cobertura','seguro') THEN amount END),0) AS cobertura_mov,
      COALESCE(SUM(CASE WHEN type='desembolso' THEN amount END),0) AS desembolso
    FROM cash_movements
    WHERE session_id=?");
  $st->execute([$sessionId]);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"cobertura_mov"=>0,"desembolso"=>0];

  $cobInv = getCoverageFromInvoicesRange($pdo, $branchId, $rangeStart, $rangeEnd);
  $cobMov = (float)$r["cobertura_mov"];
  $cobertura = $cobInv + $cobMov;

  $ing = (float)$r["efectivo"]+(float)$r["tarjeta"]+(float)$r["transferencia"] + $cobertura;
  $net = $ing-(float)$r["desembolso"];
  $r["cobertura"] = $cobertura;
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

if ($caja1) {
  [$rs,$re] = sessionRange($caja1, $today, $s1Start);
  [$r,$ing,$net] = getTotals($pdo, (int)$caja1["id"], $branchId, $rs, $re);
  $sum[1]=["r"=>$r,"ing"=>$ing,"net"=>$net];
}
if ($caja2) {
  [$rs,$re] = sessionRange($caja2, $today, $s2Start);
  [$r,$ing,$net] = getTotals($pdo, (int)$caja2["id"], $branchId, $rs, $re);
  $sum[2]=["r"=>$r,"ing"=>$ing,"net"=>$net];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Caja</title>

  <link rel="stylesheet" href="/assets/css/styles.css?v=11">

  <style>
    /* Mantener el diseño "pill" de los botones, igual al look de tu UI */
    .actions{display:flex; gap:10px; flex-wrap:wrap;}
    .btnLocal{
      display:inline-flex;align-items:center;justify-content:center;
      padding:10px 14px;border-radius:14px;
      border:1px solid #dbeafe;background:#fff;color:#052a7a;
      font-weight:900;text-decoration:none;cursor:pointer;
      transition:transform .05s ease, box-shadow .15s ease;
    }
    .btnLocal:hover{box-shadow:0 10px 25px rgba(2,6,23,.10);}
    .btnLocal:active{transform:translateY(1px);}

    .gridBox{display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:14px;}
    @media(max-width:900px){ .gridBox{grid-template-columns:1fr;} }

    .cardBox{
      background:#fff;
      border:1px solid #e6eef7;
      border-radius:22px;
      padding:18px;
      box-shadow:0 10px 30px rgba(2,6,23,.08);
    }

    table{width:100%; border-collapse:collapse; margin-top:10px; border:1px solid #e6eef7; border-radius:16px; overflow:hidden;}
    th,td{padding:10px; border-bottom:1px solid #eef2f7; text-align:left; font-size:13px;}
    thead th{background:#f7fbff; color:#0b3b9a; font-weight:900;}
    .muted{color:#6b7280; font-weight:700;}
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

  <!-- ✅ MENÚ IDÉNTICO A dashboard.php -->
  <aside class="sidebar">
    <div class="menu-title">Menú</div>

    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👥</span> Pacientes</a>

      <a href="javascript:void(0)" style="opacity:.45; cursor:not-allowed;">
        <span class="ico">🗓️</span> Citas
      </a>

      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a class="active" href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadistica/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">

    <section class="hero">
      <h1>Caja</h1>
      <p><?= h($branchName) ?> · Hoy: <?= h($today) ?></p>
    </section>

    <section class="card">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;">
        <div>
          <h2 style="margin:0; color:var(--primary-2);">Resumen</h2>
          <div class="muted" style="margin-top:6px;">
            Caja activa ahora: <b><?= (int)$currentCajaNum ?></b> · Sesión activa ID: <b><?= (int)$activeSessionId ?></b>
          </div>
          <div class="muted" style="margin-top:10px;">
            * Las cajas se abren y cierran automáticamente por horario (sin botones).
          </div>
        </div>

        <!-- ✅ BOTONES BONITOS + ✅ RUTAS CORRECTAS (EN CAJA) -->
        <div class="actions">
          <a class="btnLocal" href="/private/caja/desembolso.php">Desembolso</a>
          <a class="btnLocal" href="/private/caja/reporte_diario.php">Reporte diario</a>
          <a class="btnLocal" href="/private/caja/reporte_mensual.php">Reporte mensual</a>
        </div>
      </div>
    </section>

    <div style="height:14px;"></div>

    <div class="gridBox">

      <section class="cardBox">
        <h3 style="margin:0; color:#052a7a;">Caja 1 (08:00 AM - 01:00 PM)</h3>
        <div class="muted" style="margin-top:6px;">
          <?php if(!$caja1): ?>
            Sin sesión registrada hoy.
          <?php else: ?>
            Abierta: <?= h($caja1["opened_at"] ?? "—") ?> · Cerrada: <?= h($caja1["closed_at"] ?? "—") ?>
          <?php endif; ?>
        </div>

        <table>
          <thead><tr><th>Concepto</th><th>Monto</th></tr></thead>
          <tbody>
            <tr><td>Efectivo</td><td>RD$ <?= fmtMoney($sum[1]["r"]["efectivo"]) ?></td></tr>
            <tr><td>Tarjeta</td><td>RD$ <?= fmtMoney($sum[1]["r"]["tarjeta"]) ?></td></tr>
            <tr><td>Transferencia</td><td>RD$ <?= fmtMoney($sum[1]["r"]["transferencia"]) ?></td></tr>
            <tr><td>Cobertura</td><td>RD$ <?= fmtMoney($sum[1]["r"]["cobertura"]) ?></td></tr>
            <tr><td>Desembolsos</td><td>- RD$ <?= fmtMoney($sum[1]["r"]["desembolso"]) ?></td></tr>
            <tr><td style="font-weight:900;">Total ingresos</td><td style="font-weight:900;">RD$ <?= fmtMoney($sum[1]["ing"]) ?></td></tr>
            <tr><td style="font-weight:900;">Neto</td><td style="font-weight:900;">RD$ <?= fmtMoney($sum[1]["net"]) ?></td></tr>
          </tbody>
        </table>
      </section>

      <section class="cardBox">
        <h3 style="margin:0; color:#052a7a;">Caja 2 (01:00 PM - 06:00 PM)</h3>
        <div class="muted" style="margin-top:6px;">
          <?php if(!$caja2): ?>
            Sin sesión registrada hoy.
          <?php else: ?>
            Abierta: <?= h($caja2["opened_at"] ?? "—") ?> · Cerrada: <?= h($caja2["closed_at"] ?? "—") ?>
          <?php endif; ?>
        </div>

        <table>
          <thead><tr><th>Concepto</th><th>Monto</th></tr></thead>
          <tbody>
            <tr><td>Efectivo</td><td>RD$ <?= fmtMoney($sum[2]["r"]["efectivo"]) ?></td></tr>
            <tr><td>Tarjeta</td><td>RD$ <?= fmtMoney($sum[2]["r"]["tarjeta"]) ?></td></tr>
            <tr><td>Transferencia</td><td>RD$ <?= fmtMoney($sum[2]["r"]["transferencia"]) ?></td></tr>
            <tr><td>Cobertura</td><td>RD$ <?= fmtMoney($sum[2]["r"]["cobertura"]) ?></td></tr>
            <tr><td>Desembolsos</td><td>- RD$ <?= fmtMoney($sum[2]["r"]["desembolso"]) ?></td></tr>
            <tr><td style="font-weight:900;">Total ingresos</td><td style="font-weight:900;">RD$ <?= fmtMoney($sum[2]["ing"]) ?></td></tr>
            <tr><td style="font-weight:900;">Neto</td><td style="font-weight:900;">RD$ <?= fmtMoney($sum[2]["net"]) ?></td></tr>
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
