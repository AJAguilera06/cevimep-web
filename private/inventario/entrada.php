<?php
declare(strict_types=1);

require_once __DIR__ . '/../_guard.php';
$conn = $pdo;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function cols(PDO $conn, string $table): array {
  $out = [];
  try {
    $st = $conn->query("SHOW COLUMNS FROM `$table`");
    if ($st) {
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = $r['Field'];
    }
  } catch (Throwable $e) {}
  return $out;
}
function has(array $cols, string $c): bool { return in_array($c, $cols, true); }
function pick(array $cols, array $candidates): ?string {
  foreach ($candidates as $c) if (in_array($c, $cols, true)) return $c;
  return null;
}

$user = $_SESSION['user'] ?? [];
$branch_id = (int)($user['branch_id'] ?? 0);
$year = (int)date('Y');

if ($branch_id <= 0) { die("Sucursal inválida (branch_id)."); }

/* Sucursal name */
$branch_name = (string)($user['branch_name'] ?? '');
if ($branch_name === '') {
  try {
    $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
    $stB->execute([$branch_id]);
    $branch_name = (string)($stB->fetchColumn() ?: 'Sucursal');
  } catch (Throwable $e) {
    $branch_name = 'Sucursal';
  }
}

/* CSS específico para inventario */
$cssInventario = "/assets/css/inventario.css?v=1";

/* ====== Estado / carrito ====== */
if (!isset($_SESSION['entrada_cart']) || !is_array($_SESSION['entrada_cart'])) {
  $_SESSION['entrada_cart'] = [];
}
$cart = &$_SESSION['entrada_cart'];

$flash_success = (string)($_SESSION['flash_success'] ?? '');
$flash_error = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

