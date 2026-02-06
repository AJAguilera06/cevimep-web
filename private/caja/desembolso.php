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
        $params = [$sessionId, $branchId, $motivo, $representante, round($amount,2), $userId];
        if ($createdCol) { $params[] = $nowStr; }
        $st->execute($params);
      } else {
        $sql = "INSERT INTO cash_movements
                  (session_id, branch_id, type, motivo, metodo_pago, amount, created_by" . ($createdCol ? ", $createdCol" : "") . ")
                VALUES
                  (?, ?, 'desembolso', ?, 'efectivo', ?, ?" . ($createdCol ? ", ?" : "") . ")";
        $st = $pdo->prepare($sql);
        $params = [$sessionId, $branchId, $motivo, round($amount,2), $userId];
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
        $params = [$sessionId, $motivo, $representante, round($amount,2), $userId];
        if ($createdCol) { $params[] = $nowStr; }
        $st->execute($params);
      } else {
        $sql = "INSERT INTO cash_movements
                  (session_id, type, motivo, metodo_pago, amount, created_by" . ($createdCol ? ", $createdCol" : "") . ")
                VALUES
                  (?, 'desembolso', ?, 'efectivo', ?, ?" . ($createdCol ? ", ?" : "") . ")";
        $st = $pdo->prepare($sql);
        $params = [$sessionId, $motivo, round($amount,2), $userId];
        if ($createdCol) { $params[] = $nowStr; }
        $st->execute($params);
      }
    }

    $_SESSION["flash_success"] = "Desembolso registrado.";
    header("Location: " . $_SERVER["PHP_SELF"] . "?ok=1");
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
?>

<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CEVIMEP | Desembolso</title>
  <style>
:root{
  --blue:#0b5ed7;
  --blue2:#0a4fb8;
  --sidebar:#071b3a;
  --sidebar2:#0a2552;
  --bg:#f5f8ff;
  --card:#ffffff;
  --text:#0f172a;
  --muted:#64748b;
  --border:#e6eef7;
  --shadow:0 10px 30px rgba(2,8,23,.08);
  --radius:22px;
}

*{box-sizing:border-box;}
html,body{height:100%;}
body{
  margin:0;
  font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
  background:var(--bg);
  color:var(--text);
  overflow:hidden;
}

/* TOPBAR */
.topbar{
  height:64px;
  background:linear-gradient(90deg,var(--blue),var(--blue2));
  color:#fff;
  display:flex;
  align-items:center;
  box-shadow:0 10px 20px rgba(2,8,23,.12);
}
.topbar__inner{
  width:100%;
  max-width:1400px;
  margin:0 auto;
  padding:0 18px;
  display:grid;
  grid-template-columns:1fr auto 1fr;
  align-items:center;
  gap:10px;
}
.brand{display:flex;align-items:center;gap:10px;font-weight:900;}
.brand__dot{width:10px;height:10px;border-radius:999px;background:#fff;box-shadow:0 0 0 4px rgba(255,255,255,.18);}
.brand__text{letter-spacing:.3px;}
.topbar__title{font-weight:900;text-align:center;white-space:nowrap;}
.topbar__link{
  justify-self:end;
  color:#fff;text-decoration:none;font-weight:900;
  padding:10px 12px;border-radius:12px;
}
.topbar__link:hover{background:rgba(255,255,255,.14);}

/* LAYOUT */
.layout{
  height:calc(100vh - 64px - 56px);
  display:flex;
  min-height:0;
}
.sidebar{
  width:270px;
  background:linear-gradient(180deg,var(--sidebar),var(--sidebar2));
  color:#fff;
  padding:18px 14px;
  display:flex;
  flex-direction:column;
  min-height:0;
}
.sidebar__title{
  font-weight:900;
  text-align:center;
  padding:6px 0 12px;
  opacity:.95;
}
.menu{display:flex;flex-direction:column;gap:10px;overflow:auto;padding-right:6px;}
.menu a{
  display:flex;align-items:center;gap:10px;
  padding:12px 12px;border-radius:14px;
  color:#eaf2ff;text-decoration:none;font-weight:900;
  background:rgba(255,255,255,.06);
  border:1px solid rgba(255,255,255,.08);
  transition:.15s ease;
}
.menu a:hover{transform:translateY(-1px);background:rgba(255,255,255,.10);}
.menu a.active{background:#ffffff;color:#071b3a;border-color:rgba(255,255,255,.35);}
.menu a.disabled{opacity:.55;cursor:not-allowed;}
.ico{width:20px;text-align:center;}

.sidebar__footer{margin-top:auto;}
.chip{
  background:rgba(255,255,255,.08);
  border:1px solid rgba(255,255,255,.10);
  border-radius:16px;
  padding:12px;
}
.chip__label{font-size:12px;opacity:.85;margin-bottom:4px;}
.chip__value{font-weight:900;}

/* CONTENT */
.content{
  flex:1;min-width:0;
  overflow:auto;
  padding:22px;
}
.page-title{margin:0;font-size:26px;font-weight:900;}
.page-subtitle{margin-top:6px;color:var(--muted);font-weight:800;}

.grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:14px;
}
@media(max-width:980px){
  .sidebar{display:none;}
  .grid{grid-template-columns:1fr;}
}

.card{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  padding:18px;
}
.card--wide{margin-top:14px;}
.card__head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;}
.card__title{font-weight:900;font-size:16px;}
.muted{color:var(--muted);font-weight:800;}

