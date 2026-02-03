<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
require_once __DIR__ . "/../caja/caja_lib.php";

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

function tableColumns(PDO $pdo, string $table): array {
  try {
    $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cols = [];
    foreach ($rows as $r) $cols[] = $r["Field"];
    return $cols;
  } catch (Throwable $e) {
    return [];
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


function number0($v): float {
  if ($v === null) return 0.0;
  if (is_string($v)) $v = str_replace([",", " "], ["", ""], $v);
  return (float)$v;
}

/**
 * Devuelve expresiones SQL seguras para nombre/documento/teléfono/nac
 * sin asumir el esquema exacto de `patients`.
 */
function patientExprs(PDO $pdo): array {
  $cols = tableColumns($pdo, "patients");

  $first = in_array("first_name", $cols, true) ? "first_name" : (in_array("nombres", $cols, true) ? "nombres" : null);
  $last  = in_array("last_name",  $cols, true) ? "last_name"  : (in_array("apellidos", $cols, true) ? "apellidos" : null);
  $full  = in_array("full_name",  $cols, true) ? "full_name"  : (in_array("nombre", $cols, true) ? "nombre" : null);

  $doc   = in_array("document",   $cols, true) ? "document"   : (in_array("cedula", $cols, true) ? "cedula" : (in_array("pasaporte", $cols, true) ? "pasaporte" : null));
  $tel   = in_array("phone",      $cols, true) ? "phone"      : (in_array("telefono", $cols, true) ? "telefono" : (in_array("tel", $cols, true) ? "tel" : null));
  $nac   = in_array("nationality",$cols, true) ? "nationality": (in_array("nacionalidad", $cols, true) ? "nacionalidad" : null);

  if ($full) {
    $nameExpr = "COALESCE(NULLIF(TRIM($full),''),'')";
  } elseif ($first || $last) {
    $parts = [];
    if ($first) $parts[] = "COALESCE(NULLIF(TRIM($first),''),'')";
    if ($last)  $parts[] = "COALESCE(NULLIF(TRIM($last),''),'')";
    $nameExpr = "TRIM(CONCAT(" . implode(",' ',", $parts) . "))";
  } else {
    $nameExpr = "''";
  }

  $docExpr = $doc ? "COALESCE(NULLIF(TRIM($doc),''),'')" : "''";
  $telExpr = $tel ? "COALESCE(NULLIF(TRIM($tel),''),'')" : "''";
  $nacExpr = $nac ? "COALESCE(NULLIF(TRIM($nac),''),'')" : "''";

  return [$nameExpr, $docExpr, $telExpr, $nacExpr];
}

$user = $_SESSION["user"] ?? [];
$patient_id = (int)($_GET["patient_id"] ?? 0);

$err = "";
$ok  = "";

/* ===== cargar paciente ===== */
$patient = null;
$patient_name = "";
try {
  if ($patient_id > 0) {
    [$nameExpr, $docExpr, $telExpr, $nacExpr] = patientExprs($conn);
    $st = $conn->prepare("
      SELECT
        id,
        $nameExpr AS _name,
        $docExpr  AS _doc,
        $telExpr  AS _phone,
        $nacExpr  AS _nat
      FROM patients
      WHERE id=?
      LIMIT 1
    ");
    $st->execute([$patient_id]);
    $patient = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $patient_name = (string)($patient["_name"] ?? "");
  }
} catch (Throwable $e) {
  $err = "Error cargando paciente: " . $e->getMessage();
}

if (!$patient && !$err) {
  $err = "Paciente no encontrado.";
}

/* ===== categorías e items ===== */
$cats = [];
try {
  $cats = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

/**
 * Inventario (compat): en algunos ambientes la columna `active` no existe.
 * También normalizamos nombres de columnas para no romper el selector de productos.
 */
$invCols = tableColumns($conn, "inventory_items");

$colItemId = "id";

// category
$colCat = "category_id";
if (in_array("category_id", $invCols, true)) {
  $colCat = "category_id";
} elseif (in_array("cat_id", $invCols, true)) {
  $colCat = "cat_id";
} elseif (in_array("category", $invCols, true)) {
  $colCat = "category";
}

// name
$colName = "name";
if (in_array("name", $invCols, true)) {
  $colName = "name";
} elseif (in_array("item_name", $invCols, true)) {
  $colName = "item_name";
} elseif (in_array("descripcion", $invCols, true)) {
  $colName = "descripcion";
}

// price
$colPrice = "sale_price";
if (in_array("sale_price", $invCols, true)) {
  $colPrice = "sale_price";
} elseif (in_array("price", $invCols, true)) {
  $colPrice = "price";
} elseif (in_array("unit_price", $invCols, true)) {
  $colPrice = "unit_price";
} elseif (in_array("precio", $invCols, true)) {
  $colPrice = "precio";
}

// active/status (puede no existir)
$colActive = null;
if (in_array("active", $invCols, true)) {
  $colActive = "active";
} elseif (in_array("is_active", $invCols, true)) {
  $colActive = "is_active";
} elseif (in_array("status", $invCols, true)) {
  $colActive = "status";
}

$items_all = [];
try {
  $sql = "SELECT $colItemId AS id, $colCat AS category_id, $colName AS name, $colPrice AS sale_price FROM inventory_items";
  if ($colActive !== null) {
    // status/active típico: 1 = activo
    $sql .= " WHERE $colActive=1";
  }
  $sql .= " ORDER BY $colName ASC";
  $items_all = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

/* ===== POST: guardar ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "save_invoice") {
  try {
    if ($patient_id <= 0) throw new Exception("Paciente inválido.");

    $invoice_date    = trim((string)($_POST["invoice_date"] ?? date("Y-m-d")));
    $payment_method  = trim((string)($_POST["payment_method"] ?? "EFECTIVO"));
    $cash_received   = ($_POST["cash_received"] ?? null);
    $coverage_amount = number0($_POST["coverage_amount"] ?? 0);

    $cash_received = ($cash_received === "" || $cash_received === null) ? null : number0($cash_received);

    $representative = trim((string)($_POST["representative"] ?? ""));

    
    if ($representative === "") throw new Exception("Debe ingresar el representante.");
$lines = $_POST["lines"] ?? [];
    if (!is_array($lines) || count($lines) === 0) throw new Exception("Debe agregar al menos un producto.");

    $cleanLines = [];
    foreach ($lines as $k => $v) {
      $iid = (int)$k;
      $qty = (int)$v;
      if ($iid > 0 && $qty > 0) $cleanLines[$iid] = $qty;
    }
    if (count($cleanLines) === 0) throw new Exception("Debe agregar al menos un producto con cantidad válida.");

    $ids = array_keys($cleanLines);
    $in = implode(",", array_fill(0, count($ids), "?"));
    $stMap = $conn->prepare("SELECT $colItemId AS id, $colCat AS category_id, $colName AS name, $colPrice AS sale_price FROM inventory_items WHERE $colItemId IN ($in)");
    $stMap->execute($ids);
    $mapRows = $stMap->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    foreach ($mapRows as $r) $map[(int)$r["id"]] = $r;

    $subtotal = 0.0;
    foreach ($cleanLines as $iid => $q) {
      if (!isset($map[(int)$iid])) continue;
      $price = (float)$map[(int)$iid]["sale_price"];
      $subtotal += ($price * (int)$q);
    }

    $total = max(0.0, $subtotal - $coverage_amount);

    $change_due = 0.0;
    if (mb_strtoupper($payment_method) === "EFECTIVO") {
      $change_due = (float)number0($cash_received ?? 0) - $total;
    } else {
      $cash_received = null;
      $change_due = null;
    }

    $conn->beginTransaction();

    // ===== INSERT CABECERA (con motivo si existe) =====
    $motivo = "";
    if (isset($_POST["motivo"])) $motivo = trim((string)$_POST["motivo"]);

    // Notas (opcional)
    $notes = null;
    if (isset($_POST["notes"])) {
      $tmpNotes = trim((string)$_POST["notes"]);
      $notes = ($tmpNotes === "") ? null : $tmpNotes;
    }

    // Compat: invoices puede variar. Intentamos lo más estable.
    $invCols2 = tableColumns($conn, "invoices");
    $hasBranch  = in_array("branch_id", $invCols2, true);
    $hasMotivo  = in_array("motivo", $invCols2, true);
    $hasRep     = in_array("representative", $invCols2, true);
    $hasCov     = in_array("coverage_amount", $invCols2, true);
    $hasCash    = in_array("cash_received", $invCols2, true);
    $hasChange  = in_array("change_due", $invCols2, true);
    $hasNotes   = in_array("notes", $invCols2, true);
    
    // Si la columna representative no existe, guardamos el representante dentro de notes para no perderlo.
    if (!$hasRep && $representative !== "" && $hasNotes) {
      $notes = trim((string)$notes);
      $tag = "Representante: " . $representative;
      if ($notes === "") {
        $notes = $tag;
      } elseif (stripos($notes, "Representante:") === false) {
        $notes .= " | " . $tag;
      }
    }
$hasCreated = in_array("created_by", $invCols2, true);

    // branch_id: debe venir de la sesión (no del formulario)
    $branch_id = (int)($user["branch_id"] ?? 0);

    // Fallback: si por alguna razón no viene en sesión, lo buscamos en users.
    if ($branch_id <= 0 && isset($user["id"])) {
      try {
        $stB = $conn->prepare("SELECT branch_id FROM users WHERE id = ? LIMIT 1");
        $stB->execute([(int)$user["id"]]);
        $branch_id = (int)($stB->fetchColumn() ?: 0);
      } catch (Throwable $e) { /* noop */ }
    }

    if ($hasBranch && $branch_id <= 0) {
      throw new RuntimeException("No se pudo determinar la sucursal (branch_id) del usuario. Vuelve a iniciar sesión o verifica el usuario.");
    }

    $fields = ["patient_id","invoice_date","payment_method","subtotal","total"];
    $vals   = [$patient_id,$invoice_date,$payment_method,$subtotal,$total];

    // branch_id requerido (NOT NULL sin default en producción)
    if ($hasBranch) { $fields[] = "branch_id"; $vals[] = $branch_id; }

    // Auditoría / notas
    if ($hasNotes)   { $fields[] = "notes"; $vals[] = ($notes ?? null); }
    if ($hasCreated) { $fields[] = "created_by"; $vals[] = (int)($user["id"] ?? 0); }

    if ($hasCov)   { $fields[]="coverage_amount"; $vals[]=$coverage_amount; }
    if ($hasCash)  { $fields[]="cash_received"; $vals[]=$cash_received; }
    if ($hasChange){ $fields[]="change_due"; $vals[]=$change_due; }
    if ($hasRep)   { $fields[]="representative"; $vals[]=$representative; }
    if ($hasMotivo){ $fields[]="motivo"; $vals[]=$motivo; }

    $place = implode(",", array_fill(0, count($fields), "?"));
    $sqlInv = "INSERT INTO invoices (" . implode(",", $fields) . ") VALUES ($place)";
    $stIns = $conn->prepare($sqlInv);
    $stIns->execute($vals);
    $invoice_id = (int)$conn->lastInsertId();

    // ===== INSERT LÍNEAS =====
    // Compat: puede llamarse invoice_items o invoice_lines (según el esquema)
    $linesTable = "invoice_items"; // esquema actual
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
      if (!isset($map[(int)$iid])) continue;
      $unit = (float)$map[(int)$iid]["sale_price"];
      $lt   = $unit * (int)$q;

      $args = [$invoice_id, (int)$iid, (int)$q];
      if ($hasUnit) $args[] = $unit;
      if ($hasLineTotal) $args[] = $lt;

      $stLine->execute($args);
    }

        // ===== CAJA: registrar ingresos de la factura =====
    // Registra el pago (efectivo/tarjeta/transferencia) y, si aplica, la cobertura como ingreso separado.
    if (function_exists('caja_registrar_ingreso_factura')) {
      try {
        $uid = (int)($user['id'] ?? 0);
        $pm  = strtolower(trim((string)$payment_method));
        // normalizar método
        if ($pm === 'efectivo' || $pm === 'cash') $pm = 'efectivo';
        if ($pm === 'tarjeta'  || $pm === 'card') $pm = 'tarjeta';
        if ($pm === 'transferencia' || $pm === 'transfer') $pm = 'transferencia';

        // Pago principal (total neto de cobertura)
        caja_registrar_ingreso_factura($conn, (int)$branch_id, $uid, (int)$invoice_id, (float)$total, (string)$pm);

        // Cobertura (si existe)
        if ((float)$coverage_amount > 0) {
          caja_registrar_ingreso_factura($conn, (int)$branch_id, $uid, (int)$invoice_id, (float)$coverage_amount, 'cobertura');
        }
      } catch (Throwable $e) {
        // No detenemos la facturación si falla caja
      }
    }

$conn->commit();

    $last_invoice_id = $invoice_id;
    $ok = "Factura creada (#{$invoice_id}).";
  } catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    $err = $e->getMessage();
  }
}

