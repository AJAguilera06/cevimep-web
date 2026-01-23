<?php
declare(strict_types=1);
require_once __DIR__ . "/../_guard.php";

$conn = $pdo;

$user = $_SESSION["user"];
$branch_id = (int)($user["branch_id"] ?? 0);
$rol = $user["role"] ?? "";
$nombre = $user["full_name"] ?? "Usuario";

$branch_name = "CEVIMEP";
if ($branch_id > 0) {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $bn = $stB->fetchColumn();
  if ($bn) $branch_name = (string)$bn;
}

$today = date("Y-m-d");

if (!isset($_SESSION["entrada_cart"]) || !is_array($_SESSION["entrada_cart"])) {
  $_SESSION["entrada_cart"] = [];
}

function table_columns(PDO $conn, string $table): array {
  $st = $conn->prepare("SHOW COLUMNS FROM `$table`");
  $st->execute();
  $cols = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $cols[] = $r["Field"];
  return $cols;
}
function pick_col(array $cols, array $candidates): ?string {
  $map = array_flip(array_map("strtolower", $cols));
  foreach ($candidates as $c) {
    $k = strtolower($c);
    if (isset($map[$k])) return $cols[$map[$k]];
  }
  return null;
}
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }

/* =========================
   CARGA: Items/Categorías (solo sede actual si existe branch_id en items)
========================= */
$itemCols = table_columns($conn, "inventory_items");
$colItemId = pick_col($itemCols, ["id"]);
$colItemName = pick_col($itemCols, ["name","nombre"]);
$colItemBranch = pick_col($itemCols, ["branch_id","sucursal_id"]);
$colItemCategory = pick_col($itemCols, ["category_id","categoria_id","category"]);

$catCols = table_columns($conn, "inventory_categories");
$colCatId = pick_col($catCols, ["id"]);
$colCatName = pick_col($catCols, ["name","nombre"]);
$colCatBranch = pick_col($catCols, ["branch_id","sucursal_id"]);

$categories = [];
if ($colCatId && $colCatName) {
  $where = "";
  $params = [];
  if ($colCatBranch) { $where = "WHERE `$colCatBranch`=?"; $params[] = $branch_id; }
  $stC = $conn->prepare("SELECT `$colCatId` AS id, `$colCatName` AS name FROM inventory_categories $where ORDER BY `$colCatName` ASC");
  $stC->execute($params);
  $categories = $stC->fetchAll(PDO::FETCH_ASSOC);
}

$whereItems = "";
$paramsItems = [];
if ($colItemBranch) { $whereItems = "WHERE i.`$colItemBranch`=?"; $paramsItems[] = $branch_id; }

$stI = $conn->prepare(
  "SELECT i.`$colItemId` AS id, i.`$colItemName` AS name" .
  ($colItemCategory ? ", i.`$colItemCategory` AS category_id" : "") .
  " FROM inventory_items i $whereItems ORDER BY i.`$colItemName` ASC"
);
$stI->execute($paramsItems);
$products = $stI->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   MOVEMENTS columns
========================= */
$movCols = table_columns($conn, "inventory_movements");
$colMovId = pick_col($movCols, ["id"]);
$colMovBranch = pick_col($movCols, ["branch_id","sucursal_id"]);
$colMovType = pick_col($movCols, ["movement_type","type","tipo","movement"]);
$colMovItem = pick_col($movCols, ["item_id","inventory_item_id","product_id","producto_id"]);
$colMovQty = pick_col($movCols, ["qty","quantity","cantidad"]);
$colMovNote = pick_col($movCols, ["note","nota","observacion","descripcion","comment"]);
$colMovCreated = pick_col($movCols, ["created_at","fecha","date","created","created_on"]);
$colMovCreatedBy = pick_col($movCols, ["created_by","user_id","registrado_por"]);

if (!$colMovBranch || !$colMovItem || !$colMovQty) {
  die("Config BD: inventory_movements no tiene columnas requeridas.");
}

