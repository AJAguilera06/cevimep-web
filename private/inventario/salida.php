<?php
declare(strict_types=1);
require_once __DIR__ . "/../_guard.php";

$conn = $pdo;

$user = $_SESSION["user"];
$branch_id = (int)($user["branch_id"] ?? 0);
$rol = $user["role"] ?? "";
$nombre = $user["full_name"] ?? "Usuario";
$user_id = (int)($user["id"] ?? 0);

$branch_name = "CEVIMEP";
if ($branch_id > 0) {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $bn = $stB->fetchColumn();
  if ($bn) $branch_name = (string)$bn;
}

$today = date("Y-m-d");

if (!isset($_SESSION["salida_cart"]) || !is_array($_SESSION["salida_cart"])) {
  $_SESSION["salida_cart"] = [];
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

function extract_fecha(string $note): string {
  if (preg_match('/FECHA=([0-9]{4}-[0-9]{2}-[0-9]{2})/i', $note, $m)) return $m[1];
  return "";
}

/* ===== Items/Categorías ===== */
$itemCols = table_columns($conn, "inventory_items");
$colItemId = pick_col($itemCols, ["id"]);
$colItemName = pick_col($itemCols, ["name","nombre"]);
$colItemStock = pick_col($itemCols, ["stock","existencia"]);
$colItemBranch = pick_col($itemCols, ["branch_id","sucursal_id"]);
$colItemCategory = pick_col($itemCols, ["category_id","categoria_id","category"]);

$catCols = table_columns($conn, "inventory_categories");
$colCatId = pick_col($catCols, ["id"]);
$colCatName = pick_col($catCols, ["name","nombre"]);
$colCatBranch = pick_col($catCols, ["branch_id","sucursal_id"]);

if (!$colItemId || !$colItemName) die("Config BD: inventory_items no tiene id/name.");

$categories = [];
if ($colCatId && $colCatName) {
  $where = "";
  $params = [];
  if ($colCatBranch) { $where = "WHERE `$colCatBranch`=?"; $params[] = $branch_id; }
  $stC = $conn->prepare("SELECT `$colCatId` AS id, `$colCatName` AS name FROM inventory_categories $where ORDER BY `$colCatName` ASC");
  $stC->execute($params);
  $categories = $stC->fetchAll(PDO::FETCH_ASSOC);
}

$whereItems = "WHERE 1=1 ";
$paramsItems = [];
if ($colItemBranch) { $whereItems .= " AND `$colItemBranch`=?"; $paramsItems[] = $branch_id; }

$stI = $conn->prepare(
  "SELECT `$colItemId` AS id, `$colItemName` AS name" .
  ($colItemCategory ? ", `$colItemCategory` AS category_id" : "") .
  ($colItemStock ? ", `$colItemStock` AS stock" : "") .
  " FROM inventory_items $whereItems ORDER BY `$colItemName` ASC"
);
$stI->execute($paramsItems);
$products = $stI->fetchAll(PDO::FETCH_ASSOC);

/* ===== Movements columns (tu tabla real) ===== */
$movCols = table_columns($conn, "inventory_movements");
$colMovId = pick_col($movCols, ["id"]);
$colMovBranch = pick_col($movCols, ["branch_id"]);
$colMovType = pick_col($movCols, ["movement_type"]);
$colMovItem = pick_col($movCols, ["item_id"]);
$colMovQty = pick_col($movCols, ["qty"]);
$colMovNote = pick_col($movCols, ["note"]);
$colMovCreatedBy = pick_col($movCols, ["created_by"]);

if (!$colMovBranch || !$colMovType || !$colMovItem || !$colMovQty || !$colMovNote || !$colMovCreatedBy) {
  die("Config BD: inventory_movements no tiene columnas requeridas (branch_id, movement_type, item_id, qty, note, created_by).");
}

/* ===== AJAX historial ===== */
if (isset($_GET["ajax"]) && $_GET["ajax"] === "history") {
  header("Content-Type: text/html; charset=utf-8");

  $sql = "SELECT m.`$colMovId` AS id, m.`$colMovQty` AS qty, m.`$colMovNote` AS note, i.`$colItemName` AS item_name
          FROM inventory_movements m
          INNER JOIN inventory_items i ON i.`$colItemId` = m.`$colMovItem`
          WHERE m.`$colMovBranch`=? AND m.`$colMovType` IN ('OUT','out','Salida','salida','S','s')
          ORDER BY m.`$colMovId` DESC
          LIMIT 50";
  $st = $conn->prepare($sql);
  $st->execute([$branch_id]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) {
    echo "<p class='muted'>No hay salidas registradas.</p>";
    exit;
  }

  echo "<div style='overflow:auto;'><table class='table'><thead><tr>
          <th>ID</th><th>Fecha</th><th>Producto</th><th>Cantidad</th><th>Nota</th>
        </tr></thead><tbody>";
  foreach ($rows as $r) {
    $id = (int)$r["id"];
    $fecha = extract_fecha((string)($r["note"] ?? ""));
    echo "<tr>
      <td>{$id}</td>
      <td>".h($fecha)."</td>
      <td>".h($r["item_name"] ?? "")."</td>
      <td style='text-align:right;'>".h($r["qty"] ?? "")."</td>
      <td>".h($r["note"] ?? "")."</td>
    </tr>";
  }
  echo "</tbody></table></div>";
  exit;
}

$errors = [];
$success = null;
$printPayload = null;

/* ===== Acciones carrito ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = $_POST["action"] ?? "";

  if ($action === "add") {
    $item_id = (int)($_POST["item_id"] ?? 0);
    $qty = (int)($_POST["qty"] ?? 0);

    if ($item_id <= 0) $errors[] = "Selecciona un producto.";
    if ($qty <= 0) $errors[] = "Cantidad inválida.";

    if (!$errors) {
      $_SESSION["salida_cart"][] = ["item_id"=>$item_id, "qty"=>$qty];
      $success = "Producto añadido.";
    }
  }

  if ($action === "remove") {
    $idx = (int)($_POST["idx"] ?? -1);
    if ($idx >= 0 && isset($_SESSION["salida_cart"][$idx])) {
      array_splice($_SESSION["salida_cart"], $idx, 1);
      $success = "Producto removido.";
    }
  }

  if ($action === "clear") {
    $_SESSION["salida_cart"] = [];
    $success = "Detalle vaciado.";
  }

  if ($action === "save_print") {

    $fecha = trim($_POST["fecha"] ?? $today);
    $destino = trim($_POST["destino"] ?? "");
    $nota = trim($_POST["nota"] ?? "");

    if (empty($_SESSION["salida_cart"])) $errors[] = "No hay productos agregados.";
    if ($fecha === "") $errors[] = "La fecha es obligatoria.";

    if (!$errors) {
      // Validar stock antes de guardar
      foreach ($_SESSION["salida_cart"] as $line) {
        $iid = (int)$line["item_id"];
        $q = (int)$line["qty"];

        $st = $conn->prepare("SELECT `$colItemStock` AS stock, `$colItemName` AS name FROM inventory_items WHERE `$colItemId`=? " . ($colItemBranch ? "AND `$colItemBranch`=?" : "") . " LIMIT 1");
        $st->execute($colItemBranch ? [$iid, $branch_id] : [$iid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $errors[] = "Producto inválido (ID {$iid})."; continue; }

        $stock = (float)($row["stock"] ?? 0);
        if ($q > $stock) $errors[] = "Stock insuficiente para ".($row["name"] ?? "producto").". Disponible: ".$stock;
      }
    }

    if (!$errors) {
      $conn->beginTransaction();
      try {
        $firstMovementId = null;

        foreach ($_SESSION["salida_cart"] as $line) {
          $iid = (int)$line["item_id"];
          $q = (int)$line["qty"];

          // NOTE como tú lo guardas
          $noteParts = [];
          $noteParts[] = "FECHA=".$fecha;
          $noteParts[] = "SALIDA=".$branch_id;
          $noteParts[] = "DESTINO=".($destino !== "" ? $destino : "-");
          if ($nota !== "") $noteParts[] = "NOTA=".$nota;
          $noteFinal = implode(" | ", $noteParts);

          // Insert movement
          $ins = $conn->prepare("INSERT INTO inventory_movements (`$colMovItem`,`$colMovBranch`,`$colMovType`,`$colMovQty`,`$colMovNote`,`$colMovCreatedBy`)
                                 VALUES (?,?,?,?,?,?)");
          $ins->execute([$iid, $branch_id, "OUT", $q, $noteFinal, $user_id]);

          if ($firstMovementId === null) $firstMovementId = (int)$conn->lastInsertId();

          // Update stock
          $up = $conn->prepare("UPDATE inventory_items SET `$colItemStock` = `$colItemStock` - ? WHERE `$colItemId`=? " . ($colItemBranch ? "AND `$colItemBranch`=?" : ""));
          $up->execute($colItemBranch ? [$q, $iid, $branch_id] : [$q, $iid]);
        }

        $conn->commit();

        $success = "Salida guardada correctamente.";
        $printPayload = [
          "movement_id" => $firstMovementId ?? 0,
          "fecha" => $fecha,
          "destino" => $destino,
          "nota" => $nota,
          "branch_id" => $branch_id,
          "usuario" => $nombre
        ];

        $_SESSION["salida_cart"] = [];
      } catch (Throwable $e) {
        $conn->rollBack();
        $errors[] = "Error al guardar: ".$e->getMessage();
      }
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Salida</title>
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
      <div class="page-title">
        <div>
          <h1>Salida</h1>
          <p class="muted">Registra salida de inventario (sede actual)</p>
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

      <?php if ($success): ?>
        <div class="card" style="border-color: rgba(0,180,120,.25); background: rgba(0,180,120,.06);">
          <strong style="color:#0a5;"><?= h($success) ?></strong>
        </div>
      <?php endif; ?>

      <!-- fila bonita igual a entrada -->
      <form method="post" style="display:grid; grid-template-columns: 260px 1fr 140px 140px; gap:14px; align-items:end; margin-top:14px;">
        <input type="hidden" name="action" value="add">

        <div>
          <label class="muted" style="display:block; margin-bottom:6px;">Categoría</label>
          <select id="category_id" name="category_id" class="input">
            <option value="">Todas</option>
            <?php foreach($categories as $c): ?>
              <option value="<?= (int)$c["id"] ?>"><?= h($c["name"]) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="muted" style="display:block; margin-bottom:6px;">Producto</label>
          <select id="item_id" name="item_id" class="input" required>
            <option value="">Selecciona</option>
            <?php foreach($products as $p): ?>
              <option value="<?= (int)$p["id"] ?>" data-cat="<?= isset($p["category_id"]) ? (int)$p["category_id"] : 0 ?>">
                <?= h($p["name"]) ?><?= isset($p["stock"]) ? " — Stock: ".h($p["stock"]) : "" ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="muted" style="font-size:12px; margin-top:6px;">Solo productos de esta sucursal.</div>
        </div>

        <div>
          <label class="muted" style="display:block; margin-bottom:6px;">Cantidad</label>
          <input type="number" name="qty" class="input" min="1" step="1" value="1" required>
        </div>

        <div style="display:flex; justify-content:flex-end;">
          <button class="btn-pill" type="submit">Añadir</button>
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
            <button class="btn-pill" type="submit">Vaciar</button>
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
          <?php if (empty($_SESSION["salida_cart"])): ?>
            <tr><td colspan="4" class="muted" style="text-align:center;">No hay productos agregados.</td></tr>
          <?php else: ?>
            <?php foreach ($_SESSION["salida_cart"] as $i => $line):
              $pid = (int)$line["item_id"];
              $qty = (int)$line["qty"];
              $pname = "";
              foreach ($products as $p) { if ((int)$p["id"] === $pid) { $pname = (string)$p["name"]; break; } }
            ?>
              <tr>
                <td><?= $i+1 ?></td>
                <td><?= h($pname) ?></td>
                <td style="text-align:right;"><?= (int)$qty ?></td>
                <td style="text-align:right;">
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="idx" value="<?= (int)$i ?>">
                    <button class="btn-pill" type="submit">Quitar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>

        <form method="post" style="margin-top:14px; display:grid; grid-template-columns:1fr 1fr; gap:14px; align-items:end;">
          <input type="hidden" name="action" value="save_print">

          <div>
            <label class="muted" style="display:block; margin-bottom:6px;">Fecha</label>
            <input type="date" name="fecha" class="input" value="<?= h($today) ?>" required>
          </div>

          <div>
            <label class="muted" style="display:block; margin-bottom:6px;">Destino</label>
            <input type="text" name="destino" class="input" placeholder="Ej: Consultorio, Brigada, Paciente">
          </div>

          <div style="grid-column:1 / span 2;">
            <label class="muted" style="display:block; margin-bottom:6px;">Nota (opcional)</label>
            <input type="text" name="nota" class="input" placeholder="Observación...">
          </div>

          <div style="grid-column:1 / span 2; display:flex; justify-content:flex-end;">
            <button class="btn-pill" type="submit">Guardar e Imprimir</button>
          </div>
        </form>
      </div>

      <!-- Historial -->
      <div class="card" style="margin-top:14px;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
          <div>
            <h3 style="margin:0 0 6px;">Historial de Salidas</h3>
            <p class="muted">Últimos 50 registros (sede actual)</p>
          </div>
          <button class="btn-pill" type="button" id="btnToggleHistory">Ver el historial</button>
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

<?php if ($printPayload): ?>
<script>
(function(){
  const data = <?= json_encode($printPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const w = window.open('', '_blank', 'width=420,height=650');
  if (!w) return;

  const esc = (s)=>String(s ?? '')
    .replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')
    .replaceAll('"','&quot;').replaceAll("'","&#039;");

  w.document.open();
  w.document.write(`
<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Acuse de Salida</title>
<style>
body{font-family:Arial,sans-serif;padding:14px}
.center{text-align:center}
.hr{border-top:1px dashed #999;margin:12px 0}
.row{display:flex;justify-content:space-between;gap:12px;margin:6px 0}
.label{color:#555}.val{font-weight:700}.small{font-size:12px;color:#666}
</style></head><body>
<div class="center">
  <h2 style="margin:0;">CEVIMEP</h2>
  <div class="small">Sucursal ID: ${esc(data.branch_id)}</div>
  <div class="hr"></div>
  <h3 style="margin:6px 0;">ACUSE DE SALIDA</h3>
  <div class="small">Movimiento #${esc(data.movement_id)}</div>
</div>
<div class="hr"></div>
<div class="row"><span class="label">Fecha:</span><span class="val">${esc(data.fecha)}</span></div>
<div class="row"><span class="label">Destino:</span><span class="val">${esc(data.destino || '-')}</span></div>
${data.nota ? `<div style="margin-top:10px;"><div class="label">Nota:</div><div class="val">${esc(data.nota)}</div></div>` : ``}
<div class="hr"></div>
<div class="small">Registrado por: ${esc(data.usuario)}</div>
<div class="small">Impreso: ${new Date().toLocaleString()}</div>
<script>window.onload=function(){window.print();setTimeout(()=>window.close(),700);}<\/script>
</body></html>`);
  w.document.close();
})();
</script>
<?php endif; ?>

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
