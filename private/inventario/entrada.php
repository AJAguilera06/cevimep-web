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

  if (!$rows) {
    echo "<p class='muted'>No hay entradas registradas.</p>";
    exit;
  }

  echo "<div style='overflow:auto;'><table class='table'><thead><tr>
          <th style='width:120px;'>ID</th>
          <th style='width:190px;'>Fecha</th>
          <th>Nota</th>
          <th style='width:140px;'>Acción</th>
        </tr></thead><tbody>";

  foreach ($rows as $r) {
    $id = (int)$r["id"];
    $fecha = $r["created_at"] ?? "";
    $note = $r["note"] ?? "";
    echo "<tr>
            <td>{$id}</td>
            <td>".h((string)$fecha)."</td>
            <td>".h((string)$note)."</td>
            <td style='text-align:right;'>—</td>
          </tr>";
  }

  echo "</tbody></table></div>";
  exit;
}

/* =========================
   Acciones carrito (add/remove/clear/save_print)
========================= */
$errors = [];
$success = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  if (isset($_POST["action"]) && $_POST["action"] === "add") {
    $item_id = (int)($_POST["item_id"] ?? 0);
    $qty = (int)($_POST["qty"] ?? 0);

    if ($item_id <= 0) $errors[] = "Selecciona un producto.";
    if ($qty <= 0) $errors[] = "Cantidad inválida.";

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
      header("Location: entrada.php");
      exit;
    }
  }

  if (isset($_POST["action"]) && $_POST["action"] === "clear") {
    $_SESSION["entrada_cart"] = [];
    header("Location: entrada.php");
    exit;
  }

  if (isset($_POST["action"]) && $_POST["action"] === "save_print") {

    $supplier = trim((string)($_POST["supplier"] ?? ""));

    if (empty($_SESSION["entrada_cart"])) $errors[] = "No hay productos agregados.";

    if (!$errors) {
      $batch = "ENT-" . strtoupper(substr(uniqid(), -8));
      $noteFull = "BATCH=$batch";
      if ($supplier !== "") $noteFull .= " | SUPLIDOR=$supplier"; // ✅ Nota eliminada (como pediste)

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
</head>
<body>

<div class="navbar">
  <div class="inner">
    <div class="brand"><span class="dot"></span><strong>CEVIMEP</strong></div>
    <div class="nav-right"><a class="btn-pill" href="/logout.php">Salir</a></div>
  </div>
</div>

<div class="layout">

  <?php require_once __DIR__ . "/../partials/sidebar.php"; ?>

  <main class="content">

    <div class="card">

      <!-- ✅ HEADER centrado y más arribita -->
      <div class="page-head-center">
  <h1>Entrada</h1>
  <div class="sub">Registra entrada de inventario (sede actual)</div>
  <div class="branch">Sucursal: <strong><?= h($branch_name) ?></strong></div>
</div>


      <?php if (!empty($errors)): ?>
        <div class="card" style="border-color: rgba(255,80,80,.25); background: rgba(255,80,80,.06);">
          <strong style="color:#7a1010;">Revisa:</strong>
          <ul style="margin:8px 0 0 18px;">
            <?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <!-- ✅ Campos arriba en una sola fila -->
      <form method="post" class="form-row-4">
  <input type="hidden" name="action" value="add">

  <div>
    <label>Categoría</label>
    <select id="category_id" name="category_id">
      <option value="">Todas</option>
      <?php foreach($categories as $c): ?>
        <option value="<?= (int)$c["id"] ?>"><?= h($c["name"]) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div>
    <label>Producto</label>
    <select id="item_id" name="item_id" required>
      <option value="">Selecciona</option>
      <?php foreach($products as $p): ?>
        <option value="<?= (int)$p["id"] ?>" data-cat="<?= isset($p["category_id"]) ? (int)$p["category_id"] : 0 ?>">
          <?= h($p["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="muted2" style="margin-top:6px;">Solo productos de esta sucursal.</div>
  </div>

  <div>
    <label>Cantidad</label>
    <input type="number" name="qty" min="1" step="1" value="1" required>
  </div>

  <div style="display:flex; justify-content:flex-end;">
    <button class="btn" type="submit" style="min-width:120px;">Añadir</button>
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
            <button class="btn" type="submit">Vaciar</button>
          </form>
        </div>

        <div style="margin-top:10px; overflow:auto;">
          <table class="table">
            <thead>
              <tr>
                <th style="width:80px;">#</th>
                <th>Producto</th>
                <th style="width:160px; text-align:right;">Cantidad</th>
                <th style="width:160px; text-align:right;">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($_SESSION["entrada_cart"])): ?>
                <tr><td colspan="4" class="muted" style="text-align:center;">No hay productos agregados.</td></tr>
              <?php else: ?>
                <?php foreach($_SESSION["entrada_cart"] as $i => $line): ?>
                  <?php
                    $pid = (int)$line["item_id"];
                    $qty = (int)$line["qty"];
                    $pname = "";
                    foreach ($products as $p) { if ((int)$p["id"] === $pid) { $pname = (string)$p["name"]; break; } }
                  ?>
                  <tr>
                    <td><?= (int)($i+1) ?></td>
                    <td><?= h($pname) ?></td>
                    <td style="text-align:right;"><?= (int)$qty ?></td>
                    <td style="text-align:right;">
                      <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="idx" value="<?= (int)$i ?>">
                        <button class="btn" type="submit">Quitar</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- ✅ Form guardar e imprimir: QUITÉ Nota -->
        <form method="post" class="section-card">
  <input type="hidden" name="action" value="save_print">

  <div class="supplier-row">
    <div>
      <label class="muted2">Suplidor</label>
      <input type="text" name="supplier" placeholder="Escribe el suplidor (opcional)">
    </div>

    <div class="actions-right" style="margin-top:0;">
      <button class="btn" type="submit" style="width:100%;">Guardar e Imprimir</button>
    </div>
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
          <button class="btn" type="button" id="btnToggleHistory">Ver el historial</button>
        </div>

        <div id="historyBox" style="display:none; margin-top:10px;">
          <div id="historyLoading" class="muted">Cargando...</div>
          <div id="historyContent"></div>
        </div>
      </div>

    </div>

  </main>
</div>

<div class="footer">
  <div class="inner">© <?= (int)date("Y") ?> CEVIMEP. Todos los derechos reservados.</div>
</div>

<script>
(function(){
  const btn = document.getElementById("btnToggleHistory");
  const box = document.getElementById("historyBox");
  const loading = document.getElementById("historyLoading");
  const content = document.getElementById("historyContent");
  if(!btn || !box) return;

  let loaded = false;
  btn.addEventListener("click", async () => {
    box.style.display = (box.style.display === "none" || box.style.display === "") ? "block" : "none";
    if (box.style.display === "block" && !loaded) {
      loaded = true;
      loading.style.display = "block";
      content.innerHTML = "";
      try{
        const res = await fetch("?ajax=history", { cache: "no-store" });
        content.innerHTML = await res.text();
      }catch(e){
        content.innerHTML = "<p class='muted'>No se pudo cargar el historial.</p>";
      }finally{
        loading.style.display = "none";
      }
    }
  });
})();
</script>

<script>
/* filtro categoría (front) */
(function(){
  const cat = document.getElementById("category_id");
  const prod = document.getElementById("item_id");
  if(!cat || !prod) return;

  const all = Array.from(prod.options).map(o => ({
    value:o.value, text:o.textContent, cat:o.getAttribute("data-cat") || "0"
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
</script>

</body>
</html>
