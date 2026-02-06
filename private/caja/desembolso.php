<?php
/**
 * CEVIMEP - Caja / Desembolso
 * - Diseño oficial (caja.css)
 * - Guardar e Imprimir (abre acuse en otra pestaña)
 * - Historial con scroll dentro de la card
 *
 * Requiere:
 *  - /private/config/db.php (debe exponer $pdo como PDO)
 *  - /private/caja/caja_lib.php (si existe caja_get_or_open_current_session)
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/caja_lib.php";

if (!isset($_SESSION["user"])) { header("Location: /login.php"); exit; }

$user = $_SESSION["user"];
$branchId = (int)($user["branch_id"] ?? 0);
$userId   = (int)($user["id"] ?? 0);

date_default_timezone_set("America/Santo_Domingo");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function money($n){ return "RD$ " . number_format((float)$n, 2, ".", ","); }

$today = date("Y-m-d");

// =============================
// 1) Sesión de caja actual
// =============================
$sessionId = null;

// Preferir función existente del proyecto
if (function_exists("caja_get_or_open_current_session")) {
  try {
    $sessionId = (int)caja_get_or_open_current_session($pdo, $branchId, $userId);
    if ($sessionId <= 0) { $sessionId = null; }
  } catch (Throwable $e) { $sessionId = null; }
}

// Fallback: buscar una caja abierta
if (!$sessionId) {
  try {
    $st = $pdo->prepare("SELECT id FROM cash_sessions
                         WHERE branch_id = ? AND (status = 'abierta' OR status = 'open' OR status = 'opened')
                         ORDER BY id DESC LIMIT 1");
    $st->execute([$branchId]);
    $sessionId = (int)($st->fetchColumn() ?: 0);
    if ($sessionId <= 0) { $sessionId = null; }
  } catch (Throwable $e) { $sessionId = null; }
}

// =============================
// 2) Guardar desembolso
// =============================
$flashOk = false;
$flashErr = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $motivo = trim($_POST["motivo"] ?? "");
  $monto  = (float)($_POST["monto"] ?? 0);
  $rep    = trim($_POST["representante"] ?? "");
  $metodo = trim($_POST["metodo_pago"] ?? "efectivo");

  if ($motivo === "") $flashErr = "El motivo es obligatorio.";
  elseif ($monto <= 0) $flashErr = "El monto debe ser mayor que cero.";
  elseif ($rep === "") $flashErr = "El representante es obligatorio.";
  elseif (!$sessionId) $flashErr = "No hay una caja abierta para esta sucursal.";
  else {
    try {
      // Desembolso siempre negativo en caja
      $amount = -abs($monto);

      // Guardar movimiento
      $sql = "INSERT INTO cash_movements (session_id, type, motivo, metodo_pago, amount, created_by, created_at)
              VALUES (?, 'desembolso', ?, ?, ?, ?, NOW())";
      $st = $pdo->prepare($sql);
      $st->execute([$sessionId, $motivo, $metodo, $amount, $userId]);

      $movId = (int)$pdo->lastInsertId();
      $flashOk = true;

      // Abrir acuse en otra pestaña + volver al historial
      echo "<script>
        window.open('acuse_desembolso.php?id={$movId}', '_blank');
        window.location.href = 'desembolso.php?ok=1#historial';
      </script>";
      exit;
    } catch (Throwable $e) {
      $flashErr = "No se pudo guardar el desembolso. Verifica la base de datos.";
    }
  }
}

if (isset($_GET["ok"]) && $_GET["ok"] === "1") $flashOk = true;

// =============================
// 3) Historial (sesión actual)
// =============================
$history = [];
$totalDia = 0.0;

if ($sessionId) {
  try {
    $sqlH = "SELECT id, motivo, metodo_pago, amount, created_at, created_by
             FROM cash_movements
             WHERE session_id = ? AND type = 'desembolso' AND DATE(created_at) = ?
             ORDER BY id DESC
             LIMIT 500";
    $st = $pdo->prepare($sqlH);
    $st->execute([$sessionId, $today]);
    $history = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // total día (sumar valores absolutos)
    foreach ($history as $r) {
      $totalDia += abs((float)$r["amount"]);
    }
  } catch (Throwable $e) {
    $history = [];
    $totalDia = 0.0;
  }
}

// Sucursal (texto)
$branchName = $_SESSION["branch_name"] ?? ($user["branch_name"] ?? "");
if ($branchName === "") $branchName = "CEVIMEP";

?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Desembolso - CEVIMEP</title>

  <!-- ✅ Estilo oficial -->
  <link rel="stylesheet" href="/private/caja/caja.css">

  <!-- Ajustes puntuales para inputs y tabla -->
  <style>
    .formGrid{
      display:grid;
      grid-template-columns:repeat(3,minmax(0,1fr));
      gap:14px;
      margin-top:10px;
    }
    @media(max-width:980px){ .formGrid{grid-template-columns:1fr;} }
    .field label{
      display:block;
      font-weight:900;
      font-size:12px;
      color:#0b2a5b;
      margin-bottom:6px;
      text-transform:uppercase;
      letter-spacing:.4px;
    }
    .control{
      width:100%;
      padding:12px 14px;
      border:1px solid var(--border);
      border-radius:14px;
      outline:none;
      font-weight:800;
      background:#fff;
    }
    .control:focus{ border-color:#9cc2ff; box-shadow:0 0 0 4px rgba(11,94,215,.10); }
    .right{ text-align:right; }

    /* HISTORIAL: la card NO crece; el scroll es interno */
    .historyCard{ overflow:hidden; }
    .tableScroll{
      max-height:260px; /* <-- aquí ajustas cuánto se ve antes del scroll */
      overflow:auto;
      border-radius:14px;
      border:1px solid var(--border);
      background:#fff;
    }

    table{ width:100%; border-collapse:separate; border-spacing:0; }
    thead th{
      position:sticky; top:0; z-index:2;
      background:#eef5ff;
      font-weight:900;
      padding:12px 12px;
      border-bottom:1px solid var(--border);
      font-size:13px;
    }
    tbody td{
      padding:12px 12px;
      border-bottom:1px solid var(--border);
      font-weight:800;
      font-size:13px;
    }
    tbody tr:last-child td{ border-bottom:none; }

    .pillTotal{
      padding:8px 12px;border-radius:999px;
      font-weight:900;font-size:12px;
      border:1px solid #bcd6ff;
      background:#f4f9ff;
      color:#0b2a5b;
      white-space:nowrap;
    }

    .alertOk{
      padding:12px 14px;border-radius:14px;
      background:#eafff1;border:1px solid #b7f3c7;
      font-weight:900;color:#0b5b2a;
      margin-bottom:12px;
    }
    .alertErr{
      padding:12px 14px;border-radius:14px;
      background:#fff0f0;border:1px solid #ffd0d0;
      font-weight:900;color:#8a1f1f;
      margin-bottom:12px;
    }
  </style>
