<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

$user = $_SESSION["user"] ?? [];
$branch_id = (int)($user["branch_id"] ?? 0);

$batch = trim((string)($_GET["batch"] ?? ""));
if ($branch_id <= 0 || $batch === "") { http_response_code(400); die("Acuse inválido."); }

// Nombre sucursal
$branch_name = "";
try {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $branch_name = (string)($stB->fetchColumn() ?: "");
} catch (Throwable $e) {}

// Movimiento
$mov = null;
try {
  $st = $conn->prepare("
    SELECT m.created_at, m.type, m.qty, m.note, m.batch,
           i.name AS item_name
    FROM inventory_movements m
    INNER JOIN inventory_items i ON i.id = m.item_id
    WHERE m.branch_id=? AND m.batch=? 
    ORDER BY m.created_at DESC
    LIMIT 1
  ");
  $st->execute([$branch_id, $batch]);
  $mov = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if (!$mov) { http_response_code(404); die("No se encontró el acuse."); }

$type = strtoupper((string)($mov["type"] ?? ""));
$title = ($type === "OUT") ? "SALIDA" : "ENTRADA";
$qty = (int)($mov["qty"] ?? 0);
$item_name = (string)($mov["item_name"] ?? "");
$note = (string)($mov["note"] ?? "");
$created_at = (string)($mov["created_at"] ?? "");
$year = date("Y");

// Logo: respeta mayúsculas/minúsculas (Railway)
$logo = null;
$try = [
  __DIR__ . "/../../public/assets/img/CEVIMEP.png" => "/public/assets/img/CEVIMEP.png",
  __DIR__ . "/../../public/assets/img/cevimep.png" => "/public/assets/img/cevimep.png",
  __DIR__ . "/../../public/assets/img/logo.png"    => "/public/assets/img/logo.png",
];
foreach ($try as $fs => $url) { if (file_exists($fs)) { $logo = $url; break; } }
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Acuse <?= h($title) ?> | CEVIMEP</title>
  <style>
    @page{ size: 80mm auto; margin: 2mm; }
    html,body{ background:#fff; margin:0; padding:0; color:#000; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:1.25; }
    .ticket{ width:80mm; margin:0 auto; }
    .center{text-align:center;} .bold{font-weight:800;}
    .divider{ border-top:1px dashed #000; margin:2mm 0; }
    .logo{ display:block; margin:2mm auto 1mm auto; max-width:46mm; height:auto; filter:grayscale(100%); }
    .title{ font-size:16px; font-weight:900; letter-spacing:.5px; margin:0; }
    .subtitle{ font-size:10px; margin:.5mm 0 0 0; }
    .branch{ font-size:12px; font-weight:900; margin-top:2mm; }
    .line{ margin:.8mm 0; word-break:break-word; }
    .btns{ display:flex; gap:8px; justify-content:center; margin:10px 0 6px 0; }
    .btn{ border:1px solid #000; background:#fff; padding:6px 10px; border-radius:8px; font-weight:800; cursor:pointer; font-size:12px; text-decoration:none; color:#000; }
    @media print{ .btns{display:none !important;} }
  </style>
</head>
<body>
  <div class="ticket">
    <?php if ($logo): ?>
      <img class="logo" src="<?= h($logo) ?>" alt="CEVIMEP">
      <div class="center title">CEVIMEP</div>
      <div class="center subtitle">CENTRO DE VACUNACIÓN INTEGRAL</div>
      <div class="center subtitle">Y MEDICINA PREVENTIVA</div>
    <?php else: ?>
      <div class="center title">CEVIMEP</div>
    <?php endif; ?>

    <div class="center branch"><?= h($branch_name ?: "—") ?></div>

    <div class="divider"></div>

    <div class="center bold" style="font-size:14px;"><?= h($title) ?> DE INVENTARIO</div>

    <div class="divider"></div>

    <div class="line"><span class="bold">Fecha:</span> <?= h($created_at) ?></div>
    <div class="line"><span class="bold">Batch:</span> <?= h($batch) ?></div>
    <div class="line"><span class="bold">Producto:</span> <?= h($item_name) ?></div>
    <div class="line"><span class="bold">Cantidad:</span> <?= (int)$qty ?></div>
    <?php if (trim($note) !== ""): ?>
      <div class="line"><span class="bold">Nota:</span> <?= h($note) ?></div>
    <?php endif; ?>

    <div class="divider"></div>

    <div class="center" style="font-size:10px;opacity:.85;">© <?= h($year) ?> CEVIMEP. Todos los derechos reservados.</div>

    <div class="btns">
      <button class="btn" onclick="window.print()">Imprimir</button>
      <a class="btn" href="/private/inventario/<?= ($type==='OUT') ? 'salida.php' : 'entrada.php' ?>">Volver</a>
    </div>
  </div>

  <script>
    window.addEventListener('load', () => {
      setTimeout(() => { window.print(); }, 250);
    });
  </script>
</body>
</html>
