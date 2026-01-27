<?php
declare(strict_types=1);
require_once __DIR__ . "/../_guard.php";

$conn = $pdo;

$user = $_SESSION["user"] ?? [];
$branch_id = (int)($user["branch_id"] ?? 0);
$nombre = (string)($user["full_name"] ?? "Usuario");

$branch_name = "CEVIMEP";
if ($branch_id > 0) {
  try {
    $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
    $stB->execute([$branch_id]);
    $bn = $stB->fetchColumn();
    if ($bn) $branch_name = (string)$bn;
  } catch (Throwable $e) {}
}

if (!isset($_SESSION["entrada_cart"]) || !is_array($_SESSION["entrada_cart"])) {
  $_SESSION["entrada_cart"] = [];
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }

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

$errors = [];

/* =========================
   CATEGORÍAS
========================= */
$categories = [];
try {
  $stC = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
  $categories = $stC ? $stC->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {}

/* =========================
   PRODUCTOS (solo sucursal actual)
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
   ACTIONS
========================= */
$action = $_POST["action"] ?? "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  /* Añadir */
  if ($action === "add") {
    $item_id = (int)($_POST["item_id"] ?? 0);
    $qty = (int)($_POST["qty"] ?? 0);

    if ($item_id <= 0) $errors[] = "Selecciona un producto.";
    if ($qty <= 0) $errors[] = "La cantidad debe ser mayor que 0.";

    if (!$errors) {
      // valida que pertenece a la sucursal actual
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
        if (!isset($_SESSION["entrada_cart"][$item_id])) {
          $_SESSION["entrada_cart"][$item_id] = [
            "item_id" => $item_id,
            "name" => (string)$p["name"],
            "qty" => $qty
          ];
        } else {
          $_SESSION["entrada_cart"][$item_id]["qty"] += $qty;
        }
      }
    }
  }

  /* Vaciar */
  if ($action === "clear") {
    $_SESSION["entrada_cart"] = [];
    header("Location: entrada.php");
    exit;
  }

  /* Quitar */
  if ($action === "remove") {
    $rid = (int)($_POST["remove_id"] ?? 0);
    if ($rid > 0 && isset($_SESSION["entrada_cart"][$rid])) {
      unset($_SESSION["entrada_cart"][$rid]);
    }
    header("Location: entrada.php");
    exit;
  }

  /* Guardar + mostrar ACUSE */
  if ($action === "save_print") {

    $supplier = trim((string)($_POST["supplier"] ?? ""));
    $made_by  = trim((string)($_POST["made_by"] ?? "")); // vacío para llenar
    $destino  = trim((string)($_POST["destino"] ?? $branch_name)); // sucursal iniciada
    $note     = trim((string)($_POST["note"] ?? ""));
    $entry_date = trim((string)($_POST["entry_date"] ?? "")); // YYYY-MM-DD (opcional)

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

          // Armado de insert dinámico
          $baseCols = [$mov_branch, $mov_item, $mov_qty];
          $extraCols = [];
          $extraVals = [];

          if ($mov_type) { $extraCols[] = $mov_type; $extraVals[] = "IN"; }
          if ($mov_sup)  { $extraCols[] = $mov_sup;  $extraVals[] = $supplier; }
          if ($mov_user) { $extraCols[] = $mov_user; $extraVals[] = ($made_by !== "" ? $made_by : (int)($user["id"] ?? 0)); }

          if ($mov_date) {
            $extraCols[] = $mov_date;
            $dt = date("Y-m-d H:i:s");
            if ($entry_date !== "" && preg_match("/^\\d{4}-\\d{2}-\\d{2}$/", $entry_date)) {
              $dt = $entry_date . " " . date("H:i:s");
            }
            $extraVals[] = $dt;
          }

          if ($mov_note) {
            $extraCols[] = $mov_note;
            $noteTxt = $receipt_id . " | Destino: " . $destino;
            if ($note !== "") $noteTxt .= " | Nota: " . $note;
            $extraVals[] = $noteTxt;
          }

          $insCols = array_merge($baseCols, $extraCols);
          $ph = implode(",", array_fill(0, count($insCols), "?"));
          $sqlIns = "INSERT INTO inventory_movements (`" . implode("`,`", $insCols) . "`) VALUES ($ph)";
          $stIns = $conn->prepare($sqlIns);

          // Stock upsert
          $stChk = $conn->prepare("SELECT 1 FROM inventory_stock WHERE `$stk_branch`=? AND `$stk_item`=? LIMIT 1");
          $stUpd = $conn->prepare("UPDATE inventory_stock SET `$stk_qty` = `$stk_qty` + ? WHERE `$stk_branch`=? AND `$stk_item`=?");
          $stStockIns = $conn->prepare("INSERT INTO inventory_stock (`$stk_branch`,`$stk_item`,`$stk_qty`) VALUES (?,?,?)");

          $receipt_lines = [];

          foreach ($_SESSION["entrada_cart"] as $row) {
            $iid = (int)$row["item_id"];
            $q = (int)$row["qty"];

            // movimiento
            $vals = array_merge([$branch_id, $iid, $q], $extraVals);
            $stIns->execute($vals);

            // stock
            $stChk->execute([$branch_id, $iid]);
            $exists = (bool)$stChk->fetchColumn();
            if ($exists) $stUpd->execute([$q, $branch_id, $iid]);
            else $stStockIns->execute([$branch_id, $iid, $q]);

            $receipt_lines[] = ["name" => (string)$row["name"], "qty" => $q];
          }

          $conn->commit();

          // Guardar acuse en sesión
          $_SESSION["last_entrada_receipt"] = [
            "id" => $receipt_id,
            "branch" => $branch_name,
            "destino" => $destino,
            "date" => date("Y-m-d H:i:s"),
            "supplier" => $supplier,
            "made_by" => $made_by,
            "lines" => $receipt_lines
          ];

          // limpiar carrito
          $_SESSION["entrada_cart"] = [];

          // mostrar acuse en misma pantalla
          header("Location: entrada.php?acuse=1");
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
      SELECT $selDate, $selNote,
             i.name AS item_name,
             m.`$mov_qty` AS qty
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

/* ===== Forzar que lo último guardado aparezca inmediatamente ===== */
if (isset($_SESSION["last_entrada_receipt"]) && is_array($_SESSION["last_entrada_receipt"])) {
  $r = $_SESSION["last_entrada_receipt"];
  $rid = (string)($r["id"] ?? "");
  $found = false;

  foreach ($history as $hrow) {
    if (!empty($hrow["mov_note"]) && strpos((string)$hrow["mov_note"], $rid) !== false) {
      $found = true;
      break;
    }
  }

  if (!$found && $rid !== "") {
    $fakeRows = [];
    foreach (($r["lines"] ?? []) as $ln) {
      $fakeRows[] = [
        "mov_date"  => $r["date"] ?? date("Y-m-d H:i:s"),
        "item_name" => $ln["name"] ?? "",
        "qty"       => $ln["qty"] ?? 0,
        "mov_note"  => $rid . " | Destino: " . ($r["destino"] ?? "")
      ];
    }
    $history = array_merge($fakeRows, $history);
  }
}

$cart = $_SESSION["entrada_cart"];

// Mapas para mostrar categoría en el detalle
$catMap = [];
foreach ($categories as $c) { $catMap[(int)$c["id"]] = (string)$c["name"]; }
$prodCatMap = [];
foreach ($products as $p) { $prodCatMap[(int)$p["id"]] = (int)($p["category_id"] ?? 0); }

$acuse = (isset($_GET["acuse"]) && (int)$_GET["acuse"] === 1) ? ($_SESSION["last_entrada_receipt"] ?? null) : null;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Entrada | CEVIMEP</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=60">
  <link rel="stylesheet" href="/assets/css/inventario.css?v=60">
</head>
<body>

<header class="navbar">
  <div class="inner">
    <div class="brand"><span class="dot"></span><span>CEVIMEP</span></div>
    <div class="nav-right"><a href="/logout.php" class="btn-pill">Salir</a></div>
  </div>
</header>

<div class="layout">
  <aside class="sidebar">
    <div class="menu-title">Menú</div>
    <nav class="menu">
      <a href="/private/dashboard.php">🏠 Panel</a>
      <a href="/private/patients/index.php">👤 Pacientes</a>
      <a href="/private/citas/index.php">📅 Citas</a>
      <a href="/private/facturacion/index.php">🧾 Facturación</a>
      <a href="/private/caja/index.php">💳 Caja</a>
      <a class="active" href="/private/inventario/index.php">📦 Inventario</a>
      <a href="/private/estadistica/index.php">📊 Estadísticas</a>
    </nav>
  </aside>

  <main class="content inv-root inv-entrada">
    <div class="inv-wrap">

      <div class="inv-head">
        <h1>Entrada</h1>
        <div class="sub">Registra entrada de inventario (sede actual)</div>
        <div class="branch"><?= h($branch_name) ?></div>
      </div>

      <?php if (!empty($errors)): ?>
        <div class="inv-card" style="border:1px solid rgba(255,0,0,.15); background: rgba(255,0,0,.04);">
          <strong style="color:#7a1010;">Revisa:</strong>
          <ul style="margin:8px 0 0 18px;">
            <?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <!-- ACUSE (aparece solo si ?acuse=1) -->
      <?php if ($acuse): ?>
        <div class="inv-card acuse">
          <div class="section-head">
            <div>
              <h3>Acuse de Entrada</h3>
              <p class="muted">Recibo listo para imprimir</p>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
              <button class="btn-action" type="button" onclick="window.print()">🖨️ Imprimir</button>
              <a class="btn-action btn-ghost" href="entrada.php" style="text-decoration:none;">Cerrar</a>
            </div>
          </div>

          <div class="acuse row">
            <div><div class="k">Recibo</div><div class="v"><?= h($acuse["id"] ?? "") ?></div></div>
            <div><div class="k">Fecha</div><div class="v"><?= h($acuse["date"] ?? "") ?></div></div>
            <div><div class="k">Destino</div><div class="v"><?= h($acuse["destino"] ?? "") ?></div></div>
            <div><div class="k">Suplidor</div><div class="v"><?= h($acuse["supplier"] ?? "") ?></div></div>
            <div><div class="k">Hecha por</div><div class="v"><?= h($acuse["made_by"] ?? "") ?></div></div>
          </div>

          <div class="table-wrap" style="margin-top:12px;">
            <table>
              <thead>
                <tr>
                  <th>Producto</th>
                  <th style="width:140px;">Cantidad</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (($acuse["lines"] ?? []) as $ln): ?>
                  <tr>
                    <td><?= h($ln["name"] ?? "") ?></td>
                    <td><?= (int)($ln["qty"] ?? 0) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

      <!-- FORMULARIO (como la imagen) -->
      <div class="inv-card">
        <div class="entry-card-title">Entrada</div>
        <div class="entry-card-sub">Registra entrada de inventario (sede actual)</div>

        <!-- save -->
        <form method="post" id="saveForm">
          <input type="hidden" name="action" value="save_print">
          <input type="hidden" name="destino" value="<?= h($branch_name) ?>">
          <input type="hidden" name="made_by" value="">
        </form>

        <!-- add -->
        <form method="post" id="addForm">
          <input type="hidden" name="action" value="add">
        </form>

        <!-- GRID SUPERIOR -->
        <div class="entry-grid">
          <div>
            <label>Fecha</label>
            <input form="saveForm" type="date" name="entry_date" value="<?= h(date("Y-m-d")) ?>">
          </div>

          <div>
            <label>Suplidor</label>
            <input form="saveForm" type="text" name="supplier" value="<?= h("Almacén " . $branch_name) ?>">
          </div>

          <div>
            <label>Área de destino</label>
            <input type="text" value="<?= h($branch_name) ?>" readonly>
          </div>

          <div>
            <label>Nota (opcional)</label>
            <input form="saveForm" type="text" name="note" placeholder="Observación...">
          </div>
        </div>

        <!-- FILA: categoría / producto / cantidad / añadir -->
        <div class="row-3" style="margin-top:18px">
          <div>
            <label>Categoría</label>
            <select form="addForm" id="category_id" name="category_id">
              <option value="">— Todas —</option>
              <?php foreach($categories as $c): ?>
                <option value="<?= (int)$c["id"] ?>"><?= h($c["name"]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label>Producto</label>
            <select form="addForm" id="item_id" name="item_id" required>
              <option value="">— Seleccionar —</option>
              <?php foreach($products as $p): ?>
                <option value="<?= (int)$p["id"] ?>" data-cat="<?= (int)($p["category_id"] ?? 0) ?>">
                  <?= h($p["name"]) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="hint-small">Al añadir, se mantiene seleccionado el producto y la cantidad.</div>
          </div>

          <div>
            <label>Cantidad</label>
            <input form="addForm" type="number" name="qty" min="1" step="1" value="1" required>
          </div>

          <div class="entry-actions">
            <button class="btn-action btn-primary" type="submit" form="addForm">Añadir</button>
          </div>
        </div>

        <!-- DETALLE EN LA MISMA TARJETA -->
        <div class="table-wrap" style="margin-top:16px;">
          <table>
            <thead>
              <tr>
                <th>Categoría</th>
                <th>Producto</th>
                <th style="width:140px;">Cantidad</th>
                <th style="width:140px;">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($cart)): ?>
                <tr><td colspan="4" style="text-align:center; padding:18px;">No hay productos agregados.</td></tr>
              <?php else: ?>
                <?php foreach($cart as $row): ?>
                  <?php
                    $iid = (int)$row["item_id"];
                    $cid = (int)($prodCatMap[$iid] ?? 0);
                    $cname = $cid > 0 ? ($catMap[$cid] ?? "") : "";
                  ?>
                  <tr>
                    <td><?= h($cname) ?></td>
                    <td style="font-weight:900;"><?= h($row["name"]) ?></td>
                    <td><?= (int)$row["qty"] ?></td>
                    <td>
                      <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="remove_id" value="<?= (int)$row["item_id"] ?>">
                        <button class="btn-action btn-ghost" type="submit">Quitar</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="save-row" style="gap:10px;">
          <form method="post" style="margin:0;">
            <input type="hidden" name="action" value="clear">
            <button class="btn-action btn-ghost" type="submit">Vaciar</button>
          </form>
          <button id="btnSave" class="btn-action" type="submit" form="saveForm">Guardar e Imprimir</button>
        </div>
      </div>

      <!-- HISTORIAL (colapsable) -->
      <div class="inv-card" style="margin-top:16px;">
        <div class="section-head">
          <div>
            <h3>Historial de Entradas</h3>
            <p class="muted">Últimos 50 registros (sede actual)</p>
          </div>

          <button class="btn-action btn-ghost" type="button"
            onclick="document.getElementById('histBody').classList.toggle('show')">
            Ver el historial
          </button>
        </div>

        <div id="histBody" class="hist-body">
          <div class="table-wrap" style="margin-top:12px;">
            <table>
              <thead>
                <tr>
                  <th style="width:230px;">Fecha</th>
                  <th>Producto</th>
                  <th style="width:120px;">Cantidad</th>
                  <th style="width:260px;">Recibo / Nota</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($history)): ?>
                  <tr><td colspan="4" style="text-align:center; padding:18px;">No hay registros.</td></tr>
                <?php else: ?>
                  <?php foreach($history as $r): ?>
                    <tr>
                      <td><?= h($r["mov_date"] ?? "") ?></td>
                      <td style="font-weight:900;"><?= h($r["item_name"] ?? "") ?></td>
                      <td><?= (int)($r["qty"] ?? 0) ?></td>
                      <td><?= h($r["mov_note"] ?? "") ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </main>
</div>

<footer class="footer">
  © <?= (int)date("Y") ?> CEVIMEP. Todos los derechos reservados.
</footer>

<script>
  // Filtro front-end por categoría
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
      if(item.value){
        const sel = item.selectedOptions[0];
        if(sel && sel.style.display === "none") item.value = "";
      }
    }
    cat.addEventListener('change', apply);
    apply();
  })();

  // Evitar doble click Guardar
  (function(){
    const btn = document.getElementById('btnSave');
    const form = document.getElementById('saveForm');
    if(!btn || !form) return;
    form.addEventListener('submit', function(){
      btn.disabled = true;
      btn.textContent = 'Guardando...';
    });
  })();
</script>

</body>
</html>