.badge{
  padding:8px 12px;border-radius:999px;
  font-weight:900;font-size:12px;
  border:1px solid var(--border);
  background:#f8fbff;
}
.badge--ok{background:#ecfdf5;border-color:#bbf7d0;color:#166534;}
.badge--warn{background:#fff7ed;border-color:#fed7aa;color:#9a3412;}
.badge--info{background:#eff6ff;border-color:#bfdbfe;color:#1e40af;}

.kv{display:flex;flex-direction:column;gap:10px;}
.kv__row{
  display:flex;justify-content:space-between;gap:12px;
  padding:10px 12px;
  background:#f8fbff;
  border:1px solid var(--border);
  border-radius:16px;
}
.kv__k{color:var(--muted);font-weight:900;}
.kv__v{font-weight:900;}

.card__actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;}
.btn{
  display:inline-flex;align-items:center;justify-content:center;
  padding:12px 14px;border-radius:16px;
  font-weight:900;text-decoration:none;
  border:1px solid transparent;
  transition:.15s ease;
}
.btn--primary{
  background:linear-gradient(90deg,var(--blue),var(--blue2));
  color:#fff;
  box-shadow:0 10px 22px rgba(11,94,215,.22);
}
.btn--primary:hover{transform:translateY(-1px);}
.btn--ghost{
  background:#fff;
  color:#071b3a;
  border-color:var(--border);
}
.btn--ghost:hover{transform:translateY(-1px);}

.stats{
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:12px;
}
@media(max-width:900px){.stats{grid-template-columns:1fr;}}
.stat{
  background:#f8fbff;border:1px solid var(--border);
  border-radius:18px;padding:14px;
}
.stat__label{color:var(--muted);font-weight:900;font-size:12px;}
.stat__value{font-weight:900;font-size:18px;margin-top:6px;}

.note{margin-top:12px;color:var(--muted);font-weight:800;font-size:13px;}

.actions-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:12px;
}
@media(max-width:900px){.actions-grid{grid-template-columns:1fr;}}
.action-tile{
  display:flex;gap:12px;align-items:center;
  padding:14px;border-radius:18px;
  border:1px solid var(--border);
  background:#fff;
  text-decoration:none;color:var(--text);
  box-shadow:0 10px 22px rgba(2,8,23,.06);
  transition:.15s ease;
}
.action-tile:hover{transform:translateY(-1px);}
.action-tile__ico{
  width:44px;height:44px;border-radius:16px;
  display:flex;align-items:center;justify-content:center;
  background:#eff6ff;border:1px solid #dbeafe;
  font-size:18px;
}
.action-tile__title{font-weight:900;}
.action-tile__sub{color:var(--muted);font-weight:800;font-size:13px;margin-top:2px;}

.footer{
  height:56px;
  background:linear-gradient(90deg,var(--blue),var(--blue2));
  color:#fff;display:flex;align-items:center;
}
.footer__inner{
  width:100%;max-width:1400px;margin:0 auto;padding:0 18px;
  font-weight:900;text-align:center;
}



/* Inputs for desembolso */
.formGrid{
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:14px;
}
@media(max-width:980px){ .formGrid{grid-template-columns:1fr;} }

.inLabel{display:block;font-weight:900;color:var(--muted);font-size:12px;margin:0 0 6px;}
.inBox{
  width:100%;
  padding:12px 14px;
  border-radius:16px;
  border:1px solid var(--border);
  background:#f8fbff;
  font-weight:900;
  outline:none;
}
.inBox:focus{border-color:#bfd9ff; box-shadow:0 0 0 4px rgba(11,94,215,.10); background:#fff;}

.alert-ok, .alert-err{
  border-radius:16px;
  padding:12px 14px;
  font-weight:900;
  border:1px solid var(--border);
  margin-bottom:12px;
}
.alert-ok{background:#ecfdf5;border-color:#bbf7d0;color:#166534;}
.alert-err{background:#fff1f2;border-color:#fecdd3;color:#9f1239;}

.tableWrap{
  border:1px solid var(--border);
  border-radius:18px;
  overflow:hidden; /* mantiene todo dentro */
  background:#fff;
}
.tableScroll{
  max-height:260px;   /* ✅ scroll dentro de la card */
  overflow:auto;
}
.table{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
}
.table thead th{
  position:sticky; top:0; z-index:2;
  background:#eef5ff;
  border-bottom:1px solid var(--border);
  font-weight:900;
  padding:10px 12px;
  font-size:12px;
  text-align:left;
}
.table tbody td{
  padding:12px;
  border-bottom:1px solid #eef2ff;
  font-weight:800;
  font-size:13px;
}
.table tbody tr:last-child td{border-bottom:none;}
.right{text-align:right;}
.small{font-size:12px;color:var(--muted);font-weight:800;}


/* Print: acuse en otra pestaña, aquí solo mantenemos layout */
  </style>
</head>
<body>
  <header class="topbar">
    <div class="topbar__inner">
      <div class="brand">
        <span class="brand__dot"></span>
        <span class="brand__text">CEVIMEP</span>
      </div>
      <div class="topbar__title">DESEMBOLSO</div>
      <a class="topbar__link" href="/logout.php">Salir</a>
    </div>
  </header>

  <div class="layout">
    <aside class="sidebar">
      <div class="sidebar__title">Menú</div>
      <nav class="menu">
        <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
        <a href="/private/patients/index.php"><span class="ico">👥</span> Pacientes</a>
        <a class="disabled" href="javascript:void(0)"><span class="ico">🗓️</span> Citas</a>
        <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
        <a class="active" href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>
        <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
        <a href="/private/estadisticas/index.php"><span class="ico">📊</span> Estadísticas</a>
      </nav>

      <div class="sidebar__footer">
        <div class="chip">
          <div class="chip__label">Sucursal</div>
          <div class="chip__value"><?php echo h($branchName); ?></div>
        </div>
      </div>
    </aside>

    <main class="content">
      <h1 class="page-title">DESEMBOLSO</h1>
      <div class="page-subtitle">Registra un desembolso y genera el acuse automáticamente.</div>

      <div class="card" style="margin-top:14px;">
        <div class="card__head">
          <div class="card__title">Formulario</div>
          <span class="badge badge--info">Efectivo</span>
        </div>

        <?php if ($success): ?>
          <div class="alert-ok">Desembolso registrado.</div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="alert-err"><?php echo h($error); ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
          <div class="formGrid">
            <div>
              <label class="inLabel" for="motivo">MOTIVO</label>
              <input class="inBox" id="motivo" name="motivo" type="text"
                     value="<?php echo h($motivo); ?>"
                     placeholder="Ej: Compra de agua, transporte"
                     required>
            </div>
            <div>
              <label class="inLabel" for="monto">MONTO</label>
              <input class="inBox" id="monto" name="monto" type="text"
                     value="<?php echo h($monto); ?>"
                     placeholder="Ej: 500"
                     inputmode="decimal"
                     required>
            </div>
            <div>
              <label class="inLabel" for="representante">REPRESENTANTE</label>
              <input class="inBox" id="representante" name="representante" type="text"
                     value="<?php echo h($representante); ?>"
                     placeholder="Ej: Juan Pérez"
                     required>
            </div>
            <div>
              <label class="inLabel">FECHA</label>
              <input class="inBox" type="text" value="<?php echo h($today); ?>" readonly>
            </div>
            <div>
              <label class="inLabel">SUCURSAL</label>
              <input class="inBox" type="text" value="<?php echo h($branchName); ?>" readonly>
            </div>
            <div style="display:flex; align-items:end;">
              <button class="btn btn--primary" type="submit" style="width:100%;">GUARDAR E IMPRIMIR</button>
            </div>
          </div>

          <div class="note">El acuse se abrirá en otra pestaña al guardar.</div>
        </form>
      </div>

      <h2 id="historial" class="page-title" style="font-size:18px;margin-top:18px;">HISTORIAL DE DESEMBOLSOS</h2>

      <div class="card card--wide">
        <div class="card__head">
          <div>
            <div class="card__title">Últimos 500 desembolsos del día en esta sucursal.</div>
            <div class="small">Se muestra por sesiones del día (turnos) o por sucursal según estructura de BD.</div>
          </div>
          <span class="badge badge--info">TOTAL DÍA: RD$ <?php echo fmtMoney(abs($totalDia)); ?></span>
        </div>

        <div class="tableWrap">
          <div class="tableScroll">
            <table class="table">
              <thead>
                <tr>
                  <th style="width:90px;">ID</th>
                  <?php if ($createdCol): ?><th style="width:170px;">Fecha/Hora</th><?php endif; ?>
                  <th>Motivo</th>
                  <th style="width:140px;" class="right">Monto</th>
                  <th style="width:90px;">Usuario</th>
                  <th style="width:120px;">Acción</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$history): ?>
                  <tr>
                    <td colspan="<?php echo $createdCol ? 6 : 5; ?>" class="small">No hay desembolsos en el día.</td>
                  </tr>
                <?php else: foreach ($history as $r): ?>
                  <tr>
                    <td>#<?php echo (int)$r["id"]; ?></td>
                    <?php if ($createdCol): ?><td><?php echo h($r["created_time"] ?? ""); ?></td><?php endif; ?>
                    <td><?php echo h($r["motivo"] ?? ""); ?></td>
                    <td class="right">RD$ <?php echo fmtMoney(abs((float)($r["amount"] ?? 0))); ?></td>
                    <td><?php echo (int)($r["created_by"] ?? 0); ?></td>
                    <td>
                      <a class="btn btn--ghost" style="padding:8px 10px;border-radius:14px;"
                         href="/private/caja/acuse_desembolso.php?id=<?php echo (int)$r["id"]; ?>"
                         target="_blank">Detalle</a>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>

    </main>
  </div>

  <footer class="footer">
    <div class="footer__inner">© <?php echo (int)$year; ?> CEVIMEP. Todos los derechos reservados.</div>
  </footer>
</body>
</html>
