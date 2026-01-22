<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";

header("Content-Type: application/json; charset=utf-8");

$id = (int)($_POST["id"] ?? 0);
if ($id <= 0) {
  echo json_encode(["ok" => false, "msg" => "ID inválido"]);
  exit;
}

try {
  $pdo->beginTransaction();

  // 1) Borrado lógico del item (global)
  $st = $pdo->prepare("UPDATE inventory_items SET is_active = 0 WHERE id = ? LIMIT 1");
  $st->execute([$id]);

  if ($st->rowCount() === 0) {
    $pdo->rollBack();
    echo json_encode(["ok" => false, "msg" => "Producto no encontrado o ya eliminado"]);
    exit;
  }

  // 2) Limpiar stock del item en todas las sedes
  $st2 = $pdo->prepare("DELETE FROM inventory_stock WHERE item_id = ?");
  $st2->execute([$id]);

  $pdo->commit();
  echo json_encode(["ok" => true]);
  exit;

} catch (Exception $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(["ok" => false, "msg" => "Error al eliminar"]);
  exit;
}
