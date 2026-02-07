<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/_guard.php'; // valida sesión y carga $pdo (PDO)

/* ===============================
   Guardar desembolso
   =============================== */
$mensaje = '';
$tipo_mensaje = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fecha       = $_POST['fecha'] ?? date('Y-m-d');
    $monto_raw   = $_POST['monto'] ?? '0';
    $descripcion = trim((string)($_POST['descripcion'] ?? ''));

    $monto = (float)$monto_raw;

    // Usuario (según tu _guard.php usas $_SESSION['user'])
    $usuario_id = $_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? null);

    if ($monto > 0 && $usuario_id) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO caja_desembolsos (fecha, monto, descripcion, usuario_id)
                VALUES (:fecha, :monto, :descripcion, :usuario_id)
            ");
            $stmt->execute([
                ':fecha' => $fecha,
                ':monto' => $monto,
                ':descripcion' => $descripcion,
                ':usuario_id' => $usuario_id,
            ]);

            $mensaje = "✅ Desembolso registrado correctamente.";
            $tipo_mensaje = "success";
        } catch (Throwable $e) {
            $mensaje = "❌ Error al guardar el desembolso.";
            $tipo_mensaje = "error";
        }
    } else {
        $mensaje = "⚠️ Verifica: monto mayor a 0 y sesión válida.";
        $tipo_mensaje = "warning";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Registrar Desembolso</title>

  <!-- CSS (ajusta si tu ruta es diferente) -->
  <link rel="stylesheet" href="/assets/css/styles.css">
  <link rel="stylesheet" href="/assets/css/items.css">
</head>
<body>

<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="container">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>

  <main class="content">
    <div class="header-flex" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
      <h1 style="margin:0;">💸 Registrar Desembolso</h1>
      <a href="/private/caja/historial_desembolso.php" class="btn-secondary">📄 Ver Historial</a>
    </div>

    <?php if (!empty($mensaje)): ?>
      <div class="alert <?= htmlspecialchars($tipo_mensaje) ?>" style="margin-top:14px;">
        <?= htmlspecialchars($mensaje) ?>
      </div>
    <?php endif; ?>

    <form method="POST" class="form-card" style="margin-top:14px;">
      <div class="form-group">
        <label>Fecha</label>
        <input type="date" name="fecha" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
      </div>

      <div class="form-group">
        <label>Monto (RD$)</label>
        <input type="number" step="0.01" min="0" name="monto" required>
      </div>

      <div class="form-group">
        <label>Descripción</label>
        <textarea name="descripcion" rows="3" placeholder="Ej: Pago suplidor, combustible, etc."></textarea>
      </div>

      <button type="submit" class="btn-primary">Guardar Desembolso</button>
    </form>
  </main>
</div>

</body>
</html>
