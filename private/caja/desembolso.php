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
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtMoney($n){ return number_format((float)$n, 2, ".", ","); }

$success = "";
$error = "";

$motivo = "";
$monto = "";

$today = date("Y-m-d");
$dayStart = $today . " 00:00:00";
$dayEnd   = $today . " 23:59:59";

// ✅ Sesión automática del turno actual (para registrar el movimiento)
$sessionId = caja_get_or_open_current_session($pdo, $branchId, $userId);
$currentCajaNum = caja_get_current_caja_num();

// Helper columnas
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

// Detectar campos disponibles
$hasBranchInMov = colExists($pdo, "cash_movements", "branch_id");
$createdCol = colExists($pdo, "cash_movements", "created_at") ? "created_at"
            : (colExists($pdo, "cash_movements", "created_on") ? "created_on" : null);

// POST: registrar desembolso
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  try {
    $motivo = trim($_POST["motivo"] ?? "");
    $monto  = trim($_POST["monto"] ?? "");

    $amount = (float)str_replace([","," "], ["",""], $monto);

    if ($motivo === "") throw new Exception("Debes escribir el motivo.");
    if ($amount <= 0) throw new Exception("El monto debe ser mayor que 0.");

    // Si por alguna razón no hay sesión (fuera de horario), intentar abrir
    if ($sessionId <= 0) {
      $sessionId = caja_get_or_open_current_session($pdo, $branchId, $userId);
      if ($sessionId <= 0) throw new Exception("No se pudo obtener una sesión de caja activa.");
    }

    // Insert flexible según columnas
    if ($hasBranchInMov) {
      $sql = "INSERT INTO cash_movements
                (session_id, branch_id, type, motivo, metodo_pago, amount, created_by" . ($createdCol ? ", $createdCol" : "") . ")
              VALUES
                (?, ?, 'desembolso', ?, 'efectivo', ?, ?" . ($createdCol ? ", NOW()" : "") . ")";
      $st = $pdo->prepare($sql);
      $st->execute([$sessionId, $branchId, $motivo, round($amount,2), $userId]);
    } else {
      $sql = "INSERT INTO cash_movements
                (session_id, type, motivo, metodo_pago, amount, created_by" . ($createdCol ? ", $createdCol" : "") . ")
              VALUES
                (?, 'desembolso', ?, 'efectivo', ?, ?" . ($createdCol ? ", NOW()" : "") . ")";
      $st = $pdo->prepare($sql);
      $st->execute([$sessionId, $motivo, round($amount,2), $userId]);
    }

    $success = "Desembolso registrado.";
    $motivo = "";
    $monto = "";

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
    // sesiones del día para esa sucursal
    $stS = $pdo->prepare("SELECT id FROM cash_sessions WHERE branch_id=? AND date_open=?");
    $stS->execute([$branchId, $today]);
    $sessIds = $stS->fetchAll(PDO::FETCH_COLUMN) ?: [];

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
  // Si no hay created_at/created_on, igual mostramos por sesiones del día (menos exacto en tiempo, pero siempre)
  $stS = $pdo->prepare("SELECT id FROM cash_sessions WHERE branch_id=? AND date_open=?");
  $stS->execute([$branchId, $today]);
  $sessIds = $stS->fetchAll(PDO::FETCH_COLUMN) ?: [];
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
            <td class="right">RD$ <?php echo fmtMoney($r["amount"] ?? 0); ?></td>
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
    .cardBox{
      background:#fff;border:1px solid #e6eef7;border-radius:22px;padding:18px;
      box-shadow:0 10px 30px rgba(2,6,23,.08);
    }
    .muted{color:#6b7280;font-weight:700;}
    label{display:block;font-weight:900;margin-top:12px;color:#0b3b9a;}
    input[type="text"]{width:100%;padding:10px 12px;border:1px solid #e6eef7;border-radius:14px;outline:none;}
    .


Local{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:14px;border:1px solid #dbeafe;background:#fff;color:#052a7a;font-weight:900;text-decoration:none;cursor:pointer;}
    .


Local.primary{background:#e0f2fe;}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
    .alert-ok{margin-top:10px;padding:10px 12px;border-radius:14px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-weight:900;}
    .alert-err{margin-top:10px;padding:10px 12px;border-radius:14px;background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;font-weight:900;}
    .pill{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;background:#f3f7ff;border:1px solid #dbeafe;color:#052a7a;font-weight:900;font-size:12px;}
    table{width:100%;border-collapse:collapse;margin-top:12px;border:1px solid #e6eef7;border-radius:16px;overflow:hidden;}
    th,td{padding:10px;border-bottom:1px solid #eef2f7;font-size:13px;text-align:left;}
    thead th{background:#f7fbff;color:#0b3b9a;font-weight:900;}
    .right{text-align:right;}
  </style>
</head>
<body>

<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right"><a class="


-pill" href="/logout.php">Salir</a></div>
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
    <section class="hero">
      <h1>Desembolso</h1>
      <p>Registra salida de dinero y revisa el historial del día.</p>
    </section>

    <section class="card">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;">
        <div>
          <h2 style="margin:0; color:var(--primary-2);">Registrar desembolso</h2>
          <div class="muted" style="margin-top:6px;">Motivo y monto (RD$).</div>
          <div style="margin-top:10px;">
            <span class="pill">Caja actual: <?php echo (int)$currentCajaNum; ?></span>
            <span class="pill">Sesión actual: <?php echo (int)$sessionId; ?></span>
            <span class="pill">Fecha: <?php echo h($today); ?></span>
          </div>
        </div>
        <div class="row">
          <a class="


Local" href="index.php">Volver</a>
          <a class="


Local primary" href="desembolso.php?print=1" target="_blank">Imprimir historial (día)</a>
        </div>
      </div>

      <?php if ($success): ?><div class="alert-ok"><?php echo h($success); ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert-err"><?php echo h($error); ?></div><?php endif; ?>

      <form method="post" style="margin-top:12px;">
        <label>Motivo</label>
        <input type="text" name="motivo" value="<?php echo h($motivo); ?>" placeholder="Ej: Compra de agua, transporte, etc." required>

        <label>Monto (RD$)</label>
        <input type="text" name="monto" value="<?php echo h($monto); ?>" placeholder="Ej: 500" required>

        <div class="row" style="margin-top:14px;">
          <button class="


Local primary" type="submit">Guardar</button>
          <a class="


Local" href="index.php">Cancelar</a>
        </div>
      </form>
    </section>

    <div style="height:14px;"></div>

    <section class="cardBox">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-end;">
        <div>
          <h3 style="margin:0; color:#052a7a;">Historial del día</h3>
          <div class="muted" style="margin-top:6px;">Últimos 500 desembolsos del día en esta sucursal.</div>
        </div>
        <div class="pill">TOTAL DÍA: RD$ <?php echo fmtMoney($totalDia); ?></div>
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
            <tr><td colspan="<?php echo $createdCol ? 5 : 4; ?>" class="muted">No hay desembolsos registrados hoy.</td></tr>
          <?php else: ?>
            <?php foreach ($history as $r): ?>
              <tr>
                <td>#<?php echo (int)$r["id"]; ?></td>
                <?php if ($createdCol): ?><td><?php echo h($r["created_time"] ?? ""); ?></td><?php endif; ?>
                <td><?php echo h($r["motivo"] ?? ""); ?></td>
                <td class="right">RD$ <?php echo fmtMoney($r["amount"] ?? 0); ?></td>
                <td><?php echo (int)($r["created_by"] ?? 0); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </section>

  </main>
</div>

<footer class="footer">
  <div class="footer-inner">© <?php echo $year; ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

</body>
</html>
