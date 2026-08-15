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
    $st = $pdo->prepare("\n      SELECT\n        COALESCE(SUM(CASE WHEN type IN ('ingreso','anulacion') AND metodo_pago='efectivo' THEN amount END),0) AS efectivo,\n        COALESCE(SUM(CASE WHEN type IN ('ingreso','anulacion') AND metodo_pago='tarjeta' THEN amount END),0) AS tarjeta,\n        COALESCE(SUM(CASE WHEN type IN ('ingreso','anulacion') AND metodo_pago='transferencia' THEN amount END),0) AS transferencia,\n        COALESCE(SUM(CASE WHEN type IN ('ingreso','anulacion') AND metodo_pago='cobertura' THEN amount END),0) AS cobertura,\n        COALESCE(SUM(CASE WHEN type='desembolso' THEN ABS(amount) END),0) AS desembolso\n      FROM cash_movements\n      WHERE caja_sesion_id IN ($ph)\n    ");
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

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <style>
    :root{
        --navy-deep:#071B32;
        --teal:#14B8A6;
        --teal-dark:#0D9488;
    }
    *{ box-sizing:border-box; }
    body{
        margin:0;
        font-family:'Inter',sans-serif;
        background:var(--navy-deep);
        color:#fff;
        min-height:100vh;
        display:flex;
        flex-direction:column;
    }
    .navbar{ background:rgba(255,255,255,.04); border-bottom:1px solid rgba(255,255,255,.08); }
    .navbar .inner{ display:grid; grid-template-columns:1fr auto 1fr; align-items:center; padding:16px 28px; }
    .navbar .brand{ grid-column:2; justify-self:center; }
    .navbar .nav-right{ grid-column:3; justify-self:end; }
    .brand{ display:flex; align-items:center; gap:10px; font-family:'Manrope'; font-weight:800; font-size:15px; letter-spacing:.04em; }
    .brand .dot{ width:9px;height:9px;border-radius:50%;background:var(--teal); box-shadow:0 0 0 4px rgba(20,184,166,.18); }
    .btn-pill{ background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.16); color:#fff; padding:9px 16px; border-radius:999px; text-decoration:none; font-weight:700; font-size:13px; }
    .btn-pill:hover{ background:rgba(255,255,255,.14); }

    .layout{ display:flex; width:100%; position:relative; flex:1; }
    .auth-bg{ position:fixed; inset:0; width:100%; height:100%; z-index:0; opacity:.55; pointer-events:none; }

    .sidebar{ position:relative; z-index:1; width:230px; flex-shrink:0; padding:24px 16px; }
    .menu-title{ font-weight:700; font-size:12px; letter-spacing:.08em; text-transform:uppercase; color:#7D95AC; margin:0 0 14px 6px; }
    .menu{ display:flex; flex-direction:column; gap:2px; }
    .menu a{ display:block; padding:10px 12px; border-radius:10px; text-decoration:none; font-size:14px; font-weight:600; color:#B9CBDD; }
    .menu a:hover{ background:rgba(255,255,255,.06); }
    .menu a.active{ background:rgba(20,184,166,.18); color:var(--teal); }

    .content{ position:relative; z-index:1; flex:1; width:100%; padding:40px 40px 60px; }
    .footer{ position:relative; z-index:1; text-align:center; padding:20px; font-size:12px; color:#5C7896; }

    .page-wrap{max-width: 1180px; width:100%; margin: 0 auto;}
    .card-soft{
        background:rgba(255,255,255,.07);
        backdrop-filter:blur(14px);
        -webkit-backdrop-filter:blur(14px);
        border:1px solid rgba(255,255,255,.12);
        border-radius:18px;
        padding:18px;
    }
    .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    .head-row{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:10px;flex-wrap:wrap;}
    .title{margin:0;font-family:'Manrope';font-size:40px;font-weight:900;line-height:1.1;}
    .sub{margin:6px 0 0 0;opacity:.85;color:#9FB6CC;}
    .pill-links{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;}
    .btn-ghost{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:999px;background:rgba(20,184,166,.1);color:var(--teal)!important;border:1px solid rgba(20,184,166,.35);font-weight:800;text-decoration:none;}
    .btn-ghost:hover{background:rgba(20,184,166,.2);}
    table{width:100%;border-collapse:collapse;}
    td{padding:12px 10px;border-bottom:1px solid rgba(255,255,255,.08);color:#E7EEF6;}
    .t-strong{font-weight:900;color:#fff;}
    .box-title{text-align:center;font-weight:900;margin:0;font-size:22px;font-family:'Manrope';}
    .muted{opacity:.75;font-weight:700;color:#9FB6CC;}
    .alert{padding:12px 14px;border-radius:14px;margin-bottom:12px;font-weight:800;}
    .alert.success{background:rgba(20,184,166,.12);border:1px solid rgba(20,184,166,.3);color:var(--teal);}
    .alert.error{background:rgba(220,38,38,.12);border:1px solid rgba(220,38,38,.3);color:#f87171;}
    .card-top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px;}
    .status{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:7px 12px;font-weight:900;font-size:12px;border:1px solid rgba(255,255,255,.16);white-space:nowrap;}
    .status.open{background:rgba(20,184,166,.14);border-color:rgba(20,184,166,.35);color:var(--teal);}
    .status.closed{background:rgba(234,179,8,.14);border-color:rgba(234,179,8,.35);color:#facc15;}
    .status.none{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.14);color:#9FB6CC;}
    .session-info{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 12px;font-size:13px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:10px;margin:10px 0 12px;color:#E7EEF6;}
    .turno-actions{margin-top:14px;}
    .turno-form{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;}
    .turno-form input{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.16);outline:none;background:rgba(255,255,255,.06);color:#fff;font:inherit;}
    .turno-form input::placeholder{color:#7D95AC;}
    .turno-form input:focus{border-color:var(--teal);}
    .turno-form button{border:0;border-radius:999px;padding:11px 16px;font-weight:900;color:#fff;cursor:pointer;white-space:nowrap;}
    .btn-open{background:var(--teal);color:#04211d;}
    .btn-open:hover{background:var(--teal-dark);}
    .btn-close{background:#dc2626;}
    .btn-close:hover{background:#b91c1c;}
    .hint-box{margin-top:14px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:12px;font-weight:700;color:#9FB6CC;}
    @media(max-width:980px){
      .layout{ flex-direction:column; }
      .sidebar{ width:100%; }
      .content{ padding:24px 18px 40px; }
      .grid-2{grid-template-columns:1fr}
      .turno-form{grid-template-columns:1fr}
      .pill-links{justify-content:flex-start}
      .title{font-size:34px}
      .session-info{grid-template-columns:1fr}
    }
  </style>
</head>
<body>

<svg class="auth-bg" viewBox="0 0 1400 800" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
    <circle cx="130" cy="80" r="240" fill="#0D3A5C" opacity=".5"/>
    <circle cx="1280" cy="650" r="280" fill="#0F4A63" opacity=".45"/>
    <g fill="none" stroke="#1D5C7A" stroke-width="1" opacity=".7">
        <line x1="60" y1="60" x2="180" y2="130"/><line x1="180" y1="130" x2="140" y2="250"/>
        <line x1="1220" y1="560" x2="1300" y2="620"/><line x1="1300" y1="620" x2="1250" y2="700"/>
    </g>
    <g fill="#14B8A6" opacity=".85">
        <circle cx="60" cy="60" r="3"/><circle cx="180" cy="130" r="4"/><circle cx="140" cy="250" r="3"/>
        <circle cx="1220" cy="560" r="3"/><circle cx="1300" cy="620" r="4"/><circle cx="1250" cy="700" r="3"/>
    </g>
</svg>

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
          <a class="btn-ghost" href="/private/caja/facturas_canceladas.php">🚫 Facturas canceladas</a>
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