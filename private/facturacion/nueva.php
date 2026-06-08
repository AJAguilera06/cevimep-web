<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";

// si existe tu librería de caja, la mantenemos
$maybeCajaLib = __DIR__ . "/../caja/caja_lib.php";
if (file_exists($maybeCajaLib)) {
  require_once $maybeCajaLib;
}

$conn = $pdo;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

function columnExists(PDO $pdo, string $table, string $column): bool {
  // MySQL/MariaDB
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$column]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    // PostgreSQL / others
    try {
      $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ? LIMIT 1");
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e2) {
      return false;
    }
  }
}

function tableColumns(PDO $pdo, string $table): array {
  // MySQL/MariaDB
  try {
    $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cols = [];
    foreach ($rows as $r) $cols[] = $r["Field"];
    return $cols;
  } catch (Throwable $e) {
    // PostgreSQL / others
    try {
      $st = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position");
      $st->execute([$table]);
      $cols = [];
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $cols[] = $r["column_name"];
      }
      return $cols;
    } catch (Throwable $e2) {
      return [];
    }
  }
}

function tableExists(PDO $pdo, string $table): bool {
  // MySQL/MariaDB
  try {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    // PostgreSQL / others (information_schema)
    try {
      $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = ? LIMIT 1");
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e2) {
      return false;
    }
  }
}

function number0($v): float {
  if ($v === null) return 0.0;
  if (is_string($v)) $v = str_replace([",", " "], ["", ""], $v);
  return (float)$v;
}

function invoicePrefixByBranch(int $branchId): string {
  $map = [
    1 => "M",   // Moca
    2 => "L",   // La Vega
    3 => "SC",  // Salcedo
    4 => "S",   // Santiago
    5 => "V",   // Mao
    6 => "P",   // Puerto Plata
  ];

  return $map[$branchId] ?? "F";
}

