<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
require_once __DIR__ . "/caja_helpers.php";

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

date_default_timezone_set("America/Santo_Domingo");

$user = $_SESSION["user"] ?? [];
$branchId = (int)($user["branch_id"] ?? ($_SESSION["branch_id"] ?? 0));
$userId = (int)($user["id"] ?? 0);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtMoney($n){ return number_format((float)$n, 2, ".", ","); }

if ($branchId <= 0) {
  die("Sucursal inválida.");
}

$today = date("Y-m-d");
$msg = "";
$msgType = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = (string)($_POST["action"] ?? "");
  $cajaNum = (int)($_POST["caja_num"] ?? 0);

  try {
    if ($action === "abrir") {
      $montoInicial = (float)($_POST["monto_inicial"] ?? 0);
      caja_abrir_sesion($pdo, $branchId, $cajaNum, $montoInicial, $userId);
      $msg = "Caja {$cajaNum} abierta correctamente.";
      $msgType = "success";
    } elseif ($action === "cerrar") {
      $montoFinal = (float)($_POST["monto_final"] ?? 0);
      caja_cerrar_sesion($pdo, $branchId, $cajaNum, $montoFinal, $userId);
      $msg = "Caja {$cajaNum} cerrada correctamente.";
      $msgType = "success";
    }
  } catch (Throwable $e) {
    $msg = $e->getMessage();
    $msgType = "error";
  }
}

function getSessionIds(PDO $pdo, int $branchId, string $date, int $cajaNum): array {
  try {
    $st = $pdo->prepare("\n      SELECT id\n      FROM caja_sesiones\n      WHERE branch_id = ?\n        AND caja_num  = ?\n        AND fecha     = ?\n      ORDER BY id ASC\n    ");
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
    $st = $pdo->prepare("\n      SELECT\n        COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='efectivo' THEN amount END),0) AS efectivo,\n        COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='tarjeta' THEN amount END),0) AS tarjeta,\n        COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='transferencia' THEN amount END),0) AS transferencia,\n        COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='cobertura' THEN amount END),0) AS cobertura,\n        COALESCE(SUM(CASE WHEN type='desembolso' THEN ABS(amount) END),0) AS desembolso\n      FROM cash_movements\n      WHERE caja_sesion_id IN ($ph)\n    ");
    $st->execute($sessionIds);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: $base;
  } catch (Throwable $e) {
    $r = $base;
  }

  $ing = (float)$r["efectivo"] + (float)$r["tarjeta"] + (float)$r["transferencia"] + (float)$r["cobertura"];
  $net = $ing - (float)$r["desembolso"];

  return [$r, $ing, $net];
}

$idsCaja1 = getSessionIds($pdo, $branchId, $today, 1);
$idsCaja2 = getSessionIds($pdo, $branchId, $today, 2);

[$r1, $ing1, $net1] = getTotalsBySessionIds($pdo, $idsCaja1);
[$r2, $ing2, $net2] = getTotalsBySessionIds($pdo, $idsCaja2);

$open1 = caja_get_open_session($pdo, $branchId, 1, $today);
$open2 = caja_get_open_session($pdo, $branchId, 2, $today);
$latest1 = caja_get_latest_session($pdo, $branchId, $today, 1);
$latest2 = caja_get_latest_session($pdo, $branchId, $today, 2);

$branchName = "";
try {
  $st = $pdo->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $st->execute([$branchId]);
  $branchName = (string)($st->fetchColumn() ?: "");
} catch (Throwable $e) {}

function cajaStatusText(?array $open, ?array $latest): string {
  if ($open) return 'ABIERTA';
  if ($latest && (($latest['estado'] ?? '') === 'cerrada')) return 'CERRADA';
  return 'SIN ABRIR';
}

function cajaStatusClass(?array $open, ?array $latest): string {
  if ($open) return 'open';
  if ($latest && (($latest['estado'] ?? '') === 'cerrada')) return 'closed';
  return 'none';
}

