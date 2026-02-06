<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function money($n): string { return number_format((float)$n, 2); }

$user = $_SESSION["user"] ?? [];
$branch_id = (int)($user["branch_id"] ?? 0);

$id  = (int)($_GET["id"] ?? 0);
$rep = trim((string)($_GET["rep"] ?? ""));

if ($branch_id <= 0) { die("Sucursal inválida."); }
if ($id <= 0) { die("Factura inválida."); }

function colExists(PDO $conn, string $table, string $col): bool {
  $db = (string)$conn->query("SELECT DATABASE()")->fetchColumn();
  $st = $conn->prepare("
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?
  ");
  $st->execute([$db, $table, $col]);
  return (int)$st->fetchColumn() > 0;
}

/* =========================
   Elegir columna nombre paciente (patients)
========================= */
$patientNameExpr = "''";
if (colExists($conn, "patients", "full_name")) {
  $patientNameExpr = "p.full_name";
} elseif (colExists($conn, "patients", "name")) {
  $patientNameExpr = "p.name";
} elseif (colExists($conn, "patients", "nombre")) {
  $patientNameExpr = "p.nombre";
} elseif (colExists($conn, "patients", "nombres") && colExists($conn, "patients", "apellidos")) {
  $patientNameExpr = "CONCAT(p.nombres,' ',p.apellidos)";
} elseif (colExists($conn, "patients", "first_name") && colExists($conn, "patients", "last_name")) {
  $patientNameExpr = "CONCAT(p.first_name,' ',p.last_name)";
}

/* =========================
   CABECERA FACTURA (invoices)
========================= */
$selectExtra = [];

// Campos opcionales en invoices
if (colExists($conn, 'invoices', 'ars')) $selectExtra[] = "i.ars";
if (colExists($conn, 'invoices', 'no_libro')) $selectExtra[] = "i.no_libro";
if (colExists($conn, 'invoices', 'numero_afiliado')) $selectExtra[] = "i.numero_afiliado";
if (colExists($conn, 'invoices', 'clinica_referencia')) $selectExtra[] = "i.clinica_referencia";
if (colExists($conn, 'invoices', 'medico_refiere')) $selectExtra[] = "i.medico_refiere";
if (colExists($conn, 'invoices', 'representante')) $selectExtra[] = "i.representante";
if (colExists($conn, 'invoices', 'telefono')) $selectExtra[] = "i.telefono";
if (colExists($conn, 'invoices', 'hora')) $selectExtra[] = "i.hora";
if (colExists($conn, 'invoices', 'invoice_time')) $selectExtra[] = "i.invoice_time";
if (colExists($conn, 'invoices', 'created_at')) $selectExtra[] = "i.created_at";

$extraSql = $selectExtra ? (", " . implode(", ", $selectExtra)) : "";

$sql = "
SELECT i.id, i.invoice_date, i.payment_method, i.subtotal, i.total,
       i.patient_id,
       {$patientNameExpr} AS patient_name,
       b.name AS branch_name
       {$extraSql}
FROM invoices i
LEFT JOIN patients p ON p.id = i.patient_id
LEFT JOIN branches b ON b.id = i.branch_id
WHERE i.id = ? AND i.branch_id = ?
LIMIT 1
";
$st = $conn->prepare($sql);
$st->execute([$id, $branch_id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);

if (!$inv) {
  die("Error cargando factura.");
}

/* =========================
   ITEMS (invoice_items -> inventory_items)
========================= */
$itemNameExpr = "it.name";
if (!colExists($conn, "inventory_items", "name")) {
  if (colExists($conn, "inventory_items", "product_name")) $itemNameExpr = "it.product_name";
  elseif (colExists($conn, "inventory_items", "nombre")) $itemNameExpr = "it.nombre";
}

$sqlItems = "
SELECT {$itemNameExpr} AS item_name,
       ii.qty,
       ii.unit_price,
       ii.line_total
FROM invoice_items ii
LEFT JOIN inventory_items it ON it.id = ii.item_id
WHERE ii.invoice_id = ?
ORDER BY ii.id ASC
";
$stI = $conn->prepare($sqlItems);
$stI->execute([$id]);
$items = $stI->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   DATOS
========================= */
$fecha    = (string)($inv["invoice_date"] ?? "");
$paciente = (string)($inv["patient_name"] ?? "");
$sucursal = (string)($inv["branch_name"] ?? "");
$pago     = strtoupper((string)($inv["payment_method"] ?? "EFECTIVO"));
$total    = (float)($inv["total"] ?? 0);
$year     = date("Y");

/* =========================
   CAMPOS EXTRA (ticket)
   - Se intenta tomar primero de invoices, y si no existe/está vacío, de patients.
========================= */
$patientId = (int)($inv['patient_id'] ?? 0);
$pat = [];
if ($patientId > 0) {
  $patientCols = [];
  foreach (['ars','no_libro','numero_afiliado','clinica_referencia','medico_refiere','representante','telefono','no_telefono','phone','tel'] as $c) {
    if (colExists($conn, 'patients', $c)) $patientCols[] = $c;
  }
  if ($patientCols) {
    $stP = $conn->prepare("SELECT " . implode(',', array_map(fn($c)=>"`{$c}`", $patientCols)) . " FROM patients WHERE id=? LIMIT 1");
    $stP->execute([$patientId]);
    $pat = $stP->fetch(PDO::FETCH_ASSOC) ?: [];
  }
}

function firstNonEmpty(...$vals): string {
  foreach ($vals as $v) {
    $s = trim((string)$v);
    if ($s !== '') return $s;
  }
  return '';
}

$ars               = firstNonEmpty($inv['ars'] ?? '', $pat['ars'] ?? '');
$noLibro           = firstNonEmpty($inv['no_libro'] ?? '', $pat['no_libro'] ?? '');
$clinicaReferencia = firstNonEmpty($inv['clinica_referencia'] ?? '', $pat['clinica_referencia'] ?? '');
$medicoRefiere     = firstNonEmpty($inv['medico_refiere'] ?? '', $pat['medico_refiere'] ?? '');
$numeroAfiliado    = firstNonEmpty($inv['numero_afiliado'] ?? '', $pat['numero_afiliado'] ?? '');

// Representante: prioridad a querystring (por compatibilidad con tu flujo actual)
$representante = firstNonEmpty($rep, $inv['representante'] ?? '', $pat['representante'] ?? '');

// Teléfono: soporta distintos nombres comunes en patients
$telefono = firstNonEmpty(
  $inv['telefono'] ?? '',
  $pat['telefono'] ?? '',
  $pat['no_telefono'] ?? '',
  $pat['phone'] ?? '',
  $pat['tel'] ?? ''
);

// Hora (formato 12h RD)
$horaRaw = firstNonEmpty(
  $inv['hora'] ?? '',
  $inv['invoice_time'] ?? '',
  $inv['created_at'] ?? ''
);

if ($horaRaw === '') {
  // si invoice_date trae hora (datetime), úsala
  $horaRaw = (string)($inv['invoice_date'] ?? '');
}

$hora = '';
try {
  if ($horaRaw !== '') {
    $dt = new DateTime($horaRaw);
    $hora = $dt->format('h:i A');
  }
} catch (Throwable $e) {
  $hora = '';
}

/* =========================
   LOGO (FIX DEFINITIVO)
   - En Railway /public NO es ruta web
   - Linux es case-sensitive
   - Usamos data URI para que siempre imprima
========================= */
$logoSrc = "";
$logoCandidates = [
  __DIR__ . "/../../public/assets/img/CEVIMEP.png",
  __DIR__ . "/../../public/assets/img/cevimep.png",
  __DIR__ . "/../../public/assets/img/logo.png",
  __DIR__ . "/../../public/assets/img/Logo.png",
  __DIR__ . "/../../public/assets/img/cevimep-logo.png",
];

foreach ($logoCandidates as $path) {
  if (is_file($path)) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = "image/png";
    if ($ext === "jpg" || $ext === "jpeg") $mime = "image/jpeg";
    elseif ($ext === "webp") $mime = "image/webp";

    $data = @file_get_contents($path);
    if ($data !== false) {
      $logoSrc = "data:$mime;base64," . base64_encode($data);
    }
    break;
  }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Factura #<?= (int)$id ?></title>

<style>
  @page{ size:80mm auto; margin:2mm; }
  html,body{ margin:0; padding:0; background:#fff; color:#000; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:1.25; }
  .ticket{ width:80mm; margin:0 auto; }
  .center{text-align:center;}
  .right{text-align:right;}
  .bold{font-weight:700;}
  .divider{ border-top:1px dashed #000; margin:2mm 0; }
  .logo{ max-width:46mm; display:block; margin:2mm auto 1mm auto; }
  .title{ font-size:16px; font-weight:900; letter-spacing:.5px; margin:0; }
  .subtitle{ font-size:10px; margin:.5mm 0 0 0; }
  .branch{ font-size:12px; font-weight:900; margin-top:2mm; }
  .item{ margin:1.2mm 0; }
  .item-name{ font-weight:900; text-transform:uppercase; }
  .item-meta{ margin-top:.4mm; }
  .total{ font-size:14px; font-weight:900; display:flex; justify-content:space-between; margin-top:1mm; }
  .footer{ font-size:10px; text-align:center; margin-top:2mm; opacity:.85; }
  .btn{ margin-top:8px; border:1px solid #000; background:#fff; padding:6px 10px; border-radius:8px; font-weight:800; cursor:pointer; }
  @media print{ .btn{ display:none !important; } }
</style>
</head>

<body>
<div class="ticket">

  <?php if ($logoSrc !== ""): ?>
    <img src="<?= h($logoSrc) ?>" class="logo" alt="CEVIMEP">
  <?php endif; ?>

  <!-- Quitado texto "CEVIMEP" debajo del logo (estaba duplicado) -->
  <div class="center subtitle">CENTRO DE VACUNACIÓN INTEGRAL</div>
  <div class="center subtitle">Y MEDICINA PREVENTIVA</div>
  <div class="center branch"><?= h($sucursal) ?></div>

  <div class="divider"></div>

  <div><span class="bold">Factura:</span> #<?= (int)$id ?></div>
  <div><span class="bold">Fecha:</span> <?= h($fecha) ?></div>
  <div><span class="bold">Hora:</span> <?= h($hora !== '' ? $hora : '—') ?></div>
  <div><span class="bold">Paciente:</span> <?= h($paciente) ?></div>

  <div><span class="bold">ARS:</span> <?= h($ars !== '' ? $ars : '—') ?></div>
  <div><span class="bold">No. Libro:</span> <?= h($noLibro !== '' ? $noLibro : '—') ?></div>
  <div><span class="bold">Clínica de referencia:</span> <?= h($clinicaReferencia !== '' ? $clinicaReferencia : '—') ?></div>
  <div><span class="bold">Médico que refiere:</span> <?= h($medicoRefiere !== '' ? $medicoRefiere : '—') ?></div>
  <div><span class="bold">No. Afiliado:</span> <?= h($numeroAfiliado !== '' ? $numeroAfiliado : '—') ?></div>
  <div><span class="bold">Representante:</span> <?= h($representante !== '' ? $representante : '—') ?></div>
  <div><span class="bold">Teléfono:</span> <?= h($telefono !== '' ? $telefono : '—') ?></div>

  <div><span class="bold">Pago:</span> <?= h($pago) ?></div>

  <div class="divider"></div>

  <?php if (!$items): ?>
    <div class="center">Sin items.</div>
    <div class="divider"></div>
  <?php else: ?>
    <?php foreach ($items as $it): ?>
      <div class="item">
        <div class="item-name"><?= h($it["item_name"] ?? "") ?></div>
        <div class="item-meta"><span class="bold">Cantidad:</span> <?= (int)($it["qty"] ?? 0) ?></div>
      </div>
      <div class="divider"></div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="total">
    <span>TOTAL A PAGAR</span>
    <span><?= money($total) ?></span>
  </div>

  <div class="divider"></div>

  <div class="footer">© <?= h($year) ?> CEVIMEP. Todos los derechos reservados.</div>

  <button class="btn" onclick="window.print()">Imprimir</button>
</div>
</body>
</html>
