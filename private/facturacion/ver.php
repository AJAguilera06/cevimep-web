<?php

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

function h($s){
    return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

function firstNonEmpty(...$vals): string {
    foreach ($vals as $v) {
        $s = trim((string)$v);
        if ($s !== '') {
            return $s;
        }
    }
    return '';
}

$user = $_SESSION["user"] ?? [];
$sucursalId = (int)($user["branch_id"] ?? 0);

$id = (int)($_GET["id"] ?? 0);

if ($sucursalId <= 0) {
    die("Sucursal inválida.");
}

if ($id <= 0) {
    die("Factura inválida.");
}

/**
 * FACTURA
 */
$stmt = $conn->prepare("
    SELECT
        i.*,
        p.first_name,
        p.last_name,
        b.name AS branch_name,
        u.full_name AS created_by_name
    FROM invoices i

    INNER JOIN patients p
        ON p.id = i.patient_id

    INNER JOIN branches b
        ON b.id = i.branch_id

    LEFT JOIN users u
        ON u.id = i.created_by

    WHERE i.id = :id
    AND i.branch_id = :bid

    LIMIT 1
");

$stmt->execute([
    "id" => $id,
    "bid" => $sucursalId
]);

$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    die("Factura no encontrada.");
}

$invoiceStatus = strtoupper((string)($invoice["status"] ?? "ACTIVE"));
$isCancelled = ($invoiceStatus === "CANCELLED" || $invoiceStatus === "ANULADA");
$cancelReason = trim((string)($invoice["cancel_reason"] ?? ""));
$cancelledAt = trim((string)($invoice["cancelled_at"] ?? ""));

$facturaCode = trim((string)($invoice["invoice_code"] ?? ""));
if ($facturaCode === "") {
    $facturaCode = "#" . (string)((int)$invoice["id"]);
}

/**
 * ITEMS
 */
$stmt = $conn->prepare("
    SELECT
        ii.*,
        inv.name AS product_name
    FROM invoice_items ii

    LEFT JOIN inventory_items inv
        ON inv.id = ii.item_id

    WHERE ii.invoice_id = :id

    ORDER BY ii.id ASC
");

$stmt->execute([
    "id" => $id
]);

$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * REPRESENTANTE
 * Primero toma la columna representative si existe, luego busca en notes.
 */
$representanteFromNotes = "";
if (!empty($invoice["notes"]) && preg_match('/Representante:\s*([^|\r\n]+)/i', (string)$invoice["notes"], $m)) {
    $representanteFromNotes = trim($m[1]);
}

$representante = firstNonEmpty(
    $invoice["representative"] ?? "",
    $invoice["representante"] ?? "",
    $representanteFromNotes
);

/**
 * PACIENTE
 */
$paciente = trim(
    ($invoice["first_name"] ?? "") . " " .
    ($invoice["last_name"] ?? "")
);

/**
 * FECHA CORRECTA RD
 */
$fecha = "";

if (!empty($invoice["created_at"])) {

    $dt = new DateTime(
        $invoice["created_at"],
        new DateTimeZone("UTC")
    );

    $dt->setTimezone(
        new DateTimeZone("America/Santo_Domingo")
    );

    $fecha = $dt->format("Y-m-d h:i A");
}

/**
 * LOGO
 */
$logo = "/assets/img/CEVIMEP.png";

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">

<title>
Factura <?= h($facturaCode) ?>
</title>

<style>

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    background:#fff;
    color:#000;
    font-family:Arial, Helvetica, sans-serif;
}

.ticket{
    width:80mm;
    margin:0 auto;
    padding:8px;
}

.center{
    text-align:center;
}

.logo{
    width:210px;
    max-width:100%;
    display:block;
    margin:0 auto 5px;
}

.sucursal{
    font-size:20px;
    font-weight:900;
    margin-top:4px;
}

.line{
    border-top:2px dashed #999;
    margin:12px 0;
}

.info{
    font-size:14px;
    line-height:1.6;
}

.info strong{
    font-weight:900;
}

.item{
    margin-bottom:10px;
}

.item-name{
    font-size:16px;
    font-weight:700;
}

.item-qty{
    font-size:14px;
    margin-top:2px;
}

.total{
    display:flex;
    justify-content:space-between;
    align-items:center;
    font-size:18px;
    font-weight:900;
}

.footer{
    text-align:center;
    margin-top:16px;
    font-size:11px;
    color:#444;
}

.cancelled-banner{
    border:3px solid #a00000;
    color:#a00000;
    background:#fff1f1;
    padding:10px;
    text-align:center;
    font-weight:900;
    font-size:18px;
    margin:10px 0;
}

.cancelled-note{
    font-size:12px;
    margin-top:5px;
    color:#7a0000;
    line-height:1.4;
}

.print-btn{
    position:fixed;
    top:10px;
    right:10px;
    background:#0b63b6;
    color:#fff;
    border:none;
    border-radius:8px;
    padding:10px 15px;
    cursor:pointer;
    font-weight:900;
    z-index:999;
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
        padding:5px;
    }
}

