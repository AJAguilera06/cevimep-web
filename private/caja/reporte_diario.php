<?php
session_start();
require_once __DIR__ . "/../../config/db.php";
if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }

$user = $_SESSION["user"];
$year = date("Y");
$isAdmin  = (($user["role"] ?? "") === "admin");
$branchId = (int)($user["branch_id"] ?? 0);
if (!$isAdmin && $branchId <= 0) { header("Location: /logout.php"); exit; }

date_default_timezone_set("America/Santo_Domingo");
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt($n){ return number_format((float)$n, 2, ".", ","); }

$day = $_GET["day"] ?? date("Y-m-d");

/* ==== TU QUERY ORIGINAL (NO TOCO LA LÓGICA) ==== */
$st = $pdo->prepare("
  SELECT s.id, s.caja_num, s.opened_at, s.closed_at
  FROM cash_sessions s
  WHERE DATE(s.opened_at)=?
    AND (?=1 OR s.branch_id=?)
  ORDER BY s.caja_num ASC, s.opened_at ASC
");
$st->execute([$day, $isAdmin ? 1 : 0, $branchId]);
$sessions = $st->fetchAll(PDO::FETCH_ASSOC);

/* Totales por sesión */
$totalsBySession = [];
$detailBySession = [];
foreach ($sessions as $sess) {
  $sid = (int)$sess["id"];

  $stMov = $pdo->prepare("
    SELECT
      SUM(CASE WHEN payment_method='Efectivo' THEN amount ELSE 0 END) AS efectivo,
      SUM(CASE WHEN payment_method='Tarjeta' THEN amount ELSE 0 END) AS tarjeta,
      SUM(CASE WHEN payment_method='Transferencia' THEN amount ELSE 0 END) AS transferencia,
      SUM(CASE WHEN payment_method='Cobertura' THEN amount ELSE 0 END) AS cobertura
    FROM cash_movements
    WHERE session_id=?
  ");
  $stMov->execute([$sid]);
  $mov = $stMov->fetch(PDO::FETCH_ASSOC) ?: ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"cobertura"=>0];

  $stOut = $pdo->prepare("SELECT SUM(amount) AS desembolsos FROM cash_movements WHERE session_id=? AND type='Desembolso'");
  $stOut->execute([$sid]);
  $out = $stOut->fetch(PDO::FETCH_ASSOC) ?: ["desembolsos"=>0];

  $ef  = (float)($mov["efectivo"] ?? 0);
  $ta  = (float)($mov["tarjeta"] ?? 0);
  $tr  = (float)($mov["transferencia"] ?? 0);
  $co  = (float)($mov["cobertura"] ?? 0);
  $des = (float)($out["desembolsos"] ?? 0);

  $ing = $ef + $ta + $tr + $co;
  $net = $ing - $des;

  $totalsBySession[$sid] = [
    "efectivo"=>$ef, "tarjeta"=>$ta, "transferencia"=>$tr, "cobertura"=>$co,
    "desembolsos"=>$des, "ingresos"=>$ing, "neto"=>$net
  ];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Reporte diario</title>

  <!-- ✅ MISMO CSS DEL DASHBOARD -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=11">

  <style>
    /* solo ajustes propios del reporte */
    .card{background:#fff;border:1px solid #e6eef7;border-radius:22px;padding:18px;box-shadow:0 10px 30px rgba(2,6,23,.08);}
    .muted{color:#6b7280; font-weight:700;}
    .row{display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:space-between;}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:14px;border:1px solid #dbeafe;background:#fff;color:#052a7a;font-weight:900;text-decoration:none;cursor:pointer;}
    table{width:100%; border-collapse:collapse; margin-top:10px; border:1px solid #e6eef7; border-radius:16px; overflow:hidden;}
    th,td{padding:10px; border-bottom:1px solid #eef2f7; text-align:left; font-size:13px;}
    thead th{background:#f7fbff; color:#0b3b9a; font-weight:900;}
    input[type="date"]{padding:10px 12px;border-radius:14px;border:1px solid #e6eef7;outline:none;}

    @media print{
      body{overflow:visible !important;}
      .navbar, .sidebar, .footer, .no-print{display:none !important;}
      .layout{display:block !important;}
      .content{padding:0 !important;}
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
    <div class="nav-right">
      <a class="btn-pill" href="/logout.php">Salir</a>
    </div>
  </div>
</header>

<div class="layout">

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
          <a class="btn" href="/private/caja/index.php">Volver</a>
        </div>
      </div>

      <table>
        <thead>
          <tr>
            <th>Caja</th>
            <th>Apertura</th>
            <th>Cierre</th>
            <th>Efectivo</th>
            <th>Tarjeta</th>
            <th>Transferencia</th>
            <th>Cobertura</th>
            <th>Desembolsos</th>
            <th>Total ingresos</th>
            <th>Neto</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($sessions)): ?>
            <tr><td colspan="10" class="muted">No hay sesiones para este día.</td></tr>
          <?php else: ?>
            <?php foreach ($sessions as $s): $sid=(int)$s["id"]; $t=$totalsBySession[$sid] ?? []; ?>
              <tr>
                <td><strong><?php echo (int)$s["caja_num"]; ?></strong></td>
                <td><?php echo h($s["opened_at"]); ?></td>
                <td><?php echo h($s["closed_at"] ?: "—"); ?></td>
                <td>RD$ <?php echo fmt($t["efectivo"] ?? 0); ?></td>
                <td>RD$ <?php echo fmt($t["tarjeta"] ?? 0); ?></td>
                <td>RD$ <?php echo fmt($t["transferencia"] ?? 0); ?></td>
                <td>RD$ <?php echo fmt($t["cobertura"] ?? 0); ?></td>
                <td>- RD$ <?php echo fmt($t["desembolsos"] ?? 0); ?></td>
                <td><strong>RD$ <?php echo fmt($t["ingresos"] ?? 0); ?></strong></td>
                <td><strong>RD$ <?php echo fmt($t["neto"] ?? 0); ?></strong></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </main>
</div>

<footer class="footer">
  <div class="footer-inner">© <?php echo (int)$year; ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

</body>
</html>
