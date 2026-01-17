<?php
session_start();

if (!isset($_SESSION["user"])) {
  header("Location: ../../public/login.php");
  exit;
}

require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/caja_lib.php";

$user = $_SESSION["user"];
$year = date("Y");

$branch_id = (int)($user["branch_id"] ?? 0);
$user_id   = (int)($user["id"] ?? 0);

date_default_timezone_set("America/Santo_Domingo");
$today = date("Y-m-d");

function money($n) {
  return number_format((float)$n, 2, ".", ",");
}

/* ===========================
   CAJA ACTIVA AUTOMÁTICA
=========================== */
$active_session_id = caja_get_or_open_current_session($pdo, $branch_id, $user_id);
$current_caja = caja_get_current_caja_num();

/* ===========================
   HORARIOS
=========================== */
$s1 = ["08:00:00", "13:00:00"];
$s2 = ["13:00:00", "18:00:00"];

function getCaja(PDO $pdo, $branch_id, $num, $date, $start, $end) {
  $st = $pdo->prepare("
    SELECT * FROM cash_sessions
    WHERE branch_id=? AND caja_num=? AND date_open=? 
    AND shift_start=? AND shift_end=?
    ORDER BY id DESC LIMIT 1
  ");
  $st->execute([$branch_id, $num, $date, $start, $end]);
  return $st->fetch(PDO::FETCH_ASSOC);
}

$caja1 = getCaja($pdo, $branch_id, 1, $today, $s1[0], $s1[1]);
$caja2 = getCaja($pdo, $branch_id, 2, $today, $s2[0], $s2[1]);

function totals(PDO $pdo, $session_id) {
  if (!$session_id) return [
    "efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"cobertura"=>0,"desembolso"=>0,"ing"=>0,"neto"=>0
  ];

  $st = $pdo->prepare("
    SELECT
      SUM(CASE WHEN type='ingreso' AND metodo_pago='efectivo' THEN amount ELSE 0 END) efectivo,
      SUM(CASE WHEN type='ingreso' AND metodo_pago='tarjeta' THEN amount ELSE 0 END) tarjeta,
      SUM(CASE WHEN type='ingreso' AND metodo_pago='transferencia' THEN amount ELSE 0 END) transferencia,
      SUM(CASE WHEN type='ingreso' AND metodo_pago IN ('cobertura','seguro') THEN amount ELSE 0 END) cobertura,
      SUM(CASE WHEN type='desembolso' THEN amount ELSE 0 END) desembolso
    FROM cash_movements
    WHERE session_id=?
  ");
  $st->execute([$session_id]);
  $r = $st->fetch(PDO::FETCH_ASSOC);

  $ing = $r["efectivo"] + $r["tarjeta"] + $r["transferencia"] + $r["cobertura"];
  $net = $ing - $r["desembolso"];

  return [
    "efectivo"=>$r["efectivo"],
    "tarjeta"=>$r["tarjeta"],
    "transferencia"=>$r["transferencia"],
    "cobertura"=>$r["cobertura"],
    "desembolso"=>$r["desembolso"],
    "ing"=>$ing,
    "neto"=>$net
  ];
}

$t1 = $caja1 ? totals($pdo, $caja1["id"]) : totals($pdo, null);
$t2 = $caja2 ? totals($pdo, $caja2["id"]) : totals($pdo, null);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>CEVIMEP | Caja</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <!-- MISMO CSS QUE DASHBOARD -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=11">
</head>

<body>

<!-- ================= HEADER ================= -->
<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand">
      <span class="dot"></span> CEVIMEP
    </div>
    <div class="nav-right">
      <a class="btn-pill" href="/public/logout.php">Salir</a>
    </div>
  </div>
</header>

<div class="layout">

  <!-- ================= SIDEBAR (IGUAL A DASHBOARD) ================= -->
  <aside class="sidebar">
    <?php include __DIR__ . "/../partials/sidebar.php"; ?>
  </aside>

  <!-- ================= CONTENT ================= -->
  <main class="content">

    <section class="hero">
      <h1>Caja</h1>
      <p>Moca · Hoy: <?= $today ?></p>
    </section>

    <section class="card">
      <div class="card-header between">
        <div>
          <h2>Resumen</h2>
          <p class="muted">
            Caja activa ahora: <b><?= $current_caja ?></b> · Sesión activa ID: <b><?= $active_session_id ?></b><br>
            <small>* Las cajas se abren y cierran automáticamente por horario</small>
          </p>
        </div>

        <!-- BOTONES CORREGIDOS -->
        <div class="actions">
          <a class="btn-outline" href="/private/caja/desembolso.php">Desembolso</a>
          <a class="btn-outline" href="/private/estadistica/reporte_diario.php">Reporte diario</a>
          <a class="btn-outline" href="/private/estadistica/reporte_mensual.php">Reporte mensual</a>
        </div>
      </div>
    </section>

    <div class="grid-2">

      <!-- CAJA 1 -->
      <section class="card">
        <h3>Caja 1 (08:00 AM - 01:00 PM)</h3>
        <p class="muted"><?= $caja1 ? "Sesión activa" : "Sin sesión registrada hoy" ?></p>
        <table class="table">
          <tr><td>Efectivo</td><td>RD$ <?= money($t1["efectivo"]) ?></td></tr>
          <tr><td>Tarjeta</td><td>RD$ <?= money($t1["tarjeta"]) ?></td></tr>
          <tr><td>Transferencia</td><td>RD$ <?= money($t1["transferencia"]) ?></td></tr>
          <tr><td>Cobertura</td><td>RD$ <?= money($t1["cobertura"]) ?></td></tr>
          <tr><td>Desembolsos</td><td>- RD$ <?= money($t1["desembolso"]) ?></td></tr>
          <tr class="total"><td>Total ingresos</td><td>RD$ <?= money($t1["ing"]) ?></td></tr>
          <tr class="total"><td>Neto</td><td>RD$ <?= money($t1["neto"]) ?></td></tr>
        </table>
      </section>

      <!-- CAJA 2 -->
      <section class="card">
        <h3>Caja 2 (01:00 PM - 06:00 PM)</h3>
        <p class="muted"><?= $caja2 ? "Sesión activa" : "Sin sesión registrada hoy" ?></p>
        <table class="table">
          <tr><td>Efectivo</td><td>RD$ <?= money($t2["efectivo"]) ?></td></tr>
          <tr><td>Tarjeta</td><td>RD$ <?= money($t2["tarjeta"]) ?></td></tr>
          <tr><td>Transferencia</td><td>RD$ <?= money($t2["transferencia"]) ?></td></tr>
          <tr><td>Cobertura</td><td>RD$ <?= money($t2["cobertura"]) ?></td></tr>
          <tr><td>Desembolsos</td><td>- RD$ <?= money($t2["desembolso"]) ?></td></tr>
          <tr class="total"><td>Total ingresos</td><td>RD$ <?= money($t2["ing"]) ?></td></tr>
          <tr class="total"><td>Neto</td><td>RD$ <?= money($t2["neto"]) ?></td></tr>
        </table>
      </section>

    </div>

  </main>
</div>

<footer class="footer">
  © <?= $year ?> CEVIMEP. Todos los derechos reservados.
</footer>

</body>
</html>