function buildInvoiceCode(int $branchId, int $number): string {
  return invoicePrefixByBranch($branchId) . "-" . str_pad((string)$number, 7, "0", STR_PAD_LEFT);
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

/* ===============================
   FALLBACK CAJA (si no hay función)
   =============================== */
function cajaFallbackInsert(PDO $conn, int $branchId, int $userId, int $invoiceId, float $amount, string $method, string $desc): void {
  $candidates = ["cash_movements", "caja_movimientos", "caja_transacciones", "cash_transactions", "movimientos_caja"];
  $table = null;
  foreach ($candidates as $t) {
    if (tableExists($conn, $t)) { $table = $t; break; }
  }
  if ($table === null) return;

  $cols = tableColumns($conn, $table);

  $pick = function(array $opts) use ($cols) {
    foreach ($opts as $o) if (in_array($o, $cols, true)) return $o;
    return null;
  };

  $cBranch = $pick(["branch_id","sucursal_id","branch","sucursal"]);
  $cSesion = $pick(["caja_sesion_id","session_id","cash_session_id"]);
  $cUser   = $pick(["created_by","user_id","usuario_id","user","uid"]);
  $cType   = $pick(["type","tipo","movement_type","movimiento","categoria"]);
  $cAmount = $pick(["amount","monto","total","importe","valor"]);
  $cMethod = $pick(["metodo_pago","method","metodo","payment_method","forma_pago"]);
  $cMotivo = $pick(["motivo","description","descripcion","concepto","detalle","nota","notes"]);
  $cRef    = $pick(["reference","ref","invoice_id","factura_id","referencia"]);
  $cAt     = $pick(["created_at","fecha","created_on","created"]);

  if ($cAmount === null) return;

  $method = strtolower(trim($method));
  if ($method === "cash") $method = "efectivo";
  if ($method === "card") $method = "tarjeta";
  if ($method === "transfer") $method = "transferencia";

  $cajaSesionId = 0;
  if ($cSesion !== null) {
    if (function_exists("caja_get_or_create_session_id")) {
      $cajaSesionId = (int)caja_get_or_create_session_id($conn, $branchId);
    }

    if ($cajaSesionId <= 0) {
      $fecha = date("Y-m-d");
      $stSesion = $conn->prepare("
        SELECT id
        FROM caja_sesiones
        WHERE branch_id = ?
          AND fecha = ?
          AND estado = 'abierta'
        ORDER BY caja_num ASC, id DESC
        LIMIT 1
      ");
      $stSesion->execute([$branchId, $fecha]);
      $cajaSesionId = (int)($stSesion->fetchColumn() ?: 0);
    }

    if ($cajaSesionId <= 0) {
      throw new RuntimeException("Debe abrir una caja antes de registrar facturas o ingresos.");
    }
  }

  $fields = [];
  $vals   = [];

  if ($cBranch) { $fields[] = $cBranch; $vals[] = $branchId; }
  if ($cSesion) { $fields[] = $cSesion; $vals[] = $cajaSesionId; }
  if ($cUser)   { $fields[] = $cUser;   $vals[] = $userId > 0 ? $userId : null; }
  if ($cType)   { $fields[] = $cType;   $vals[] = "ingreso"; }
  if ($cMotivo) { $fields[] = $cMotivo; $vals[] = $desc; }
  $fields[] = $cAmount; $vals[] = $amount;

  if ($cMethod) { $fields[] = $cMethod; $vals[] = $method; }
  if ($cRef)    { $fields[] = $cRef;    $vals[] = $invoiceId; }
  if ($cAt)     { $fields[] = $cAt;     $vals[] = date("Y-m-d H:i:s"); }

  $place = implode(",", array_fill(0, count($fields), "?"));
  $sql = "INSERT INTO `$table` (`" . implode("`,`", $fields) . "`) VALUES ($place)";
  $st = $conn->prepare($sql);
  $st->execute($vals);
}

/* ===============================
   CONTEXTO
   =============================== */
$user = $_SESSION["user"] ?? [];
$patient_id = (int)($_GET["patient_id"] ?? 0);

// branch_id de sesión (obligatorio)
$branch_id = (int)($user["branch_id"] ?? 0);

// fallback si no viene
if ($branch_id <= 0 && isset($user["id"])) {
  try {
    $stB = $conn->prepare("SELECT branch_id FROM users WHERE id=? LIMIT 1");
    $stB->execute([(int)$user["id"]]);
    $branch_id = (int)($stB->fetchColumn() ?: 0);
  } catch (Throwable $e) {}
}

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

/* ===== detectar columnas inventory_items ===== */
$invCols = tableColumns($conn, "inventory_items");

$colItemId = "id";

// category
$colCat = "category_id";
if (in_array("cat_id", $invCols, true)) $colCat = "cat_id";
if (in_array("category", $invCols, true)) $colCat = "category";

// name
$colName = "name";
if (in_array("item_name", $invCols, true)) $colName = "item_name";
if (in_array("descripcion", $invCols, true)) $colName = "descripcion";

// price
$colPrice = "sale_price";
if (in_array("price", $invCols, true)) $colPrice = "price";
if (in_array("unit_price", $invCols, true)) $colPrice = "unit_price";
if (in_array("precio", $invCols, true)) $colPrice = "precio";

// active/status
$colActive = null;
if (in_array("active", $invCols, true)) $colActive = "active";
elseif (in_array("is_active", $invCols, true)) $colActive = "is_active";
elseif (in_array("status", $invCols, true)) $colActive = "status";

/* ===== detectar tabla de stock ===== */
$stockTable = null;
$stockCandidates = ["inventory_stock","inventory_branch_stock","inventario_stock","inventory_sucursal_stock","stock"];
foreach ($stockCandidates as $t) {
  if (tableExists($conn, $t)) { $stockTable = $t; break; }
}

$stockItemCol = null;
$stockBranchCol = null;
$stockQtyCol = null;

if ($stockTable !== null) {
  $stockCols = tableColumns($conn, $stockTable);

  if (in_array("item_id", $stockCols, true)) $stockItemCol = "item_id";
  elseif (in_array("product_id", $stockCols, true)) $stockItemCol = "product_id";
  elseif (in_array("inventory_item_id", $stockCols, true)) $stockItemCol = "inventory_item_id";

  if (in_array("branch_id", $stockCols, true)) $stockBranchCol = "branch_id";
  elseif (in_array("sucursal_id", $stockCols, true)) $stockBranchCol = "sucursal_id";
  elseif (in_array("branch", $stockCols, true)) $stockBranchCol = "branch";

  if (in_array("quantity", $stockCols, true)) $stockQtyCol = "quantity";
  elseif (in_array("qty", $stockCols, true)) $stockQtyCol = "qty";
  elseif (in_array("existencia", $stockCols, true)) $stockQtyCol = "existencia";
  elseif (in_array("stock", $stockCols, true)) $stockQtyCol = "stock";
  elseif (in_array("balance", $stockCols, true)) $stockQtyCol = "balance";

  if ($stockItemCol === null || $stockBranchCol === null) {
    $stockTable = null; // inutilizable
  }
}

/* fallback: branch_id dentro de inventory_items */
$colBranchItems = null;
if (in_array("branch_id", $invCols, true)) $colBranchItems = "branch_id";
elseif (in_array("sucursal_id", $invCols, true)) $colBranchItems = "sucursal_id";
elseif (in_array("branch", $invCols, true)) $colBranchItems = "branch";

/* ===== cargar items por sucursal ===== */
$items_all = [];
try {
  if ($branch_id <= 0) throw new RuntimeException("No se pudo determinar la sucursal del usuario.");

  if ($stockTable !== null) {
    $sql = "
      SELECT 
        CASE 
          WHEN ip.id IS NULL THEN CONCAT('i_', i.$colItemId)
          ELSE CONCAT('p_', ip.id)
        END AS line_key,
        i.$colItemId AS item_id,
        ip.id AS price_id,
        i.$colCat AS category_id,
        CASE 
          WHEN ip.id IS NULL THEN i.$colName
          ELSE CONCAT(i.$colName, ' ', ip.price_name)
        END AS name,
        COALESCE(ip.sale_price, i.$colPrice) AS sale_price,
        COALESCE(ip.stock_discount, 1.00) AS stock_discount
      FROM inventory_items i
      INNER JOIN $stockTable s ON s.$stockItemCol = i.$colItemId
      LEFT JOIN item_prices ip ON ip.item_id = i.$colItemId AND ip.is_active = 1
      WHERE s.$stockBranchCol = ?
    ";
    $params = [$branch_id];

    if ($stockQtyCol !== null) $sql .= " AND COALESCE(s.$stockQtyCol,0) > 0";
    if ($colActive !== null) $sql .= " AND i.$colActive=1";
    $sql .= " ORDER BY i.$colName ASC, ip.price_name ASC";

    $st = $conn->prepare($sql);
    $st->execute($params);
    $items_all = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } else {
    $sql = "
      SELECT 
        CASE 
          WHEN ip.id IS NULL THEN CONCAT('i_', i.$colItemId)
          ELSE CONCAT('p_', ip.id)
        END AS line_key,
        i.$colItemId AS item_id,
        ip.id AS price_id,
        i.$colCat AS category_id,
        CASE 
          WHEN ip.id IS NULL THEN i.$colName
          ELSE CONCAT(i.$colName, ' ', ip.price_name)
        END AS name,
        COALESCE(ip.sale_price, i.$colPrice) AS sale_price,
        COALESCE(ip.stock_discount, 1.00) AS stock_discount
      FROM inventory_items i
      LEFT JOIN item_prices ip ON ip.item_id = i.$colItemId AND ip.is_active = 1
    ";
    $where = [];
    $params = [];
    if ($colActive !== null) $where[] = "i.$colActive=1";
    if ($colBranchItems !== null) { $where[] = "i.$colBranchItems=?"; $params[] = $branch_id; }
    if ($where) $sql .= " WHERE " . implode(" AND ", $where);
    $sql .= " ORDER BY i.$colName ASC, ip.price_name ASC";

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
    $payment_method  = mb_strtoupper(trim((string)($_POST["payment_method"] ?? "EFECTIVO")));
    $cash_received   = ($_POST["cash_received"] ?? null);
    $coverage_amount = number0($_POST["coverage_amount"] ?? 0);

    // Pago mixto: permite dividir el total entre efectivo, tarjeta y transferencia.
    $mixed_cash     = number0($_POST["mixed_cash_amount"] ?? 0);
    $mixed_card     = number0($_POST["mixed_card_amount"] ?? 0);
    $mixed_transfer = number0($_POST["mixed_transfer_amount"] ?? 0);

    $cash_received   = ($cash_received === "" || $cash_received === null) ? null : number0($cash_received);
    $representative  = trim((string)($_POST["representative"] ?? ""));

    if ($representative === "") throw new Exception("Debe ingresar el representante.");

    $lines = $_POST["lines"] ?? [];
    if (!is_array($lines) || count($lines) === 0) throw new Exception("Debe agregar al menos un producto.");

    $cleanLines = [];
    foreach ($lines as $k => $v) {
      $key = (string)$k;
      $qty = (int)$v;

      // Aceptamos solo claves del formulario:
      // p_# = variante de precio en item_prices
      // i_# = producto normal en inventory_items
      if ($qty > 0 && preg_match('/^(p|i)_\d+$/', $key)) {
        $cleanLines[$key] = $qty;
      }
    }
    if (count($cleanLines) === 0) throw new Exception("Debe agregar productos con cantidad válida.");

    $priceIds = [];
    $itemIds  = [];
    foreach (array_keys($cleanLines) as $key) {
      if (str_starts_with($key, "p_")) $priceIds[] = (int)substr($key, 2);
      if (str_starts_with($key, "i_")) $itemIds[]  = (int)substr($key, 2);
    }

    /* Mapa de líneas válidas por sucursal */
    $map = [];

    if ($priceIds) {
      $inP = implode(",", array_fill(0, count($priceIds), "?"));

      if ($stockTable !== null) {
        $sqlMapP = "
          SELECT 
            CONCAT('p_', ip.id) AS line_key,
            i.$colItemId AS item_id,
            ip.id AS price_id,
            i.$colCat AS category_id,
            CONCAT(i.$colName, ' ', ip.price_name) AS name,
            ip.sale_price AS sale_price,
            ip.stock_discount AS stock_discount
          FROM item_prices ip
          INNER JOIN inventory_items i ON i.$colItemId = ip.item_id
          INNER JOIN $stockTable s ON s.$stockItemCol = i.$colItemId
          WHERE ip.id IN ($inP)
            AND ip.is_active = 1
            AND s.$stockBranchCol = ?
        ";
        $paramsMapP = array_merge($priceIds, [$branch_id]);
        if ($stockQtyCol !== null) $sqlMapP .= " AND COALESCE(s.$stockQtyCol,0) > 0";
        if ($colActive !== null) $sqlMapP .= " AND i.$colActive=1";
      } else {
        $sqlMapP = "
          SELECT 
            CONCAT('p_', ip.id) AS line_key,
            i.$colItemId AS item_id,
            ip.id AS price_id,
            i.$colCat AS category_id,
            CONCAT(i.$colName, ' ', ip.price_name) AS name,
            ip.sale_price AS sale_price,
            ip.stock_discount AS stock_discount
          FROM item_prices ip
          INNER JOIN inventory_items i ON i.$colItemId = ip.item_id
          WHERE ip.id IN ($inP)
            AND ip.is_active = 1
        ";
        $paramsMapP = $priceIds;
        if ($colBranchItems !== null) { $sqlMapP .= " AND i.$colBranchItems=?"; $paramsMapP[] = $branch_id; }
        if ($colActive !== null) $sqlMapP .= " AND i.$colActive=1";
      }

      $stMapP = $conn->prepare($sqlMapP);
      $stMapP->execute($paramsMapP);
      foreach (($stMapP->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $map[(string)$r["line_key"]] = $r;
      }
    }

    if ($itemIds) {
      $inI = implode(",", array_fill(0, count($itemIds), "?"));

      if ($stockTable !== null) {
        $sqlMapI = "
          SELECT 
            CONCAT('i_', i.$colItemId) AS line_key,
            i.$colItemId AS item_id,
            NULL AS price_id,
            i.$colCat AS category_id,
            i.$colName AS name,
            i.$colPrice AS sale_price,
            1.00 AS stock_discount
          FROM inventory_items i
          INNER JOIN $stockTable s ON s.$stockItemCol = i.$colItemId
          WHERE i.$colItemId IN ($inI)
            AND s.$stockBranchCol = ?
        ";
        $paramsMapI = array_merge($itemIds, [$branch_id]);
        if ($stockQtyCol !== null) $sqlMapI .= " AND COALESCE(s.$stockQtyCol,0) > 0";
        if ($colActive !== null) $sqlMapI .= " AND i.$colActive=1";
      } else {
        $sqlMapI = "
          SELECT 
            CONCAT('i_', $colItemId) AS line_key,
            $colItemId AS item_id,
            NULL AS price_id,
            $colCat AS category_id,
            $colName AS name,
            $colPrice AS sale_price,
            1.00 AS stock_discount
          FROM inventory_items
          WHERE $colItemId IN ($inI)
        ";
        $paramsMapI = $itemIds;
        if ($colBranchItems !== null) { $sqlMapI .= " AND $colBranchItems=?"; $paramsMapI[] = $branch_id; }
        if ($colActive !== null) $sqlMapI .= " AND $colActive=1";
      }

      $stMapI = $conn->prepare($sqlMapI);
      $stMapI->execute($paramsMapI);
      foreach (($stMapI->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $map[(string)$r["line_key"]] = $r;
      }
    }

    $subtotal = 0.0;
    foreach ($cleanLines as $lineKey => $q) {
      if (!isset($map[(string)$lineKey])) continue;
      $price = (float)$map[(string)$lineKey]["sale_price"];
      $subtotal += ($price * (int)$q);
    }

    // Total de la factura: SOLO descuenta cobertura.
    // Los pagos en efectivo/tarjeta/transferencia NO reducen el total impreso,
    // porque son métodos de pago, no descuentos.
    $amount_due = max(0.0, $subtotal - $coverage_amount);
    $mixed_total = $mixed_cash + $mixed_card + $mixed_transfer;
    $total = $amount_due;

    $change_due = null;
    if ($payment_method === "EFECTIVO") {
      $change_due = max(0.0, (float)number0($cash_received ?? 0) - $amount_due);
    } elseif ($payment_method === "MIXTO") {
      if ($mixed_total <= 0) {
        throw new Exception("Debe ingresar al menos un monto para el pago mixto.");
      }

      $cash_received = $mixed_cash > 0 ? $mixed_cash : null;
      $change_due = max(0.0, $mixed_total - $amount_due);
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
// ✅ Numeración independiente por sucursal + serie
$invoice_number = 1;
$invoice_code   = buildInvoiceCode($branch_id, $invoice_number);

try {
  if (columnExists($conn, "invoices", "invoice_number")) {
    $stNum = $conn->prepare("
      SELECT COALESCE(MAX(invoice_number), 0) + 1
      FROM invoices
      WHERE branch_id = ?
    ");
    $stNum->execute([$branch_id]);

    $invoice_number = (int)$stNum->fetchColumn();
    if ($invoice_number <= 0) {
      $invoice_number = 1;
    }
  }

  $invoice_code = buildInvoiceCode($branch_id, $invoice_number);
} catch (Throwable $e) {
  $invoice_number = 1;
  $invoice_code   = buildInvoiceCode($branch_id, $invoice_number);
}
    $fields = [
  "patient_id",
  "invoice_date",
  "payment_method",
  "subtotal",
  "total"
];

$vals = [
  $patient_id,
  $invoice_date,
  $payment_method,
  $subtotal,
  $total
];

// ✅ guardar número independiente por sucursal
if (columnExists($conn, "invoices", "invoice_number")) {
  $fields[] = "invoice_number";
  $vals[]   = $invoice_number;
}

// ✅ guardar código de factura con serie de sucursal: M-0000001, S-0000001, etc.
if (columnExists($conn, "invoices", "invoice_code")) {
  $fields[] = "invoice_code";
  $vals[]   = $invoice_code;
}

    if ($hasBranch) { $fields[]="branch_id"; $vals[]=$branch_id; }
    if ($hasNotes)  { $fields[]="notes"; $vals[]=($notes ?? null); }
    if ($hasCreated){ $fields[]="created_by"; $vals[]=(int)($user["id"] ?? 0); }
    if ($hasCov)    { $fields[]="coverage_amount"; $vals[]=$coverage_amount; }
    if ($hasCash)   { $fields[]="cash_received"; $vals[]=$cash_received; }
    if ($hasChange) { $fields[]="change_due"; $vals[]=$change_due; }
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

    foreach ($cleanLines as $lineKey => $q) {
      if (!isset($map[(string)$lineKey])) continue;
      $row  = $map[(string)$lineKey];
      $unit = (float)$row["sale_price"];
      $lt   = $unit * (int)$q;
      $iid  = (int)$row["item_id"];

      $args = [$invoice_id, $iid, (int)$q];
      if ($hasUnit) $args[] = $unit;
      if ($hasLineTotal) $args[] = $lt;

      $stLine->execute($args);
    }

    /* ===============================
       ✅ DESCONTAR INVENTARIO (POR SUCURSAL)
       =============================== */
    if ($stockTable !== null && $stockQtyCol !== null) {
      // update seguro: no permite stock negativo.
      // Usa stock_discount de item_prices:
      // Havrix ADULTOS = 1.00
      // Havrix NIÑOS   = 0.50
      $sqlUpd = "UPDATE $stockTable
                 SET $stockQtyCol = $stockQtyCol - ?
                 WHERE $stockBranchCol = ?
                   AND $stockItemCol = ?
                   AND COALESCE($stockQtyCol,0) >= ?";
      $stUpd = $conn->prepare($sqlUpd);

      foreach ($cleanLines as $lineKey => $q) {
        if (!isset($map[(string)$lineKey])) continue;

        $iid = (int)$map[(string)$lineKey]["item_id"];
        $stockDiscount = (float)$map[(string)$lineKey]["stock_discount"];
        $qtyToDiscount = $stockDiscount * (int)$q;

        if ($iid <= 0 || $qtyToDiscount <= 0) continue;

        $stUpd->execute([$qtyToDiscount, $branch_id, $iid, $qtyToDiscount]);
        if ($stUpd->rowCount() <= 0) {
          throw new RuntimeException("Stock insuficiente para el producto ID #{$iid} en esta sucursal.");
        }
      }
    } elseif ($stockTable !== null) {
      // hay tabla stock pero no detectó columna cantidad (no debería pasar con inventory_stock.quantity)
    }
/* ===============================
       ✅ CAJA: registrar ingresos
       =============================== */
    $uid = (int)($user["id"] ?? 0);
    $pm  = strtolower(trim((string)$payment_method));
    if ($pm === "cash") $pm = "efectivo";
    if ($pm === "card") $pm = "tarjeta";
    if ($pm === "transfer") $pm = "transferencia";

    $paymentsToCaja = [];

    if ($payment_method === "MIXTO") {
      if ($mixed_cash > 0)     $paymentsToCaja[] = ["efectivo", $mixed_cash];
      if ($mixed_card > 0)     $paymentsToCaja[] = ["tarjeta", $mixed_card];
      if ($mixed_transfer > 0) $paymentsToCaja[] = ["transferencia", $mixed_transfer];
    } else {
      $paymentsToCaja[] = [$pm, (float)$amount_due];
    }

    // 1) si existe función oficial, úsala
    if (function_exists("caja_registrar_ingreso_factura")) {
      try {
        foreach ($paymentsToCaja as $pay) {
          caja_registrar_ingreso_factura($conn, (int)$branch_id, $uid, (int)$invoice_id, (float)$pay[1], (string)$pay[0]);
        }

        if ((float)$coverage_amount > 0) {
          caja_registrar_ingreso_factura($conn, (int)$branch_id, $uid, (int)$invoice_id, (float)$coverage_amount, "cobertura");
        }
      } catch (Throwable $e) {
        // 2) fallback si falla
        foreach ($paymentsToCaja as $pay) {
          cajaFallbackInsert($conn, (int)$branch_id, $uid, (int)$invoice_id, (float)$pay[1], (string)$pay[0], "Ingreso por factura #{$invoice_id}");
        }
        if ((float)$coverage_amount > 0) {
          cajaFallbackInsert($conn, (int)$branch_id, $uid, (int)$invoice_id, (float)$coverage_amount, "cobertura", "Cobertura factura #{$invoice_id}");
        }
      }
    } else {
      // 2) fallback directo
      foreach ($paymentsToCaja as $pay) {
        cajaFallbackInsert($conn, (int)$branch_id, $uid, (int)$invoice_id, (float)$pay[1], (string)$pay[0], "Ingreso por factura #{$invoice_id}");
      }
      if ((float)$coverage_amount > 0) {
        cajaFallbackInsert($conn, (int)$branch_id, $uid, (int)$invoice_id, (float)$coverage_amount, "cobertura", "Cobertura factura #{$invoice_id}");
      }
    }

    $conn->commit();

    $last_invoice_id = $invoice_id;
    $ok = "Factura creada ({$invoice_code}).";
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
    .page-wrap{max-width:1400px !important; width:100% !important;margin:0 auto;padding:18px}
    .card{background:#fff;border-radius:18px;box-shadow:0 10px 25px rgba(0,0,0,.08);padding:18px;width:100%}
    .title{font-size:34px;font-weight:900;text-align:center;margin:10px 0 8px}
    .subtitle{font-size:14px;color:#334155;text-align:center;margin-bottom:10px;font-weight:600}
    .alert{padding:10px 12px;border-radius:12px;margin:10px auto;max-width:1100px}
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
    .btn.light{background:#eef2ff;color:#1e3a8a;border-color:#dbeafe;text-decoration:none;display:inline-flex;align-items:center}
    .btn.delete{height:32px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:0 10px;border-radius:10px;font-weight:900;cursor:pointer}
    .lines{width:100%;border-collapse:separate;border-spacing:0;margin-top:10px}
    .lines th,.lines td{padding:10px 10px;border-bottom:1px solid #eef2f6;font-size:13px}
    .lines th{color:#0b4d87;text-align:left;font-weight:900;font-size:12px}
    .lines tr:last-child td{border-bottom:none}
    .money{white-space:nowrap;font-weight:900}
    .totals{max-width:260px;margin-left:auto;background:#f8fafc;border:1px solid rgba(2,21,44,.08);border-radius:14px;padding:12px}
    .totals .row{display:flex;justify-content:space-between;gap:10px;margin:6px 0;font-weight:900}
    .mini{font-size:12px;opacity:.75;font-weight:700}

    .content{align-items:flex-start;justify-content:center;text-align:left;overflow:auto;padding:24px 0;}
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
                  <option value="MIXTO">MIXTO</option>
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

              <div id="mixed_box" style="grid-column:1 / -1; display:none;">
                <div class="grid3">
                  <div>
                    <label>Efectivo (pago mixto)</label>
                    <input type="number" step="0.01" min="0" name="mixed_cash_amount" id="mixed_cash_amount" value="0.00">
                  </div>
                  <div>
                    <label>Tarjeta (pago mixto)</label>
                    <input type="number" step="0.01" min="0" name="mixed_card_amount" id="mixed_card_amount" value="0.00">
                  </div>
                  <div>
                    <label>Transferencia (pago mixto)</label>
                    <input type="number" step="0.01" min="0" name="mixed_transfer_amount" id="mixed_transfer_amount" value="0.00">
                  </div>
                </div>
                <div class="mini" id="mixed_note" style="margin-top:6px;">
                  Escribe los montos y abajo verás cuánto falta por pagar.
                </div>
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
                    <option 
                      value="<?php echo h($it["line_key"] ?? ('i_' . (int)($it["item_id"] ?? $it["id"] ?? 0))); ?>" 
                      data-item-id="<?php echo (int)($it["item_id"] ?? $it["id"] ?? 0); ?>"
                      data-price-id="<?php echo h($it["price_id"] ?? ""); ?>"
                      data-cat="<?php echo (int)$it["category_id"]; ?>" 
                      data-price="<?php echo h($it["sale_price"]); ?>"
                      data-stock-discount="<?php echo h($it["stock_discount"] ?? "1.00"); ?>"
                    >
                      <?php echo h($it["name"]); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

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
                  <th style="width:50%;">Producto</th>
                  <th style="width:13%;">Cantidad</th>
                  <th style="width:14%;">Precio</th>
                  <th style="width:14%;">Total</th>
                  <th style="width:9%;">Acción</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>

            <div class="totals" style="margin-top:12px;">
              <div class="row"><span>Subtotal</span><span class="money" id="t_sub">RD$ 0.00</span></div>
              <div class="row"><span>Cobertura</span><span class="money" id="t_cov">RD$ 0.00</span></div>
              <div class="row"><span>Total a pagar</span><span class="money" id="t_total">RD$ 0.00</span></div>
              <div class="row"><span>Cambio</span><span class="money" id="t_change">RD$ 0.00</span></div>
              <div class="mini" id="totals_note">* El cambio solo aplica en EFECTIVO.</div>
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

  const mixedBox = document.getElementById("mixed_box");
  const mixedCash = document.getElementById("mixed_cash_amount");
  const mixedCard = document.getElementById("mixed_card_amount");
  const mixedTransfer = document.getElementById("mixed_transfer_amount");
  const mixedNote = document.getElementById("mixed_note");

  const catFilter = document.getElementById("cat_filter");
  const itemSel   = document.getElementById("item_select");
  const qtyInp    = document.getElementById("qty");
  const btnAdd    = document.getElementById("btnAdd");

  const tbody     = document.querySelector("#linesTable tbody");
  const hidden    = document.getElementById("hiddenLines");

  const tSub       = document.getElementById("t_sub");
  const tCov       = document.getElementById("t_cov");
  const tTotal     = document.getElementById("t_total");
  const tChange    = document.getElementById("t_change");
  const totalsNote = document.getElementById("totals_note");

  let lines = {}; // {line_key: {name, price, qty, cat, itemId, priceId, stockDiscount}}

  function syncPaymentUI(){
    const m = (payment.value || "").toUpperCase();
    const isCash = (m === "EFECTIVO");
    const isMixed = (m === "MIXTO");

    cashBox.style.display = isCash ? "block" : "none";
    mixedBox.style.display = isMixed ? "block" : "none";

    if (!isCash) cashInp.value = "";
    if (!isMixed) {
      mixedCash.value = "0.00";
      mixedCard.value = "0.00";
      mixedTransfer.value = "0.00";
    }

    recalc();
  }

  function filterItems(){
    const c = Number(catFilter.value||0);
    [...itemSel.options].forEach((opt, idx)=>{
      if (idx === 0) return;
      const oc = Number(opt.getAttribute("data-cat")||0);
      opt.style.display = (c===0 || c===oc) ? "" : "none";
    });
    itemSel.value = "0";
  }

  function recalc(){
    let sub = 0;
    Object.keys(lines).forEach((id)=>{
      const ln = lines[id];
      sub += (Number(ln.price||0) * Number(ln.qty||0));
    });

    const cov = Number(covInp.value||0);
    const total = Math.max(0, sub - cov);

    const method = (payment.value||"").toUpperCase();
    let change = 0;

    if (method === "EFECTIVO") {
      const cash = Number(cashInp.value||0);
      change = Math.max(0, cash - total);
      if (totalsNote) totalsNote.textContent = "* El total a pagar solo descuenta cobertura.";
    } else if (method === "MIXTO") {
      const mixedPaid = Number(mixedCash.value||0) + Number(mixedCard.value||0) + Number(mixedTransfer.value||0);
      change = Math.max(0, mixedPaid - total);

      if (mixedNote) {
        mixedNote.textContent = "Total recibido: " + money(mixedPaid) + " | Total factura: " + money(total);
      }
      if (totalsNote) totalsNote.textContent = "* Efectivo, tarjeta y transferencia no descuentan el total; solo la cobertura.";
    } else {
      change = 0;
      if (totalsNote) totalsNote.textContent = "* El total a pagar solo descuenta cobertura.";
    }

    tSub.textContent = money(sub);
    tCov.textContent = money(cov);
    tTotal.textContent = money(total);
    tChange.textContent = money(change);
  }

  function render(){
    tbody.innerHTML = "";
    hidden.innerHTML = "";

    Object.keys(lines).forEach((id)=>{
      const ln = lines[id];

      const tr = document.createElement("tr");

      const tdName = document.createElement("td");
      tdName.textContent = ln.name;

      const tdQty = document.createElement("td");
      tdQty.textContent = ln.qty;

      const tdPrice = document.createElement("td");
      tdPrice.textContent = money(ln.price);

      const tdTotal = document.createElement("td");
      tdTotal.textContent = money(Number(ln.price) * Number(ln.qty));

      const tdAction = document.createElement("td");
      const btnDel = document.createElement("button");
      btnDel.type = "button";
      btnDel.className = "btn delete";
      btnDel.textContent = "Eliminar";
      btnDel.addEventListener("click", ()=>{
        delete lines[id];
        render();
      });
      tdAction.appendChild(btnDel);

      tr.appendChild(tdName);
      tr.appendChild(tdQty);
      tr.appendChild(tdPrice);
      tr.appendChild(tdTotal);
      tr.appendChild(tdAction);
      tbody.appendChild(tr);

      // hidden inputs para el POST
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
    const id = String(itemSel.value || "0");
    if (id === "0") return;

    const qty = Math.max(1, Number(qtyInp.value||1));
    const name = opt.textContent.trim();
    const price = Number(opt.getAttribute("data-price")||0);
    const cat = Number(opt.getAttribute("data-cat")||0);
    const itemId = Number(opt.getAttribute("data-item-id")||0);
    const priceId = opt.getAttribute("data-price-id") || "";
    const stockDiscount = Number(opt.getAttribute("data-stock-discount")||1);

    if (!lines[id]) {
      lines[id] = {name, price, qty, cat, itemId, priceId, stockDiscount};
    } else {
      lines[id].qty += qty;
    }

    render();
  });

  catFilter.addEventListener("change", filterItems);
  payment.addEventListener("change", syncPaymentUI);
  cashInp.addEventListener("input", recalc);
  covInp.addEventListener("input", recalc);
  mixedCash.addEventListener("input", recalc);
  mixedCard.addEventListener("input", recalc);
  mixedTransfer.addEventListener("input", recalc);

  filterItems();
  syncPaymentUI();
})();
</script>
<!-- FOOTER (igual dashboard.php) -->
<footer class="footer">
    © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>
</body>
</html>
