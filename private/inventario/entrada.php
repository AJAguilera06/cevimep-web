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

function table_columns(PDO $conn, string $table): array {
  $st = $conn->prepare("SHOW COLUMNS FROM `$table`");
  $st->execute();
  $cols = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cols[] = $r["Field"];
  }
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

/**
 * Intenta elegir el valor correcto para movement_type según datos existentes.
 * Devuelve string (ej. 'IN') o null si no puede inferir.
 */
function choose_movement_value(PDO $conn, string $table, string $colType, string $kind): ?string {
  // kind: 'entrada' or 'salida'
  $wantEntrada = in_array($kind, ["entrada","in","IN"], true);

  try {
    $st = $conn->prepare("SELECT DISTINCT `$colType` AS v FROM `$table` WHERE `$colType` IS NOT NULL LIMIT 200");
    $st->execute();
    $vals = array_filter(array_map(fn($r) => $r["v"], $st->fetchAll(PDO::FETCH_ASSOC)));
  } catch (Throwable $e) {
    return $wantEntrada ? "IN" : "OUT";
  }

  $valsNorm = [];
  foreach ($vals as $v) $valsNorm[] = (string)$v;

  // Si ya usan IN/OUT
  if ($wantEntrada) {
    if (in_array("IN", $valsNorm, true)) return "IN";
    if (in_array("in", $valsNorm, true)) return "in";
    if (in_array("Entrada", $valsNorm, true)) return "Entrada";
    if (in_array("entrada", $valsNorm, true)) return "entrada";
    if (in_array("E", $valsNorm, true)) return "E";
    if (in_array("e", $valsNorm, true)) return "e";
    // fallback
    return "IN";
  } else {
    if (in_array("OUT", $valsNorm, true)) return "OUT";
    if (in_array("out", $valsNorm, true)) return "out";
    if (in_array("Salida", $valsNorm, true)) return "Salida";
    if (in_array("salida", $valsNorm, true)) return "salida";
    if (in_array("S", $valsNorm, true)) return "S";
    if (in_array("s", $valsNorm, true)) return "s";
    return "OUT";
  }
}

/* =========================
   Categorías / Productos (sede actual)
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
  if ($colCatBranch) {
    $where = "WHERE `$colCatBranch`=?";
    $params[] = $branch_id;
  }
  $stC = $conn->prepare("SELECT `$colCatId` AS id, `$colCatName` AS name FROM inventory_categories $where ORDER BY `$colCatName` ASC");
  $stC->execute($params);
  $categories = $stC->fetchAll(PDO::FETCH_ASSOC);
}

$whereItems = "";
$paramsItems = [];
if ($colItemBranch) {
  $whereItems = "WHERE i.`$colItemBranch`=?";
  $paramsItems[] = $branch_id;
}
$itemsSql = "SELECT i.`$colItemId` AS id, i.`$colItemName` AS name"
          . ($colItemCategory ? ", i.`$colItemCategory` AS category_id" : "")
          . " FROM inventory_items i $whereItems ORDER BY i.`$colItemName` ASC";
$stI = $conn->prepare($itemsSql);
$stI->execute($paramsItems);
$products = $stI->fetchAll(PDO::FETCH_ASSOC);

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

  $whereType = "";
  $params = [$branch_id];
  if ($colType) {
    $whereType = " AND {$colType} IN ('entrada','in','IN','E','ENTRADA','Entrada')";
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
      $whereType = " AND {$colType} IN ('IN','in','Entrada','entrada','E','e')";
    }
    $sql = "
      SELECT i.name, m.{$colQty} AS qty
      FROM inventory_movements m
      INNER JOIN inventory_items i ON i.id = m.{$colItem}
      WHERE m.{$colBranch}=? AND " . ($colNote ? "m.{$colNote} LIKE ?" : "1=0") . $whereType . "
      ORDER BY i.name
    ";
    $stI2 = $conn->prepare($sql);
    $stI2->execute($params);
    $itemsPrint = $stI2->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $itemsPrint = [[ "name" => "", "qty" => (float)($mov[$colQty] ?? 0) ]];
    $stN = $conn->prepare("SELECT name FROM inventory_items WHERE id=? LIMIT 1");
    $stN->execute([(int)$mov[$colItem]]);
    $nm = $stN->fetchColumn();
    if ($nm) $itemsPrint[0]["name"] = (string)$nm;
  }

  $dt = "";
  if ($colDate && !empty($mov[$colDate])) $dt = (string)$mov[$colDate];
  $dtOut = ($dt && strtotime($dt) !== false) ? date("d/m/Y H:i", strtotime($dt)) : date("d/m/Y H:i");
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
      h2{margin:0 0 10px 0;}
      .small{font-size:12px; opacity:.75;}
      .wrap{max-width:560px;margin:0 auto;}
    </style>
  </head>
  <body>
    <div class="wrap">
      <h2>CEVIMEP - Entrada de Inventario</h2>
      <div><strong>Sucursal:</strong> <?= h($branch_name) ?></div>
      <div><strong>Fecha:</strong> <?= h($dtOut) ?></div>
      <div><strong>Movimiento #:</strong> <?= (int)$mov_id ?></div>
      <?php if ($noteRaw): ?><div><strong>Nota:</strong> <?= h($noteRaw) ?></div><?php endif; ?>
      <hr>
      <table>
        <thead><tr><th>Producto</th><th>Cant.</th></tr></thead>
        <tbody>
          <?php foreach ($itemsPrint as $it): ?>
            <tr>
              <td><?= h((string)$it["name"]) ?></td>
              <td><?= (int)$it["qty"] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="small">Generado por CEVIMEP</p>
    </div>
    <script>window.addEventListener("load", function(){ window.print(); });</script>
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
   Quitar item / Vaciar
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["remove_item"])) {
  $rid = (int)($_POST["remove_item"] ?? 0);
  if ($rid > 0 && isset($_SESSION["entrada_items"][$rid])) {
    unset($_SESSION["entrada_items"][$rid]);
  }
  header("Location: entrada.php");
  exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["clear_items"])) {
  $_SESSION["entrada_items"] = [];
  header("Location: entrada.php");
  exit;
}

/* =========================
   Guardar entrada + imprimir (coherente con inventory_movements)
========================= */
$receipt = null;
$print_receipt = false;

