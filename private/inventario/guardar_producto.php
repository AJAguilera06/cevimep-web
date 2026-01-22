<?php
declare(strict_types=1);

/**
 * ✅ MISMO ESTÁNDAR QUE EL RESTO:
 * - No duplicar session_start
 * - Usar _guard.php (ahí ya está el $pdo y seguridad)
 */
require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

$year = (int)date("Y");

function wants_json(): bool {
  $accept = $_SERVER["HTTP_ACCEPT"] ?? "";
  $xhr = $_SERVER["HTTP_X_REQUESTED_WITH"] ?? "";
  return (stripos($accept, "application/json") !== false) || (strtolower($xhr) === "xmlhttprequest");
}
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

$user = $_SESSION["user"] ?? [];
$userBranchId = (int)($user["branch_id"] ?? 0);

if ($userBranchId <= 0) {
  http_response_code(400);
  die("Este usuario no tiene sucursal (branch_id).");
}

/* ==========================
   GET => FORM
========================== */
if ($_SERVER["REQUEST_METHOD"] !== "POST") {

  try {
    $st = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
    $cats = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
  } catch (Throwable $e) {
    $cats = [];
  }
  ?>
  <!doctype html>
  <html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CEVIMEP | Registrar producto</title>

    <!-- ✅ MISMO CSS QUE dashboard.php -->
    <link rel="stylesheet" href="/assets/css/styles.css?v=30">

    <style>
      .card{
        background:#fff;
        border-radius:16px;
        box-shadow:0 12px 30px rgba(0,0,0,.08);
        padding:16px;
        margin-top:14px;
      }
      .formGrid{ display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-top:14px; }
      .field{display:flex;flex-direction:column;gap:8px;}
      .field label{font-weight:900;color:#0b2a4a;font-size:13px;}
      .field input, .field select{
        height:40px;
        padding:0 12px;
        border-radius:14px;
        border:1px solid rgba(2,21,44,.12);
        outline:none;
        font-weight:800;
        background:#fff;
      }
      .field input:focus, .field select:focus{
        border-color:#7fb2ff; box-shadow:0 0 0 3px rgba(127,178,255,.20);
      }
      .actions{display:flex;gap:10px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap;}

      .btn-ui{
        height:38px;
        border:none;
        border-radius:12px;
        padding:0 14px;
        font-weight:900;
        cursor:pointer;
        text-decoration:none;
        display:inline-flex;
        align-items:center;
        justify-content:center;
      }
      .btn-primary-ui{background:#0b4d87;color:#fff;}
      .btn-secondary-ui{background:#eef2f6;color:#2b3b4a;}
      @media (max-width: 820px){ .formGrid{grid-template-columns:1fr;} }
    </style>
  </head>

  <body>

  <!-- NAVBAR (igual dashboard) -->
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

    <!-- SIDEBAR (igual dashboard) -->
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

    <!-- CONTENT -->
    <main class="content">

      <section class="hero">
        <h1>Inventario</h1>
        <p>Registrar nuevo producto</p>
      </section>

      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
          <div>
            <h3 style="margin:0 0 6px;">Registrar nuevo producto</h3>
            <p class="muted" style="margin:0;">Completa los datos para agregarlo al inventario.</p>
          </div>
          <a class="btn-ui btn-secondary-ui" href="/private/inventario/items.php">Volver</a>
        </div>

        <form method="POST" action="/private/inventario/guardar_producto.php">
          <div class="formGrid">

            <div class="field" style="grid-column:1 / -1;">
              <label>Nombre del producto</label>
              <input name="nombre" required>
            </div>

            <div class="field">
              <label>Categoría</label>
              <select name="category_id" required>
                <option value="">Selecciona...</option>
                <?php foreach ($cats as $c): ?>
                  <option value="<?= (int)$c["id"] ?>"><?= h($c["name"]) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label>Min Stock</label>
              <!-- ✅ fijo en 3 -->
              <input type="number" name="min_stock" value="3" readonly>
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
            <button class="btn-ui btn-primary-ui" type="submit">Guardar</button>
            <a class="btn-ui btn-secondary-ui" href="/private/inventario/items.php">Cancelar</a>
          </div>
        </form>
      </div>

    </main>
  </div>

  <div class="footer">
    <div class="inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
  </div>

  </body>
  </html>
  <?php
  exit;
}

/* ==========================
   POST => GUARDAR
========================== */
$nombre        = trim($_POST["nombre"] ?? "");
$category_id   = (int)($_POST["category_id"] ?? 0);
$precio_compra = (float)($_POST["precio_compra"] ?? 0);
$precio_venta  = (float)($_POST["precio_venta"] ?? 0);

/* ✅ Min stock SIEMPRE 3 */
$min_stock     = 3;

if ($nombre === "" || $category_id <= 0) {
  $resp = ["ok"=>false, "msg"=>"Completa nombre y categoría"];
  if (wants_json()) { header("Content-Type: application/json"); echo json_encode($resp); exit; }
  $_SESSION["flash_error"] = "Completa nombre y categoría.";
  header("Location: /private/inventario/guardar_producto.php");
  exit;
}

try {
  // Validar categoría
  $stCat = $conn->prepare("SELECT id, name FROM inventory_categories WHERE id=? LIMIT 1");
  $stCat->execute([$category_id]);
  $rowCat = $stCat->fetch(PDO::FETCH_ASSOC);
  if (!$rowCat) {
    throw new Exception("Categoría inválida");
  }
  $catName = (string)$rowCat["name"];

  // Insert item
  $st = $conn->prepare("
    INSERT INTO inventory_items (category_id, name, sku, unit, purchase_price, sale_price, min_stock, is_active)
    VALUES (?, ?, NULL, 'dosis', ?, ?, ?, 1)
  ");
  $st->execute([$category_id, $nombre, $precio_compra, $precio_venta, $min_stock]);
  $newItemId = (int)$conn->lastInsertId();

  // Stock por sucursal
  $insStock = $conn->prepare("
    INSERT INTO inventory_stock (item_id, branch_id, quantity)
    VALUES (?, ?, 0)
    ON DUPLICATE KEY UPDATE quantity = quantity
  ");
  $insStock->execute([$newItemId, $userBranchId]);

  $resp = ["ok"=>true, "id"=>$newItemId, "name"=>$nombre, "category_id"=>$category_id, "category_name"=>$catName];

  if (wants_json()) {
    header("Content-Type: application/json");
    echo json_encode($resp);
    exit;
  }

  $_SESSION["flash_success"] = "✅ Producto registrado correctamente";
  header("Location: /private/inventario/items.php");
  exit;

} catch (Throwable $e) {
  $resp = ["ok"=>false, "msg"=>"Error guardando producto"];
  if (wants_json()) { header("Content-Type: application/json"); echo json_encode($resp); exit; }
  $_SESSION["flash_error"] = "No se pudo guardar el producto.";
  header("Location: /private/inventario/guardar_producto.php");
  exit;
}
