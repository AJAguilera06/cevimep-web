<?php
declare(strict_types=1);

date_default_timezone_set('America/Santo_Domingo');

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

$__BUILD_MARK = 'items.php@2026-08-19-dark-theme-v1';

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

  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
    return $m[3] . "/" . $m[2] . "/" . $m[1];
  }

  return $date;
}

ensureExpirationColumn($conn);

if ($branch_id <= 0) {
  http_response_code(400);
  die("Sucursal inválida (branch_id).");
}

/* Nombre sucursal */
$branch_name = "";
try {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $branch_name = (string)($stB->fetchColumn() ?: "");
} catch (Throwable $e) {}

if ($branch_name === "") {
  $branch_name = "Sucursal #" . $branch_id;
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
      i.sale_price,
      i.expiration_date
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

    .content{ position:relative; z-index:1; flex:1; width:100%; padding:26px 22px 60px; overflow:auto; }
    .footer{ position:relative; z-index:1; text-align:center; padding:20px; font-size:12px; color:#5C7896; }

    .page-wrap{
      width: 100%;
      max-width: 1240px;
      margin: 0 auto;
    }

    .inv-title{
      text-align:center;
      font-family:'Manrope';
      font-weight: 900;
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
      border-radius: 999px;
      border: 1px solid var(--teal);
      background: var(--teal);
      color:#04211d;
      font-weight: 950;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:10px;
      text-decoration:none;
      cursor:pointer;
      transition: transform .12s ease, filter .15s ease, box-shadow .18s ease, background .15s ease;
      white-space:nowrap;
    }
    .btn-main:hover{ background:var(--teal-dark); transform: translateY(-1px); }
    .btn-main:active{ transform: translateY(0); box-shadow:none; }

    .select-ui{
      height:40px;
      border:1px solid rgba(255,255,255,.16);
      border-radius:12px;
      padding:0 12px;
      background:rgba(255,255,255,.06);
      color:#fff;
      outline:none;
      min-width:260px;
      font-weight:850;
    }
    .select-ui option{ background:var(--navy-deep); color:#fff; }
    .btn-filter{
      height:40px;
      padding:0 16px;
      border-radius:12px;
      border:1px solid rgba(255,255,255,.16);
      background:rgba(255,255,255,.08);
      color:#fff;
      font-weight:950;
      cursor:pointer;
      transition:.15s ease;
      white-space:nowrap;
    }
    .btn-filter:hover{ background:rgba(255,255,255,.14); transform: translateY(-1px); }

    .flash-ok{background:rgba(16,185,129,.14);border:1px solid rgba(16,185,129,.35);color:#34d399;border-radius:12px;padding:10px 12px;font-size:13px;margin:0 0 12px;font-weight:850;}
    .flash-err{background:rgba(220,38,38,.14);border:1px solid rgba(220,38,38,.35);color:#f87171;border-radius:12px;padding:10px 12px;font-size:13px;margin:0 0 12px;font-weight:850;}

    .card{
      background:rgba(255,255,255,.07);
      backdrop-filter:blur(14px);
      -webkit-backdrop-filter:blur(14px);
      border:1px solid rgba(255,255,255,.12);
      border-radius:16px;
      padding:12px;
    }
    .table-wrap{
      width:100%;
      overflow-y:auto;
      overflow-x:hidden;
      max-height: 460px;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.1);
      -webkit-overflow-scrolling: touch;
    }
    table{ width:100%; border-collapse:separate; border-spacing:0; min-width:0; table-layout:auto; }
    th, td{ padding:12px 10px; border-bottom:1px solid rgba(255,255,255,.08); font-size:13px; color:#E7EEF6; }
    th{
      color:var(--teal);
      text-align:left;
      font-weight:950;
      font-size:12px;
      position:sticky;
      top:0;
      background:#0C2545;
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
      border:1px solid rgba(255,255,255,.16);
      white-space:nowrap;
    }
    .pill-ok{ background:rgba(16,185,129,.14); color:#34d399; border-color:rgba(16,185,129,.35); }
    .pill-low{ background:rgba(234,179,8,.14); color:#facc15; border-color:rgba(234,179,8,.35); }
    .pill-out{ background:rgba(220,38,38,.14); color:#f87171; border-color:rgba(220,38,38,.35); }

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
      border:1px solid rgba(20,184,166,.4);
      background:rgba(20,184,166,.16);
      color:var(--teal);
      cursor:pointer;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      gap:8px;
      white-space:nowrap;
      transition: background .15s ease;
    }
    .btn-sm:hover{ background:rgba(20,184,166,.26); }

    .vencimiento-ok{ color:#34d399; font-weight:950; white-space:nowrap; }
    .vencimiento-alerta{ color:#f87171; font-weight:950; white-space:nowrap; }
    .vencimiento-vacio{ color:#9FB6CC; font-weight:850; white-space:nowrap; }


    @media (max-width: 980px){
      .layout{ flex-direction:column; }
      .sidebar{ width:100%; }
      .content{ padding:20px 14px 40px; }
      .inv-title{ font-size: 34px; }
      .inv-controls{ grid-template-columns: 1fr; }
      .inv-controls__center{ grid-column:auto; justify-self:center; }
      .inv-controls__right{ grid-column:auto; justify-self:center; }
      table{ min-width:0; }
    }

    /* ===================================================
       IMPRESIÓN: tabla compacta blanco/negro, 3 columnas
       (Producto | Stock | Vencimiento). Se mantiene clara
       intencionalmente para ahorrar tinta e imprimir bien.
       =================================================== */
    .print-inventory-compact{ display:none; }

    @media print{
      *{ -webkit-print-color-adjust:exact!important; print-color-adjust:exact!important; }

      @page{ size: letter portrait; margin: 7mm; }

      html, body{
        background:#fff !important;
        color:#000 !important;
        font-family: Arial, Helvetica, sans-serif !important;
      }

      .navbar, .sidebar, .footer, .auth-bg,
      .inv-controls, .actions, .card,
      .flash-ok, .flash-err, .btn-main, .btn-filter{
        display:none !important;
      }

      .layout, .content, .page-wrap{
        display:block !important;
        width:100% !important;
        max-width:100% !important;
        margin:0 !important;
        padding:0 !important;
        overflow:visible !important;
      }

      .inv-title{ display:none !important; }

      .print-inventory-compact{
        display:block !important;
        width:100% !important;
      }

      .print-header{
        display:flex !important;
        align-items:flex-start !important;
        justify-content:space-between !important;
        gap:10px !important;
        margin:0 0 6mm 0 !important;
        width:100% !important;
      }
      .print-title{
        font-size:16px !important;
        font-weight:900 !important;
        color:#000 !important;
      }
      .print-date{
        font-size:10px !important;
        font-weight:800 !important;
        color:#000 !important;
        white-space:nowrap !important;
      }

      .print-compact-table{
        width:100% !important;
        border-collapse:collapse !important;
        table-layout:fixed !important;
      }
      .print-compact-table th,
      .print-compact-table td{
        border-bottom:1px solid #d8d8d8 !important;
        padding:3px 5px !important;
        vertical-align:middle !important;
        line-height:1.15 !important;
        color:#000 !important;
      }
      .print-compact-table th{
        background:#fff !important;
        border-bottom:1px solid #000 !important;
        font-size:9px !important;
        font-weight:900 !important;
        text-align:left !important;
      }
      .print-compact-table td{ font-size:9px !important; }

      .print-compact-table th:nth-child(1),
      .print-compact-table td:nth-child(1){ width:58% !important; text-align:left !important; }
      .print-compact-table th:nth-child(2),
      .print-compact-table td:nth-child(2){ width:16% !important; text-align:right !important; }
      .print-compact-table th:nth-child(3),
      .print-compact-table td:nth-child(3){ width:26% !important; text-align:right !important; }

      .print-compact-table .p-name{
        font-weight:900 !important;
        text-transform:uppercase !important;
        white-space:nowrap !important;
        overflow:hidden !important;
        text-overflow:ellipsis !important;
      }
      .print-compact-table .p-stock,
      .print-compact-table .p-exp{
        font-weight:900 !important;
        white-space:nowrap !important;
      }
      .print-compact-table tr{ page-break-inside:avoid !important; }
    }
  </style>
</head>

<body>

<!-- BUILD: <?= h($__BUILD_MARK) ?> | branch_id=<?= (int)$branch_id ?> | ts=<?= date('Y-m-d H:i:s') ?> -->

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
      <a href="#" onclick="return false;" style="opacity:.5;cursor:not-allowed;">
    📅 Citas (Próximamente)
</a>
      <a href="/private/facturacion/index.php">🧾 Facturación</a>
      <a href="/private/caja/index.php">💳 Caja</a>
      <a class="active" href="/private/inventario/index.php">📦 Inventario</a>
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
        <div class="print-header">
          <div class="print-title">
            Inventario - <?= h($branch_name) ?>
          </div>
          <div class="print-date">
            <?= date('d/m/Y h:i A') ?>
          </div>
        </div>

        <table class="print-compact-table">
          <thead>
            <tr>
              <th>Producto</th>
              <th>Stock</th>
              <th>Vencimiento</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <?php
                $expirationDatePrint = trim((string)($it["expiration_date"] ?? ""));
                $expirationTextPrint = formatDateDmy($expirationDatePrint);
              ?>
              <tr>
                <td class="p-name"><?= h($it["name"] ?? "") ?></td>
                <td class="p-stock"><?= number_format((float)($it["stock"] ?? 0), 2) ?></td>
                <td class="p-exp"><?= h($expirationTextPrint) ?></td>
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
                <th class="right">Venta</th>
                <th class="right">Stock</th>
                <th class="right">Vencimiento</th>
                <th class="right">Acciones</th>
              </tr>
            </thead>

            <tbody>
              <?php if (!$items): ?>
                <tr>
                  <td colspan="6" style="padding:18px; text-align:center; font-weight:900; color:#9FB6CC;">
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
elseif ($stock <= 3) {
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
    </div>
  </main>

</div>
<footer class="footer">
    © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>
</body>
</html>