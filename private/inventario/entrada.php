<?php
declare(strict_types=1);
require_once __DIR__ . "/../_guard.php";

$conn = $pdo;

$user = $_SESSION["user"] ?? [];
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

$errors = [];

/* =========================
   CARGA: CATEGORÍAS
========================= */
$categories = [];
try {
  $stC = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
  $categories = $stC ? $stC->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {}

/* =========================
   CARGA: PRODUCTOS (solo sucursal actual)
   - basado en inventory_stock por branch_id
========================= */
$products = [];
try {
  $stP = $conn->prepare("
    SELECT i.id, i.name, i.category_id
    FROM inventory_items i
    INNER JOIN inventory_stock s ON s.item_id = i.id AND s.branch_id = ?
    WHERE (i.is_active = 1 OR i.is_active IS NULL)
    ORDER BY i.name ASC
  ");
  $stP->execute([$branch_id]);
  $products = $stP->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* =========================
   ACCIONES
========================= */
$action = $_POST["action"] ?? "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  /* ADD */
  if ($action === "add") {
    $item_id = (int)($_POST["item_id"] ?? 0);
    $qty = (int)($_POST["qty"] ?? 0);

    if ($item_id <= 0) $errors[] = "Selecciona un producto.";
    if ($qty <= 0) $errors[] = "La cantidad debe ser mayor que 0.";

    // Validar que el item pertenece a la sucursal (por stock)
    if (!$errors) {
      $st = $conn->prepare("
        SELECT i.id, i.name
        FROM inventory_items i
        INNER JOIN inventory_stock s ON s.item_id = i.id AND s.branch_id = ?
        WHERE i.id = ?
        LIMIT 1
      ");
      $st->execute([$branch_id, $item_id]);
      $p = $st->fetch(PDO::FETCH_ASSOC);

      if (!$p) {
        $errors[] = "Ese producto no pertenece a esta sucursal.";
      } else {
        // Si ya existe, suma cantidad
        if (!isset($_SESSION["entrada_cart"][$item_id])) {
          $_SESSION["entrada_cart"][$item_id] = [
            "item_id" => $item_id,
            "name" => (string)$p["name"],
            "qty" => $qty,
          ];
        } else {
          $_SESSION["entrada_cart"][$item_id]["qty"] += $qty;
        }
      }
    }
  }

  /* CLEAR */
  if ($action === "clear") {
    $_SESSION["entrada_cart"] = [];
    header("Location: entrada.php");
    exit;
  }

  /* REMOVE */
  if ($action === "remove") {
    $rid = (int)($_POST["remove_id"] ?? 0);
    if ($rid > 0 && isset($_SESSION["entrada_cart"][$rid])) {
      unset($_SESSION["entrada_cart"][$rid]);
    }
    header("Location: entrada.php");
    exit;
  }

  /* SAVE + PRINT */
  if ($action === "save_print") {

    $supplier = trim((string)($_POST["supplier"] ?? ""));

    if (empty($_SESSION["entrada_cart"])) {
      $errors[] = "No hay productos agregados.";
    }

    if (!$errors) {
      $movCols = table_columns($conn, "inventory_movements");
      $stockCols = table_columns($conn, "inventory_stock");

      $mov_branch = pick_col($movCols, ["branch_id","sucursal_id"]);
      $mov_item   = pick_col($movCols, ["item_id","product_id","inventory_item_id"]);
      $mov_qty    = pick_col($movCols, ["quantity","qty","cantidad"]);
      $mov_type   = pick_col($movCols, ["movement_type","type","mov_type","direction"]);
      $mov_sup    = pick_col($movCols, ["supplier","suplidor","provider"]);
      $mov_user   = pick_col($movCols, ["created_by","user_id","made_by"]);
      $mov_date   = pick_col($movCols, ["created_at","date","fecha","created_on"]);
      $mov_note   = pick_col($movCols, ["note","notes","detalle","description"]);

      $stk_branch = pick_col($stockCols, ["branch_id","sucursal_id"]);
      $stk_item   = pick_col($stockCols, ["item_id","product_id","inventory_item_id"]);
      $stk_qty    = pick_col($stockCols, ["quantity","qty","stock","existencia"]);

      if (!$mov_branch || !$mov_item || !$mov_qty) $errors[] = "inventory_movements sin columnas base (branch/item/qty).";
      if (!$stk_branch || !$stk_item || !$stk_qty) $errors[] = "inventory_stock sin columnas base (branch/item/qty).";

      if (!$errors) {
        $receipt_id = "ENT-" . date("Ymd-His") . "-" . $branch_id;

        try {
          $conn->beginTransaction();

          // Insert movimiento
          $baseCols = [$mov_branch, $mov_item, $mov_qty];
          $extraCols = [];
          $extraVals = [];

          if ($mov_type) { $extraCols[] = $mov_type; $extraVals[] = "IN"; }
          if ($mov_sup)  { $extraCols[] = $mov_sup;  $extraVals[] = $supplier; }
          if ($mov_user) { $extraCols[] = $mov_user; $extraVals[] = (int)($user["id"] ?? 0); }
          if ($mov_date) { $extraCols[] = $mov_date; $extraVals[] = date("Y-m-d H:i:s"); }
          if ($mov_note) { $extraCols[] = $mov_note; $extraVals[] = $receipt_id; }

          $insCols = array_merge($baseCols, $extraCols);
          $ph = implode(",", array_fill(0, count($insCols), "?"));
          $sqlIns = "INSERT INTO inventory_movements (`" . implode("`,`", $insCols) . "`) VALUES ($ph)";
          $stIns = $conn->prepare($sqlIns);

          // Stock
          $stChk = $conn->prepare("SELECT 1 FROM inventory_stock WHERE `$stk_branch`=? AND `$stk_item`=? LIMIT 1");
          $stUpd = $conn->prepare("UPDATE inventory_stock SET `$stk_qty` = `$stk_qty` + ? WHERE `$stk_branch`=? AND `$stk_item`=?");
          $stStockIns = $conn->prepare("INSERT INTO inventory_stock (`$stk_branch`,`$stk_item`,`$stk_qty`) VALUES (?,?,?)");

          $receipt_lines = [];

          foreach ($_SESSION["entrada_cart"] as $row) {
            $iid = (int)$row["item_id"];
            $q = (int)$row["qty"];

            $vals = array_merge([$branch_id, $iid, $q], $extraVals);
            $stIns->execute($vals);

            $stChk->execute([$branch_id, $iid]);
            $exists = (bool)$stChk->fetchColumn();

            if ($exists) $stUpd->execute([$q, $branch_id, $iid]);
            else $stStockIns->execute([$branch_id, $iid, $q]);

            $receipt_lines[] = ["name" => (string)$row["name"], "qty" => $q];
          }

          $conn->commit();

          $_SESSION["last_entrada_receipt"] = [
            "id" => $receipt_id,
            "branch" => $branch_name,
            "date" => date("Y-m-d H:i:s"),
            "supplier" => $supplier,
            "lines" => $receipt_lines
          ];

          $_SESSION["entrada_cart"] = [];

          header("Location: entrada.php?print=1");
          exit;

        } catch (Throwable $e) {
          if ($conn->inTransaction()) $conn->rollBack();
          $errors[] = "Error guardando entrada: " . $e->getMessage();
        }
      }
    }
  }
}

/* =========================
   PRINT
========================= */
if (isset($_GET["print"]) && (int)$_GET["print"] === 1) {
  $r = $_SESSION["last_entrada_receipt"] ?? null;
  if (!$r) { header("Location: entrada.php"); exit; }
  ?>
  <!doctype html>
  <html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Imprimir Entrada</title>
    <style>
      body{ font-family: Arial, sans-serif; margin:0; padding:14px; }
      .t{ text-align:center; font-weight:900; }
      .small{ font-size:12px; }
      hr{ border:none; border-top:1px dashed #999; margin:10px 0; }
      table{ width:100%; border-collapse:collapse; }
      td{ padding:6px 0; font-size:13px; }
      .right{ text-align:right; }
      .btnRow{ margin-top:14px; display:flex; gap:10px; justify-content:center; }
      .btn{ padding:10px 14px; border:1px solid #222; background:#fff; border-radius:10px; font-weight:800; cursor:pointer; }
      @media print{ .btnRow{ display:none; } }
    </style>
  </head>
  <body onload="window.print()">
    <div class="t">CEVIMEP</div>
    <div class="t">ENTRADA DE INVENTARIO</div>
    <div class="small">Sucursal: <strong><?= h($r["branch"]) ?></strong></div>
    <div class="small">Fecha: <?= h($r["date"]) ?></div>
    <div class="small">Recibo: <?= h($r["id"]) ?></div>
    <?php if (!empty($r["supplier"])): ?>
      <div class="small">Suplidor: <?= h($r["supplier"]) ?></div>
    <?php endif; ?>
    <hr>
    <table>
      <?php foreach ($r["lines"] as $ln): ?>
        <tr>
          <td><?= h($ln["name"]) ?></td>
          <td class="right">x<?= (int)$ln["qty"] ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <hr>
    <div class="t small">Gracias</div>

    <div class="btnRow">
      <button class="btn" onclick="window.print()">Imprimir</button>
      <a class="btn" href="entrada.php" style="text-decoration:none; color:#000;">Volver</a>
    </div>
  </body>
  </html>
  <?php
  exit;
}

/* =========================
   HISTORIAL (últimos 50 IN)
========================= */
$history = [];
try {
  $movCols = table_columns($conn, "inventory_movements");
  $mov_branch = pick_col($movCols, ["branch_id","sucursal_id"]);
  $mov_item   = pick_col($movCols, ["item_id","product_id","inventory_item_id"]);
  $mov_qty    = pick_col($movCols, ["quantity","qty","cantidad"]);
  $mov_type   = pick_col($movCols, ["movement_type","type","mov_type","direction"]);
  $mov_date   = pick_col($movCols, ["created_at","date","fecha","created_on"]);
  $mov_note   = pick_col($movCols, ["note","notes","detalle","description"]);

  if ($mov_branch && $mov_item && $mov_qty) {
    $selDate = $mov_date ? "`$mov_date` AS mov_date" : "NULL AS mov_date";
    $selNote = $mov_note ? "`$mov_note` AS mov_note" : "NULL AS mov_note";

    $sql = "
      SELECT m.`$mov_item` AS item_id,
             m.`$mov_qty` AS qty,
             $selDate,
             $selNote,
             i.name AS item_name
      FROM inventory_movements m
      LEFT JOIN inventory_items i ON i.id = m.`$mov_item`
      WHERE m.`$mov_branch` = ?
    ";

    if ($mov_type) {
      $sql .= " AND (m.`$mov_type`='IN' OR m.`$mov_type`='entrada' OR m.`$mov_type`='ENTRADA') ";
    }

    $sql .= " ORDER BY " . ($mov_date ? "m.`$mov_date`" : "m.id") . " DESC LIMIT 50";
    $stH = $conn->prepare($sql);
    $stH->execute([$branch_id]);
    $history = $stH->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) {}

$cart = $_SESSION["entrada_cart"];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Entrada</title>

  <!-- Estilo GLOBAL del sistema (el mismo del index.php de inventario) -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=30">
  <!-- Estilo extra SOLO para inventario (seguro: solo afecta si usas inv-root) -->
  <link rel="stylesheet" href="/assets/css/inventario.css?v=3">
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

  <!-- IMPORTANTE: para aplicar el layout bonito del inventario.css (Bloque B) -->
  <main class="content inv-root inv-entrada">

    <div class="card">

      <div class="page-head-center">
        <h1>Entrada</h1>
        <div class="sub">Registra entrada de inventario (sede actual)</div>
        <div class="branch">Sucursal: <strong><?= h($branch_name) ?></strong></div>
      </div>

      <?php if (!empty($errors)): ?>
        <div class="card" style="border-color: rgba(255,80,80,.25); background: rgba(255,80,80,.06); margin-top:12px;">
          <strong style="color:#7a1010;">Revisa:</strong>
          <ul style="margin:8px 0 0 18px;">
            <?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <!-- ===== FILA SUPERIOR organizada ===== -->
      <div class="entry-top">

        <!-- Categoria (ADD FORM) -->
        <form method="post" id="addForm">
          <input type="hidden" name="action" value="add">
          <label>Categoría</label>
          <select id="category_id" name="category_id">
            <option value="">Todas</option>
            <?php foreach($categories as $c): ?>
              <option value="<?= (int)$c["id"] ?>"><?= h($c["name"]) ?></option>
            <?php endforeach; ?>
          </select>
        </form>

        <!-- Producto (ADD FORM) -->
        <div>
          <label>Producto</label>
          <select form="addForm" id="item_id" name="item_id" required>
            <option value="">Selecciona</option>
            <?php foreach($products as $p): ?>
              <option value="<?= (int)$p["id"] ?>" data-cat="<?= isset($p["category_id"]) ? (int)$p["category_id"] : 0 ?>">
                <?= h($p["name"]) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="muted2">Solo productos de esta sucursal.</div>
        </div>

        <!-- Cantidad (ADD FORM) -->
        <div>
          <label>Cantidad</label>
          <input form="addForm" type="number" name="qty" min="1" step="1" value="1" required>
        </div>

        <!-- Suplidor (SAVE FORM) -->
        <form method="post" id="saveForm">
          <input type="hidden" name="action" value="save_print">
          <label>Suplidor</label>
          <input type="text" name="supplier" placeholder="Escribe el suplidor (opcional)">
        </form>

        <!-- Hecha por (solo visual) -->
        <div>
          <label>Hecha por</label>
          <input type="text" value="<?= h($nombre) ?>" readonly>
        </div>

        <!-- Botón Añadir -->
        <div class="entry-actions">
          <button class="btn" type="submit" form="addForm">Añadir</button>
        </div>

      </div>
    </div>

    <!-- Detalle -->
    <div class="card section-card">
      <div class="section-head">
        <div>
          <h3>Detalle</h3>
          <p class="muted">Productos agregados</p>
        </div>

        <form method="post" style="margin:0;">
          <input type="hidden" name="action" value="clear">
          <button class="btn" type="submit">Vaciar</button>
        </form>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:70px;">#</th>
              <th>Producto</th>
              <th style="width:140px;">Cantidad</th>
              <th style="width:140px;">Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($cart)): ?>
              <tr><td colspan="4" style="text-align:center; padding:18px;">No hay productos agregados.</td></tr>
            <?php else: ?>
              <?php $i=1; foreach($cart as $row): ?>
                <tr>
                  <td><?= $i++ ?></td>
                  <td style="text-align:left; font-weight:800;"><?= h($row["name"]) ?></td>
                  <td><?= (int)$row["qty"] ?></td>
                  <td>
                    <form method="post" style="display:inline;">
                      <input type="hidden" name="action" value="remove">
                      <input type="hidden" name="remove_id" value="<?= (int)$row["item_id"] ?>">
                      <button class="btn btn-sm" type="submit">Quitar</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="save-row">
        <div></div>
        <button class="btn btn-primary" type="submit" form="saveForm">Guardar e imprimir</button>
      </div>
    </div>

    <!-- Historial -->
    <div class="card section-card">
      <div class="section-head">
        <div>
          <h3>Historial de Entradas</h3>
          <p class="muted">Últimos 50 registros (sede actual)</p>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Producto</th>
              <th style="width:120px;">Cantidad</th>
              <th style="width:200px;">Recibo / Nota</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($history)): ?>
              <tr><td colspan="4" style="text-align:center; padding:18px;">No hay registros.</td></tr>
            <?php else: ?>
              <?php foreach($history as $r): ?>
                <tr>
                  <td><?= h($r["mov_date"] ?? "") ?></td>
                  <td style="text-align:left; font-weight:800;"><?= h($r["item_name"] ?? "") ?></td>
                  <td><?= (int)($r["qty"] ?? 0) ?></td>
                  <td><?= h($r["mov_note"] ?? "") ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>
</div>

<div class="footer">
  <div class="inner">
    © <?= (int)date("Y") ?> CEVIMEP. Todos los derechos reservados.
  </div>
</div>

<script>
  // Filtro rápido por categoría (front-end)
  (function(){
    const cat = document.getElementById('category_id');
    const item = document.getElementById('item_id');
    if(!cat || !item) return;

    function apply(){
      const c = parseInt(cat.value || "0", 10);
      [...item.options].forEach(opt=>{
        if(!opt.value) return;
        const oc = parseInt(opt.getAttribute('data-cat') || "0", 10);
        opt.style.display = (!c || oc === c) ? "" : "none";
      });
      if(item.selectedOptions.length && item.selectedOptions[0].style.display === "none"){
        item.value = "";
      }
    }
    cat.addEventListener('change', apply);
    apply();
  })();
</script>

</body>
</html>
