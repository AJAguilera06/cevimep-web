<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

date_default_timezone_set("America/Santo_Domingo");

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function money($n): string { return number_format((float)$n, 2, ".", ","); }

function isAdminRole(string $role): bool {
  $r = mb_strtolower(trim($role));
  return in_array($r, ['administrador', 'admin', 'superadmin'], true);
}

function colExists(PDO $conn, string $table, string $col): bool {
  try {
    $db = (string)$conn->query("SELECT DATABASE()")->fetchColumn();
    $st = $conn->prepare("
      SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?
    ");
    $st->execute([$db, $table, $col]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

function tableExists(PDO $conn, string $table): bool {
  try {
    $db = (string)$conn->query("SELECT DATABASE()")->fetchColumn();
    $st = $conn->prepare("
      SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
    ");
    $st->execute([$db, $table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

/* Detecta la columna de nombre disponible en una tabla (paciente / usuario) */
function pickNameCol(PDO $conn, string $table, array $candidates): ?string {
  foreach ($candidates as $c) {
    if (colExists($conn, $table, $c)) return $c;
  }
  return null;
}

$user = $_SESSION["user"] ?? [];
$role = (string)($user["role"] ?? "");
$isAdmin = isAdminRole($role);
$userBranchId = (int)($user["branch_id"] ?? 0);
$branchId = $isAdmin ? (int)($_SESSION['selected_branch_id'] ?? $userBranchId) : $userBranchId;

$year = (int)date("Y");
$today = date("Y-m-d");
$date = $_GET["date"] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = $today;

/* Sucursales (solo admin puede cambiar) */
$branchesList = [];
try {
  if ($isAdmin) {
    $stBranches = $conn->query("SELECT id, name FROM branches ORDER BY name ASC");
    $branchesList = $stBranches->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $requestedBranchId = (int)($_GET["branch_id"] ?? 0);
    if ($requestedBranchId > 0) {
      $branchId = $requestedBranchId;
    } elseif ($branchId <= 0 && !empty($branchesList)) {
      $branchId = (int)$branchesList[0]["id"];
    }
  }
} catch (Throwable $e) {}

if ($branchId <= 0) {
  http_response_code(400);
  die("Sucursal inválida. Selecciona una sucursal primero.");
}

/* Nombre sucursal */
$branchName = "Sucursal #" . $branchId;
try {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id = ? LIMIT 1");
  $stB->execute([$branchId]);
  $bn = $stB->fetchColumn();
  if ($bn) $branchName = (string)$bn;
} catch (Throwable $e) {}

/* Detectar columnas de nombre en patients y users (esquema flexible) */
$patientNameCol = pickNameCol($conn, "patients", ["full_name", "name", "nombre", "nombre_completo"]);
$patientHasFirstLast = !$patientNameCol
  && colExists($conn, "patients", "first_name")
  && colExists($conn, "patients", "last_name");
$userNameCol = pickNameCol($conn, "users", ["full_name", "name", "nombre", "username"]);

$hasSubtotal = colExists($conn, "invoices", "subtotal");

$error = "";
$rows = [];
$totalAnulado = 0.0;
$totalCount = 0;

try {
  $selPatient = $patientNameCol
    ? "p.`$patientNameCol` AS patient_name"
    : ($patientHasFirstLast
        ? "TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))) AS patient_name"
        : "NULL AS patient_name");
  $selUser    = $userNameCol ? "u.`$userNameCol` AS cancelled_by_name" : "NULL AS cancelled_by_name";

  $joinPatient = tableExists($conn, "patients")
    ? "LEFT JOIN patients p ON p.id = i.patient_id"
    : "";
  $joinUser = tableExists($conn, "users")
    ? "LEFT JOIN users u ON u.id = i.cancelled_by"
    : "";

  $sql = "
    SELECT
      i.id,
      i.invoice_code,
      i.total" . ($hasSubtotal ? ", i.subtotal" : "") . ",
      i.cancel_reason,
      i.cancelled_at,
      i.cancelled_by,
      $selPatient,
      $selUser
    FROM invoices i
    $joinPatient
    $joinUser
    WHERE i.branch_id = ?
      AND UPPER(i.status) IN ('CANCELLED','ANULADA')
      AND DATE(i.cancelled_at) = ?
    ORDER BY i.cancelled_at DESC
  ";

  $st = $conn->prepare($sql);
  $st->execute([$branchId, $date]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($rows as $r) {
    $totalAnulado += (float)($r["total"] ?? 0);
    $totalCount++;
  }
} catch (Throwable $e) {
  $error = "Error interno generando el reporte: " . $e->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Facturas Canceladas</title>

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
        padding:22px;
    }

    .reportTitle{
      text-align:center;
      margin:0;
      font-family:'Manrope';
      font-size:34px;
      font-weight:900;
      letter-spacing:.5px;
    }

    .reportTop{
      display:flex;
      align-items:flex-start;
      gap:14px;
      flex-wrap:wrap;
      margin-top:8px;
      justify-content:space-between;
    }
    .reportTopLeft{
      display:flex;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
    }
    .branchTag{ font-weight:900; color:var(--teal); }

    .pill{
      padding:8px 12px;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.16);
      background:rgba(255,255,255,.05);
    }
    .pill select, .pill input{
      border:0; outline:none; font-weight:900; background:transparent; color:#fff;
    }
    .pill select option{ background:var(--navy-deep); color:#fff; }
    .muted{ color:#9FB6CC; font-weight:700; display:block; font-size:12px; margin-bottom:4px; }

    .btnLocal{
      display:inline-flex; align-items:center; justify-content:center;
      padding:10px 16px; border-radius:999px;
      border:1px solid rgba(255,255,255,.16);
      background:rgba(255,255,255,.08);
      color:#fff; font-weight:900; text-decoration:none; cursor:pointer;
      transition:all .15s ease;
    }
    .btnLocal:hover{ background:rgba(255,255,255,.14); }
    .btnPrimary{ border:1px solid var(--teal); background:var(--teal); color:#04211d; }
    .btnPrimary:hover{ background:var(--teal-dark); }

    .danger{
      background:rgba(220,38,38,.12);
      border:1px solid rgba(220,38,38,.3);
      color:#f87171;
      border-radius:14px;
      padding:10px 12px;
      font-weight:800;
      margin-top:12px;
    }

    .summary{
      margin-top:16px;
      display:grid;
      grid-template-columns:repeat(2,1fr);
      gap:14px;
    }
    .sum-card{
      background:rgba(255,255,255,.05);
      border:1px solid rgba(255,255,255,.12);
      border-radius:16px;
      padding:14px 16px;
      text-align:center;
    }
    .sum-card small{ display:block; color:#9FB6CC; font-weight:800; font-size:12px; margin-bottom:6px; }
    .sum-card strong{ font-size:22px; font-weight:900; }
    .sum-card.danger-num strong{ color:#f87171; }
    .sum-card.count-num strong{ color:var(--teal); }

    .table-wrap{
      margin-top:18px;
      overflow:auto;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.1);
    }
    table{ width:100%; border-collapse:collapse; }
    th, td{ padding:10px 12px; border-bottom:1px solid rgba(255,255,255,.08); text-align:left; font-size:13px; color:#E7EEF6; }
    thead th{ background:rgba(20,184,166,.12); color:var(--teal); font-weight:900; white-space:nowrap; }
    tbody tr:last-child td{ border-bottom:none; }
    tbody tr:hover{ background:rgba(255,255,255,.03); }
    .right{ text-align:right; }
    .money{ font-weight:900; white-space:nowrap; }
    .reason-cell{ max-width:280px; white-space:normal; color:#B9CBDD; }
    .empty{ text-align:center; padding:22px; color:#9FB6CC; font-weight:800; }

    input[type="date"]::-webkit-calendar-picker-indicator{ filter: invert(1); opacity:.8; }

    @media print {
      *{ -webkit-print-color-adjust:exact!important; print-color-adjust:exact!important; }
      @page{ margin: 10mm; }

      body{ background:#fff !important; color:#111 !important; }
      body *{ visibility:hidden !important; }
      #printArea, #printArea *{ visibility:visible !important; }
      #printArea{ position:absolute !important; left:0 !important; top:0 !important; width:100% !important; margin:0 !important; background:#fff !important; }
      #printArea.card{ box-shadow:none !important; border:none !important; backdrop-filter:none !important; -webkit-backdrop-filter:none !important; }

      .sidebar, .navbar, footer{ display:none !important; }
      .layout, .content{ padding:0 !important; margin:0 !important; }
      .btnLocal, .filterForm{ display:none !important; }

      .reportTitle{ color:#0B1F33 !important; }
      .branchTag{ color:#0D9488 !important; }
      .muted{ color:#444 !important; }

      .sum-card{ background:#F8FAFC !important; border:1px solid #ddd !important; }
      .sum-card small{ color:#555 !important; }
      .sum-card strong{ color:#111 !important; }

      .table-wrap{ border:1px solid #ccc !important; }
      table{ border-collapse:collapse !important; }
      th, td{ color:#111 !important; border-bottom:1px solid #ddd !important; }
      thead th{ background:#E6F7F5 !important; color:#0D9488 !important; }
      .reason-cell{ color:#333 !important; }
    }

    @media(max-width:980px){
      .layout{ flex-direction:column; }
      .sidebar{ width:100%; }
      .content{ padding:24px 18px 40px; }
      .summary{ grid-template-columns:1fr; }
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
    <div class="brand"><span class="dot"></span>CEVIMEP</div>
    <div class="nav-right"><a class="btn-pill" href="/logout.php">Salir</a></div>
  </div>
</header>

<div class="layout">

  <aside class="sidebar">
    <div class="menu-title">Menú</div>
    <nav class="menu">
      <a href="/private/dashboard.php">🏠 Panel</a>
      <a href="/private/patients/index.php">👥 Pacientes</a>
      <a href="javascript:void(0)" style="opacity:.45; cursor:not-allowed;">🗓️ Citas</a>
      <a class="active" href="/private/facturacion/index.php">🧾 Facturación</a>
      <a href="/private/caja/index.php">💵 Caja</a>
      <a href="/private/inventario/index.php">📦 Inventario</a>
      <a href="/private/estadistica/index.php">📊 Estadísticas</a>
    </nav>
  </aside>

  <main class="content">

    <section id="printArea" class="card">
      <h1 class="reportTitle">Facturas Canceladas</h1>

      <div class="reportTop">
        <div class="reportTopLeft">
          <div class="branchTag"><?= h($branchName) ?>:</div>

          <form class="reportTopLeft filterForm" method="GET" action="/private/facturacion/facturas_canceladas.php">
            <?php if ($isAdmin && !empty($branchesList)): ?>
              <div class="pill">
                <label class="muted">Sucursal</label>
                <select name="branch_id">
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
              <label class="muted">Fecha</label>
              <input type="date" name="date" value="<?= h($date) ?>">
            </div>

            <button class="btnLocal" type="submit">Ver</button>
          </form>
        </div>

        <div>
          <a class="btnLocal btnPrimary" href="javascript:void(0)" onclick="window.print()">Imprimir</a>
        </div>
      </div>

      <?php if ($error): ?>
        <div class="danger"><?= h($error) ?></div>
      <?php endif; ?>

      <div class="summary">
        <div class="sum-card count-num">
          <small>Facturas anuladas</small>
          <strong><?= (int)$totalCount ?></strong>
        </div>
        <div class="sum-card danger-num">
          <small>Total anulado</small>
          <strong>RD$ <?= money($totalAnulado) ?></strong>
        </div>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Factura</th>
              <th>Paciente</th>
              <th class="right">Monto</th>
              <th>Motivo</th>
              <th>Anulada por</th>
              <th>Fecha / Hora</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="6" class="empty">No hay facturas anuladas en esta fecha.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td style="font-weight:900;"><?= h($r["invoice_code"] ?? ("#" . $r["id"])) ?></td>
                  <td><?= h($r["patient_name"] ?? "—") ?></td>
                  <td class="right money">RD$ <?= money($r["total"] ?? 0) ?></td>
                  <td class="reason-cell"><?= h($r["cancel_reason"] ?? "—") ?></td>
                  <td><?= h($r["cancelled_by_name"] ?? ("Usuario #" . (int)($r["cancelled_by"] ?? 0))) ?></td>
                  <td><?= h($r["cancelled_at"] ?? "—") ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    </section>

  </main>
</div>

<footer class="footer">
  © <?= $year ?> CEVIMEP. Todos los derechos reservados.
</footer>

</body>
</html>