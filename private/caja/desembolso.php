<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Desembolso | CEVIMEP</title>
<link rel="stylesheet" href="../assets/css/items.css">
</head>
<body>
<?php include '../includes/topbar.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<main class="content">
  <h1>DESEMBOLSO</h1>

  <div class="card">
    <form method="POST" action="guardar_desembolso.php" target="_blank">
      <div class="grid">
        <div>
          <label>Motivo</label>
          <input name="motivo" required>
        </div>
        <div>
          <label>Monto</label>
          <input name="monto" type="number" step="0.01" required>
        </div>
        <div>
          <label>Representante</label>
          <input name="representante">
        </div>
        <div>
          <label>Fecha</label>
          <input type="date" name="fecha" value="<?=date('Y-m-d')?>">
        </div>
      </div>

      <div class="actions">
        <button class="btn-primary">Guardar e imprimir</button>
        <a href="historial_desembolso.php" class="btn-secondary">Historial</a>
      </div>
    </form>
  </div>
</main>

<?php include '../includes/footer.php'; ?>
</body>
</html>
