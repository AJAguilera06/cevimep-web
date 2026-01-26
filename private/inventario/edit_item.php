<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

$user = $_SESSION["user"] ?? [];
$year = (int)date("Y");
$branch_id = (int)($user["branch_id"] ?? 0);

if ($branch_id <= 0) { die("Sucursal inválida (branch_id)."); }

/* Nombre sucursal */
$branch_name = "";
try {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $branch_name = (string)($stB->fetchColumn() ?: "");
} catch (Throwable $e) {}

/* ID item */
$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) { die("ID inválido"); }

/* Categorías */
$categories = [];
try {
  $qc = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
  $categories = $qc ? $qc->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {}

/* Cargar item SOLO si existe en esta sucursal */
$st = $conn->prepare("
  SELECT i.id, i.name, i.category_id, i.purchase_price, i.sale_price, i.min_stock
  FROM inventory_items i
  INNER JOIN inventory_stock s
    ON s.item_id = i.id AND s.branch_id = ?
  WHERE i.id = ? AND (i.is_active = 1 OR i.is_active IS NULL)
  LIMIT 1
");
$st->execute([$branch_id, $id]);
$item = $st->fetch(PDO::FETCH_ASSOC);

if (!$item) { die("Producto no encontrado en esta sucursal."); }

$flash_error = "";

/* Guardar cambios */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $name = trim($_POST["name"] ?? "");
  $category_id = (int)($_POST["category_id"] ?? 0);
  $purchase_price = (float)($_POST["purchase_price"] ?? 0);
  $sale_price = (float)($_POST["sale_price"] ?? 0);

  /* ✅ Min stock SIEMPRE 3 */
  $min_stock = 3;

  if ($name === "") {
    $flash_error = "El nombre del producto es obligatorio.";
  } elseif ($purchase_price < 0 || $sale_price < 0) {
    $flash_error = "Los precios no pueden ser negativos.";
  } else {
    if ($category_id > 0) {
      $stc = $conn->prepare("SELECT id FROM inventory_categories WHERE id=? LIMIT 1");
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

  /* repintar form */
  $item["name"] = $name;
  $item["category_id"] = $category_id ?: null;
  $item["purchase_price"] = $purchase_price;
  $item["sale_price"] = $sale_price;
  $item["min_stock"] = 3;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Editar producto</title>

  <link rel="stylesheet" href="/assets/css/styles.css?v=30">

  <style>
    /* Contenedor centrado para evitar el “vacío” */
    .pageWrap{
      width:100%;
      max-width: 920px;
      margin: 0 auto;
      padding: 18px 18px 28px;
    }

    .hero{
      text-align:center;
      padding: 14px 10px;
      border-radius: 18px;
      background: radial-gradient(900px 240px at 50% 0%, rgba(127,178,255,.22), rgba(255,255,255,0));
      margin-bottom: 14px;
    }
    .hero h1{
      margin: 2px 0 6px;
      font-size: 34px;
      letter-spacing: .2px;
      color:#0b2a4a;
    }
    .hero p{
      margin:0;
      color: rgba(11,42,74,.75);
      font-weight: 800;
    }

    .card{
      border-radius: 18px;
      background:#fff;
      border: 1px solid rgba(2,21,44,.10);
      box-shadow: 0 18px 45px rgba(2,21,44,.08);
      padding: 18px;
    }

    .cardHead{
      display:flex;
      justify-content:space-between;
      align-items:flex-start;
      gap:12px;
      flex-wrap:wrap;
      margin-bottom: 10px;
    }
    .cardHead h3{
      margin:0 0 6px;
      font-size:18px;
      color:#0b2a4a;
      font-weight: 900;
    }
    .muted{ margin:0; color:rgba(11,42,74,.65); font-weight:700; }

    /* Form */
    .formGrid{
      display:grid;
      grid-template-columns: 1.2fr 1fr;
      gap: 14px;
      margin-top: 14px;
    }
    .field{ display:flex; flex-direction:column; gap:8px; }
    .field label{ font-weight:900; color:#0b2a4a; font-size:13px; }
    .field input, .field select{
      height:42px;
      padding:0 12px;
      border-radius:14px;
      border:1px solid rgba(2,21,44,.12);
      outline:none;
      font-weight:800;
      background:#fff;
    }
    .field input:focus, .field select:focus{
      border-color:#7fb2ff;
      box-shadow:0 0 0 3px rgba(127,178,255,.20);
    }
    .spanAll{ grid-column: 1 / -1; }

    /* Botones bonitos */
    .btnx{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      height:38px;
      padding: 0 14px;
      border-radius: 999px;
      font-weight: 900;
      border: 1px solid rgba(2,21,44,.14);
      background: #fff;
      color:#0b2a4a;
      text-decoration:none;
      cursor:pointer;
      transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
      user-select:none;
    }
    .btnx:hover{ transform: translateY(-1px); box-shadow: 0 10px 20px rgba(2,21,44,.10); border-color: rgba(127,178,255,.55); }
    .btnPrimary{
      background: linear-gradient(180deg, #0f4f8a, #0b2a4a);
      border-color: rgba(255,255,255,.18);
      color:#fff;
    }
    .btnGhost{
      background: transparent;
    }

    .actions{
      display:flex;
      gap:10px;
      justify-content:flex-end;
      flex-wrap:wrap;
      margin-top: 16px;
      padding-top: 14px;
      border-top: 1px dashed rgba(2,21,44,.12);
    }

    .err{
      background:#ffecec;
      border:1px solid #ffb6b6;
      color:#a40000;
      border-radius:14px;
      padding:10px 12px;
      font-size:13px;
      margin: 10px 0 14px;
      font-weight: 800;
    }

    @media (max-width: 820px){
      .formGrid{ grid-template-columns:1fr; }
      .hero h1{ font-size: 28px; }
    }
  </style>
</head>

<body>

<!-- NAVBAR igual dashboard -->
<div class="navbar">
  <div class="inner">
    <div class="brand">
      <span class="dot"></span>
      <strong>CEVIMEP</strong>
    </div>

    <div class="nav-right">
      <a class="btn-pill" href="/logout.php">Salir</a>
    </div>
  </div>
</div>

<div class="layout">

  <!-- SIDEBAR igual dashboard -->
  <aside class="sidebar">
    <h3 class="menu-title">Menú</h3>

    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👤</span> Pacientes</a>
      <a href="/private/citas/index.php"><span class="ico">📅</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💳</span> Caja</a>
      <a class="active" href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadisticas/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <div class="pageWrap">

      <section class="hero">
        <h1>Inventario</h1>
        <p>Editar producto — Sucursal: <strong><?= h($branch_name ?: "—") ?></strong></p>
      </section>

      <?php if ($flash_error): ?>
        <div class="err"><?= h($flash_error) ?></div>
      <?php endif; ?>

      <div class="card">
        <div class="cardHead">
          <div>
            <h3>Editar producto</h3>
            <p class="muted">Actualiza la información del producto seleccionado.</p>
          </div>
          <a class="btnx btnGhost" href="/private/inventario/items.php">← Volver</a>
        </div>

        <form method="post">
          <div class="formGrid">

            <div class="field spanAll">
              <label>Nombre</label>
              <input name="name" value="<?= h($item["name"] ?? "") ?>" required>
            </div>

            <div class="field">
              <label>Categoría</label>
              <select name="category_id">
                <option value="0">— Sin categoría —</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c["id"] ?>" <?= ((int)($item["category_id"] ?? 0) === (int)$c["id"]) ? "selected" : "" ?>>
                    <?= h($c["name"]) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label>Min Stock</label>
              <input type="number" value="3" readonly>
            </div>

            <div class="field">
              <label>Precio compra</label>
              <input type="number" step="0.01" min="0" name="purchase_price" value="<?= h($item["purchase_price"] ?? 0) ?>">
            </div>

            <div class="field">
              <label>Precio venta</label>
              <input type="number" step="0.01" min="0" name="sale_price" value="<?= h($item["sale_price"] ?? 0) ?>">
            </div>

          </div>

          <div class="actions">
            <a class="btnx" href="/private/inventario/items.php">Cancelar</a>
            <button class="btnx btnPrimary" type="submit">Guardar cambios</button>
          </div>
        </form>
      </div>

    </div>
  </main>
</div>

<div class="footer">
  <div class="inner">
    © <?= $year ?> CEVIMEP. Todos los derechos reservados.
  </div>
</div>

</body>
</html>
