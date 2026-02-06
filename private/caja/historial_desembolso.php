das<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';

$rows = $db->query("
  SELECT id, motivo, amount, created_at, created_by
  FROM cash_movements
  WHERE type='desembolso'
  ORDER BY id DESC
  LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Historial Desembolsos | CEVIMEP</title>
<link rel="stylesheet" href="../assets/css/items.css">
<style>
.table-scroll{max-height:260px;overflow-y:auto}
</style>
</head>
<body>
<?php include '../includes/topbar.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<main class="content">
  <h1>HISTORIAL DE DESEMBOLSOS</h1>

  <div class="card table-scroll">
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Fecha</th>
          <th>Motivo</th>
          <th>Monto</th>
          <th>Usuario</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): ?>
        <tr>
          <td>#<?=$r['id']?></td>
          <td><?=$r['created_at']?></td>
          <td><?=$r['motivo']?></td>
          <td>RD$ <?=number_format($r['amount'],2)?></td>
          <td><?=$r['created_by']?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>

<?php include '../includes/footer.php'; ?>
</body>
</html>
