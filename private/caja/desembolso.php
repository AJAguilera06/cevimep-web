<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/caja_lib.php";

if (!isset($_SESSION["user"])) { header("Location: /login.php"); exit; }

$user = $_SESSION["user"];
$isAdmin  = (($user["role"] ?? "") === "admin");
$branchId = (int)($user["branch_id"] ?? 0);
$userId   = (int)($user["id"] ?? 0);

if (!$isAdmin && $branchId <= 0) { header("Location: /logout.php"); exit; }

date_default_timezone_set("America/Santo_Domingo");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function money($n){ return number_format((float)$n, 2, ".", ","); }

$branchMap = [
  1 => "CEVIMEP Moca",
  2 => "CEVIMEP La Vega",
  3 => "CEVIMEP Salcedo",
  4 => "CEVIMEP Santiago",
  5 => "CEVIMEP Mao Valverde",
  6 => "CEVIMEP Puerto Plata",
];
$branchName = $branchMap[$branchId] ?? ("Sucursal #" . $branchId);

$today    = date("Y-m-d");
$dayStart = $today . " 00:00:00";
$dayEnd   = $today . " 23:59:59";

/** Sesión de caja del turno */
$sessionId = caja_get_or_open_current_session($pdo, $branchId, $userId);

/** =========================
 *  ACUSE (mismo archivo)
 *  ========================= */
