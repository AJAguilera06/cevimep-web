<?php

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

function h($s){
    return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

$user = $_SESSION['user'] ?? [];
$sucursalId = (int)($user['branch_id'] ?? 0);

if ($sucursalId <= 0) {
    die("Sucursal inválida.");
}

$id = (int)($_GET['id'] ?? 0);

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
        b.name AS branch_name
    FROM invoices i
    INNER JOIN patients p ON p.id = i.patient_id
    INNER JOIN branches b ON b.id = i.branch_id
    WHERE i.id = :id
    AND i.branch_id = :bid
    LIMIT 1
");

$stmt->execute([
    'id' => $id,
    'bid' => $sucursalId
]);

$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    die("Factura no encontrada.");
}

/**
 * ITEMS
 * Ajusta nombres si tu tabla cambia
 */
$items = [];

try {

    $stmt = $conn->prepare("
        SELECT *
        FROM invoice_items
        WHERE invoice_id = :id
        ORDER BY id ASC
    ");

    $stmt->execute([
        'id' => $id
    ]);

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(Exception $e){
    $items = [];
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Factura #<?= (int)$invoice['id'] ?></title>

<style>

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    font-family: Arial, Helvetica, sans-serif;
    background:#fff;
    color:#000;
}

/* TERMICA 80mm */
.ticket{
    width:80mm;
    margin:0 auto;
    padding:10px;
}

.center{
    text-align:center;
}

.logo{
    width:170px;
    margin:0 auto 8px;
    display:block;
}

.branch{
    font-size:18px;
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
    margin-bottom:4px;
}

.item-row{
    display:flex;
    justify-content:space-between;
    font-size:14px;
}

.total-box{
    margin-top:10px;
}

.total-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    font-weight:900;
    font-size:18px;
}

.footer{
    text-align:center;
    margin-top:16px;
    font-size:12px;
    color:#444;
}

.print-btn{
    position:fixed;
    top:15px;
    right:15px;
    background:#0b63b6;
    color:#fff;
    border:none;
    padding:12px 18px;
    border-radius:10px;
    font-weight:700;
    cursor:pointer;
    z-index:999;
}

@media print{

    .print-btn{
        display:none;
    }

    body{
        background:#fff;
    }

    .ticket{
        width:80mm;
        padding:0;
    }
}

</style>
</head>
<body>

<button class="print-btn" onclick="window.print()">
    🖨 Imprimir
</button>

<div class="ticket">

    <div class="center">

        <img
            src="/assets/img/logo.png"
            class="logo"
            alt="CEVIMEP"
        >

        <div class="branch">
            <?= h($invoice['branch_name']) ?>
        </div>

    </div>

    <div class="line"></div>

    <div class="info">

        <div>
            <strong>Factura:</strong>
            #<?= (int)$invoice['id'] ?>
        </div>

        <div>
            <strong>Fecha:</strong>
            <?= h(date('Y-m-d H:i', strtotime($invoice['created_at']))) ?>
        </div>

        <div>
            <strong>Paciente:</strong>
            <?= h($invoice['first_name'] . ' ' . $invoice['last_name']) ?>
        </div>

        <?php if(!empty($invoice['representative'])): ?>
        <div>
            <strong>Representante:</strong>
            <?= h($invoice['representative']) ?>
        </div>
        <?php endif; ?>

        <div>
            <strong>Pago:</strong>
            <?= h($invoice['payment_method']) ?>
        </div>

    </div>

    <div class="line"></div>

    <?php if(empty($items)): ?>

        <div class="center" style="font-size:14px;">
            No hay productos registrados
        </div>

    <?php else: ?>

        <?php foreach($items as $item): ?>

            <div class="item">

                <div class="item-name">
                    <?= h($item['description'] ?? '-') ?>
                </div>

                <div class="item-row">
                    <span>
                        Cantidad:
                        <?= (int)($item['quantity'] ?? 1) ?>
                    </span>

                    <span>
                        RD$
                        <?= number_format((float)($item['total'] ?? 0), 2) ?>
                    </span>
                </div>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

    <div class="line"></div>

    <div class="total-box">

        <div class="total-row">

            <span>TOTAL A PAGAR</span>

            <span>
                RD$
                <?= number_format((float)$invoice['total'], 2) ?>
            </span>

        </div>

    </div>

    <div class="line"></div>

    <div class="footer">
        © <?= date('Y') ?> CEVIMEP. Todos los derechos reservados.
    </div>

</div>

<script>
window.onload = () => {
    setTimeout(() => {
        window.print();
    }, 500);
};
</script>

</body>
</html>