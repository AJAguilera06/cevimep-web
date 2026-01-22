<?php
declare(strict_types=1);
require_once __DIR__ . "/../_guard.php";

if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }

$user = $_SESSION["user"];
$year = date("Y");

$isAdmin  = (($user["role"] ?? "") === "admin");
$branchId = (int)($user["branch_id"] ?? 0);
$userId   = (int)($user["id"] ?? 0);

if (!$isAdmin && $branchId <= 0) { header("Location: ../../public/logout.php"); exit; }

date_default_timezone_set("America/Santo_Domingo");
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtMoney($n){ return number_format((float)$n, 2, ".", ","); }

$today = date("Y-m-d");

// ✅ Auto cerrar vencidas y abrir sesión actual (sin botones)
$activeSessionId = caja_get_or_open_current_session($pdo, $branchId, $userId);

// Nombre sucursal (si existe branches)
$branchName = "Sucursal #".$branchId;
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

function getTotals(PDO $pdo, int $sessionId){
  $st = $pdo->prepare("SELECT
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='efectivo' THEN amount END),0) AS efectivo,
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='tarjeta' THEN amount END),0) AS tarjeta,
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='transferencia' THEN amount END),0) AS transferencia,
      COALESCE(SUM(CASE WHEN type='desembolso' THEN amount END),0) AS desembolso
    FROM cash_movements
    WHERE session_id=?");
  $st->execute([$sessionId]);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"desembolso"=>0];
  $ing = (float)$r["efectivo"]+(float)$r["tarjeta"]+(float)$r["transferencia"];
  $net = $ing-(float)$r["desembolso"];
  return [$r,$ing,$net];
}

[$s1Start,$s1End] = ["08:00:00","13:00:00"];
[$s2Start,$s2End] = ["13:00:00","18:00:00"];

$caja1 = getSession($pdo, $branchId, 1, $today, $s1Start, $s1End);
$caja2 = getSession($pdo, $branchId, 2, $today, $s2Start, $s2End);

