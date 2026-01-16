<?php
session_start();
if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }

require_once __DIR__ . "/../../config/db.php";
$conn = $pdo;

$user = $_SESSION["user"];
$branch_id = (int)($user["branch_id"] ?? 0);

$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) { echo "Factura inválida"; exit; }

$rep_qs = trim((string)($_GET["rep"] ?? ""));

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
  SELECT ii.qty, it.name AS product
  FROM invoice_items ii
  JOIN inventory_items it ON it.id=ii.item_id
  WHERE ii.invoice_id=?
  ORDER BY ii.id ASC
");
$stL->execute([$id]);
$lines = $stL->fetchAll(PDO::FETCH_ASSOC);

// Representante: primero DB, luego querystring
$representative = "";
if (isset($inv["representative_name"])) $representative = trim((string)$inv["representative_name"]);
if ($representative === "" && isset($inv["representative"])) $representative = trim((string)$inv["representative"]);
if ($representative === "") $representative = $rep_qs;

// Fecha + hora (si existe created_at/created_on, usamos eso; si no, usamos hora actual)
$dateToPrint = (string)($inv["invoice_date"] ?? date("Y-m-d"));
$timeToPrint = date("H:i");
if (!empty($inv["created_at"])) {
  $ts = strtotime((string)$inv["created_at"]);
  if ($ts) $timeToPrint = date("H:i", $ts);
}
if (!empty($inv["created_on"])) {
  $ts = strtotime((string)$inv["created_on"]);
  if ($ts) $timeToPrint = date("H:i", $ts);
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
      <div><span class="h">Fecha:</span> <?= htmlspecialchars($dateToPrint) ?> <?= htmlspecialchars($timeToPrint) ?></div>
      <div><span class="h">Paciente:</span> <?= htmlspecialchars($inv["patient_name"] ?: "-") ?></div>
      <?php if ($representative !== ""): ?>
        <div><span class="h">Representante:</span> <?= htmlspecialchars($representative) ?></div>
      <?php endif; ?>
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
          <td class="r"></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <hr>

    <table>
      <tr><td class="tot">TOTAL A PAGAR</td><td class="r tot"><?= number_format((float)$inv["total"],2) ?></td></tr>
    </table>

    <hr>
    <div class="center small">© <?= date("Y") ?> CEVIMEP. Todos los derechos reservados.</div>
  </div>
</body>
</html>
