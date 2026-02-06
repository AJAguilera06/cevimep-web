<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
require_once __DIR__ . "/../config/db.php";   // deja tu conexión existente ($pdo)
require_once __DIR__ . "/caja_lib.php";       // para sesión de caja si existe

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (!isset($_SESSION["user"])) { header("Location: /login.php"); exit; }

$pdo = $pdo ?? ($conn ?? null);
if (!$pdo) { die("DB no disponible."); }

$user = $_SESSION["user"];
$year = date("Y");
$branchId = (int)($user["branch_id"] ?? 0);
$userId   = (int)($user["id"] ?? 0);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

if ($branchId <= 0) { http_response_code(400); die("Sucursal inválida."); }

/** Obtiene sesión de caja actual (abierta) */
$sessionId = 0;
try {
  if (function_exists("caja_get_or_open_current_session")) {
    $sessionId = (int)caja_get_or_open_current_session($pdo, $branchId, $userId);
  } else {
    $q = $pdo->prepare("SELECT id FROM cash_sessions WHERE branch_id=? AND status='abierta' ORDER BY id DESC LIMIT 1");
    $q->execute([$branchId]);
    $sessionId = (int)($q->fetchColumn() ?: 0);
  }
} catch (Throwable $e) { $sessionId = 0; }

if ($sessionId <= 0) { http_response_code(400); die("No hay caja abierta para esta sucursal."); }

$flashOk = "";
$flashErr = "";

/** Guardar desembolso */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $motivo = trim((string)($_POST["motivo"] ?? ""));
  $montoRaw = (string)($_POST["monto"] ?? "");
  $representante = trim((string)($_POST["representante"] ?? ""));
  $metodoPago = "efectivo"; // fijo (como tu diseño)

  $monto = (float)str_replace([","," "], ["",""], $montoRaw);

  if ($motivo === "") $flashErr = "El motivo es obligatorio.";
  elseif ($monto <= 0) $flashErr = "El monto debe ser mayor que cero.";
  elseif ($representante === "") $flashErr = "El representante es obligatorio.";

  if ($flashErr === "") {
    try {
      $amount = -abs($monto); // ✅ desembolso negativo
      $ins = $pdo->prepare("
        INSERT INTO cash_movements (session_id, type, motivo, metodo_pago, amount, created_at, created_by)
        VALUES (?, 'desembolso', ?, ?, ?, NOW(), ?)
      ");
      $ins->execute([$sessionId, $motivo, $metodoPago, $amount, $userId]);
      $movementId = (int)$pdo->lastInsertId();

      // abre acuse en otra pestaña y regresa al historial
      echo "<script>
        window.open('/private/caja/acuse_desembolso.php?id={$movementId}', '_blank');
        window.location.href = '/private/caja/desembolso.php?ok=1#historial';
      </script>";
      exit;
    } catch (Throwable $e) {
      $flashErr = "No se pudo registrar el desembolso.";
    }
  }
}

if (isset($_GET["ok"])) $flashOk = "Desembolso registrado.";

/** Datos de sucursal (texto) */
$branchName = "CEVIMEP";
try {
  $b = $pdo->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $b->execute([$branchId]);
  $branchName = (string)($b->fetchColumn() ?: $branchName);
} catch (Throwable $e) {}

