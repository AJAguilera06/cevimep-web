<?php
session_start();
require_once __DIR__ . "/../../config/db.php";
if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }

$user = $_SESSION["user"];
$year = date("Y");
$isAdmin  = (($user["role"] ?? "") === "admin");
$branchId = (int)($user["branch_id"] ?? 0);
if (!$isAdmin && $branchId <= 0) { header("Location: ../../public/logout.php"); exit; }

date_default_timezone_set("America/Santo_Domingo");
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt($n){ return number_format((float)$n, 2, ".", ","); }

$day = $_GET["day"] ?? date("Y-m-d");

$st = $pdo->prepare("
  SELECT s.id, s.caja_num, s.opened_at, s.closed_at
  FROM cash_sessions s
  WHERE s.branch_id=:b AND s.date_open=:d
  ORDER BY s.caja_num ASC
");
$st->execute(["b"=>$branchId, "d"=>$day]);
$sessions = $st->fetchAll(PDO::FETCH_ASSOC);

function totalsForSession(PDO $pdo, int $sid){
  $st = $pdo->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='efectivo' THEN amount END),0) AS efectivo,
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='tarjeta' THEN amount END),0) AS tarjeta,
      COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='transferencia' THEN amount END),0) AS transferencia,
      COALESCE(SUM(CASE WHEN type='desembolso' THEN amount END),0) AS desembolso
    FROM cash_movements
    WHERE session_id=:sid
  ");
  $st->execute(["sid"=>$sid]);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"desembolso"=>0];
  $ing = (float)$r["efectivo"]+(float)$r["tarjeta"]+(float)$r["transferencia"];
  $net = $ing-(float)$r["desembolso"];
  return [$r,$ing,$net];
}

// Totales del día (sumando todas las cajas)
$tot = ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"desembolso"=>0,"ingresos"=>0,"neto"=>0];
$byCaja = [];

