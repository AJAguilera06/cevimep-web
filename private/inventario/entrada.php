<?php
declare(strict_types=1);
require_once __DIR__ . "/../_guard.php";

$conn = $pdo;

$user = $_SESSION["user"];
$branch_id = (int)($user["branch_id"] ?? 0);

$branch_name = "CEVIMEP";
if ($branch_id > 0) {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $bn = $stB->fetchColumn();
  if ($bn) $branch_name = (string)$bn;
}

$today = date("Y-m-d");

if (!isset($_SESSION["entrada_items"]) || !is_array($_SESSION["entrada_items"])) {
  $_SESSION["entrada_items"] = [];
}

/* =========================
   Helpers BD (no romper por columnas)
========================= */
function table_columns(PDO $conn, string $table): array {
  $db = (string)$conn->query("SELECT DATABASE()")->fetchColumn();
  $st = $conn->prepare("
    SELECT COLUMN_NAME
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=? AND TABLE_NAME=?
  ");
  $st->execute([$db, $table]);
  return array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
}
function pick_col(array $cols, array $candidates): ?string {
  foreach ($candidates as $c) {
    if (in_array(strtolower($c), $cols, true)) return $c;
  }
  return null;
}

/* =========================
   Categorías (autodetect)
========================= */
function get_categories(PDO $conn, int $branch_id): array {
  $db = (string)$conn->query("SELECT DATABASE()")->fetchColumn();
  $candidates = ["inventory_categories","categories","categorias"];
  $table = null;

  foreach ($candidates as $t) {
    $st = $conn->prepare("
      SELECT COUNT(*)
      FROM information_schema.tables
      WHERE table_schema=? AND table_name=?
    ");
    $st->execute([$db, $t]);
    if ((int)$st->fetchColumn() > 0) { $table = $t; break; }
  }
  if (!$table) return [];

  $cols = table_columns($conn, $table);
  $idCol = pick_col($cols, ["id"]);
  $nameCol = pick_col($cols, ["name","nombre","categoria","category","title"]);
  if (!$idCol || !$nameCol) return [];

  $branchCol = pick_col($cols, ["branch_id","sucursal_id","branch","sucursal"]);

  if ($branchCol) {
    $sql = "SELECT {$idCol} AS id, {$nameCol} AS name FROM {$table} WHERE {$branchCol}=? ORDER BY {$nameCol}";
    $st = $conn->prepare($sql);
    $st->execute([$branch_id]);
  } else {
    $sql = "SELECT {$idCol} AS id, {$nameCol} AS name FROM {$table} ORDER BY {$nameCol}";
    $st = $conn->prepare($sql);
    $st->execute();
  }

  return $st->fetchAll(PDO::FETCH_ASSOC);
}
$categories = get_categories($conn, $branch_id);

/* =========================
   Productos SOLO de la sede + categoria_id (si existe)
========================= */
$itemCols = table_columns($conn, "inventory_items");
$itemCatCol = pick_col($itemCols, ["category_id","inventory_category_id","categoria_id","cat_id"]);

$selectCat = $itemCatCol ? "i.{$itemCatCol} AS category_id" : "0 AS category_id";

$st = $conn->prepare("
  SELECT i.id, i.name, {$selectCat}
  FROM inventory_items i
  INNER JOIN inventory_stock s ON s.item_id=i.id
  WHERE s.branch_id=?
  GROUP BY i.id, i.name " . ($itemCatCol ? ", i.{$itemCatCol}" : "") . "
  ORDER BY i.name
");
$st->execute([$branch_id]);
$products = $st->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   Agregar item
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_item"])) {
  $category_id = (int)($_POST["category_id"] ?? 0);
  $item_id     = (int)($_POST["item_id"] ?? 0);
  $qty         = (int)($_POST["qty"] ?? 0);

  if ($item_id > 0 && $qty > 0) {

    $st = $conn->prepare("
      SELECT i.id, i.name
      FROM inventory_items i
      INNER JOIN inventory_stock s ON s.item_id=i.id
      WHERE i.id=? AND s.branch_id=?
      LIMIT 1
    ");
    $st->execute([$item_id, $branch_id]);
    $it = $st->fetch(PDO::FETCH_ASSOC);

    if ($it) {
      $catName = "";
      foreach ($categories as $c) {
        if ((int)$c["id"] === $category_id) { $catName = (string)$c["name"]; break; }
      }

      if (isset($_SESSION["entrada_items"][$item_id])) {
        $_SESSION["entrada_items"][$item_id]["qty"] += $qty;
      } else {
        $_SESSION["entrada_items"][$item_id] = [
          "category_id" => $category_id,
          "category"    => $catName,
          "name"        => (string)$it["name"],
          "qty"         => $qty
        ];
      }
    }
  }
}

/* =========================
   Eliminar item
========================= */
if (isset($_GET["remove"])) {
  unset($_SESSION["entrada_items"][(int)$_GET["remove"]]);
}

/* =========================
   Guardar entrada + imprimir (sin depender de type)
========================= */
$print_receipt = false;
$receipt = null;
$receipt_items = [];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_entry"])) {

  if (!empty($_SESSION["entrada_items"])) {

    $supplier = trim((string)($_POST["supplier"] ?? ""));
    $note     = trim((string)($_POST["note"] ?? ""));

    $note_final = "";
    if ($supplier !== "") $note_final .= "Suplidor: {$supplier}";
    if ($note !== "") $note_final .= ($note_final ? " | " : "") . "Nota: {$note}";
    if ($note_final === "") $note_final = null;

    $movCols = table_columns($conn, "inventory_movements");
    $colType   = pick_col($movCols, ["type","movement_type","tipo","accion","action","movement"]);
    $colBranch = pick_col($movCols, ["branch_id","sucursal_id","branch","sucursal"]);
    $colNote   = pick_col($movCols, ["note","nota","descripcion","observacion","obs","comment"]);
    $colDate   = pick_col($movCols, ["created_at","created","fecha","date","created_on"]);

    if (!$colBranch) {
      die("Config BD: inventory_movements no tiene columna branch_id/sucursal_id.");
    }

    $fields = [];
    $values = [];
    $params = [];

    if ($colType) { $fields[] = $colType; $values[] = "?"; $params[] = "entrada"; }
    $fields[] = $colBranch; $values[] = "?"; $params[] = $branch_id;
    if ($colNote) { $fields[] = $colNote; $values[] = "?"; $params[] = $note_final; }
    if ($colDate) { $fields[] = $colDate; $values[] = "NOW()"; }

    $sqlMov = "INSERT INTO inventory_movements (" . implode(",", $fields) . ") VALUES (" . implode(",", $values) . ")";

    $conn->beginTransaction();

    $stMov = $conn->prepare($sqlMov);
    $stMov->execute($params);
    $movement_id = (int)$conn->lastInsertId();

    $itemCols2 = table_columns($conn, "inventory_movement_items");
    $colMovId = pick_col($itemCols2, ["movement_id","inventory_movement_id","movimiento_id","entry_id","entrada_id"]);
    $colItem  = pick_col($itemCols2, ["item_id","inventory_item_id","product_id","producto_id"]);
    $colQty   = pick_col($itemCols2, ["quantity","qty","cantidad"]);
    if (!$colMovId || !$colItem || !$colQty) {
      $conn->rollBack();
      die("Config BD: inventory_movement_items no tiene columnas requeridas.");
    }

    $sqlIt = "INSERT INTO inventory_movement_items ({$colMovId}, {$colItem}, {$colQty}) VALUES (?, ?, ?)";
    $stIt = $conn->prepare($sqlIt);

    $stockCols = table_columns($conn, "inventory_stock");
    $colSItem  = pick_col($stockCols, ["item_id","inventory_item_id","product_id","producto_id"]);
    $colSBranch= pick_col($stockCols, ["branch_id","sucursal_id","branch","sucursal"]);
    $colSQty   = pick_col($stockCols, ["quantity","qty","cantidad"]);
    if (!$colSItem || !$colSBranch || !$colSQty) {
      $conn->rollBack();
      die("Config BD: inventory_stock no tiene columnas requeridas.");
    }

    $sqlStock = "
      INSERT INTO inventory_stock ({$colSItem}, {$colSBranch}, {$colSQty})
      VALUES (?, ?, ?)
      ON DUPLICATE KEY UPDATE {$colSQty} = {$colSQty} + VALUES({$colSQty})
    ";
    $stStock = $conn->prepare($sqlStock);

    foreach ($_SESSION["entrada_items"] as $item_id => $d) {
      $q = (int)($d["qty"] ?? 0);
      if ($q <= 0) continue;

      $stIt->execute([$movement_id, (int)$item_id, $q]);
      $stStock->execute([(int)$item_id, $branch_id, $q]);
    }

    $conn->commit();

    $receipt = [
      "movement_id" => $movement_id,
      "branch" => $branch_name,
      "date" => date("d/m/Y H:i"),
      "supplier" => $supplier,
      "note" => $note
    ];

    foreach ($_SESSION["entrada_items"] as $d) {
      $receipt_items[] = [
        "category" => (string)($d["category"] ?? ""),
        "product"  => (string)($d["name"] ?? ""),
        "qty"      => (int)($d["qty"] ?? 0),
      ];
    }

    $_SESSION["entrada_items"] = [];
    $print_receipt = true;
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Entrada</title>

  <!-- CSS ABSOLUTO (Railway + /private) -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=30">

  <style>
    /* Layout como tu ejemplo: ancho, ordenado, y fila de producto completa */
    .content .wrap { max-width: 1180px; margin: 0 auto; }

    .form-grid-2{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      margin-top: 14px;
    }

    .row-4{
      display:grid;
      grid-template-columns: 220px 1fr 140px 120px;
      gap: 14px;
      align-items:end;
      margin-top: 12px;
    }

    .hint { font-size: 12px; opacity: .7; margin-top: 6px; }
    .btn-right { display:flex; justify-content:flex-end; margin-top: 12px; }

    .receipt{display:none;}
    @media print{
      body *{visibility:hidden;}
      .receipt,.receipt *{visibility:visible;}
      .receipt{display:block;position:absolute;left:0;top:0;width:100%;padding:16px;background:#fff;}
    }
  </style>
</head>
<body>

<!-- NAVBAR (igual dashboard) -->
<div class="navbar">
  <div class="inner">
    <div class="brand"><span class="dot"></span><strong>CEVIMEP</strong></div>
    <div class="nav-right"><a class="btn-pill" href="/logout.php">Salir</a></div>
  </div>
</div>

<div class="layout">

  <!-- SIDEBAR igual dashboard + submenú inventario -->
  <aside class="sidebar">
    <h3 class="menu-title">Menú</h3>

    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👤</span> Pacientes</a>
      <a href="/private/citas/index.php"><span class="ico">📅</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💳</span> Caja</a>

      <a class="active" href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a class="active" href="/private/inventario/entrada.php" style="margin-left:14px;"><span class="ico">➕</span> Entrada</a>
      <a href="/private/inventario/salida.php" style="margin-left:14px;"><span class="ico">➖</span> Salida</a>

      <a href="/private/estadisticas/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <div class="wrap">

      <div class="card">
        <h3 style="margin:0;">Entrada</h3>
        <div class="muted" style="margin-top:4px;">Registra entrada de inventario (sede actual)</div>

        <form method="post" id="frmEntrada">

          <div class="form-grid-2">
            <div>
              <label>Fecha</label>
              <input type="date" value="<?= htmlspecialchars($today) ?>" disabled>
            </div>
            <div>
              <label>Suplidor</label>
              <input type="text" name="supplier" placeholder="Ej: Almacén, Ministerio, Farmacia, etc.">
            </div>

            <div>
              <label>Área de destino</label>
              <input type="text" value="<?= htmlspecialchars($branch_name) ?>" disabled>
            </div>
            <div>
              <label>Nota (opcional)</label>
              <input type="text" name="note" placeholder="Observación...">
            </div>
          </div>

          <div class="row-4">
            <div>
              <label>Categoría</label>
              <select name="category_id" id="categorySelect">
                <option value="">Todas ...</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c["id"] ?>"><?= htmlspecialchars((string)$c["name"]) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label>Producto</label>
              <select name="item_id" id="productSelect" required>
                <option value="">-- Seleccionar --</option>
                <?php foreach ($products as $p): ?>
                  <option
                    value="<?= (int)$p["id"] ?>"
                    data-cat="<?= (int)($p["category_id"] ?? 0) ?>"
                  >
                    <?= htmlspecialchars((string)$p["name"]) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label>Cantidad</label>
              <input type="number" name="qty" min="1" value="1" required>
            </div>

            <div>
              <button class="btn primary" type="submit" name="add_item">Añadir</button>
            </div>
          </div>

          <div class="hint">Al añadir, se mantiene seleccionado el producto y la cantidad.</div>
        </form>

        <div style="margin-top:14px;">
          <table class="table">
            <thead>
              <tr>
                <th>Categoría</th>
                <th>Producto</th>
                <th style="width:140px;">Cantidad</th>
                <th style="width:140px;">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($_SESSION["entrada_items"])): ?>
                <tr><td colspan="4" class="muted">No hay productos agregados.</td></tr>
              <?php else: foreach ($_SESSION["entrada_items"] as $id => $it): ?>
                <tr>
                  <td><?= htmlspecialchars((string)($it["category"] ?? "")) ?></td>
                  <td><?= htmlspecialchars((string)($it["name"] ?? "")) ?></td>
                  <td><?= (int)($it["qty"] ?? 0) ?></td>
                  <td><a class="btn" href="?remove=<?= (int)$id ?>">Eliminar</a></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <form method="post" class="btn-right">
          <button class="btn primary" type="submit" name="save_entry">Guardar e Imprimir</button>
        </form>
      </div>

      <div class="card" style="margin-top:14px;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
          <div>
            <strong>Historial de Entradas</strong>
            <div class="muted" style="font-size:12px;">Últimos 50 registros (sede actual)</div>
          </div>
          <a class="btn" href="/private/inventario/historial_entradas.php">Ver el historial</a>
        </div>
      </div>

    </div>
  </main>
</div>

<div class="footer">
  <div class="inner">
    © <?= (int)date("Y") ?> CEVIMEP. Todos los derechos reservados.
  </div>
</div>

<?php if ($print_receipt && $receipt): ?>
<div class="receipt">
  <h2 style="margin:0 0 6px 0;">CEVIMEP - Entrada de Inventario</h2>
  <div><strong>Sucursal:</strong> <?= htmlspecialchars($receipt["branch"]) ?></div>
  <div><strong>Fecha:</strong> <?= htmlspecialchars($receipt["date"]) ?></div>
  <div><strong>Movimiento #:</strong> <?= (int)$receipt["movement_id"] ?></div>
  <?php if ($receipt["supplier"] !== ""): ?><div><strong>Suplidor:</strong> <?= htmlspecialchars($receipt["supplier"]) ?></div><?php endif; ?>
  <?php if ($receipt["note"] !== ""): ?><div><strong>Nota:</strong> <?= htmlspecialchars($receipt["note"]) ?></div><?php endif; ?>
  <hr>
  <table style="width:100%; border-collapse:collapse;">
    <thead>
      <tr>
        <th style="text-align:left; border-bottom:1px solid #ccc; padding:6px;">Categoría</th>
        <th style="text-align:left; border-bottom:1px solid #ccc; padding:6px;">Producto</th>
        <th style="text-align:right; border-bottom:1px solid #ccc; padding:6px;">Cant.</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($receipt_items as $ri): ?>
        <tr>
          <td style="padding:6px; border-bottom:1px solid #eee;"><?= htmlspecialchars($ri["category"]) ?></td>
          <td style="padding:6px; border-bottom:1px solid #eee;"><?= htmlspecialchars($ri["product"]) ?></td>
          <td style="padding:6px; border-bottom:1px solid #eee; text-align:right;"><?= (int)$ri["qty"] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p style="margin-top:10px; font-size:12px; opacity:.75;">Generado por CEVIMEP</p>
</div>
<script>
  window.addEventListener("load", function(){ window.print(); });
</script>
<?php endif; ?>

<script>
/* ==========================================
   FILTRO: Categoría -> Productos
   (si inventory_items no tiene category_id, data-cat=0 y no filtra)
========================================== */
(function(){
  const cat = document.getElementById("categorySelect");
  const prod = document.getElementById("productSelect");
  if(!cat || !prod) return;

  const all = Array.from(prod.options).map(o => ({
    value: o.value,
    text: o.text,
    cat: (o.dataset && o.dataset.cat) ? o.dataset.cat : ""
  }));

  function rebuild(){
    const selectedCat = cat.value || "";
    const currentValue = prod.value;

    prod.innerHTML = "";
    const def = document.createElement("option");
    def.value = "";
    def.textContent = "-- Seleccionar --";
    prod.appendChild(def);

    all.forEach(opt => {
      if(opt.value === "") return; // ya tenemos el default
      if(selectedCat === "" || opt.cat === "" || opt.cat === "0" || opt.cat === selectedCat){
        const o = document.createElement("option");
        o.value = opt.value;
        o.textContent = opt.text;
        o.dataset.cat = opt.cat;
        prod.appendChild(o);
      }
    });

    // intenta mantener selección si aún existe
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
