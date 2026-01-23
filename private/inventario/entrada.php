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
   Helpers BD (robustos)
========================= */
function db_name(PDO $conn): string {
  return (string)$conn->query("SELECT DATABASE()")->fetchColumn();
}
function table_exists(PDO $conn, string $table): bool {
  $db = db_name($conn);
  $st = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name=?");
  $st->execute([$db, $table]);
  return ((int)$st->fetchColumn() > 0);
}
function table_columns(PDO $conn, string $table): array {
  $db = db_name($conn);
  $st = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
  $st->execute([$db, $table]);
  return array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
}
function column_meta(PDO $conn, string $table, string $column): ?array {
  $db = db_name($conn);
  $st = $conn->prepare("
    SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?
    LIMIT 1
  ");
  $st->execute([$db, $table, $column]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}
function pick_col(array $cols, array $candidates): ?string {
  foreach ($candidates as $c) {
    if (in_array(strtolower($c), $cols, true)) return $c;
  }
  return null;
}

/* =========================
   Categorías (auto)
========================= */
function get_categories(PDO $conn, int $branch_id): array {
  $candidates = ["inventory_categories","categories","categorias"];
  $table = null;
  foreach ($candidates as $t) {
    if (table_exists($conn, $t)) { $table = $t; break; }
  }
  if (!$table) return [];

  $cols = table_columns($conn, $table);
  $idCol = pick_col($cols, ["id"]);
  $nameCol = pick_col($cols, ["name","nombre","categoria","category","title"]);
  if (!$idCol || !$nameCol) return [];

  $branchCol = pick_col($cols, ["branch_id","sucursal_id","branch","sucursal"]);

  if ($branchCol) {
    $st = $conn->prepare("SELECT {$idCol} AS id, {$nameCol} AS name FROM {$table} WHERE {$branchCol}=? ORDER BY {$nameCol}");
    $st->execute([$branch_id]);
  } else {
    $st = $conn->prepare("SELECT {$idCol} AS id, {$nameCol} AS name FROM {$table} ORDER BY {$nameCol}");
    $st->execute();
  }
  return $st->fetchAll(PDO::FETCH_ASSOC);
}
$categories = get_categories($conn, $branch_id);

/* =========================
   Productos SOLO de la sede + category_id
========================= */
$itemCols = table_columns($conn, "inventory_items");
$itemCatCol = pick_col($itemCols, ["category_id","inventory_category_id","categoria_id","cat_id"]);
$selectCat = $itemCatCol ? "i.{$itemCatCol} AS category_id" : "0 AS category_id";

$st = $conn->prepare("
  SELECT i.id, i.name, {$selectCat}
  FROM inventory_items i
  INNER JOIN inventory_stock s ON s.item_id=i.id
  WHERE s.branch_id=?
  ORDER BY i.name
");
$st->execute([$branch_id]);
$products = $st->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   AJAX: historial en la misma página
========================= */
if (isset($_GET["ajax"]) && $_GET["ajax"] === "history") {
  header("Content-Type: text/html; charset=utf-8");

  $movCols = table_columns($conn, "inventory_movements");
  $colId     = pick_col($movCols, ["id"]);
  $colBranch = pick_col($movCols, ["branch_id","sucursal_id"]);
  $colType   = pick_col($movCols, ["movement_type","type","tipo","movement"]);
  $colNote   = pick_col($movCols, ["note","nota","observacion","descripcion","comment"]);
  $colDate   = pick_col($movCols, ["created_at","fecha","date","created","created_on"]);

  if (!$colId || !$colBranch) {
    echo "<div class='muted'>No se pudo leer inventory_movements (faltan columnas).</div>";
    exit;
  }

  // Filtro tipo "entrada" si existe
  $whereType = "";
  $params = [$branch_id];
  if ($colType) {
    $whereType = " AND {$colType} IN ('entrada','in','IN','E','ENTRADA')";
  }

  $orderCol = $colDate ?: $colId;

  $sql = "SELECT {$colId} AS id"
       . ($colDate ? ", {$colDate} AS created_at" : "")
       . ($colNote ? ", {$colNote} AS note" : "")
       . " FROM inventory_movements
          WHERE {$colBranch}=? {$whereType}
          ORDER BY {$orderCol} DESC
          LIMIT 50";

  $stH = $conn->prepare($sql);
  $stH->execute($params);
  $rows = $stH->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) {
    echo "<div class='muted'>No hay entradas registradas.</div>";
    exit;
  }

  echo "<table class='table'><thead><tr>";
  echo "<th style='width:110px;'>Mov.</th>";
  echo "<th style='width:180px;'>Fecha</th>";
  echo "<th>Nota</th>";
  echo "<th style='width:140px;'>Acción</th>";
  echo "</tr></thead><tbody>";

  foreach ($rows as $r) {
    $id = (int)$r["id"];
    $dt = isset($r["created_at"]) ? (string)$r["created_at"] : "";
    $note = isset($r["note"]) ? (string)$r["note"] : "";

    // Formatear fecha si viene tipo datetime
    $dtOut = $dt;
    if ($dt && strtotime($dt) !== false) {
      $dtOut = date("d/m/Y H:i", strtotime($dt));
    } else if ($dt === "") {
      $dtOut = "-";
    }

    echo "<tr>";
    echo "<td>#{$id}</td>";
    echo "<td>" . htmlspecialchars($dtOut) . "</td>";
    echo "<td>" . htmlspecialchars($note) . "</td>";
    echo "<td><a class='btn' href='?print={$id}'>Imprimir</a></td>";
    echo "</tr>";
  }

  echo "</tbody></table>";
  exit;
}

/* =========================
   Imprimir historial (reimpresión)
========================= */
if (isset($_GET["print"])) {
  $mov_id = (int)$_GET["print"];

  // Detectar columnas
  $movCols = table_columns($conn, "inventory_movements");
  $colId     = pick_col($movCols, ["id"]);
  $colBranch = pick_col($movCols, ["branch_id","sucursal_id"]);
  $colNote   = pick_col($movCols, ["note","nota","observacion","descripcion","comment"]);
  $colDate   = pick_col($movCols, ["created_at","fecha","date","created","created_on"]);

  $itCols = table_columns($conn, "inventory_movement_items");
  $colMovId = pick_col($itCols, ["movement_id","inventory_movement_id","movimiento_id","entrada_id"]);
  $colItem  = pick_col($itCols, ["item_id","inventory_item_id","product_id","producto_id"]);
  $colQty   = pick_col($itCols, ["quantity","qty","cantidad"]);

  if (!$colId || !$colBranch || !$colMovId || !$colItem || !$colQty) {
    die("No se puede imprimir: faltan columnas en BD.");
  }

  $stM = $conn->prepare("SELECT * FROM inventory_movements WHERE {$colId}=? AND {$colBranch}=? LIMIT 1");
  $stM->execute([$mov_id, $branch_id]);
  $mov = $stM->fetch(PDO::FETCH_ASSOC);
  if (!$mov) die("Movimiento no encontrado.");

  $stI = $conn->prepare("
    SELECT i.name, mi.{$colQty} AS qty
    FROM inventory_movement_items mi
    INNER JOIN inventory_items i ON i.id = mi.{$colItem}
    WHERE mi.{$colMovId} = ?
    ORDER BY i.name
  ");
  $stI->execute([$mov_id]);
  $items = $stI->fetchAll(PDO::FETCH_ASSOC);

  $dt = "";
  if ($colDate && !empty($mov[$colDate])) $dt = (string)$mov[$colDate];
  $dtOut = ($dt && strtotime($dt) !== false) ? date("d/m/Y H:i", strtotime($dt)) : date("d/m/Y H:i");
  $note = ($colNote && isset($mov[$colNote])) ? (string)$mov[$colNote] : "";

  ?>
  <!doctype html>
  <html lang="es">
  <head>
    <meta charset="utf-8">
    <title>Imprimir Entrada</title>
    <style>
      body{font-family:Arial, sans-serif; padding:16px;}
      table{width:100%; border-collapse:collapse;}
      th,td{border-bottom:1px solid #ddd; padding:8px; text-align:left;}
      th:last-child, td:last-child{text-align:right;}
    </style>
  </head>
  <body>
    <h2 style="margin:0 0 6px 0;">CEVIMEP - Entrada de Inventario</h2>
    <div><strong>Sucursal:</strong> <?= htmlspecialchars($branch_name) ?></div>
    <div><strong>Fecha:</strong> <?= htmlspecialchars($dtOut) ?></div>
    <div><strong>Movimiento #:</strong> <?= (int)$mov_id ?></div>
    <?php if ($note): ?><div><strong>Nota:</strong> <?= htmlspecialchars($note) ?></div><?php endif; ?>
    <hr>
    <table>
      <thead>
        <tr><th>Producto</th><th style="text-align:right;">Cant.</th></tr>
      </thead>
      <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= htmlspecialchars((string)$it["name"]) ?></td>
            <td style="text-align:right;"><?= (int)$it["qty"] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <script>window.addEventListener("load", ()=>window.print());</script>
  </body>
  </html>
  <?php
  exit;
}

/* =========================
   Agregar item
========================= */
$errorMsg = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_item"])) {
  $category_id = (int)($_POST["category_id"] ?? 0);
  $item_id     = (int)($_POST["item_id"] ?? 0);
  $qty         = (int)($_POST["qty"] ?? 0);

  if ($item_id > 0 && $qty > 0) {
    // Validar producto pertenece a la sede
    $st = $conn->prepare("
      SELECT i.id, i.name
      FROM inventory_items i
      INNER JOIN inventory_stock s ON s.item_id=i.id
      WHERE i.id=? AND s.branch_id=?
      LIMIT 1
    ");
    $st->execute([$item_id, $branch_id]);
    $it = $st->fetch(PDO::FETCH_ASSOC);

    if (!$it) {
      $errorMsg = "El producto seleccionado no pertenece a esta sede.";
    } else {
      $catName = "";
      foreach ($categories as $c) {
        if ((int)$c["id"] === $category_id) { $catName = (string)$c["name"]; break; }
      }

      $current = isset($_SESSION["entrada_items"][$item_id]) ? (int)$_SESSION["entrada_items"][$item_id]["qty"] : 0;
      $_SESSION["entrada_items"][$item_id] = [
        "category_id" => $category_id,
        "category"    => $catName,
        "name"        => (string)$it["name"],
        "qty"         => $current + $qty
      ];
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
   Guardar + imprimir (FIX movement_type)
========================= */
$print_receipt = false;
$receipt = null;
$receipt_items = [];

function choose_movement_value(PDO $conn, string $table, string $col, string $desired): ?string {
  $meta = column_meta($conn, $table, $col);
  if (!$meta) return null;

  $dataType = strtolower((string)$meta["DATA_TYPE"]);
  $colType  = (string)$meta["COLUMN_TYPE"];
  $maxLen   = (int)($meta["CHARACTER_MAXIMUM_LENGTH"] ?? 0);

  // Si es enum, intentar mapear
  if ($dataType === "enum") {
    // Parsear enum('a','b','c')
    preg_match_all("/'([^']+)'/", $colType, $m);
    $vals = $m[1] ?? [];
    if (!$vals) return null;

    $d = strtolower($desired);

    // Preferencias comunes
    $prefer = [];
    if ($d === "entrada") $prefer = ["entrada","ENTRADA","in","IN","E","e"];
    if ($d === "salida")  $prefer = ["salida","SALIDA","out","OUT","S","s"];

    foreach ($prefer as $p) {
      foreach ($vals as $v) {
        if (strtolower($v) === strtolower($p)) return $v;
      }
    }
    // Si no encuentra, usar primer valor del enum (para no truncar)
    return $vals[0];
  }

  // Si es varchar/char, recortar a maxLen
  if (($dataType === "varchar" || $dataType === "char") && $maxLen > 0) {
    $val = $desired;
    if (mb_strlen($val) > $maxLen) {
      // Intentar versiones cortas
      $shortMap = [
        "entrada" => ["E","in"],
        "salida"  => ["S","out"]
      ];
      foreach (($shortMap[$desired] ?? []) as $s) {
        if (mb_strlen($s) <= $maxLen) return $s;
      }
      return mb_substr($val, 0, $maxLen);
    }
    return $val;
  }

  // Otros tipos: mejor no tocar
  return null;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_entry"])) {
  if (!empty($_SESSION["entrada_items"])) {

    $supplier = trim((string)($_POST["supplier"] ?? ""));
    $note     = trim((string)($_POST["note"] ?? ""));

    $note_final = "";
    if ($supplier !== "") $note_final .= "Suplidor: {$supplier}";
    if ($note !== "") $note_final .= ($note_final ? " | " : "") . "Nota: {$note}";
    if ($note_final === "") $note_final = null;

    // Movements columns
    $movCols = table_columns($conn, "inventory_movements");
    $colType   = pick_col($movCols, ["movement_type","type","tipo","movement"]);
    $colBranch = pick_col($movCols, ["branch_id","sucursal_id","branch","sucursal"]);
    $colNote   = pick_col($movCols, ["note","nota","descripcion","observacion","obs","comment"]);
    $colDate   = pick_col($movCols, ["created_at","created","fecha","date","created_on"]);

    if (!$colBranch) die("Config BD: inventory_movements no tiene branch_id.");

    // movement_items columns
    $miCols = table_columns($conn, "inventory_movement_items");
    $colMovId = pick_col($miCols, ["movement_id","inventory_movement_id","movimiento_id","entrada_id"]);
    $colItem  = pick_col($miCols, ["item_id","inventory_item_id","product_id","producto_id"]);
    $colQty   = pick_col($miCols, ["quantity","qty","cantidad"]);
    if (!$colMovId || !$colItem || !$colQty) die("Config BD: inventory_movement_items incompleta.");

    // stock columns
    $stockCols = table_columns($conn, "inventory_stock");
    $colSItem   = pick_col($stockCols, ["item_id","inventory_item_id","product_id","producto_id"]);
    $colSBranch = pick_col($stockCols, ["branch_id","sucursal_id","branch","sucursal"]);
    $colSQty    = pick_col($stockCols, ["quantity","qty","cantidad"]);
    if (!$colSItem || !$colSBranch || !$colSQty) die("Config BD: inventory_stock incompleta.");

    $conn->beginTransaction();
    try {
      // 1) Insert movement (sin truncar movement_type)
      $fields = [];
      $values = [];
      $params = [];

      if ($colType) {
        $mv = choose_movement_value($conn, "inventory_movements", $colType, "entrada");
        // Si no puede determinar valor seguro, NO insertar type (evita truncation)
        if ($mv !== null) {
          $fields[] = $colType; $values[] = "?"; $params[] = $mv;
        }
      }

      $fields[] = $colBranch; $values[] = "?"; $params[] = $branch_id;

      if ($colNote) { $fields[] = $colNote; $values[] = "?"; $params[] = $note_final; }
      if ($colDate) { $fields[] = $colDate; $values[] = "NOW()"; }

      $sqlMov = "INSERT INTO inventory_movements (" . implode(",", $fields) . ") VALUES (" . implode(",", $values) . ")";
      $stMov = $conn->prepare($sqlMov);
      $stMov->execute($params);
      $movement_id = (int)$conn->lastInsertId();

      // 2) Insert items + sumar stock
      $stIt = $conn->prepare("INSERT INTO inventory_movement_items ({$colMovId}, {$colItem}, {$colQty}) VALUES (?, ?, ?)");

      // upsert stock: si existe, sumar; si no, crear
      $stSel = $conn->prepare("SELECT {$colSQty} FROM inventory_stock WHERE {$colSItem}=? AND {$colSBranch}=? LIMIT 1");
      $stUpd = $conn->prepare("UPDATE inventory_stock SET {$colSQty} = {$colSQty} + ? WHERE {$colSItem}=? AND {$colSBranch}=? LIMIT 1");
      $stIns = $conn->prepare("INSERT INTO inventory_stock ({$colSItem}, {$colSBranch}, {$colSQty}) VALUES (?, ?, ?)");

      foreach ($_SESSION["entrada_items"] as $item_id => $d) {
        $q = (int)($d["qty"] ?? 0);
        if ($q <= 0) continue;

        $stIt->execute([$movement_id, (int)$item_id, $q]);

        $stSel->execute([(int)$item_id, $branch_id]);
        $exists = $stSel->fetchColumn();

        if ($exists === false) {
          $stIns->execute([(int)$item_id, $branch_id, $q]);
        } else {
          $stUpd->execute([$q, (int)$item_id, $branch_id]);
        }
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

    } catch (Throwable $e) {
      $conn->rollBack();
      $errorMsg = $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Entrada</title>

  <link rel="stylesheet" href="/assets/css/styles.css?v=40">

  <style>
    .content .wrap { max-width: 1180px; margin: 0 auto; }

    /* Layout como tu ejemplo */
    .form-grid-2{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
      margin-top: 14px;
    }
    .row-4{
      display:grid;
      grid-template-columns: 240px 1fr 140px 120px;
      gap: 14px;
      align-items:end;
      margin-top: 14px;
    }
    .hint { font-size: 12px; opacity: .7; margin-top: 6px; }
    .btn-right { display:flex; justify-content:flex-end; margin-top: 12px; }

    .alert{
      padding: 10px 12px;
      border-radius: 12px;
      margin-top: 12px;
      background: rgba(255,0,0,.08);
      border: 1px solid rgba(255,0,0,.18);
    }

    /* Historial embebido */
    .historyBox{ display:none; margin-top:12px; }
    .historyBox.open{ display:block; }
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

        <?php if ($errorMsg): ?>
          <div class="alert"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

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
                  <option value="<?= (int)$p["id"] ?>" data-cat="<?= (int)($p["category_id"] ?? 0) ?>">
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
          <button class="btn" type="button" id="btnToggleHistory">Ver el historial</button>
        </div>

        <div class="historyBox" id="historyBox">
          <div class="muted" id="historyLoading" style="margin-top:10px;">Cargando...</div>
          <div id="historyContent" style="margin-top:10px;"></div>
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
<!-- Recibo simple para imprimir -->
<div id="receipt" style="display:none;">
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
  // Imprimir usando una ventana limpia (evita que se imprima la UI)
  window.addEventListener("load", function(){
    const html = document.getElementById("receipt").innerHTML;
    const w = window.open("", "_blank", "width=900,height=700");
    w.document.write("<html><head><title>Imprimir</title><style>body{font-family:Arial;padding:16px;} table{width:100%;border-collapse:collapse;} th,td{padding:6px;border-bottom:1px solid #ddd;} th:last-child,td:last-child{text-align:right;}</style></head><body>"+html+"</body></html>");
    w.document.close();
    w.focus();
    w.print();
    w.close();
  });
</script>
<?php endif; ?>

<script>
/* FILTRO categoría -> productos */
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
      if(opt.value === "") return;
      if(selectedCat === "" || opt.cat === selectedCat){
        const o = document.createElement("option");
        o.value = opt.value;
        o.textContent = opt.text;
        o.dataset.cat = opt.cat;
        prod.appendChild(o);
      }
    });

    if(currentValue){
      const exists = Array.from(prod.options).some(o => o.value === currentValue);
      if(exists) prod.value = currentValue;
    }
  }

  cat.addEventListener("change", rebuild);
  rebuild();
})();

/* Historial embebido (toggle + AJAX) */
(function(){
  const btn = document.getElementById("btnToggleHistory");
  const box = document.getElementById("historyBox");
  const loading = document.getElementById("historyLoading");
  const content = document.getElementById("historyContent");
  let loaded = false;

  async function loadHistory(){
    loading.style.display = "block";
    content.innerHTML = "";
    const res = await fetch("?ajax=history", {cache:"no-store"});
    const html = await res.text();
    loading.style.display = "none";
    content.innerHTML = html;
    loaded = true;
  }

  btn.addEventListener("click", async function(){
    box.classList.toggle("open");
    if (box.classList.contains("open")) {
      btn.textContent = "Ocultar historial";
      if (!loaded) await loadHistory();
    } else {
      btn.textContent = "Ver el historial";
    }
  });
})();
</script>

</body>
</html>
