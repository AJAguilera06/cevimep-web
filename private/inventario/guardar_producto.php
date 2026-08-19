<?php
declare(strict_types=1);

/**
 * ✅ Estándar CEVIMEP:
 * - No duplicar session_start
 * - Usar _guard.php (ahí ya está el $pdo y seguridad)
 */
require_once __DIR__ . "/../_guard.php";
require_once __DIR__ . "/../csrf.php";

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

function colExists(PDO $conn, string $table, string $col): bool {
  try {
    $st = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return false;
  }
}

function ensureExpirationColumn(PDO $conn): void {
  try {
    if (!colExists($conn, "inventory_items", "expiration_date")) {
      $conn->exec("ALTER TABLE inventory_items ADD COLUMN expiration_date DATE NULL AFTER sale_price");
    }
  } catch (Throwable $e) {
    // Si el usuario de BD no tiene permiso para ALTER, el sistema seguirá funcionando sin tumbar la página.
  }
}

ensureExpirationColumn($conn);

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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
      :root{
          --navy-deep:#071B32;
          --teal:#14B8A6;
          --teal-dark:#0D9488;
      }
      *{ box-sizing:border-box; }
      body{
          margin:0;
          font-family:'Inter',sans-serif;
          background:var(--navy-deep);
          color:#fff;
          min-height:100vh;
          display:flex;
          flex-direction:column;
      }
      .navbar{ background:rgba(255,255,255,.04); border-bottom:1px solid rgba(255,255,255,.08); }
      .navbar .inner{ display:grid; grid-template-columns:1fr auto 1fr; align-items:center; padding:16px 28px; }
      .navbar .brand{ grid-column:2; justify-self:center; }
      .navbar .nav-right{ grid-column:3; justify-self:end; }
      .brand{ display:flex; align-items:center; gap:10px; font-family:'Manrope'; font-weight:800; font-size:15px; letter-spacing:.04em; }
      .brand .dot{ width:9px;height:9px;border-radius:50%;background:var(--teal); box-shadow:0 0 0 4px rgba(20,184,166,.18); }
      .btn-pill{ background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.16); color:#fff; padding:9px 16px; border-radius:999px; text-decoration:none; font-weight:700; font-size:13px; }
      .btn-pill:hover{ background:rgba(255,255,255,.14); }

      .layout{ display:flex; width:100%; position:relative; flex:1; }
      .auth-bg{ position:fixed; inset:0; width:100%; height:100%; z-index:0; opacity:.55; pointer-events:none; }

      .sidebar{ position:relative; z-index:1; width:230px; flex-shrink:0; padding:24px 16px; }
      .menu-title{ font-weight:700; font-size:12px; letter-spacing:.08em; text-transform:uppercase; color:#7D95AC; margin:0 0 14px 6px; }
      .menu{ display:flex; flex-direction:column; gap:2px; }
      .menu a{ display:block; padding:10px 12px; border-radius:10px; text-decoration:none; font-size:14px; font-weight:600; color:#B9CBDD; }
      .menu a:hover{ background:rgba(255,255,255,.06); }
      .menu a.active{ background:rgba(20,184,166,.18); color:var(--teal); }

      .content{ position:relative; z-index:1; flex:1; width:100%; padding:40px 40px 60px; }
      .footer{ position:relative; z-index:1; text-align:center; padding:20px; font-size:12px; color:#5C7896; }

      .page-wrap{
        max-width: 1100px;
        margin: 0 auto;
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
        font-family:'Manrope';
        font-size: 34px;
        font-weight: 900;
        letter-spacing: -.3px;
      }
      .page-title p{
        margin: 6px 0 0;
        color:#9FB6CC;
        font-weight: 800;
      }

      .btn-ui{
        height:38px;
        border:none;
        border-radius:999px;
        padding:0 14px;
        font-weight:900;
        cursor:pointer;
        text-decoration:none;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:8px;
        transition: transform .12s ease, filter .15s ease, box-shadow .18s ease, background .15s ease;
        white-space: nowrap;
        border:1px solid rgba(255,255,255,.16);
      }
      .btn-ui:hover{ transform: translateY(-1px); box-shadow: 0 10px 22px rgba(0,0,0,.25); }
      .btn-ui:active{ transform: translateY(0); box-shadow:none; }
      .btn-primary-ui{background:var(--teal);color:#04211d;border-color:var(--teal);}
      .btn-primary-ui:hover{background:var(--teal-dark);}
      .btn-secondary-ui{background:rgba(255,255,255,.08);color:#fff;}
      .btn-secondary-ui:hover{background:rgba(255,255,255,.14);}

      .flash-ok{background:rgba(16,185,129,.14);border:1px solid rgba(16,185,129,.35);color:#34d399;border-radius:12px;padding:10px 12px;font-size:13px;margin:10px 0;font-weight:850;}
      .flash-err{background:rgba(220,38,38,.14);border:1px solid rgba(220,38,38,.35);color:#f87171;border-radius:12px;padding:10px 12px;font-size:13px;margin:10px 0;font-weight:850;}

      .card{
        background:rgba(255,255,255,.07);
        backdrop-filter:blur(14px);
        -webkit-backdrop-filter:blur(14px);
        border:1px solid rgba(255,255,255,.12);
        border-radius:18px;
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
        font-weight: 900;
        color:#fff;
      }
      .card-head .hint{
        color:#9FB6CC;
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
      .field label{font-weight:900;color:#9FB6CC;font-size:13px;}
      .field input, .field select{
        height:40px;
        padding:0 12px;
        border-radius:14px;
        border:1px solid rgba(255,255,255,.16);
        outline:none;
        font-weight:800;
        background:rgba(255,255,255,.06);
        color:#fff;
      }
      .field input::placeholder{ color:#7D95AC; }
      .field input:focus, .field select:focus{
        border-color:var(--teal); box-shadow:0 0 0 3px rgba(20,184,166,.20);
      }
      .field select option{ background:var(--navy-deep); color:#fff; }
      .field input[disabled]{
        background:rgba(255,255,255,.03) !important;
        color:#9FB6CC !important;
        cursor:not-allowed !important;
      }

      .note{
        grid-column: 1 / -1;
        background:rgba(255,255,255,.04);
        border:1px solid rgba(255,255,255,.1);
        border-radius:14px;
        padding:10px 12px;
        font-weight:800;
        color:#9FB6CC;
      }

      .actions{
        display:flex;
        gap:10px;
        justify-content:flex-end;
        margin-top:16px;
        flex-wrap:wrap;
      }

      @media(max-width:980px){
        .layout{ flex-direction:column; }
        .sidebar{ width:100%; }
        .content{ padding:24px 18px 40px; }
      }
      @media (max-width: 820px){
        .formGrid{grid-template-columns:1fr;}
        .page-title h1{font-size: 28px;}
      }
    </style>
  </head>

  <body>

  <svg class="auth-bg" viewBox="0 0 1400 800" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
      <circle cx="130" cy="80" r="240" fill="#0D3A5C" opacity=".5"/>
      <circle cx="1280" cy="650" r="280" fill="#0F4A63" opacity=".45"/>
      <g fill="none" stroke="#1D5C7A" stroke-width="1" opacity=".7">
          <line x1="60" y1="60" x2="180" y2="130"/><line x1="180" y1="130" x2="140" y2="250"/>
          <line x1="1220" y1="560" x2="1300" y2="620"/><line x1="1300" y1="620" x2="1250" y2="700"/>
      </g>
      <g fill="#14B8A6" opacity=".85">
          <circle cx="60" cy="60" r="3"/><circle cx="180" cy="130" r="4"/><circle cx="140" cy="250" r="3"/>
          <circle cx="1220" cy="560" r="3"/><circle cx="1300" cy="620" r="4"/><circle cx="1250" cy="700" r="3"/>
      </g>
  </svg>

    <header class="navbar">
      <div class="inner">
        <div class="brand"><span class="dot"></span><span>CEVIMEP</span></div>
        <div class="nav-right"><a href="/logout.php" class="btn-pill">Salir</a></div>
      </div>
    </header>

    <div class="layout">

      <aside class="sidebar">
        <div class="menu-title">Menú</div>
        <nav class="menu">
          <a href="/private/dashboard.php">🏠 Panel</a>
          <a href="/private/patients/index.php">👤 Pacientes</a>
          <a href="#" onclick="return false;" style="opacity:.5;cursor:not-allowed;">📅 Citas (Próximamente)</a>
          <a href="/private/facturacion/index.php">🧾 Facturación</a>
          <a href="/private/caja/index.php">💳 Caja</a>
          <a class="active" href="/private/inventario/index.php">📦 Inventario</a>
          <a href="/private/estadistica/index.php">📊 Estadísticas</a>
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
          <?= csrf_field() ?>
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
                  <label>Precio venta (RD$)</label>
                  <input type="number" step="0.01" min="0" name="precio_venta" placeholder="0.00">
                </div>

                <div class="field">
                  <label>Fecha de vencimiento</label>
                  <input
                    id="expiration_date_view"
                    type="text"
                    placeholder="DD/MM/AAAA"
                    maxlength="10"
                    inputmode="numeric"
                  >
                  <input
                    id="expiration_date"
                    type="hidden"
                    name="expiration_date"
                  >
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

    <footer class="footer">© <?= h($year) ?> CEVIMEP — Todos los derechos reservados.</footer>


<script>
(function () {
  const view = document.getElementById('expiration_date_view');
  const hidden = document.getElementById('expiration_date');

  if (!view || !hidden) return;

  function formatExpirationDate() {
    let v = view.value.replace(/\D/g, '').slice(0, 8);

    if (v.length >= 5) {
      view.value = v.slice(0, 2) + '/' + v.slice(2, 4) + '/' + v.slice(4);
    } else if (v.length >= 3) {
      view.value = v.slice(0, 2) + '/' + v.slice(2);
    } else {
      view.value = v;
    }

    const parts = view.value.split('/');
    if (parts.length === 3 && parts[0].length === 2 && parts[1].length === 2 && parts[2].length === 4) {
      hidden.value = parts[2] + '-' + parts[1] + '-' + parts[0];
    } else {
      hidden.value = '';
    }
  }

  view.addEventListener('input', formatExpirationDate);

  const form = view.closest('form');
  if (form) {
    form.addEventListener('submit', formatExpirationDate);
  }
})();
</script>

  </body>
  </html>
  <?php
  exit;
}

/* ==========================
   POST => GUARDAR
========================== */
csrf_validate_post();

$nombre        = trim($_POST["nombre"] ?? "");
$category_id   = (int)($_POST["category_id"] ?? 0);

$precio_venta    = (float)($_POST["precio_venta"] ?? 0);
$expiration_date = trim((string)($_POST["expiration_date"] ?? ""));

if ($expiration_date !== "" && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiration_date)) {
  $expiration_date = "";
}

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

  // Insert item ajustado a la base de datos actual:
  // Sin sku y sin purchase_price. Con fecha de vencimiento si la columna existe.
  if (colExists($conn, "inventory_items", "expiration_date")) {
    $st = $conn->prepare("
      INSERT INTO inventory_items (category_id, name, unit, sale_price, expiration_date, min_stock, branch_id, is_active)
      VALUES (?, ?, 'dosis', ?, ?, ?, ?, 1)
    ");
    $st->execute([
      $category_id,
      $nombre,
      $precio_venta,
      $expiration_date !== "" ? $expiration_date : null,
      $min_stock,
      $userBranchId
    ]);
  } else {
    $st = $conn->prepare("
      INSERT INTO inventory_items (category_id, name, unit, sale_price, min_stock, branch_id, is_active)
      VALUES (?, ?, 'dosis', ?, ?, ?, 1)
    ");
    $st->execute([$category_id, $nombre, $precio_venta, $min_stock, $userBranchId]);
  }

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