$today = date("Y-m-d");
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Nueva factura - CEVIMEP</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="/assets/css/facturacion.css?v=<?php echo time(); ?>">
  <style>
    .page-wrap{max-width:1100px;margin:0 auto;padding:18px}
    .card{background:#fff;border-radius:18px;box-shadow:0 10px 25px rgba(0,0,0,.08);padding:18px}
    .title{font-size:34px;font-weight:900;text-align:center;margin:10px 0 8px}
    .subtitle{font-size:14px;color:#334155;text-align:center;margin-bottom:10px;font-weight:600}
    .alert{padding:10px 12px;border-radius:12px;margin:10px auto;max-width:900px}
    .alert.err{background:#fee2e2;border:1px solid #fecaca;color:#991b1b}
    .alert.ok{background:#dcfce7;border:1px solid #bbf7d0;color:#166534}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
    .grid4{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px}
    @media(max-width:900px){.grid,.grid3,.grid4{grid-template-columns:1fr}}
    label{font-weight:800;font-size:12px;color:#0f172a;display:block;margin-bottom:6px}
    input,select{width:100%;padding:10px 12px;border-radius:12px;border:1px solid #e2e8f0;outline:none}
    input:focus,select:focus{border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.12)}
    .section{margin-top:14px}
    .section h3{margin:0 0 10px;font-size:15px;font-weight:900;color:#0f172a}
    .btnrow{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;margin-top:14px}
    .btn{height:40px;border-radius:12px;border:1px solid transparent;padding:0 14px;font-weight:900;cursor:pointer}
    .btn.primary{background:#0b4d87;color:#fff}
    .btn.light{background:#eef2ff;color:#1e3a8a;border-color:#dbeafe}
    .lines{width:100%;border-collapse:separate;border-spacing:0;margin-top:10px}
    .lines th,.lines td{padding:10px 10px;border-bottom:1px solid #eef2f6;font-size:13px}
    .lines th{color:#0b4d87;text-align:left;font-weight:900;font-size:12px}
    .lines tr:last-child td{border-bottom:none}
    .money{white-space:nowrap;font-weight:900}
    .totals{max-width:260px;margin-left:auto;background:#f8fafc;border:1px solid rgba(2,21,44,.08);border-radius:14px;padding:12px}
    .totals .row{display:flex;justify-content:space-between;gap:10px;margin:6px 0;font-weight:900}
    .mini{font-size:12px;opacity:.75;font-weight:700}

    .content{
      align-items:flex-start;
      justify-content:center;
      text-align:left;
      overflow:auto;
      padding:24px 0;
    }
    .page-wrap{max-width:1100px;margin:0 auto;padding:18px;width:100%;}
    .card{border-radius:18px;}
    input,select,textarea{font-family:inherit;}
    .btn.primary{background:#0b4d87;}
  </style>
  <style>
    /* Ajuste solo para esta pantalla: más ancho para evitar scroll */
    .page-wrap{max-width:1400px !important; width:100% !important;}
    .fact-card{width:100% !important;}
    .card{width:100% !important;}
    .content{overflow:auto;}
  </style>
</head>
<body>

<!-- TOPBAR -->
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
  <!-- SIDEBAR -->
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

  <!-- CONTENIDO -->
  <main class="content">

<?php if (file_exists(__DIR__ . "/../partials/_topbar.php")) include __DIR__ . "/../partials/_topbar.php"; ?>

<div class="layout">
  <?php if (file_exists(__DIR__ . "/../partials/_sidebar.php")) include __DIR__ . "/../partials/_sidebar.php"; ?>

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
                <input type="number" step="0.01" name="cash_received" id="cash_received" value="0.00">
              </div>

              <div>
                <label>Cobertura (RD$)</label>
                <input type="number" step="0.01" name="coverage_amount" id="coverage_amount" value="0.00">
              </div>

              <div style="grid-column:1 / -1;">
                <label>Representante</label>
                <input type="text" name="representative" placeholder="Nombre del representante / tutor">
              </div>

              <?php if (columnExists($conn, "invoices", "motivo")): ?>
                <div style="grid-column:1 / -1;">
                  <label>Motivo (opcional)</label>
                  <input type="text" name="motivo" placeholder="Ej: Vacuna / Consulta / Procedimiento...">
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="section">
            <h3>Agregar productos</h3>

            <div class="grid4">
              <div>
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
                    <option value="<?php echo (int)$it["id"]; ?>" data-cat="<?php echo (int)$it["category_id"]; ?>" data-price="<?php echo h($it["sale_price"]); ?>">
                      <?php echo h($it["name"]); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <?php if (empty($items_all)): ?>
                <div class="alert err" style="margin-top:10px;">
                  No se pudieron cargar los productos del inventario. Verifica la tabla <b>inventory_items</b> (columnas y/o estado activo).
                </div>
              <?php endif; ?>

              <div>
                <label>Cantidad</label>
                <input type="number" id="qty" value="1" min="1">
              </div>

              <div style="display:flex;align-items:flex-end;">
                <button type="button" class="btn primary" id="btnAdd" style="width:100%;">Añadir</button>
              </div>
            </div>

            <table class="lines" id="linesTable">
              <thead>
                <tr>
                  <th style="width:55%;">Producto</th>
                  <th style="width:15%;">Cantidad</th>
                  <th style="width:15%;">Precio</th>
                  <th style="width:15%;">Total</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>

            <div class="totals" style="margin-top:12px;">
              <div class="row"><span>Subtotal</span><span class="money" id="t_sub">RD$ 0.00</span></div>
              <div class="row"><span>Cobertura</span><span class="money" id="t_cov">RD$ 0.00</span></div>
              <div class="row"><span>Total a pagar</span><span class="money" id="t_total">RD$ 0.00</span></div>
              <div class="row"><span>Cambio</span><span class="money" id="t_change">RD$ 0.00</span></div>
              <div class="mini">* El cambio solo aplica en EFECTIVO.</div>
            </div>

            <div class="btnrow">
              <a class="btn light" href="/private/facturacion/index.php">Cancelar</a>
              <button type="submit" class="btn primary" id="btnSave">Guardar y imprimir</button>
            </div>
          </div>

          <div id="hiddenLines"></div>
        </form>
      </div>
    </div>
  </main>
</div>

<?php if (file_exists(__DIR__ . "/../partials/_footer.php")) include __DIR__ . "/../partials/_footer.php"; ?>

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
  const itemSel   = document.getElementById("item_select");
  const qtyInp    = document.getElementById("qty");
  const btnAdd    = document.getElementById("btnAdd");

  const tbody     = document.querySelector("#linesTable tbody");
  const hidden    = document.getElementById("hiddenLines");

  const tSub    = document.getElementById("t_sub");
  const tCov    = document.getElementById("t_cov");
  const tTotal  = document.getElementById("t_total");
  const tChange = document.getElementById("t_change");

  let lines = {}; // {id: {name, price, qty, cat}}

  function syncPaymentUI(){
    const m = (payment.value || "").toUpperCase();
    const isCash = (m === "EFECTIVO");
    cashBox.style.display = isCash ? "block" : "none";
    if (!isCash) cashInp.value = "";
    recalc();
  }

  function filterItems(){
    const c = Number(catFilter.value||0);
    [...itemSel.options].forEach((opt, idx)=>{
      if (idx===0) return;
      const oc = Number(opt.dataset.cat||0);
      opt.hidden = (c>0 && oc!==c);
    });
    if (itemSel.selectedOptions[0] && itemSel.selectedOptions[0].hidden) itemSel.value="0";
  }

  function render(){
    tbody.innerHTML = "";
    hidden.innerHTML = "";
    Object.keys(lines).forEach((id)=>{
      const it = lines[id];
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td><strong>${it.name}</strong></td>
        <td>
          <input type="number" min="1" value="${it.qty}" style="max-width:90px"
            data-id="${id}" class="qtyLine">
        </td>
        <td class="money">${money(it.price)}</td>
        <td class="money">${money(it.price*it.qty)}</td>
      `;
      tbody.appendChild(tr);

      const inp = document.createElement("input");
      inp.type="hidden";
      inp.name = `lines[${id}]`;
      inp.value = String(it.qty);
      hidden.appendChild(inp);
    });

    [...document.querySelectorAll(".qtyLine")].forEach(inp=>{
      inp.addEventListener("input", ()=>{
        const id = inp.dataset.id;
        const q  = Math.max(1, parseInt(inp.value||"1",10));
        lines[id].qty = q;
        render();
        recalc();
      });
    });
  }

  function recalc(){
    let subtotal = 0;
    Object.keys(lines).forEach((id)=>{
      const it = lines[id];
      subtotal += (Number(it.price)||0) * (Number(it.qty)||0);
    });

    const cov = Math.max(0, Number(covInp.value||0));
    const total = Math.max(0, subtotal - cov);

    let change = 0;
    if ((payment.value||"").toUpperCase()==="EFECTIVO"){
      const cash = Number(cashInp.value||0);
      change = cash - total;
    } else {
      change = 0;
    }

    tSub.textContent = money(subtotal);
    tCov.textContent = money(cov);
    tTotal.textContent = money(total);
    tChange.textContent = money(change);
  }

  btnAdd.addEventListener("click", ()=>{
    const opt = itemSel.selectedOptions[0];
    const id  = Number(itemSel.value||0);
    const qty = Math.max(1, parseInt(qtyInp.value||"1",10));
    if (!id || !opt) return;

    const name = opt.textContent.trim();
    const price = Number(opt.dataset.price||0);
    const cat = Number(opt.dataset.cat||0);

    if (!lines[id]) lines[id] = {name, price, qty:0, cat};
    lines[id].qty += qty;

    render();
    recalc();
  });

  payment.addEventListener("change", syncPaymentUI);
  cashInp.addEventListener("input", recalc);
  covInp.addEventListener("input", recalc);
  catFilter.addEventListener("change", ()=>{
    filterItems();
  });

  // init
  filterItems();
  syncPaymentUI();
  recalc();
})();
</script>

  </main>
</div>

<footer class="footer">
  © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>