</style>
</head>

<body>

<button
    class="print-btn"
    onclick="window.print()"
>
🖨 Imprimir
</button>

<div class="ticket">

    <div class="center">

        <img
            src="<?= h($logo) ?>"
            class="logo"
            alt="CEVIMEP"
        >

        <div class="sucursal">
            <?= h($invoice["branch_name"]) ?>
        </div>

    </div>

    <div class="line"></div>

    <?php if ($isCancelled): ?>
        <div class="cancelled-banner">
            FACTURA ANULADA
            <?php if ($cancelReason !== "" || $cancelledAt !== ""): ?>
                <div class="cancelled-note">
                    <?php if ($cancelledAt !== ""): ?>
                        Fecha de anulación: <?= h($cancelledAt) ?><br>
                    <?php endif; ?>
                    <?php if ($cancelReason !== ""): ?>
                        Motivo: <?= h($cancelReason) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="line"></div>
    <?php endif; ?>

    <div class="info">

        <div>
            <strong>Factura:</strong>
            <?= h($facturaCode) ?>
        </div>

        <div>
            <strong>Fecha:</strong>
            <?= h($fecha) ?>
        </div>

        <div>
            <strong>Paciente:</strong>
            <?= h($paciente) ?>
        </div>

        <?php if($representante !== ""): ?>
        <div>
            <strong>Representante:</strong>
            <?= h($representante) ?>
        </div>
        <?php endif; ?>

        <?php if(!empty($invoice["created_by_name"])): ?>
        <div>
            <strong>Facturado por:</strong>
            <?= h($invoice["created_by_name"]) ?>
        </div>
        <?php endif; ?>

        <div>
            <strong>Pago:</strong>
            <?= h($invoice["payment_method"]) ?>
        </div>

        <div>
            <strong>Estado:</strong>
            <?= $isCancelled ? "ANULADA" : "ACTIVA" ?>
        </div>

    </div>

    <div class="line"></div>

    <?php if(empty($items)): ?>

        <div class="item">

            <div class="item-name">
                Producto no encontrado
            </div>

            <div class="item-qty">
                Cantidad: 1
            </div>

        </div>

    <?php else: ?>

        <?php foreach($items as $item): ?>

            <div class="item">

                <div class="item-name">
                    <?= h($item["product_name"]) ?>
                </div>

                <div class="item-qty">
                    Cantidad:
                    <?= (int)$item["qty"] ?>
                </div>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

    <div class="line"></div>

    <div class="total">

        <span>TOTAL A PAGAR</span>

        <span>
            RD$
            <?= number_format((float)$invoice["total"], 2) ?>
        </span>

    </div>

    <div class="line"></div>

    <div class="footer">
        © <?= date("Y") ?> CEVIMEP.
        Todos los derechos reservados.
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