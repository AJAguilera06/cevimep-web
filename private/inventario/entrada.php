<?php
session_start();
if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }

require_once __DIR__ . "/../../config/db.php";
$conn = $pdo;

$user = $_SESSION["user"];
$year = date("Y");
$today = date("Y-m-d");
$now_dt = date("Y-m-d H:i:s");
$branch_id = (int)($user["branch_id"] ?? 0);
$created_by = (int)($user["id"] ?? 0);

if ($branch_id <= 0) { die("Sucursal inválida."); }

$branch_name = "";
try {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $branch_name = (string)($stB->fetchColumn() ?: "");
} catch (Exception $e) {}

$flash_success = "";
$flash_error = "";

/* ===== Categorías ===== */
$categories = [];
try {
  $stC = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
  $categories = $stC->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

/* ===== Productos (con stock por sucursal) ===== */
$products = [];
try {
  $st = $conn->prepare("
    SELECT 
      i.id,
      i.name,
      COALESCE(c.name,'') AS category,
      COALESCE(c.id,0) AS category_id,
      COALESCE(s.quantity,0) AS stock
    FROM inventory_items i
    LEFT JOIN inventory_categories c ON c.id = i.category_id
    LEFT JOIN inventory_stock s 
      ON s.item_id = i.id AND s.branch_id = ?
    WHERE i.is_active = 1
    ORDER BY i.name ASC
  ");
  $st->execute([$branch_id]);
  $products = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $flash_error = "Error cargando productos.";
}

/* ===== Historial IN ===== */
$history_in = [];
if ($branch_id > 0) {
  try {
    $stH = $conn->prepare("
      SELECT 
        m.id, m.qty, m.note, m.created_at, m.created_by
      FROM inventory_movements m
      WHERE m.branch_id = ? AND m.movement_type = 'IN'
      ORDER BY m.id DESC
      LIMIT 50
    ");
    $stH->execute([$branch_id]);
    $history_in = $stH->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {}
}

/* ===========================
   IMPRIMIR DETALLE
   ?print=1&id=XX
=========================== */
if (isset($_GET["print"]) && (int)($_GET["id"] ?? 0) > 0) {
  $pid = (int)$_GET["id"];
  $print_data = null;
  try {
    $stP = $conn->prepare("
      SELECT id, branch_id, note, created_by, created_at
      FROM inventory_movements
      WHERE id=? AND branch_id=? AND movement_type='IN'
      LIMIT 1
    ");
    $stP->execute([$pid, $branch_id]);
    $print_data = $stP->fetch(PDO::FETCH_ASSOC);
  } catch (Exception $e) {}

  if (!$print_data) { die("Registro no encontrado."); }

  header("Content-Type: text/html; charset=utf-8");
  ?>
  <!doctype html>
  <html lang="es">
  <head>
    <meta charset="utf-8">
    <title>Entrada #<?= (int)$print_data["id"] ?></title>
    <style>
      body{font-family:Arial,Helvetica,sans-serif;margin:24px}
      h2{margin:0 0 8px}
      .box{border:1px solid #ddd;border-radius:10px;padding:16px}
      .muted{color:#666;font-size:13px}
      .row{display:flex;gap:16px;flex-wrap:wrap}
      .row>div{min-width:220px}
      @media print{ .no-print{display:none} }
    </style>
  </head>
  <body onload="window.print()">
    <div class="box">
      <h2>Entrada de Inventario</h2>
      <div class="muted">CEVIMEP - <?= htmlspecialchars($branch_name) ?></div>
      <hr>
      <div class="row">
        <div><strong>ID:</strong> <?= (int)$print_data["id"] ?></div>
        <div><strong>Fecha:</strong> <?= htmlspecialchars($print_data["created_at"]) ?></div>
      </div>
      <div style="margin-top:10px">
        <strong>Nota:</strong><br>
        <?= nl2br(htmlspecialchars($print_data["note"])) ?>
      </div>
      <div class="no-print" style="margin-top:16px">
        <button onclick="window.close()">Cerrar</button>
      </div>
    </div>
  </body>
  </html>
  <?php
  exit;
}

/* ===========================
   GUARDAR ENTRADA
=========================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "save_entry") {

  $fecha = trim($_POST["fecha"] ?? $today);
  $suplidor = trim($_POST["suplidor"] ?? "");
  $area_destino = trim($_POST["area_destino"] ?? $branch_name);

  $items_json = $_POST["items_json"] ?? "[]";
  $items = json_decode($items_json, true);
  if (!is_array($items)) $items = [];

  if (count($items) === 0) {
    $flash_error = "No hay productos agregados.";
  } else {
    try {
      $conn->beginTransaction();

      // validar ids/cantidades
      $ids = [];
      foreach ($items as $it) {
        $iid = (int)($it["id"] ?? 0);
        $q = (int)($it["qty"] ?? 0);
        if ($iid > 0 && $q > 0) $ids[] = $iid;
      }
      $ids = array_values(array_unique($ids));
      if (count($ids) === 0) throw new Exception("Items inválidos.");

      // info items
      $in = implode(",", array_fill(0, count($ids), "?"));
      $stInfo = $conn->prepare("SELECT id, name FROM inventory_items WHERE id IN ($in)");
      $stInfo->execute($ids);
      $infoRows = $stInfo->fetchAll(PDO::FETCH_ASSOC);

      $infoMap = [];
      foreach ($infoRows as $r) $infoMap[(int)$r["id"]] = $r;

      // sumar stock por sucursal
      $stStock = $conn->prepare("
        INSERT INTO inventory_stock (item_id, branch_id, quantity)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
      ");

      // registrar movimientos
      $stMov = $conn->prepare("
        INSERT INTO inventory_movements (item_id, branch_id, movement_type, qty, note, created_by)
        VALUES (?, ?, 'IN', ?, ?, ?)
      ");

      foreach ($items as $it) {
        $iid = (int)($it["id"] ?? 0);
        $q = (int)($it["qty"] ?? 0);
        if ($iid <= 0 || $q <= 0) continue;

        $pname = $infoMap[$iid]["name"] ?? ("ID ".$iid);

        $metaNote = "FECHA={$fecha} | SUPLIDOR={$suplidor} | DESTINO={$area_destino} | ITEM={$pname}";
        $stStock->execute([$iid, $branch_id, $q]);
        $stMov->execute([$iid, $branch_id, $q, $metaNote, $created_by]);
      }

      $conn->commit();
      $flash_success = "Entrada guardada correctamente.";
    } catch (Exception $e) {
      if ($conn->inTransaction()) $conn->rollBack();
      $flash_error = "No se pudo guardar la entrada: " . $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>CEVIMEP | Inventario - Entrada</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="../../public/assets/css/styles.css?v=<?= time() ?>">
  <style>
    .grid{display:grid;grid-template-columns:1fr;gap:16px}
    .card{background:#fff;border-radius:16px;box-shadow:0 8px 20px rgba(0,0,0,.08);padding:16px}
    .title{font-size:18px;font-weight:700;color:#0a3a78;margin:0 0 8px}
    .muted{color:#6b7280;font-size:13px}
    .row{display:flex;gap:12px;flex-wrap:wrap}
    .field{flex:1;min-width:220px}
    .input{width:100%}
    .btn{background:#0ea5a5;border:none;color:#fff;padding:10px 14px;border-radius:12px;font-weight:700;cursor:pointer}
    .btn:hover{opacity:.92}
    .btn-soft{background:#e6f7f7;color:#0a3a78;border:1px solid #cceeee}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th,td{padding:10px;border-bottom:1px solid #edf2f7;text-align:left;font-size:14px}
    th{color:#0a3a78;font-weight:800}
    .right{text-align:right}
    .flash{padding:10px 12px;border-radius:12px;margin-bottom:10px}
    .flash.ok{background:#e8fff3;color:#146c43;border:1px solid #bfead2}
    .flash.err{background:#fff2f2;color:#842029;border:1px solid #f5c2c7}
  </style>
</head>
<body>

<?php include __DIR__ . "/../partials/sidebar.php"; ?>

<div class="content">
  <?php include __DIR__ . "/../partials/topbar.php"; ?>

  <div class="grid">
    <div class="card">
      <div class="title">Entrada</div>
      <div class="muted">Registra entrada de inventario (sede actual)</div>

      <?php if ($flash_success): ?>
        <div class="flash ok"><?= htmlspecialchars($flash_success) ?></div>
      <?php endif; ?>
      <?php if ($flash_error): ?>
        <div class="flash err"><?= htmlspecialchars($flash_error) ?></div>
      <?php endif; ?>

      <div class="row" style="margin-top:12px">
        <div class="field">
          <label>Fecha</label>
          <input class="input" type="date" id="fecha" value="<?= htmlspecialchars($today) ?>">
        </div>

        <div class="field">
          <label>Área de destino</label>
          <input class="input" type="text" id="area_destino" value="<?= htmlspecialchars($branch_name) ?>" readonly>
        </div>

        <div class="field">
          <label>Categoría</label>
          <select class="input" id="selCat">
            <option value="0">-- Todas --</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c["id"] ?>"><?= htmlspecialchars($c["name"]) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label>Producto</label>
          <select class="input" id="selItem">
            <option value="0" data-cat-id="0" data-cat-name="">-- Seleccionar --</option>
            <?php foreach ($products as $p): ?>
              <option
                value="<?= (int)$p["id"] ?>"
                data-cat-id="<?= (int)$p["category_id"] ?>"
                data-cat-name="<?= htmlspecialchars($p["category"]) ?>"
              >
                <?= htmlspecialchars($p["name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label>Cantidad</label>
          <input class="input" type="number" id="qty" min="1" value="1">
        </div>

        <button type="button" class="btn" id="btnAdd">Añadir</button>
      </div>

      <p class="muted" style="margin:10px 0 0">
        Al añadir, se mantiene seleccionado el producto y la cantidad.
      </p>

      <table>
        <thead>
          <tr>
            <th>Categoría</th>
            <th>Producto</th>
            <th class="right">Cantidad</th>
            <th class="right">Acción</th>
          </tr>
        </thead>
        <tbody id="tbodyItems">
          <tr id="emptyRow"><td colspan="4" class="muted">No hay productos agregados.</td></tr>
        </tbody>
      </table>

      <div style="display:flex;justify-content:flex-end;margin-top:14px;gap:10px">
        <button type="button" class="btn btn-soft" id="btnToggleHist">Ver el historial</button>
        <button type="button" class="btn" id="btnSave">Guardar e Imprimir</button>
      </div>

      <div id="histWrap" style="display:none;margin-top:16px">
        <div class="title" style="font-size:16px">Historial de Entradas</div>
        <div class="muted">Últimos 50 registros (sede actual)</div>

        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Cantidad</th>
              <th>Nota</th>
              <th>Fecha</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($history_in) === 0): ?>
              <tr><td colspan="4" class="muted">No hay registros.</td></tr>
            <?php else: ?>
              <?php foreach ($history_in as $h): ?>
                <tr>
                  <td>#<?= (int)$h["id"] ?></td>
                  <td><?= (int)$h["qty"] ?></td>
                  <td><?= htmlspecialchars($h["note"]) ?></td>
                  <td><?= htmlspecialchars($h["created_at"]) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <form id="frmSave" method="post" style="display:none">
        <input type="hidden" name="action" value="save_entry">
        <input type="hidden" name="fecha" id="f_fecha">
        <input type="hidden" name="suplidor" id="f_suplidor">
        <input type="hidden" name="area_destino" id="f_area_destino">
        <input type="hidden" name="items_json" id="f_items">
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  const selCat = document.getElementById('selCat');
  const selItem = document.getElementById('selItem');
  const qty = document.getElementById('qty');
  const btn = document.getElementById('btnAdd');
  const tbody = document.getElementById('tbodyItems');

  const btnToggle = document.getElementById('btnToggleHist');
  const histWrap = document.getElementById('histWrap');
  btnToggle.addEventListener('click', () => {
    const open = histWrap.style.display === 'block';
    histWrap.style.display = open ? 'none' : 'block';
    btnToggle.textContent = open ? 'Ver el historial' : 'Ocultar historial';
  });

  let items = [];

  function filterProducts(){
    const catId = parseInt(selCat.value||"0",10);
    for (const opt of selItem.options) {
      const oc = parseInt(opt.getAttribute('data-cat-id')||"0",10);
      if (opt.value === "0") { opt.hidden = false; continue; }
      opt.hidden = (catId !== 0 && oc !== catId);
    }
    const cur = selItem.options[selItem.selectedIndex];
    if (cur && cur.hidden) selItem.value = "0";
  }
  selCat.addEventListener('change', filterProducts);
  filterProducts();

  function ensureNoEmpty(){
    const er = document.getElementById('emptyRow');
    if (items.length === 0) {
      if (!er) {
        const tr = document.createElement('tr');
        tr.id = 'emptyRow';
        tr.innerHTML = '<td colspan="4" class="muted">No hay productos agregados.</td>';
        tbody.appendChild(tr);
      }
    } else {
      if (er) er.remove();
    }
  }

  function render(){
    tbody.innerHTML = '';
    if (items.length === 0) {
      tbody.innerHTML = '<tr id="emptyRow"><td colspan="4" class="muted">No hay productos agregados.</td></tr>';
      return;
    }
    for (const it of items) {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${it.category || ''}</td>
        <td>${it.name}</td>
        <td class="right">${it.qty}</td>
        <td class="right"><button type="button" class="btn btn-soft" data-del="${it.id}">Quitar</button></td>
      `;
      tbody.appendChild(tr);
    }
  }

  btn.addEventListener('click', () => {
    const id = parseInt(selItem.value||"0",10);
    const q = parseInt(qty.value||"0",10);
    if (!id || q <= 0) return;

    const opt = selItem.options[selItem.selectedIndex];
    const name = opt ? opt.textContent.trim() : ('ID '+id);
    const catName = opt ? (opt.getAttribute('data-cat-name')||'') : '';

    const existing = items.find(x => x.id === id);
    if (existing) existing.qty += q;
    else items.push({id, name, qty:q, category:catName});

    render();
    selItem.focus();
  });

  tbody.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-del]');
    if (!b) return;
    const id = parseInt(b.getAttribute('data-del'),10);
    items = items.filter(x => x.id !== id);
    render();
  });

  document.getElementById('btnSave').addEventListener('click', () => {
    if (items.length === 0) return;

    document.getElementById('f_fecha').value = document.getElementById('fecha').value;
    document.getElementById('f_suplidor').value = ''; // si luego agregas campo suplidor en UI
    document.getElementById('f_area_destino').value = document.getElementById('area_destino').value;
    document.getElementById('f_items').value = JSON.stringify(items);

    document.getElementById('frmSave').submit();
  });

})();
</script>

</body>
</html>