/* ====== Cargar categorías ====== */
$categories = [];
try {
  $stC = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
  $categories = $stC ? $stC->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {}

/* Filtro de categoría */
$category_id = (int)($_GET['category_id'] ?? 0);

/* ====== Productos SOLO de la sucursal (por stock) ====== */
$products = [];
try {
  $sql = "
    SELECT i.id, i.name, i.category_id
    FROM inventory_items i
    INNER JOIN inventory_stock s ON s.item_id = i.id AND s.branch_id = ?
    WHERE (i.is_active = 1 OR i.is_active IS NULL)
  ";
  $params = [$branch_id];

  if ($category_id > 0) {
    $sql .= " AND i.category_id = ? ";
    $params[] = $category_id;
  }

  $sql .= " ORDER BY i.name ASC";
  $stP = $conn->prepare($sql);
  $stP->execute($params);
  $products = $stP->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* ====== Acciones POST ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  /* Añadir al carrito */
  if ($action === 'add_item') {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $qty = (int)($_POST['qty'] ?? 0);
    $supplier = trim((string)($_POST['supplier'] ?? ''));

    if ($product_id <= 0) {
      $_SESSION['flash_error'] = "Selecciona un producto.";
      header("Location: entrada.php?category_id=" . $category_id);
      exit;
    }
    if ($qty <= 0) {
      $_SESSION['flash_error'] = "La cantidad debe ser mayor que 0.";
      header("Location: entrada.php?category_id=" . $category_id);
      exit;
    }

    // validar que el producto pertenece a esta sucursal
    $st = $conn->prepare("
      SELECT i.id, i.name
      FROM inventory_items i
      INNER JOIN inventory_stock s ON s.item_id = i.id AND s.branch_id = ?
      WHERE i.id = ?
      LIMIT 1
    ");
    $st->execute([$branch_id, $product_id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);

    if (!$p) {
      $_SESSION['flash_error'] = "Ese producto no pertenece a esta sucursal.";
      header("Location: entrada.php?category_id=" . $category_id);
      exit;
    }

    // si ya existe, suma
    if (!isset($cart[$product_id])) {
      $cart[$product_id] = [
        'item_id'   => $product_id,
        'name'      => (string)$p['name'],
        'qty'       => $qty,
        'supplier'  => $supplier,
      ];
    } else {
      $cart[$product_id]['qty'] += $qty;
      // conserva el suplidor si mandan uno nuevo
      if ($supplier !== '') $cart[$product_id]['supplier'] = $supplier;
    }

    $_SESSION['flash_success'] = "✅ Producto añadido.";
    header("Location: entrada.php?category_id=" . $category_id);
    exit;
  }

  /* Vaciar carrito */
  if ($action === 'clear') {
    $_SESSION['entrada_cart'] = [];
    $_SESSION['flash_success'] = "Carrito vaciado.";
    header("Location: entrada.php?category_id=" . $category_id);
    exit;
  }

  /* Quitar 1 item */
  if ($action === 'remove') {
    $rid = (int)($_POST['remove_id'] ?? 0);
    if ($rid > 0 && isset($cart[$rid])) unset($cart[$rid]);
    $_SESSION['flash_success'] = "Producto removido.";
    header("Location: entrada.php?category_id=" . $category_id);
    exit;
  }

  /* Guardar e imprimir */
  if ($action === 'save_print') {
    if (empty($cart)) {
      $_SESSION['flash_error'] = "No hay productos agregados.";
      header("Location: entrada.php?category_id=" . $category_id);
      exit;
    }

    $supplier_global = trim((string)($_POST['supplier_global'] ?? '')); // opcional

    // Detectar columnas para INSERT/UPDATE adaptativo
    $movCols = cols($conn, "inventory_movements");
    $stockCols = cols($conn, "inventory_stock");

    $mov_branch = pick($movCols, ['branch_id', 'sucursal_id']);
    $mov_item   = pick($movCols, ['item_id', 'product_id', 'inventory_item_id']);
    $mov_qty    = pick($movCols, ['quantity', 'qty', 'cantidad']);
    $mov_type   = pick($movCols, ['movement_type', 'type', 'mov_type', 'direction']);
    $mov_sup    = pick($movCols, ['supplier', 'suplidor', 'provider']);
    $mov_user   = pick($movCols, ['created_by', 'user_id', 'made_by']);
    $mov_date   = pick($movCols, ['created_at', 'date', 'fecha', 'created_on']);
    $mov_note   = pick($movCols, ['note', 'notes', 'detalle', 'description']);

    $stk_branch = pick($stockCols, ['branch_id', 'sucursal_id']);
    $stk_item   = pick($stockCols, ['item_id', 'product_id', 'inventory_item_id']);
    $stk_qty    = pick($stockCols, ['quantity', 'qty', 'stock', 'existencia']);

    if (!$mov_branch || !$mov_item || !$mov_qty) {
      $_SESSION['flash_error'] = "Tu tabla inventory_movements no tiene columnas básicas (branch_id/item_id/qty).";
      header("Location: entrada.php?category_id=" . $category_id);
      exit;
    }
    if (!$stk_branch || !$stk_item || !$stk_qty) {
      $_SESSION['flash_error'] = "Tu tabla inventory_stock no tiene columnas básicas (branch_id/item_id/qty).";
      header("Location: entrada.php?category_id=" . $category_id);
      exit;
    }

    $receipt_id = "ENT-" . date("Ymd-His") . "-" . $branch_id;

    try {
      $conn->beginTransaction();

      // preparar inserts movimientos
      $movFields = [];
      if ($mov_type)   $movFields[$mov_type] = 'IN';
      if ($mov_sup)    $movFields[$mov_sup] = $supplier_global;
      if ($mov_user)   $movFields[$mov_user] = (int)($user['id'] ?? 0);
      if ($mov_date)   $movFields[$mov_date] = date("Y-m-d H:i:s");
      if ($mov_note)   $movFields[$mov_note] = $receipt_id;

      $movInsertCols = array_merge([$mov_branch, $mov_item, $mov_qty], array_keys($movFields));
      $place = implode(",", array_fill(0, count($movInsertCols), "?"));

      $sqlIns = "INSERT INTO inventory_movements (`" . implode("`,`", $movInsertCols) . "`) VALUES ($place)";
      $stIns = $conn->prepare($sqlIns);

      // update stock
      $sqlUpd = "UPDATE inventory_stock SET `$stk_qty` = `$stk_qty` + ? WHERE `$stk_branch`=? AND `$stk_item`=?";
      $stUpd = $conn->prepare($sqlUpd);

      // si no existe stock, insertar fila (para items nuevos en esa sucursal)
      $sqlChk = "SELECT 1 FROM inventory_stock WHERE `$stk_branch`=? AND `$stk_item`=? LIMIT 1";
      $stChk = $conn->prepare($sqlChk);

      $sqlStockIns = "INSERT INTO inventory_stock (`$stk_branch`,`$stk_item`,`$stk_qty`) VALUES (?,?,?)";
      $stStockIns = $conn->prepare($sqlStockIns);

      $receipt_lines = [];

      foreach ($cart as $row) {
        $item_id = (int)$row['item_id'];
        $qty = (int)$row['qty'];
        $supLine = trim((string)($row['supplier'] ?? ''));
        $supplier_used = $supplier_global !== '' ? $supplier_global : $supLine;

        $values = [];
        $values[] = $branch_id;
        $values[] = $item_id;
        $values[] = $qty;

        $dyn = $movFields;
        if ($mov_sup) $dyn[$mov_sup] = $supplier_used;
        foreach ($dyn as $v) $values[] = $v;

        $stIns->execute($values);

        // stock
        $stChk->execute([$branch_id, $item_id]);
        $exists = (bool)$stChk->fetchColumn();

        if ($exists) {
          $stUpd->execute([$qty, $branch_id, $item_id]);
        } else {
          $stStockIns->execute([$branch_id, $item_id, $qty]);
        }

        $receipt_lines[] = [
          'name' => (string)$row['name'],
          'qty'  => $qty
        ];
      }

      $conn->commit();

      // guardar recibo en sesión para imprimir
      $_SESSION['last_entrada_receipt'] = [
        'id' => $receipt_id,
        'branch' => $branch_name,
        'date' => date("Y-m-d H:i:s"),
        'supplier' => $supplier_global,
        'lines' => $receipt_lines,
      ];

      // vaciar carrito
      $_SESSION['entrada_cart'] = [];

      header("Location: entrada.php?print=1");
      exit;

    } catch (Throwable $e) {
      if ($conn->inTransaction()) $conn->rollBack();
      $_SESSION['flash_error'] = "Error guardando entrada: " . $e->getMessage();
      header("Location: entrada.php?category_id=" . $category_id);
      exit;
    }
  }
}

/* ====== Imprimir (modo print) ====== */
if (isset($_GET['print']) && (int)$_GET['print'] === 1) {
  $r = $_SESSION['last_entrada_receipt'] ?? null;
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
      @media print{
        .btnRow{ display:none; }
      }
    </style>
  </head>
  <body onload="window.print()">
    <div class="t">CEVIMEP</div>
    <div class="t">ENTRADA DE INVENTARIO</div>
    <div class="small">Sucursal: <strong><?= h($r['branch']) ?></strong></div>
    <div class="small">Fecha: <?= h($r['date']) ?></div>
    <div class="small">Recibo: <?= h($r['id']) ?></div>
    <?php if (!empty($r['supplier'])): ?>
      <div class="small">Suplidor: <?= h($r['supplier']) ?></div>
    <?php endif; ?>
    <hr>
    <table>
      <?php foreach ($r['lines'] as $ln): ?>
        <tr>
          <td><?= h($ln['name']) ?></td>
          <td class="right">x<?= (int)$ln['qty'] ?></td>
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

/* ====== Historial (últimos 50 IN) ====== */
$history = [];
try {
  $movCols = cols($conn, "inventory_movements");
  $mov_branch = pick($movCols, ['branch_id', 'sucursal_id']);
  $mov_item   = pick($movCols, ['item_id', 'product_id', 'inventory_item_id']);
  $mov_qty    = pick($movCols, ['quantity', 'qty', 'cantidad']);
  $mov_type   = pick($movCols, ['movement_type', 'type', 'mov_type', 'direction']);
  $mov_date   = pick($movCols, ['created_at', 'date', 'fecha', 'created_on']);
  $mov_note   = pick($movCols, ['note', 'notes', 'detalle', 'description']);

  if ($mov_branch && $mov_item && $mov_qty) {
    $selDate = $mov_date ? "`$mov_date` AS mov_date" : "NULL AS mov_date";
    $selType = $mov_type ? "`$mov_type` AS mov_type" : "NULL AS mov_type";
    $selNote = $mov_note ? "`$mov_note` AS mov_note" : "NULL AS mov_note";

    $sql = "
      SELECT m.`$mov_item` AS item_id,
             m.`$mov_qty` AS qty,
             $selDate,
             $selType,
             $selNote,
             i.name AS item_name
      FROM inventory_movements m
      LEFT JOIN inventory_items i ON i.id = m.`$mov_item`
      WHERE m.`$mov_branch` = ?
    ";

    // filtrar solo entradas
    if ($mov_type) {
      $sql .= " AND (m.`$mov_type`='IN' OR m.`$mov_type`='entrada' OR m.`$mov_type`='ENTRADA') ";
    }

    $sql .= " ORDER BY " . ($mov_date ? "m.`$mov_date`" : "m.id") . " DESC LIMIT 50";
    $stH = $conn->prepare($sql);
    $stH->execute([$branch_id]);
    $history = $stH->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) {}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Entrada</title>
  <link rel="stylesheet" href="<?= h($cssInventario) ?>">
</head>

<body class="cevimep-ui">

<div class="wrap">

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="logo">
      <div style="font-weight:900; font-size:18px;">CEVIMEP</div>
      <div style="opacity:.9; font-size:12px; margin-top:6px;"><?= h($branch_name) ?></div>
    </div>

    <a href="/private/dashboard.php">🏠 Panel</a>
    <a href="/private/patients/index.php">👤 Pacientes</a>
    <a href="/private/citas/index.php">📅 Citas</a>
    <a href="/private/facturacion/index.php">🧾 Facturación</a>
    <a href="/private/caja/index.php">💳 Caja</a>
    <a class="active" href="/private/inventario/entrada.php">📦 Inventario - Entrada</a>
    <a href="/private/inventario/salida.php">📤 Inventario - Salida</a>
    <a href="/private/inventario/items.php">📋 Productos</a>
  </aside>

  <!-- Main -->
  <main class="main">

    <div class="topbar">
      <h1 class="title">Entrada de Inventario</h1>
      <a href="/logout.php" class="btn btn-secondary" style="text-decoration:none;">Salir</a>
    </div>

    <?php if ($flash_success): ?>
      <div class="card" style="border-left:6px solid #198754;">
        <strong><?= h($flash_success) ?></strong>
      </div>
    <?php endif; ?>

    <?php if ($flash_error): ?>
      <div class="card" style="border-left:6px solid #dc3545;">
        <strong><?= h($flash_error) ?></strong>
      </div>
    <?php endif; ?>

    <!-- Card Form -->
    <section class="card">
      <h2 style="margin:0 0 8px 0;">Entrada</h2>
      <div style="font-weight:800; color:#555; margin-bottom:14px;">
        Registra entrada de inventario (sede actual) — <strong><?= h($branch_name) ?></strong>
      </div>

      <form method="get" class="grid" style="margin-bottom:14px;">
        <div class="col-6">
          <label>Categoría</label>
          <select name="category_id" onchange="this.form.submit()">
            <option value="0">Todas</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ($category_id === (int)$c['id']) ? 'selected' : '' ?>>
                <?= h($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6" style="display:flex; align-items:end; gap:10px;">
          <div style="flex:1;"></div>
          <a class="btn btn-secondary" href="entrada.php" style="text-decoration:none; align-self:end;">Reset</a>
        </div>
      </form>

      <form method="post" class="grid">
        <input type="hidden" name="action" value="add_item">

        <div class="col-6">
          <label>Producto</label>
          <select name="product_id" required>
            <option value="">Selecciona</option>
            <?php foreach ($products as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div style="font-size:12px; margin-top:6px; opacity:.75;">
            Solo productos de esta sucursal.
          </div>
        </div>

        <div class="col-3">
          <label>Cantidad</label>
          <input type="number" name="qty" min="1" value="1" required>
        </div>

        <div class="col-3">
          <label>Suplidor (opcional)</label>
          <input type="text" name="supplier" placeholder="Escribe el suplidor (opcional)">
        </div>

        <div class="col-12" style="display:flex; justify-content:flex-end; gap:10px;">
          <button class="btn btn-primary" type="submit">Añadir</button>
        </div>
      </form>
    </section>

    <!-- Detalle -->
    <section class="card">
      <h3 style="margin:0 0 12px 0;">Detalle</h3>

      <div class="table-wrap">
        <table>
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
              <?php $i=1; foreach ($cart as $row): ?>
                <tr>
                  <td><?= $i++ ?></td>
                  <td style="text-align:left; font-weight:800;"><?= h($row['name']) ?></td>
                  <td><?= (int)$row['qty'] ?></td>
                  <td>
                    <form method="post" style="display:inline;">
                      <input type="hidden" name="action" value="remove">
                      <input type="hidden" name="remove_id" value="<?= (int)$row['item_id'] ?>">
                      <button class="btn btn-secondary" type="submit">Quitar</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:14px; flex-wrap:wrap;">
        <form method="post">
          <input type="hidden" name="action" value="clear">
          <button class="btn btn-secondary" type="submit">Vaciar</button>
        </form>

        <form method="post" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
          <input type="hidden" name="action" value="save_print">
          <div>
            <label style="margin:0 0 6px 0;">Suplidor global (opcional)</label>
            <input type="text" name="supplier_global" placeholder="Aplica a todos (opcional)">
          </div>
          <button class="btn btn-primary" type="submit">Guardar e imprimir</button>
        </form>
      </div>
    </section>

    <!-- Historial -->
    <section class="card">
      <h3 style="margin:0 0 8px 0;">Historial de Entradas</h3>
      <div style="opacity:.8; margin-bottom:12px;">Últimos 50 registros (sede actual)</div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Producto</th>
              <th style="width:120px;">Cantidad</th>
              <th style="width:180px;">Recibo / Nota</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($history)): ?>
              <tr><td colspan="4" style="text-align:center; padding:18px;">No hay registros.</td></tr>
            <?php else: ?>
              <?php foreach ($history as $r): ?>
                <tr>
                  <td><?= h($r['mov_date'] ?? '') ?></td>
                  <td style="text-align:left; font-weight:800;"><?= h($r['item_name'] ?? '') ?></td>
                  <td><?= (int)($r['qty'] ?? 0) ?></td>
                  <td><?= h($r['mov_note'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <div style="text-align:center; opacity:.75; font-size:12px; margin-top:14px;">
      © <?= $year ?> CEVIMEP. Todos los derechos reservados.
    </div>

  </main>
</div>

</body>
</html>
