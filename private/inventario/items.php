<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

$user = $_SESSION["user"];
$year = date("Y");
$branch_id = (int)($user["branch_id"] ?? 0);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

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
} catch (Exception $e) {}
if ($branch_name === "") $branch_name = "Sede #".$branch_id;

/* Flash */
$flash_ok = $_SESSION["flash_success"] ?? "";
$flash_err = $_SESSION["flash_error"] ?? "";
unset($_SESSION["flash_success"], $_SESSION["flash_error"]);

/* Categorías */
$categories = [];
try {
  $qc = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
  $categories = $qc ? $qc->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
  $categories = [];
}

$filter_category_id = isset($_GET["category_id"]) ? (int)$_GET["category_id"] : 0;

/* ===== ELIMINAR (solo de ESTA sucursal) ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "delete_from_branch") {
  $item_id = (int)($_POST["item_id"] ?? 0);

  if ($item_id > 0) {
    try {
      $stD = $conn->prepare("DELETE FROM inventory_stock WHERE branch_id=? AND item_id=?");
      $stD->execute([$branch_id, $item_id]);
      $_SESSION["flash_success"] = "Producto removido de esta sucursal.";
    } catch (Exception $e) {
      $_SESSION["flash_error"] = "No se pudo eliminar el producto de la sucursal.";
    }

    $q = $filter_category_id > 0 ? ("?category_id=" . $filter_category_id) : "";
    header("Location: /private/inventario/items.php{$q}");
    exit;
  }
}

/* ===== LISTADO POR SUCURSAL (inventory_stock.quantity) ===== */
$items = [];
try {
  $sql = "
    SELECT
      i.id,
      i.name,
      i.category_id,
      c.name AS category_name,
      s.quantity AS stock,
      i.purchase_price,
      i.sale_price
    FROM inventory_items i
    INNER JOIN inventory_stock s
      ON s.item_id = i.id AND s.branch_id = ?
    LEFT JOIN inventory_categories c
      ON c.id = i.category_id
  ";

  $params = [$branch_id];

  if ($filter_category_id > 0) {
    $sql .= " WHERE i.category_id = ? ";
    $params[] = $filter_category_id;
  }

  $sql .= " ORDER BY i.name ASC ";

  $st = $conn->prepare($sql);
  $st->execute($params);
  $items = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
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

  <link rel="stylesheet" href="/assets/css/styles.css?v=90">

  <style>
    /* ✅ FIX: quitar el max-width centrado que te crea el hueco */
    .page-wrap{
      width: 100%;
      max-width: none;   /* antes: 1280px */
      margin: 0;         /* antes: 0 auto */
      padding: 22px 22px 12px;
    }

    .page-header{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:14px;
      flex-wrap:wrap;
      margin-bottom: 12px;
    }
    .page-title h1{
      margin:0;
      font-size: 34px;
      font-weight: 950;
      letter-spacing: -.3px;
    }
    .page-title p{
      margin:6px 0 0;
      opacity:.78;
      font-weight: 800;
    }
    .page-actions{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      align-items:center;
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

    .btn-primary-ui{ background:#0b4d87; color:#fff; }
    .btn-secondary-ui{ background:#eef2f6; color:#2b3b4a; }

    .flash-ok{background:#e9fff1;border:1px solid #a7f0bf;color:#0a7a33;border-radius:12px;padding:10px 12px;font-size:13px;margin-top:12px;font-weight:850;}
    .flash-err{background:#ffecec;border:1px solid #ffb6b6;color:#a40000;border-radius:12px;padding:10px 12px;font-size:13px;margin-top:12px;font-weight:850;}

    .top-row{
      display:flex;
      justify-content:space-between;
      align-items:flex-end;
      gap:12px;
      flex-wrap:wrap;
      margin-top: 14px;
      margin-bottom: 12px;
    }

    .chips{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      align-items:center;
    }
    .chip{
      background:#f3f6fb;
      border:1px solid rgba(2,21,44,.10);
      padding: 8px 10px;
      border-radius:999px;
      font-weight:900;
      font-size:12px;
      white-space:nowrap;
    }

    .filter-form{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      align-items:center;
      justify-content:flex-end;
    }
    .select-ui{
      height:38px;
      border:1px solid rgba(2,21,44,.12);
      border-radius:12px;
      padding:0 12px;
      background:#fff;
      outline:none;
      min-width:260px;
      font-weight:850;
    }

    .card{
      background:#fff;
      border-radius:18px;
      box-shadow:0 12px 30px rgba(0,0,0,.08);
      padding:14px;
    }

    .table-wrap{
      width:100%;
      overflow-x:auto;
      overflow-y:auto;
      max-height: 540px;
      border-radius:14px;
      border:1px solid rgba(2,21,44,.06);
      -webkit-overflow-scrolling: touch;
      margin-top: 10px;
    }
    table{ width:100%; border-collapse:separate; border-spacing:0; min-width: 980px; }
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
      text-decoration:none;
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
    .btn-danger{
      background:#ffecec;
      border-color:#ffb6b6;
      color:#a40000;
    }

    @media (max-width: 980px){
      .page-title h1{ font-size: 28px; }
      table{ min-width: 860px; }
      .page-wrap{ padding: 18px 14px 10px; }
    }
  </style>
</head>

<body>

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
          <h1>Inventario</h1>
          <p>Productos por sucursal (automático). Sucursal: <strong><?= h($branch_name) ?></strong></p>
        </div>

        <div class="page-actions">
          <a class="btn-ui btn-primary-ui" href="<?= h($new_product_url) ?>">➕ Registrar nuevo producto</a>
          <a class="btn-ui btn-secondary-ui" href="/private/inventario/index.php">← Volver</a>
        </div>
      </div>

      <?php if ($flash_ok): ?><div class="flash-ok"><?= h($flash_ok) ?></div><?php endif; ?>
      <?php if ($flash_err): ?><div class="flash-err"><?= h($flash_err) ?></div><?php endif; ?>

      <div class="top-row">
        <div class="chips">
          <div class="chip">Mostrando: <strong><?= (int)count($items) ?></strong> producto(s)</div>
          <div class="chip">Sucursal ID: <strong><?= (int)$branch_id ?></strong></div>
        </div>

        <form class="filter-form" method="get" action="/private/inventario/items.php">
          <select class="select-ui" name="category_id">
            <option value="0">Todas las categorías</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c["id"] ?>" <?= ((int)$c["id"] === $filter_category_id) ? "selected" : "" ?>>
                <?= h($c["name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn-ui btn-secondary-ui" type="submit">Filtrar</button>
        </form>
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
                <th class="right">Acciones</th>
              </tr>
            </thead>

            <tbody>
              <?php if (!$items): ?>
                <tr>
                  <td colspan="6" style="opacity:.75;font-weight:800;">No hay productos registrados en esta sucursal.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($items as $it): ?>
                  <?php
                    $stock = (int)($it["stock"] ?? 0);

                    if ($stock <= 0) { $pillClass = "pill-out"; $pillText = "0 · Agotado"; }
                    elseif ($stock <= 1) { $pillClass = "pill-low"; $pillText = $stock . " · Bajo"; }
                    else { $pillClass = "pill-ok"; $pillText = $stock . " · OK"; }
                  ?>
                  <tr>
                    <td style="font-weight:950;"><?= h($it["name"] ?? "") ?></td>
                    <td><?= h($it["category_name"] ?? "—") ?></td>
                    <td class="right money">RD$ <?= number_format((float)($it["purchase_price"] ?? 0), 2) ?></td>
                    <td class="right money">RD$ <?= number_format((float)($it["sale_price"] ?? 0), 2) ?></td>
                    <td class="right">
                      <span class="pill <?= $pillClass ?>"><?= h($pillText) ?></span>
                    </td>
                    <td class="right">
                      <div class="actions">
                        <a class="btn-sm" href="<?= h($edit_url_base . (int)$it["id"]) ?>">✏️ Editar</a>

                        <form method="post" style="display:inline;" onsubmit="return confirm('¿Eliminar este producto de esta sucursal?');">
                          <input type="hidden" name="action" value="delete_from_branch">
                          <input type="hidden" name="item_id" value="<?= (int)$it["id"] ?>">
                          <button class="btn-sm btn-danger" type="submit">🗑️ Eliminar</button>
                        </form>
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

<div class="footer">
  <div class="inner">© <?= h($year) ?> CEVIMEP. Todos los derechos reservados.</div>
</div>

</body>
</html>
