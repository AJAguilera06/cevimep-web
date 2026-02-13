<?php
require_once __DIR__ . "/../../config/db.php";
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION["user"])) {
  header("Location: /login.php");
  exit;
}

$user = $_SESSION["user"];
$branchId = (int)($user["branch_id"] ?? 0);

if ($branchId <= 0) {
  die("Sucursal no definida.");
}

/* ======================================================
   FECHA REAL DESDE MYSQL (evita problema UTC)
====================================================== */
try {
  $today = (string)$pdo->query("SELECT CURDATE()")->fetchColumn();
  if ($today === "") $today = date("Y-m-d");
} catch (Throwable $e) {
  $today = date("Y-m-d");
}

/* ======================================================
   FUNCION: OBTENER SESIONES POR CAJA
====================================================== */
function getSessionIds(PDO $pdo, int $branchId, string $date, int $cajaNum): array {
  try {
    $st = $pdo->prepare("
      SELECT id
      FROM cash_sessions
      WHERE branch_id = ?
        AND caja_num  = ?
        AND (date_open = ? OR DATE(opened_at) = ?)
      ORDER BY id ASC
    ");
    $st->execute([$branchId, $cajaNum, $date, $date]);

    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $ids = [];
    foreach ($rows as $r) {
      $id = (int)($r['id'] ?? 0);
      if ($id > 0) $ids[] = $id;
    }
    return $ids;
  } catch (Throwable $e) {
    return [];
  }
}

/* ======================================================
   FUNCION: SUMAR MOVIMIENTOS
====================================================== */
function sumMov(PDO $pdo, array $sessionIds, string $type, string $metodo = null): float {
  if (empty($sessionIds)) return 0.0;

  $in = implode(",", array_fill(0, count($sessionIds), "?"));
  $sql = "SELECT SUM(amount) FROM cash_movements WHERE session_id IN ($in) AND type = ?";
  $params = $sessionIds;
  $params[] = $type;

  if ($metodo !== null) {
    $sql .= " AND metodo_pago = ?";
    $params[] = $metodo;
  }

  $st = $pdo->prepare($sql);
  $st->execute($params);

  return (float)$st->fetchColumn();
}

/* ======================================================
   OBTENER SESIONES DE HOY
====================================================== */
$caja1Sessions = getSessionIds($pdo, $branchId, $today, 1);
$caja2Sessions = getSessionIds($pdo, $branchId, $today, 2);

/* ======================================================
   CAJA 1
====================================================== */
$c1_efectivo = sumMov($pdo, $caja1Sessions, "ingreso", "efectivo");
$c1_tarjeta  = sumMov($pdo, $caja1Sessions, "ingreso", "tarjeta");
$c1_transf   = sumMov($pdo, $caja1Sessions, "ingreso", "transferencia");
$c1_cob      = sumMov($pdo, $caja1Sessions, "ingreso", "cobertura");
$c1_des      = sumMov($pdo, $caja1Sessions, "egreso");

$c1_total = $c1_efectivo + $c1_tarjeta + $c1_transf + $c1_cob;
$c1_neto  = $c1_total - $c1_des;

/* ======================================================
   CAJA 2
====================================================== */
$c2_efectivo = sumMov($pdo, $caja2Sessions, "ingreso", "efectivo");
$c2_tarjeta  = sumMov($pdo, $caja2Sessions, "ingreso", "tarjeta");
$c2_transf   = sumMov($pdo, $caja2Sessions, "ingreso", "transferencia");
$c2_cob      = sumMov($pdo, $caja2Sessions, "ingreso", "cobertura");
$c2_des      = sumMov($pdo, $caja2Sessions, "egreso");

$c2_total = $c2_efectivo + $c2_tarjeta + $c2_transf + $c2_cob;
$c2_neto  = $c2_total - $c2_des;

/* ======================================================
   OBTENER NOMBRE SUCURSAL
====================================================== */
$st = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
$st->execute([$branchId]);
$sucursal = $st->fetchColumn() ?: "Sucursal";
?>

<?php include __DIR__ . "/../_guard.php"; ?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Caja</title>
<link rel="stylesheet" href="/assets/style.css">
</head>

<body>

<div class="main-content">
  <div class="card-header">
    <h1>Caja</h1>
    <p>Sucursal: <?= htmlspecialchars($sucursal) ?></p>
  </div>

  <div class="cards-container">

    <!-- CAJA 1 -->
    <div class="card">
      <h3>Caja 1 (08:00 AM - 01:00 PM)</h3>
      <table>
        <tr><td>Efectivo</td><td>RD$ <?= number_format($c1_efectivo,2) ?></td></tr>
        <tr><td>Tarjeta</td><td>RD$ <?= number_format($c1_tarjeta,2) ?></td></tr>
        <tr><td>Transferencia</td><td>RD$ <?= number_format($c1_transf,2) ?></td></tr>
        <tr><td>Cobertura</td><td>RD$ <?= number_format($c1_cob,2) ?></td></tr>
        <tr><td>Desembolsos</td><td>- RD$ <?= number_format($c1_des,2) ?></td></tr>
        <tr><th>Total ingresos</th><th>RD$ <?= number_format($c1_total,2) ?></th></tr>
        <tr><th>Neto</th><th>RD$ <?= number_format($c1_neto,2) ?></th></tr>
      </table>
    </div>

    <!-- CAJA 2 -->
    <div class="card">
      <h3>Caja 2 (01:00 PM - 06:00 PM)</h3>
      <table>
        <tr><td>Efectivo</td><td>RD$ <?= number_format($c2_efectivo,2) ?></td></tr>
        <tr><td>Tarjeta</td><td>RD$ <?= number_format($c2_tarjeta,2) ?></td></tr>
        <tr><td>Transferencia</td><td>RD$ <?= number_format($c2_transf,2) ?></td></tr>
        <tr><td>Cobertura</td><td>RD$ <?= number_format($c2_cob,2) ?></td></tr>
        <tr><td>Desembolsos</td><td>- RD$ <?= number_format($c2_des,2) ?></td></tr>
        <tr><th>Total ingresos</th><th>RD$ <?= number_format($c2_total,2) ?></th></tr>
        <tr><th>Neto</th><th>RD$ <?= number_format($c2_neto,2) ?></th></tr>
      </table>
    </div>

  </div>
</div>

</body>
</html>