/* =========================
   AJAX: historial (mismo archivo)
========================= */
if (isset($_GET["ajax"]) && $_GET["ajax"] === "history") {
  header("Content-Type: text/html; charset=utf-8");

  $orderCol = $colMovCreated ?: $colMovId;

  $whereType = "";
  if ($colMovType) {
    $whereType = " AND `$colMovType` IN ('IN','in','Entrada','entrada','E','e')";
  }

  $sql = "SELECT `$colMovId` AS id" .
         ($colMovCreated ? ", `$colMovCreated` AS created_at" : "") .
         ($colMovNote ? ", `$colMovNote` AS note" : "") .
         " FROM inventory_movements
           WHERE `$colMovBranch`=? $whereType
           ORDER BY `$orderCol` DESC
           LIMIT 50";

  $stH = $conn->prepare($sql);
  $stH->execute([$branch_id]);
  $rows = $stH->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) { echo "<p class='muted'>No hay entradas registradas.</p>"; exit; }

  echo "<table class='table'><thead><tr>
          <th style='width:110px;'>Mov.</th>
          <th style='width:190px;'>Fecha</th>
          <th>Nota</th>
          <th style='width:140px;'>Acción</th>
        </tr></thead><tbody>";

  foreach ($rows as $r) {
    $id = (int)$r["id"];
    $dt = $r["created_at"] ?? "";
    $note = $r["note"] ?? "";
    $dtOut = ($dt && strtotime($dt) !== false) ? date("d/m/Y H:i", strtotime((string)$dt)) : "-";
    echo "<tr>
            <td>#{$id}</td>
            <td>".h($dtOut)."</td>
            <td>".h((string)$note)."</td>
            <td><a class='btn btn-small' href='?print={$id}'>Imprimir</a></td>
          </tr>";
  }

  echo "</tbody></table>";
  exit;
}

