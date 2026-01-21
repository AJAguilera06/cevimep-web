<?php
session_start();
if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }

require_once __DIR__ . "/../../config/db.php";
$conn = $pdo;

$user = $_SESSION["user"];
$today = date("Y-m-d");
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

/* ===== Historial OUT (sede actual) ===== */
$history_out = [];
try {
  $stH = $conn->prepare("
    SELECT 
      m.id, m.qty, m.note, m.created_at, m.created_by
    FROM inventory_movements m
    WHERE m.branch_id = ? AND m.movement_type = 'OUT'
    ORDER BY m.id DESC
    LIMIT 50
  ");
  $stH->execute([$branch_id]);
  $history_out = $stH->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

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
      WHERE id=? AND branch_id=? AND movement_type='OUT'
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
    <title>Salida #<?= (int)$print_data["id"] ?></title>
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
      <h2>Salida de Inventario</h2>
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
   GUARDAR SALIDA
=========================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save_exit") {

  $fecha = trim($_POST["fecha"] ?? $today);
  $area_salida = trim($_POST["area_salida"] ?? $branch_name);
  $area_destino = trim($_POST["area_destino"] ?? "");
  $hecho_por = trim($_POST["hecho_por"] ?? "");

  if ($area_destino === "") $area_destino = "N/A";
  if ($hecho_por === "") $hecho_por = "N/A";

  $items_json = $_POST["items_json"] ?? "[]";
  $items = json_decode($items_json, true);
  if (!is_array($items)) $items = [];

  if (count($items) === 0) {
    $flash_error = "No hay productos agregados.";
  } else {
    try {
      $conn->beginTransaction();

      $ids = [];
      foreach ($items as $it) {
        $iid = (int)($it["id"] ?? 0);
        $q = (int)($it["qty"] ?? 0);
        if ($iid > 0 && $q > 0) $ids[] = $iid;
      }
      $ids = array_values(array_unique($ids));
      if (count($ids) === 0) throw new Exception("Items inválidos.");

      $in = implode(",", array_fill(0, count($ids), "?"));

      $stInfo = $conn->prepare("SELECT id, name FROM inventory_items WHERE id IN ($in)");
      $stInfo->execute($ids);
      $infoRows = $stInfo->fetchAll(PDO::FETCH_ASSOC);
      $infoMap = [];
      foreach ($infoRows as $r) $infoMap[(int)$r["id"]] = $r;

      $stAvail = $conn->prepare("
        SELECT item_id, quantity
        FROM inventory_stock
        WHERE branch_id=? AND item_id IN ($in)
      ");
      $stAvail->execute(array_merge([$branch_id], $ids));
      $availRows = $stAvail->fetchAll(PDO::FETCH_ASSOC);
      $availMap = [];
      foreach ($availRows as $r) $availMap[(int)$r["item_id"]] = (int)$r["quantity"];

      foreach ($items as $it) {
        $iid = (int)($it["id"] ?? 0);
        $q = (int)($it["qty"] ?? 0);
        if ($iid <= 0 || $q <= 0) continue;

        $available = $availMap[$iid] ?? 0;
        if ($available < $q) {
          $pname = $infoMap[$iid]["name"] ?? ("ID ".$iid);
          throw new Exception("Stock insuficiente para '$pname'. Disponible: $available, solicitado: $q.");
        }
      }

      $stStockUpd = $conn->prepare("
        UPDATE inventory_stock
        SET quantity = quantity - ?
        WHERE branch_id=? AND item_id=?
      ");

      $stMov = $conn->prepare("
        INSERT INTO inventory_movements (item_id, branch_id, movement_type, qty, note, created_by)
        VALUES (?, ?, 'OUT', ?, ?, ?)
      ");

      foreach ($items as $it) {
        $iid = (int)($it["id"] ?? 0);
        $q = (int)($it["qty"] ?? 0);
        if ($iid <= 0 || $q <= 0) continue;

        $pname = $infoMap[$iid]["name"] ?? ("ID ".$iid);
        $metaNote = "FECHA={$fecha} | SALIDA={$area_salida} | DESTINO={$area_destino} | HECHO_POR={$hecho_por} | ITEM={$pname}";

        $stStockUpd->execute([$q, $branch_id, $iid]);
        $stMov->execute([$iid, $branch_id, $q, $metaNote, $created_by]);
      }

      $conn->commit();
      $flash_success = "Salida guardada correctamente.";
    } catch (Exception $e) {
      if ($conn->inTransaction()) $conn->rollBack();
      $flash_error = "No se pudo guardar la salida: " . $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Inventario - Salida</title>

  <!-- IMPORTANTE: mismo CSS y misma versión que el Dashboard -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=11">

  <style>
    /* Ajustes mínimos para que Entrada/Salida se vean como el Dashboard, sin depender de partials */
    .page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}
    .page-head h1{margin:0}
    .page-head .muted{margin:0}
    .card{margin-top:12px}
    .form-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
    .field{flex:1;min-width:220px}
    .field label{display:block;font-size:13px;color:#6b7280;margin-bottom:6px}
    .input, select.input{width:100%}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px 12px;border-bottom:1px solid rgba(0,0,0,.06);text-align:left}
    th{font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.02em}
    .actions{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:14px}
    .btn{cursor:pointer}
    .flash{margin-top:10px;padding:10px 12px;border-radius:12px}
    .flash.ok{background:#d1fae5;color:#065f46}
    .flash.err{background:#fee2e2;color:#991b1b}
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
      <a href="javascript:void(0)" style="opacity:.45; cursor:not-allowed;"><span class="ico">🗓️</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>
      <a class="active" href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadistica/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">

    <section class="hero">
      <div class="page-head">
        <div>
          <h1>Salida</h1>
          <p class="muted">Sucursal: <?= htmlspecialchars($branch_name ?? '') ?></p>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="title">Salida</div>
      <div class="muted">Registra salida de inventario (sede actual)</div>

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
          <label>Área de salida</label>
          <input class="input" type="text" id="area_salida" value="<?= htmlspecialchars($branch_name) ?>" readonly>
        </div>

        <div class="field">
          <label>Área de destino</label>
          <input class="input" type="text" id="area_destino" placeholder="Ej: Dirección Provincial, Consultorio, etc.">
        </div>

        <div class="field">
          <label>Hecho por</label>
          <input class="input" type="text" id="hecho_por" placeholder="Nombre del responsable">
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
        <div class="title" style="font-size:16px">Historial de Salidas</div>
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
            <?php if (count($history_out) === 0): ?>
              <tr><td colspan="4" class="muted">No hay registros.</td></tr>
            <?php else: ?>
              <?php foreach ($history_out as $h): ?>
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
        <input type="hidden" name="action" value="save_exit">
        <input type="hidden" name="fecha" id="f_fecha">
        <input type="hidden" name="area_salida" id="f_area_salida">
        <input type="hidden" name="area_destino" id="f_area_destino">
        <input type="hidden" name="hecho_por" id="f_hecho_por">
        <input type="hidden" name="items_json" id="f_items">
      </form>
    </section>

  </main>
</div>

<footer class="footer">
  <div class="footer-inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

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
    document.getElementById('f_area_salida').value = document.getElementById('area_salida').value;
    document.getElementById('f_area_destino').value = document.getElementById('area_destino').value;
    document.getElementById('f_hecho_por').value = document.getElementById('hecho_por').value;
    document.getElementById('f_items').value = JSON.stringify(items);

    document.getElementById('frmSave').submit();
  });

})();
</script>

</body>
</html>
