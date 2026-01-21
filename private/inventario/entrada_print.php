<?php
session_start();
if (!isset($_SESSION["user"])) { exit; }

require_once __DIR__ . "/../../config/db.php";

$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) { exit("ID inválido"); }

$st = $pdo->prepare("
  SELECT e.created_at, b.name AS branch, u.name AS user_name
  FROM inventory_entries e
  JOIN branches b ON b.id = e.branch_id
  JOIN users u ON u.id = e.created_by
  WHERE e.id = ?
  LIMIT 1
");
$st->execute([$id]);
$entry = $st->fetch();
if (!$entry) { exit("Entrada no encontrada"); }

$st2 = $pdo->prepare("
  SELECT i.name, d.quantity
  FROM inventory_entry_details d
  JOIN inventory_items i ON i.id = d.item_id
  WHERE d.entry_id = ?
  ORDER BY i.name
");
$st2->execute([$id]);
$items = $st2->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Entrada de Inventario</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:Arial, sans-serif; padding:22px; color:#0b1f2a;}
    .header{display:flex; justify-content:space-between; align-items:flex-start; gap:16px;}
    .brand h2{margin:0 0 4px 0;}
    .muted{color:#6b7a86; font-size:13px;}
    .box{margin-top:14px; padding:12px 14px; border:1px solid #e5eaee; border-radius:10px;}
    table{width:100%; border-collapse:collapse; margin-top:12px;}
    th,td{border:1px solid #e5eaee; padding:9px 10px; font-size:14px;}
    th{background:#f4f7f9; text-align:left;}
    .right{text-align:right;}
    .footer{margin-top:18px; font-size:12px; color:#6b7a86;}
    @media print { .no-print{display:none;} body{padding:0;} }
  </style>
</head>
<body onload="window.print(); setTimeout(()=>{ window.location='entrada.php'; }, 800);">

  <div class="header">
    <div class="brand">
      <h2>CEVIMEP — Entrada de Inventario</h2>
      <div class="muted">Comprobante #<?= (int)$id ?></div>
    </div>
    <div class="muted">
      <div><b>Sucursal:</b> <?= htmlspecialchars($entry["branch"]) ?></div>
      <div><b>Fecha:</b> <?= date("d/m/Y H:i", strtotime($entry["created_at"])) ?></div>
      <div><b>Registrado por:</b> <?= htmlspecialchars($entry["user_name"]) ?></div>
    </div>
  </div>

  <div class="box">
    <table>
      <tr>
        <th>Producto</th>
        <th class="right">Cantidad</th>
      </tr>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= htmlspecialchars($it["name"]) ?></td>
          <td class="right"><?= (int)$it["quantity"] ?></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <div class="footer">
      Documento generado automáticamente por el sistema CEVIMEP.
    </div>
  </div>

  <div class="no-print" style="margin-top:14px;">
    Si no se abre la impresión automáticamente, presiona Ctrl+P.
  </div>

</body>
</html>
