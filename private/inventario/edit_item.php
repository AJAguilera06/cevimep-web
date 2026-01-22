<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

$user = $_SESSION["user"];
$year = date("Y");
$branch_id = (int)($user["branch_id"] ?? 0);

if ($branch_id <= 0) { die("Sucursal inválida."); }

/* ID */
$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) { die("ID inválido."); }

/* Categorías */
$categories = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* Item SOLO si existe en la sucursal */
$st = $conn->prepare("
  SELECT i.*
  FROM inventory_items i
  INNER JOIN inventory_stock s
    ON s.item_id = i.id AND s.branch_id = ?
  WHERE i.id = ?
  LIMIT 1
");
$st->execute([$branch_id, $id]);
$item = $st->fetch(PDO::FETCH_ASSOC);

if (!$item) { die("Producto no pertenece a esta sucursal."); }

/* Guardar */
$err = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $name = trim($_POST["name"] ?? "");
  $category_id = (int)($_POST["category_id"] ?? 0);
  $purchase_price = (float)($_POST["purchase_price"] ?? 0);
  $sale_price = (float)($_POST["sale_price"] ?? 0);

  if ($name === "") {
    $err = "El nombre es obligatorio.";
  } else {
    $up = $conn->prepare("
      UPDATE inventory_items
      SET name=?, category_id=?, purchase_price=?, sale_price=?, min_stock=3
      WHERE id=?
    ");
    $up->execute([$name, $category_id ?: null, $purchase_price, $sale_price, $id]);

    $_SESSION["flash_success"] = "Producto actualizado correctamente.";
    header("Location: /private/inventario/items.php");
    exit;
  }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>CEVIMEP | Editar producto</title>
<link rel="stylesheet" href="/assets/css/styles.css?v=30">
<style>
.formGrid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px}
.field{display:flex;flex-direction:column;gap:6px}
.field label{font-weight:900;color:#0b2a4a;font-size:13px}
.field input,.field select{
  height:40px;border-radius:14px;border:1px solid rgba(2,21,44,.12);
  padding:0 12px;font-weight:800
}
.actions{display:flex;gap:10px;justify-content:flex-end;margin-top:16px}
@media(max-width:820px){.formGrid{grid-template-columns:1fr}}
</style>
</head>

<body>

<div class="navbar">
  <div class="inner">
    <div class="brand"><span class="dot"></span><strong>CEVIMEP</strong></div>
    <div class="nav-right"><a class="btn-pill" href="/logout.php">Salir</a></div>
  </div>
</div>

<div class="layout">
<aside class="sidebar">
  <h3 class="menu-title">Menú</h3>
  <nav class="menu">
    <a href="/private/dashboard.php">🏠 Panel</a>
    <a class="active" href="/private/inventario/index.php">📦 Inventario</a>
  </nav>
</aside>

<main class="content">
<section class="hero">
  <h1>Inventario</h1>
  <p>Editar producto</p>
</section>

<?php if($err): ?><div class="flash-err"><?=h($err)?></div><?php endif; ?>

<div class="card">
<form method="post">
  <div class="formGrid">
    <div class="field" style="grid-column:1/-1">
      <label>Nombre</label>
      <input name="name" value="<?=h($item["name"])?>" required>
    </div>

    <div class="field">
      <label>Categoría</label>
      <select name="category_id">
        <option value="">—</option>
        <?php foreach($categories as $c): ?>
          <option value="<?=$c["id"]?>" <?=($item["category_id"]==$c["id"]?"selected":"")?>>
            <?=h($c["name"])?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label>Min Stock</label>
      <input value="3" readonly>
    </div>

    <div class="field">
      <label>Precio compra (RD$)</label>
      <input type="number" step="0.01" name="purchase_price" value="<?=h($item["purchase_price"])?>">
    </div>

    <div class="field">
      <label>Precio venta (RD$)</label>
      <input type="number" step="0.01" name="sale_price" value="<?=h($item["sale_price"])?>">
    </div>
  </div>

  <div class="actions">
    <a class="btn-ui btn-secondary-ui" href="/private/inventario/items.php">Cancelar</a>
    <button class="btn-ui btn-primary-ui">Guardar cambios</button>
  </div>
</form>
</div>

</main>
</div>

<div class="footer">
  <div class="inner">© <?=$year?> CEVIMEP</div>
</div>

</body>
</html>