function safe_string($val, $maxLen = 255) {
  if ($val === null) return null;
  if (is_string($val)) {
    $val = trim($val);
    if ($val === "") return null;
    if (strlen($val) > $maxLen) {
      $val = substr($val, 0, $maxLen);
    }
    return $val;
  }
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

    // Batch para agrupar la impresión (todas las filas de esta entrada)
    try {
      $batch = strtoupper(bin2hex(random_bytes(6)));
    } catch (Throwable $e) {
      $batch = strtoupper(str_replace('.', '', uniqid('', true)));
    }
    $batchTag = "BATCH={$batch}";
    $note_final = $note_final ? ($batchTag . " | " . $note_final) : $batchTag;

    // Movements columns
    $movCols = table_columns($conn, "inventory_movements");
    $colType   = pick_col($movCols, ["movement_type","type","tipo","movement"]);
    $colBranch = pick_col($movCols, ["branch_id","sucursal_id","branch","sucursal"]);
    $colNote   = pick_col($movCols, ["note","nota","descripcion","observacion","obs","comment"]);
    $colDate   = pick_col($movCols, ["created_at","created","fecha","date","created_on"]);

    if (!$colBranch) die("Config BD: inventory_movements no tiene branch_id.");

    // movement columns (item/qty dentro de inventory_movements)
    $colItemMov = pick_col($movCols, ["item_id","inventory_item_id","product_id","producto_id"]);
    $colQtyMov  = pick_col($movCols, ["qty","quantity","cantidad"]);
    $colCreatedBy = pick_col($movCols, ["created_by","user_id","registrado_por"]);
    if (!$colItemMov || !$colQtyMov) die("Config BD: inventory_movements no tiene item_id/qty.");

    // stock columns
    $stockCols = table_columns($conn, "inventory_stock");
    $colSItem   = pick_col($stockCols, ["item_id","inventory_item_id","product_id","producto_id"]);
    $colSBranch = pick_col($stockCols, ["branch_id","sucursal_id","branch","sucursal"]);
    $colSQty    = pick_col($stockCols, ["quantity","qty","cantidad"]);
    if (!$colSItem || !$colSBranch || !$colSQty) die("Config BD: inventory_stock incompleta.");

    $conn->beginTransaction();
    try {
      // Preparar INSERT dinámico
      $fields = [];
      $values = [];
      $hasType = false;

      $mvTypeVal = null;
      if ($colType) {
        $mvTypeVal = choose_movement_value($conn, "inventory_movements", $colType, "entrada");
        if ($mvTypeVal !== null) {
          $fields[] = $colType; $values[] = "?"; $hasType = true;
        }
      }

      $fields[] = $colBranch;  $values[] = "?";
      $fields[] = $colItemMov; $values[] = "?";
      $fields[] = $colQtyMov;  $values[] = "?";

      if ($colNote) { $fields[] = $colNote; $values[] = "?"; }
      if ($colCreatedBy) { $fields[] = $colCreatedBy; $values[] = "?"; }
      if ($colDate) { $fields[] = $colDate; $values[] = "NOW()"; }

      $sqlMov = "INSERT INTO inventory_movements (" . implode(",", $fields) . ") VALUES (" . implode(",", $values) . ")";
      $stMov = $conn->prepare($sqlMov);

      $movement_id = 0;

      // upsert stock
      $stSel = $conn->prepare("SELECT {$colSQty} FROM inventory_stock WHERE {$colSItem}=? AND {$colSBranch}=? LIMIT 1");
      $stUpd = $conn->prepare("UPDATE inventory_stock SET {$colSQty} = {$colSQty} + ? WHERE {$colSItem}=? AND {$colSBranch}=? LIMIT 1");
      $stIns = $conn->prepare("INSERT INTO inventory_stock ({$colSItem}, {$colSBranch}, {$colSQty}) VALUES (?, ?, ?)");

      $createdByVal = (int)($user["id"] ?? 0);

      foreach ($_SESSION["entrada_items"] as $item_id => $d) {
        $q = (int)($d["qty"] ?? 0);
        if ($q <= 0) continue;

        $params = [];
        if ($hasType) $params[] = $mvTypeVal;
        $params[] = $branch_id;
        $params[] = (int)$item_id;
        $params[] = $q;
        if ($colNote) $params[] = $note_final;
        if ($colCreatedBy) $params[] = $createdByVal;

        $stMov->execute($params);
        if (!$movement_id) $movement_id = (int)$conn->lastInsertId();

        // Stock +q
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
        "supplier" => safe_string($supplier, 120),
        "note" => safe_string($note, 400),
        "batch" => $batch,
        "items" => array_values(array_map(function($v){
          return [
            "name" => (string)($v["name"] ?? ""),
            "qty"  => (int)($v["qty"] ?? 0),
          ];
        }, $_SESSION["entrada_items"]))
      ];

      $_SESSION["entrada_items"] = [];
      $print_receipt = true;

    } catch (Throwable $e) {
      $conn->rollBack();
      $errorMsg = "Error guardando la entrada: " . $e->getMessage();
    }
  } else {
    $errorMsg = "No hay productos agregados.";
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

    .card{
      background:#fff;
      border-radius:14px;
      padding:16px;
      box-shadow:0 6px 18px rgba(0,0,0,.08);
      border:1px solid rgba(0,0,0,.06);
    }

    .table{
      width:100%;
      border-collapse:collapse;
      margin-top:10px;
    }
    .table th, .table td{
      border-bottom:1px solid rgba(0,0,0,.08);
      padding:10px 8px;
      text-align:left;
      vertical-align:top;
    }
    .table th:last-child, .table td:last-child{ text-align:right; }

    .btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      padding:10px 14px;
      border-radius:10px;
      border:1px solid rgba(0,0,0,.15);
      background:#0b5ed7;
      color:#fff;
      text-decoration:none;
      cursor:pointer;
      font-weight:600;
      transition:.15s;
    }
    .btn:hover{ filter:brightness(.95); }
    .btn.secondary{
      background:#f3f5f7;
      color:#222;
    }

    .input, select, textarea{
      width:100%;
      padding:10px 12px;
      border-radius:10px;
      border:1px solid rgba(0,0,0,.18);
      outline:none;
      background:#fff;
    }

    label{ font-size:12px; opacity:.8; display:block; margin-bottom:6px; }

    /* Historial embebido */
    .historyBox{ display:none; margin-top:12px; }
    .historyBox.open{ display:block; }
  </style>
