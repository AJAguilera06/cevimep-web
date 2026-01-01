<?php
session_start();
if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }

require_once __DIR__ . "/../../config/db.php";
$conn = $pdo;

$user = $_SESSION["user"];
$branch_id = (int)($user["branch_id"] ?? 0);

$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) { echo "Factura inválida"; exit; }

$discount_qs = isset($_GET["discount"]) ? (float)$_GET["discount"] : 0.0;

$st = $conn->prepare("
  SELECT i.*,
         COALESCE(b.name,'') AS branch_name,
         TRIM(CONCAT(p.first_name,' ',p.last_name)) AS patient_name
  FROM invoices i
  LEFT JOIN branches b ON b.id=i.branch_id
  LEFT JOIN patients p ON p.id=i.patient_id
  WHERE i.id=? AND i.branch_id=?
  LIMIT 1
");
$st->execute([$id, $branch_id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) { echo "No encontrada"; exit; }

$stL = $conn->prepare("
  SELECT ii.qty, ii.unit_price, ii.line_total, it.name AS product
  FROM invoice_items ii
  JOIN inventory_items it ON it.id=ii.item_id
  WHERE ii.invoice_id=?
  ORDER BY ii.id ASC
");
$stL->execute([$id]);
$lines = $stL->fetchAll(PDO::FETCH_ASSOC);

/* ✅ Subtotal que se imprime = subtotal guardado (ya incluye 5% si fue tarjeta) */
$printedSubtotal = (float)$inv["subtotal"];

/* Descuento */
$discount = 0.0;
if (isset($inv["discount_amount"])) {
  $discount = (float)$inv["discount_amount"];
} elseif ($discount_qs > 0) {
  $discount = $discount_qs;
} else {
  $discount = max(0.0, round($printedSubtotal - (float)$inv["total"], 2));
}

$logoPath = "../../assets/img/CEVIMEP.png";
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Factura #<?= (int)$inv["id"] ?></title>
  <style>
    @page { size: 80mm auto; margin: 4mm; }
    body { margin:0; font-family: Arial, sans-serif; }
    .ticket { width: 80mm; }
    .center { text-align:center; }
    .muted { color:#333; font-size:12px; }
    .h { font-weight:900; }
    hr { border:none; border-top:1px dashed #000; margin:6px 0; }
    table { width:100%; border-collapse:collapse; }
    td { font-size:12px; padding:2px 0; vertical-align:top; }
    .r { text-align:right; }
    .tot { font-size:13px; font-weight:900; }
    .small { font-size:11px; }
  </style>
</head>
<body onload="window.print()">
  <div class="ticket">
    <div class="center">
      <img src="<?= $logoPath ?>" alt="CEVIMEP" style="max-width:60mm;height:auto;">
      <div class="h">CEVIMEP</div>
      <div class="muted"><?= htmlspecialchars($inv["branch_name"] ?: "Sucursal") ?></div>
    </div>

    <hr>

    <div class="small">
      <div><span class="h">Factura:</span> #<?= (int)$inv["id"] ?></div>
      <div><span class="h">Fecha:</span> <?= htmlspecialchars($inv["invoice_date"]) ?></div>
      <div><span class="h">Paciente:</span> <?= htmlspecialchars($inv["patient_name"] ?: "-") ?></div>
      <div><span class="h">Pago:</span> <?= htmlspecialchars($inv["payment_method"]) ?></div>
    </div>

    <hr>

    <!-- ✅ LISTA DE PRODUCTOS SIN PRECIOS -->
    <table>
      <?php foreach ($lines as $l): ?>
        <tr>
          <td>
            <?= htmlspecialchars($l["product"]) ?><br>
            <span class="small">Cantidad: <?= (int)$l["qty"] ?></span>
          </td>
          <td class="r"></td> <!-- vacío para que NO salga 1,500.00 -->
        </tr>
      <?php endforeach; ?>
    </table>

    <hr>

    <table>
      <tr><td>Subtotal</td><td class="r"><?= number_format($printedSubtotal,2) ?></td></tr>
      <?php if ($discount > 0): ?>
        <tr><td>Descuento</td><td class="r">-<?= number_format($discount,2) ?></td></tr>
      <?php endif; ?>
      <tr><td class="tot">TOTAL</td><td class="r tot"><?= number_format((float)$inv["total"],2) ?></td></tr>

      <?php if ($inv["payment_method"] === "EFECTIVO"): ?>
        <tr><td>Efectivo recibido</td><td class="r"><?= number_format((float)$inv["cash_received"],2) ?></td></tr>
        <tr><td class="h">Devuelta</td><td class="r h"><?= number_format((float)$inv["change_due"],2) ?></td></tr>
      <?php endif; ?>
    </table>

    <hr>
    <div class="center small">© 2025 CEVIMEP. Todos los derechos reservados.</div>
  </div>
</body>
</html>
