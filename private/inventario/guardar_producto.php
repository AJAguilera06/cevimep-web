<?php
session_start();
require_once __DIR__ . "/../../config/db.php";

if (!isset($_SESSION["user"])) {
  header("Location: ../../public/login.php");
  exit;
}

$year = date("Y");

/* detectar si viene por AJAX */
function wants_json(): bool {
  $accept = $_SERVER["HTTP_ACCEPT"] ?? "";
  $xhr = $_SERVER["HTTP_X_REQUESTED_WITH"] ?? "";
  return (stripos($accept, "application/json") !== false) || (strtolower($xhr) === "xmlhttprequest");
}

/* ==========================
   GET => FORM
========================== */
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  $st = $pdo->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
  $cats = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
  ?>
  <!doctype html>
  <html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CEVIMEP | Registrar producto</title>

    <link rel="stylesheet" href="../../assets/css/styles.css">

    <style>
      .formWrap{ max-width:920px; margin:22px auto; padding:0 22px; }
      .card{ background:#fff; border:1px solid #e6eef7; border-radius:22px; padding:18px; box-shadow:0 10px 30px rgba(15,42,80,.08); }
      .grid{ display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:16px; }
      .field{ display:flex; flex-direction:column; gap:8px; }
      .field label{ font-weight:900; color:#0b2a4a; font-size:13px; }
      .field input, .field select{
        padding:10px 12px; border-radius:14px; border:1px solid #dbe7f3;
        outline:none; font-weight:700; background:#fff;
      }
      .field input:focus, .field select:focus{
        border-color:#7fb2ff;
        box-shadow:0 0 0 3px rgba(127,178,255,.20);
      }
      .actions{
        display:flex;
        gap:10px;
        justify-content:flex-end;
        margin-top:18px;
      }
      .btnLink{
        text-decoration:none;
        display:inline-flex;
        align-items:center;
        justify-content:center;
      }
    </style>
  </head>

  <body>

    <header class="navbar">
      <div class="inner">
        <div></div>
        <div class="brand"><span class="dot"></span> CEVIMEP</div>
        <div class="nav-right"><a href="../../public/logout.php">Salir</a></div>
      </div>
    </header>

    <main class="app">
      <aside class="sidebar">
        <div class="title">Menú</div>
        <nav class="menu">
          <a href="../dashboard.php"><span class="ico">🏠</span> Panel</a>
          <a href="../patients/index.php"><span class="ico">🧑‍🤝‍🧑</span> Pacientes</a>

          <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;">
            <span class="ico">📅</span> Citas
          </a>
          <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;">
            <span class="ico">🧾</span> Facturación
          </a>
          <a href="../caja/index.php"><span class="ico">💳</span> Caja</a>

          <a class="active" href="items.php"><span class="ico">📦</span> Inventario</a>

          <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;">
            <span class="ico">⏳</span> Coming Soon
          </a>
        </nav>
      </aside>

      <section class="main">
        <div class="formWrap">
          <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
              <div>
                <h2 style="margin:0 0 6px;">Registrar nuevo producto</h2>
                <p class="muted" style="margin:0;">Completa los datos para agregarlo al inventario.</p>
              </div>
              <a class="btn btnLink" href="items.php">Volver</a>
            </div>

            <form method="POST" action="">
              <div class="grid">
                <div class="field" style="grid-column:1 / -1;">
                  <label>Nombre del producto</label>
                  <input name="nombre" required>
                </div>

                <div class="field">
                  <label>Categoría</label>
                  <select name="category_id" required>
                    <option value="">Selecciona...</option>
                    <?php foreach($cats as $c): ?>
                      <option value="<?= (int)$c["id"] ?>"><?= htmlspecialchars($c["name"]) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="field">
                  <label>Min Stock</label>
                  <input type="number" name="min_stock" min="0" value="0" required>
                </div>

                <div class="field">
                  <label>Precio compra</label>
                  <input type="number" step="0.01" min="0" name="precio_compra" required>
                </div>

                <div class="field">
                  <label>Precio venta</label>
                  <input type="number" step="0.01" min="0" name="precio_venta" required>
                </div>
              </div>

              <div class="actions">
                <button class="btn" type="submit">Guardar</button>
                <a class="btn btnLink" href="items.php" style="background:#eef6ff;border:1px solid rgba(2,21,44,.12);color:#0b2a4a;">Cancelar</a>
              </div>
            </form>
          </div>
        </div>
      </section>
    </main>

    <footer class="footer">
      <div class="inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
    </footer>
  </body>
  </html>
  <?php
  exit;
}

/* ==========================
   POST => GUARDAR
========================== */
$nombre = trim($_POST["nombre"] ?? "");
$category_id = (int)($_POST["category_id"] ?? 0);
$precio_compra = (float)($_POST["precio_compra"] ?? 0);
$precio_venta = (float)($_POST["precio_venta"] ?? 0);
$min_stock = (int)($_POST["min_stock"] ?? 0);

$userBranchId = (int)($_SESSION["user"]["branch_id"] ?? 0);

if ($userBranchId <= 0) {
  $resp = ["ok"=>false, "msg"=>"Este usuario no tiene sede asignada. No se puede registrar productos."];
  if (wants_json()) { header("Content-Type: application/json"); echo json_encode($resp); exit; }
  $_SESSION["flash_success"] = "";
  header("Location: items.php");
  exit;
}

if ($nombre === "" || $category_id <= 0) {
  $resp = ["ok"=>false, "msg"=>"Completa nombre y categoría"];
  if (wants_json()) { header("Content-Type: application/json"); echo json_encode($resp); exit; }
  header("Location: items.php"); exit;
}

try {
  // Validar categoría
  $stCat = $pdo->prepare("SELECT id, name FROM inventory_categories WHERE id=? LIMIT 1");
  $stCat->execute([$category_id]);
  $rowCat = $stCat->fetch(PDO::FETCH_ASSOC);
  $catName = $rowCat ? $rowCat["name"] : "";

  // Insert del item (global en tabla)
  $st = $pdo->prepare("
    INSERT INTO inventory_items (category_id, name, sku, unit, purchase_price, sale_price, min_stock, is_active)
    VALUES (?, ?, NULL, 'dosis', ?, ?, ?, 1)
  ");
  $st->execute([$category_id, $nombre, $precio_compra, $precio_venta, $min_stock]);

  $newItemId = (int)$pdo->lastInsertId();

  // ✅ MUY IMPORTANTE: crear stock SOLO para la sede del usuario
  $insStock = $pdo->prepare("
    INSERT INTO inventory_stock (item_id, branch_id, quantity)
    VALUES (?, ?, 0)
    ON DUPLICATE KEY UPDATE quantity = quantity
  ");
  $insStock->execute([$newItemId, $userBranchId]);

  $resp = [
    "ok" => true,
    "id" => (int)$newItemId,
    "name" => $nombre,
    "category_id" => $category_id,
    "category_name" => $catName,
    "purchase_price" => $precio_compra,
    "sale_price" => $precio_venta,
    "min_stock" => $min_stock
  ];

  $_SESSION["flash_success"] = "✅ Producto registrado correctamente";

  if (wants_json()) {
    header("Content-Type: application/json");
    echo json_encode($resp);
    exit;
  }

  header("Location: items.php");
  exit;

} catch (Exception $e) {
  $resp = ["ok"=>false, "msg"=>"Error guardando producto"];
  if (wants_json()) { header("Content-Type: application/json"); echo json_encode($resp); exit; }
  header("Location: items.php");
  exit;
}