function renderCajaCard(int $num, string $titulo, array $r, float $ing, float $net, ?array $open, ?array $latest): void {
  $statusText = cajaStatusText($open, $latest);
  $statusClass = cajaStatusClass($open, $latest);
  $montoInicial = $open['monto_inicial'] ?? ($latest['monto_inicial'] ?? null);
  $montoFinal = $latest['monto_final'] ?? null;
  $diferencia = $latest['diferencia'] ?? null;
  ?>
  <div class="card-soft caja-card">
    <div class="card-top">
      <h2 class="box-title"><?= h($titulo) ?></h2>
      <span class="status <?= h($statusClass) ?>"><?= h($statusText) ?></span>
    </div>

    <div class="session-info">
      <div><strong>Sesiones hoy:</strong> <?= (int)count($GLOBALS['idsCaja' . $num] ?? []) ?></div>
      <?php if ($montoInicial !== null): ?>
        <div><strong>Inicial:</strong> RD$ <?= fmtMoney($montoInicial) ?></div>
      <?php endif; ?>
      <?php if ($montoFinal !== null): ?>
        <div><strong>Final:</strong> RD$ <?= fmtMoney($montoFinal) ?></div>
      <?php endif; ?>
      <?php if ($diferencia !== null): ?>
        <div><strong>Diferencia:</strong> RD$ <?= fmtMoney($diferencia) ?></div>
      <?php endif; ?>
    </div>

    <table>
      <tr><td>Efectivo</td><td>RD$ <?= fmtMoney($r["efectivo"] ?? 0) ?></td></tr>
      <tr><td>Tarjeta</td><td>RD$ <?= fmtMoney($r["tarjeta"] ?? 0) ?></td></tr>
      <tr><td>Transferencia</td><td>RD$ <?= fmtMoney($r["transferencia"] ?? 0) ?></td></tr>
      <tr><td>Cobertura</td><td>RD$ <?= fmtMoney($r["cobertura"] ?? 0) ?></td></tr>
      <tr><td>Desembolsos</td><td>RD$ <?= fmtMoney($r["desembolso"] ?? 0) ?></td></tr>
      <tr><td class="t-strong">Total ingresos</td><td class="t-strong">RD$ <?= fmtMoney($ing) ?></td></tr>
      <tr><td class="t-strong">Neto</td><td class="t-strong">RD$ <?= fmtMoney($net) ?></td></tr>
    </table>

    <div class="turno-actions">
      <?php if ($open): ?>
        <form method="post" class="turno-form" onsubmit="return confirm('¿Seguro que deseas cerrar Caja <?= (int)$num ?>?');">
          <input type="hidden" name="action" value="cerrar">
          <input type="hidden" name="caja_num" value="<?= (int)$num ?>">
          <input type="number" step="0.01" min="0" name="monto_final" placeholder="Efectivo final RD$" required>
          <button type="submit" class="btn-close">Cerrar caja</button>
        </form>
      <?php else: ?>
        <form method="post" class="turno-form">
          <input type="hidden" name="action" value="abrir">
          <input type="hidden" name="caja_num" value="<?= (int)$num ?>">
          <input type="number" step="0.01" min="0" name="monto_inicial" placeholder="Efectivo inicial RD$" value="0.00" required>
          <button type="submit" class="btn-open">Abrir caja</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Caja</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=110">
  <style>
    .page-wrap{max-width: 1180px; width:100%; margin: 0 auto;}
    .card-soft{background:#fff;border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:18px;}
    .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    .head-row{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:10px;flex-wrap:wrap;}
    .title{margin:0;font-size:40px;line-height:1.1;}
    .sub{margin:6px 0 0 0;opacity:.85;}
    .pill-links{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;}
    .btn-ghost{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:999px;background:#fff;color:#0b5ed7!important;border:2px solid rgba(11,94,215,.35);font-weight:800;text-decoration:none;}
    .btn-ghost:hover{background:rgba(11,94,215,.06);}
    table{width:100%;border-collapse:collapse;}
    td{padding:12px 10px;border-bottom:1px solid #eee;}
    .t-strong{font-weight:900;}
    .box-title{text-align:center;font-weight:900;margin:0;font-size:22px;}
    .muted{opacity:.75;font-weight:700;}
    .alert{padding:12px 14px;border-radius:14px;margin-bottom:12px;font-weight:800;}
    .alert.success{background:#eaf8ef;border:1px solid #bfe7c9;color:#166534;}
    .alert.error{background:#ffe9e9;border:1px solid #f3b2b2;color:#991b1b;}
    .card-top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px;}
    .status{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:7px 12px;font-weight:900;font-size:12px;border:1px solid #ddd;white-space:nowrap;}
    .status.open{background:#eaf8ef;border-color:#bfe7c9;color:#166534;}
    .status.closed{background:#fff3cd;border-color:#ffe08a;color:#8a5a00;}
    .status.none{background:#f1f5f9;border-color:#dbe3ea;color:#475569;}
    .session-info{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 12px;font-size:13px;background:#f8fafc;border:1px solid #eef2f6;border-radius:14px;padding:10px;margin:10px 0 12px;}
    .turno-actions{margin-top:14px;}
    .turno-form{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;}
    .turno-form input{width:100%;padding:10px 12px;border-radius:12px;border:1px solid #d9d9d9;outline:none;}
    .turno-form button{border:0;border-radius:999px;padding:11px 16px;font-weight:900;color:#fff;cursor:pointer;white-space:nowrap;}
    .btn-open{background:#16a34a;}
    .btn-close{background:#dc2626;}
    .hint-box{margin-top:14px;background:#f8fafc;border:1px solid #e6eef7;border-radius:16px;padding:12px;font-weight:700;color:#475569;}
    @media(max-width:980px){.grid-2{grid-template-columns:1fr}.turno-form{grid-template-columns:1fr}.pill-links{justify-content:flex-start}.title{font-size:34px}.session-info{grid-template-columns:1fr}}
  </style>
</head>
<body>

<header class="navbar">
  <div class="inner">
    <div class="brand"><span class="dot"></span><span>CEVIMEP</span></div>
    <div class="nav-right"><a href="/logout.php" class="btn-pill">Salir</a></div>
  </div>
</header>

<div class="layout">
  <aside class="sidebar">
    <div class="menu-title">Menú</div>
    <nav class="menu">
      <a href="/private/dashboard.php">🏠 Panel</a>
      <a href="/private/patients/index.php">👤 Pacientes</a>
      <a href="#" onclick="return false;" style="opacity:.5;cursor:not-allowed;">📅 Citas (Próximamente)</a>
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
          <p class="sub"><span class="muted">Sucursal:</span> <?= h($branchName ?: '—') ?> · <span class="muted">Fecha:</span> <?= h($today) ?></p>
        </div>

        <div class="pill-links">
          <a class="btn-ghost" href="/private/caja/desembolso.php">💸 Desembolso</a>
          <a class="btn-ghost" href="/private/caja/reporte_diario.php">📄 Reporte diario</a>
          <a class="btn-ghost" href="/private/caja/movimiento_diario.php">📋 Movimiento diario</a>
          <a class="btn-ghost" href="/private/caja/reporte_mensual.php">📅 Reporte mensual</a>
        </div>
      </div>

      <?php if ($msg): ?>
        <div class="alert <?= h($msgType) ?>"><?= h($msg) ?></div>
      <?php endif; ?>

      <div class="grid-2">
        <?php renderCajaCard(1, 'Caja 1 ', $r1, $ing1, $net1, $open1, $latest1); ?>
        <?php renderCajaCard(2, 'Caja 2 ', $r2, $ing2, $net2, $open2, $latest2); ?>
      </div>

      <div class="hint-box">
        Para llevar control por turnos: primero abre la caja del turno, luego registra facturas/desembolsos, y al finalizar escribe el efectivo final para cerrar.
      </div>
    </div>
  </main>
</div>

<footer class="footer">© <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.</footer>
</body>
</html>
