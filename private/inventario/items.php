<?php
declare(strict_types=1);

/**
 * CEVIMEP - Inventario (Items)
 * - Layout/estilos igual al dashboard.php
 * - Menú lateral estándar
 * - Footer centrado estándar
 */

session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

if (empty($_SESSION['user'])) {
  header('Location: /login.php');
  exit;
}

require_once __DIR__ . "/../../config/db.php";
$conn = $pdo;

$user = $_SESSION["user"];
$year = (int)date("Y");

/* ===== SEDE (branch_id) DEL USUARIO LOGUEADO ===== */
$branch_id = (int)($user["branch_id"] ?? 0);
$branch_warning = "";
if ($branch_id <= 0) {
  $branch_warning = "⚠️ Este usuario no tiene sede asignada (branch_id).";
}

/* Flash message */
$flash_success = $_SESSION["flash_success"] ?? "";
unset($_SESSION["flash_success"]);

/* CATEGORÍAS (para filtro) */
$categories = [];
$qc = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
if ($qc) {
  $categories = $qc->fetchAll(PDO::FETCH_ASSOC);
}

/* FILTRO POR CATEGORÍA (GET) */
$filter_category_id = isset($_GET["category_id"]) ? (int)$_GET["category_id"] : 0;

/* ===== ITEMS VISIBLES POR SEDE =====
   IMPORTANTE: usar INNER JOIN a inventory_stock para que SOLO aparezcan
   productos creados/activados para esa sede.
*/
if ($branch_id > 0) {
  $sql = "
    SELECT 
      i.id,
      i.name,
      i.purchase_price,
      i.sale_price,
      i.min_stock,
      i.category_id,
      c.name AS category_name,
      COALESCE(s.quantity, 0) AS existencia
    FROM inventory_items i
    LEFT JOIN inventory_categories c ON c.id = i.category_id
    INNER JOIN inventory_stock s 
      ON s.item_id = i.id
     AND s.branch_id = ?
    WHERE i.is_active = 1
  ";

  $params = [$branch_id];

  if ($filter_category_id > 0) {
    $sql .= " AND i.category_id = ? ";
    $params[] = $filter_category_id;
  }

  $sql .= " ORDER BY i.name ASC ";

  $st = $conn->prepare($sql);
  $st->execute($params);
  $items = $st->fetchAll(PDO::FETCH_ASSOC);
} else {
  // Si entra alguien sin sede asignada, no mostramos nada (para respetar tu regla)
  $items = [];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Inventario</title>

  <!-- IMPORTANTE: mismo CSS y misma versión que los demás módulos -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=11">

  <style>
    .
/assets/css/styles.css


.small{
      padding:6px 12px;
      border-radius:999px;
      font-weight:900;
      font-size:12px;
      border:1px solid rgba(2,21,44,.12);
      background:#eef6ff;
      cursor:pointer;
    }
    .
/assets/css/styles.css


.small.danger{
      background:#dc3545;
      color:#fff;
      border-color:#dc3545;
    }
    .low-stock{ color:#d00000; font-weight:900; }

    .alert-success{
      margin-top:14px;
      padding:12px 14px;
      border-radius:14px;
      background:#ecfff3;
      border:1px solid #b7f3c9;
      color:#0f5132;
      font-weight:900;
      display:flex;
      align-items:center;
      gap:10px;
    }

    .alert-warn{
      margin-top:14px;
      padding:12px 14px;
      border-radius:14px;
      background:#fff7e6;
      border:1px solid #ffe2a8;
      color:#7a4b00;
      font-weight:900;
    }

    .header-filter{
      margin-left:10px;
      padding:6px 10px;
      border-radius:12px;
      border:1px solid #dbe7f3;
      background:#fff;
      font-weight:800;
      outline:none;
    }
    .th-flex{
      display:flex;
      align-items:center;
      gap:10px;
      white-space:nowrap;
    }
  </style>
</head>

<body>

<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right">
      <a class="
/assets/css/styles.css


-pill" href="/logout.php">Salir</a>
    </div>
  </div>
</header>

<div class="layout">

  <aside class="sidebar">
    <div class="menu-title">Menú</div>

    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👥</span> Pacientes</a>
      <a href="javascript:void(0)" style="opacity:.45; cursor:not-allowed;"><span class="ico">🗓️</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>
      <a class="active" href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadistica/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">

    <section class="hero">
      <h1>Inventario</h1>
      <p>Stock por sucursal (entradas - salidas)</p>
    </section>

    <section class="card">
      <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
        <div>
          <h2 style="margin:0 0 6px;">Listado de productos</h2>
          <p class="muted" style="margin:0;">Gestiona precios, categorías y existencia</p>
        </div>

        <a class="
/assets/css/styles.css


" href="guardar_producto.php" style="text-decoration:none;">Registrar nuevo producto</a>
      </div>

      <?php if ($flash_success): ?>
        <div class="alert-success" id="flashSuccess"><?php echo htmlspecialchars($flash_success); ?></div>
      <?php endif; ?>

      <?php if ($branch_warning): ?>
        <div class="alert-warn"><?php echo htmlspecialchars($branch_warning); ?></div>
      <?php endif; ?>

      <div style="margin-top:18px; overflow:auto;">
        <table class="table">
          <thead>
            <tr>
              <th>Producto</th>

              <th>
                <div class="th-flex">
                  <span>Categoría</span>

                  <form id="filterForm" method="GET" action="items.php" style="margin:0;">
                    <select class="header-filter" name="category_id" onchange="document.getElementById('filterForm').submit()">
                      <option value="0" <?php echo $filter_category_id === 0 ? "selected" : ""; ?>>Todas</option>
                      <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat['id']; ?>"
                          <?php echo ((int)$cat['id'] === $filter_category_id) ? "selected" : ""; ?>
                        >
                          <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                </div>
              </th>

              <th>Compra</th>
              <th>Venta</th>
              <th>Existencia</th>
              <th>Acciones</th>
            </tr>
          </thead>

          <tbody>
            <?php if (empty($items)): ?>
              <tr>
                <td colspan="6" class="muted">No hay productos para ese filtro</td>
              </tr>
            <?php else: ?>
              <?php foreach ($items as $item):
                $existencia = (int)($item["existencia"] ?? 0);
                $min_stock = (int)($item["min_stock"] ?? 0);
                $low = ($existencia <= $min_stock);
              ?>
                <tr>
                  <td><?php echo htmlspecialchars($item["name"]); ?></td>
                  <td><?php echo htmlspecialchars($item["category_name"] ?? ""); ?></td>
                  <td><?php echo number_format((float)$item["purchase_price"], 2); ?></td>
                  <td><?php echo number_format((float)$item["sale_price"], 2); ?></td>
                  <td class="<?php echo $low ? "low-stock" : ""; ?>"><?php echo $existencia; ?></td>

                  <td style="display:flex; gap:10px; align-items:center;">
                    <button class="
/assets/css/styles.css


 small" type="button" onclick="editItem(<?php echo (int)$item['id']; ?>)">Editar</button>
                    <button class="
/assets/css/styles.css


 small danger" type="button" onclick="deleteItem(<?php echo (int)$item['id']; ?>)">Eliminar</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>
</div>

<footer class="footer">
  <div class="footer-inner">© <?php echo $year; ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

<script>
function editItem(id){
  window.location.href = "edit_item.php?id=" + encodeURIComponent(id);
}

async function deleteItem(id){
  if(!confirm("¿Seguro que deseas eliminar este producto?")) return;

  try{
    const res = await fetch("delete_item.php", {
      method: "POST",
      headers: {"Content-Type":"application/x-www-form-urlencoded"},
      body: "id=" + encodeURIComponent(id)
    });

    const data = await res.json();
    if(data.ok){
      location.reload();
    }else{
      alert(data.msg || "No se pudo eliminar");
    }
  }catch(e){
    alert("Error al eliminar");
  }
}

/* Auto-hide flash */
const flash = document.getElementById("flashSuccess");
if (flash) setTimeout(() => flash.style.display = "none", 2500);
</script>

</body>
</html>
