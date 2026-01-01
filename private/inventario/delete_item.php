<?php
session_start();
require_once __DIR__ . "/../../config/db.php";

header("Content-Type: application/json");

if (!isset($_SESSION["user"])) {
  echo json_encode(["ok" => false, "msg" => "No autorizado"]);
  exit;
}

$id = (int)($_POST["id"] ?? 0);
if ($id <= 0) {
  echo json_encode(["ok" => false, "msg" => "ID inválido"]);
  exit;
}

try {
  // (Opcional) Si quieres restringir solo a admin / branch_admin:
  // $role = $_SESSION["user"]["role"] ?? "";
  // if (!in_array($role, ["admin", "branch_admin"])) {
  //   echo json_encode(["ok"=>false, "msg"=>"Sin permisos"]);
  //   exit;
  // }

  $pdo->beginTransaction();

  // 1) Borrado lógico del item (NO lo elimina de la tabla)
  $st = $pdo->prepare("UPDATE inventory_items SET is_active = 0 WHERE id = ? LIMIT 1");
  $st->execute([$id]);

  if ($st->rowCount() === 0) {
    $pdo->rollBack();
    echo json_encode(["ok" => false, "msg" => "Producto no encontrado o ya eliminado"]);
    exit;
  }

  // 2) (Opcional pero recomendado) limpiar stock del item en todas las sedes
  // para que no quede data suelta.
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
