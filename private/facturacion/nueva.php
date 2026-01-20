<?php
session_start();
if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }

require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../caja/caja_lib.php";

$conn = $pdo;

$user = $_SESSION["user"];
$year = date("Y");
$today = date("Y-m-d");
$now_dt = date("Y-m-d H:i:s");
$branch_id = (int)($user["branch_id"] ?? 0);
$created_by = (int)($user["id"] ?? 0);

$patient_id = (int)($_GET["patient_id"] ?? $_POST["patient_id"] ?? 0);
if ($patient_id <= 0) { header("Location: index.php"); exit; }

$branch_name = "";
if ($branch_id > 0) {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $branch_name = (string)($stB->fetchColumn() ?? "");
}
if ($branch_name === "") $branch_name = ($branch_id>0) ? "Sede #".$branch_id : "CEVIMEP";

/* Paciente SOLO de esa sucursal */
$stP = $conn->prepare("
  SELECT p.id, p.branch_id, TRIM(CONCAT(p.first_name,' ',p.last_name)) AS full_name
  FROM patients p
  WHERE p.id=? AND p.branch_id=?
  LIMIT 1
");
$stP->execute([$patient_id, $branch_id]);
$patient = $stP->fetch(PDO::FETCH_ASSOC);
if (!$patient) { echo "Paciente no encontrado en esta sucursal."; exit; }

$flash_error = "";

/* Categorías */
$categories = [];
$stC = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
$categories = $stC ? $stC->fetchAll(PDO::FETCH_ASSOC) : [];

/* Productos con precio venta (SOLO DE ESTA SUCURSAL)
   - Si un producto no tiene registro en inventory_stock para la sucursal, NO debe salir.
   - Mostramos tambien los que tengan existencia = 0 (pero se marcaran como "Sin stock").
     Asi puedes ver toda tu lista por sucursal sin mezclar con otras sedes.
*/
$products = [];
if ($branch_id > 0) {
  $st = $conn->prepare("
    SELECT i.id, i.name, i.sale_price, COALESCE(c.id,0) AS category_id, COALESCE(s.quantity,0) AS stock_qty
    FROM inventory_items i
    LEFT JOIN inventory_categories c ON c.id=i.category_id
    INNER JOIN inventory_stock s ON s.item_id=i.id AND s.branch_id=?
    WHERE i.is_active=1
    ORDER BY i.name ASC
  ");
  $st->execute([$branch_id]);
  $products = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* Helper: verificar columna */
function columnExists(PDO $conn, string $table, string $column): bool {
  try {
    $db = $conn->query("SELECT DATABASE()")->fetchColumn();
    $st = $conn->prepare("
      SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?
    ");
    $st->execute([$db, $table, $column]);
    return ((int)$st->fetchColumn() > 0);
  } catch (Exception $e) {
    return false;
  }
}

/* POST: guardar factura */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  if ($branch_id <= 0) {
    $flash_error = "Este usuario no tiene sede asignada. No puede facturar.";
  } else {

    $invoice_date = $_POST["invoice_date"] ?? $today;
    $payment_method = strtoupper(trim($_POST["payment_method"] ?? "EFECTIVO"));
    $cash_received = isset($_POST["cash_received"]) && $_POST["cash_received"] !== "" ? (float)$_POST["cash_received"] : null;
    // ✅ Cobertura (seguro): monto que cubre el seguro
    $coverage_amount = isset($_POST["coverage_amount"]) && $_POST["coverage_amount"] !== "" ? (float)$_POST["coverage_amount"] : 0.0;
    $representative = trim((string)($_POST["representative"] ?? ""));

    $item_ids = $_POST["item_id"] ?? [];
    $qtys     = $_POST["qty"] ?? [];

    $lines = [];
    for ($i=0; $i<count($item_ids); $i++) {
      $iid = (int)($item_ids[$i] ?? 0);
      $q   = (int)($qtys[$i] ?? 0);
      if ($iid > 0 && $q > 0) {
        if (!isset($lines[$iid])) $lines[$iid] = 0;
        $lines[$iid] += $q;
      }
    }

    if (empty($lines)) {
      $flash_error = "Agrega al menos un producto.";
    } else {

      if (!in_array($payment_method, ["EFECTIVO","TARJETA","TRANSFERENCIA"], true)) {
        $payment_method = "EFECTIVO";
      }

      $isCard = ($payment_method === "TARJETA");
      // ✅ Ya NO se agrega 5% por pago con tarjeta
      $card_fee_pct = 0.00;

      try {
        $conn->beginTransaction();

        $ids = array_keys($lines);
        $ph  = implode(",", array_fill(0, count($ids), "?"));

        $stInfo = $conn->prepare("
          SELECT i.id, i.name, i.sale_price, COALESCE(c.id,0) AS category_id
          FROM inventory_items i
          LEFT JOIN inventory_categories c ON c.id=i.category_id
          WHERE i.id IN ($ph) AND i.is_active=1
        ");
        $stInfo->execute($ids);
        $rows = $stInfo->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $r) $map[(int)$r["id"]] = $r;

        $raw_subtotal = 0.00;

        foreach ($lines as $iid => $q) {
          if (!isset($map[(int)$iid])) throw new Exception("Producto inválido (ID $iid).");

          $price = (float)$map[(int)$iid]["sale_price"];

          $stS = $conn->prepare("SELECT quantity FROM inventory_stock WHERE item_id=? AND branch_id=? FOR UPDATE");
          $stS->execute([(int)$iid, $branch_id]);
          $cur = (int)($stS->fetchColumn() ?? 0);

          if ($cur < $q) {
            $name = $map[(int)$iid]["name"] ?? "ID $iid";
            throw new Exception("No hay existencia suficiente para: $name. Disponible: $cur, solicitado: $q.");
          }

          $raw_subtotal += ($price * $q);
        }

        // ✅ Sin recargo por tarjeta
        $card_fee_amount = 0.00;
        $subtotal_with_fee = round($raw_subtotal, 2);

        // ✅ Cobertura (monto) se resta del subtotal
        if ($coverage_amount < 0) $coverage_amount = 0;
        if ($coverage_amount > $subtotal_with_fee) $coverage_amount = $subtotal_with_fee;

        $subtotal = round($subtotal_with_fee, 2);
        $total    = round($subtotal_with_fee - $coverage_amount, 2); // 👈 TOTAL a pagar (después de cobertura)

        $change_due = null;
        if ($payment_method === "EFECTIVO") {
          if ($cash_received === null) $cash_received = 0;
          $change_due = round($cash_received - $total, 2);
          if ($change_due < 0) {
            throw new Exception("Efectivo recibido insuficiente. Falta: " . number_format(abs($change_due), 2));
          }
        } else {
          $cash_received = null;
          $change_due = null;
        }

        // ✅ Inserción flexible (sin tocar DB si no existe la columna)
        $cols = [
          "branch_id", "patient_id", "invoice_date", "payment_method",
          "subtotal", "total", "cash_received", "change_due", "created_by"
        ];
        $vals = [
          $branch_id, $patient_id, $invoice_date, $payment_method,
          $subtotal, $total, $cash_received, $change_due, $created_by
        ];

        // ✅ Guardamos la cobertura en discount_amount si existe (sin tocar base de datos)
        if (columnExists($conn, "invoices", "discount_amount")) {
          $cols[] = "discount_amount";
          $vals[] = $coverage_amount;
        }

        if (columnExists($conn, "invoices", "card_fee_amount")) {
          $cols[] = "card_fee_amount";
          $vals[] = 0.00;
        }

        if (columnExists($conn, "invoices", "card_fee_pct")) {
          $cols[] = "card_fee_pct";
          $vals[] = 0.00;
        }

        // Representante (si existe la columna)
        if ($representative !== "" && columnExists($conn, "invoices", "representative_name")) {
          $cols[] = "representative_name";
          $vals[] = $representative;
        }
        if ($representative !== "" && columnExists($conn, "invoices", "representative")) {
          $cols[] = "representative";
          $vals[] = $representative;
        }

        // Fecha+hora (si existe la columna)
        if (columnExists($conn, "invoices", "created_at")) {
          $cols[] = "created_at";
          $vals[] = $now_dt;
        }
        if (columnExists($conn, "invoices", "created_on")) {
          $cols[] = "created_on";
          $vals[] = $now_dt;
        }

        $ph = implode(",", array_fill(0, count($cols), "?"));
        $sql = "INSERT INTO invoices (" . implode(",", $cols) . ") VALUES ($ph)";
        $stInv = $conn->prepare($sql);
        $stInv->execute($vals);

        $invoice_id = (int)$conn->lastInsertId();

        $stItem = $conn->prepare("
          INSERT INTO invoice_items (invoice_id, item_id, category_id, qty, unit_price, line_total)
          VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stU = $conn->prepare("UPDATE inventory_stock SET quantity = quantity - ? WHERE item_id=? AND branch_id=?");

        $stMov = $conn->prepare("
          INSERT INTO inventory_movements (item_id, branch_id, movement_type, qty, note, created_by)
          VALUES (?, ?, 'OUT', ?, ?, ?)
        ");

        foreach ($lines as $iid => $q) {
          $price = (float)$map[(int)$iid]["sale_price"];
          $catId = (int)($map[(int)$iid]["category_id"] ?? 0);
          $line_total = round($price * $q, 2);

          $stItem->execute([$invoice_id, (int)$iid, $catId, (int)$q, $price, $line_total]);
          $stU->execute([(int)$q, (int)$iid, $branch_id]);

          $note = "VENTA | FACTURA={$invoice_id} | PACIENTE={$patient_id}";
          if ($isCard) $note .= " | TARJETA";
          if ($coverage_amount > 0) $note .= " | COBERTURA=" . number_format($coverage_amount, 2);
          $stMov->execute([(int)$iid, $branch_id, (int)$q, $note, $created_by]);
        }

        // ================================
        // ✅ CONECTAR FACTURACIÓN → CAJA
        // ✅ Cobertura CUENTA como ingreso
        //    - 1) Ingreso por el monto pagado (total a pagar) con el método seleccionado
        //    - 2) Ingreso por Cobertura con método "COBERTURA"
        // ================================

        // 1) Pago del paciente (después de cobertura)
        if ((float)$total > 0) {
          caja_registrar_ingreso_factura(
            $conn,
            (int)$branch_id,
            (int)$created_by,
            (int)$invoice_id,
            (float)$total,
            (string)$payment_method
          );
        }

        // 2) Cobertura como ingreso separado
        if ((float)$coverage_amount > 0) {
          caja_registrar_ingreso_factura(
            $conn,
            (int)$branch_id,
            (int)$created_by,
            (int)$invoice_id,
            (float)$coverage_amount,
            "COBERTURA"
          );

          // Guardar cobertura en invoice_adjustments (si existe la tabla)
          try {
            $session_id = caja_get_or_open_current_session($conn, (int)$branch_id, (int)$created_by);
            if ($session_id <= 0) {
              // fallback: replicar apertura de sesión para no perder trazabilidad
              $today2  = date("Y-m-d");
              $cajaNum2 = caja_get_current_caja_num();
              [$shiftStart2, $shiftEnd2] = caja_shift_times($cajaNum2);
              $insS = $conn->prepare("INSERT INTO cash_sessions (branch_id, caja_num, shift_start, shift_end, date_open, opened_at, opened_by)
                                     VALUES (?, ?, ?, ?, ?, NOW(), ?)");
              $insS->execute([(int)$branch_id, (int)$cajaNum2, (string)$shiftStart2, (string)$shiftEnd2, (string)$today2, (int)$created_by]);
              $session_id = (int)$conn->lastInsertId();
            }

            $stAdj = $conn->prepare("
              INSERT INTO invoice_adjustments
                (invoice_id, session_id, branch_id, tipo, monto, created_at)
              VALUES (?, ?, ?, 'cobertura', ?, CURDATE())
            ");
            $stAdj->execute([
              (int)$invoice_id,
              (int)$session_id,
              (int)$branch_id,
              (float)$coverage_amount
            ]);
          } catch (Exception $eAdj) {
            // Si no existe la tabla invoice_adjustments o falla el insert, no bloqueamos la factura.
          }
        }

        $conn->commit();

        header("Location: print.php?id=".$invoice_id."&rep=".urlencode($representative));
        exit;

      } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $flash_error = $e->getMessage();
      }
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Nueva Factura</title>

  <!-- ✅ ESTÁNDAR -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=11">

  <style>
    /* Solo estilos internos del formulario/tabla (sin tocar layout global) */
    .card-box{
      background:#fff;
      border:1px solid #e6eef7;
      border-radius:22px;
      padding:18px;
      box-shadow:0 10px 30px rgba(2,6,23,.08);
    }
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    @media(max-width:980px){.grid2{grid-template-columns:1fr;}}
    .input, select{width:100%;padding:10px 12px;border:1px solid #e6eef7;border-radius:14px;outline:none;}
    label{font-weight:900;color:#0b3b9a;display:block;margin:0 0 6px;}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:14px;border:1px solid #dbeafe;background:#e0f2fe;color:#052a7a;font-weight:900;text-decoration:none;cursor:pointer;}
    .btn.secondary{background:#fff;border:1px solid #dbeafe;}
    .muted{color:#6b7280;font-weight:700;}
    .row{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;}
    .table{width:100%;border-collapse:collapse;border:1px solid #e6eef7;border-radius:16px;overflow:hidden;}
    .table th,.table td{padding:10px;border-bottom:1px solid #eef2f7;font-size:13px;}
    .table thead th{background:#f7fbff;color:#0b3b9a;font-weight:900;}
    .qtyRight{text-align:right;}
  </style>
</head>
<body>

<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right">
      <a class="btn-pill" href="/logout.php">Salir</a>
    </div>
  </div>
</header>

<div class="layout">
  <aside class="sidebar">
    <div class="menu-title">Menú</div>
    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👥</span> Pacientes</a>
      <a href="javascript:void(0)" style="opacity:.55; cursor:not-allowed;"><span class="ico">🗓️</span> Citas</a>
      <a class="active" href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadistica/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <section class="hero">
      <h1>Nueva factura</h1>
      <p>Paciente: <strong><?= htmlspecialchars($patient["full_name"]) ?></strong></p>
    </section>

    <section class="card">
      <div class="row">
        <div>
          <h2 style="margin:0; color:var(--primary-2);">Nueva factura</h2>
          <div class="muted">Paciente: <b><?= htmlspecialchars($patient["full_name"]) ?></b></div>
        </div>
        <a class="btn secondary" href="index.php">Volver</a>
      </div>

      <?php if($flash_error): ?>
        <div style="margin-top:12px;padding:12px 14px;border-radius:14px;background:#fff1f2;border:1px solid #fecdd3;color:#991b1b;font-weight:900;">
          <?= htmlspecialchars($flash_error) ?>
        </div>
      <?php endif; ?>

      <form method="post" id="frmInvoice" style="margin-top:14px;">
        <input type="hidden" name="patient_id" value="<?= (int)$patient_id ?>">
        <input type="hidden" name="grand_total" id="grandTotalInput" value="0">

        <div class="grid2">
          <div>
            <label>Área desde donde sale la venta (sucursal)</label>
            <input class="input" type="text" value="<?= htmlspecialchars($branch_name) ?>" disabled>
          </div>

          <div>
            <label>Fecha</label>
            <input class="input" type="date" name="invoice_date" value="<?= htmlspecialchars($today) ?>">
          </div>

          <div>
            <label>Nombre del paciente</label>
            <input class="input" type="text" value="<?= htmlspecialchars($patient["full_name"]) ?>" disabled>
          </div>

          <div>
            <label>Representante</label>
            <input class="input" type="text" name="representative" placeholder="Nombre de quien realizó la factura">
          </div>

          <div>
            <label>Método de pago</label>
            <select class="input" name="payment_method" id="payMethod">
              <option value="EFECTIVO">Efectivo</option>
              <option value="TARJETA">Tarjeta</option>
              <option value="TRANSFERENCIA">Transferencia</option>
            </select>
          </div>

          <div id="cashBox">
            <label>Efectivo recibido</label>
            <input class="input" type="number" step="0.01" min="0" name="cash_received" id="cashReceived" placeholder="Ej: 1000">
          </div>

          <div>
            <label>Devuelta</label>
            <input class="input" type="text" id="changeDue" value="0.00" disabled>
          </div>

          <div>
            <label>Cobertura</label>
            <input class="input" type="number" step="0.01" min="0" name="coverage_amount" id="coverageAmount" value="0">
          </div>

          <div>
            <label>Total</label>
            <input class="input" type="text" id="totalBox" value="0.00" disabled>
          </div>
        </div>

        <hr style="margin:16px 0;border:none;border-top:1px solid #eef2f7;">

        <div class="grid2" style="grid-template-columns:200px 1fr 140px 140px;">
          <div>
            <label>Categoría</label>
            <select class="input" id="selCat">
              <option value="0">-- Todas --</option>
              <?php foreach($categories as $c): ?>
                <option value="<?= (int)$c["id"] ?>"><?= htmlspecialchars($c["name"]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label>Producto</label>
            <select class="input" id="selItem">
              <option value="0">-- Seleccionar --</option>
              <?php foreach($products as $p): ?>
                <?php $stk = (int)($p['stock_qty'] ?? 0); ?>
                <option value="<?= (int)$p['id'] ?>"
                        data-price="<?= htmlspecialchars($p['sale_price']) ?>"
                        data-cat-id="<?= (int)$p['category_id'] ?>"
                        data-stock="<?= $stk ?>">
                  <?= htmlspecialchars($p['name']) ?><?= ($stk <= 0 ? ' (Sin stock)' : '') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label>Cantidad</label>
            <input class="input" id="qty" type="number" min="1" value="1">
          </div>

          <div style="display:flex;align-items:flex-end;">
            <button class="btn" type="button" id="btnAdd">Añadir</button>
          </div>
        </div>

        <table class="table" style="margin-top:12px;">
          <thead>
            <tr>
              <th>Producto</th>
              <th style="width:140px;" class="qtyRight">Cantidad</th>
              <th style="width:160px;" class="qtyRight">Precio</th>
              <th style="width:160px;" class="qtyRight">Total</th>
              <th style="width:140px;">Acción</th>
            </tr>
          </thead>
          <tbody id="tbodyItems">
            <tr id="emptyRow">
              <td colspan="5" class="muted">No hay productos agregados.</td>
            </tr>
          </tbody>
        </table>

        <div style="display:flex;justify-content:flex-end;gap:18px;align-items:center;margin-top:14px;flex-wrap:wrap;">
          <div style="text-align:right;">
            <div class="muted">Subtotal</div>
            <div style="font-weight:900;font-size:18px;" id="subTotal">0.00</div>
          </div>
          <div style="text-align:right;">
            <div class="muted">Cobertura</div>
            <div style="font-weight:900;font-size:18px;" id="discTotal">0.00</div>
          </div>
          <div style="text-align:right;">
            <div class="muted">Total</div>
            <div style="font-weight:900;font-size:20px;" id="grandTotal">0.00</div>
          </div>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-top:14px;">
          <button type="submit" class="btn">Realizar orden</button>
        </div>

      </form>
    </section>
  </main>
</div>

<footer class="footer">
  <div class="footer-inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

<script>
(function(){
  const selCat = document.getElementById('selCat');
  const selItem = document.getElementById('selItem');
  const qty = document.getElementById('qty');
  const btn = document.getElementById('btnAdd');
  const tbody = document.getElementById('tbodyItems');

  const payMethod = document.getElementById('payMethod');
  const cashBox = document.getElementById('cashBox');
  const cashReceived = document.getElementById('cashReceived');
  const changeDue = document.getElementById('changeDue');

  const coverageAmount = document.getElementById('coverageAmount');

  const subTotalEl = document.getElementById('subTotal');
  const discTotalEl = document.getElementById('discTotal');
  const grandTotalEl = document.getElementById('grandTotal');
  const grandTotalInput = document.getElementById('grandTotalInput');

  // mantener sincronizado el campo "Total" de arriba
  const totalBox = document.getElementById('totalBox');

  function filterProducts(){
    const catId = parseInt(selCat.value||"0",10);
    for (const opt of selItem.options) {
      const oc = parseInt(opt.getAttribute('data-cat-id')||"0",10);
      if (opt.value === "0") { opt.hidden = false; continue; }
      opt.hidden = (catId !== 0 && oc !== catId);
    }
    const cur = selItem.options[selItem.selectedIndex];
    if (cur && cur.hidden) selItem.value = "0";
  }
  selCat.addEventListener('change', filterProducts);
  filterProducts();

  function ensureNoEmpty(){
    const er = document.getElementById('emptyRow');
    if (er) er.remove();
  }

  function money(n){ return (Math.round(n*100)/100).toFixed(2); }

  function recalcTotals(){
    let rawSubtotal = 0;
    tbody.querySelectorAll('tr[data-id]').forEach(tr=>{
      rawSubtotal += parseFloat(tr.getAttribute('data-line-total')||"0");
    });

    // ✅ Sin recargo por tarjeta
    const subtotalWithFee = Math.round(rawSubtotal * 100)/100;

    let disc = parseFloat(coverageAmount.value||"0");
    if (isNaN(disc) || disc < 0) disc = 0;
    if (disc > subtotalWithFee) disc = subtotalWithFee;

    const total = Math.round((subtotalWithFee - disc) * 100)/100;

    subTotalEl.textContent = money(subtotalWithFee);
    discTotalEl.textContent = money(disc);
    grandTotalEl.textContent = money(total);
    grandTotalInput.value = money(total);
    totalBox.value = money(total);

    if (payMethod.value === "EFECTIVO") {
      const rec = parseFloat(cashReceived.value||"0");
      const change = Math.round((rec - total) * 100)/100;
      changeDue.value = money(change);
    } else {
      changeDue.value = money(0);
    }
  }

  payMethod.addEventListener('change', ()=>{
    cashBox.style.display = (payMethod.value === "EFECTIVO") ? "block" : "none";
    if (payMethod.value !== "EFECTIVO") cashReceived.value = "";
    recalcTotals();
  });
  cashReceived.addEventListener('input', recalcTotals);
  coverageAmount.addEventListener('input', recalcTotals);

  function addRow(id, name, q, price){
    ensureNoEmpty();

    const ex = tbody.querySelector('tr[data-id="'+id+'"]');
    if (ex) {
      const inp = ex.querySelector('input[name="qty[]"]');
      const cur = parseInt(inp.value||"0",10);
      const next = cur + q;
      inp.value = next;

      const unit = parseFloat(ex.getAttribute('data-price')||"0");
      const newLine = Math.round((unit * next) * 100)/100;
      ex.setAttribute('data-line-total', newLine);

      ex.querySelector('.jsQty').textContent = next;
      ex.querySelector('.jsTotal').textContent = money(newLine);

      recalcTotals();
      return;
    }

    const lineTotal = Math.round((price * q) * 100)/100;

    const tr = document.createElement('tr');
    tr.setAttribute('data-id', id);
    tr.setAttribute('data-price', price);
    tr.setAttribute('data-line-total', lineTotal);

    tr.innerHTML = `
      <td>
        ${name}
        <input type="hidden" name="item_id[]" value="${id}">
      </td>
      <td class="qtyRight">
        <span class="jsQty">${q}</span>
        <input type="hidden" name="qty[]" value="${q}">
      </td>
      <td class="qtyRight">RD$ ${money(price)}</td>
      <td class="qtyRight">RD$ <span class="jsTotal">${money(lineTotal)}</span></td>
      <td>
        <button class="btn secondary jsDel" type="button">Quitar</button>
      </td>
    `;

    tbody.appendChild(tr);

    tr.querySelector('.jsDel').addEventListener('click', ()=>{
      tr.remove();
      if (!tbody.querySelector('tr[data-id]')) {
        const er = document.createElement('tr');
        er.id = "emptyRow";
        er.innerHTML = `<td colspan="5" class="muted">No hay productos agregados.</td>`;
        tbody.appendChild(er);
      }
      recalcTotals();
    });

    recalcTotals();
  }

  btn.addEventListener('click', ()=>{
    const id = parseInt(selItem.value||"0",10);
    if (!id) return;

    const opt = selItem.options[selItem.selectedIndex];
    const name = opt.textContent.trim();
    const price = parseFloat(opt.getAttribute('data-price')||"0");
    const stock = parseInt(opt.getAttribute('data-stock')||"0",10);
    const q = parseInt(qty.value||"1",10);

    if (!q || q < 1) return;

    // Validacion rapida en UI (la validacion real tambien corre en el servidor)
    if (stock <= 0) {
      alert('Este producto esta sin stock en esta sucursal.');
      return;
    }
    if (q > stock) {
      alert('Cantidad solicitada supera la existencia. Disponible: ' + stock);
      return;
    }

    addRow(id, name, q, price);
  });

  // init
  recalcTotals();
})();
</script>

</body>
</html>
