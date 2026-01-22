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

/* =========================
   CATEGORÍAS (SELECT)
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

  $stCols = $conn->prepare("
    SELECT column_name
    FROM information_schema.columns
    WHERE table_schema=? AND table_name=?
  ");
  $stCols->execute([$db, $table]);
  $cols = array_map('strtolower', $stCols->fetchAll(PDO::FETCH_COLUMN));

  $idCol = in_array("id", $cols, true) ? "id" : null;
  $nameCol = null;
  foreach (["name","nombre","category","categoria","title"] as $c) {
    if (in_array($c, $cols, true)) { $nameCol = $c; break; }
  }
  if (!$idCol || !$nameCol) return [];

  $branchCol = null;
  foreach (["branch_id","sucursal_id","branch","sucursal"] as $c) {
    if (in_array($c, $cols, true)) { $branchCol = $c; break; }
  }

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
   SESIÓN TEMPORAL
   ========================= */
if (!isset($_SESSION["entrada_items"]) || !is_array($_SESSION["entrada_items"])) {
  $_SESSION["entrada_items"] = [];
}

/* =========================
   AGREGAR ITEM
   ========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_item"])) {
  $category_id = (int)($_POST["category_id"] ?? 0);
  $item_id     = (int)($_POST["item_id"] ?? 0);
  $qty         = (int)($_POST["qty"] ?? 0);

  if ($item_id > 0 && $qty > 0) {
    // validar que el producto está registrado en la sede (vía inventory_stock)
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
          "category" => $catName,
          "name" => (string)$it["name"],
          "qty" => $qty
        ];
      }
    }
  }
}

/* =========================
   ELIMINAR ITEM
   ========================= */
if (isset($_GET["remove"])) {
  unset($_SESSION["entrada_items"][(int)$_GET["remove"]]);
}

/* =========================
   GUARDAR ENTRADA
   ========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_entry"])) {
  if (!empty($_SESSION["entrada_items"])) {

    $supplier = trim((string)($_POST["supplier"] ?? ""));
    $note     = trim((string)($_POST["note"] ?? ""));

    $note_final = "";
    if ($supplier !== "") $note_final .= "Suplidor: {$supplier}";
    if ($note !== "") $note_final .= ($note_final ? " | " : "") . "Nota: {$note}";
    if ($note_final === "") $note_final = null;

    $conn->beginTransaction();

    $stMov = $conn->prepare("
      INSERT INTO inventory_movements (type, branch_id, note, created_at)
      VALUES ('entrada', ?, ?, NOW())
    ");
    $stMov->execute([$branch_id, $note_final]);
    $movement_id = (int)$conn->lastInsertId();

    $stItem = $conn->prepare("
      INSERT INTO inventory_movement_items (movement_id, item_id, quantity)
      VALUES (?, ?, ?)
    ");

    $stStock = $conn->prepare("
      INSERT INTO inventory_stock (item_id, branch_id, quantity)
      VALUES (?, ?, ?)
      ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
    ");

    foreach ($_SESSION["entrada_items"] as $item_id => $d) {
      $q = (int)($d["qty"] ?? 0);
      if ($q <= 0) continue;
      $stItem->execute([$movement_id, (int)$item_id, $q]);
      $stStock->execute([(int)$item_id, $branch_id, $q]);
    }

    $conn->commit();
    $_SESSION["entrada_items"] = [];
    header("Location: entrada.php?ok=1");
    exit;
  }
}

/* =========================
   PRODUCTOS DE LA SEDE (aunque stock=0)
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

<!-- TOPBAR -->
<div class="topbar">
  <div class="inner">
    <div class="brand">
      <span class="dot"></span>
      <span class="brand-name">CEVIMEP</span>
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

  <!-- MAIN -->
  <main class="main">

    <div class="page-head">
      <div>
        <h1 class="page-title">Entrada</h1>
        <p class="muted">Registra entrada de inventario (sede actual)</p>
      </div>
      <div class="actions">
        <a class="btn" href="/private/inventario/productos.php">Productos</a>
        <a class="btn" href="/private/inventario/index.php">Volver</a>
      </div>
    </div>

    <?php if (isset($_GET["ok"])): ?>
      <div class="alert success">Entrada guardada correctamente.</div>
    <?php endif; ?>

    <section class="card">
      <div class="card-head">
        <h3>Entrada</h3>
        <p class="muted">Sucursal: <strong><?= htmlspecialchars($branch_name) ?></strong></p>
      </div>

      <form method="post">
        <div class="form-grid two">
          <div class="field">
            <label>Fecha</label>
            <input type="date" value="<?= $today ?>" disabled>
          </div>
          <div class="field">
            <label>Suplidor</label>
            <input type="text" name="supplier" placeholder="Escriba el suplidor...">
          </div>

          <div class="field">
            <label>Área de destino</label>
            <input type="text" value="<?= htmlspecialchars($branch_name) ?>" disabled>
          </div>
          <div class="field">
            <label>Nota (opcional)</label>
            <input type="text" name="note" placeholder="Observación...">
          </div>
        </div>

        <div class="hr"></div>

        <div class="form-grid four">
          <div class="field">
            <label>Categoría</label>
            <select name="category_id" required>
              <option value="">Seleccione</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c["id"] ?>"><?= htmlspecialchars((string)$c["name"]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>Producto</label>
            <select name="item_id" required>
              <option value="">Seleccione</option>
              <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p["id"] ?>"><?= htmlspecialchars((string)$p["name"]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>Cantidad</label>
            <input type="number" name="qty" min="1" value="1" required>
          </div>

          <div class="field field-btn">
            <label>&nbsp;</label>
            <button class="btn primary" type="submit" name="add_item">Añadir</button>
          </div>
        </div>
      </form>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Categoría</th>
              <th>Producto</th>
              <th style="width:120px">Cantidad</th>
              <th style="width:120px">Acción</th>
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
                <td><a class="link danger" href="?remove=<?= (int)$id ?>">Eliminar</a></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <form method="post" class="form-actions">
        <button class="btn primary" type="submit" name="save_entry">Guardar e imprimir</button>
      </form>
    </section>

  </main>
</div>

<!-- FOOTER -->
<div class="footer">
  <div class="inner">
    © <?= (int)date("Y") ?> CEVIMEP. Todos los derechos reservados.
  </div>
</div>

</body>
</html>
