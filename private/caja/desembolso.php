<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fecha       = $_POST['fecha'] ?? date('Y-m-d');
    $monto       = $_POST['monto'] ?? 0;
    $descripcion = $_POST['descripcion'] ?? '';
    $usuario_id  = $_SESSION['user_id'] ?? null;

    if ($monto > 0 && $usuario_id) {
        $stmt = $conn->prepare("
            INSERT INTO caja_desembolsos 
            (fecha, monto, descripcion, usuario_id)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("sdsi", $fecha, $monto, $descripcion, $usuario_id);

        if ($stmt->execute()) {
            $mensaje = "✅ Desembolso registrado correctamente.";
        } else {
            $mensaje = "❌ Error al guardar el desembolso.";
        }
    } else {
        $mensaje = "⚠️ Datos inválidos.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registrar Desembolso</title>
    <link rel="stylesheet" href="/assets/css/styles.css">
    <link rel="stylesheet" href="/assets/css/items.css">
</head>
<body>

<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="container">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="content">
    <div class="header-flex">
        <h1>💸 Registrar Desembolso</h1>
        <a href="/private/caja/historial_desembolso.php" class="btn-secondary">
            📄 Ver Historial
        </a>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <form method="POST" class="form-card">
        <div class="form-group">
            <label>Fecha</label>
            <input type="date" name="fecha" value="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="form-group">
            <label>Monto (RD$)</label>
            <input type="number" step="0.01" name="monto" required>
        </div>

        <div class="form-group">
            <label>Descripción</label>
            <textarea name="descripcion" rows="3"></textarea>
        </div>

        <button type="submit" class="btn-primary">
            Guardar Desembolso
        </button>
    </form>
</main>
</div>

</body>
</html>
