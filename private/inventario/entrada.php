<?php
declare(strict_types=1);
require_once __DIR__ . "/../_guard.php";

$conn = $pdo;

$user = $_SESSION["user"];
$branch_id = (int)($user["branch_id"] ?? 0);

$branch_name = "";
if ($branch_id > 0) {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $branch_name = (string)($stB->fetchColumn() ?? "");
}
if ($branch_name === "") $branch_name = "CEVIMEP";

$today = date("Y-m-d");

if (!isset($_SESSION["entrada_items"]) || !is_array($_SESSION["entrada_items"])) {
  $_SESSION["entrada_items"] = [];
}

/* =========================================================
   Helpers: detectar columnas reales (para no romper por BD)
   ========================================================= */
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
   Productos SOLO de la sede
========================= */
$st = $conn->prepare("
  SELECT i.id, i.name
  FROM inventory_items i
  INNER JOIN inventory_stock s ON s.item_id=i.id
  WHERE s.branch_id=?
  GROUP BY i.id, i.name
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
    // validar producto en esta sede
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

/* =========================================================
   Guardar entrada (SIN depender de columna 'type')
   + Generar recibo y print
   ========================================================= */
$print_receipt = false;
$receipt = null;
$receipt_items = [];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_entry"])) {

  if (!empty($_SESSION["entrada_items"])) {

    $supplier = trim((string)($_POST["supplier"] ?? ""));
    $note     = trim((string)($_POST["note"] ?? ""));

    // note final (para que no te falte info)
    $note_final = "";
    if ($supplier !== "") $note_final .= "Suplidor: {$supplier}";
    if ($note !== "") $note_final .= ($note_final ? " | " : "") . "Nota: {$note}";
    if ($note_final === "") $note_final = null;

    // detectar columnas reales de inventory_movements
    $movCols = table_columns($conn, "inventory_movements");
    $colType   = pick_col($movCols, ["type","movement_type","tipo","accion","action","movement"]);
    $colBranch = pick_col($movCols, ["branch_id","sucursal_id","branch","sucursal"]);
    $colNote   = pick_col($movCols, ["note","nota","descripcion","observacion","obs","comment"]);
    $colDate   = pick_col($movCols, ["created_at","created","fecha","date","created_on"]);

    if (!$colBranch) {
      // sin branch no se puede controlar sede
      die("Config BD: inventory_movements no tiene columna branch_id/sucursal_id.");
    }

    // construir INSERT dinámico
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

    // items: detectar columnas reales
    $itemCols = table_columns($conn, "inventory_movement_items");
    $colMovId = pick_col($itemCols, ["movement_id","inventory_movement_id","movimiento_id","entry_id","entrada_id"]);
    $colItem  = pick_col($itemCols, ["item_id","inventory_item_id","product_id","producto_id"]);
    $colQty   = pick_col($itemCols, ["quantity","qty","cantidad"]);

    if (!$colMovId || !$colItem || !$colQty) {
      $conn->rollBack();
      die("Config BD: inventory_movement_items no tiene columnas requeridas (movement_id/item_id/quantity).");
    }

    $sqlIt = "INSERT INTO inventory_movement_items ({$colMovId}, {$colItem}, {$colQty}) VALUES (?, ?, ?)";
    $stIt = $conn->prepare($sqlIt);

    // stock: detectar columnas reales
    $stockCols = table_columns($conn, "inventory_stock");
    $colSItem  = pick_col($stockCols, ["item_id","inventory_item_id","product_id","producto_id"]);
    $colSBranch= pick_col($stockCols, ["branch_id","sucursal_id","branch","sucursal"]);
    $colSQty   = pick_col($stockCols, ["quantity","qty","cantidad"]);

    if (!$colSItem || !$colSBranch || !$colSQty) {
      $conn->rollBack();
      die("Config BD: inventory_stock no tiene columnas requeridas (item_id/branch_id/quantity).");
    }

    // upsert (si tu tabla NO tiene unique, esto igual funciona si existe la key)
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

    // construir recibo para imprimir (sin archivos extra)
    $receipt = [
      "movement_id" => $movement_id,
      "branch" => $branch_name,
      "date" => date("d/m/Y H:i"),
      "supplier" => $supplier,
      "note" => $note
    ];

    $receipt_items = [];
    foreach ($_SESSION["entrada_items"] as $item_id => $d) {
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
  <link rel="stylesheet" href="/assets/css/styles.css?v=30">

  <style>
    /* Para que el contenido quede más centrado como tu estándar */
    .content .wrap {
      max-width: 980px;
      margin: 0 auto;
    }
    /* Recibo impresión */
    .receipt {
      display: none;
    }
    @media print {
      body * { visibility: hidden; }
      .receipt, .receipt * { visibility: visible; }
      .receipt {
        display: block;
        position: absolute;
        left: 0; top: 0;
        width: 100%;
        padding: 16px;
        background: #fff;
      }
    }
  </style>
</head>

<body>

<!-- NAVBAR (igual dashboard.php) -->
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
      <a href="/private/patients/index.php"><span class="ico">🧑‍⚕️</span> Pacientes</a>
      <a href="/private/citas/index.php"><span class="ico">🗓️</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>
      <a class="active" href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadisticas/index.php"><span class="ico">📊</span> Estadística</a>
    </nav>
  </aside>

  <!-- CONTENT -->
  <main class="content">
    <div class="wrap">

      <section class="hero">
        <h1>Entrada</h1>
        <p class="muted">Registra entrada de inventario (sede actual)</p>
        <div style="margin-top:10px; display:flex; gap:10px;">
          <a class="btn" href="/private/inventario/productos.php">Productos</a>
          <a class="btn" href="/private/inventario/index.php">Volver</a>
        </div>
      </section>

      <div class="card">
        <h3>Entrada</h3>
        <p class="muted">Sucursal: <strong><?= htmlspecialchars($branch_name) ?></strong></p>

        <form method="post">

          <div class="grid-top" style="margin-top:14px;">
            <div>
              <label>Fecha</label>
              <input type="text" value="<?= date("d/m/Y") ?>" disabled>
            </div>
            <div>
              <label>Suplidor</label>
              <input type="text" name="supplier" placeholder="Escriba el suplidor...">
            </div>
          </div>

          <div class="grid-top" style="margin-top:10px;">
            <div>
              <label>Área de destino</label>
              <input type="text" value="<?= htmlspecialchars($branch_name) ?>" disabled>
            </div>
            <div>
              <label>Nota (opcional)</label>
              <input type="text" name="note" placeholder="Observación...">
            </div>
          </div>

          <hr style="opacity:.25; margin:14px 0;">

          <div class="grid-top" style="gap:12px;">
            <div>
              <label>Categoría</label>
              <select name="category_id" required>
                <option value="">Seleccione</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c["id"] ?>"><?= htmlspecialchars((string)$c["name"]) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div style="flex:1;">
              <label>Producto</label>
              <select name="item_id" required>
                <option value="">Seleccione</option>
                <?php foreach ($products as $p): ?>
                  <option value="<?= (int)$p["id"] ?>"><?= htmlspecialchars((string)$p["name"]) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div style="width:140px;">
              <label>Cantidad</label>
              <input type="number" name="qty" min="1" value="1" required>
            </div>

            <div style="align-self:flex-end;">
              <button class="btn primary" type="submit" name="add_item">Añadir</button>
            </div>
          </div>

        </form>

        <div style="margin-top:16px;">
          <table class="table">
            <thead>
              <tr>
                <th>Categoría</th>
                <th>Producto</th>
                <th style="width:110px;">Cantidad</th>
                <th style="width:110px;">Acción</th>
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

        <form method="post" style="margin-top:14px;">
          <button class="btn primary" type="submit" name="save_entry">Guardar e imprimir</button>
        </form>

      </div>

    </div>
  </main>
</div>

<div class="footer">
  <div class="inner">
    © <?= (int)date("Y") ?> CEVIMEP. Todos los derechos reservados.
  </div>
</div>

<!-- RECIBO PARA IMPRESIÓN -->
<?php if ($print_receipt && $receipt): ?>
  <div class="receipt">
    <h2 style="margin:0 0 6px 0;">CEVIMEP - Entrada de Inventario</h2>
    <div><strong>Sucursal:</strong> <?= htmlspecialchars($receipt["branch"]) ?></div>
    <div><strong>Fecha:</strong> <?= htmlspecialchars($receipt["date"]) ?></div>
    <div><strong>Movimiento #:</strong> <?= (int)$receipt["movement_id"] ?></div>
    <?php if ($receipt["supplier"] !== ""): ?>
      <div><strong>Suplidor:</strong> <?= htmlspecialchars($receipt["supplier"]) ?></div>
    <?php endif; ?>
    <?php if ($receipt["note"] !== ""): ?>
      <div><strong>Nota:</strong> <?= htmlspecialchars($receipt["note"]) ?></div>
    <?php endif; ?>

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

    <p style="margin-top:10px; font-size:12px; opacity:.75;">
      Generado por CEVIMEP
    </p>
  </div>

  <script>
    // auto imprimir
    window.addEventListener("load", function () {
      window.print();
    });
  </script>
<?php endif; ?>

</body>
</html>