/* =========================
   Reimpresión
========================= */
if (isset($_GET["print"])) {
  $mov_id = (int)$_GET["print"];

  $stM = $conn->prepare("SELECT * FROM inventory_movements WHERE `$colMovId`=? AND `$colMovBranch`=? LIMIT 1");
  $stM->execute([$mov_id, $branch_id]);
  $mov = $stM->fetch(PDO::FETCH_ASSOC);
  if (!$mov) die("Movimiento no encontrado.");

  $noteRaw = ($colMovNote && isset($mov[$colMovNote])) ? (string)$mov[$colMovNote] : "";
  $batch = null;
  if ($noteRaw && preg_match('/BATCH=([A-Z0-9\\-]+)/', $noteRaw, $m)) $batch = $m[1];

  $itemsPrint = [];
  if ($batch && $colMovNote && $colMovType) {
    $sql = "
      SELECT i.`$colItemName` AS name, m.`$colMovQty` AS qty
      FROM inventory_movements m
      INNER JOIN inventory_items i ON i.`$colItemId` = m.`$colMovItem`
      WHERE m.`$colMovBranch`=? AND m.`$colMovType` IN ('IN','in','Entrada','entrada','E','e')
        AND m.`$colMovNote` LIKE ?
      ORDER BY i.`$colItemName` ASC
    ";
    $st = $conn->prepare($sql);
    $st->execute([$branch_id, "%BATCH=$batch%"]);
    $itemsPrint = $st->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $nm = "";
    $stN = $conn->prepare("SELECT `$colItemName` FROM inventory_items WHERE `$colItemId`=? LIMIT 1");
    $stN->execute([(int)$mov[$colMovItem]]);
    $nm = (string)$stN->fetchColumn();
    $itemsPrint = [[ "name" => $nm, "qty" => (int)$mov[$colMovQty] ]];
  }

  $dt = ($colMovCreated && !empty($mov[$colMovCreated])) ? (string)$mov[$colMovCreated] : "";
  $dtOut = ($dt && strtotime($dt) !== false) ? date("d/m/Y H:i", strtotime($dt)) : date("d/m/Y H:i");
  ?>
  <!doctype html>
  <html lang="es">
  <head>
    <meta charset="utf-8">
    <title>Imprimir Entrada</title>
    <style>
      body{font-family:Arial,sans-serif;padding:16px;}
      .wrap{max-width:560px;margin:0 auto;}
      table{width:100%;border-collapse:collapse;}
      th,td{border-bottom:1px solid #ddd;padding:8px;text-align:left;}
      th:last-child,td:last-child{text-align:right;}
      .small{font-size:12px;opacity:.75;}
    </style>
  </head>
  <body>
    <div class="wrap">
      <h2>CEVIMEP - Entrada</h2>
      <div><strong>Sucursal:</strong> <?= h($branch_name) ?></div>
      <div><strong>Fecha:</strong> <?= h($dtOut) ?></div>
      <div><strong>Movimiento #:</strong> <?= (int)$mov_id ?></div>
      <?php if ($noteRaw): ?><div><strong>Nota:</strong> <?= h($noteRaw) ?></div><?php endif; ?>
      <hr>
      <table>
        <thead><tr><th>Producto</th><th>Cant.</th></tr></thead>
        <tbody>
          <?php foreach ($itemsPrint as $it): ?>
            <tr><td><?= h((string)$it["name"]) ?></td><td><?= (int)$it["qty"] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="small">Generado por CEVIMEP</p>
    </div>
    <script>window.addEventListener("load",()=>window.print());</script>
  </body>
  </html>
  <?php
  exit;
}

/* =========================
   Acciones: añadir / quitar / vaciar / guardar+imprimir
========================= */
$errors = [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  if (isset($_POST["action"]) && $_POST["action"] === "add") {
    $item_id = (int)($_POST["item_id"] ?? 0);
    $qty = (int)($_POST["qty"] ?? 0);

    if ($item_id <= 0) $errors[] = "Selecciona un producto.";
    if ($qty <= 0) $errors[] = "Cantidad inválida.";

    if (!$errors) {
      // Validar que el item sea de la sucursal si existe branch_id en items
      if ($colItemBranch) {
        $st = $conn->prepare("SELECT `$colItemId` FROM inventory_items WHERE `$colItemId`=? AND `$colItemBranch`=? LIMIT 1");
        $st->execute([$item_id, $branch_id]);
        if (!$st->fetchColumn()) $errors[] = "Ese producto no pertenece a esta sede.";
      }
    }

    if (!$errors) {
      $_SESSION["entrada_cart"][] = ["item_id"=>$item_id, "qty"=>$qty];
      header("Location: entrada.php");
      exit;
    }
  }

  if (isset($_POST["action"]) && $_POST["action"] === "remove") {
    $idx = (int)($_POST["idx"] ?? -1);
    if ($idx >= 0 && isset($_SESSION["entrada_cart"][$idx])) {
      array_splice($_SESSION["entrada_cart"], $idx, 1);
    }
    header("Location: entrada.php");
    exit;
  }

  if (isset($_POST["action"]) && $_POST["action"] === "clear") {
    $_SESSION["entrada_cart"] = [];
    header("Location: entrada.php");
    exit;
  }

  if (isset($_POST["action"]) && $_POST["action"] === "save_print") {

    $supplier = trim((string)($_POST["supplier"] ?? ""));
    $note = trim((string)($_POST["note"] ?? ""));

    if (empty($_SESSION["entrada_cart"])) $errors[] = "No hay productos agregados.";

    if (!$errors) {
      $batch = "ENT-" . strtoupper(substr(uniqid(), -8));
      $noteFull = "BATCH=$batch";
      if ($supplier !== "") $noteFull .= " | SUPLIDOR=$supplier";
      if ($note !== "")     $noteFull .= " | NOTA=$note";

      $createdByVal = (int)($user["id"] ?? 0);

      foreach ($_SESSION["entrada_cart"] as $c) {
        $iid = (int)$c["item_id"];
        $q   = (int)$c["qty"];

        $sqlIns = "INSERT INTO inventory_movements (`$colMovItem`, `$colMovBranch`, ".($colMovType?"`$colMovType`, ":"")."`$colMovQty`"
                . ($colMovNote ? ", `$colMovNote`" : "")
                . ($colMovCreatedBy ? ", `$colMovCreatedBy`" : "")
                . ") VALUES (?, ?, ".($colMovType?"'IN', ":"")."?".($colMovNote?", ?":"").($colMovCreatedBy?", ?":"").")";

        $params = [$iid, $branch_id, $q];
        if ($colMovNote) $params[] = $noteFull;
        if ($colMovCreatedBy) $params[] = $createdByVal;

        $stIns = $conn->prepare($sqlIns);
        $stIns->execute($params);
      }

      $_SESSION["entrada_cart"] = [];
      header("Location: entrada.php?success=1&batch=" . urlencode($batch));
      exit;
    }
  }
}

$print_receipt = false;
$batch_print = null;
if (isset($_GET["success"]) && $_GET["success"] == "1" && isset($_GET["batch"])) {
  $print_receipt = true;
  $batch_print = (string)$_GET["batch"];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Entrada</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=30">

  <style>
    /* estilos extra solo para el formulario (sin romper tu CSS global) */
    .page-title{
      display:flex; align-items:flex-end; justify-content:space-between; gap:12px;
      margin: 0 0 14px;
    }
    .page-title h1{ margin:0; font-size:20px; font-weight:900; color:#052a7a; }
    .grid-2{ display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .row-4{ display:grid; grid-template-columns: 260px 1fr 140px 140px; gap:14px; align-items:end; }
    .actions-right{ display:flex; justify-content:flex-end; gap:10px; margin-top:12px; }
    .muted2{ color:var(--muted); font-size:12px; font-weight:700; margin-top:6px; }
    .submenu a{ padding-left:34px !important; font-size:14px; font-weight:900; opacity:.95; }
  </style>
</head>

<body>

<!-- NAVBAR (igual dashboard) -->
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

<!-- LAYOUT -->
<div class="layout">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <h3 class="menu-title">Menú</h3>

    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">🧑‍🤝‍🧑</span> Pacientes</a>
      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;"><span class="ico">📅</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💳</span> Caja</a>

      <a class="active" href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <div class="submenu">
        <a class="active" href="/private/inventario/entrada.php"><span class="ico">➕</span> Entrada</a>
        <a href="/private/inventario/salida.php"><span class="ico">➖</span> Salida</a>
      </div>

      <a href="/private/estadisticas/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <!-- CONTENT -->
  <main class="content">

    <div class="card">
      <div class="page-title">
        <div>
          <h1>Entrada</h1>
          <p class="muted">Registra entrada de inventario (sede actual)</p>
          <p class="muted">Sucursal: <strong><?= h($branch_name) ?></strong></p>
        </div>
      </div>

      <?php if (!empty($errors)): ?>
        <div class="card" style="border-color: rgba(255,80,80,.25); background: rgba(255,80,80,.06);">
          <strong style="color:#7a1010;">Revisa:</strong>
          <ul style="margin:8px 0 0 18px;">
            <?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <!-- Agregar -->
      <form method="post" class="row-4" style="margin-top:14px;">
        <input type="hidden" name="action" value="add">

        <div>
          <label class="muted2">Categoría</label>
          <select id="category_id" name="category_id">
            <option value="">Todas</option>
            <?php foreach($categories as $c): ?>
              <option value="<?= (int)$c["id"] ?>"><?= h($c["name"]) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="muted2">Producto</label>
          <select id="item_id" name="item_id" required>
            <option value="">Selecciona</option>
            <?php foreach($products as $p): ?>
              <option value="<?= (int)$p["id"] ?>" data-cat="<?= isset($p["category_id"]) ? (int)$p["category_id"] : 0 ?>">
                <?= h($p["name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="muted2">Solo productos de esta sucursal.</div>
        </div>

        <div>
          <label class="muted2">Cantidad</label>
          <input type="number" name="qty" min="1" step="1" value="1" required>
        </div>

        <div style="display:flex; justify-content:flex-end;">
          <button class="btn" type="submit">Añadir</button>
        </div>
      </form>

      <!-- Detalle -->
      <div class="card" style="margin-top:14px;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
          <div>
            <h3 style="margin:0 0 6px;">Detalle</h3>
            <p class="muted">Productos agregados</p>
          </div>

          <form method="post" style="margin:0;">
            <input type="hidden" name="action" value="clear">
            <button class="btn btn-small" type="submit">Vaciar</button>
          </form>
        </div>

        <table class="table" style="margin-top:10px;">
          <thead>
            <tr>
              <th>#</th>
              <th>Producto</th>
              <th style="text-align:right;">Cantidad</th>
              <th style="text-align:right;">Acción</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($_SESSION["entrada_cart"])): ?>
            <tr><td colspan="4" class="muted" style="padding:14px;">No hay productos agregados.</td></tr>
          <?php else: ?>
            <?php foreach($_SESSION["entrada_cart"] as $i => $c): ?>
              <?php
                $pid = (int)$c["item_id"];
                $name = "";
                foreach($products as $pp){ if ((int)$pp["id"] === $pid){ $name = (string)$pp["name"]; break; } }
              ?>
              <tr>
                <td><?= (int)($i+1) ?></td>
                <td><?= h($name) ?></td>
                <td style="text-align:right;"><?= (int)$c["qty"] ?></td>
                <td style="text-align:right;">
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="idx" value="<?= (int)$i ?>">
                    <button class="btn btn-small" type="submit">Quitar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>

        <!-- Guardar -->
        <form method="post" style="margin-top:14px;">
          <input type="hidden" name="action" value="save_print">

          <div class="grid-2">
            <div>
              <label class="muted2">Suplidor</label>
              <input type="text" name="supplier" placeholder="Escribe el suplidor (opcional)">
            </div>
            <div>
              <label class="muted2">Nota</label>
              <input type="text" name="note" placeholder="Observaciones (opcional)">
            </div>
          </div>

          <div class="actions-right">
            <button class="btn" type="submit">Guardar e Imprimir</button>
          </div>
        </form>
      </div>

      <!-- Historial -->
      <div class="card" style="margin-top:14px;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
          <div>
            <h3 style="margin:0 0 6px;">Historial de Entradas</h3>
            <p class="muted">Últimos 50 registros (sede actual)</p>
          </div>
          <button class="btn btn-small" type="button" id="btnToggleHistory">Ver el historial</button>
        </div>

        <div class="historyBox" id="historyBox">
          <div class="muted" id="historyLoading" style="margin-top:10px; display:none;">Cargando...</div>
          <div id="historyContent" style="margin-top:10px;"></div>
        </div>
      </div>

    </div>

    <?php if ($print_receipt && $batch_print && $colMovNote && $colMovType): ?>
      <div class="card" id="printArea" style="max-width:560px; margin:16px auto;">
        <h3 style="text-align:center; margin:0;">Entrada</h3>
        <p class="muted" style="text-align:center; margin-top:6px;">Sucursal: <?= h($branch_name) ?></p>
        <p class="muted" style="text-align:center; margin-top:6px;">Batch: <?= h($batch_print) ?></p>
        <hr style="margin:12px 0;">

        <table class="table">
          <thead><tr><th>Producto</th><th style="text-align:right;">Cant.</th></tr></thead>
          <tbody>
          <?php
            $stP = $conn->prepare("
              SELECT i.`$colItemName` AS name, m.`$colMovQty` AS qty
              FROM inventory_movements m
              INNER JOIN inventory_items i ON i.`$colItemId` = m.`$colMovItem`
              WHERE m.`$colMovBranch`=? AND m.`$colMovType` IN ('IN','in','Entrada','entrada','E','e')
                AND m.`$colMovNote` LIKE ?
              ORDER BY i.`$colItemName` ASC
            ");
            $stP->execute([$branch_id, "%BATCH=$batch_print%"]);
            foreach($stP->fetchAll(PDO::FETCH_ASSOC) as $r){
              echo "<tr><td>".h($r["name"])."</td><td style='text-align:right;'>".(int)$r["qty"]."</td></tr>";
            }
          ?>
          </tbody>
        </table>

        <p class="muted" style="text-align:center; margin-top:10px;">Generado por CEVIMEP</p>
      </div>
      <script>window.addEventListener("load",()=>window.print());</script>
    <?php endif; ?>

  </main>
</div>

<!-- FOOTER (igual dashboard) -->
<div class="footer">
  <div class="inner">
    © <?= (int)date("Y") ?> CEVIMEP. Todos los derechos reservados.
  </div>
</div>

<script>
/* Filtro categoría -> producto */
(function(){
  const cat = document.getElementById("category_id");
  const prod = document.getElementById("item_id");
  if(!cat || !prod) return;

  const all = Array.from(prod.options).map(o => ({
    value: o.value,
    text: o.text,
    cat: o.getAttribute("data-cat") || "0"
  }));

  function rebuild(){
    const selectedCat = cat.value || "";
    const currentValue = prod.value;

    prod.innerHTML = "";
    const first = document.createElement("option");
    first.value = "";
    first.textContent = "Selecciona";
    prod.appendChild(first);

    for(const opt of all){
      if(!opt.value) continue;
      if(!selectedCat || opt.cat === selectedCat){
        const o = document.createElement("option");
        o.value = opt.value;
        o.textContent = opt.text;
        o.setAttribute("data-cat", opt.cat);
        prod.appendChild(o);
      }
    }

    if(currentValue){
      const exists = Array.from(prod.options).some(o => o.value === currentValue);
      if(exists) prod.value = currentValue;
    }
  }

  cat.addEventListener("change", rebuild);
  rebuild();
})();

/* Historial embebido */
(function(){
  const btn = document.getElementById("btnToggleHistory");
  const box = document.getElementById("historyBox");
  const loading = document.getElementById("historyLoading");
  const content = document.getElementById("historyContent");
  if(!btn || !box || !loading || !content) return;

  let loaded = false;

  async function loadHistory(){
    loading.style.display = "block";
    content.innerHTML = "";
    try{
      const res = await fetch("?ajax=history", { cache: "no-store" });
      const html = await res.text();
      content.innerHTML = html;
    }catch(e){
      content.innerHTML = "<p class='muted'>No se pudo cargar el historial.</p>";
    }finally{
      loading.style.display = "none";
      loaded = true;
    }
  }

  btn.addEventListener("click", async function(){
    const isOpen = box.classList.toggle("open");
    btn.textContent = isOpen ? "Ocultar historial" : "Ver el historial";
    if(isOpen && !loaded) await loadHistory();
  });
})();
</script>

</body>
</html>
