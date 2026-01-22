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
unset($_SESSION["flash_success"]);

/* Categorías para filtro (si existe la tabla) */
$categories = [];
try {
  $qc = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
  $categories = $qc ? $qc->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
  $categories = [];
}

$filter_category_id = isset($_GET["category_id"]) ? (int)$_GET["category_id"] : 0;

/* Items por sucursal (TU BD: inventory_stock.quantity) */
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
  // Si algo falla por columnas de items/categorías, lo verás aquí (pero ya quitamos s.stock)
  $items = [];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Inventario - Productos</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=60">
  <style>
    .content-wrap{padding:22px 24px;}
    .header{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:12px;}
    .header h1{margin:0;font-size:30px;font-weight:900;color:#0b2b4a;}
    .subtitle{color:#5b6b7a;font-size:13px;margin-top:3px;}
    .toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:flex-end;}
    .btn{height:38px;border:none;border-radius:12px;padding:0 14px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
    .btn-primary{background:#e8f4ff;color:#0b4d87;}
    .btn-secondary{background:#eef2f6;color:#2b3b4a;}
    .card{background:#fff;border-radius:16px;box-shadow:0 12px 30px rgba(0,0,0,.08);padding:14px;margin-top:12px;}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between}
    .select{height:38px;border:1px solid #d8e1ea;border-radius:12px;padding:0 12px;background:#fff;outline:none;min-width:260px;}
    table{width:100%;border-collapse:separate;border-spacing:0;}
    th,td{padding:12px 10px;border-bottom:1px solid #eef2f6;font-size:13px;}
    th{color:#0b4d87;text-align:left;font-weight:900;font-size:12px;}
    tr:last-child td{border-bottom:none;}
    .actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
    .btn-mini{height:32px;border-radius:10px;padding:0 10px;font-size:12px;font-weight:900}
    .btn-ver{background:#e8f7ff;color:#0b4d87}
    .btn-editar{background:#eef2f6;color:#2b3b4a}
    .pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;font-weight:900;font-size:12px}
    .pill-ok{background:#e9fff1;color:#0a7a33}
    .pill-low{background:#fff2e8;color:#8a3b00}
    .pill-zero{background:#ffecec;color:#a40000}
    .flash-ok{background:#e9fff1;border:1px solid #a7f0bf;color:#0a7a33;border-radius:12px;padding:10px 12px;font-size:13px;margin-top:10px;}
  </style>
</head>
<body>

  <div class="topbar">
    <div class="topbar-inner">
      <div class="brand"><span class="dot"></span><span class="name">CEVIMEP</span></div>
      <div class="right"><a class="logout" href="/logout.php">Salir</a></div>
    </div>
  </div>

  <div class="layout">
    <aside class="sidebar">
      <div class="sidebar-title">Menú</div>
      <nav class="menu">
        <a class="menu-item" href="/private/dashboard.php">🏠 Panel</a>
        <a class="menu-item" href="/private/patients/index.php">👤 Pacientes</a>
        <a class="menu-item" href="/private/citas/index.php">📅 Citas</a>
        <a class="menu-item" href="/private/facturacion/index.php">🧾 Facturación</a>
        <a class="menu-item" href="/private/caja/index.php">💵 Caja</a>
        <a class="menu-item active" href="/private/inventario/index.php">📦 Inventario</a>
        <a class="menu-item" href="/private/estadisticas/index.php">📊 Estadísticas</a>
      </nav>
    </aside>

    <main class="main">
      <div class="content-wrap">

        <div class="header">
          <div>
            <h1>Inventario</h1>
            <div class="subtitle">
              Productos por sucursal (automático). Sucursal: <b><?= h($branch_name ?: "—") ?></b>
            </div>
          </div>

          <div class="toolbar">
            <a class="btn btn-secondary" href="/private/inventario/entrada.php">Entrada</a>
            <a class="btn btn-secondary" href="/private/inventario/salida.php">Salida</a>
            <a class="btn btn-secondary" href="/private/inventario/categorias.php">Categorías</a>
            <a class="btn btn-secondary" href="/private/inventario/index.php">Volver</a>
          </div>
        </div>

        <?php if ($flash_ok): ?>
          <div class="flash-ok"><?= h($flash_ok) ?></div>
        <?php endif; ?>

        <div class="card">
          <div class="row">
            <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
              <select class="select" name="category_id">
                <option value="0">Todas las categorías</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c["id"] ?>" <?= ($filter_category_id === (int)$c["id"]) ? "selected" : "" ?>>
                    <?= h($c["name"]) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-secondary" type="submit">Filtrar</button>
            </form>

            <div style="color:#5b6b7a;font-size:13px">
              Mostrando: <b><?= count($items) ?></b> producto(s)
            </div>
          </div>
        </div>

        <div class="card">
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
                    else if ($stock <= 3) { $pillClass = "pill-low"; $pillText = "Bajo"; } // umbral simple
                  ?>
                  <tr>
                    <td><b><?= h($it["name"] ?? "") ?></b></td>
                    <td><?= h($it["category_name"] ?? "") ?></td>
                    <td><span class="pill <?= $pillClass ?>"><?= $stock ?> • <?= $pillText ?></span></td>
                    <td style="text-align:right;">
                      <div class="actions">
                        <a class="btn-mini btn-ver" href="/private/inventario/entrada.php?item_id=<?= (int)$it["id"] ?>">Entrada</a>
                        <a class="btn-mini btn-editar" href="/private/inventario/salida.php?item_id=<?= (int)$it["id"] ?>">Salida</a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </main>
  </div>

  <footer class="footer">
    © <?= $year ?> CEVIMEP. Todos los derechos reservados.
  </footer>

</body>
</html>