</head>
<body>

<?php include __DIR__ . "/../_sidebar.php"; ?>

<div class="content">
  <div class="wrap">
<div class="card">
        <h3 style="margin:0;">Entrada</h3>
        <div class="muted" style="margin-top:4px;">Registra entrada de inventario (sede actual)</div>

        <div class="muted" style="margin-top:8px;">
          Sucursal: <strong><?= h($branch_name) ?></strong>
        </div>

        <?php if ($errorMsg): ?>
          <div class="alert"><strong>Atención:</strong> <?= h($errorMsg) ?></div>
        <?php endif; ?>

        <form method="post" class="row-4" style="margin-top:14px;">
          <div>
            <label>Categoría</label>
            <select class="input" id="category_id" name="category_id">
              <option value="">Todas</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c["id"] ?>"><?= h($c["name"]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label>Producto</label>
            <select class="input" id="item_id" name="item_id" required>
              <option value="">Selecciona</option>
              <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p["id"] ?>" data-cat="<?= isset($p["category_id"]) ? (int)$p["category_id"] : 0 ?>">
                  <?= h($p["name"]) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="hint">Solo productos de esta sucursal.</div>
          </div>

          <div>
            <label>Cantidad</label>
            <input class="input" type="number" name="qty" min="1" step="1" placeholder="0" required>
          </div>

          <div style="display:flex; gap:10px; justify-content:flex-end;">
            <button class="btn" type="submit" name="add_item" value="1" style="width:100%;">Añadir</button>
          </div>
        </form>

        <div class="card" style="margin-top:14px;">
          <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
            <div>
              <strong>Detalle</strong>
              <div class="muted" style="font-size:12px;">Productos agregados</div>
            </div>

            <form method="post" style="margin:0;">
              <button class="btn secondary" type="submit" name="clear_items" value="1">Vaciar</button>
            </form>
          </div>

          <table class="table">
            <thead>
              <tr>
                <th>Producto</th>
                <th style="text-align:right;">Cantidad</th>
                <th style="text-align:right;">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($_SESSION["entrada_items"])): ?>
                <tr><td colspan="3" class="muted">No hay productos agregados.</td></tr>
              <?php else: ?>
                <?php foreach ($_SESSION["entrada_items"] as $id => $d): ?>
                  <tr>
                    <td><?= h($d["name"]) ?></td>
                    <td style="text-align:right;"><?= (int)$d["qty"] ?></td>
                    <td style="text-align:right;">
                      <form method="post" style="display:inline;">
                        <button class="btn secondary" type="submit" name="remove_item" value="<?= (int)$id ?>">Quitar</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>

          <form method="post" style="margin-top:14px;">
            <div class="form-grid-2">
              <div>
                <label>Suplidor</label>
                <input class="input" type="text" name="supplier" placeholder="Escribe el suplidor (opcional)">
              </div>
              <div>
                <label>Nota</label>
                <input class="input" type="text" name="note" placeholder="Observaciones (opcional)">
              </div>
            </div>

            <div class="btn-right">
              <button class="btn" type="submit" name="save_entry" value="1">Guardar e Imprimir</button>
            </div>
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
  </div>
