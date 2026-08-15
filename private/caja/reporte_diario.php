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

    .card{
        background:rgba(255,255,255,.07);
        backdrop-filter:blur(14px);
        -webkit-backdrop-filter:blur(14px);
        border:1px solid rgba(255,255,255,.12);
        border-radius:16px;
    }

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
      color:var(--teal);
    }

    .reportTitle{
      text-align:center;
      margin:0;
      font-family:'Manrope';
      font-size:34px;
      font-weight:900;
      letter-spacing:.5px;
    }

    .btnLocal{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:10px 16px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.16);
      background:rgba(255,255,255,.08);
      color:#fff;
      font-weight:900;
      text-decoration:none;
      cursor:pointer;
      transition:all .15s ease;
    }

    .btnLocal:hover{
      background:rgba(255,255,255,.14);
    }

    .btnPrimary{
      border:1px solid var(--teal);
      background:var(--teal);
      color:#04211d;
    }

    .btnPrimary:hover{
      background:var(--teal-dark);
    }

    .pill{
      padding:8px 12px;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.16);
      background:rgba(255,255,255,.05);
    }

    .muted{
      color:#9FB6CC;
      font-weight:700;
    }

    .right{
      margin-left:auto;
    }

    .danger{
      background:rgba(220,38,38,.12);
      border:1px solid rgba(220,38,38,.3);
      color:#f87171;
      border-radius:14px;
      padding:10px 12px;
      font-weight:800;
    }

    .reportFrame{
      margin-top:14px;
      background:rgba(255,255,255,.03);
      border:1px solid rgba(255,255,255,.14);
      border-radius:24px;
      padding:16px;
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
      background:rgba(255,255,255,.05);
      border:1px solid rgba(255,255,255,.12);
      border-radius:20px;
      padding:14px;
    }

    .cajaHead{
      text-align:center;
      font-weight:900;
      color:var(--teal);
      margin:2px 0 6px;
      font-family:'Manrope';
    }

    .cajaSub{
      text-align:center;
      font-size:12px;
      color:#9FB6CC;
      font-weight:800;
      margin-bottom:8px;
    }

    table{
      width:100%;
      border-collapse:collapse;
      margin-top:10px;
      border:1px solid rgba(255,255,255,.1);
      border-radius:16px;
      overflow:hidden;
    }

    th, td{
      padding:9px 10px;
      border-bottom:1px solid rgba(255,255,255,.08);
      text-align:left;
      font-size:13px;
      color:#E7EEF6;
    }

    thead th{
      background:rgba(20,184,166,.12);
      color:var(--teal);
      font-weight:900;
    }

    .sectionTitle{
      margin:10px 0 0;
      text-align:center;
      color:var(--teal);
      font-weight:900;
    }

    input[type="date"]::-webkit-calendar-picker-indicator{
      filter: invert(1);
      opacity:.8;
    }

    select{
      background:rgba(255,255,255,.06)!important;
      color:#fff;
    }
    select option{ background:var(--navy-deep); color:#fff; }

    @media print {
      @page {
        margin: 10mm;
      }

      body {
        background: #fff !important;
        color:#000 !important;
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

    @media (max-width: 980px){
      .layout{ flex-direction:column; }
      .sidebar{ width:100%; }
      .content{ padding:24px 18px 40px; }
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
                <select name="branch_id" style="border:0;outline:none;font-weight:900;background:transparent;">
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
              <input type="date" name="date" value="<?= h($date) ?>" style="border:0;outline:none;font-weight:900;background:transparent;color:#fff;">
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