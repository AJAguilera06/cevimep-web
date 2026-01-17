<?php
session_start();

if (!isset($_SESSION["user"])) {
  header("Location: /login.php");
  exit;
}

require_once __DIR__ . "/../../config/db.php";
$conn = $pdo;

$user = $_SESSION["user"];
$year = date("Y");
$today = date("Y-m-d");

/* ===== SEDE ===== */
$branch_id = (int)($user["branch_id"] ?? 0);
$flash_error = "";
$flash_ok = "";
$branch_warning = "";

/* ===== Nombre sede actual ===== */
$branch_name = "";
if ($branch_id > 0) {
  try {
    $st = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
    $st->execute([$branch_id]);
    $branch_name = (string)($st->fetchColumn() ?? "");
  } catch (Exception $e) {}
}
if ($branch_name === "") $branch_name = ($branch_id > 0) ? "Sede #".$branch_id : "CEVIMEP";

if ($branch_id <= 0) {
  $branch_warning = "⚠️ Este usuario no tiene sede asignada. No se puede registrar entrada.";
}

/* ===== Categorías (para filtro) ===== */
$categories = [];
try {
  $stC = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
  $categories = $stC->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

/* ===== Productos (alfabético) con categoría ===== */
$products = [];
try {
  $st = $conn->query("
    SELECT i.id, i.name, COALESCE(c.name,'') AS category, COALESCE(c.id,0) AS category_id
    FROM inventory_items i
    LEFT JOIN inventory_categories c ON c.id=i.category_id
    WHERE i.is_active=1
    ORDER BY i.name ASC
  ");
  $products = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $flash_error = "Error cargando productos.";
}

/* ===== Historial de ENTRADAS (sede actual) ===== */
$history_in = [];
if ($branch_id > 0) {
  try {
    $stH = $conn->prepare("
      SELECT 
        m.id, m.qty, m.note, m.created_at, m.created_by,
        i.name AS product,
        COALESCE(c.name,'') AS category
      FROM inventory_movements m
      JOIN inventory_items i ON i.id = m.item_id
      LEFT JOIN inventory_categories c ON c.id = i.category_id
      WHERE m.branch_id = ? AND m.movement_type = 'IN'
      ORDER BY m.id DESC
      LIMIT 50
    ");
    $stH->execute([$branch_id]);
    $history_in = $stH->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {}
}

/* ===========================
   IMPRIMIR DETALLE (FACTURA)
   ?print=1&id=XX
=========================== */
if (isset($_GET["print"]) && (int)($_GET["id"] ?? 0) > 0) {
  $mid = (int)$_GET["id"];

  $stOne = $conn->prepare("
    SELECT m.*, i.name AS product, COALESCE(c.name,'') AS category
    FROM inventory_movements m
    JOIN inventory_items i ON i.id = m.item_id
    LEFT JOIN inventory_categories c ON c.id = i.category_id
    WHERE m.id = ? AND m.branch_id = ? AND m.movement_type = 'IN'
    LIMIT 1
  ");
  $stOne->execute([$mid, $branch_id]);
  $one = $stOne->fetch(PDO::FETCH_ASSOC);

  if (!$one) { echo "No encontrado"; exit; }

  // agrupar por “comprobante”: misma nota + created_by + created_at
  $stAll = $conn->prepare("
    SELECT m.qty, m.note, m.created_at, m.created_by,
           i.name AS product, COALESCE(c.name,'') AS category
    FROM inventory_movements m
    JOIN inventory_items i ON i.id = m.item_id
    LEFT JOIN inventory_categories c ON c.id = i.category_id
    WHERE m.branch_id = ?
      AND m.movement_type = 'IN'
      AND m.note = ?
      AND m.created_by = ?
      AND m.created_at = ?
    ORDER BY i.name ASC
  ");
  $stAll->execute([$branch_id, $one["note"], $one["created_by"], $one["created_at"]]);
  $lines = $stAll->fetchAll(PDO::FETCH_ASSOC);

  // Parsear meta del note
  $note = (string)$one["note"];
  $meta = [
    "FECHA" => "",
    "SUPLIDOR" => "",
    "DESTINO" => "",
    "NOTA" => ""
  ];
  foreach (explode("|", $note) as $part) {
    $part = trim($part);
    if (strpos($part, "=") !== false) {
      [$k,$v] = array_map("trim", explode("=", $part, 2));
      if (isset($meta[$k])) $meta[$k] = $v;
    }
  }

  $logoPath = "/assets/img/CEVIMEP.png";
  ?>
  <!doctype html>
  <html lang="es">
  <head>
    <meta charset="utf-8">
    <title>CEVIMEP | Comprobante de Entrada</title>
    <style>
      @page{ size:A4; margin:15mm; }
      body{ margin:0; font-family:Arial, sans-serif; background:#fff; }
      .page{ height: calc(297mm - 30mm); display:flex; flex-direction:column; }
      .header{ text-align:center; margin-bottom:12px; }
      .header img{ max-width:260px; height:auto; }
      .header h3{ margin:8px 0 0; font-weight:bold; }
      .content{ flex:1; font-size:14px; }
      .row{ display:flex; justify-content:space-between; margin-bottom:6px; gap:10px; flex-wrap:wrap; }
      table{ width:100%; border-collapse:collapse; margin-top:10px; }
      th,td{ border:1px solid #ccc; padding:6px; font-size:13px; }
      th{ background:#f2f2f2; font-weight:bold; }
      .qty{ text-align:right; font-weight:bold; }
      .footer{ text-align:center; font-size:12px; font-weight:bold; margin-top:10px; }
    </style>
  </head>
  <body onload="window.print()">
    <div class="page">
      <div class="header">
        <img src="<?= $logoPath ?>" alt="CEVIMEP">
        <h3>Comprobante de Entrada</h3>
      </div>

      <div class="content">
        <div class="row">
          <div><strong>Fecha:</strong> <?= htmlspecialchars($meta["FECHA"] ?: substr((string)$one["created_at"],0,10)) ?></div>
          <div><strong>Suplidor:</strong> <?= htmlspecialchars($meta["SUPLIDOR"] ?: "-") ?></div>
        </div>

        <div class="row">
          <div><strong>Área de destino:</strong> <?= htmlspecialchars($meta["DESTINO"] ?: "-") ?></div>
          <div><strong>Nota:</strong> <?= htmlspecialchars($meta["NOTA"] ?: "-") ?></div>
        </div>

        <table>
          <thead>
            <tr>
              <th>Categoría</th>
              <th>Producto</th>
              <th class="qty">Cantidad</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($lines as $l): ?>
              <tr>
                <td><?= htmlspecialchars($l["category"] ?? "") ?></td>
                <td><?= htmlspecialchars($l["product"] ?? "") ?></td>
                <td class="qty"><?= (int)$l["qty"] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="footer">© <?= (int)date("Y") ?> CEVIMEP. Todos los derechos reservados.</div>
    </div>
  </body>
  </html>
  <?php
  exit;
}

/* =========================================================
   POST: Guardar + imprimir (factura inmediata)
========================================================= */
$print_mode = false;
$print_data = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  if ($branch_id <= 0) {
    $flash_error = "No tienes sede asignada.";
  } else {

    $fecha        = $_POST["fecha"] ?? $today;
    $suplidor     = trim($_POST["suplidor"] ?? "");
    $area_destino = trim($_POST["area_destino"] ?? $branch_name);
    $nota         = trim($_POST["nota"] ?? "");

    $item_ids = $_POST["item_id"] ?? [];
    $qtys     = $_POST["qty"] ?? [];

    if ($suplidor === "") {
      $flash_error = "Completa el suplidor.";
    } else {

      $lines = [];
      for ($i=0; $i<count($item_ids); $i++) {
        $iid = (int)($item_ids[$i] ?? 0);
        $q   = (int)($qtys[$i] ?? 0);
        if ($iid > 0 && $q > 0) {
          if (!isset($lines[$iid])) $lines[$iid] = 0;
          $lines[$iid] += $q;
        }
      }

      if (empty($lines)) {
        $flash_error = "Agrega al menos un producto.";
      } else {

        try {
          $conn->beginTransaction();

          $ids = array_keys($lines);
          $ph  = implode(",", array_fill(0, count($ids), "?"));

          $stInfo = $conn->prepare("
            SELECT i.id, i.name, COALESCE(c.name,'') AS category
            FROM inventory_items i
            LEFT JOIN inventory_categories c ON c.id=i.category_id
            WHERE i.id IN ($ph) AND i.is_active=1
          ");
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

          $created_by = (int)($user["id"] ?? 0);

          $metaNote = "FECHA={$fecha} | SUPLIDOR={$suplidor} | DESTINO={$area_destino}";
          if ($nota !== "") $metaNote .= " | NOTA={$nota}";

          foreach ($lines as $iid => $q) {
            if (!isset($infoMap[(int)$iid])) {
              throw new Exception("Producto inválido o inactivo (ID $iid).");
            }
            $stStock->execute([(int)$iid, $branch_id, (int)$q]);
            $stMov->execute([(int)$iid, $branch_id, (int)$q, $metaNote, $created_by]);
          }

          $conn->commit();

          $print_lines = [];
          foreach ($lines as $iid => $q) {
            $row = $infoMap[(int)$iid];
            $print_lines[] = [
              "category" => $row["category"] ?? "",
              "product"  => $row["name"] ?? "",
              "qty"      => (int)$q
            ];
          }

          $print_mode = true;
          $print_data = [
            "fecha" => $fecha,
            "suplidor" => $suplidor,
            "area_destino" => $area_destino,
            "nota" => $nota,
            "lines" => $print_lines
          ];

        } catch (Exception $e) {
          if ($conn->inTransaction()) $conn->rollBack();
          $flash_error = $e->getMessage();
        }
      }
    }
  }
}

/* =========================================================
   IMPRESIÓN (inmediata)
========================================================= */
if ($print_mode):
  $logoPath = "/assets/img/CEVIMEP.png";
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>CEVIMEP | Comprobante de Entrada</title>
<style>
  @page{ size:A4; margin:15mm; }
  body{ margin:0; font-family:Arial, sans-serif; background:#fff; }
  .page{ height: calc(297mm - 30mm); display:flex; flex-direction:column; }
  .header{ text-align:center; margin-bottom:12px; }
  .header img{ max-width:260px; height:auto; }
  .header h3{ margin:8px 0 0; font-weight:bold; }
  .content{ flex:1; font-size:14px; }
  .row{ display:flex; justify-content:space-between; margin-bottom:6px; gap:10px; flex-wrap:wrap; }
  table{ width:100%; border-collapse:collapse; margin-top:10px; }
  th,td{ border:1px solid #ccc; padding:6px; font-size:13px; }
  th{ background:#f2f2f2; font-weight:bold; }
  .qty{ text-align:right; font-weight:bold; }
  .footer{ text-align:center; font-size:12px; font-weight:bold; margin-top:10px; }
</style>
</head>
<body onload="window.print()">
<div class="page">
  <div class="header">
    <img src="<?= $logoPath ?>" alt="CEVIMEP">
    <h3>Comprobante de Entrada</h3>
  </div>

  <div class="content">
    <div class="row">
      <div><strong>Fecha:</strong> <?= htmlspecialchars($print_data["fecha"]) ?></div>
      <div><strong>Suplidor:</strong> <?= htmlspecialchars($print_data["suplidor"]) ?></div>
    </div>

    <div class="row">
      <div><strong>Área de destino:</strong> <?= htmlspecialchars($print_data["area_destino"]) ?></div>
      <div><strong>Nota:</strong> <?= htmlspecialchars($print_data["nota"] ?: "-") ?></div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Categoría</th>
          <th>Producto</th>
          <th class="qty">Cantidad</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($print_data["lines"] as $l): ?>
          <tr>
            <td><?= htmlspecialchars($l["category"]) ?></td>
            <td><?= htmlspecialchars($l["product"]) ?></td>
            <td class="qty"><?= (int)$l["qty"] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="footer">© <?= (int)date("Y") ?> CEVIMEP. Todos los derechos reservados.</div>
</div>
</body>
</html>
<?php
exit;
endif;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Inventario - Entrada</title>

  <!-- ✅ Igual que dashboard -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=11">

  <style>
    .formGrid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:10px}
    .rowAdd{display:grid;grid-template-columns:240px 1fr 170px auto;gap:12px;align-items:end;margin-top:12px}
    .field label{display:block;font-weight:900;color:var(--primary-2);font-size:13px;margin:0 0 6px}
    .input{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:14px;background:#fff;font-weight:700;outline:none;}
    .input:focus{border-color:rgba(20,184,166,.45);box-shadow:0 0 0 3px rgba(20,184,166,.12)}
    .actions{display:flex;justify-content:flex-end;margin-top:14px}
    .qtyRight{text-align:right}
    .miniHelp{margin-top:6px}
    .histWrap{display:none;margin-top:12px}
    .btnSmall{padding:8px 12px;border-radius:999px;font-weight:900;border:1px solid rgba(2,21,44,.12);background:#eef6ff;cursor:pointer}

    /* Submenu de inventario (bonito, sin tocar sidebar global) */
    .subnav{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
    .subnav a{
      display:inline-flex;align-items:center;gap:8px;
      padding:10px 14px;border-radius:999px;
      border:1px solid rgba(2,21,44,.12);
      background:#fff;text-decoration:none;font-weight:900;color:var(--primary-2);
    }
    .subnav a.active{background:rgba(20,184,166,.12);border-color:rgba(20,184,166,.35)}
    @media(max-width:900px){
      .formGrid{grid-template-columns:1fr}
      .rowAdd{grid-template-columns:1fr}
    }
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

  <!-- ✅ Sidebar global igual al dashboard -->
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
      <p><?= htmlspecialchars($branch_name) ?> · Entrada</p>

      <!-- ✅ Submenu del módulo Inventario -->
      <div class="subnav">
        <a href="/private/inventario/index.php">📦 Inventario</a>
        <a class="active" href="/private/inventario/entrada.php">➕ Entrada</a>
        <a href="/private/inventario/salida.php">➖ Salida</a>
      </div>
    </section>

    <?php if ($branch_warning): ?>
      <div class="card" style="border-color:rgba(239,68,68,.35); background:rgba(239,68,68,.08);">
        <p style="margin:0;font-weight:900;color:#b91c1c;"><?=htmlspecialchars($branch_warning)?></p>
      </div>
      <div style="height:12px;"></div>
    <?php endif; ?>

    <?php if ($flash_error): ?>
      <div class="card" style="border-color:rgba(239,68,68,.35); background:rgba(239,68,68,.08);">
        <p style="margin:0;font-weight:900;color:#b91c1c;"><?=htmlspecialchars($flash_error)?></p>
      </div>
      <div style="height:12px;"></div>
    <?php endif; ?>

    <div class="card">
      <h3 style="margin:0 0 6px;">Entrada</h3>
      <p class="muted" style="margin:0;">Registra entrada de inventario (sede actual)</p>

      <form method="POST">
        <div class="formGrid">
          <div class="field">
            <label>Fecha</label>
            <input class="input" type="date" name="fecha" value="<?=htmlspecialchars($today)?>">
          </div>

          <div class="field">
            <label>Suplidor</label>
            <input class="input" type="text" name="suplidor" placeholder="Ej: Almacén, Ministerio, Farmacia, etc.">
          </div>

          <div class="field">
            <label>Área de destino</label>
            <input class="input" type="text" name="area_destino" value="<?=htmlspecialchars($branch_name)?>" readonly>
          </div>

          <div class="field">
            <label>Nota (opcional)</label>
            <input class="input" type="text" name="nota" placeholder="Observación...">
          </div>
        </div>

        <hr style="margin:14px 0;opacity:.25;">

        <!-- FILTRO POR CATEGORÍA + PRODUCTO + CANTIDAD -->
        <div class="rowAdd">
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

        <p class="muted miniHelp">Al añadir, se mantiene seleccionado el producto y la cantidad.</p>

        <table class="table" style="margin-top:10px;">
          <thead>
            <tr>
              <th>Categoría</th>
              <th>Producto</th>
              <th style="width:140px;" class="qtyRight">Cantidad</th>
              <th style="width:160px;">Acción</th>
            </tr>
          </thead>
          <tbody id="tbodyItems">
            <tr id="emptyRow">
              <td colspan="4" class="muted">No hay productos agregados.</td>
            </tr>
          </tbody>
        </table>

        <div class="actions">
          <button type="submit" class="btn">Guardar e Imprimir</button>
        </div>
      </form>
    </div>

    <!-- HISTORIAL ENTRADAS (BOTÓN) -->
    <div style="height:14px;"></div>

    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
        <div>
          <h3 style="margin:0 0 6px;">Historial de Entradas</h3>
          <p class="muted" style="margin:0;">Últimos 50 registros (sede actual)</p>
        </div>
        <button type="button" class="btnSmall" id="btnToggleHist">Ver el historial</button>
      </div>

      <div class="histWrap" id="histWrap">
        <table class="table" style="margin-top:12px;">
          <thead>
            <tr>
              <th style="width:170px;">Fecha/Registro</th>
              <th>Categoría</th>
              <th>Producto</th>
              <th style="width:120px;" class="qtyRight">Cantidad</th>
              <th style="width:140px;">Detalle</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($history_in)): ?>
              <tr><td colspan="5" class="muted">No hay entradas registradas todavía.</td></tr>
            <?php else: ?>
              <?php foreach ($history_in as $h): ?>
                <tr>
                  <td><?= htmlspecialchars($h["created_at"] ?? "") ?></td>
                  <td><?= htmlspecialchars($h["category"] ?? "") ?></td>
                  <td><?= htmlspecialchars($h["product"] ?? "") ?></td>
                  <td class="qtyRight" style="font-weight:900;"><?= (int)$h["qty"] ?></td>
                  <td>
                    <a class="btn" style="padding:8px 10px;text-decoration:none;" target="_blank"
                       href="/private/inventario/entrada.php?print=1&id=<?= (int)$h["id"] ?>">
                      Detalle
                    </a>
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
  <div class="footer-inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

<script>
(function(){
  const selCat = document.getElementById('selCat');
  const selItem = document.getElementById('selItem');
  const qty = document.getElementById('qty');
  const btn = document.getElementById('btnAdd');
  const tbody = document.getElementById('tbodyItems');

  // Toggle historial
  const btnToggle = document.getElementById('btnToggleHist');
  const histWrap = document.getElementById('histWrap');
  btnToggle.addEventListener('click', () => {
    const open = histWrap.style.display === 'block';
    histWrap.style.display = open ? 'none' : 'block';
    btnToggle.textContent = open ? 'Ver el historial' : 'Ocultar historial';
  });

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
    if (er) er.remove();
  }

  function addRow(id, name, catName, q){
    ensureNoEmpty();

    const ex = tbody.querySelector('tr[data-id="'+id+'"]');
    if (ex) {
      const inp = ex.querySelector('input[name="qty[]"]');
      const txt = ex.querySelector('.jsQty');
      const cur = parseInt(inp.value||"0",10);
      const next = cur + q;
      inp.value = next;
      txt.textContent = next;
      return;
    }

    const tr = document.createElement('tr');
    tr.setAttribute('data-id', id);

    const tdC = document.createElement('td');
    tdC.textContent = catName;

    const tdN = document.createElement('td');
    tdN.textContent = name;

    const hidId = document.createElement('input');
    hidId.type="hidden"; hidId.name="item_id[]"; hidId.value=id;

    const hidQty = document.createElement('input');
    hidQty.type="hidden"; hidQty.name="qty[]"; hidQty.value=q;

    tdN.appendChild(hidId);
    tdN.appendChild(hidQty);

    const tdQ = document.createElement('td');
    tdQ.className = "qtyRight jsQty";
    tdQ.style.fontWeight = "900";
    tdQ.textContent = q;

    const tdA = document.createElement('td');
    const del = document.createElement('button');
    del.type="button";
    del.className="btn";
    del.style.padding="8px 10px";
    del.textContent="Eliminar";
    del.onclick = () => {
      tr.remove();
      if (tbody.children.length === 0) {
        const er = document.createElement('tr');
        er.id="emptyRow";
        er.innerHTML = '<td colspan="4" class="muted">No hay productos agregados.</td>';
        tbody.appendChild(er);
      }
    };
    tdA.appendChild(del);

    tr.appendChild(tdC);
    tr.appendChild(tdN);
    tr.appendChild(tdQ);
    tr.appendChild(tdA);
    tbody.appendChild(tr);
  }

  btn.addEventListener('click', () => {
    const id = parseInt(selItem.value||"0",10);
    const q  = parseInt(qty.value||"0",10);
    const opt = selItem.options[selItem.selectedIndex];
    const name = opt ? opt.text : "";
    const catName = opt ? (opt.getAttribute('data-cat-name') || "") : "";

    if (!id) { alert("Selecciona un producto"); return; }
    if (!q || q <= 0) { alert("Cantidad inválida"); return; }

    addRow(id, name, catName, q);
  });
})();
</script>

</body>
</html>
