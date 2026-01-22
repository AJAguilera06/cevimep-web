<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

$user = $_SESSION["user"];
$year = date("Y");
$branch_id = (int)($user["branch_id"] ?? 0);

function h($s){ return htmlspecialchars((string)$s); }

$cats = $conn->query("SELECT id,name FROM inventory_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $name = trim($_POST["name"]);
  $category_id = (int)$_POST["category_id"];
  $purchase = (float)$_POST["purchase_price"];
  $sale = (float)$_POST["sale_price"];

  $st = $conn->prepare("
    INSERT INTO inventory_items (name, category_id, purchase_price, sale_price, min_stock, is_active)
    VALUES (?,?,?,?,3,1)
  ");
  $st->execute([$name, $category_id ?: null, $purchase, $sale]);

  $item_id = $conn->lastInsertId();

  $conn->prepare("
    INSERT INTO inventory_stock (item_id, branch_id, quantity)
    VALUES (?,?,0)
  ")->execute([$item_id, $branch_id]);

  $_SESSION["flash_success"] = "Producto registrado correctamente.";
  header("Location: /private/inventario/items.php");
  exit;
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>CEVIMEP | Nuevo producto</title>
<link rel="stylesheet" href="/assets/css/styles.css?v=30">
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
  <p>Registrar nuevo producto</p>
</section>

<div class="card">
<form method="post">
  <div class="formGrid">
    <div class="field" style="grid-column:1/-1">
      <label>Nombre</label>
      <input name="name" required>
    </div>

    <div class="field">
      <label>Categoría</label>
      <select name="category_id">
        <option value="">—</option>
        <?php foreach($cats as $c): ?>
          <option value="<?=$c["id"]?>"><?=h($c["name"])?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label>Min Stock</label>
      <input value="3" readonly>
    </div>

    <div class="field">
      <label>Precio compra (RD$)</label>
      <input type="number" step="0.01" name="purchase_price" required>
    </div>

    <div class="field">
      <label>Precio venta (RD$)</label>
      <input type="number" step="0.01" name="sale_price" required>
    </div>
  </div>

  <div class="actions">
    <a class="btn-ui btn-secondary-ui" href="/private/inventario/items.php">Cancelar</a>
    <button class="btn-ui btn-primary-ui">Guardar</button>
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
