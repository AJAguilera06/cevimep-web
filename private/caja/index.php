<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$user = $_SESSION["user"];
$year = date("Y");

$branchId = (int)($user["branch_id"] ?? 0);
$userId   = (int)($user["id"] ?? 0);

date_default_timezone_set("America/Santo_Domingo");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtMoney($n){ return number_format((float)$n, 2, ".", ","); }

// Fecha real desde MySQL
try {
  $today = (string)$pdo->query("SELECT CURDATE()")->fetchColumn();
  if ($today === "") $today = date("Y-m-d");
} catch (Throwable $e) {
  $today = date("Y-m-d");
}

/*
|--------------------------------------------------------------------------
| Obtener sesiones del día desde caja_sesiones
|--------------------------------------------------------------------------
*/
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

/*
|--------------------------------------------------------------------------
| Sumar movimientos por caja_sesion_id
|--------------------------------------------------------------------------
*/
function getTotalsBySessionIds(PDO $pdo, array $sessionIds): array {

  $base = ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"cobertura"=>0,"desembolso"=>0];

  if (empty($sessionIds)) {
    return [$base, 0.0, 0.0];
  }

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

  $ing = (float)$r["efectivo"]
       + (float)$r["tarjeta"]
       + (float)$r["transferencia"]
       + (float)$r["cobertura"];

  // desembolso ya es negativo
  $net = $ing + (float)$r["desembolso"];

  return [$r, $ing, $net];
}

// Obtener sesiones
$idsCaja1 = getSessionIds($pdo, $branchId, $today, 1);
$idsCaja2 = getSessionIds($pdo, $branchId, $today, 2);

[$r1, $ing1, $net1] = getTotalsBySessionIds($pdo, $idsCaja1);
[$r2, $ing2, $net2] = getTotalsBySessionIds($pdo, $idsCaja2);

$sum = [
  1 => ["r"=>$r1, "ing"=>$ing1, "net"=>$net1],
  2 => ["r"=>$r2, "ing"=>$ing2, "net"=>$net2],
];

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
<link rel="stylesheet" href="/assets/css/styles.css?v=60">
<style>
.caja-container{max-width:1080px;margin:0 auto;padding:12px;}
.card-soft{background:#fff;border-radius:16px;padding:16px;box-shadow:0 10px 24px rgba(0,0,0,.07);}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px;}
.box-title{text-align:center;font-weight:900;margin-bottom:10px;}
table{width:100%;border-collapse:collapse;}
th,td{padding:10px;border-bottom:1px solid #eee;}
.t-strong{font-weight:900;}
</style>
</head>
<body>

<div class="caja-container">

<div class="card-soft" style="text-align:center;">
<h1>Caja</h1>
<?php if ($branchName): ?>
<div style="font-weight:700;opacity:.7;">Sucursal: <?= h($branchName) ?></div>
<?php endif; ?>
<br>
<a href="/private/caja/desembolso.php">Desembolso</a> |
<a href="/private/caja/reporte_diario.php">Reporte diario</a> |
<a href="/private/caja/reporte_mensual.php">Reporte mensual</a>
</div>

<div class="grid-2">

<div class="card-soft">
<h2 class="box-title">Caja 1 (7:00 AM - 12:59 PM)</h2>
<table>
<tr><td>Efectivo</td><td>RD$ <?= fmtMoney($sum[1]["r"]["efectivo"]) ?></td></tr>
<tr><td>Tarjeta</td><td>RD$ <?= fmtMoney($sum[1]["r"]["tarjeta"]) ?></td></tr>
<tr><td>Transferencia</td><td>RD$ <?= fmtMoney($sum[1]["r"]["transferencia"]) ?></td></tr>
<tr><td>Cobertura</td><td>RD$ <?= fmtMoney($sum[1]["r"]["cobertura"]) ?></td></tr>
<tr><td>Desembolsos</td><td>RD$ <?= fmtMoney($sum[1]["r"]["desembolso"]) ?></td></tr>
<tr><td class="t-strong">Total ingresos</td><td class="t-strong">RD$ <?= fmtMoney($sum[1]["ing"]) ?></td></tr>
<tr><td class="t-strong">Neto</td><td class="t-strong">RD$ <?= fmtMoney($sum[1]["net"]) ?></td></tr>
</table>
</div>

<div class="card-soft">
<h2 class="box-title">Caja 2 (1:00 PM - 11:59 PM)</h2>
<table>
<tr><td>Efectivo</td><td>RD$ <?= fmtMoney($sum[2]["r"]["efectivo"]) ?></td></tr>
<tr><td>Tarjeta</td><td>RD$ <?= fmtMoney($sum[2]["r"]["tarjeta"]) ?></td></tr>
<tr><td>Transferencia</td><td>RD$ <?= fmtMoney($sum[2]["r"]["transferencia"]) ?></td></tr>
<tr><td>Cobertura</td><td>RD$ <?= fmtMoney($sum[2]["r"]["cobertura"]) ?></td></tr>
<tr><td>Desembolsos</td><td>RD$ <?= fmtMoney($sum[2]["r"]["desembolso"]) ?></td></tr>
<tr><td class="t-strong">Total ingresos</td><td class="t-strong">RD$ <?= fmtMoney($sum[2]["ing"]) ?></td></tr>
<tr><td class="t-strong">Neto</td><td class="t-strong">RD$ <?= fmtMoney($sum[2]["net"]) ?></td></tr>
</table>
</div>

</div>
</div>

</body>
</html>
