<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// Evitar cache del navegador/proxy y asegurar que Railway/PHP no sirva una versión vieja
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }
if (function_exists('opcache_reset')) { /* opcional: no lo llamamos para no afectar todo el sitio */ }

$__BUILD_MARK = 'items.php@2026-02-14-railway-01';

$conn = $pdo;
$user = $_SESSION["user"] ?? [];
$year = date("Y");
$branch_id = (int)($user["branch_id"] ?? 0);

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
    // Si no hay permiso para ALTER, la página continúa sin tumbarse.
  }
}

function formatDateDmy(?string $date): string {
  $date = trim((string)$date);
  if ($date === "" || $date === "0000-00-00") return "—";
  $ts = strtotime($date);
  return $ts ? date("d/m/Y", $ts) : $date;
}

ensureExpirationColumn($conn);
$hasExpirationColumn = colExists($conn, "inventory_items", "expiration_date");

if ($branch_id <= 0) {
  http_response_code(400);
  die("Sucursal inválida (branch_id).");
}

/* Categorías */
$categories = [];
try {
  $qc = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
  $categories = $qc ? $qc->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
  $categories = [];
}

$filter_category_id = isset($_GET["category_id"]) ? (int)$_GET["category_id"] : 0;

/* Flash */
$flash_ok  = $_SESSION["flash_success"] ?? "";
$flash_err = $_SESSION["flash_error"] ?? "";
unset($_SESSION["flash_success"], $_SESSION["flash_error"]);

/* ===== LISTADO POR SUCURSAL ===== */
$items = [];
try {
  // Stock SIEMPRE desde inventory_stock por sucursal logueada
  $sql = "
    SELECT
      i.id,
      i.name,
      i.category_id,
      c.name AS category_name,
      COALESCE(s.quantity, 0) AS stock,
      i.purchase_price,
      i.sale_price,
      " . ($hasExpirationColumn ? "i.expiration_date" : "NULL AS expiration_date") . "
    FROM inventory_items i
    LEFT JOIN inventory_stock s
      ON s.item_id = i.id AND s.branch_id = ?
    LEFT JOIN inventory_categories c
      ON c.id = i.category_id
    WHERE i.branch_id = ?
      AND i.is_active = 1
  ";

  $params = [$branch_id, $branch_id];

  if ($filter_category_id > 0) {
    $sql .= " AND i.category_id = ? ";
    $params[] = $filter_category_id;
  }

  $sql .= " ORDER BY i.name ASC ";

  $st = $conn->prepare($sql);
  $st->execute($params);
  $items = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $items = [];
}