</head>

<body>
  <!-- TOPBAR -->
  <header class="topbar">
    <div class="topbar__inner">
      <a class="topbar__link" href="/dashboard.php">● <b>CEVIMEP</b></a>
      <div class="topbar__title">DESEMBOLSO</div>
      <div style="text-align:right;">
        <a class="topbar__link" href="/logout.php">Salir</a>
      </div>
    </div>
  </header>

  <div class="layout">
    <!-- SIDEBAR -->
    <aside class="sidebar">
      <div class="sidebar__title">Menú</div>
      <nav class="menu">
        <a href="/dashboard.php">🏠 Panel</a>
        <a href="/private/pacientes/index.php">👥 Pacientes</a>
        <a href="/private/citas/index.php">📅 Citas</a>
        <a href="/private/facturacion/index.php">🧾 Facturación</a>
        <a class="active" href="/private/caja/index.php">💵 Caja</a>
        <a href="/private/inventario/index.php">📦 Inventario</a>
        <a href="/private/estadisticas/index.php">📊 Estadísticas</a>
      </nav>

      <div class="sidebar__footer">
        <div class="muted" style="margin-bottom:6px;">Sucursal</div>
        <div style="font-weight:900;"><?php echo h($branchName); ?></div>
      </div>
    </aside>

    <!-- CONTENT -->
    <main class="content">
      <h1 class="page-title">DESEMBOLSO</h1>
      <div class="page-subtitle">Registra un desembolso y genera el acuse automáticamente.</div>

      <section class="card card--wide">
        <div class="card__head">
          <div class="card__title">Formulario</div>
          <span class="badge">Efectivo</span>
        </div>

        <?php if ($flashOk): ?>
          <div class="alertOk">Desembolso registrado.</div>
        <?php endif; ?>
        <?php if ($flashErr): ?>
          <div class="alertErr"><?php echo h($flashErr); ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
          <div class="formGrid">
            <div class="field">
              <label>Motivo</label>
              <input class="control" name="motivo" placeholder="Ej: Compra de agua, transporte" required>
            </div>
            <div class="field">
              <label>Monto</label>
              <input class="control" name="monto" type="number" step="0.01" min="0" placeholder="Ej: 500" required>
            </div>
            <div class="field">
              <label>Representante</label>
              <input class="control" name="representante" placeholder="Ej: Juan Pérez" required>
            </div>

            <div class="field">
              <label>Fecha</label>
              <input class="control" value="<?php echo h($today); ?>" disabled>
            </div>
            <div class="field">
              <label>Sucursal</label>
              <input class="control" value="<?php echo h($branchName); ?>" disabled>
            </div>
            <div class="field" style="display:flex;align-items:flex-end;justify-content:flex-end;">
              <button type="submit" class="btn btn--primary" style="width:100%;max-width:320px;">
                GUARDAR E IMPRIMIR
              </button>
            </div>
          </div>

          <input type="hidden" name="metodo_pago" value="efectivo">
        </form>

        <div class="muted" style="margin-top:10px;">
          El acuse se abrirá en otra pestaña al guardar.
        </div>
      </section>

      <h2 id="historial" style="margin:18px 0 10px;font-weight:900;">HISTORIAL DE DESEMBOLSOS</h2>

      <section class="card historyCard">
        <div class="card__head">
          <div>
            <div class="muted">Últimos 500 desembolsos del día en esta sucursal.</div>
          </div>
          <div class="pillTotal">TOTAL DÍA: <?php echo money($totalDia); ?></div>
        </div>

        <div class="tableScroll">
          <table>
            <thead>
              <tr>
                <th style="width:90px;">ID</th>
                <th style="width:170px;">Fecha/Hora</th>
                <th>Motivo</th>
                <th style="width:140px;" class="right">Monto</th>
                <th style="width:90px;">Usuario</th>
                <th style="width:110px;">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($history)): ?>
                <tr><td colspan="6" class="muted" style="padding:16px;">No hay desembolsos en el día.</td></tr>
              <?php else: foreach ($history as $r): ?>
                <tr>
                  <td>#<?php echo (int)$r["id"]; ?></td>
                  <td><?php echo h($r["created_at"] ?? ""); ?></td>
                  <td><?php echo h($r["motivo"] ?? ""); ?></td>
                  <td class="right"><?php echo money(abs((float)$r["amount"])); ?></td>
                  <td><?php echo h($r["created_by"] ?? ""); ?></td>
                  <td>
                    <a class="btn btn--ghost" style="padding:8px 12px;border-radius:999px;"
                       target="_blank"
                       href="acuse_desembolso.php?id=<?php echo (int)$r["id"]; ?>">
                      Detalle
                    </a>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>

  <footer style="height:56px;background:linear-gradient(90deg,var(--blue),var(--blue2));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;">
    © <?php echo date("Y"); ?> CEVIMEP. Todos los derechos reservados.
  </footer>
</body>
</html>
