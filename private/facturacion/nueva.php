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

/* Productos con precio venta */
$products = [];
$st = $conn->query("
  SELECT i.id, i.name, i.sale_price, COALESCE(c.id,0) AS category_id
  FROM inventory_items i
  LEFT JOIN inventory_categories c ON c.id=i.category_id
  WHERE i.is_active=1
  ORDER BY i.name ASC
");
$products = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

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
    $discount_amount = isset($_POST["discount_amount"]) && $_POST["discount_amount"] !== "" ? (float)$_POST["discount_amount"] : 0.0;
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

        // ✅ Descuento (monto) se resta del subtotal
        if ($discount_amount < 0) $discount_amount = 0;
        if ($discount_amount > $subtotal_with_fee) $discount_amount = $subtotal_with_fee;

        $subtotal = round($subtotal_with_fee, 2);
        $total    = round($subtotal_with_fee - $discount_amount, 2); // 👈 TOTAL final

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

        if (columnExists($conn, "invoices", "discount_amount")) {
          $cols[] = "discount_amount";
          $vals[] = $discount_amount;
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
          if ($discount_amount > 0) $note .= " | DESCUENTO=" . number_format($discount_amount, 2);
          $stMov->execute([(int)$iid, $branch_id, (int)$q, $note, $created_by]);
        }
// ================================
// ✅ CONECTAR FACTURACIÓN → CAJA
// ================================
caja_registrar_ingreso_factura(
  $conn,
  (int)$branch_id,
  (int)$created_by,
  (int)$invoice_id,
  (float)$total,          // TOTAL FINAL
  (string)$payment_method // EFECTIVO | TARJETA | TRANSFERENCIA
);

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
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <style>
    .formGrid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:10px}
    .rowAdd{display:grid;grid-template-columns:240px 1fr 170px auto;gap:12px;align-items:end;margin-top:12px}
    .field label{display:block;font-weight:900;color:var(--primary-2);font-size:13px;margin:0 0 6px}
    .input{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:14px;background:#fff;font-weight:700;outline:none;}
    .input:focus{border-color:rgba(20,184,166,.45);box-shadow:0 0 0 3px rgba(20,184,166,.12)}
    .qtyRight{text-align:right}
  </style>
</head>
<body>

<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right"><a href="../../public/logout.php">Salir</a></div>
  </div>
</header>

<main class="app">
  <aside class="sidebar">
    <div class="title">Menú</div>
    <nav class="menu">
      <a href="../dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="../patients/index.php"><span class="ico">🧑‍🤝‍🧑</span> Pacientes</a>
      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;"><span class="ico">🗓️</span> Citas</a>
      <a class="active" href="index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;"><span class="ico">💳</span> Caja</a>
      <a href="../inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="../estadistica/reporte_diario.php"><span class="ico">📊</span> Estadística</a>
    </nav>
  </aside>

  <section class="main">
    <?php if ($flash_error): ?>
      <div class="card" style="border-color:rgba(239,68,68,.35); background:rgba(239,68,68,.08);">
        <p style="margin:0;font-weight:900;color:#b91c1c;"><?= htmlspecialchars($flash_error) ?></p>
      </div>
      <div style="height:12px;"></div>
    <?php endif; ?>

    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
        <div>
          <h2 style="margin:0 0 6px;">Nueva factura</h2>
          <p class="muted" style="margin:0;">Paciente: <strong><?= htmlspecialchars($patient["full_name"]) ?></strong></p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <a class="btn" href="paciente.php?patient_id=<?= (int)$patient_id ?>" style="text-decoration:none;">Volver</a>
        </div>
      </div>

      <form method="POST">
        <input type="hidden" name="patient_id" value="<?= (int)$patient_id ?>">

        <div class="formGrid">
          <div class="field">
            <label>Área desde donde sale la venta (sucursal)</label>
            <input class="input" type="text" value="<?= htmlspecialchars($branch_name) ?>" readonly>
          </div>

          <div class="field">
            <label>Fecha</label>
            <input class="input" type="date" name="invoice_date" value="<?= htmlspecialchars($today) ?>">
          </div>

          <div class="field">
            <label>Nombre del paciente</label>
            <input class="input" type="text" value="<?= htmlspecialchars($patient["full_name"]) ?>" readonly>
          </div>

          <div class="field">
            <label>Representante</label>
            <input class="input" type="text" name="representative" placeholder="Nombre de quien realizó la factura" value="<?= htmlspecialchars($user["name"] ?? "") ?>">
          </div>

          <div class="field">
            <label>Método de pago</label>
            <select class="input" name="payment_method" id="payMethod">
              <option value="EFECTIVO">Efectivo</option>
              <option value="TARJETA">Tarjeta</option>
              <option value="TRANSFERENCIA">Transferencia</option>
            </select>
          </div>

          <div class="field" id="cashBox">
            <label>Efectivo recibido</label>
            <input class="input" type="number" step="0.01" name="cash_received" id="cashReceived" placeholder="Ej: 1000">
          </div>

          <div class="field">
            <label>Devuelta</label>
            <input class="input" type="text" id="changeDue" value="0.00" readonly>
          </div>

          <div class="field">
            <label>Descuento</label>
            <input class="input" type="number" step="0.01" min="0" name="discount_amount" id="discountAmount" value="0">
          </div>

          <div class="field">
            <label>Total</label>
            <input class="input" type="text" id="grandTotalInput" value="0.00" readonly>
          </div>
        </div>

        <hr style="margin:14px 0;opacity:.25;">

        <div class="rowAdd">
          <div class="field">
            <label>Categoría</label>
            <select class="input" id="selCat">
              <option value="0">-- Todas --</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c["id"] ?>"><?= htmlspecialchars($c["name"]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>Producto</label>
            <select class="input" id="selItem">
              <option value="0" data-cat-id="0" data-price="0">-- Seleccionar --</option>
              <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p["id"] ?>" data-cat-id="<?= (int)$p["category_id"] ?>" data-price="<?= htmlspecialchars($p["sale_price"]) ?>">
                  <?= htmlspecialchars($p["name"]) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>Cantidad</label>
            <input class="input" type="number" id="qty" min="1" value="1">
          </div>

          <button type="button" class="btn" id="btnAdd">Añadir</button>
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
            <div class="muted">Descuento</div>
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
    </div>
  </section>
</main>

<footer class="footer">
  <div class="inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
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

  const discountAmount = document.getElementById('discountAmount');

  const subTotalEl = document.getElementById('subTotal');
  const discTotalEl = document.getElementById('discTotal');
  const grandTotalEl = document.getElementById('grandTotal');
  const grandTotalInput = document.getElementById('grandTotalInput');

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

    let disc = parseFloat(discountAmount.value||"0");
    if (isNaN(disc) || disc < 0) disc = 0;
    if (disc > subtotalWithFee) disc = subtotalWithFee;

    const total = Math.round((subtotalWithFee - disc) * 100)/100;

    subTotalEl.textContent = money(subtotalWithFee);
    discTotalEl.textContent = money(disc);
    grandTotalEl.textContent = money(total);
    grandTotalInput.value = money(total);

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
  discountAmount.addEventListener('input', recalcTotals);

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

    const tr = document.createElement('tr');
    tr.setAttribute('data-id', id);
    tr.setAttribute('data-price', price);
    tr.setAttribute('data-line-total', Math.round(price*q*100)/100);

    const tdN = document.createElement('td');
    tdN.textContent = name;

    const hidId = document.createElement('input');
    hidId.type="hidden"; hidId.name="item_id[]"; hidId.value=id;

    const hidQty = document.createElement('input');
    hidQty.type="hidden"; hidQty.name="qty[]"; hidQty.value=q;

    tdN.appendChild(hidId);
    tdN.appendChild(hidQty);

    const tdQ = document.createElement('td');
    tdQ.className = "qtyRight jsQty";
    tdQ.style.fontWeight="900";
    tdQ.textContent = q;

    const tdP = document.createElement('td');
    tdP.className = "qtyRight jsPrice";
    tdP.style.fontWeight="900";
    tdP.textContent = money(price);

    const tdT = document.createElement('td');
    tdT.className = "qtyRight jsTotal";
    tdT.style.fontWeight="900";
    tdT.textContent = money(price*q);

    const tdA = document.createElement('td');
    const del = document.createElement('button');
    del.type="button";
    del.className="btn";
    del.style.padding="8px 10px";
    del.textContent="Eliminar";
    del.onclick = () => {
      tr.remove();
      if (!tbody.querySelector('tr[data-id]')) {
        const er = document.createElement('tr');
        er.id="emptyRow";
        er.innerHTML = '<td colspan="5" class="muted">No hay productos agregados.</td>';
        tbody.appendChild(er);
      }
      recalcTotals();
    };
    tdA.appendChild(del);

    tr.appendChild(tdN);
    tr.appendChild(tdQ);
    tr.appendChild(tdP);
    tr.appendChild(tdT);
    tr.appendChild(tdA);

    tbody.appendChild(tr);
    recalcTotals();
  }

  btn.addEventListener('click', ()=>{
    const id = parseInt(selItem.value||"0",10);
    const q  = parseInt(qty.value||"0",10);
    const opt = selItem.options[selItem.selectedIndex];
    const name = opt ? opt.text : "";
    const price = parseFloat(opt ? (opt.getAttribute('data-price')||"0") : "0");

    if (!id) { alert("Selecciona un producto"); return; }
    if (!q || q <= 0) { alert("Cantidad inválida"); return; }

    addRow(id, name, q, price);
  });

  cashBox.style.display = "block";
  recalcTotals();
})();
</script>

</body>
</html>
