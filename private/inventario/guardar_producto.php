<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . "/../../config/db.php";

if (empty($_SESSION["user"])) {
  header("Location: /login.php");
  exit;
}

$year = (int)date("Y");

/* detectar si viene por AJAX */
function wants_json(): bool {
  $accept = $_SERVER["HTTP_ACCEPT"] ?? "";
  $xhr = $_SERVER["HTTP_X_REQUESTED_WITH"] ?? "";
  return (stripos($accept, "application/json") !== false) || (strtolower($xhr) === "xmlhttprequest");
}

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

/**
 * Check column existence (best-effort). Si Railway restringe INFORMATION_SCHEMA, devuelve false.
 */
function table_has_column(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = :t
        AND COLUMN_NAME = :c
      LIMIT 1
    ");
    $st->execute(["t" => $table, "c" => $column]);
    return ((int)$st->fetchColumn()) > 0;
  } catch (Throwable $e) {
    return false;
  }
}

$catsHasIsActive  = table_has_column($pdo, "inventory_categories", "is_active");
$itemHasIsActive  = table_has_column($pdo, "inventory_items", "is_active");
$itemHasSku       = table_has_column($pdo, "inventory_items", "sku");
$itemHasUnit      = table_has_column($pdo, "inventory_items", "unit");
$itemHasPBuy      = table_has_column($pdo, "inventory_items", "purchase_price");
$itemHasPSale     = table_has_column($pdo, "inventory_items", "sale_price");
$itemHasMinStock  = table_has_column($pdo, "inventory_items", "min_stock");