$sum = [
  1 => ["r"=>["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"desembolso"=>0], "ing"=>0, "net"=>0],
  2 => ["r"=>["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"desembolso"=>0], "ing"=>0, "net"=>0],
];

if ($caja1) { [$r,$ing,$net] = getTotals($pdo, (int)$caja1["id"]); $sum[1]=["r"=>$r,"ing"=>$ing,"net"=>$net]; }
if ($caja2) { [$r,$ing,$net] = getTotals($pdo, (int)$caja2["id"]); $sum[2]=["r"=>$r,"ing"=>$ing,"net"=>$net]; }

$currentCajaNum = caja_get_current_caja_num();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Caja</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">

  <style>
    html,body{height:100%;}
    body{margin:0; display:flex; flex-direction:column; min-height:100vh; overflow:hidden !important;}
    .app{flex:1; display:flex; min-height:0;}
    .main{flex:1; min-width:0; overflow:auto; padding:22px;}

    .menu a.active{background:#fff4e6;color:#b45309;border:1px solid #fed7aa;}

    .grid{display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:14px;}
    @media(max-width:900px){ .grid{grid-template-columns:1fr;} }

    .card{
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
    .pill{display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px; background:#f3f7ff; border:1px solid #dbeafe; color:#052a7a; font-weight:900; font-size:12px;}
    .actions{display:flex; gap:10px; flex-wrap:wrap;}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:14px;border:1px solid #dbeafe;background:#fff;color:#052a7a;font-weight:900;text-decoration:none;cursor:pointer;}
  </style>
</head>
<body>

<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right"><a href="../../public/logout.php">Salir</a></div>
  </div>
</header>

<main class="app">
  <aside class="sidebar">
    <div class="title">Menú</div>
    <nav class="menu">
      <a href="../dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="../patients/index.php"><span class="ico">🧑‍🤝‍🧑</span> Pacientes</a>
      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;"><span class="ico">📅</span> Citas</a>
      <a href="../facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a class="active" href="index.php"><span class="ico">💳</span> Caja</a>
      <a href="../inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="../estadistica/index.php"><span class="ico">⏳</span> Estadística</a>
    </nav>
  </aside>

  <section class="main">

    <div class="card" style="margin-bottom:14px;">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;">
        <div>
          <h2 style="margin:0; color:var(--primary-2);">Caja</h2>
          <div class="muted" style="margin-top:6px;">
            <?php echo h($branchName); ?> · Hoy: <?php echo h($today); ?>
          </div>
          <div style="margin-top:10px;">
            <span class="pill">Caja activa ahora: <?php echo (int)$currentCajaNum; ?></span>
            <span class="pill">Sesión activa ID: <?php echo (int)$activeSessionId; ?></span>
          </div>
        </div>

        <div class="actions">
          <a class="btn" href="desembolso.php">Desembolso</a>
          <a class="btn" href="reporte_diario.php">Reporte diario</a>
          <a class="btn" href="reporte_mensual.php">Reporte mensual</a>
        </div>
      </div>

      <div class="muted" style="margin-top:10px;">
        * Las cajas se abren y cierran automáticamente por horario (sin botones).
      </div>
    </div>

    <div class="grid">

      <div class="card">
        <h3 style="margin:0; color:#052a7a;">Caja 1 (08:00 AM - 01:00 PM)</h3>
        <div class="muted" style="margin-top:6px;">
          <?php if(!$caja1): ?>
            Sin sesión registrada hoy.
          <?php else: ?>
            Abierta: <?php echo h($caja1["opened_at"] ?? "—"); ?> · Cerrada: <?php echo h($caja1["closed_at"] ?? "—"); ?>
          <?php endif; ?>
        </div>

        <table>
          <thead><tr><th>Concepto</th><th>Monto</th></tr></thead>
          <tbody>
            <tr><td>Efectivo</td><td>RD$ <?php echo fmtMoney($sum[1]["r"]["efectivo"]); ?></td></tr>
            <tr><td>Tarjeta</td><td>RD$ <?php echo fmtMoney($sum[1]["r"]["tarjeta"]); ?></td></tr>
            <tr><td>Transferencia</td><td>RD$ <?php echo fmtMoney($sum[1]["r"]["transferencia"]); ?></td></tr>
            <tr><td>Desembolsos</td><td>- RD$ <?php echo fmtMoney($sum[1]["r"]["desembolso"]); ?></td></tr>
            <tr><td style="font-weight:900;">Total ingresos</td><td style="font-weight:900;">RD$ <?php echo fmtMoney($sum[1]["ing"]); ?></td></tr>
            <tr><td style="font-weight:900;">Neto</td><td style="font-weight:900;">RD$ <?php echo fmtMoney($sum[1]["net"]); ?></td></tr>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h3 style="margin:0; color:#052a7a;">Caja 2 (01:00 PM - 06:00 PM)</h3>
        <div class="muted" style="margin-top:6px;">
          <?php if(!$caja2): ?>
            Sin sesión registrada hoy.
          <?php else: ?>
            Abierta: <?php echo h($caja2["opened_at"] ?? "—"); ?> · Cerrada: <?php echo h($caja2["closed_at"] ?? "—"); ?>
          <?php endif; ?>
        </div>

        <table>
          <thead><tr><th>Concepto</th><th>Monto</th></tr></thead>
          <tbody>
            <tr><td>Efectivo</td><td>RD$ <?php echo fmtMoney($sum[2]["r"]["efectivo"]); ?></td></tr>
            <tr><td>Tarjeta</td><td>RD$ <?php echo fmtMoney($sum[2]["r"]["tarjeta"]); ?></td></tr>
            <tr><td>Transferencia</td><td>RD$ <?php echo fmtMoney($sum[2]["r"]["transferencia"]); ?></td></tr>
            <tr><td>Desembolsos</td><td>- RD$ <?php echo fmtMoney($sum[2]["r"]["desembolso"]); ?></td></tr>
            <tr><td style="font-weight:900;">Total ingresos</td><td style="font-weight:900;">RD$ <?php echo fmtMoney($sum[2]["ing"]); ?></td></tr>
            <tr><td style="font-weight:900;">Neto</td><td style="font-weight:900;">RD$ <?php echo fmtMoney($sum[2]["net"]); ?></td></tr>
          </tbody>
        </table>
      </div>

    </div>

  </section>
</main>

<footer class="footer">
  <div class="inner">© <?php echo $year; ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

</body>
</html>
