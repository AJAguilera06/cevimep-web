<?php

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

date_default_timezone_set("America/Santo_Domingo");

$user = $_SESSION["user"] ?? [];
$sucursalId = (int)($user["branch_id"] ?? 0);
$id = (int)($_GET["id"] ?? 0);

if ($sucursalId <= 0) die("Sucursal inválida.");
if ($id <= 0) die("Factura inválida.");

function logoUrl(){
    $candidatos = [
        "/assets/img/logo.png",
        "/assets/img/cevimep.png",
        "/assets/img/logo-cevimep.png",
        "/assets/logo.png"
    ];

    foreach ($candidatos as $url) {
        if (file_exists($_SERVER["DOCUMENT_ROOT"] . $url)) {
            return $url;
        }
    }

    return "";
}

$stmt = $conn->prepare("
    SELECT
        i.*,
        p.first_name,
        p.last_name,
        b.name AS branch_name,
        u.full_name AS created_by_name
    FROM invoices i
    INNER JOIN patients p ON p.id = i.patient_id
    INNER JOIN branches b ON b.id = i.branch_id
    LEFT JOIN users u ON u.id = i.created_by
    WHERE i.id = :id
      AND i.branch_id = :bid
    LIMIT 1
");

$stmt->execute([
    "id" => $id,
    "bid" => $sucursalId
]);

$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) die("Factura no encontrada.");

$stmt = $conn->prepare("
    SELECT
        ii.*,
        COALESCE(inv.name, inv.item_name, inv.descripcion, '-') AS product_name
    FROM invoice_items ii
    LEFT JOIN inventory_items inv ON inv.id = ii.item_id
    WHERE ii.invoice_id = :id
    ORDER BY ii.id ASC
");

$stmt->execute(["id" => $id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$logo = logoUrl();

$fechaFactura = $invoice["invoice_date"] ?? "";
if (!$fechaFactura && !empty($invoice["created_at"])) {
    $fechaFactura = date("Y-m-d H:i", strtotime($invoice["created_at"]));
}

$paciente = trim(($invoice["first_name"] ?? "") . " " . ($invoice["last_name"] ?? ""));
$representante = trim((string)($invoice["representative"] ?? ""));
$creadaPor = trim((string)($invoice["created_by_name"] ?? ""));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Factura #<?= (int)$invoice["id"] ?></title>

<style>
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    font-family:Arial, Helvetica, sans-serif;
    background:#fff;
    color:#000;
}

.ticket{
    width:80mm;
    margin:0 auto;
    padding:8px 10px;
}

.center{
    text-align:center;
}

.logo{
    width:190px;
    max-width:100%;
    display:block;
    margin:0 auto 3px;
}

.logo-text{
    font-size:22px;
    font-weight:900;
    color:#0b4d87;
    letter-spacing:1px;
}

.factura-top{
    font-size:9px;
    text-align:center;
    margin-bottom:4px;
}

.sucursal{
    font-size:18px;
    font-weight:900;
    margin-top:4px;
}

.line{
    border-top:2px dashed #999;
    margin:11px 0;
}

.info{
    font-size:14px;
    line-height:1.55;
}

.info strong{
    font-weight:900;
}

.item{
    font-size:15px;
    margin-bottom:8px;
}

.item-name{
    font-size:16px;
    margin-bottom:3px;
}

.item-qty{
    font-size:14px;
}

.total-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    font-size:18px;
    font-weight:900;
}

.footer{
    text-align:center;
    margin-top:14px;
    font-size:11px;
    color:#444;
}

.print-btn{
    position:fixed;
    top:15px;
    right:15px;
    background:#0b63b6;
    color:#fff;
    border:none;
    padding:10px 14px;
    border-radius:8px;
    font-weight:900;
    cursor:pointer;
}

@media print{
    @page{
        size:80mm auto;
        margin:0;
    }

    .print-btn{
        display:none;
    }

    .ticket{
        width:80mm;
        margin:0;
        padding:5px 8px;
    }
}
</style>
</head>

<body>

<button class="print-btn" onclick="window.print()">🖨 Imprimir</button>

<div class="ticket">

    <div class="factura-top">Factura #<?= (int)$invoice["id"] ?></div>

    <div class="center">
        <?php if($logo): ?>
            <img src="<?= h($logo) ?>" class="logo" alt="CEVIMEP">
        <?php else: ?>
            <div class="logo-text">CEVIMEP</div>
        <?php endif; ?>

        <div class="sucursal"><?= h($invoice["branch_name"] ?? "") ?></div>
    </div>

    <div class="line"></div>

    <div class="info">
        <div><strong>Factura:</strong> #<?= (int)$invoice["id"] ?></div>
        <div><strong>Fecha:</strong> <?= h($fechaFactura) ?></div>
        <div><strong>Paciente:</strong> <?= h($paciente) ?></div>

        <?php if($representante !== ""): ?>
            <div><strong>Representante:</strong> <?= h($representante) ?></div>
        <?php endif; ?>

        <?php if($creadaPor !== ""): ?>
            <div><strong>Facturada por:</strong> <?= h($creadaPor) ?></div>
        <?php endif; ?>

        <div><strong>Pago:</strong> <?= h($invoice["payment_method"] ?? "") ?></div>
    </div>

    <div class="line"></div>

    <?php if(empty($items)): ?>
        <div class="item">
            <div class="item-name">Producto no registrado</div>
            <div class="item-qty">Cantidad: 1</div>
        </div>
    <?php else: ?>
        <?php foreach($items as $item): ?>
            <div class="item">
                <div class="item-name"><?= h($item["product_name"] ?? "-") ?></div>
                <div class="item-qty">
                    Cantidad: <?= (int)($item["qty"] ?? $item["quantity"] ?? 1) ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="line"></div>

    <div class="total-row">
        <span>TOTAL A PAGAR</span>
        <span>RD$ <?= number_format((float)$invoice["total"], 2) ?></span>
    </div>

    <div class="line"></div>

    <div class="footer">
        © <?= date("Y") ?> CEVIMEP. Todos los derechos reservados.
    </div>

</div>

<script>
window.onload = function(){
    setTimeout(function(){
        window.print();
    }, 500);
};
</script>

</body>
</html>