/* ==========================
   GET => FORM (DISEÑO DASHBOARD)
========================== */
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  try {
    if ($catsHasIsActive) {
      $st = $pdo->query("SELECT id, name FROM inventory_categories WHERE is_active=1 ORDER BY name ASC");
    } else {
      $st = $pdo->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
    }
    $cats = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
  } catch (Throwable $e) {
    $cats = [];
  }
  ?>
  <!doctype html>
  <html lang="es">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>CEVIMEP | Registrar producto</title>

    <!-- EXACTO igual que dashboard.php -->
    <link rel="stylesheet" href="/assets/css/styles.css?v=11">

    <style>
      /* Form dentro del content, usando el card del dashboard */
      .formGrid{
        display:grid;
        grid-template-columns: 1fr 1fr;
        gap:14px;
        margin-top:14px;
      }
      .field{display:flex;flex-direction:column;gap:8px;}
      .field label{font-weight:900;color:#0b2a4a;font-size:13px;}
      .field input, .field select{
        padding:10px 12px;
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
      .actions{display:flex;gap:10px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap;}
      .btnLink{ text-decoration:none; display:inline-flex; align-items:center; justify-content:center; }
      @media (max-width: 820px){
        .formGrid{grid-template-columns:1fr;}
      }
    </style>
  </head>

  <body>

  <header class="navbar">
    <div class="inner">
      <div></div>
      <div class="brand"><span class="dot"></span> CEVIMEP</div>
      <div class="nav-right">
        <a class="btn-pill" href="/logout.php">Salir</a>
      </div>
    </div>
  </header>

  <div class="layout">

    <aside class="sidebar">
      <div class="menu-title">Menú</div>

      <nav class="menu">
        <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
        <a href="/private/patients/index.php"><span class="ico">👥</span> Pacientes</a>

        <a href="javascript:void(0)" style="opacity:.45; cursor:not-allowed;">
          <span class="ico">🗓️</span> Citas
        </a>

        <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
        <a href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>

        <a class="active" href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
        <a href="/private/estadistica/index.php"><span class="ico">📊</span> Estadísticas</a>
      </nav>
    </aside>

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
          <a class="btn-pill btnLink" href="/private/inventario/items.php">Volver</a>
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
            <button class="btn-pill" type="submit">Guardar</button>
            <a class="btn-pill btnLink" href="/private/inventario/items.php" style="background:#eef6ff;border:1px solid rgba(2,21,44,.12);color:#0b2a4a;">Cancelar</a>
          </div>
        </form>

      </div>

    </main>
  </div>

  <footer class="footer">
    <div class="footer-inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
  </footer>

  </body>
  </html>
  <?php
  exit;
}

/* ==========================
   POST => GUARDAR (MISMA LÓGICA, MÁS ROBUSTO)
========================== */
$nombre        = trim($_POST["nombre"] ?? "");
$category_id   = (int)($_POST["category_id"] ?? 0);
$precio_compra = (float)($_POST["precio_compra"] ?? 0);
$precio_venta  = (float)($_POST["precio_venta"] ?? 0);
$min_stock     = (int)($_POST["min_stock"] ?? 0);

$userBranchId  = (int)($_SESSION["user"]["branch_id"] ?? 0);

if ($userBranchId <= 0) {
  $resp = ["ok" => false, "msg" => "Este usuario no tiene sede asignada. No se puede registrar productos."];
  if (wants_json()) { header("Content-Type: application/json"); echo json_encode($resp); exit; }
  header("Location: /private/inventario/items.php");
  exit;
}

if ($nombre === "" || $category_id <= 0) {
  $resp = ["ok" => false, "msg" => "Completa nombre y categoría"];
  if (wants_json()) { header("Content-Type: application/json"); echo json_encode($resp); exit; }
  header("Location: /private/inventario/items.php");
  exit;
}

try {
  // Validar categoría (sin romper si is_active existe o no)
  $stCat = $pdo->prepare("SELECT id, name FROM inventory_categories WHERE id=? LIMIT 1");
  $stCat->execute([$category_id]);
  $rowCat = $stCat->fetch(PDO::FETCH_ASSOC);
  $catName = $rowCat ? (string)$rowCat["name"] : "";

  $pdo->beginTransaction();

  // 1) Intento A: insert “completo” si existen columnas
  $cols = ["category_id", "name"];
  $vals = [":category_id", ":name"];
  $params = ["category_id" => $category_id, "name" => $nombre];

  if ($itemHasSku) { $cols[]="sku"; $vals[]="NULL"; }
  if ($itemHasUnit) { $cols[]="unit"; $vals[]=":unit"; $params["unit"]="dosis"; }
  if ($itemHasPBuy) { $cols[]="purchase_price"; $vals[]=":pb"; $params["pb"]=$precio_compra; }
  if ($itemHasPSale) { $cols[]="sale_price"; $vals[]=":ps"; $params["ps"]=$precio_venta; }
  if ($itemHasMinStock) { $cols[]="min_stock"; $vals[]=":ms"; $params["ms"]=$min_stock; }
  if ($itemHasIsActive) { $cols[]="is_active"; $vals[]="1"; }

  $sql = "INSERT INTO inventory_items (" . implode(",", $cols) . ") VALUES (" . implode(",", $vals) . ")";
  $st = $pdo->prepare($sql);
  $st->execute($params);

  $newItemId = (int)$pdo->lastInsertId();

  // Stock por sucursal (manteniendo tu lógica)
  try {
    $insStock = $pdo->prepare("
      INSERT INTO inventory_stock (item_id, branch_id, quantity)
      VALUES (?, ?, 0)
      ON DUPLICATE KEY UPDATE quantity = quantity
    ");
    $insStock->execute([$newItemId, $userBranchId]);
  } catch (Throwable $e) {
    // Fallback si no existe UNIQUE o falla el ON DUPLICATE
    $chk = $pdo->prepare("SELECT 1 FROM inventory_stock WHERE item_id=? AND branch_id=? LIMIT 1");
    $chk->execute([$newItemId, $userBranchId]);
    if (!$chk->fetchColumn()) {
      $ins2 = $pdo->prepare("INSERT INTO inventory_stock (item_id, branch_id, quantity) VALUES (?, ?, 0)");
      $ins2->execute([$newItemId, $userBranchId]);
    }
  }

  $pdo->commit();

  $_SESSION["flash_success"] = "✅ Producto registrado correctamente";

  $resp = [
    "ok" => true,
    "id" => $newItemId,
    "name" => $nombre,
    "category_id" => $category_id,
    "category_name" => $catName,
    "purchase_price" => $precio_compra,
    "sale_price" => $precio_venta,
    "min_stock" => $min_stock
  ];

  if (wants_json()) {
    header("Content-Type: application/json");
    echo json_encode($resp);
    exit;
  }

  header("Location: /private/inventario/items.php");
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();

  $resp = ["ok" => false, "msg" => "Error guardando producto"];
  if (wants_json()) {
    header("Content-Type: application/json");
    echo json_encode($resp);
    exit;
  }
  header("Location: /private/inventario/items.php");
  exit;
}
