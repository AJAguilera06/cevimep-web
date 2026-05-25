<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (empty($_SESSION["user"])) {
  header("Location: /login.php");
  exit;
}

require_once __DIR__ . "/../config/db.php";

date_default_timezone_set("America/Santo_Domingo");

$user = $_SESSION["user"];
$year = (int)date("Y");

$isAdmin  = (($user["role"] ?? "") === "admin");
$userBranchId = (int)($user["branch_id"] ?? 0);
$branchId = $userBranchId;

if (!$isAdmin && $branchId <= 0) {
  header("Location: /logout.php");
  exit;
}

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

function money($n): string {
  return number_format((float)$n, 2, ".", ",");
}

$today = date("Y-m-d");
$date  = $_GET["date"] ?? $today;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  $date = $today;
}

/* Sucursales disponibles */
$branchesList = [];

try {
  if ($isAdmin) {
    $stBranches = $pdo->query("SELECT id, name FROM branches ORDER BY name ASC");
    $branchesList = $stBranches->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $requestedBranchId = (int)($_GET["branch_id"] ?? 0);

    if ($requestedBranchId > 0) {
      $branchId = $requestedBranchId;
    } elseif ($branchId <= 0 && !empty($branchesList)) {
      $branchId = (int)$branchesList[0]["id"];
    }
  }
} catch (Throwable $e) {
  // No detener la página si falla la lista de sucursales
}

/* Nombre sucursal */
$branchName = $user["branch_name"] ?? $user["branch"] ?? ("Sucursal #" . $branchId);

try {
  $stB = $pdo->prepare("SELECT name FROM branches WHERE id = ? LIMIT 1");
  $stB->execute([$branchId]);
  $bn = $stB->fetchColumn();

  if ($bn) {
    $branchName = (string)$bn;
  }
} catch (Throwable $e) {
  // No detener la página si falla el nombre de sucursal
}

