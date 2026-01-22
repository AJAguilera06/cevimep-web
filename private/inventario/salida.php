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

if (!isset($_SESSION["salida_items"]) || !is_array($_SESSION["salida_items"])) {
  $_SESSION["salida_items"] = [];
}

/* Helpers */
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

function get_categories(PDO $conn, int $branch_id): array {
  $db = (string)$conn->query("SELECT DATABASE()")->fetchColumn();
  $candidates = ["inventory_categories","categories","categorias"];
  $table = null;
  foreach ($candidates as $t) {
    $st = $conn->prepare("
      SELECT COUNT(*) FROM information_schema.tables
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

/* Productos SOLO de la sede con stock > 0 */
$st = $conn->prepare("
  SELECT i.id, i.name, s.quantity
  FROM inventory_items i
  INNER JOIN inventory_stock s ON s.item_id=i.id
  WHERE s.branch_id=? AND s.quantity>0
  ORDER BY i.name
");
$st->execute([$branch_id]);
$products = $st->fetchAll(PDO::FETCH_ASSOC);

/* Agregar item */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_item"])) {
  $category_id = (int)($_POST["category_id"] ?? 0);
  $item_id     = (int)($_POST["item_id"] ?? 0);
  $qty         = (int)($_POST["qty"] ?? 0);

  if ($item_id > 0 && $qty > 0) {
    $st = $conn->prepare("
      SELECT i.id, i.name, IFNULL(s.quantity,0) AS stock
      FROM inventory_items i
      INNER JOIN inventory_stock s ON s.item_id=i.id
      WHERE i.id=? AND s.branch_id=?
      LIMIT 1
    ");
    $st->execute([$item_id, $branch_id]);
    $it = $st->fetch(PDO::FETCH_ASSOC);

    if ($it) {
      $stock = (int)$it["stock"];
      $current = (int)($_SESSION["salida_items"][$item_id]["qty"] ?? 0);
      $newQty = $current + $qty;

      if ($newQty <= $stock) {
        $catName = "";
        foreach ($categories as $c) {
          if ((int)$c["id"] === $category_id) { $catName = (string)$c["name"]; break; }
        }
        $_SESSION["salida_items"][$item_id] = [
          "category_id" => $category_id,
          "category" => $catName,
          "name" => (string)$it["name"],
          "qty" => $newQty,
          "stock" => $stock,
        ];
      }
    }
  }
}

/* Eliminar item */
if (isset($_GET["remove"])) {
  unset($_SESSION["salida_items"][(int)$_GET["remove"]]);
}

/* Guardar salida + imprimir */
$print_receipt = false;
$receipt = null;
$receipt_items = [];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_exit"])) {
  if (!empty($_SESSION["salida_items"])) {

    $note = trim((string)($_POST["note"] ?? ""));
    $note_final = ($note === "") ? null : "Nota: {$note}";

    $movCols = table_columns($conn, "inventory_movements");
    $colType   = pick_col($movCols, ["type","movement_type","tipo","accion","action","movement"]);
    $colBranch = pick_col($movCols, ["branch_id","sucursal_id","branch","sucursal"]);
    $colNote   = pick_col($movCols, ["note","nota","descripcion","observacion","obs","comment"]);
    $colDate   = pick_col($movCols, ["created_at","created","fecha","date","created_on"]);

    if (!$colBranch) die("Config BD: inventory_movements no tiene columna branch_id/sucursal_id.");

    $itemCols = table_columns($conn, "inventory_movement_items");
    $colMovId = pick_col($itemCols, ["movement_id","inventory_movement_id","movimiento_id","entry_id","salida_id"]);
    $colItem  = pick_col($itemCols, ["item_id","inventory_item_id","product_id","producto_id"]);
    $colQty   = pick_col($itemCols, ["quantity","qty","cantidad"]);
    if (!$colMovId || !$colItem || !$colQty) die("Config BD: inventory_movement_items no tiene columnas requeridas.");

    $stockCols = table_columns($conn, "inventory_stock");
    $colSItem  = pick_col($stockCols, ["item_id","inventory_item_id","product_id","producto_id"]);
    $colSBranch= pick_col($stockCols, ["branch_id","sucursal_id","branch","sucursal"]);
    $colSQty   = pick_col($stockCols, ["quantity","qty","cantidad"]);
    if (!$colSItem || !$colSBranch || !$colSQty) die("Config BD: inventory_stock no tiene columnas requeridas.");

    $conn->beginTransaction();

    // lock stock
    $stCheck = $conn->prepare("
      SELECT {$colSQty}
      FROM inventory_stock
      WHERE {$colSItem}=? AND {$colSBranch}=?
      LIMIT 1 FOR UPDATE
    ");
    foreach ($_SESSION["salida_items"] as $item_id => $d) {
      $q = (int)($d["qty"] ?? 0);
      if ($q <= 0) continue;
      $stCheck->execute([(int)$item_id, $branch_id]);
      $cur = (int)($stCheck->fetchColumn() ?? 0);
      if ($cur < $q) {
        $conn->rollBack();
        header("Location: salida.php?err=stock");
        exit;
      }
    }

    // movement insert
    $fields = [];
    $values = [];
    $params = [];
    if ($colType) { $fields[] = $colType; $values[] = "?"; $params[] = "salida"; }
    $fields[] = $colBranch; $values[] = "?"; $params[] = $branch_id;
    if ($colNote) { $fields[] = $colNote; $values[] = "?"; $params[] = $note_final; }
    if ($colDate) { $fields[] = $colDate; $values[] = "NOW()"; }

    $sqlMov = "INSERT INTO inventory_movements (" . implode(",", $fields) . ") VALUES (" . implode(",", $values) . ")";
    $stMov = $conn->prepare($sqlMov);
    $stMov->execute($params);
    $movement_id = (int)$conn->lastInsertId();

    $sqlIt = "INSERT INTO inventory_movement_items ({$colMovId}, {$colItem}, {$colQty}) VALUES (?, ?, ?)";
    $stIt = $conn->prepare($sqlIt);

    $sqlStock = "
      UPDATE inventory_stock
      SET {$colSQty} = {$colSQty} - ?
      WHERE {$colSItem}=? AND {$colSBranch}=?
    ";
    $stStock = $conn->prepare($sqlStock);

    foreach ($_SESSION["salida_items"] as $item_id => $d) {
      $q = (int)($d["qty"] ?? 0);
      if ($q <= 0) continue;
      $stIt->execute([$movement_id, (int)$item_id, $q]);
      $stStock->execute([$q, (int)$item_id, $branch_id]);
    }

    $conn->commit();

    $receipt = [
      "movement_id" => $movement_id,
      "branch" => $branch_name,
      "date" => date("d/m/Y H:i"),
      "note" => $note,
    ];
    foreach ($_SESSION["salida_items"] as $d) {
      $receipt_items[] = [
        "category" => (string)($d["category"] ?? ""),
        "product"  => (string)($d["name"] ?? ""),
        "qty"      => (int)($d["qty"] ?? 0),
      ];
    }

    $_SESSION["salida_items"] = [];
    $print_receipt = true;
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
  <style>
    .content .wrap { max-width: 1180px; margin: 0 auto; }
    .page-card { padding: 18px 18px 14px; }
    .grid-2 { display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
    .row-4 { display:grid; grid-template-columns: 220px 1fr 140px 110px; gap:14px; align-items:end; }
    .hint { font-size:12px; opacity:.7; margin-top:6px; }
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

<div class="navbar">
  <div class="inner">
    <div class="brand"><span class="dot"></span><strong>CEVIMEP</strong></div>
    <div class="nav-right"><a class="btn-pill" href="/logout.php">Salir</a></div>
  </div>
</div>

<div class="layout">
  <aside class="sidebar">
    <h3 class="menu-title">Menú</h3>
    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a class="active" href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/inventario/entrada.php" style="margin-left:10px;"><span class="ico">➕</span> Entrada</a>
      <a class="active" href="/private/inventario/salida.php" style="margin-left:10px;"><span class="ico">➖</span> Salida</a>
    </nav>
  </aside>

  <main class="content">
    <div class="wrap">

      <?php if (isset($_GET["err"]) && $_GET["err"] === "stock"): ?>
        <div class="card" style="border-left:4px solid #d9534f;">
          <strong>Stock insuficiente</strong>
          <div class="muted">Revisa las cantidades e intenta de nuevo.</div>
        </div>
      <?php endif; ?>

      <div class="card page-card">
        <h3 style="margin:0;">Salida</h3>
        <div class="muted" style="margin-top:4px;">Registra salida de inventario (sede actual)</div>

        <form method="post" style="margin-top:14px;">
          <div class="grid-2">
            <div>
              <label>Fecha</label>
              <input type="date" value="<?= htmlspecialchars($today) ?>" disabled>
            </div>
            <div>
              <label>Nota (opcional)</label>
              <input type="text" name="note" placeholder="Observación...">
            </div>
          </div>

          <div style="height:12px;"></div>

          <div class="row-4">
            <div>
              <label>Categoría</label>
              <select name="category_id">
                <option value="">Todas ...</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c["id"] ?>"><?= htmlspecialchars((string)$c["name"]) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label>Producto</label>
              <select name="item_id" required>
                <option value="">-- Seleccionar --</option>
                <?php foreach ($products as $p): ?>
                  <option value="<?= (int)$p["id"] ?>">
                    <?= htmlspecialchars((string)$p["name"]) ?> (Stock: <?= (int)$p["quantity"] ?>)
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
              <?php if (empty($_SESSION["salida_items"])): ?>
                <tr><td colspan="4" class="muted">No hay productos agregados.</td></tr>
              <?php else: foreach ($_SESSION["salida_items"] as $id => $it): ?>
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
          <button class="btn primary" type="submit" name="save_exit">Guardar e Imprimir</button>
        </form>
      </div>

      <div class="card" style="margin-top:14px;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
          <div>
            <strong>Historial de Salidas</strong>
            <div class="muted" style="font-size:12px;">Últimos 50 registros (sede actual)</div>
          </div>
          <a class="btn" href="/private/inventario/historial_salidas.php">Ver el historial</a>
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
  <h2 style="margin:0 0 6px 0;">CEVIMEP - Salida de Inventario</h2>
  <div><strong>Sucursal:</strong> <?= htmlspecialchars($receipt["branch"]) ?></div>
  <div><strong>Fecha:</strong> <?= htmlspecialchars($receipt["date"]) ?></div>
  <div><strong>Movimiento #:</strong> <?= (int)$receipt["movement_id"] ?></div>
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

</body>
</html>
