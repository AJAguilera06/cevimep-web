<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

$user = $_SESSION["user"];
$year = (int)date("Y");
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
      s.quantity AS stock
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

  <!-- MISMO CSS/ESTRUCTURA QUE dashboard.php -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=30">

  <style>
    /* Solo detalles del listado (manteniendo look dashboard) */
    .toolbar-line{
      display:flex;
      gap:12px;
      flex-wrap:wrap;
      align-items:center;
      justify-content:space-between;
      margin-top:14px;
    }
    .filter-form{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      align-items:center;
    }
    .select-ui{
      height:38px;
      border:1px solid #d8e1ea;
      border-radius:12px;
      padding:0 12px;
      background:#fff;
      outline:none;
      min-width:260px;
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
    }
    .btn-primary-ui{background:#0b4d87;color:#fff;}
    .btn-secondary-ui{background:#eef2f6;color:#2b3b4a;}
    .btn-danger-ui{background:#ffecec;color:#a40000;}

    .table-card{
      background:#fff;
      border-radius:16px;
      box-shadow:0 12px 30px rgba(0,0,0,.08);
      padding:14px;
      margin-top:14px;
    }

    table{width:100%;border-collapse:separate;border-spacing:0;}
    th,td{padding:12px 10px;border-bottom:1px solid #eef2f6;font-size:13px;}
    th{color:#0b4d87;text-align:left;font-weight:900;font-size:12px;}
    tr:last-child td{border-bottom:none;}

    .actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
    .btn-mini{height:32px;border-radius:10px;padding:0 10px;font-size:12px;font-weight:900;border:none;cursor:pointer}
    .pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;font-weight:900;font-size:12px}
    .pill-ok{background:#e9fff1;color:#0a7a33}
    .pill-low{background:#fff2e8;color:#8a3b00}
    .pill-zero{background:#ffecec;color:#a40000}

    .flash-ok{background:#e9fff1;border:1px solid #a7f0bf;color:#0a7a33;border-radius:12px;padding:10px 12px;font-size:13px;margin-top:12px;}
    .flash-err{background:#ffecec;border:1px solid #ffb6b6;color:#a40000;border-radius:12px;padding:10px 12px;font-size:13px;margin-top:12px;}
  </style>
</head>

<body>

<!-- NAVBAR (IGUAL AL dashboard.php) -->
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

<!-- LAYOUT (IGUAL AL dashboard.php) -->
<div class="layout">

  <!-- SIDEBAR (IGUAL AL dashboard.php) -->
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

  <!-- CONTENIDO (IGUAL AL dashboard.php) -->
  <main class="content">

    <section class="hero">
      <h1>Inventario</h1>
      <p>
        Productos por sucursal (automático).
        Sucursal: <strong><?= h($branch_name ?: "—") ?></strong>
      </p>
    </section>

    <div class="toolbar-line">
      <div class="muted">
        Mostrando: <strong><?= count($items) ?></strong> producto(s)
      </div>

      <a class="btn-ui btn-primary-ui" href="<?= h($new_product_url) ?>">Registrar nuevo producto</a>
    </div>

    <?php if ($flash_ok): ?><div class="flash-ok"><?= h($flash_ok) ?></div><?php endif; ?>
    <?php if ($flash_err): ?><div class="flash-err"><?= h($flash_err) ?></div><?php endif; ?>

    <div class="table-card">
      <form method="get" class="filter-form">
        <select class="select-ui" name="category_id">
          <option value="0">Todas las categorías</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c["id"] ?>" <?= ($filter_category_id === (int)$c["id"]) ? "selected" : "" ?>>
              <?= h($c["name"]) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button class="btn-ui btn-secondary-ui" type="submit">Filtrar</button>
      </form>
    </div>

    <div class="table-card">
      <table>
        <thead>
          <tr>
            <th>Producto</th>
            <th>Categoría</th>
            <th>Stock</th>
            <th style="text-align:right;">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$items): ?>
          <tr><td colspan="4">No hay productos cargados para esta sucursal.</td></tr>
        <?php else: ?>
          <?php foreach ($items as $it): ?>
            <?php
              $stock = (int)($it["stock"] ?? 0);
              $pillClass = "pill-ok";
              $pillText = "OK";
              if ($stock <= 0) { $pillClass = "pill-zero"; $pillText = "Agotado"; }
              else if ($stock <= 3) { $pillClass = "pill-low"; $pillText = "Bajo"; }
            ?>
            <tr>
              <td><b><?= h($it["name"] ?? "") ?></b></td>
              <td><?= h($it["category_name"] ?? "") ?></td>
              <td><span class="pill <?= $pillClass ?>"><?= $stock ?> • <?= $pillText ?></span></td>

              <td style="text-align:right;">
                <div class="actions">
                  <a class="btn-mini btn-secondary-ui" href="<?= h($edit_url_base . (int)$it["id"]) ?>">Editar</a>

                  <form method="post" style="display:inline" onsubmit="return confirm('¿Quitar este producto de esta sucursal?')">
                    <input type="hidden" name="action" value="delete_from_branch">
                    <input type="hidden" name="item_id" value="<?= (int)$it["id"] ?>">
                    <button class="btn-mini btn-danger-ui" type="submit">Eliminar</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

  </main>
</div>

<!-- FOOTER (IGUAL AL dashboard.php) -->
<div class="footer">
  <div class="inner">
    © <?= $year ?> CEVIMEP. Todos los derechos reservados.
  </div>
</div>

</body>
</html>