/* Links */
$new_product_url = "/private/inventario/guardar_producto.php";
$edit_url_base   = "/private/inventario/edit_item.php?id=";

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Inventario</title>

  <link rel="stylesheet" href="/assets/css/styles.css?v=120">

  <style>
    .page-wrap{
      width: 100%;
      max-width: 1040px;
      margin: 0 auto;
      padding: 24px 18px 18px;
    }

    .inv-title{
      text-align:center;
      font-weight: 950;
      font-size: 36px;
      letter-spacing: -0.8px;
      margin: 6px 0 10px;
    }

    .inv-controls{
      display:grid;
      grid-template-columns: 1fr auto 1fr;
      align-items:center;
      gap: 12px;
      margin: 4px 0 16px;
    }
    .inv-controls__center{ grid-column: 2; justify-self:center; }
    .inv-controls__right{
      grid-column: 3;
      justify-self:end;
      display:flex;
      gap:10px;
      align-items:center;
      flex-wrap:wrap;
    }

    .btn-main{
      height:40px;
      padding:0 16px;
      border-radius: 14px;
      border: 0;
      background: #0b4d87;
      color:#fff;
      font-weight: 950;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:10px;
      text-decoration:none;
      cursor:pointer;
      box-shadow: 0 12px 26px rgba(0,0,0,.12);
      transition: transform .12s ease, filter .15s ease, box-shadow .18s ease;
      white-space:nowrap;
    }
    .btn-main:hover{ filter: brightness(.98); transform: translateY(-1px); }
    .btn-main:active{ transform: translateY(0); box-shadow:none; }

    .select-ui{
      height:40px;
      border:1px solid rgba(2,21,44,.14);
      border-radius:12px;
      padding:0 12px;
      background:#fff;
      outline:none;
      min-width:260px;
      font-weight:850;
    }
    .btn-filter{
      height:40px;
      padding:0 16px;
      border-radius:12px;
      border:0;
      background:#eef2f6;
      color:#2b3b4a;
      font-weight:950;
      cursor:pointer;
      transition:.15s ease;
      white-space:nowrap;
    }
    .btn-filter:hover{ filter: brightness(.98); transform: translateY(-1px); }

    .flash-ok{background:#e9fff1;border:1px solid #a7f0bf;color:#0a7a33;border-radius:12px;padding:10px 12px;font-size:13px;margin:0 0 12px;font-weight:850;}
    .flash-err{background:#ffecec;border:1px solid #ffb6b6;color:#a40000;border-radius:12px;padding:10px 12px;font-size:13px;margin:0 0 12px;font-weight:850;}

    .card{
      background:#fff;
      border-radius:16px;
      box-shadow:0 12px 30px rgba(0,0,0,.08);
      padding:12px;
    }
    .table-wrap{
      width:100%;
      overflow:auto;
      max-height: 460px;
      border-radius:14px;
      border:1px solid rgba(2,21,44,.06);
      -webkit-overflow-scrolling: touch;
    }
    table{ width:100%; border-collapse:separate; border-spacing:0; min-width: 1040px; }
    th, td{ padding:12px 10px; border-bottom:1px solid #eef2f6; font-size:13px; }
    th{
      color:#0b4d87;
      text-align:left;
      font-weight:950;
      font-size:12px;
      position:sticky;
      top:0;
      background:#fff;
      z-index:2;
      white-space: nowrap;
    }
    tr:last-child td{ border-bottom:none; }
    .money{ font-weight:950; white-space:nowrap; }
    .right{ text-align:right; }

    .pill{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:6px 10px;
      border-radius:999px;
      font-weight:900;
      font-size:12px;
      border:1px solid rgba(2,21,44,.12);
      white-space:nowrap;
    }
    .pill-ok{ background:#eafff1; color:#0a7a33; border-color:#b6f0c9; }
    .pill-low{ background:#fff2e6; color:#9a4a00; border-color:#ffd3ad; }
    .pill-out{ background:#ffecec; color:#a40000; border-color:#ffb6b6; }

    .actions{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      justify-content:flex-end;
    }
    .btn-sm{
      height:34px;
      padding:0 12px;
      border-radius:12px;
      font-weight:950;
      border:1px solid rgba(2,21,44,.12);
      background:#eef6ff;
      color:#0b4d87;
      cursor:pointer;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      gap:8px;
      white-space:nowrap;
    }

    .vencimiento-ok{ color:#0a7a33; font-weight:950; white-space:nowrap; }
    .vencimiento-alerta{ color:#a40000; font-weight:950; white-space:nowrap; }
    .vencimiento-vacio{ color:#6b7a88; font-weight:850; white-space:nowrap; }


    @media (max-width: 980px){
      .page-wrap{ max-width: 100%; padding: 18px 14px 14px; }
      .inv-title{ font-size: 34px; }
      .inv-controls{ grid-template-columns: 1fr; }
      .inv-controls__center{ grid-column:auto; justify-self:center; }
      .inv-controls__right{ grid-column:auto; justify-self:center; }
      table{ min-width: 860px; }
    }
  
    @media print {

      .navbar,
      .sidebar,
      .inv-controls,
      .actions,
      .footer {
        display:none !important;
      }

      .page-wrap{
        width:100% !important;
        max-width:100% !important;
        padding:0 !important;
      }

      .card{
        box-shadow:none !important;
        border:none !important;
      }

      .table-wrap{
        overflow:visible !important;
        max-height:none !important;
        border:none !important;
      }

      table{
        min-width:100% !important;
      }

      th:last-child,
      td:last-child{
        display:none !important;
      }

      .inv-title{
        font-size:28px !important;
        margin-bottom:20px !important;
      }
    }


    .print-inventory-compact{
      display:none;
    }

    @media print {
      @page{
        size: letter portrait;
        margin: 8mm;
      }

      html, body{
        background:#fff !important;
        color:#000 !important;
        font-family: Arial, Helvetica, sans-serif !important;
        font-size: 10px !important;
      }

      .navbar,
      .sidebar,
      .inv-controls,
      .actions,
      .footer,
      .card,
      .flash-ok,
      .flash-err,
      .btn-main,
      .btn-filter {
        display:none !important;
      }

      .layout,
      .content,
      .page-wrap{
        display:block !important;
        width:100% !important;
        max-width:100% !important;
        margin:0 !important;
        padding:0 !important;
        overflow:visible !important;
      }

      .inv-title{
        font-size:18px !important;
        margin:0 0 6mm 0 !important;
        text-align:center !important;
        font-weight:900 !important;
      }

      .print-inventory-compact{
        display:block !important;
        width:100% !important;
      }

      .print-compact-table{
        width:100% !important;
        border-collapse:collapse !important;
        table-layout:fixed !important;
      }

      .print-compact-table td{
        border-bottom:1px solid #ddd !important;
        padding:4px 5px !important;
        vertical-align:middle !important;
        font-size:10px !important;
        line-height:1.15 !important;
      }

      .print-compact-table .p-name{
        width:38% !important;
        font-weight:800 !important;
        text-transform:uppercase !important;
      }

      .print-compact-table .p-stock{
        width:12% !important;
        text-align:right !important;
        font-weight:900 !important;
        white-space:nowrap !important;
      }
    }

  
    /* ===== IMPRESIÓN COMPACTA: 2 BLOQUES PRODUCTO/STOCK ===== */
    @media print {
      @page{
        size: letter portrait;
        margin: 6mm 5mm;
      }

      html, body{
        background:#fff !important;
        color:#000 !important;
        font-family: Arial, Helvetica, sans-serif !important;
        font-size: 9px !important;
      }

      .navbar,
      .sidebar,
      .inv-controls,
      .actions,
      .footer,
      .card,
      .flash-ok,
      .flash-err,
      .btn-main,
      .btn-filter {
        display:none !important;
      }

      .layout,
      .content,
      .page-wrap{
        display:block !important;
        width:100% !important;
        max-width:100% !important;
        margin:0 !important;
        padding:0 !important;
        overflow:visible !important;
      }

      .inv-title{
        font-size:15px !important;
        margin:0 0 4mm 0 !important;
        text-align:center !important;
        font-weight:900 !important;
      }

      .print-inventory-compact{
        display:block !important;
        width:100% !important;
      }

      .print-compact-table{
        width:100% !important;
        border-collapse:collapse !important;
        table-layout:fixed !important;
      }

      .print-compact-table th{
        position:static !important;
        top:auto !important;
        background:#fff !important;
        color:#000 !important;
        border-bottom:1px solid #000 !important;
        padding:2px 4px !important;
        font-size:8px !important;
        line-height:1 !important;
        text-align:left !important;
        font-weight:900 !important;
      }

      .print-compact-table td{
        border-bottom:1px solid #d8d8d8 !important;
        padding:2px 4px !important;
        vertical-align:middle !important;
        font-size:8px !important;
        line-height:1.05 !important;
      }

      .print-compact-table .p-name{
        width:38% !important;
        font-weight:900 !important;
        text-transform:uppercase !important;
        white-space:nowrap !important;
        overflow:hidden !important;
        text-overflow:ellipsis !important;
      }

      .print-compact-table .p-stock{
        width:9% !important;
        text-align:right !important;
        font-weight:900 !important;
        white-space:nowrap !important;
      }

      .print-compact-table .gap{
        width:6% !important;
        border-bottom:none !important;
      }

      .print-compact-table tr{
        height:12px !important;
        page-break-inside:avoid !important;
      }

      .print-compact-table th:nth-child(1),
      .print-compact-table td:nth-child(1){ width:38% !important; }

      .print-compact-table th:nth-child(2),
      .print-compact-table td:nth-child(2){ width:9% !important; text-align:right !important; }

      .print-compact-table th:nth-child(3),
      .print-compact-table td:nth-child(3){ width:6% !important; }

      .print-compact-table th:nth-child(4),
      .print-compact-table td:nth-child(4){ width:38% !important; }

      .print-compact-table th:nth-child(5),
      .print-compact-table td:nth-child(5){ width:9% !important; text-align:right !important; }
    }

  </style>
</head>

<body>

<!-- BUILD: <?= h($__BUILD_MARK) ?> | branch_id=<?= (int)$branch_id ?> | ts=<?= date('Y-m-d H:i:s') ?> -->

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

  <aside class="sidebar">
    <div class="menu-title">Menú</div>

    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👤</span> Pacientes</a>
      <a href="#" onclick="return false;" style="opacity:.5;cursor:not-allowed;">
    📅 Citas (Próximamente)
</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💳</span> Caja</a>
      <a href="/private/inventario/index.php">📦 Inventario</a>
      <a href="/private/estadistica/index.php">📊 Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <div class="page-wrap">

      <div class="inv-title">Inventario</div>

      <?php if ($flash_ok): ?>
        <div class="flash-ok"><?= h($flash_ok) ?></div>
      <?php endif; ?>
      <?php if ($flash_err): ?>
        <div class="flash-err"><?= h($flash_err) ?></div>
      <?php endif; ?>

      <div class="inv-controls">
        <div></div>

        <div class="inv-controls__center">
          <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
            <a class="btn-main" href="<?= h($new_product_url) ?>">➕ Nuevo Producto</a>
            <button type="button" class="btn-main" onclick="window.print()">🖨️ Imprimir Inventario</button>
          </div>
        </div>

        <div class="inv-controls__right">
          <form method="get" action="/private/inventario/items.php" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <select class="select-ui" name="category_id">
              <option value="0">Todas las categorías</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c["id"] ?>" <?= ($filter_category_id === (int)$c["id"]) ? "selected" : "" ?>>
                  <?= h($c["name"]) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="btn-filter" type="submit">Filtrar</button>
          </form>
        </div>
      </div>

      <div class="print-inventory-compact">
        <table class="print-compact-table">
          <thead>
            <tr>
              <th>Producto</th>
              <th>Stock</th>
              <th class="gap"></th>
              <th>Producto</th>
              <th>Stock</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $chunks = array_chunk($items, 2);
              foreach ($chunks as $pair):
                $left = $pair[0] ?? null;
                $right = $pair[1] ?? null;
            ?>
              <tr>
                <?php if ($left): ?>
                  <td class="p-name"><?= h($left["name"] ?? "") ?></td>
                  <td class="p-stock"><?= number_format((float)($left["stock"] ?? 0), 2) ?></td>
                <?php else: ?>
                  <td></td><td></td>
                <?php endif; ?>

                <td class="gap"></td>

                <?php if ($right): ?>
                  <td class="p-name"><?= h($right["name"] ?? "") ?></td>
                  <td class="p-stock"><?= number_format((float)($right["stock"] ?? 0), 2) ?></td>
                <?php else: ?>
                  <td></td><td></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>


      <div class="card">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Producto</th>
                <th>Categoría</th>
                <th class="right">Compra</th>
                <th class="right">Venta</th>
                <th class="right">Stock</th>
                <th class="right">Vencimiento</th>
                <th class="right">Acciones</th>
              </tr>
            </thead>

            <tbody>
              <?php if (!$items): ?>
                <tr>
                  <td colspan="7" style="padding:18px; text-align:center; font-weight:900; color:#6b7a88;">
                    No hay productos registrados para esta sucursal.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($items as $it): ?>
                  <?php
                    $stock = (float)($it["stock"] ?? 0);

if ($stock <= 0) {
  $pillClass = "pill-out";
  $pillText = number_format($stock, 2) . " · Agotado";
}
elseif ($stock <= 1) {
  $pillClass = "pill-low";
  $pillText = number_format($stock, 2) . " · Bajo";
}
else {
  $pillClass = "pill-ok";
  $pillText = number_format($stock, 2) . " · OK";
}

$expirationDate = trim((string)($it["expiration_date"] ?? ""));
$expirationText = formatDateDmy($expirationDate);
$expirationClass = "vencimiento-vacio";

if ($expirationDate !== "" && $expirationDate !== "0000-00-00") {
  $today = new DateTime("today");
  $limit = (clone $today)->modify("+1 month");
  $exp = DateTime::createFromFormat("Y-m-d", $expirationDate);

  if ($exp && $exp <= $limit) {
    $expirationClass = "vencimiento-alerta";
  } else {
    $expirationClass = "vencimiento-ok";
  }
}
                  ?>
                  <tr>
                    <td style="font-weight:950;"><?= h($it["name"] ?? "") ?></td>
                    <td><?= h($it["category_name"] ?? "—") ?></td>
                    <td class="right money"><?= number_format((float)($it["purchase_price"] ?? 0), 2) ?></td>
                    <td class="right money"><?= number_format((float)($it["sale_price"] ?? 0), 2) ?></td>
                    <td class="right">
                      <span class="pill <?= h($pillClass) ?>"><?= h($pillText) ?></span>
                    </td>
                    <td class="right">
                      <span class="<?= h($expirationClass) ?>"><?= h($expirationText) ?></span>
                    </td>
                    <td class="right">
                      <div class="actions">
                        <a class="btn-sm" href="<?= h($edit_url_base . (int)$it["id"]) ?>">✏️ Editar</a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div style="text-align:center; margin-top: 14px; font-weight: 850; color:#6b7a88;">
        © <?= h($year) ?> CEVIMEP
      </div>

    </div>
  </main>

</div>
<!-- FOOTER (igual dashboard.php) -->
<footer class="footer">
    © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>
</body>
</html>
