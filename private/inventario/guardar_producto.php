<?php
declare(strict_types=1);

/**
 * ✅ Estándar CEVIMEP:
 * - No duplicar session_start
 * - Usar _guard.php (ahí ya está el $pdo y seguridad)
 */
require_once __DIR__ . "/../_guard.php";

if (isset($_GET["edit_id"])) {
  $id = (int)$_GET["edit_id"];
  header("Location: /private/inventario/edit_item.php?id=" . $id);
  exit;
}

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

/* Flash */
$flash_ok  = $_SESSION["flash_success"] ?? "";
$flash_err = $_SESSION["flash_error"] ?? "";
unset($_SESSION["flash_success"], $_SESSION["flash_error"]);

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

  // Nombre sucursal
  $branch_name = "";
  try {
    $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
    $stB->execute([$userBranchId]);
    $branch_name = (string)($stB->fetchColumn() ?? "");
  } catch (Throwable $e) {}
  if ($branch_name === "") $branch_name = "Sede #".$userBranchId;

  ?>
  <!doctype html>
  <html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CEVIMEP | Registrar producto</title>

    <!-- ✅ MISMO CSS QUE dashboard.php -->
    <link rel="stylesheet" href="/assets/css/styles.css?v=90">

    <style>
      .page-wrap{
        max-width: 1100px;
        margin: 0 auto;
        padding: 22px 18px 12px;
      }

      .page-header{
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:14px;
        flex-wrap:wrap;
        margin-bottom: 14px;
      }
      .page-title h1{
        margin:0;
        font-size: 34px;
        font-weight: 950;
        letter-spacing: -.3px;
      }
      .page-title p{
        margin: 6px 0 0;
        opacity:.78;
        font-weight: 800;
      }

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
        gap:8px;
        transition: transform .12s ease, filter .15s ease, box-shadow .18s ease;
        white-space: nowrap;
      }
      .btn-ui:hover{ filter: brightness(.98); transform: translateY(-1px); box-shadow: 0 10px 22px rgba(0,0,0,.08); }
      .btn-ui:active{ transform: translateY(0); box-shadow:none; }
      .btn-primary-ui{background:#0b4d87;color:#fff;}
      .btn-secondary-ui{background:#eef2f6;color:#2b3b4a;}

      .flash-ok{background:#e9fff1;border:1px solid #a7f0bf;color:#0a7a33;border-radius:12px;padding:10px 12px;font-size:13px;margin:10px 0;font-weight:850;}
      .flash-err{background:#ffecec;border:1px solid #ffb6b6;color:#a40000;border-radius:12px;padding:10px 12px;font-size:13px;margin:10px 0;font-weight:850;}

      .card{
        background:#fff;
        border-radius:18px;
        box-shadow:0 12px 30px rgba(0,0,0,.08);
        padding:16px;
      }
      .card-head{
        display:flex;
        align-items:flex-end;
        justify-content:space-between;
        gap:10px;
        flex-wrap:wrap;
        margin-bottom: 12px;
      }
      .card-head h3{
        margin:0;
        font-weight: 950;
      }
      .card-head .hint{
        opacity:.72;
        font-weight: 800;
        font-size: 13px;
      }

      .formGrid{
        display:grid;
        grid-template-columns: 1fr 1fr;
        gap:14px;
        margin-top: 10px;
      }
      .field{display:flex;flex-direction:column;gap:6px;}
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

      .note{
        grid-column: 1 / -1;
        background:#f8fafc;
        border:1px solid rgba(2,21,44,.08);
        border-radius:14px;
        padding:10px 12px;
        font-weight:800;
        opacity:.85;
      }

      .actions{
        display:flex;
        gap:10px;
        justify-content:flex-end;
        margin-top:16px;
        flex-wrap:wrap;
      }

      @media (max-width: 820px){
        .formGrid{grid-template-columns:1fr;}
        .page-title h1{font-size: 28px;}
      }
    </style>
  </head>

  <body>

    <!-- NAVBAR -->
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

      <!-- SIDEBAR -->
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
        <div class="page-wrap">

          <div class="page-header">
            <div class="page-title">
              <h1>Registrar nuevo producto</h1>
              <p>Sucursal: <strong><?= h($branch_name) ?></strong> · Min Stock fijo: <strong>3</strong></p>
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap;">
              <a class="btn-ui btn-secondary-ui" href="/private/inventario/items.php">← Volver</a>
            </div>
          </div>

          <?php if ($flash_ok): ?><div class="flash-ok"><?= h($flash_ok) ?></div><?php endif; ?>
          <?php if ($flash_err): ?><div class="flash-err"><?= h($flash_err) ?></div><?php endif; ?>

          <div class="card">
            <div class="card-head">
              <h3>Datos del producto</h3>
              <div class="hint">Completa los datos para agregarlo al inventario de esta sucursal.</div>
            </div>

            <form method="post" action="/private/inventario/guardar_producto.php" autocomplete="off">
              <div class="formGrid">

                <div class="field" style="grid-column:1 / -1;">
                  <label>Nombre del producto</label>
                  <input name="nombre" required placeholder="Ej: Influenza, Varicela, etc.">
                </div>

                <div class="field">
                  <label>Categoría</label>
                  <select name="category_id" required>
                    <option value="0">Selecciona...</option>
                    <?php foreach ($cats as $c): ?>
                      <option value="<?= (int)$c["id"] ?>"><?= h($c["name"]) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="field">
                  <label>Min Stock</label>
                  <input value="3" disabled>
                </div>

                <div class="field">
                  <label>Precio compra (RD$)</label>
                  <input type="number" step="0.01" min="0" name="precio_compra" placeholder="0.00">
                </div>

                <div class="field">
                  <label>Precio venta (RD$)</label>
                  <input type="number" step="0.01" min="0" name="precio_venta" placeholder="0.00">
                </div>

                <div class="note">
                  Nota: este producto se registra y se vincula automáticamente a tu sucursal. El stock inicial dependerá de entradas/salidas.
                </div>

              </div>

              <div class="actions">
                <a class="btn-ui btn-secondary-ui" href="/private/inventario/items.php">Cancelar</a>
                <button class="btn-ui btn-primary-ui" type="submit">Guardar</button>
              </div>
            </form>
          </div>

        </div>
      </main>
    </div>

    <div class="footer">
      <div class="inner">© <?= h($year) ?> CEVIMEP. Todos los derechos reservados.</div>
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

  $conn->beginTransaction();

  // Insert item
  $st = $conn->prepare("
    INSERT INTO inventory_items (category_id, name, sku, unit, purchase_price, sale_price, min_stock, branch_id, is_active)
    VALUES (?, ?, NULL, 'dosis', ?, ?, ?, ?, 1)
  ");
  $st->execute([$category_id, $nombre, $precio_compra, $precio_venta, $min_stock, $userBranchId]);

  $newItemId = (int)$conn->lastInsertId();

  // Vincular a sucursal (stock inicial 0 si no existe ya)
  $stS = $conn->prepare("
    INSERT INTO inventory_stock (branch_id, item_id, quantity)
    VALUES (?, ?, 0)
  ");
  $stS->execute([$userBranchId, $newItemId]);

  $conn->commit();

  if (wants_json()) {
    header("Content-Type: application/json");
    echo json_encode(["ok"=>true, "msg"=>"Producto registrado.", "id"=>$newItemId]);
    exit;
  }

  $_SESSION["flash_success"] = "Producto registrado correctamente.";
  header("Location: /private/inventario/items.php");
  exit;

} catch (Throwable $e) {
  if ($conn->inTransaction()) $conn->rollBack();

  if (wants_json()) {
    header("Content-Type: application/json");
    echo json_encode(["ok"=>false, "msg"=>$e->getMessage()]);
    exit;
  }

  $_SESSION["flash_error"] = $e->getMessage();
  header("Location: /private/inventario/guardar_producto.php");
  exit;
}
