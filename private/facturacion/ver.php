sda# ver.php

```php
<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

$user = $_SESSION['user'] ?? [];
$sucursalId = (int)($user['branch_id'] ?? 0);

if ($sucursalId <= 0) {
    http_response_code(400);
    die("Sucursal inválida.");
}

$invoice_id = (int)($_GET['id'] ?? 0);

if ($invoice_id <= 0) {
    http_response_code(400);
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
    'id'  => $invoice_id,
    'bid' => $sucursalId
]);

$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    http_response_code(404);
    die("Factura no encontrada.");
}

/**
 * DETALLES
 * Ajusta el nombre de la tabla si tu sistema usa otro.
 */
$items = [];

try {
    $stmt = $conn->prepare("
        SELECT *
        FROM invoice_items
        WHERE invoice_id = :id
        ORDER BY id ASC
    ");

    $stmt->execute(['id' => $invoice_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Si no existe la tabla, no rompe la página
    $items = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ver factura | CEVIMEP</title>
    <link rel="stylesheet" href="/assets/css/styles.css?v=50">

    <style>
        .page-wrap{
            padding:18px;
        }

        .page-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:16px;
        }

        .page-header h1{
            margin:0;
            font-size:42px;
            font-weight:900;
        }

        .page-subtitle{
            margin-top:5px;
            opacity:.7;
            font-weight:700;
        }

        .actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }

        .btn-pill.secondary{
            background:#fff;
            color:#0b4d87;
            border:1px solid #d7e7fb;
        }

        .btn-pill.primary{
            background:#0b63b6;
            color:#fff;
            border:1px solid #0b63b6;
        }

        .card{
            background:#fff;
            border-radius:18px;
            border:1px solid #e6eef8;
            box-shadow:0 12px 30px rgba(0,0,0,.06);
            padding:20px;
        }

        .grid{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
            gap:16px;
            margin-bottom:20px;
        }

        .info-box{
            background:#f8fbff;
            border:1px solid #dcecff;
            border-radius:14px;
            padding:14px;
        }

        .label{
            font-size:12px;
            font-weight:800;
            color:#0b4d87;
            margin-bottom:5px;
            text-transform:uppercase;
        }

        .value{
            font-size:15px;
            font-weight:800;
        }

        table{
            width:100%;
            border-collapse:collapse;
        }

        th, td{
            padding:12px;
            border-bottom:1px solid #eef2f6;
            text-align:left;
            font-size:14px;
        }

        th{
            color:#0b4d87;
            font-weight:900;
        }

        .total-box{
            margin-top:20px;
            display:flex;
            justify-content:flex-end;
        }

        .total-card{
            background:#0b63b6;
            color:#fff;
            padding:18px 24px;
            border-radius:18px;
            min-width:240px;
            text-align:center;
        }

        .total-card small{
            display:block;
            opacity:.8;
            margin-bottom:6px;
            font-weight:700;
        }

        .total-card strong{
            font-size:28px;
            font-weight:900;
        }

        .muted{
            opacity:.7;
            font-weight:700;
        }
    </style>
</head>
<body>

<header class="navbar">
    <div class="inner">
        <div class="brand">
            <span class="dot"></span>
            <span>CEVIMEP</span>
        </div>

        <div class="nav-right">
            <a href="/logout.php" class="btn-pill">Salir</a>
        </div>
    </div>
</header>

<div class="layout">

    <aside class="sidebar">
        <div class="menu-title">Menú</div>

        <nav class="menu">
            <a href="/private/dashboard.php">🏠 Panel</a>
            <a href="/private/patients/index.php">👤 Pacientes</a>
            <a href="/private/citas/index.php">📅 Citas</a>
            <a class="active" href="/private/facturacion/index.php">🧾 Facturación</a>
            <a href="/private/caja/index.php">💳 Caja</a>
            <a href="/private/inventario/index.php">📦 Inventario</a>
            <a href="/private/estadistica/index.php">📊 Estadísticas</a>
        </nav>
    </aside>

    <main class="content">
        <div class="page-wrap">

            <div class="page-header">
                <div>
                    <h1>Factura #<?= (int)$invoice['id'] ?></h1>
                    <div class="page-subtitle">Detalle completo de la factura</div>
                </div>

                <div class="actions">
                    <a class="btn-pill secondary" href="/private/facturacion/paciente.php?patient_id=<?= (int)$invoice['patient_id'] ?>">← Volver</a>

                    <a class="btn-pill primary" target="_blank" href="/private/facturacion/print.php?id=<?= (int)$invoice['id'] ?>">
                        🖨 Imprimir
                    </a>
                </div>
            </div>

            <div class="card">

                <div class="grid">
                    <div class="info-box">
                        <div class="label">Paciente</div>
                        <div class="value">
                            <?= h($invoice['first_name'] . ' ' . $invoice['last_name']) ?>
                        </div>
                    </div>

                    <div class="info-box">
                        <div class="label">Sucursal</div>
                        <div class="value">
                            <?= h($invoice['branch_name']) ?>
                        </div>
                    </div>

                    <div class="info-box">
                        <div class="label">Método de pago</div>
                        <div class="value">
                            <?= h($invoice['payment_method'] ?? 'N/D') ?>
                        </div>
                    </div>

                    <div class="info-box">
                        <div class="label">Fecha</div>
                        <div class="value">
                            <?= h($invoice['created_at']) ?>
                        </div>
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Descripción</th>
                            <th>Cantidad</th>
                            <th>Precio</th>
                            <th>Total</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="4" class="muted">
                                No hay detalles registrados para esta factura.
                            </td>
                        </tr>
                    <?php else: ?>

                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= h($item['description'] ?? '-') ?></td>
                                <td><?= h($item['quantity'] ?? 1) ?></td>
                                <td>
                                    RD$ <?= number_format((float)($item['price'] ?? 0), 2) ?>
                                </td>
                                <td>
                                    RD$ <?= number_format((float)($item['total'] ?? 0), 2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>
                </table>

                <div class="total-box">
                    <div class="total-card">
                        <small>Total factura</small>
                        <strong>
                            RD$ <?= number_format((float)$invoice['total'], 2) ?>
                        </strong>
                    </div>
                </div>

            </div>

        </div>
    </main>

</div>

<footer class="footer">
    © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>
```

El botón que ya tienes en `paciente.php` seguirá funcionando:

```php
href="/private/facturacion/ver.php?id=<?= (int)$inv['id'] ?>"
```

Y el diseño mantiene:

* navbar
* sidebar
* estilos actuales
* colores
* cards
* botones
* estructura visual del sistema
