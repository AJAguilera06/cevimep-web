<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function money($n){ return number_format((float)$n, 2); }

$user = $_SESSION["user"];
$branch_id = (int)$user["branch_id"];

$id  = (int)($_GET["id"] ?? 0);
$rep = trim($_GET["rep"] ?? "");

if ($id <= 0) { die("Factura inválida."); }

/* =========================
   FACTURA
========================= */
$sql = "
SELECT i.*,
       p.full_name AS patient_name,
       b.name AS branch_name
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
   ITEMS
========================= */
$stI = $conn->prepare("
  SELECT product_name, quantity
  FROM invoice_items
  WHERE invoice_id = ?
  ORDER BY id ASC
");
$stI->execute([$id]);
$items = $stI->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   DATOS
========================= */
$fecha   = $inv["created_at"];
$paciente= $inv["patient_name"];
$sucursal= $inv["branch_name"];
$pago    = strtoupper($inv["payment_method"]);
$total   = $inv["total"];
$year    = date("Y");

$logo = "../../public/assets/img/cevimep.png";
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Factura #<?= $id ?></title>

<style>
@page{ size:80mm auto; margin:2mm; }
body{
  margin:0;
  font-family: Arial, Helvetica, sans-serif;
  font-size:12px;
  color:#000;
}
.ticket{ width:80mm; margin:auto; }
.center{text-align:center;}
.bold{font-weight:700;}
.divider{ border-top:1px dashed #000; margin:2mm 0; }
.logo{ max-width:46mm; display:block; margin:2mm auto; }
.item{ margin:1mm 0; }
.total{
  font-size:14px;
  font-weight:900;
  display:flex;
  justify-content:space-between;
}
.footer{ font-size:10px; text-align:center; margin-top:2mm; }
@media print{ button{display:none;} }
</style>
</head>

<body>
<div class="ticket">

<img src="<?= $logo ?>" class="logo">

<div class="center bold" style="font-size:16px;">CEVIMEP</div>
<div class="center" style="font-size:10px;">CENTRO DE VACUNACIÓN INTEGRAL</div>
<div class="center" style="font-size:10px;">Y MEDICINA PREVENTIVA</div>

<div class="center bold"><?= h($sucursal) ?></div>

<div class="divider"></div>

<div><b>Factura:</b> #<?= $id ?></div>
<div><b>Fecha:</b> <?= h($fecha) ?></div>
<div><b>Paciente:</b> <?= h($paciente) ?></div>
<?php if($rep): ?>
<div><b>Representante:</b> <?= h($rep) ?></div>
<?php endif; ?>
<div><b>Pago:</b> <?= h($pago) ?></div>

<div class="divider"></div>

<?php foreach($items as $it): ?>
  <div class="item">
    <div class="bold"><?= h($it["product_name"]) ?></div>
    <div>Cantidad: <?= (int)$it["quantity"] ?></div>
  </div>
  <div class="divider"></div>
<?php endforeach; ?>

<div class="total">
  <span>TOTAL A PAGAR</span>
  <span><?= money($total) ?></span>
</div>

<div class="divider"></div>

<div class="footer">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>

<button onclick="window.print()">Imprimir</button>

</div>
</body>
</html>
