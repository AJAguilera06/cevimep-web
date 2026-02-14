<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";

// si existe tu librería de caja, la mantenemos
$maybeCajaLib = __DIR__ . "/../caja/caja_lib.php";
if (file_exists($maybeCajaLib)) {
  require_once $maybeCajaLib;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// Evitar cache del navegador/proxy y minimizar que PHP sirva una versión vieja
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if (function_exists('opcache_invalidate')) { @opcache_invalidate(__FILE__, true); }

$conn = $pdo;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

function columnExists(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$column]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return false;
  }
}

function tableExists(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function tableColumns(PDO $pdo, string $table): array {
  try {
    $st = $pdo->query("DESCRIBE `$table`");
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    $cols = [];
    foreach ($rows as $r) $cols[] = (string)($r["Field"] ?? "");
    return array_values(array_filter($cols));
  } catch (Throwable $e) {
    return [];
  }
}

function number0($v): float {
  if ($v === null) return 0.0;
  $s = trim((string)$v);
  if ($s === "") return 0.0;
  $s = str_replace(["RD$", ",", " "], ["", "", ""], $s);
  return (float)$s;
}

/* ===============================
   Sesión / contexto
   =============================== */
$user = $_SESSION["user"] ?? [];
$branch_id = (int)($user["branch_id"] ?? 0);

$patient_id = isset($_GET["patient_id"]) ? (int)$_GET["patient_id"] : 0;
$patient_name = "";
$today = date("Y-m-d");
$year = date("Y");

$err = "";
$ok = "";
$last_invoice_id = null;

/* ===============================
   Paciente
   =============================== */
try {
  if ($patient_id > 0 && tableExists($conn, "patients")) {
    $stP = $conn->prepare("SELECT name FROM patients WHERE id=? LIMIT 1");
    $stP->execute([$patient_id]);
    $patient_name = (string)($stP->fetchColumn() ?: "");
  }
} catch (Throwable $e) {}

/* ===============================
   Detectar columnas de inventory_items para listado
   =============================== */
$items_all = [];
$stockTable = null;
$stockItemCol = null;
$stockBranchCol = null;
$stockQtyCol = null;

$colItemId = "id";
$colName   = "name";
$colCat    = "category_id";
$colPrice  = "sale_price";
$colActive = null;
$colBranchItems = null;

try {
  if (tableExists($conn, "inventory_items")) {
    $itemCols = tableColumns($conn, "inventory_items");
    if (in_array("id", $itemCols, true)) $colItemId = "id";
    if (in_array("name", $itemCols, true)) $colName = "name";
    if (in_array("category_id", $itemCols, true)) $colCat = "category_id";
    if (in_array("sale_price", $itemCols, true)) $colPrice = "sale_price";
    if (in_array("is_active", $itemCols, true)) $colActive = "is_active";
    if (in_array("branch_id", $itemCols, true)) $colBranchItems = "branch_id";

    // stock candidates
    $stockCandidates = ["inventory_stock", "inventory_branch_stock", "inventario_stock", "stock"];
    foreach ($stockCandidates as $t) {
      if (tableExists($conn, $t)) {
        $stockTable = $t;
        $cols = tableColumns($conn, $t);
        $stockItemCol = in_array("item_id", $cols, true) ? "item_id" : (in_array("inventory_item_id", $cols, true) ? "inventory_item_id" : null);
        $stockBranchCol = in_array("branch_id", $cols, true) ? "branch_id" : null;
        $stockQtyCol = in_array("quantity", $cols, true) ? "quantity" : (in_array("qty", $cols, true) ? "qty" : null);

        if ($stockItemCol && $stockBranchCol) break;
        $stockTable = null;
      }
    }

    // cargar items para el select (solo activos y de la sucursal si aplica)
    $sql = "SELECT $colItemId AS id, $colName AS name, $colCat AS category_id, $colPrice AS sale_price FROM inventory_items";
    $where = [];
    $params = [];
    if ($colActive !== null) { $where[] = "$colActive=1"; }
    if ($colBranchItems !== null) { $where[] = "$colBranchItems=?"; $params[] = $branch_id; }
    if ($where) $sql .= " WHERE " . implode(" AND ", $where);
    $sql .= " ORDER BY $colName ASC";

    $st = $conn->prepare($sql);
    $st->execute($params);
    $items_all = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch (Throwable $e) {}

/* ===============================
   POST: guardar factura
   =============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save_invoice") {
  try {
    if ($patient_id <= 0) throw new Exception("Paciente inválido.");
    if ($branch_id <= 0) throw new Exception("Sucursal inválida (branch_id).");

    $invoice_date    = trim((string)($_POST["invoice_date"] ?? date("Y-m-d")));
    $payment_method  = trim((string)($_POST["payment_method"] ?? "EFECTIVO"));
    $cash_received   = ($_POST["cash_received"] ?? null);
    $coverage_amount = number0($_POST["coverage_amount"] ?? 0);
    $cash_received   = ($cash_received === "" || $cash_received === null) ? null : number0($cash_received);
    $representative  = trim((string)($_POST["representative"] ?? ""));

    if ($representative === "") throw new Exception("Debe ingresar el representante.");

    $lines = $_POST["lines"] ?? [];
    if (!is_array($lines) || count($lines) === 0) throw new Exception("Debe agregar al menos un producto.");

    $cleanLines = [];
    foreach ($lines as $k => $v) {
      $iid = (int)$k;
      $qty = (int)$v;
      if ($iid > 0 && $qty > 0) $cleanLines[$iid] = $qty;
    }
    if (count($cleanLines) === 0) throw new Exception("Debe agregar productos con cantidad válida.");

    $ids = array_keys($cleanLines);
    $in  = implode(",", array_fill(0, count($ids), "?"));

    /* Mapa items válidos por sucursal */
    if ($stockTable !== null && $stockItemCol && $stockBranchCol) {
      $sqlMap = "SELECT i.$colItemId AS id, i.$colCat AS category_id, i.$colName AS name, i.$colPrice AS sale_price
                 FROM inventory_items i
                 INNER JOIN $stockTable s ON s.$stockItemCol = i.$colItemId
                 WHERE i.$colItemId IN ($in)
                   AND s.$stockBranchCol = ?";
      $paramsMap = array_merge($ids, [$branch_id]);
      if ($colActive !== null) $sqlMap .= " AND i.$colActive=1";
      $stMap = $conn->prepare($sqlMap);
      $stMap->execute($paramsMap);
    } else {
      $sqlMap = "SELECT $colItemId AS id, $colCat AS category_id, $colName AS name, $colPrice AS sale_price
                 FROM inventory_items
                 WHERE $colItemId IN ($in)";
      $paramsMap = $ids;
      if ($colBranchItems !== null) { $sqlMap .= " AND $colBranchItems=?"; $paramsMap[] = $branch_id; }
      if ($colActive !== null) $sqlMap .= " AND $colActive=1";
      $stMap = $conn->prepare($sqlMap);
      $stMap->execute($paramsMap);
    }

    $mapRows = $stMap->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    foreach ($mapRows as $r) $map[(int)$r["id"]] = $r;

    // Validar que TODOS los productos enviados existan/estén disponibles para esta sucursal
    $missing = [];
    foreach ($cleanLines as $iid => $q) {
      $iid = (int)$iid;
      if (!isset($map[$iid])) $missing[] = $iid;
    }
    if ($missing) {
      throw new RuntimeException("Hay productos inválidos o no disponibles en esta sucursal: " . implode(", ", $missing));
    }

    $subtotal = 0.0;
    foreach ($cleanLines as $iid => $q) {
      $price = (float)$map[(int)$iid]["sale_price"];
      $subtotal += ($price * (int)$q);
    }

    $total = max(0.0, $subtotal - $coverage_amount);

    $change_due = null;
    if (mb_strtoupper($payment_method) === "EFECTIVO") {
      $change_due = (float)number0($cash_received ?? 0) - $total;
    } else {
      $cash_received = null;
      $change_due = null;
    }

    $conn->beginTransaction();

    /* INSERT CABECERA invoices */
    $motivo = isset($_POST["motivo"]) ? trim((string)$_POST["motivo"]) : "";
    $notes  = isset($_POST["notes"]) ? trim((string)$_POST["notes"]) : null;
    if ($notes === "") $notes = null;

    $invCols2 = tableColumns($conn, "invoices");
    $hasBranch  = in_array("branch_id", $invCols2, true);
    $hasMotivo  = in_array("motivo", $invCols2, true);
    $hasRep     = in_array("representative", $invCols2, true);
    $hasCov     = in_array("coverage_amount", $invCols2, true);
    $hasCash    = in_array("cash_received", $invCols2, true);
    $hasChange  = in_array("change_due", $invCols2, true);
    $hasNotes   = in_array("notes", $invCols2, true);
    $hasCreated = in_array("created_by", $invCols2, true);

    if (!$hasRep && $representative !== "" && $hasNotes) {
      $tag = "Representante: " . $representative;
      $notes = trim((string)$notes);
      $notes = ($notes === "" || $notes === "0") ? $tag : ($notes . " | " . $tag);
    }

    if ($hasBranch && $branch_id <= 0) {
      throw new RuntimeException("No se pudo determinar la sucursal del usuario.");
    }

    $fields = ["patient_id","invoice_date","payment_method","subtotal","total"];
    $vals   = [$patient_id, $invoice_date, $payment_method, $subtotal, $total];

    if ($hasBranch) { $fields[]="branch_id"; $vals[]=$branch_id; }
    if ($hasCov)    { $fields[]="coverage_amount"; $vals[]=$coverage_amount; }
    if ($hasCash)   { $fields[]="cash_received"; $vals[]=$cash_received; }
    if ($hasChange) { $fields[]="change_due"; $vals[]=$change_due; }
    if ($hasNotes)  { $fields[]="notes"; $vals[]=$notes; }
    if ($hasCreated){ $fields[]="created_by"; $vals[]=(int)($user["id"] ?? 0); }
    if ($hasRep)    { $fields[]="representative"; $vals[]=$representative; }
    if ($hasMotivo) { $fields[]="motivo"; $vals[]=$motivo; }

    $place = implode(",", array_fill(0, count($fields), "?"));
    $sqlInv = "INSERT INTO invoices (" . implode(",", $fields) . ") VALUES ($place)";
    $stIns = $conn->prepare($sqlInv);
    $stIns->execute($vals);
    $invoice_id = (int)$conn->lastInsertId();

    /* INSERT LÍNEAS invoice_items */
    $linesTable = "invoice_items";
    $lineCols = tableColumns($conn, $linesTable);
    if (!$lineCols) throw new RuntimeException("No se pudo leer la estructura de invoice_items.");

    $colItem = in_array("item_id", $lineCols, true) ? "item_id" : (in_array("inventory_item_id", $lineCols, true) ? "inventory_item_id" : "item_id");
    $colQty  = in_array("qty", $lineCols, true) ? "qty" : (in_array("quantity", $lineCols, true) ? "quantity" : "qty");

    $hasUnit = in_array("unit_price", $lineCols, true) || in_array("price", $lineCols, true);
    $colUnit = in_array("unit_price", $lineCols, true) ? "unit_price" : (in_array("price", $lineCols, true) ? "price" : "unit_price");

    $hasLineTotal = in_array("line_total", $lineCols, true) || in_array("total", $lineCols, true);
    $colLineTotal = in_array("line_total", $lineCols, true) ? "line_total" : (in_array("total", $lineCols, true) ? "total" : "line_total");

    $sqlLine = "INSERT INTO {$linesTable} (invoice_id, {$colItem}, {$colQty}"
      . ($hasUnit ? ", {$colUnit}" : "")
      . ($hasLineTotal ? ", {$colLineTotal}" : "")
      . ") VALUES (?" . ", ?" . ", ?" . ($hasUnit ? ", ?" : "") . ($hasLineTotal ? ", ?" : "") . ")";
    $stLine = $conn->prepare($sqlLine);

    foreach ($cleanLines as $iid => $q) {
      $unit = (float)$map[(int)$iid]["sale_price"];
      $lt   = $unit * (int)$q;

      $args = [$invoice_id, (int)$iid, (int)$q];
      if ($hasUnit) $args[] = $unit;
      if ($hasLineTotal) $args[] = $lt;

      $stLine->execute($args);
    }

    /* ===============================
       DESCONTAR INVENTARIO (POR SUCURSAL)
       - Actualiza inventory_stock (tabla real)
       - (Opcional) actualiza branch_items si existe (pantallas antiguas)
       - Registra salida en inventory_movements (si existe)
       =============================== */

    $stHasStockRow = $conn->prepare("
      SELECT quantity
      FROM inventory_stock
      WHERE branch_id = ?
        AND item_id = ?
      LIMIT 1
    ");

    $stUpdStock = $conn->prepare("
      UPDATE inventory_stock
      SET quantity = quantity - ?
      WHERE branch_id = ?
        AND item_id = ?
        AND quantity >= ?
    ");

    $stInvMov = null;
    if (tableExists($conn, "inventory_movements")) {
      $stInvMov = $conn->prepare("
        INSERT INTO inventory_movements
          (item_id, branch_id, movement_type, qty, note, created_by)
        VALUES
          (?, ?, 'OUT', ?, ?, ?)
      ");
    }

    $stUpdBranchItems = null;
    if (tableExists($conn, "branch_items")) {
      $branchCols = tableColumns($conn, "branch_items");
      if (in_array("quantity", $branchCols, true)) {
        $stUpdBranchItems = $conn->prepare("
          UPDATE branch_items
          SET quantity = quantity - ?
          WHERE branch_id = ?
            AND item_id = ?
            AND quantity >= ?
        ");
      }
    }

    foreach ($cleanLines as $iid => $q) {
      $iid = (int)$iid;
      $qty = (int)$q;
      if ($iid <= 0 || $qty <= 0) continue;

      if (!isset($map[$iid])) {
        throw new RuntimeException("Producto inválido o no disponible (ID #{$iid}).");
      }

      $stHasStockRow->execute([$branch_id, $iid]);
      $curQty = $stHasStockRow->fetchColumn();
      if ($curQty === false) {
        throw new RuntimeException("El producto ID #{$iid} no tiene stock registrado en esta sucursal.");
      }

      $stUpdStock->execute([$qty, $branch_id, $iid, $qty]);
      if ($stUpdStock->rowCount() <= 0) {
        throw new RuntimeException("Stock insuficiente para el producto ID #{$iid} en esta sucursal.");
      }

      if ($stInvMov) {
        $note = "Salida por factura #{$invoice_id}";
        $stInvMov->execute([$iid, $branch_id, $qty, $note, (int)($user["id"] ?? 0)]);
      }

      if ($stUpdBranchItems) {
        $stUpdBranchItems->execute([$qty, $branch_id, $iid, $qty]);
      }
    }

/* ===============================
       ✅ CAJA: registrar ingresos
       =============================== */
    $uid = (int)($user["id"] ?? 0);
    $pm  = strtolower(trim((string)$payment_method));
    if ($pm === "cash") $pm = "efectivo";
    if ($pm === "card") $pm = "tarjeta";
    if ($pm === "transfer") $pm = "transferencia";

    // 1) si existe función oficial, úsala
    if (function_exists("caja_registrar_ingreso_factura")) {
      try {
        caja_registrar_ingreso_factura($conn, (int)$branch_id, $uid, (int)$invoice_id, (float)$total, (string)$pm);

        if ((float)$coverage_amount > 0) {
          caja_registrar_ingreso_factura($conn, (int)$branch_id, $uid, (int)$invoice_id, (float)$coverage_amount, "cobertura");
        }
      } catch (Throwable $e) {
        // no detenemos facturación si caja falla
      }
    } else {
      // 2) fallback genérico: si tienes tabla caja_movimientos o similares, aquí podrías integrarlo
    }

    $conn->commit();

    $ok = "Factura creada correctamente.";
    $last_invoice_id = $invoice_id;

  } catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    $err = $e->getMessage();
  }
}

/* ===============================
   Categorías (para filtro del select)
   =============================== */
$cats = [];
try {
  if (tableExists($conn, "inventory_categories")) {
    $stC = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
    $cats = $stC ? $stC->fetchAll(PDO::FETCH_ASSOC) : [];
  }
} catch (Throwable $e) {}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Nueva factura</title>

  <link rel="stylesheet" href="/assets/css/styles.css?v=120">

  <style>
    .page-wrap{max-width:1400px !important; width:100% !important;margin:0 auto;padding:18px}
    .card{background:#fff;border-radius:18px;box-shadow:0 10px 25px rgba(0,0,0,.08);padding:18px;width:100%}
    .title{font-size:34px;font-weight:900;text-align:center;margin:10px 0 8px}
    .subtitle{font-size:14px;color:#334155;text-align:center;margin-bottom:10px;font-weight:600}
    .page-footer{text-align:center;margin-top:14px;font-weight:850;color:#6b7a88}

    .alert{padding:10px 12px;border-radius:12px;margin:10px auto;max-width:1100px}
    .alert.err{background:#fee2e2;border:1px solid #fecaca;color:#991b1b}
    .alert.ok{background:#dcfce7;border:1px solid #bbf7d0;color:#166534}

    .section{margin-top:14px}
    .section h3{font-size:15px;margin:0 0 10px;font-weight:900;color:#0f172a}
    .grid{display:grid;grid-template-columns:repeat(2, minmax(0,1fr));gap:14px}
    label{display:block;font-size:12px;font-weight:800;color:#334155;margin-bottom:6px}
    input, select{width:100%;height:42px;border:1px solid #e2e8f0;border-radius:12px;padding:0 12px;font-weight:700}
    .row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
    .row > *{flex:1}
    .btn{height:42px;border-radius:12px;border:0;font-weight:900;cursor:pointer;padding:0 16px}
    .btn-primary{background:#0b4d87;color:#fff}
    .btn-muted{background:#eef2f6;color:#0f172a}
    .table{width:100%;border-collapse:separate;border-spacing:0;margin-top:10px}
    .table th,.table td{padding:10px;border-bottom:1px solid #eef2f6;font-size:13px}
    .table th{font-size:12px;color:#0b4d87;text-align:left;font-weight:950}
    .right{text-align:right}
    .totals{max-width:380px;margin-left:auto;margin-top:12px;border:1px solid #eef2f6;border-radius:14px;padding:12px}
    .totals .line{display:flex;justify-content:space-between;margin:6px 0;font-weight:900}
    .muted{color:#64748b;font-weight:800}
    .hide{display:none}

    @media (max-width: 980px){
      .grid{grid-template-columns:1fr}
    }
  </style>
</head>

<body>

<header class="navbar">
  <div class="inner">
    <div class="brand">
      <span class="dot"></span>
      <span>CEVIMEP</span>
    </div>
    <div class="nav-right">
      <a href="/logout.php" class="btn-pill">Salir</a>
    </div>
  </div>
</header>

<div class="layout">
  <aside class="sidebar">
    <div class="menu-title">Menú</div>
    <nav class="menu">
      <a href="/private/dashboard.php">🏠 Panel</a>
      <a href="/private/patients/index.php">👤 Pacientes</a>
      <a href="/private/citas/index.php">📅 Citas</a>
      <a class="active" href="/private/facturacion/index.php">🧾 Facturación</a>
      <a href="/private/caja/index.php">💳 Caja</a>
      <a href="/private/inventario/index.php">📦 Inventario</a>
      <a href="/private/estadistica/index.php">📊 Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <div class="page-wrap">
      <div class="card">
        <div class="title">Nueva factura</div>
        <div class="subtitle">
          Paciente: <?php echo h($patient_name ?: "—"); ?> — Sucursal: <?php echo h((string)($user["branch_name"] ?? "—")); ?>
        </div>

        <?php if ($err): ?>
          <div class="alert err"><?php echo h($err); ?></div>
        <?php endif; ?>
        <?php if ($ok): ?>
          <div class="alert ok"><?php echo h($ok); ?></div>
        <?php endif; ?>

        <?php if ($ok && !empty($last_invoice_id)): ?>
          <script>
          (function(){
            var url = "print.php?id=<?php echo (int)$last_invoice_id; ?>";
            window.open(url, "_blank");
          })();
          </script>
        <?php endif; ?>

        <form method="post" id="invoiceForm" autocomplete="off">
          <input type="hidden" name="action" value="save_invoice">

          <div class="section">
            <h3>Datos de la factura</h3>
            <div class="grid">
              <div>
                <label>Fecha</label>
                <input type="date" name="invoice_date" value="<?php echo h($today); ?>">
              </div>

              <div>
                <label>Método de pago</label>
                <select name="payment_method" id="payment_method">
                  <option value="EFECTIVO">EFECTIVO</option>
                  <option value="TARJETA">TARJETA</option>
                  <option value="TRANSFERENCIA">TRANSFERENCIA</option>
                </select>
              </div>

              <div id="cash_box">
                <label>Efectivo recibido (solo efectivo)</label>
                <input type="text" name="cash_received" id="cash_received" value="">
              </div>

              <div>
                <label>Cobertura (RD$)</label>
                <input type="text" name="coverage_amount" id="coverage_amount" value="0.00">
              </div>

              <div style="grid-column:1 / -1">
                <label>Representante</label>
                <input type="text" name="representative" placeholder="Nombre del representante / tutor">
              </div>
            </div>
          </div>

          <div class="section">
            <h3>Agregar productos</h3>
            <div class="row">
              <div style="max-width:260px">
                <label>Categoría (filtro)</label>
                <select id="cat_filter">
                  <option value="0">Todas</option>
                  <?php foreach ($cats as $c): ?>
                    <option value="<?php echo (int)$c["id"]; ?>"><?php echo h($c["name"]); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <label>Producto</label>
                <select id="item_select">
                  <option value="0">-- Seleccionar --</option>
                  <?php foreach ($items_all as $it): ?>
                    <option
                      value="<?php echo (int)$it["id"]; ?>"
                      data-price="<?php echo h($it["sale_price"]); ?>"
                      data-cat="<?php echo (int)($it["category_id"] ?? 0); ?>"
                    >
                      <?php echo h($it["name"]); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div style="max-width:160px">
                <label>Cantidad</label>
                <input type="number" id="qty_input" min="1" value="1">
              </div>

              <div style="max-width:160px">
                <button type="button" class="btn btn-primary" id="btn_add">Añadir</button>
              </div>
            </div>

            <table class="table">
              <thead>
                <tr>
                  <th>Producto</th>
                  <th class="right">Cantidad</th>
                  <th class="right">Precio</th>
                  <th class="right">Total</th>
                </tr>
              </thead>
              <tbody id="lines_tbody"></tbody>
            </table>

            <div class="totals">
              <div class="line"><span class="muted">Subtotal</span><span id="subTotal">RD$ 0.00</span></div>
              <div class="line"><span class="muted">Cobertura</span><span id="covTotal">RD$ 0.00</span></div>
              <div class="line"><span>Total</span><span id="grandTotal">RD$ 0.00</span></div>
              <div class="line"><span class="muted">Cambio</span><span id="changeDue">RD$ 0.00</span></div>
            </div>

            <div id="hidden_lines"></div>

            <div style="margin-top:14px; display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
              <a href="/private/facturacion/index.php" class="btn btn-muted" style="text-decoration:none;display:inline-flex;align-items:center;">Volver</a>
              <button type="submit" class="btn btn-primary">Guardar e imprimir</button>
            </div>
          </div>
        </form>
      </div>

      <div class="page-footer">© <?php echo h($year); ?> CEVIMEP</div>
    </div>
  </main>
</div>

<script>
(function(){
  const money = (n)=> {
    n = Number(n||0);
    return "RD$ " + n.toFixed(2);
  };

  const payment = document.getElementById("payment_method");
  const cashBox = document.getElementById("cash_box");
  const cashInp = document.getElementById("cash_received");
  const covInp  = document.getElementById("coverage_amount");

  const catFilter = document.getElementById("cat_filter");
  const itemSel = document.getElementById("item_select");
  const qtyInp  = document.getElementById("qty_input");
  const btnAdd  = document.getElementById("btn_add");

  const tbody   = document.getElementById("lines_tbody");
  const hidden  = document.getElementById("hidden_lines");

  const subEl   = document.getElementById("subTotal");
  const covEl   = document.getElementById("covTotal");
  const totEl   = document.getElementById("grandTotal");
  const chgEl   = document.getElementById("changeDue");

  const lines = {};

  function syncPaymentUI(){
    const pm = (payment.value||"").toUpperCase();
    if (pm === "EFECTIVO") {
      cashBox.classList.remove("hide");
    } else {
      cashBox.classList.add("hide");
      cashInp.value = "";
    }
    recalc();
  }

  function filterItems(){
    const cat = Number(catFilter.value||0);
    [...itemSel.options].forEach((opt, idx)=>{
      if (idx===0) return;
      const oc = Number(opt.getAttribute("data-cat")||0);
      opt.hidden = (cat && oc !== cat);
    });
    itemSel.value = "0";
  }

  function recalc(){
    let subtotal = 0;
    Object.keys(lines).forEach(id=>{
      const ln = lines[id];
      subtotal += (Number(ln.price) * Number(ln.qty));
    });

    const cov = Number(covInp.value||0);
    const total = Math.max(0, subtotal - cov);

    let change = 0;
    const pm = (payment.value||"").toUpperCase();
    if (pm === "EFECTIVO") {
      const cash = Number(cashInp.value||0);
      change = cash - total;
    }

    subEl.textContent = money(subtotal);
    covEl.textContent = money(cov);
    totEl.textContent = money(total);
    chgEl.textContent = money(change);
  }

  function render(){
    tbody.innerHTML = "";
    hidden.innerHTML = "";

    Object.keys(lines).forEach(id=>{
      const ln = lines[id];

      const tr = document.createElement("tr");
      const tdName = document.createElement("td");
      const tdQty = document.createElement("td");
      const tdPrice = document.createElement("td");
      const tdTotal = document.createElement("td");

      tdName.textContent = ln.name;

      tdQty.className = "right";
      tdQty.textContent = ln.qty;

      tdPrice.className = "right";
      tdPrice.textContent = money(ln.price);

      tdTotal.className = "right";
      tdTotal.textContent = money(Number(ln.price) * Number(ln.qty));

      tr.appendChild(tdName);
      tr.appendChild(tdQty);
      tr.appendChild(tdPrice);
      tr.appendChild(tdTotal);
      tbody.appendChild(tr);

      const inp = document.createElement("input");
      inp.type = "hidden";
      inp.name = "lines[" + id + "]";
      inp.value = ln.qty;
      hidden.appendChild(inp);
    });

    recalc();
  }

  btnAdd.addEventListener("click", ()=>{
    const opt = itemSel.options[itemSel.selectedIndex];
    const id = Number(itemSel.value||0);
    if (!id) return;

    const qty = Math.max(1, Number(qtyInp.value||1));
    const name = opt.textContent.trim();
    const price = Number(opt.getAttribute("data-price")||0);
    const cat = Number(opt.getAttribute("data-cat")||0);

    if (!lines[id]) {
      lines[id] = {name, price, qty, cat};
    } else {
      lines[id].qty += qty;
    }

    render();
  });

  catFilter.addEventListener("change", filterItems);
  payment.addEventListener("change", syncPaymentUI);
  cashInp.addEventListener("input", recalc);
  covInp.addEventListener("input", recalc);

  filterItems();
  syncPaymentUI();
})();
</script>

</body>
</html>