/* Buscar sesiones de caja */
function getSessions(PDO $pdo, int $branchId, int $cajaNum, string $date): array {
  /*
    Trae TODAS las sesiones de esa caja, sucursal y fecha.
    Si solo se toma la última sesión, movimientos/desembolsos guardados
    en otra sesión del mismo día no se reflejan en el reporte.
  */
  $st = $pdo->prepare("
    SELECT *
    FROM caja_sesiones
    WHERE branch_id = ?
      AND fecha = ?
      AND caja_num = ?
    ORDER BY id ASC
  ");

  $st->execute([$branchId, $date, $cajaNum]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sessionIds(array $sessions): array {
  return array_values(array_filter(array_map(
    fn($s) => (int)($s["id"] ?? 0),
    $sessions
  )));
}
/* Totales de movimientos */
function getTotals(PDO $pdo, array $sessionIds): array {
  $empty = [
    "efectivo" => 0,
    "tarjeta" => 0,
    "transferencia" => 0,
    "cobertura" => 0,
    "desembolso" => 0,
    "ing" => 0,
    "net" => 0
  ];

  if (empty($sessionIds)) {
    return $empty;
  }

  $ph = implode(",", array_fill(0, count($sessionIds), "?"));

  $st = $pdo->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN type = 'ingreso' AND metodo_pago = 'efectivo' THEN amount END), 0) AS efectivo,
      COALESCE(SUM(CASE WHEN type = 'ingreso' AND metodo_pago = 'tarjeta' THEN amount END), 0) AS tarjeta,
      COALESCE(SUM(CASE WHEN type = 'ingreso' AND metodo_pago = 'transferencia' THEN amount END), 0) AS transferencia,
      COALESCE(SUM(CASE WHEN type = 'ingreso' AND metodo_pago = 'cobertura' THEN amount END), 0) AS cobertura,
      COALESCE(SUM(CASE WHEN type = 'desembolso' THEN ABS(amount) END), 0) AS desembolso
    FROM cash_movements
    WHERE caja_sesion_id IN ($ph)
  ");

  $st->execute($sessionIds);

  $r = $st->fetch(PDO::FETCH_ASSOC) ?: $empty;

  $ing = (float)$r["efectivo"]
       + (float)$r["tarjeta"]
       + (float)$r["transferencia"]
       + (float)$r["cobertura"];

  $desembolso = (float)$r["desembolso"];
  $net = $ing - $desembolso;

  return [
    "efectivo"      => (float)$r["efectivo"],
    "tarjeta"       => (float)$r["tarjeta"],
    "transferencia" => (float)$r["transferencia"],
    "cobertura"     => (float)$r["cobertura"],
    "desembolso"    => $desembolso,
    "ing"           => $ing,
    "net"           => $net
  ];
}

/* Movimientos */
function getMovements(PDO $pdo, array $sessionIds): array {
  if (empty($sessionIds)) {
    return [];
  }

  $ph = implode(",", array_fill(0, count($sessionIds), "?"));

  $st = $pdo->prepare("
    SELECT
      id,
      created_at,
      type,
      metodo_pago,
      motivo AS concept,
      amount
    FROM cash_movements
    WHERE caja_sesion_id IN ($ph)
    ORDER BY created_at ASC, id ASC
  ");

  $st->execute($sessionIds);

  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$s1 = ["08:00:00", "13:00:00"];
$s2 = ["13:00:00", "18:00:00"];

$error = "";

$caja1 = null;
$caja2 = null;

$t1 = [
  "efectivo" => 0,
  "tarjeta" => 0,
  "transferencia" => 0,
  "cobertura" => 0,
  "desembolso" => 0,
  "ing" => 0,
  "net" => 0
];

$t2 = $t1;

$m1 = [];
$m2 = [];

try {
  $caja1 = getSessions($pdo, $branchId, 1, $date);
  $caja2 = getSessions($pdo, $branchId, 2, $date);

  $idsCaja1 = sessionIds($caja1);
  $idsCaja2 = sessionIds($caja2);

  $t1 = getTotals($pdo, $idsCaja1);
  $m1 = getMovements($pdo, $idsCaja1);

  $t2 = getTotals($pdo, $idsCaja2);
  $m2 = getMovements($pdo, $idsCaja2);

} catch (Throwable $e) {
  $error = "Error interno generando el reporte: " . $e->getMessage();
}


$tTotal = [
  "efectivo"      => (float)$t1["efectivo"] + (float)$t2["efectivo"],
  "tarjeta"       => (float)$t1["tarjeta"] + (float)$t2["tarjeta"],
  "transferencia" => (float)$t1["transferencia"] + (float)$t2["transferencia"],
  "cobertura"     => (float)$t1["cobertura"] + (float)$t2["cobertura"],
  "desembolso"    => (float)$t1["desembolso"] + (float)$t2["desembolso"],
  "ing"           => (float)$t1["ing"] + (float)$t2["ing"],
  "net"           => (float)$t1["net"] + (float)$t2["net"]
];

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Reporte Diario Caja</title>

  <link rel="stylesheet" href="/assets/css/styles.css?v=50">

  <style>
    .reportTop{
      display:flex;
      align-items:flex-start;
      gap:14px;
      flex-wrap:wrap;
      margin-top:8px;
    }

    .reportTopLeft{
      display:flex;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
    }

    .branchTag{
      font-weight:900;
      color:#0b3b9a;
    }

    .reportTitle{
      text-align:center;
      margin:0;
      font-size:34px;
      letter-spacing:.5px;
    }

    .btnLocal{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:10px 16px;
      border-radius:999px;
      border:1px solid #dbeafe;
      background:#fff;
      color:#052a7a;
      font-weight:900;
      text-decoration:none;
      cursor:pointer;
      transition:all .15s ease;
    }

    .btnLocal:hover{
      box-shadow:0 10px 25px rgba(2,6,23,.10);
      transform:translateY(-1px);
    }

    .btnPrimary{
      border:none;
      background:linear-gradient(180deg,#2f6dff,#0b3b9a);
      color:#fff;
      box-shadow:0 18px 40px rgba(11,59,154,.20);
    }

    .btnPrimary:hover{
      filter:brightness(1.03);
    }

    .pill{
      padding:8px 12px;
      border-radius:14px;
      border:1px solid #e6eef7;
      background:#fff;
    }

    .muted{
      color:#6b7280;
      font-weight:700;
    }

    .right{
      margin-left:auto;
    }

    .danger{
      background:#fff5f5;
      border:1px solid #fed7d7;
      color:#b91c1c;
      border-radius:14px;
      padding:10px 12px;
      font-weight:800;
    }

    .reportFrame{
      margin-top:14px;
      background:#fff;
      border:3px solid rgba(11,59,154,.55);
      border-radius:24px;
      padding:16px;
      box-shadow:0 14px 40px rgba(2,6,23,.10);
    }

    .gridBox{
      display:grid;
      grid-template-columns:repeat(3, minmax(0,1fr));
      gap:14px;
    }

    @media(max-width:1100px){
      .gridBox{
        grid-template-columns:1fr;
      }
    }

    .cajaCard{
      background:#fff;
      border:1px solid #e6eef7;
      border-radius:20px;
      padding:14px;
    }

    .cajaHead{
      text-align:center;
      font-weight:900;
      color:#0b3b9a;
      margin:2px 0 6px;
    }

    .cajaSub{
      text-align:center;
      font-size:12px;
      color:#6b7280;
      font-weight:800;
      margin-bottom:8px;
    }

    table{
      width:100%;
      border-collapse:collapse;
      margin-top:10px;
      border:1px solid #e6eef7;
      border-radius:16px;
      overflow:hidden;
    }

    th, td{
      padding:9px 10px;
      border-bottom:1px solid #eef2f7;
      text-align:left;
      font-size:13px;
    }

    thead th{
      background:#f7fbff;
      color:#0b3b9a;
      font-weight:900;
    }

    .sectionTitle{
      margin:10px 0 0;
      text-align:center;
      color:#0b3b9a;
      font-weight:900;
    }

    @media print {
      @page {
        margin: 10mm;
      }

      body {
        background: #fff !important;
      }

      body * {
        visibility: hidden !important;
      }

      #printArea,
      #printArea * {
        visibility: visible !important;
      }

      #printArea {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        margin: 0 !important;
      }

      #printArea.card {
        box-shadow: none !important;
        border: none !important;
      }

      .sidebar,
      .navbar,
      footer {
        display: none !important;
      }

      .layout,
      .content {
        padding: 0 !important;
        margin: 0 !important;
      }

      .btnLocal,
      .right,
      .pill {
        display: none !important;
      }
    }
  </style>
</head>

<body>

<header class="navbar">
  <div class="inner">
    <div></div>

    <div class="brand">
      <span class="dot"></span>
      CEVIMEP
    </div>

    <div class="nav-right">
      <a class="btn-pill" href="/logout.php">Salir</a>
    </div>
  </div>
</header>

<div class="layout">

  <aside class="sidebar">
    <div class="menu-title">Menú</div>

    <nav class="menu">
      <a href="/private/dashboard.php">🏠 Panel</a>
      <a href="/private/patients/index.php">👥 Pacientes</a>
      <a href="javascript:void(0)" style="opacity:.45; cursor:not-allowed;">🗓️ Citas</a>
      <a href="/private/facturacion/index.php">🧾 Facturación</a>
      <a class="active" href="/private/caja/index.php">💵 Caja</a>
      <a href="/private/inventario/index.php">📦 Inventario</a>
      <a href="/private/estadistica/index.php">📊 Estadísticas</a>
    </nav>
  </aside>

  <main class="content">

    <section id="printArea" class="card" style="padding:22px;">
      <h1 class="reportTitle">Reporte Diario</h1>

      <div class="reportTop">
        <div class="reportTopLeft">
          <div class="branchTag"><?= h($branchName) ?>:</div>

          <form class="reportTopLeft" method="GET" action="/private/caja/reporte_diario.php">
            <?php if ($isAdmin && !empty($branchesList)): ?>
              <div class="pill">
                <label class="muted" style="display:block;font-size:12px;margin-bottom:4px;">Sucursal</label>
                <select name="branch_id" style="border:0;outline:none;font-weight:900;background:#fff;">
                  <?php foreach ($branchesList as $br): ?>
                    <option value="<?= (int)$br["id"] ?>" <?= ((int)$br["id"] === (int)$branchId) ? "selected" : "" ?>>
                      <?= h($br["name"]) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <input type="hidden" name="branch_id" value="<?= (int)$branchId ?>">
            <?php endif; ?>

            <div class="pill">
              <label class="muted" style="display:block;font-size:12px;margin-bottom:4px;">Fecha</label>
              <input type="date" name="date" value="<?= h($date) ?>" style="border:0;outline:none;font-weight:900;">
            </div>

            <button class="btnLocal" type="submit">Ver</button>
          </form>
        </div>

        <div class="right">
          <a class="btnLocal btnPrimary" href="javascript:void(0)" onclick="window.print()">Imprimir</a>
        </div>
      </div>

      <?php if ($error): ?>
        <div style="margin-top:12px;" class="danger"><?= h($error) ?></div>
      <?php endif; ?>

      <div class="reportFrame">
        <div class="gridBox">

          <section class="cajaCard">
            <div class="cajaHead">Caja 1 (08:00 AM - 01:00 PM)</div>
            <div class="cajaSub">
              <?= !empty($caja1) ? count($caja1) . " sesión(es)" : "Sin sesión registrada" ?>
            </div>

            <table>
              <thead>
                <tr>
                  <th>Concepto</th>
                  <th>Monto</th>
                </tr>
              </thead>

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

            </section>

          <section class="cajaCard">
            <div class="cajaHead">Caja 2 (01:00 PM - 06:00 PM)</div>
            <div class="cajaSub">
              <?= !empty($caja2) ? count($caja2) . " sesión(es)" : "Sin sesión registrada" ?>
            </div>

            <table>
              <thead>
                <tr>
                  <th>Concepto</th>
                  <th>Monto</th>
                </tr>
              </thead>

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

            </section>

          <section class="cajaCard">
            <div class="cajaHead">TOTAL GENERAL</div>
            <div class="cajaSub">Caja 1 + Caja 2</div>

            <table>
              <thead>
                <tr>
                  <th>Concepto</th>
                  <th>Total</th>
                </tr>
              </thead>

              <tbody>
                <tr><td>Efectivo</td><td>RD$ <?= money($tTotal["efectivo"]) ?></td></tr>
                <tr><td>Tarjeta</td><td>RD$ <?= money($tTotal["tarjeta"]) ?></td></tr>
                <tr><td>Transferencia</td><td>RD$ <?= money($tTotal["transferencia"]) ?></td></tr>
                <tr><td>Cobertura</td><td>RD$ <?= money($tTotal["cobertura"]) ?></td></tr>
                <tr><td>Desembolsos</td><td>- RD$ <?= money($tTotal["desembolso"]) ?></td></tr>
                <tr><td style="font-weight:900;">Total ingresos</td><td style="font-weight:900;">RD$ <?= money($tTotal["ing"]) ?></td></tr>
                <tr><td style="font-weight:900;">Neto</td><td style="font-weight:900;">RD$ <?= money($tTotal["net"]) ?></td></tr>
              </tbody>
            </table>

          </section>

        </div>
      </div>
    </section>

  </main>
</div>

<footer class="footer">
  <div class="footer-inner">
    © <?= $year ?> CEVIMEP. Todos los derechos reservados.
  </div>
</footer>

</body>
</html>