if (isset($_GET["acuse"])) {
  $id = (int)$_GET["acuse"];

  $st = $pdo->prepare("SELECT id, session_id, motivo, metodo_pago, amount, created_at, created_by
                       FROM cash_movements
                       WHERE id=? AND type='desembolso' LIMIT 1");
  $st->execute([$id]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  if (!$r) { http_response_code(404); echo "Acuse no encontrado."; exit; }

  $monto = abs((float)$r["amount"]);
  ?>
  <!doctype html>
  <html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acuse de Desembolso #<?= h($r["id"]) ?></title>
    <style>
      body{font-family:Arial,Helvetica,sans-serif;margin:0;background:#eef2f7;padding:18px;}
      .card{max-width:720px;margin:0 auto;background:#fff;border-radius:18px;padding:18px;
            box-shadow:0 12px 34px rgba(2,6,23,.10);border:1px solid #e6eef7;}
      .top{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;}
      .title{font-size:20px;font-weight:900;color:#0b1b34;}
      .sub{color:#64748b;font-weight:700;font-size:13px;}
      .pill{background:#eaf1ff;color:#0b4bd7;border-radius:999px;padding:6px 10px;font-weight:900;font-size:12px;}
      .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;}
      .box{border:1px solid #e6eefc;border-radius:14px;padding:12px;}
      .lbl{font-size:12px;color:#64748b;font-weight:900;letter-spacing:.4px;text-transform:uppercase;margin-bottom:6px;}
      .val{font-size:16px;font-weight:900;color:#0b1b34;}
      .btns{display:flex;gap:10px;margin-top:14px;}
      .btn{border:0;border-radius:14px;padding:10px 14px;font-weight:900;cursor:pointer}
      .btnPrint{background:#0b1b34;color:#fff;}
      .btnClose{background:#eef2f8;color:#0b1b34;text-decoration:none;display:inline-flex;align-items:center;}
      @media print{ body{background:#fff;padding:0} .btns{display:none} .card{box-shadow:none;border:0} }
    </style>
  </head>
  <body>
    <div class="card">
      <div class="top">
        <div>
          <div class="title">Acuse de Desembolso</div>
          <div class="sub">CEVIMEP • Movimiento #<?= h($r["id"]) ?></div>
        </div>
        <div class="pill">DESEMBOLSO</div>
      </div>

      <div class="grid">
        <div class="box"><div class="lbl">Motivo</div><div class="val"><?= h($r["motivo"]) ?></div></div>
        <div class="box"><div class="lbl">Monto</div><div class="val">RD$ <?= money($monto) ?></div></div>
        <div class="box"><div class="lbl">Método de pago</div><div class="val"><?= h($r["metodo_pago"]) ?></div></div>
        <div class="box"><div class="lbl">Usuario</div><div class="val">#<?= h($r["created_by"]) ?></div></div>
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

/** =========================
 *  GUARDAR (POST)
 *  ========================= */
$success = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $motivo = trim($_POST["motivo"] ?? "");
  $monto  = (float)($_POST["monto"] ?? 0);
  $representante = trim($_POST["representante"] ?? "");

  if ($motivo === "") $error = "Debe indicar el motivo.";
  elseif ($monto <= 0) $error = "El monto debe ser mayor que 0.";
  elseif ($representante === "") $error = "Debe indicar el representante.";

  if ($error === "") {
    $amount = -abs($monto); // ✅ desembolso siempre negativo
    $metodo = "efectivo";

    $st = $pdo->prepare("INSERT INTO cash_movements (session_id, type, motivo, metodo_pago, amount, created_at, created_by)
                         VALUES (?, 'desembolso', ?, ?, ?, NOW(), ?)");
    $st->execute([$sessionId, $motivo, $metodo, $amount, $userId]);
    $newId = (int)$pdo->lastInsertId();

    // Abre acuse en otra pestaña y vuelve al historial
    echo "<script>
      window.open('desembolso.php?acuse={$newId}', '_blank');
      window.location.href = 'desembolso.php#historial';
    </script>";
    exit;
  }
}

/** =========================
 *  HISTORIAL (hoy, esta sesión)
 *  ========================= */
$hist = [];
$totalDay = 0.0;

$st = $pdo->prepare("SELECT id, created_at, motivo, amount, created_by
                     FROM cash_movements
                     WHERE session_id=? AND type='desembolso' AND created_at BETWEEN ? AND ?
                     ORDER BY id DESC
                     LIMIT 500");
$st->execute([$sessionId, $dayStart, $dayEnd]);
$hist = $st->fetchAll(PDO::FETCH_ASSOC);

$st2 = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM cash_movements
                      WHERE session_id=? AND type='desembolso' AND created_at BETWEEN ? AND ?");
$st2->execute([$sessionId, $dayStart, $dayEnd]);
$totalDay = (float)$st2->fetchColumn();

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Desembolso</title>
  <link rel="stylesheet" href="/public/assets/css/dashboard.css">
  <style>
    /* Mantén tu estilo general; esto solo arregla el historial dentro */
    .historyTitle{ text-align:center;font-weight:900;color:#0b3aa7;letter-spacing:.3px;margin:22px 0 12px; }
    .historyCard{
      max-width:860px;margin:0 auto;background:#fff;border:1px solid #e6eef7;border-radius:22px;
      padding:16px;box-shadow:0 18px 40px rgba(2,6,23,.08);
      overflow:hidden; /* ✅ importante para que no se salga */
    }
    .historyHeader{ display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:10px; }
    .pillSoft{ padding:8px 12px;border-radius:999px;font-weight:900;color:#1e40af;border:1px solid #bfdbfe;background:#fff; }
    .historyScroll{
      margin-top:10px;
      max-height: clamp(140px, 22vh, 240px); /* ✅ la card nunca crece */
      overflow:auto;
      border-radius:16px;
      border:1px solid #e6eef7;
    }
    .historyScroll table{ width:100%; border-collapse:separate; border-spacing:0; }
    .historyScroll thead th{
      position:sticky; top:0; z-index:2;
      background:#f3f7ff;
    }
  </style>
</head>

<body>
  <?php include __DIR__ . "/../partials/sidebar.php"; ?>

  <main class="main">
    <div class="pageHeader">
      <h1 class="pageTitle">DESEMBOLSO</h1>
    </div>

    <div class="cardBox" style="max-width:860px;margin:0 auto;">
      <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

      <form method="post" class="formGrid">
        <div class="formGroup">
          <label>MOTIVO</label>
          <input type="text" name="motivo" placeholder="Ej: Compra de agua, transporte" value="<?= h($_POST["motivo"] ?? "") ?>" required>
        </div>

        <div class="formGroup">
          <label>MONTO</label>
          <input type="number" step="0.01" name="monto" placeholder="Ej: 500" value="<?= h($_POST["monto"] ?? "") ?>" required>
        </div>

        <div class="formGroup">
          <label>REPRESENTANTE</label>
          <input type="text" name="representante" placeholder="Ej: Juan Pérez" value="<?= h($_POST["representante"] ?? "") ?>" required>
        </div>

        <div class="formGroup">
          <label>FECHA</label>
          <input type="date" value="<?= h($today) ?>" disabled>
        </div>

        <div class="formGroup">
          <label>SUCURSAL</label>
          <input type="text" value="<?= h($branchName) ?>" disabled>
        </div>

        <div class="formActions" style="grid-column:1/-1;display:flex;justify-content:center;margin-top:10px;">
          <button type="submit" class="btnPrimary">GUARDAR E IMPRIMIR</button>
        </div>
      </form>
    </div>

    <h2 class="historyTitle" id="historial">HISTORIAL DE DESEMBOLSOS</h2>

    <div class="historyCard">
      <div class="historyHeader">
        <div class="muted">Últimos 500 desembolsos del día en esta sucursal.</div>
        <div class="pillSoft">TOTAL DÍA: RD$ <?= money($totalDay) ?></div>
      </div>

      <div class="historyScroll">
        <table class="table">
          <thead>
            <tr>
              <th style="width:80px;">ID</th>
              <th>Fecha/Hora</th>
              <th>Motivo</th>
              <th style="width:120px;">Monto</th>
              <th style="width:90px;">Usuario</th>
              <th style="width:110px;">Acción</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$hist): ?>
            <tr><td colspan="6" class="muted" style="padding:14px;">No hay desembolsos en el día.</td></tr>
          <?php else: foreach ($hist as $r): ?>
            <tr>
              <td>#<?= h($r["id"]) ?></td>
              <td><?= h($r["created_at"]) ?></td>
              <td><?= h($r["motivo"]) ?></td>
              <td>RD$ <?= money(abs((float)$r["amount"])) ?></td>
              <td><?= h($r["created_by"]) ?></td>
              <td>
                <a class="btnSoft" target="_blank" href="desembolso.php?acuse=<?= (int)$r["id"] ?>">Detalle</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div style="height:40px;"></div>
  </main>
</body>
</html>