/** Historial (sesión actual) */
$rows = [];
$total = 0.0;
try {
  $q = $pdo->prepare("
    SELECT id, created_at, motivo, amount, created_by
    FROM cash_movements
    WHERE session_id=? AND type='desembolso'
    ORDER BY id DESC
    LIMIT 500
  ");
  $q->execute([$sessionId]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($rows as $r) { $total += (float)$r["amount"]; } // negativo
} catch (Throwable $e) { $rows = []; $total = 0.0; }

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Desembolso</title>

  <!-- MISMO CSS que usa items.php -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=120">

  <style>
    /* page wrap igual a items.php */
    .page-wrap{ width:100%; max-width: 1040px; margin:0 auto; padding: 24px 18px 18px; }
    .page-title{ text-align:center; font-weight: 950; font-size: 44px; letter-spacing: -1px; margin: 8px 0 16px; }
    .card-soft{ background:#fff; border-radius: 22px; box-shadow: 0 18px 40px rgba(0,0,0,.10); padding: 18px; }
    .form-grid{ display:grid; grid-template-columns: repeat(3, 1fr); gap:14px; }
    .form-grid .span2{ grid-column: span 2; }
    .form-grid .span3{ grid-column: span 3; }
    .label{ font-size: 12px; font-weight: 950; color:#0b4d87; letter-spacing:.6px; text-transform: uppercase; margin-bottom: 6px; display:block;}
    .input{ width:100%; height:42px; border-radius: 14px; border:1px solid #d7e6ff; background:#f7fbff; padding: 0 14px; outline:none; }
    .input:focus{ border-color:#7fb0ff; box-shadow: 0 0 0 3px rgba(127,176,255,.25); }
    .pill{ display:inline-flex; align-items:center; justify-content:center; padding: 8px 14px; border-radius: 999px; background:#eef5ff; border:1px solid #cfe1ff; font-weight:900; color:#0b4d87; font-size: 12px; }
    .btn-save{ width: 320px; max-width: 100%; height: 46px; border:0; border-radius: 999px; background:#0b4d87; color:#fff; font-weight: 950; letter-spacing:.3px; box-shadow: 0 16px 30px rgba(11,77,135,.25); cursor:pointer; }
    .btn-save:hover{ filter: brightness(1.03); transform: translateY(-1px); }
    .center{ display:flex; justify-content:center; align-items:center; gap:10px; flex-wrap:wrap; }
    .hidden{ display:none !important; }
    .btn-hist{ height: 38px; padding: 0 16px; border-radius: 999px; border:1px solid #bcd6ff; background:#fff; color:#0b4d87; font-weight:950; cursor:pointer; }
    .btn-hist:hover{ background:#eef5ff; }

    /* HISTORIAL: card fija + scroll interno */
    .history-card{ background:#fff; border-radius: 22px; box-shadow: 0 18px 40px rgba(0,0,0,.10); padding: 14px; overflow:hidden; }
    .history-head{ display:flex; justify-content:space-between; align-items:center; gap:12px; padding: 6px 6px 10px; }
    .history-sub{ color:#667; font-weight:700; }
    .tableScroll{ max-height: 260px; overflow:auto; border-radius: 16px; border:1px solid #e6f0ff; }
    .tableScroll table{ width:100%; border-collapse: separate; border-spacing:0; }
    .tableScroll thead th{ position: sticky; top:0; z-index: 2; background:#eef5ff; }
    .tbtn{ display:inline-flex; align-items:center; justify-content:center; padding: 8px 14px; border-radius: 999px; border:1px solid #bcd6ff; background:#fff; color:#0b4d87; font-weight: 950; text-decoration:none; }
    .tbtn:hover{ background:#eef5ff; }
    @media (max-width: 900px){
      .form-grid{ grid-template-columns: 1fr; }
      .form-grid .span2,.form-grid .span3{ grid-column: span 1; }
    }
  </style>
</head>
<body>

<div class="navbar">
  <div class="inner">
    <div class="brand">
      <span class="dot"></span>
      <strong>CEVIMEP</strong>
    </div>
    <div class="nav-right">
      <a class="btn-pill" href="/logout.php">Salir</a>
    </div>
  </div>
</div>

<div class="layout">

  <aside class="sidebar">
    <h3 class="menu-title">Menú</h3>
    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👤</span> Pacientes</a>
      <a href="/private/citas/index.php"><span class="ico">📅</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a class="active" href="/private/caja/index.php"><span class="ico">💳</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadisticas/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <div class="page-wrap">

      <h1 class="page-title">DESEMBOLSO</h1>

      <?php if ($flashOk): ?><div class="flash-ok"><?= h($flashOk) ?></div><?php endif; ?>
      <?php if ($flashErr): ?><div class="flash-err"><?= h($flashErr) ?></div><?php endif; ?>

      <div class="card-soft">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px;">
          <div style="font-weight:950;">Formulario</div>
          <span class="pill">Efectivo</span>
        </div>

        <form method="post" action="/private/caja/desembolso.php">
          <div class="form-grid">
            <div>
              <label class="label">Motivo</label>
              <input class="input" name="motivo" placeholder="Ej: Compra de agua, transporte" required>
            </div>
            <div>
              <label class="label">Monto</label>
              <input class="input" name="monto" placeholder="Ej: 500" inputmode="decimal" required>
            </div>
            <div>
              <label class="label">Representante</label>
              <input class="input" name="representante" placeholder="Ej: Juan Pérez" required>
            </div>

            <div class="span2">
              <label class="label">Fecha</label>
              <input class="input" value="<?= h(date("Y-m-d")) ?>" readonly>
            </div>
            <div>
              <label class="label">Sucursal</label>
              <input class="input" value="<?= h($branchName) ?>" readonly>
            </div>

            <div class="span3 center" style="padding-top:6px;">
              <button class="btn-save" type="submit">GUARDAR E IMPRIMIR</button>
          <button class="btn-hist" type="button" id="btnHistorial">HISTORIAL</button>
            </div>
          </div>
          <div style="margin-top:10px;color:#667;font-weight:700;">El acuse se abrirá en otra pestaña al guardar.</div>
        </form>
      </div>

      <div id="historialWrap" class="hidden">
      <h2 id="historial" style="text-align:center;margin:20px 0 10px;font-weight:950;color:#0b4d87;">HISTORIAL DE DESEMBOLSOS</h2>

      <div class="history-card">
        <div class="history-head">
          <div>
            <div class="history-sub">Últimos 500 desembolsos del día en esta sucursal.</div>
          </div>
          <div class="pill">TOTAL DÍA: RD$ <?= number_format($total, 2) ?></div>
        </div>

        <div class="tableScroll">
          <table class="table">
            <thead>
              <tr>
                <th style="width:90px;">ID</th>
                <th style="width:170px;">Fecha/Hora</th>
                <th>Motivo</th>
                <th style="width:140px;">Monto</th>
                <th style="width:90px;">Usuario</th>
                <th style="width:120px;">Acción</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="6" style="padding:12px;">No hay desembolsos en el día.</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <tr>
                <td>#<?= (int)$r["id"] ?></td>
                <td><?= h($r["created_at"]) ?></td>
                <td><?= h($r["motivo"]) ?></td>
                <td>RD$ <?= number_format(abs((float)$r["amount"]), 2) ?></td>
                <td><?= (int)$r["created_by"] ?></td>
                <td>
                  <a class="tbtn" target="_blank" href="/private/caja/acuse_desembolso.php?id=<?= (int)$r["id"] ?>">Detalle</a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      </div>

      <div style="height:22px;"></div>

    </div>
  </main>

</div>

<footer class="footer">© <?= h($year) ?> CEVIMEP. Todos los derechos reservados.</footer>


<script>
(function(){
  const wrap = document.getElementById('historialWrap');
  const btn = document.getElementById('btnHistorial');
  function showHist(){
    if(!wrap) return;
    wrap.classList.remove('hidden');
    // scroll suave al historial
    const anchor = document.getElementById('historial');
    if(anchor) anchor.scrollIntoView({behavior:'smooth', block:'start'});
  }
  if(btn){ btn.addEventListener('click', showHist); }

  // Si vienes con #historial o ?ok=1, mostrar automáticamente
  const params = new URLSearchParams(window.location.search);
  if(window.location.hash === '#historial' || params.has('ok')){
    if(wrap) wrap.classList.remove('hidden');
  }
})();
</script>

</body>
</html>
