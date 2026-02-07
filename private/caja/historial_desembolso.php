<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$result = $conn->query("
    SELECT d.id, d.fecha, d.monto, d.descripcion, u.nombre 
    FROM caja_desembolsos d
    LEFT JOIN usuarios u ON d.usuario_id = u.id
    ORDER BY d.fecha DESC, d.id DESC
");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial de Desembolsos</title>
    <link rel="stylesheet" href="/assets/css/styles.css">
    <link rel="stylesheet" href="/assets/css/items.css">
</head>
<body>

<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="container">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="content">
    <div class="header-flex">
        <h1>📄 Historial de Desembolsos</h1>
        <a href="/private/caja/desembolso.php" class="btn-secondary">
            ➕ Nuevo Desembolso
        </a>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Monto</th>
                <th>Descripción</th>
                <th>Usuario</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['fecha']) ?></td>
                        <td>RD$ <?= number_format($row['monto'], 2) ?></td>
                        <td><?= htmlspecialchars($row['descripcion']) ?></td>
                        <td><?= htmlspecialchars($row['nombre'] ?? '—') ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" style="text-align:center;">No hay desembolsos registrados</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</main>
</div>

</body>
</html>
