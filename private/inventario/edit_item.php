<?php
session_start();

if (!isset($_SESSION["user"])) {
  header("Location: ../../public/login.php");
  exit;
}

require_once __DIR__ . "/../../config/db.php";
$conn = $pdo;

$year = date("Y");

/* ID */
$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) {
  die("ID inválido");
}

/* Cargar categorías */
$categories = [];
$qc = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
if ($qc) {
  $categories = $qc->fetchAll(PDO::FETCH_ASSOC);
}

/* Cargar item */
$st = $conn->prepare("
  SELECT id, name, category_id, purchase_price, sale_price, min_stock
  FROM inventory_items
  WHERE id = ? AND is_active = 1
  LIMIT 1
");
$st->execute([$id]);
$item = $st->fetch(PDO::FETCH_ASSOC);

if (!$item) {
  die("Producto no encontrado.");
}

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
      if (!$stc->fetchColumn()) {
        $flash_error = "Categoría inválida.";
      }
    }

    if ($flash_error === "") {
      $up = $conn->prepare("
        UPDATE inventory_items
        SET name = ?, category_id = ?, purchase_price = ?, sale_price = ?, min_stock = ?
        WHERE id = ? AND is_active = 1
      ");

      $cat_val = ($category_id > 0) ? $category_id : null;

      $ok = $up->execute([$name, $cat_val, $purchase_price, $sale_price, $min_stock, $id]);

      if ($ok) {
        $_SESSION["flash_success"] = "✅ Producto actualizado correctamente";
        header("Location: items.php");
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
  <title>CEVIMEP | Editar producto</title>

  <link rel="stylesheet" href="../../assets/css/styles.css">

  <style>
    .form-grid{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap:14px;
      margin-top:18px;
    }
    .field{
      display:flex;
      flex-direction:column;
      gap:8px;
    }
    .field label{
      font-weight:900;
      color:#0b2a4a;
      font-size:13px;
    }
    .input, .select{
      padding:10px 12px;
      border-radius:14px;
      border:1px solid #dbe7f3;
      outline:none;
      font-weight:700;
      background:#fff;
    }
    .actions{
      display:flex;
      gap:10px;
      margin-top:16px;
      align-items:center;
    }
    .
/assets/css/styles.css


.secondary{
      background:#eef6ff;
      border:1px solid rgba(2,21,44,.12);
      color:#0b2a4a;
    }
    .alert-error{
      margin-top:14px;
      padding:12px 14px;
      border-radius:14px;
      background:#ffecec;
      border:1px solid #ffb7b7;
      color:#7a1010;
      font-weight:900;
    }
  </style>
</head>

<body>

<header class="navbar">
  <div class="inner">
    <div></div>

    <div class="brand">
      <span class="dot"></span>
      CEVIMEP
    </div>

    <div class="nav-right">
      <a href="../../public/logout.php">Salir</a>
    </div>
  </div>
</header>

<main class="app">

  <!-- SIDEBAR (Inventario NO activo aquí, pero SIEMPRE manda a index.php) -->
  <aside class="sidebar">
    <div class="title">Menú</div>

    <nav class="menu">
      <a href="../dashboard.php">
        <span class="ico">🏠</span> Panel
      </a>

      <a href="../patients/index.php">
        <span class="ico">🧑‍🤝‍🧑</span> Pacientes
      </a>

      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;">
        <span class="ico">📅</span> Citas
      </a>

      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;">
        <span class="ico">🧾</span> Facturación
      </a>

      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;">
        <span class="ico">💳</span> Caja
      </a>

      <a href="index.php">
        <span class="ico">📦</span> Inventario
      </a>

      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;">
        <span class="ico">⏳</span> Coming Soon
      </a>
    </nav>
  </aside>

  <section class="main">
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
        <div>
          <h2 style="margin:0 0 6px;">Editar producto</h2>
          <p class="muted" style="margin:0;">ID: <?php echo (int)$item["id"]; ?></p>
        </div>

        <a class="
/assets/css/styles.css


 secondary" href="items.php" style="text-decoration:none;">Volver</a>
      </div>

      <?php if ($flash_error): ?>
        <div class="alert-error"><?php echo htmlspecialchars($flash_error); ?></div>
      <?php endif; ?>

      <form method="POST" action="">
        <div class="form-grid">

          <div class="field" style="grid-column:1 / -1;">
            <label>Producto</label>
            <input class="input" name="name" value="<?php echo htmlspecialchars($item["name"]); ?>" required>
          </div>

          <div class="field">
            <label>Categoría</label>
            <select class="select" name="category_id">
              <option value="0">Seleccionar...</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?php echo (int)$cat["id"]; ?>"
                  <?php echo ((int)($item["category_id"] ?? 0) === (int)$cat["id"]) ? "selected" : ""; ?>
                >
                  <?php echo htmlspecialchars($cat["name"]); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>Min Stock</label>
            <input class="input" type="number" name="min_stock" min="0"
              value="<?php echo (int)($item["min_stock"] ?? 0); ?>">
          </div>

          <div class="field">
            <label>Precio compra</label>
            <input class="input" type="number" step="0.01" min="0" name="purchase_price"
              value="<?php echo (float)($item["purchase_price"] ?? 0); ?>">
          </div>

          <div class="field">
            <label>Precio venta</label>
            <input class="input" type="number" step="0.01" min="0" name="sale_price"
              value="<?php echo (float)($item["sale_price"] ?? 0); ?>">
          </div>

        </div>

        <div class="actions">
          <button class="
/assets/css/styles.css


" type="submit">Guardar cambios</button>
          <a class="
/assets/css/styles.css


 secondary" href="items.php" style="text-decoration:none;">Cancelar</a>
        </div>
      </form>

    </div>
  </section>

</main>

<footer class="footer">
  <div class="inner">
    © <?php echo $year; ?> CEVIMEP. Todos los derechos reservados.
  </div>
</footer>

</body>
</html>
