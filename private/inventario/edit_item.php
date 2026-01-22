<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

$year = date("Y");

/* ID */
$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) { die("ID inválido"); }

/* Cargar categorías */
$categories = [];
try {
  $qc = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
  if ($qc) $categories = $qc->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

/* Cargar item */
$st = $conn->prepare("
  SELECT id, name, category_id, purchase_price, sale_price, min_stock
  FROM inventory_items
  WHERE id = ? AND (is_active = 1 OR is_active IS NULL)
  LIMIT 1
");
$st->execute([$id]);
$item = $st->fetch(PDO::FETCH_ASSOC);

if (!$item) { die("Producto no encontrado."); }

$flash_error = "";

/* Guardar cambios */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $name = trim($_POST["name"] ?? "");
  $category_id = (int)($_POST["category_id"] ?? 0);
  $purchase_price = (float)($_POST["purchase_price"] ?? 0);
  $sale_price = (float)($_POST["sale_price"] ?? 0);
  $min_stock = (int)($_POST["min_stock"] ?? 0);

  if ($name === "") {
    $flash_error = "El nombre del producto es obligatorio.";
  } elseif ($purchase_price < 0 || $sale_price < 0) {
    $flash_error = "Los precios no pueden ser negativos.";
  } elseif ($min_stock < 0) {
    $flash_error = "El Min Stock no puede ser negativo.";
  } else {
    if ($category_id > 0) {
      $stc = $conn->prepare("SELECT id FROM inventory_categories WHERE id=?");
      $stc->execute([$category_id]);
      if (!$stc->fetchColumn()) $flash_error = "Categoría inválida.";
    }

    if ($flash_error === "") {
      $up = $conn->prepare("
        UPDATE inventory_items
        SET name = ?, category_id = ?, purchase_price = ?, sale_price = ?, min_stock = ?
        WHERE id = ? AND (is_active = 1 OR is_active IS NULL)
      ");

      $cat_val = ($category_id > 0) ? $category_id : null;

      $ok = $up->execute([$name, $cat_val, $purchase_price, $sale_price, $min_stock, $id]);

      if ($ok) {
        $_SESSION["flash_success"] = "✅ Producto actualizado correctamente";
        header("Location: /private/inventario/items.php");
        exit;
      } else {
        $flash_error = "No se pudo guardar. Intenta de nuevo.";
      }
    }
  }

  $item["name"] = $name;
  $item["category_id"] = $category_id ?: null;
  $item["purchase_price"] = $purchase_price;
  $item["sale_price"] = $sale_price;
  $item["min_stock"] = $min_stock;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Editar producto</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=120">
  <style>
    .wrap{max-width:900px;margin:20px auto;padding:0 16px;}
    .card{background:#fff;border-radius:16px;box-shadow:0 12px 30px rgba(0,0,0,.08);padding:16px;}
    label{font-weight:800;color:#0b2b4a;font-size:13px;}
    input,select{width:100%;height:40px;border:1px solid #d8e1ea;border-radius:12px;padding:0 12px;outline:none;margin-top:6px;}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    .btn{height:40px;border:none;border-radius:12px;padding:0 14px;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
    .btn-primary{background:#0b4d87;color:#fff;}
    .btn-secondary{background:#eef2f6;color:#2b3b4a;}
    .row{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;justify-content:flex-end}
    .err{background:#ffecec;border:1px solid #ffb6b6;color:#a40000;border-radius:12px;padding:10px 12px;font-size:13px;margin-bottom:12px;}
    @media(max-width:720px){.grid{grid-template-columns:1fr;}}
  </style>
</head>
<body>

<div class="wrap">
  <h2 style="margin:0 0 10px;font-weight:900;color:#0b2b4a;">Editar producto</h2>

  <?php if ($flash_error): ?>
    <div class="err"><?= h($flash_error) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="post">
      <div style="margin-bottom:12px;">
        <label>Nombre</label>
        <input name="name" value="<?= h($item["name"] ?? "") ?>" required>
      </div>

      <div class="grid">
        <div>
          <label>Categoría</label>
          <select name="category_id">
            <option value="0">— Sin categoría —</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c["id"] ?>" <?= ((int)$item["category_id"] === (int)$c["id"]) ? "selected" : "" ?>>
                <?= h($c["name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Min Stock</label>
          <input type="number" name="min_stock" value="<?= h($item["min_stock"] ?? 0) ?>" min="0">
        </div>

        <div>
          <label>Precio compra</label>
          <input type="number" step="0.01" name="purchase_price" value="<?= h($item["purchase_price"] ?? 0) ?>" min="0">
        </div>

        <div>
          <label>Precio venta</label>
          <input type="number" step="0.01" name="sale_price" value="<?= h($item["sale_price"] ?? 0) ?>" min="0">
        </div>
      </div>

      <div class="row">
        <a class="btn btn-secondary" href="/private/inventario/items.php">Volver</a>
        <button class="btn btn-primary" type="submit">Guardar cambios</button>
      </div>
    </form>
  </div>
</div>

</body>
</html>
