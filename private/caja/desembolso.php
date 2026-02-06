<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/caja_lib.php";

if (!isset($_SESSION["user"])) { header("Location: /login.php"); exit; }

$user = $_SESSION["user"];
$year = date("Y");

$isAdmin  = (($user["role"] ?? "") === "admin");
$branchId = (int)($user["branch_id"] ?? 0);
$userId   = (int)($user["id"] ?? 0);

if (!$isAdmin && $branchId <= 0) { header("Location: /logout.php"); exit; }

date_default_timezone_set("America/Santo_Domingo");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function fmtMoney($n){ return number_format((float)$n, 2, ".", ","); }

/** Column/table detection (evita errores si cambian nombres en Railway) */
function colExists(PDO $pdo, string $table, string $col): bool {
  try {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->execute([$db, $table, $col]);
    return ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) {
    return false;
  }
}
function tableExists(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

$success = "";
$error   = "";

// Flash mensajes (post/redirect/get)
if (!empty($_SESSION["flash_success"])) {
  $success = (string)$_SESSION["flash_success"];
  unset($_SESSION["flash_success"]);
}
if (!empty($_SESSION["flash_error"])) {
  $error = (string)$_SESSION["flash_error"];
  unset($_SESSION["flash_error"]);
}

// ===============================
// MODO ACUSE (mismo desembolso.php)
// ===============================
if (isset($_GET['acuse'])) {
  $movId = (int)$_GET['acuse'];

  // Detectar columna de fecha
  $createdColAcuse = colExists($pdo, "cash_movements", "created_at") ? "created_at"
                   : (colExists($pdo, "cash_movements", "created_on") ? "created_on" : null);

  $cols = "id, session_id, type, motivo, metodo_pago, amount, created_by";
  if (colExists($pdo, "cash_movements", "representante")) { $cols .= ", representante"; }
  if ($createdColAcuse) { $cols .= ", {$createdColAcuse} AS created_time"; }

  $st = $pdo->prepare("SELECT {$cols} FROM cash_movements WHERE id=? AND type='desembolso' LIMIT 1");
  $st->execute([$movId]);
  $r = $st->fetch(PDO::FETCH_ASSOC);

  if (!$r) { http_response_code(404); echo "Acuse no encontrado."; exit; }

  $monto = abs((float)($r['amount'] ?? 0));
  $fecha = $r['created_time'] ?? '';
  ?>
  <!doctype html>
  <html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acuse de Desembolso #<?php echo (int)$r['id']; ?></title>
    <style>
      body{font-family:Arial,sans-serif;margin:0;padding:18px;background:#f7f9fc;}
      .card{max-width:720px;margin:0 auto;background:#fff;border-radius:16px;padding:18px 18px 14px;
            box-shadow:0 10px 30px rgba(0,0,0,.08);}
      .top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;}
      .title{font-size:20px;font-weight:800;color:#0b1b34;}
      .sub{color:#556; font-size:13px;}
      .pill{background:#eaf1ff;color:#0b4bd7;border-radius:999px;padding:6px 10px;font-weight:700;font-size:12px;}
      .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;}
      .box{border:1px solid #e6eefc;border-radius:12px;padding:12px;}
      .lbl{font-size:12px;color:#567;font-weight:800;letter-spacing:.4px;text-transform:uppercase;margin-bottom:6px;}
      .val{font-size:16px;font-weight:800;color:#0b1b34;}
      .btns{display:flex;gap:10px;margin-top:14px;}
      .btn{border:0;border-radius:12px;padding:10px 14px;font-weight:800;cursor:pointer}
      .btnPrint{background:#0b1b34;color:#fff;}
      .btnClose{background:#eef2f8;color:#0b1b34;text-decoration:none;display:inline-flex;align-items:center;}
      @media print{
        body{background:#fff;padding:0;}
        .btns{display:none;}
        .card{box-shadow:none;border-radius:0;}
      }
    /* Scroll interno para historial */
.historial-scroll{
  max-height: 320px;
  overflow-y: auto;
  overflow-x: hidden;
  margin-top: 10px;
  border-radius: 14px;
}
.historial-scroll thead th{
  position: sticky;
  top: 0;
  z-index: 2;
  background: #f3f7ff;
}
.historial-scroll::-webkit-scrollbar{ width: 10px; }
.historial-scroll::-webkit-scrollbar-thumb{ background: rgba(0,0,0,.18); border-radius: 10px; }
.historial-scroll::-webkit-scrollbar-track{ background: rgba(0,0,0,.06); border-radius: 10px; }
</style>
  </head>
  <body>
    <div class="card">
      <div class="top">
        <div>
          <div class="title">Acuse de Desembolso</div>
          <div class="sub">CEVIMEP • Movimiento #<?php echo (int)$r['id']; ?></div>
          <?php if ($fecha): ?><div class="sub"><?php echo h($fecha); ?></div><?php endif; ?>
        </div>
        <div class="pill">DESEMBOLSO</div>
      </div>

      <div class="grid">
        <div class="box">
          <div class="lbl">Motivo</div>
          <div class="val"><?php echo h($r['motivo'] ?? ''); ?></div>
        </div>
        <div class="box">
          <div class="lbl">Monto</div>
          <div class="val">RD$ <?php echo fmtMoney($monto); ?></div>
        </div>
        <div class="box">
          <div class="lbl">Método de pago</div>
          <div class="val"><?php echo h($r['metodo_pago'] ?? 'efectivo'); ?></div>
        </div>
        <div class="box">
          <div class="lbl">Usuario</div>
          <div class="val">#<?php echo (int)($r['created_by'] ?? 0); ?></div>
        </div>
      </div>

      <div class="btns">
        <button class="btn btnPrint" onclick="window.print()">Imprimir</button>
        <a class="btn btnClose" href="desembolso.php#historial">Cerrar</a>
      </div>
    </div>
  </body>
  </html>
  <?php
  exit;
}


$motivo = "";
$monto  = "";
$representante = ""; // ✅ siempre vacío para que el usuario escriba

$today    = date("Y-m-d");
$dayStart = $today . " 00:00:00";
$dayEnd   = $today . " 23:59:59";

// ✅ Sesión automática del turno actual (para registrar el movimiento)
$sessionId = caja_get_or_open_current_session($pdo, $branchId, $userId);
$currentCajaNum = caja_get_current_caja_num();

// Nombre de la sucursal iniciada
// 1) Intentar leer desde tablas (branches/sucursales/sedes) si existen
// 2) Fallback: mapa fijo por ID (para que siempre muestre "CEVIMEP Moca", etc.)
$branchMap = [
  1 => "CEVIMEP Moca",
  2 => "CEVIMEP La Vega",
  3 => "CEVIMEP Salcedo",
  4 => "CEVIMEP Santiago",
  5 => "CEVIMEP Mao Valverde",
  6 => "CEVIMEP Puerto Plata",
];

$branchName = $branchMap[$branchId] ?? ("Sucursal #" . $branchId);

try {
  $candidates = [
    ["branches",   ["name","branch_name","nombre","nombre_sucursal","sucursal","descripcion","title"]],
    ["sucursales", ["nombre","name","nombre_sucursal","descripcion","title"]],
    ["sedes",      ["nombre","name","nombre_sede","descripcion","title"]],
    ["sede",       ["nombre","name","descripcion","title"]],
    ["branch",     ["name","branch_name","nombre","descripcion","title"]],
  ];

  foreach ($candidates as $cand) {
    $t = $cand[0];
    if (!tableExists($pdo, $t)) continue;

    foreach ($cand[1] as $col) {
      if (!colExists($pdo, $t, $col)) continue;

      $idCol = colExists($pdo, $t, "id") ? "id" : (colExists($pdo, $t, "branch_id") ? "branch_id" : (colExists($pdo, $t, "sucursal_id") ? "sucursal_id" : null));
      if (!$idCol) continue;

      $st = $pdo->prepare("SELECT $col FROM $t WHERE $idCol=? LIMIT 1");
      $st->execute([$branchId]);
      $nm = $st->fetchColumn();
      if ($nm) { $branchName = (string)$nm; break 2; }
    }
  }
} catch (Throwable $e) { /* mantener fallback */ }


// Sesiones del día (para histórico y detalle cuando no hay branch_id en movimientos)
$sessIdsDay = [];
try {
  if (tableExists($pdo, "cash_sessions") && colExists($pdo, "cash_sessions", "branch_id")) {
    // en algunos sistemas el campo puede ser date_open o opened_at
    if (colExists($pdo, "cash_sessions", "date_open")) {
      $stS = $pdo->prepare("SELECT id FROM cash_sessions WHERE branch_id=? AND date_open=?");
      $stS->execute([$branchId, $today]);
      $sessIdsDay = $stS->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } elseif (colExists($pdo, "cash_sessions", "opened_at")) {
      $stS = $pdo->prepare("SELECT id FROM cash_sessions WHERE branch_id=? AND opened_at >= ? AND opened_at <= ?");
      $stS->execute([$branchId, $dayStart, $dayEnd]);
      $sessIdsDay = $stS->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
  }
} catch (Throwable $e) { $sessIdsDay = []; }

// ✅ Fallback: si no detectamos sesiones del día, usar la sesión actual abierta
if (empty($sessIdsDay) && !empty($sessionId)) { $sessIdsDay = [(int)$sessionId]; }

// Detectar campos disponibles
$hasBranchInMov = colExists($pdo, "cash_movements", "branch_id");
$hasRepInMov    = colExists($pdo, "cash_movements", "representante");

$createdCol = colExists($pdo, "cash_movements", "created_at") ? "created_at"
            : (colExists($pdo, "cash_movements", "created_on") ? "created_on" : null);
// Detectar campos disponibles
$hasBranchInMov = colExists($pdo, "cash_movements", "branch_id");
$createdCol = colExists($pdo, "cash_movements", "created_at") ? "created_at"
            : (colExists($pdo, "cash_movements", "created_on") ? "created_on" : null);

// POST: registrar desembolso
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $nowStr = date("Y-m-d H:i:s");
  try {
    $motivo = trim($_POST["motivo"] ?? "");
    $monto  = trim($_POST["monto"] ?? "");
    $representante = trim($_POST["representante"] ?? "");
    $amount = (float)str_replace([","," "], ["",""], $monto);

    if ($motivo === "") throw new Exception("Debes escribir el motivo.");
    if ($amount <= 0) throw new Exception("El monto debe ser mayor que 0.");
    if ($representante === "") throw new Exception("Debes escribir el representante.");

    // Si por alguna razón no hay sesión (fuera de horario), intentar abrir
    if ($sessionId <= 0) {
      $sessionId = caja_get_or_open_current_session($pdo, $branchId, $userId);
      if ($sessionId <= 0) throw new Exception("No se pudo obtener una sesión de caja activa.");
    }
    // Insert flexible según columnas
    if ($hasBranchInMov) {
      if ($hasRepInMov) {
        $sql = "INSERT INTO cash_movements
                  (session_id, branch_id, type, motivo, representante, metodo_pago, amount, created_by" . ($createdCol ? ", $createdCol" : "") . ")
                VALUES
                  (?, ?, 'desembolso', ?, ?, 'efectivo', ?, ?" . ($createdCol ? ", ?" : "") . ")";
        $st = $pdo->prepare($sql);
        $params = [$sessionId, $branchId, $motivo, $representante, round(-abs($amount),2), $userId];
        if ($createdCol) { $params[] = $nowStr; }
        $st->execute($params);
      } else {
        $sql = "INSERT INTO cash_movements
                  (session_id, branch_id, type, motivo, metodo_pago, amount, created_by" . ($createdCol ? ", $createdCol" : "") . ")
                VALUES
                  (?, ?, 'desembolso', ?, 'efectivo', ?, ?" . ($createdCol ? ", ?" : "") . ")";
        $st = $pdo->prepare($sql);
        $params = [$sessionId, $branchId, $motivo, round(-abs($amount),2), $userId];
        if ($createdCol) { $params[] = $nowStr; }
        $st->execute($params);
      }
    } else {
      if ($hasRepInMov) {
        $sql = "INSERT INTO cash_movements
                  (session_id, type, motivo, representante, metodo_pago, amount, created_by" . ($createdCol ? ", $createdCol" : "") . ")
                VALUES
                  (?, 'desembolso', ?, ?, 'efectivo', ?, ?" . ($createdCol ? ", ?" : "") . ")";
        $st = $pdo->prepare($sql);
        $params = [$sessionId, $motivo, $representante, round(-abs($amount),2), $userId];
        if ($createdCol) { $params[] = $nowStr; }
        $st->execute($params);
      } else {
        $sql = "INSERT INTO cash_movements
                  (session_id, type, motivo, metodo_pago, amount, created_by" . ($createdCol ? ", $createdCol" : "") . ")
                VALUES
                  (?, 'desembolso', ?, 'efectivo', ?, ?" . ($createdCol ? ", ?" : "") . ")";
        $st = $pdo->prepare($sql);
        $params = [$sessionId, $motivo, round(-abs($amount),2), $userId];
        if ($createdCol) { $params[] = $nowStr; }
        $st->execute($params);
      }
    }

    $_SESSION["flash_success"] = "Desembolso registrado.";
    $movId = (int)$pdo->lastInsertId();
    echo "<script>window.open('desembolso.php?acuse={$movId}', '_blank'); window.location.href='desembolso.php?ok=1#historial';</script>";
    exit;

  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

// =============================
// HISTORIAL DEL DÍA (SIEMPRE)
// =============================
// Regla:
// - Si cash_movements tiene branch_id, filtramos por branch_id.
// - Si no tiene branch_id, filtramos por session_id IN (sesiones del día de esa sucursal) para abarcar todos los turnos.

$history = [];
$totalDia = 0.0;

if ($createdCol) {
  if ($hasBranchInMov) {
    $sqlH = "
      SELECT id, motivo, amount, created_by, $createdCol AS created_time
      FROM cash_movements
      WHERE type='desembolso'
        AND branch_id = ?
        AND $createdCol >= ? AND $createdCol <= ?
      ORDER BY $createdCol DESC, id DESC
      LIMIT 500
    ";
    $stH = $pdo->prepare($sqlH);
    $stH->execute([$branchId, $dayStart, $dayEnd]);
    $history = $stH->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stT = $pdo->prepare("
      SELECT COALESCE(SUM(amount),0)
      FROM cash_movements
      WHERE type='desembolso'
        AND branch_id = ?
        AND $createdCol >= ? AND $createdCol <= ?
    ");
    $stT->execute([$branchId, $dayStart, $dayEnd]);
    $totalDia = (float)$stT->fetchColumn();

  } else {
    $sessIds = $sessIdsDay;

    if (!empty($sessIds)) {
      $ph = implode(",", array_fill(0, count($sessIds), "?"));

      $sqlH = "
        SELECT id, motivo, amount, created_by, $createdCol AS created_time
        FROM cash_movements
        WHERE type='desembolso'
          AND session_id IN ($ph)
          AND $createdCol >= ? AND $createdCol <= ?
        ORDER BY $createdCol DESC, id DESC
        LIMIT 500
      ";
      $stH = $pdo->prepare($sqlH);
      $stH->execute(array_merge($sessIds, [$dayStart, $dayEnd]));
      $history = $stH->fetchAll(PDO::FETCH_ASSOC) ?: [];

      $sqlT = "
        SELECT COALESCE(SUM(amount),0)
        FROM cash_movements
        WHERE type='desembolso'
          AND session_id IN ($ph)
          AND $createdCol >= ? AND $createdCol <= ?
      ";
      $stT = $pdo->prepare($sqlT);
      $stT->execute(array_merge($sessIds, [$dayStart, $dayEnd]));
      $totalDia = (float)$stT->fetchColumn();
    }
  }
} else {
  $sessIds = $sessIdsDay;

  if (!empty($sessIds)) {
    $ph = implode(",", array_fill(0, count($sessIds), "?"));

    $stH = $pdo->prepare("
      SELECT id, motivo, amount, created_by
      FROM cash_movements
      WHERE type='desembolso' AND session_id IN ($ph)
      ORDER BY id DESC
      LIMIT 500
    ");
    $stH->execute($sessIds);
    $history = $stH->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stT = $pdo->prepare("
      SELECT COALESCE(SUM(amount),0)
      FROM cash_movements
      WHERE type='desembolso' AND session_id IN ($ph)
    ");
    $stT->execute($sessIds);
    $totalDia = (float)$stT->fetchColumn();
  }
}

// =============================
// MODO IMPRESIÓN (DÍA COMPLETO)
// =============================

// =============================
// DETALLE (opcional)
// =============================
$detailRow = null;
$detailId = isset($_GET["detalle"]) ? (int)$_GET["detalle"] : 0;
if ($detailId > 0) {
  try {
    $cols = "id, motivo, amount, created_by" . ($createdCol ? ", $createdCol AS created_time" : "");
    if ($hasRepInMov) $cols .= ", representante";
    if ($hasBranchInMov) {
      $stD = $pdo->prepare("SELECT $cols FROM cash_movements WHERE id=? AND type='desembolso' AND branch_id=? LIMIT 1");
      $stD->execute([$detailId, $branchId]);
      $detailRow = $stD->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
      // Sin branch_id: permitimos ver el detalle si pertenece a una sesión del día en esta sucursal
      if (!empty($sessIdsDay)) {
        $ph = implode(",", array_fill(0, count($sessIdsDay), "?"));
        $stD = $pdo->prepare("SELECT $cols FROM cash_movements WHERE id=? AND type='desembolso' AND session_id IN ($ph) LIMIT 1");
        $stD->execute(array_merge([$detailId], $sessIdsDay));
        $detailRow = $stD->fetch(PDO::FETCH_ASSOC) ?: null;
      } else {
        $stD = $pdo->prepare("SELECT $cols FROM cash_movements WHERE id=? AND type='desembolso' AND session_id=? LIMIT 1");
        $stD->execute([$detailId, $sessionId]);
        $detailRow = $stD->fetch(PDO::FETCH_ASSOC) ?: null;
      }
    }
  } catch (Throwable $e) {
    $detailRow = null;
  }
}

$isPrint = (isset($_GET["print"]) && $_GET["print"] == "1");
if ($isPrint):
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CEVIMEP | Historial de desembolsos</title>
  <style>
    body{font-family:Arial,Helvetica,sans-serif; margin:24px; color:#111827;}
    h1{margin:0 0 6px;}
    .muted{color:#6b7280; font-weight:700; margin:0 0 14px;}
    table{width:100%; border-collapse:collapse; margin-top:10px;}
    th,td{border:1px solid #e5e7eb; padding:8px 10px; font-size:12px; text-align:left;}
    th{background:#f3f4f6;}
    .right{text-align:right;}
    .total{margin-top:12px; font-weight:900; font-size:14px;}
  </style>
</head>
<body>
  <h1>Historial de desembolsos (día)</h1>
  <div class="muted">
    Sucursal ID: <?php echo (int)$branchId; ?> · Fecha: <?php echo h($today); ?>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:70px;">ID</th>
        <?php if ($createdCol): ?><th style="width:170px;">Fecha/Hora</th><?php endif; ?>
        <th>Motivo</th>
        <th style="width:120px;" class="right">Monto</th>
        <th style="width:110px;">Usuario</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($history)): ?>
        <tr><td colspan="<?php echo $createdCol ? 5 : 4; ?>">No hay desembolsos en el día.</td></tr>
      <?php else: ?>
        <?php foreach ($history as $r): ?>
          <tr>
            <td>#<?php echo (int)$r["id"]; ?></td>
            <?php if ($createdCol): ?><td><?php echo h($r["created_time"] ?? ""); ?></td><?php endif; ?>
            <td><?php echo h($r["motivo"] ?? ""); ?></td>
            <td class="right">RD$ <?php echo fmtMoney(abs($r["amount"] ?? 0)); ?></td>
            <td><?php echo (int)($r["created_by"] ?? 0); ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="total">TOTAL DESEMBOLSADO (DÍA): RD$ <?php echo fmtMoney($totalDia); ?></div>

  <script>window.onload = () => window.print();</script>
</body>
</html>
<?php
exit;
endif;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Desembolso</title>

  <link rel="stylesheet" href="/assets/css/styles.css?v=50">

  <style>
    /* Panel derecho */
    .cardBox{
      background:#fff;
      border:1px solid #e6eef7;
      border-radius:22px;
      padding:18px;
      box-shadow:0 10px 30px rgba(2,6,23,.08);
    }

    .muted{color:#6b7280;font-weight:700;}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}

    .alert-ok{
      margin-top:10px;padding:10px 12px;border-radius:14px;
      background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-weight:900;
    }
    .alert-err{
      margin-top:10px;padding:10px 12px;border-radius:14px;
      background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;font-weight:900;
    }

    .pill{
      display:inline-flex;align-items:center;gap:8px;
      padding:6px 10px;border-radius:999px;
      background:#f3f7ff;border:1px solid #dbeafe;
      color:#052a7a;font-weight:900;font-size:12px;white-space:nowrap;
    }

    table{
      width:100%;
      border-collapse:collapse;
      margin-top:12px;
      border:1px solid #e6eef7;
      border-radius:16px;
      overflow:hidden;
      background:#fff;
    }
    th,td{padding:10px;border-bottom:1px solid #eef2f7;font-size:13px;text-align:left;}
    thead th{background:#f7fbff;color:#0b3b9a;font-weight:900;}
    .right{text-align:right;}

    /* ✅ Título centrado arriba */
    .hero.centered{
      text-align:center;
      padding: 18px 0 8px;
    }
    .hero.centered h1{
      margin:0;
      font-size:34px;
      font-weight:900;
      letter-spacing:.2px;
    }
    .hero.centered p{
      margin:8px 0 0;
      max-width:720px;
      margin-left:auto;
      margin-right:auto;
      font-weight:700;
      color:#475569;
    }

    /* ✅ Layout organizado */
    .two-col{
      display:grid;
      grid-template-columns: 1.15fr .85fr;
      gap:16px;
      align-items:start;
    }
    @media (max-width: 980px){
      .two-col{grid-template-columns: 1fr;}
    }

    /* ✅ Botones locales (sin romper tu styles.css) */
    .btnLocal{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      padding:10px 14px;
      border-radius:14px;
      border:1px solid #dbeafe;
      background:#fff;
      color:#052a7a;
      font-weight:900;
      text-decoration:none;
      cursor:pointer;
      transition: transform .05s ease, box-shadow .12s ease, background .12s ease, border-color .12s ease;
      user-select:none;
    }
    .btnLocal:hover{box-shadow:0 10px 20px rgba(2,6,23,.08); transform: translateY(-1px);}
    .btnLocal:active{transform: translateY(0); box-shadow:none;}
    .btnLocal.primary{background:#e0f2fe;}
    .btnLocal.print{
      background: linear-gradient(180deg, #2563eb, #0b3b9a);
      border-color: rgba(255,255,255,.18);
      color:#fff;
    }
    .btnLocal.print:hover{box-shadow:0 14px 26px rgba(37,99,235,.25);}
    button.btnLocal{appearance:none;}

    /* ✅ Form estético */
    .formGrid{
      display:grid;
      grid-template-columns: 1fr;
      gap:12px;
      margin-top:14px;
    }
    .field label{
      display:flex;
      justify-content:space-between;
      align-items:center;
      font-weight:900;
      color:#0b3b9a;
      margin-bottom:6px;
    }
    .field small{color:#64748b;font-weight:800;}
    .field input{
      width:100%;
      padding:12px 14px;
      border:1px solid #e6eef7;
      border-radius:14px;
      outline:none;
      background:#fff;
    }
    .field input[readonly]{
      background:#f8fafc;
      color:#0f172a;
    }
  
    /* UI tipo dashboard (como el ejemplo) */
    .pageTitle{
      text-align:center;
      font-weight:900;
      letter-spacing:.5px;
      color:#0f172a;
      font-size:42px;
      margin:8px 0 18px;
    }
    .panel{
      max-width:860px;
      margin:0 auto;
    }
    .cardBox{
      background:#fff;
      border:1px solid #e6eef7;
      border-radius:24px;
      padding:22px 22px 18px;
      box-shadow:0 18px 40px rgba(2,6,23,.10);
    }
    .formGrid{
      display:grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap:16px;
      margin-top:10px;
    }
    .formGrid.two{
      grid-template-columns: 1fr 1fr;
    }
    .inLabel{
      display:block;
      text-align:center;
      font-weight:900;
      color:#0b3aa7;
      margin-bottom:8px;
      letter-spacing:.3px;
      font-size:13px;
      text-transform:uppercase;
    }
    .inBox{
      width:100%;
      border:1px solid #dbeafe;
      border-radius:12px;
      padding:12px 12px;
      font-size:14px;
      background:#f8fbff;
      outline:none;
    }
    .inBox:focus{border-color:#93c5fd; box-shadow:0 0 0 3px rgba(59,130,246,.15);}
    .actionsCenter{
      display:flex;
      justify-content:center;
      gap:14px;
      margin-top:18px;
      flex-wrap:wrap;
    }
    .btnSave{
      border:none;
      padding:12px 26px;
      border-radius:999px;
      font-weight:900;
      color:#fff;
      cursor:pointer;
      background:linear-gradient(180deg,#2563eb,#1e40af);
      box-shadow:0 12px 24px rgba(37,99,235,.25);
    }
    .btnGhost{
      padding:12px 22px;
      border-radius:999px;
      font-weight:900;
      color:#1e40af;
      border:1px solid #bfdbfe;
      background:#fff;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
    }
    .historyTitle{
      text-align:center;
      font-weight:900;
      color:#0b3aa7;
      letter-spacing:.3px;
      margin:22px 0 12px;
    }
    .historyCard{
      max-width:860px;
      margin:0 auto;
      background:#fff;
      border:1px solid #e6eef7;
      border-radius:22px;
      padding:16px;
      box-shadow:0 18px 40px rgba(2,6,23,.08);
    }
    .historyHeader{
      display:flex;
      justify-content:space-between;
      gap:12px;
      flex-wrap:wrap;
      align-items:center;
      margin-bottom:10px;
    }
    .pillSoft{
      display:inline-flex;
      padding:8px 12px;
      border-radius:999px;
      border:1px solid #dbeafe;
      background:#f8fbff;
      font-weight:900;
      color:#0f172a;
      font-size:12px;
    }
    table.tbl{
      width:100%;
      border-collapse:separate;
      border-spacing:0;
      overflow:hidden;
      border-radius:14px;
      border:1px solid #e6eef7;
      font-size:13px;
    }
    .tbl th{
      text-align:left;
      padding:10px 10px;
      background:#f1f7ff;
      color:#0b3aa7;
      font-weight:900;
      border-bottom:1px solid #e6eef7;
    }
    .tbl td{
      padding:10px 10px;
      border-bottom:1px solid #eef2f7;
    }
    .tbl tr:last-child td{border-bottom:none;}
    .btnDetail{
      padding:8px 12px;
      border-radius:999px;
      border:1px solid #bfdbfe;
      background:#fff;
      color:#1e40af;
      font-weight:900;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      gap:6px;
      font-size:12px;
    }
    .detailBox{
      max-width:860px;
      margin:0 auto 12px;
      background:#fff;
      border:1px solid #e6eef7;
      border-radius:18px;
      padding:14px 16px;
      box-shadow:0 14px 30px rgba(2,6,23,.08);
    }
</style>
</head>

<body>

<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right"><a class="pill" href="/logout.php">Salir</a></div>
  </div>
</header>

<div class="layout">
  <aside class="sidebar">
    <div class="menu-title">Menú</div>
    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👥</span> Pacientes</a>
      <a href="javascript:void(0)" style="opacity:.55; cursor:not-allowed;"><span class="ico">🗓️</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a class="active" href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadistica/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">

  <div class="panel">
    <div class="pageTitle">DESEMBOLSO</div>

    <section class="cardBox">
      <?php if ($success): ?><div class="alert-ok"><?php echo h($success); ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert-err"><?php echo h($error); ?></div><?php endif; ?>

      <form method="post" autocomplete="off">
        <div class="formGrid">
          <div>
            <label class="inLabel" for="motivo">Motivo</label>
            <input class="inBox" id="motivo" type="text" name="motivo"
              value="<?php echo h($motivo); ?>"
              placeholder="Ej: Compra de agua, transporte, etc."
              required>
          </div>

          <div>
            <label class="inLabel" for="monto">Monto</label>
            <input class="inBox" id="monto" type="text" name="monto"
              value="<?php echo h($monto); ?>"
              placeholder="Ej: 500"
              required>
          </div>

          <div>
            <label class="inLabel" for="representante">Representante</label>
            <input class="inBox" id="representante" type="text" name="representante"
              value="<?php echo h($representante); ?>"
              placeholder="Ej: Juan Pérez"
              required>
          </div>
        </div>

        <div class="formGrid two" style="margin-top:16px;">
          <div>
            <label class="inLabel">Fecha</label>
            <input class="inBox" type="text" value="<?php echo h($today); ?>" readonly>
          </div>
          <div>
            <label class="inLabel">Sucursal</label>
            <input class="inBox" type="text" value="<?php echo h($branchName); ?>" readonly>
          </div>
        </div>

        <div class="actionsCenter">
          <button class="btnSave" type="submit">GUARDAR E IMPRIMIR</button>
</div>
      </form>
    </section>

    <div class="historyTitle" id="historial">HISTORIAL DE DESEMBOLSOS</div>

    <?php if ($detailRow): ?>
      <div class="detailBox">
        <div class="historyHeader">
          <div class="pillSoft">DETALLE #<?php echo (int)$detailRow["id"]; ?></div>
          <?php if ($createdCol && !empty($detailRow["created_time"])): ?>
            <div class="pillSoft"><?php echo h($detailRow["created_time"]); ?></div>
          <?php endif; ?>
          <a class="btnGhost" href="desembolso.php#historial">Cerrar</a>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div><strong>Motivo:</strong> <?php echo h($detailRow["motivo"] ?? ""); ?></div>
          <div><strong>Monto:</strong> RD$ <?php echo fmtMoney(abs($detailRow["amount"] ?? 0)); ?></div>
          <?php if ($hasRepInMov): ?>
            <div><strong>Representante:</strong> <?php echo h($detailRow["representante"] ?? ""); ?></div>
          <?php endif; ?>
          <div><strong>Usuario:</strong> <?php echo (int)($detailRow["created_by"] ?? 0); ?></div>
        </div>
      </div>
    <?php endif; ?>

    <section class="historyCard">
      <div class="historyHeader">
        <div class="muted">Últimos 500 desembolsos del día en esta sucursal.</div>
        <div class="pillSoft">TOTAL DÍA: RD$ <?php echo fmtMoney($totalDia); ?></div>
      </div>

      <div class="historial-scroll">
      <table class="tbl">
        <thead>
          <tr>
            <th style="width:80px;">ID</th>
            <?php if ($createdCol): ?><th style="width:190px;">Fecha/Hora</th><?php endif; ?>
            <th>Motivo</th>
            <th style="width:140px;">Monto</th>
            <th style="width:90px;">Usuario</th>
            <th style="width:120px;">Acción</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($history)): ?>
            <tr><td colspan="<?php echo $createdCol ? 6 : 5; ?>">No hay desembolsos en el día.</td></tr>
          <?php else: ?>
            <?php foreach ($history as $r): ?>
              <tr>
                <td>#<?php echo (int)$r["id"]; ?></td>
                <?php if ($createdCol): ?><td><?php echo h($r["created_time"] ?? ""); ?></td><?php endif; ?>
                <td><?php echo h($r["motivo"] ?? ""); ?></td>
                <td>RD$ <?php echo fmtMoney(abs($r["amount"] ?? 0)); ?></td>
                <td><?php echo (int)($r["created_by"] ?? 0); ?></td>
                <td>
                  <a class="btnDetail" href="desembolso.php?acuse=<?php echo (int)$r["id"]; ?>" target="_blank">Detalle</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
      </div>
    </section>

  </div>

</main>
</div>

<footer class="footer">
  <div class="footer-inner">© <?php echo $year; ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

</body>
</html>