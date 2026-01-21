<?php
session_start();
if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }

require_once __DIR__ . "/../../config/db.php";
$conn = $pdo;

$user = $_SESSION["user"];
$year = date("Y");
$today = date("Y-m-d");
$branch_id = (int)($user["branch_id"] ?? 0);
$created_by = (int)($user["id"] ?? 0);

$made_by_name = trim(($user["name"] ?? "") . " " . ($user["lastname"] ?? ""));
if ($made_by_name === "") $made_by_name = ($user["username"] ?? "Usuario");

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
      AND EXISTS (
        SELECT 1 FROM inventory_movements m2
        WHERE m2.item_id = i.id AND m2.branch_id = ?
      )
    ORDER BY i.name ASC
  ");
  $st->execute([$branch_id, $branch_id]);
  $products = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $flash_error = "Error cargando productos.";
}

/* ===== Historial IN ===== */
$history_in = [];
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


/* ===========================
   IMPRIMIR DETALLE (POR LOTE)
   ?print_batch=XXXX
=========================== */
if (isset($_GET["print_batch"]) && $_GET["print_batch"] !== "") {
  $batch = trim($_GET["print_batch"]);

  $rows = [];
  try {
    $stP = $conn->prepare("
      SELECT id, item_id, qty, note, created_by, created_at
      FROM inventory_movements
      WHERE branch_id=? AND movement_type='IN' AND note LIKE ?
      ORDER BY id ASC
    ");
    $stP->execute([$branch_id, "%BATCH={$batch}%"]);
    $rows = $stP->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {}

  if (!$rows || count($rows) === 0) { die("Registro no encontrado."); }

  // Parseo de metadatos (suplidor, destino, hecho_por) desde la nota
  $meta = [
    "FECHA" => "",
    "SUPLIDOR" => "",
    "DESTINO" => "",
    "HECHO_POR" => ""
  ];
  $note0 = (string)($rows[0]["note"] ?? "");
  foreach (array_keys($meta) as $k) {
    if (preg_match("/{$k}=([^\|\n\r]*)/i", $note0, $m)) {
      $meta[$k] = trim($m[1]);
    }
  }

  $ids = array_values(array_unique(array_map(fn($r)=> (int)$r["item_id"], $rows)));
  $infoMap = [];
  if (count($ids) > 0) {
    try {
      $in = implode(",", array_fill(0, count($ids), "?"));
      $stInfo = $conn->prepare("SELECT id, name FROM inventory_items WHERE id IN ($in)");
      $stInfo->execute($ids);
      foreach ($stInfo->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $infoMap[(int)$r["id"]] = $r["name"];
      }
    } catch (Exception $e) {}
  }

  $created_at = $rows[0]["created_at"] ?? "";
  header("Content-Type: text/html; charset=utf-8");
  ?>
  <!doctype html>
  <html lang="es">
  <head>
    <meta charset="utf-8">
    <title>Acuse de Entrada | <?= htmlspecialchars($batch) ?></title>
    <style>
      body{font-family:Arial,Helvetica,sans-serif;margin:24px;background:#f8fafc}
      .box{
        max-width:760px;
        margin:40px auto;
        background:#fff;
        border:1px solid rgba(15,23,42,.08);
        border-radius:18px;
        padding:22px;
        box-shadow:0 20px 40px rgba(2,6,23,.12);
      }
      h2{margin:0 0 6px;text-align:center;font-size:28px}
      .muted{color:#64748b;font-size:13px;line-height:1.4;text-align:center}
      table{width:100%;border-collapse:collapse;margin-top:16px}
      th,td{border-bottom:1px solid rgba(0,0,0,.08);padding:10px 8px;text-align:left;font-size:14px}
      th{background:#f1f5f9;text-transform:uppercase;font-size:12px;letter-spacing:.04em;color:#475569}
      .right{text-align:right}
      .topgrid{display:flex;gap:12px;flex-wrap:wrap;margin-top:14px;justify-content:center}
      .pill{
        border:1px solid rgba(15,23,42,.10);
        border-radius:14px;
        padding:10px 12px;
        min-width:220px;
        background:#fff;
      }
      .pill b{display:block;font-size:12px;text-transform:uppercase;color:#64748b;margin-bottom:4px}
      @media print{
        body{margin:0;background:#fff}
        .box{margin:0;border:none;border-radius:0;box-shadow:none;max-width:none}
      }
    </style>
  </head>
  <body onload="window.print(); setTimeout(function(){ window.location.href='entrada.php?printed=1'; }, 700);">
    <div class="box">
      <h2>Entrada de Inventario</h2>
      <div class="muted">CEVIMEP - <?= htmlspecialchars($branch_name) ?> · Lote: <?= htmlspecialchars($batch) ?></div>
      <div class="muted">Fecha/Hora sistema: <?= htmlspecialchars($created_at) ?></div>

      <div class="topgrid">
        <div class="pill"><b>Fecha</b><?= htmlspecialchars($meta["FECHA"]) ?></div>
        <div class="pill"><b>Sucursal / Destino</b><?= htmlspecialchars($meta["DESTINO"] ?: $branch_name) ?></div>
        <div class="pill"><b>Suplidor</b><?= htmlspecialchars($meta["SUPLIDOR"]) ?></div>
        <div class="pill"><b>Hecho por</b><?= htmlspecialchars($meta["HECHO_POR"]) ?></div>
      </div>

      <table>
        <thead>
          <tr>
            <th>Producto</th>
            <th class="right">Cantidad</th>
            <th>ID Mov.</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $r):
            $iid = (int)$r["item_id"];
            $nm = $infoMap[$iid] ?? ("ID ".$iid);
          ?>
            <tr>
              <td><?= htmlspecialchars($nm) ?></td>
              <td class="right"><?= (int)$r["qty"] ?></td>
              <td>#<?= (int)$r["id"] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </body>
  </html>
  <?php
  exit;
}


/* ===========================
   GUARDAR ENTRADA
=========================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save_entry") {

  $fecha = trim($_POST["fecha"] ?? $today);
  $suplidor = trim($_POST["suplidor"] ?? "");
  $area_destino = trim($_POST["area_destino"] ?? $branch_name);
  $hecho_por = trim($_POST["hecho_por"] ?? $made_by_name);

  $items_json = $_POST["items_json"] ?? "[]";
  $items = json_decode($items_json, true);
  if (!is_array($items)) $items = [];

  if (count($items) === 0) {
    $flash_error = "No hay productos agregados.";
  } else {
    try {
      $batch = date("YmdHis") . "-" . substr(bin2hex(random_bytes(3)), 0, 6);

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

      $stStock = $conn->prepare("
        INSERT INTO inventory_stock (item_id, branch_id, quantity)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
      ");

      $stMov = $conn->prepare("
        INSERT INTO inventory_movements (item_id, branch_id, movement_type, qty, note, created_by)
        VALUES (?, ?, 'IN', ?, ?, ?)
      ");

      foreach ($items as $it) {
        $iid = (int)($it["id"] ?? 0);
        $q = (int)($it["qty"] ?? 0);
        if ($iid <= 0 || $q <= 0) continue;

        $pname = $infoMap[$iid]["name"] ?? ("ID ".$iid);

        // Nota con metadatos (usado para reconstruir e imprimir el acuse)
        $metaNote = "BATCH={$batch}
FECHA={$fecha} | SUPLIDOR={$suplidor} | DESTINO={$area_destino} | HECHO_POR={$hecho_por} | ITEM={$pname}";

        $stStock->execute([$iid, $branch_id, $q]);
        $stMov->execute([$iid, $branch_id, $q, $metaNote, $created_by]);
      }

      $conn->commit();
      $flash_success = "Entrada guardada correctamente.";

      if ((int)($_POST["do_print"] ?? 0) === 1) {
        header("Location: entrada.php?print_batch=" . urlencode($batch));
        exit;
      }
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
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Inventario - Entrada</title>

  <link rel="stylesheet" href="/public/assets/css/styles.css?v=11">

  <style>
    .big-header{
      min-height: 140px;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:32px 24px !important;
    }
    .big-header h1{font-size:40px !important;margin:0}
    .big-header .muted{margin-top:10px !important;font-size:15px !important}

    .page-head{display:flex;align-items:center;justify-content:center;margin-bottom:12px}
    .hero.hero-white{background:transparent !important; padding:0 !important; margin-bottom:16px;}
    .hero.hero-white .page-head{
      background:#fff !important;
      border:1px solid rgba(15,23,42,.08);
      border-radius:18px;
      padding:22px 24px;
      box-shadow:0 10px 30px rgba(2,6,23,.06);
      width:100%;
      max-width: 1200px;
    }
    .hero.hero-white .center{text-align:center}
    .hero.hero-white h1{margin:0; font-size:34px;}
    .hero.hero-white .muted{margin:6px 0 0 0; color:#64748b}

    .card{margin-top:12px}
    .form-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
    .field{flex:1;min-width:220px}
    .field label{display:block;font-size:13px;color:#475569;margin-bottom:6px;font-weight:600}
    .input, select.input{
      width:100%;
      height:42px;
      padding:10px 12px;
      border:1px solid rgba(15,23,42,.15);
      border-radius:14px;
      background:#fff;
      box-shadow:0 2px 10px rgba(2,6,23,.04);
      transition:box-shadow .15s ease, border-color .15s ease;
    }
    .input:focus, select.input:focus{
      outline:none;
      border-color:rgba(11,74,162,.55);
      box-shadow:0 0 0 4px rgba(14,165,164,.18), 0 8px 24px rgba(2,6,23,.08);
    }
    input.input[readonly]{background:#f8fafc; color:#334155}

    table{width:100%;border-collapse:collapse}
    th,td{padding:10px 12px;border-bottom:1px solid rgba(0,0,0,.06);text-align:left}
    th{font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.02em}
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


-pill" href="/public/logout.php">Salir</a>
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

    <!-- CUADRO BLANCO SUPERIOR -->
    <section class="hero hero-white">
      <div class="page-head big-header">
        <div class="center">
          <h1>Entrada</h1>
          <p class="muted">Sucursal: <?= htmlspecialchars($branch_name ?? '') ?></p>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="title">Entrada</div>
      <div class="muted">Registra entrada de inventario (sede actual)</div>

      <?php if ($flash_success): ?>
        <div style="margin-top:10px;padding:10px 12px;border-radius:12px;background:#d1fae5;color:#065f46">
          <?= htmlspecialchars($flash_success) ?>
        </div>
      <?php endif; ?>
      <?php if ($flash_error): ?>
        <div style="margin-top:10px;padding:10px 12px;border-radius:12px;background:#fee2e2;color:#991b1b">
          <?= htmlspecialchars($flash_error) ?>
        </div>
      <?php endif; ?>

      <div class="form-row" style="margin-top:12px">
        <div class="field">
          <label>Fecha</label>
          <input class="input" type="date" id="fecha" value="<?= htmlspecialchars($today) ?>">
        </div>

        <div class="field">
          <label>Área de destino</label>
          <input class="input" type="text" id="area_destino" value="<?= htmlspecialchars($branch_name) ?>" readonly>
        </div>

        <div class="field">
          <label>Suplidor</label>
          <input class="input" type="text" id="suplidor" placeholder="Ej: Suplidor X">
        </div>

        <div class="field">
          <label>Hecho por</label>
          <input class="input" type="text" id="hecho_por" value="<?= htmlspecialchars($made_by_name) ?>">
        </div>
      </div>

      <div class="form-row" style="margin-top:12px">
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

        <div class="field" style="flex:0 0 auto;min-width:auto">
          <button type="button" class="
/assets/css/styles.css


 
/assets/css/styles.css


-primary" id="
/assets/css/styles.css


Add">Añadir</button>
        </div>
      </div>

      <p class="muted" style="margin:10px 0 0">
        Al añadir, se mantiene seleccionado el producto y la cantidad.
      </p>

      <table style="margin-top:10px">
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

      <div style="display:flex;justify-content:flex-end;margin-top:14px;gap:10px;flex-wrap:wrap">
        <button type="button" class="
/assets/css/styles.css


 
/assets/css/styles.css


-soft" id="
/assets/css/styles.css


ToggleHist">Ver el historial</button>
        <button type="button" class="
/assets/css/styles.css


 
/assets/css/styles.css


-primary" id="
/assets/css/styles.css


Save">Guardar e Imprimir</button>
      </div>

      <div id="histWrap" style="display:none;margin-top:16px">
        <div class="title" style="font-size:16px">Historial de Entradas</div>
        <div class="muted">Últimos 50 registros (sede actual)</div>

        <table style="margin-top:10px">
          <thead>
            <tr>
              <th>ID</th>
              <th>Cantidad</th>
              <th>Nota</th>
              <th>Fecha</th>
              <th class="right">Detalle</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($history_in) === 0): ?>
              <tr><td colspan="5" class="muted">No hay registros.</td></tr>
            <?php else: ?>
              <?php foreach ($history_in as $h):
                $note = (string)($h["note"] ?? "");
                $batch = "";
                if (preg_match("/BATCH=([0-9]{14}\-[0-9a-fA-F]{6})/",$note,$m)) {
                  $batch = trim($m[1]);
                }
              ?>
                <tr>
                  <td>#<?= (int)$h["id"] ?></td>
                  <td><?= (int)$h["qty"] ?></td>
                  <td style="max-width:560px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                    <?= htmlspecialchars($note) ?>
                  </td>
                  <td><?= htmlspecialchars($h["created_at"]) ?></td>
                  <td class="right">
                    <?php if ($batch !== ""): ?>
                      <a class="
/assets/css/styles.css


 
/assets/css/styles.css


-soft" href="entrada.php?print_batch=<?= urlencode($batch) ?>" target="_blank" rel="noopener">Ver acuse</a>
                    <?php else: ?>
                      <span class="muted">N/A</span>
                    <?php endif; ?>
                  </td>
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
        <input type="hidden" name="hecho_por" id="f_hecho_por">
        <input type="hidden" name="area_destino" id="f_area_destino">
        <input type="hidden" name="items_json" id="f_items">
        <input type="hidden" name="do_print" id="f_do_print" value="1">
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
  const 
/assets/css/styles.css


 = document.getElementById('
/assets/css/styles.css


Add');
  const tbody = document.getElementById('tbodyItems');

  const 
/assets/css/styles.css


Toggle = document.getElementById('
/assets/css/styles.css


ToggleHist');
  const histWrap = document.getElementById('histWrap');

  
/assets/css/styles.css


Toggle.addEventListener('click', () => {
    const open = histWrap.style.display === 'block';
    histWrap.style.display = open ? 'none' : 'block';
    
/assets/css/styles.css


Toggle.textContent = open ? 'Ver el historial' : 'Ocultar historial';
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
        <td class="right"><button type="button" class="
/assets/css/styles.css


 
/assets/css/styles.css


-soft" data-del="${it.id}">Quitar</button></td>
      `;
      tbody.appendChild(tr);
    }
  }

  
/assets/css/styles.css


.addEventListener('click', () => {
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

  document.getElementById('
/assets/css/styles.css


Save').addEventListener('click', () => {
    if (items.length === 0) return;

    document.getElementById('f_fecha').value = document.getElementById('fecha').value;
    document.getElementById('f_suplidor').value = document.getElementById('suplidor').value;
    document.getElementById('f_hecho_por').value = document.getElementById('hecho_por').value;
    document.getElementById('f_area_destino').value = document.getElementById('area_destino').value;
    document.getElementById('f_items').value = JSON.stringify(items);
    document.getElementById('f_do_print').value = '1';

    document.getElementById('frmSave').submit();
  });

})();
</script>

</body>
</html>
