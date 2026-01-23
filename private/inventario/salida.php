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
   Productos SOLO de la sede (con stock > 0) + category_id
========================= */
$itemCols = table_columns($conn, "inventory_items");
$itemCatCol = pick_col($itemCols, ["category_id","inventory_category_id","categoria_id","cat_id"]);
$selectCat = $itemCatCol ? "i.{$itemCatCol} AS category_id" : "0 AS category_id";

$st = $conn->prepare("
  SELECT i.id, i.name, {$selectCat}, s.quantity AS stock_qty
  FROM inventory_items i
  INNER JOIN inventory_stock s ON s.item_id=i.id
  WHERE s.branch_id=? AND s.quantity > 0
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

  // Filtro tipo "salida" si existe
  $whereType = "";
  $params = [$branch_id];
  if ($colType) {
    $whereType = " AND {$colType} IN ('salida','out','OUT','S','SALIDA')";
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
    echo "<div class='muted'>No hay salidas registradas.</div>";
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

  $movCols = table_columns($conn, "inventory_movements");
  $colId     = pick_col($movCols, ["id"]);
  $colBranch = pick_col($movCols, ["branch_id","sucursal_id"]);
  $colType   = pick_col($movCols, ["movement_type","type","tipo","movement"]);
  $colItem   = pick_col($movCols, ["item_id","inventory_item_id","product_id","producto_id"]);
  $colQty    = pick_col($movCols, ["qty","quantity","cantidad"]);
  $colNote   = pick_col($movCols, ["note","nota","observacion","descripcion","comment"]);
  $colDate   = pick_col($movCols, ["created_at","fecha","date","created","created_on"]);

  if (!$colId || !$colBranch || !$colItem || !$colQty) {
    die("No se puede imprimir: faltan columnas en inventory_movements.");
  }

  $stM = $conn->prepare("SELECT * FROM inventory_movements WHERE {$colId}=? AND {$colBranch}=? LIMIT 1");
  $stM->execute([$mov_id, $branch_id]);
  $mov = $stM->fetch(PDO::FETCH_ASSOC);
  if (!$mov) die("Movimiento no encontrado.");

  $noteRaw = ($colNote && isset($mov[$colNote])) ? (string)$mov[$colNote] : "";
  $batch = null;
  if ($noteRaw && preg_match('/BATCH=([A-Z0-9\\-]+)/', $noteRaw, $m)) {
    $batch = $m[1];
  }

  if ($batch) {
    $whereType = "";
    $params = [$branch_id, "%BATCH={$batch}%"];
    if ($colType) {
      $whereType = " AND {$colType} IN ('OUT','out','Salida','salida','S','s')";
    }
    $sql = "
      SELECT i.name, m.{$colQty} AS qty
      FROM inventory_movements m
      INNER JOIN inventory_items i ON i.id = m.{$colItem}
      WHERE m.{$colBranch}=? AND " . ($colNote ? "m.{$colNote} LIKE ?" : "1=0") . $whereType . "
      ORDER BY i.name
    ";
    $stI = $conn->prepare($sql);
    $stI->execute($params);
    $items = $stI->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $items = [[ "name" => "", "qty" => (float)($mov[$colQty] ?? 0) ]];
    $stN = $conn->prepare("SELECT name FROM inventory_items WHERE id=? LIMIT 1");
    $stN->execute([(int)$mov[$colItem]]);
    $nm = $stN->fetchColumn();
    if ($nm) $items[0]["name"] = (string)$nm;
  }

  $dt = "";
  if ($colDate && !empty($mov[$colDate])) $dt = (string)$mov[$colDate];
  $dtOut = ($dt && strtotime($dt) !== false) ? date("d/m/Y H:i", strtotime($dt)) : date("d/m/Y H:i");

  ?>
  <!doctype html>
  <html lang="es">
  <head>
    <meta charset="utf-8">
    <title>Imprimir Salida</title>
    <style>
      body{font-family:Arial, sans-serif; padding:16px;}
      table{width:100%; border-collapse:collapse;}
      th,td{border-bottom:1px solid #ddd; padding:8px; text-align:left;}
      th:last-child, td:last-child{text-align:right;}
      h2{margin:0 0 10px 0;}
      .small{font-size:12px; opacity:.75;}
    </style>
  </head>
  <body>
    <h2>CEVIMEP - Salida de Inventario</h2>
    <div><strong>Sucursal:</strong> <?= htmlspecialchars($branch_name) ?></div>
    <div><strong>Fecha:</strong> <?= htmlspecialchars($dtOut) ?></div>
    <div><strong>Movimiento #:</strong> <?= (int)$mov_id ?></div>
    <?php if ($noteRaw): ?><div><strong>Nota:</strong> <?= htmlspecialchars($noteRaw) ?></div><?php endif; ?>
    <hr>
    <table>
      <thead><tr><th>Producto</th><th>Cant.</th></tr></thead>
      <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= htmlspecialchars((string)$it["name"]) ?></td>
            <td><?= (int)$it["qty"] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="small">Generado por CEVIMEP</p>
    <script>window.print();</script>
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

    // Validar que el producto pertenece a la sede y tenga stock
    $st = $conn->prepare("
      SELECT i.id, i.name, s.quantity AS stock_qty
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
      $stock = (int)($it["stock_qty"] ?? 0);

      // Cantidad acumulada si ya está agregado
      $current = isset($_SESSION["salida_items"][$item_id]) ? (int)$_SESSION["salida_items"][$item_id]["qty"] : 0;
      $newQty = $current + $qty;

      if ($newQty > $stock) {
        $errorMsg = "Stock insuficiente. Disponible: {$stock}.";
      } else {
        $catName = "";
        foreach ($categories as $c) {
          if ((int)$c["id"] === $category_id) { $catName = (string)$c["name"]; break; }
        }

        $_SESSION["salida_items"][$item_id] = [
          "category_id" => $category_id,
          "category"    => $catName,
          "name"        => (string)$it["name"],
          "qty"         => $newQty
        ];
      }
    }
  }
}

/* =========================
   Eliminar item
========================= */
if (isset($_GET["remove"])) {
  unset($_SESSION["salida_items"][(int)$_GET["remove"]]);
}

/* =========================
   Guardar salida + imprimir (sin depender de type)
========================= */
$print_receipt = false;
$receipt = null;
$receipt_items = [];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_exit"])) {

  if (!empty($_SESSION["salida_items"])) {

    $destination = trim((string)($_POST["destination"] ?? "")); // a quién/para dónde
    $note        = trim((string)($_POST["note"] ?? ""));

    $note_final = "";
    if ($destination !== "") $note_final .= "Destino: {$destination}";
    if ($note !== "") $note_final .= ($note_final ? " | " : "") . "Nota: {$note}";
    if ($note_final === "") $note_final = null;

    // Detectar columnas de inventory_movements (tu BD guarda 1 fila por item, no usa movement_items)
    $movCols = table_columns($conn, "inventory_movements");
    $colType    = pick_col($movCols, ["type","movement_type","tipo","accion","action","movement"]);
    $colBranch  = pick_col($movCols, ["branch_id","sucursal_id","branch","sucursal"]);
    $colItemMov = pick_col($movCols, ["item_id","inventory_item_id","product_id","producto_id"]);
    $colQtyMov  = pick_col($movCols, ["qty","quantity","cantidad"]);
    $colNote    = pick_col($movCols, ["note","nota","descripcion","observacion","obs","comment"]);
    $colDate    = pick_col($movCols, ["created_at","created","fecha","date","created_on"]);

    if (!$colBranch) {
      die("Config BD: inventory_movements no tiene columna branch_id/sucursal_id.");
    }
    if (!$colItemMov) {
      die("Config BD: inventory_movements no tiene columna item_id/product_id.");
    }
    if (!$colQtyMov) {
      die("Config BD: inventory_movements no tiene columna qty/quantity.");
    }
    if (!$colType) {
      die("Config BD: inventory_movements no tiene columna movement_type/type.");
    }

    // Generar un batch para agrupar esta salida en el recibo
    $batch = strtoupper(bin2hex(random_bytes(4)));
    if ($note_final === null || $note_final === "") {
      $note_final = "BATCH={$batch}";
    } else {
      $note_final = "BATCH={$batch} | " . $note_final;
    }



    
    // Detectar inventory_stock (opcional). Si existe, lo actualizamos; si no, el stock queda calculado por movimientos.
    $stockCols = table_columns($conn, "inventory_stock");
    $colSItem   = pick_col($stockCols, ["item_id","inventory_item_id","product_id","producto_id"]);
    $colSBranch = pick_col($stockCols, ["branch_id","sucursal_id","branch","sucursal"]);
    $colSQty    = pick_col($stockCols, ["quantity","qty","cantidad"]);
    $hasStockTable = ($colSItem && $colSBranch && $colSQty);


$conn->beginTransaction();

    try {
      // 1) Validar stock por item (calculado desde inventory_movements)
      $stHave = $conn->prepare("
        SELECT COALESCE(SUM(
          CASE
            WHEN UPPER({$colType}) IN ('IN','ENTRADA','ENTRY') THEN {$colQtyMov}
            ELSE -{$colQtyMov}
          END
        ), 0) AS stock
        FROM inventory_movements
        WHERE {$colBranch}=? AND {$colItemMov}=?
      ");

      foreach ($_SESSION["salida_items"] as $item_id => $d) {
        $need = (int)($d["qty"] ?? 0);
        if ($need <= 0) continue;

        $stHave->execute([$branch_id, (int)$item_id]);
        $have = (int)($stHave->fetchColumn() ?? 0);

        if ($have < $need) {
          throw new RuntimeException("Stock insuficiente para '{$d["name"]}'. Disponible: {$have}, solicitado: {$need}.");
        }
      }

      // 2) Insertar salida (1 fila por item) en inventory_movements
      $movement_id = 0;

      $fieldsBase = [];
      $valuesBase = [];
      // Tipo
      $fieldsBase[] = $colType;
      $valuesBase[] = "?";
      // Sucursal
      $fieldsBase[] = $colBranch;
      $valuesBase[] = "?";
      // Item
      $fieldsBase[] = $colItemMov;
      $valuesBase[] = "?";
      // Cantidad
      $fieldsBase[] = $colQtyMov;
      $valuesBase[] = "?";
      // Nota (si existe)
      if ($colNote) { $fieldsBase[] = $colNote; $valuesBase[] = "?"; }
      // Fecha (si existe y no es autoincrement)
      if ($colDate) { $fieldsBase[] = $colDate; $valuesBase[] = "NOW()"; }

      $sqlMov = "INSERT INTO inventory_movements (" . implode(",", $fieldsBase) . ") VALUES (" . implode(",", $valuesBase) . ")";
      $stMov = $conn->prepare($sqlMov);

      foreach ($_SESSION["salida_items"] as $item_id => $d) {
        $q = (int)($d["qty"] ?? 0);
        if ($q <= 0) continue;

        $params = [];
        $params[] = "OUT";         // movement_type
        $params[] = $branch_id;    // branch_id
        $params[] = (int)$item_id; // item_id
        $params[] = $q;            // qty
        if ($colNote) $params[] = $note_final;

        $stMov->execute($params);

        // 3) Actualizar inventory_stock si existe
        if ($hasStockTable) {
          if (!isset($stUpd)) { $stUpd = $conn->prepare("
            UPDATE inventory_stock
            SET {$colSQty} = {$colSQty} - ?
            WHERE {$colSItem}=? AND {$colSBranch}=?
            LIMIT 1
          ");
          }
          $stUpd->execute([$q, (int)$item_id, $branch_id]);
        }

        if ($movement_id === 0) {
          $movement_id = (int)$conn->lastInsertId();
        }
      }


      $conn->commit();

      $receipt = [
        "movement_id" => $movement_id,
        "branch" => $branch_name,
        "date" => date("d/m/Y H:i"),
        "destination" => $destination,
        "note" => $note
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
  <title>CEVIMEP | Salida</title>

  <!-- CSS ABSOLUTO -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=30">

  <style>
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

    .alert{
      padding: 10px 12px;
      border-radius: 12px;
      margin-top: 12px;
      background: rgba(255,0,0,.08);
      border: 1px solid rgba(255,0,0,.18);
    }

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
      <a href="/private/patients/index.php"><span class="ico">👤</span> Pacientes</a>
      <a href="/private/citas/index.php"><span class="ico">📅</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💳</span> Caja</a>

      <a class="active" href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/inventario/entrada.php" style="margin-left:14px;"><span class="ico">➕</span> Entrada</a>
      <a class="active" href="/private/inventario/salida.php" style="margin-left:14px;"><span class="ico">➖</span> Salida</a>

      <a href="/private/estadisticas/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <div class="wrap">

      <div class="card">
        <h3 style="margin:0;">Salida</h3>
        <div class="muted" style="margin-top:4px;">Registra salida de inventario (sede actual)</div>

        <?php if ($errorMsg): ?>
          <div class="alert"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <form method="post" id="frmSalida">

          <div class="form-grid-2">
            <div>
              <label>Fecha</label>
              <input type="date" value="<?= htmlspecialchars($today) ?>" disabled>
            </div>
            <div>
              <label>Destino</label>
              <input type="text" name="destination" placeholder="Ej: Consultorio, Brigada, Paciente, etc.">
            </div>

            <div>
              <label>Área de origen</label>
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
                    data-stock="<?= (int)($p["stock_qty"] ?? 0) ?>"
                  >
                    <?= htmlspecialchars((string)$p["name"]) ?> (Stock: <?= (int)($p["stock_qty"] ?? 0) ?>)
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
<div class="receipt">
  <h2 style="margin:0 0 6px 0;">CEVIMEP - Salida de Inventario</h2>
  <div><strong>Sucursal:</strong> <?= htmlspecialchars($receipt["branch"]) ?></div>
  <div><strong>Fecha:</strong> <?= htmlspecialchars($receipt["date"]) ?></div>
  <div><strong>Movimiento #:</strong> <?= (int)$receipt["movement_id"] ?></div>
  <?php if ($receipt["destination"] !== ""): ?><div><strong>Destino:</strong> <?= htmlspecialchars($receipt["destination"]) ?></div><?php endif; ?>
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
   FILTRO: Categoría -> Productos (category_id)
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
</script>

</body>
</html>