</div>

<div class="footer">
  <div class="inner">
    © <?= (int)date("Y") ?> CEVIMEP. Todos los derechos reservados.
  </div>
</div>

<?php if ($print_receipt && $receipt): ?>
<script>
  window.addEventListener("load", function(){
    const r = <?= json_encode($receipt, JSON_UNESCAPED_UNICODE) ?>;

    let html = "";
    html += "<div style='max-width:560px;margin:0 auto;font-family:Arial,sans-serif;'>";
    html += "<h2 style='text-align:center;margin:0;'>Entrada</h2>";
    html += "<div style='text-align:center;opacity:.75;font-size:12px;'>Sucursal: "+(r.branch||"")+"</div>";
    html += "<div style='text-align:center;opacity:.75;font-size:12px;'>Fecha: "+(r.date||"")+"</div>";
    if (r.batch) html += "<div style='text-align:center;opacity:.75;font-size:12px;'>Batch: "+r.batch+"</div>";
    html += "<hr style='margin:12px 0;'>";
    if (r.supplier) html += "<div><strong>Suplidor:</strong> "+r.supplier+"</div>";
    if (r.note) html += "<div><strong>Nota:</strong> "+r.note+"</div>";
    html += "<hr style='margin:12px 0;'>";
    html += "<table style='width:100%;border-collapse:collapse;'>";
    html += "<thead><tr><th style='text-align:left;border-bottom:1px solid #ddd;padding:8px;'>Producto</th><th style='text-align:right;border-bottom:1px solid #ddd;padding:8px;'>Cant.</th></tr></thead>";
    html += "<tbody>";
    (r.items || []).forEach(it=>{
      html += "<tr><td style='padding:8px;border-bottom:1px solid #eee;'>"+(it.name||"")+"</td><td style='padding:8px;border-bottom:1px solid #eee;text-align:right;'>"+(it.qty||0)+"</td></tr>";
    });
    html += "</tbody></table>";
    html += "<div style='text-align:center;opacity:.75;font-size:12px;margin-top:10px;'>Generado por CEVIMEP</div>";
    html += "</div>";

    const w = window.open("", "_blank");
    w.document.write("<html><head><title>Imprimir</title><meta charset='utf-8'><style>body{padding:16px;}@media print{button{display:none;}}</style></head><body>"+html+"</body></html>");
    w.document.close();
    w.focus();
    w.print();
    w.close();
  });
</script>
<?php endif; ?>

<script>
/* ==========================================
   FILTRO: Categoría -> Productos (category_id)
========================================== */
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

/* ==========================================
   HISTORIAL EMBEBIDO: toggle + AJAX
========================================== */
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
      content.innerHTML = "<div class='muted'>No se pudo cargar el historial.</div>";
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