foreach($sessions as $s){
  [$r,$ing,$net] = totalsForSession($pdo, (int)$s["id"]);
  $byCaja[(int)$s["caja_num"]] = ["session"=>$s, "r"=>$r, "ing"=>$ing, "net"=>$net];

  $tot["efectivo"] += (float)$r["efectivo"];
  $tot["tarjeta"] += (float)$r["tarjeta"];
  $tot["transferencia"] += (float)$r["transferencia"];
  $tot["desembolso"] += (float)$r["desembolso"];
  $tot["ingresos"] += (float)$ing;
  $tot["neto"] += (float)$net;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Reporte diario</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <style>
    html,body{height:100%;}
    body{margin:0; display:flex; flex-direction:column; min-height:100vh; overflow:hidden !important;}
    .app{flex:1; display:flex; min-height:0;}
    .main{flex:1; min-width:0; overflow:auto; padding:22px;}
    .menu a.active{background:#fff4e6;color:#b45309;border:1px solid #fed7aa;}
    .card{background:#fff;border:1px solid #e6eef7;border-radius:22px;padding:18px;box-shadow:0 10px 30px rgba(2,6,23,.08);}
    .muted{color:#6b7280; font-weight:600;}
    .row{display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:space-between;}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:14px;border:1px solid #dbeafe;background:#fff;color:#052a7a;font-weight:900;text-decoration:none;cursor:pointer;}
    table{width:100%; border-collapse:collapse; margin-top:10px; border:1px solid #e6eef7; border-radius:16px; overflow:hidden;}
    th,td{padding:10px; border-bottom:1px solid #eef2f7; text-align:left; font-size:13px;}
    thead th{background:#f7fbff; color:#0b3b9a; font-weight:900;}
    .grid{display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:14px; margin-top:14px;}
    @media(max-width:900px){ .grid{grid-template-columns:1fr;} }
    input[type="date"]{padding:10px 12px;border-radius:14px;border:1px solid #e6eef7;outline:none;}

    /* ✅ IMPRESIÓN */
    @media print{
      body{overflow:visible !important;}
      .navbar, .sidebar, .footer, .no-print{display:none !important;}
      .app{display:block !important;}
      .main{padding:0 !important; overflow:visible !important;}
      .card{box-shadow:none !important; border:1px solid #e5e7eb !important; border-radius:12px !important;}
      table{border:1px solid #e5e7eb !important;}
      th,td{font-size:12px !important;}
    }
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
      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;"><span class="ico">⏳</span> Coming Soon</a>
    </nav>
  </aside>

  <section class="main">

    <div class="card">
      <div class="row">
        <div>
          <h2 style="margin:0; color:var(--primary-2);">Reporte diario</h2>
          <div class="muted">Detalle por caja y por método de pago.</div>
        </div>

        <div class="row no-print" style="justify-content:flex-end;">
          <form method="get" class="row" style="margin:0;">
            <input type="date" name="day" value="<?php echo h($day); ?>">
            <button class="btn" type="submit">Ver</button>
          </form>

          <button class="btn" type="button" onclick="window.print()">Imprimir</button>
          <a class="btn" href="index.php">Volver</a>
        </div>
      </div>

      <table>
        <thead>
          <tr><th colspan="2">Resumen del día: <?php echo h($day); ?></th></tr>
        </thead>
        <tbody>
          <tr><td>Efectivo</td><td>RD$ <?php echo fmt($tot["efectivo"]); ?></td></tr>
          <tr><td>Tarjeta</td><td>RD$ <?php echo fmt($tot["tarjeta"]); ?></td></tr>
          <tr><td>Transferencia</td><td>RD$ <?php echo fmt($tot["transferencia"]); ?></td></tr>
          <tr><td>Desembolsos</td><td>- RD$ <?php echo fmt($tot["desembolso"]); ?></td></tr>
          <tr><td style="font-weight:900;">Total ingresos</td><td style="font-weight:900;">RD$ <?php echo fmt($tot["ingresos"]); ?></td></tr>
          <tr><td style="font-weight:900;">Neto</td><td style="font-weight:900;">RD$ <?php echo fmt($tot["neto"]); ?></td></tr>
        </tbody>
      </table>
    </div>

    <div class="grid">
      <?php foreach([1,2] as $c): ?>
        <?php $data = $byCaja[$c] ?? null; ?>
        <div class="card">
          <h3 style="margin:0; color:#052a7a;">Caja <?php echo $c; ?></h3>
          <?php if(!$data): ?>
            <div class="muted" style="margin-top:8px;">No hay sesión registrada para esta caja en este día.</div>
          <?php else: ?>
            <div class="muted" style="margin-top:6px;">
              Abierta: <?php echo h($data["session"]["opened_at"]); ?> |
              Cerrada: <?php echo h($data["session"]["closed_at"] ?: "—"); ?>
            </div>
            <table>
              <thead><tr><th>Concepto</th><th>Monto</th></tr></thead>
              <tbody>
                <tr><td>Efectivo</td><td>RD$ <?php echo fmt($data["r"]["efectivo"]); ?></td></tr>
                <tr><td>Tarjeta</td><td>RD$ <?php echo fmt($data["r"]["tarjeta"]); ?></td></tr>
                <tr><td>Transferencia</td><td>RD$ <?php echo fmt($data["r"]["transferencia"]); ?></td></tr>
                <tr><td>Desembolsos</td><td>- RD$ <?php echo fmt($data["r"]["desembolso"]); ?></td></tr>
                <tr><td style="font-weight:900;">Total ingresos</td><td style="font-weight:900;">RD$ <?php echo fmt($data["ing"]); ?></td></tr>
                <tr><td style="font-weight:900;">Neto</td><td style="font-weight:900;">RD$ <?php echo fmt($data["net"]); ?></td></tr>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

  </section>
</main>

<footer class="footer">
  <div class="inner">© <?php echo $year; ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

</body>
</html>
