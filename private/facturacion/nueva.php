<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
require_once __DIR__ . "/../caja/caja_lib.php";
$conn = $pdo;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

/* ===== contexto ===== */
$user = $_SESSION["user"] ?? [];
$year = date("Y");
$today = date("Y-m-d");
$now_dt = date("Y-m-d H:i:s");
$branch_id = (int)($user["branch_id"] ?? 0);
$created_by = (int)($user["id"] ?? 0);

$patient_id = (int)($_GET["patient_id"] ?? $_POST["patient_id"] ?? 0);
if ($patient_id <= 0) { header("Location: /private/facturacion/index.php"); exit; }

$flash_error = "";
$flash_ok = "";

/* ===== sucursal ===== */
$branch_name = "";
if ($branch_id > 0) {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $branch_name = (string)($stB->fetchColumn() ?? "");
}
if ($branch_name === "") $branch_name = ($branch_id > 0) ? ("Sede #".$branch_id) : "—";

/* ===== paciente (solo sucursal) ===== */
$patient = null;
try {
  $stP = $conn->prepare("
    SELECT id, branch_id, TRIM(CONCAT(first_name,' ',last_name)) AS full_name
    FROM patients
    WHERE id=? AND branch_id=?
    LIMIT 1
  ");
  $stP->execute([$patient_id, $branch_id]);
  $patient = $stP->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if (!$patient) {
  http_response_code(404);
  die("Paciente no encontrado en esta sucursal.");
}

/* ===== categorías ===== */
$categories = [];
$stC = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
$categories = $stC ? $stC->fetchAll(PDO::FETCH_ASSOC) : [];

/* ===== productos SOLO DE ESTA SUCURSAL ===== */
$products = [];
if ($branch_id > 0) {
  $st = $conn->prepare("
    SELECT i.id, i.name, i.sale_price, COALESCE(c.id,0) AS category_id, COALESCE(s.quantity,0) AS stock_qty
    FROM inventory_stock s
    INNER JOIN inventory_items i ON i.id = s.item_id
    LEFT JOIN inventory_categories c ON c.id = i.category_id
    WHERE s.branch_id = ? AND i.is_active = 1
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

/* ===== POST: guardar factura (tu lógica original, intacta) ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  if ($branch_id <= 0) {
    $flash_error = "Este usuario no tiene sede asignada. No puede facturar.";
  } else {

    $invoice_date = $_POST["invoice_date"] ?? $today;
    $payment_method = strtoupper(trim($_POST["payment_method"] ?? "EFECTIVO"));
    if (!in_array($payment_method, ["EFECTIVO","TARJETA","TRANSFERENCIA"], true)) { $payment_method = "EFECTIVO"; }
    $cash_received = isset($_POST["cash_received"]) && $_POST["cash_received"] !== "" ? (float)$_POST["cash_received"] : null;
    if ($payment_method !== "EFECTIVO") { $cash_received = null; }
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
        $total    = round($subtotal_with_fee - $coverage_amount, 2); // TOTAL a pagar (después de cobertura)

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

        // Inserción flexible
        $cols = [
          "branch_id", "patient_id", "invoice_date", "payment_method",
          "subtotal", "total", "cash_received", "change_due", "created_by"
        ];
        $vals = [
          $branch_id, $patient_id, $invoice_date, $payment_method,
          $subtotal, $total, $cash_received, $change_due, $created_by
        ];

        // Guardamos cobertura en discount_amount si existe
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

        if ($representative !== "" && columnExists($conn, "invoices", "representative_name")) {
          $cols[] = "representative_name";
          $vals[] = $representative;
        }
        if ($representative !== "" && columnExists($conn, "invoices", "representative")) {
          $cols[] = "representative";
          $vals[] = $representative;
        }

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

        // ✅ Facturación → Caja
        if ((float)$total > 0) {
          caja_registrar_ingreso_factura(
            $conn, (int)$branch_id, (int)$created_by, (int)$invoice_id, (float)$total, (string)$payment_method
          );
        }

        if ((float)$coverage_amount > 0) {
          caja_registrar_ingreso_factura(
            $conn, (int)$branch_id, (int)$created_by, (int)$invoice_id, (float)$coverage_amount, "COBERTURA"
          );
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

  <!-- ✅ MISMO CSS QUE DASHBOARD -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=30">

  <style>
    .card-box{
      background:#fff;
      border-radius:16px;
      box-shadow:0 12px 30px rgba(0,0,0,.08);
      padding:16px;
      margin-top:14px;
    }
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    .grid4{display:grid;grid-template-columns:1fr 1fr 1fr 160px;gap:12px;align-items:end;}
    @media(max-width:980px){.grid2{grid-template-columns:1fr;}.grid4{grid-template-columns:1fr;}}
    .field{display:flex;flex-direction:column;gap:6px;}
    .field label{font-weight:900;color:#0b2a4a;font-size:13px;}
    .input, select{
      height:40px;
      padding:0 12px;
      border-radius:14px;
      border:1px solid rgba(2,21,44,.12);
      outline:none;
      font-weight:800;
      background:#fff;
    }
    .input:focus, select:focus{border-color:#7fb2ff;box-shadow:0 0 0 3px rgba(127,178,255,.20);}

    .btn-ui{
      height:38px;border:none;border-radius:12px;padding:0 14px;
      font-weight:900;cursor:pointer;text-decoration:none;
      display:inline-flex;align-items:center;justify-content:center;gap:8px;
    }
    .btn-primary-ui{background:#0b4d87;color:#fff;}
    .btn-secondary-ui{background:#eef2f6;color:#2b3b4a;}

    table{width:100%;border-collapse:separate;border-spacing:0;margin-top:10px;}
    th,td{padding:12px 10px;border-bottom:1px solid #eef2f6;font-size:13px;}
    th{color:#0b4d87;text-align:left;font-weight:900;font-size:12px;}
    tr:last-child td{border-bottom:none;}
    .right{text-align:right;}
    .muted{opacity:.75}
    .flash-ok{background:#e9fff1;border:1px solid #a7f0bf;color:#0a7a33;border-radius:12px;padding:10px 12px;font-size:13px;margin-top:12px;}
    .flash-err{background:#ffecec;border:1px solid #ffb6b6;color:#a40000;border-radius:12px;padding:10px 12px;font-size:13px;margin-top:12px;}
    .toolbar{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-top:14px;}
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
      <a class="active" href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💳</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadisticas/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">

    <section class="hero">
      <h1>Nueva factura</h1>
      <p>Paciente: <strong><?= h($patient["full_name"]) ?></strong> — Sucursal: <strong><?= h($branch_name) ?></strong></p>
    </section>

    <?php if ($flash_ok): ?><div class="flash-ok"><?= h($flash_ok) ?></div><?php endif; ?>
    <?php if ($flash_error): ?><div class="flash-err"><?= h($flash_error) ?></div><?php endif; ?>

    <div class="toolbar">
      <div>
        <h3 style="margin:0 0 6px;"><?= h($patient["full_name"]) ?></h3>
        <p class="muted" style="margin:0;">Agrega productos y guarda la factura.</p>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a class="btn-ui btn-secondary-ui" href="/private/facturacion/paciente.php?patient_id=<?= (int)$patient_id ?>">← Volver</a>
      </div>
    </div>

    <form method="post" class="card-box" id="frmFactura">
      <input type="hidden" name="patient_id" value="<?= (int)$patient_id ?>">

      <div class="grid2">
        <div class="field">
          <label>Fecha</label>
          <input class="input" type="date" name="invoice_date" value="<?= h($today) ?>">
        </div>

        <div class="field">
          <label>Método de pago</label>
          <select name="payment_method" id="payment_method">
            <option value="EFECTIVO">EFECTIVO</option>
            <option value="TARJETA">TARJETA</option>
            <option value="TRANSFERENCIA">TRANSFERENCIA</option>
          </select>
        </div>

        <div class="field">
          <label>Efectivo recibido (solo efectivo)</label>
          <input class="input" type="number" step="0.01" min="0" name="cash_received" id="cash_received" placeholder="0.00">
        </div>

        <div class="field">
          <label>Cobertura (RD$)</label>
          <input class="input" type="number" step="0.01" min="0" name="coverage_amount" id="coverage_amount" value="0">
        </div>

        <div class="field" style="grid-column:1 / -1;">
          <label>Representante (opcional)</label>
          <input class="input" name="representative" placeholder="Nombre del representante / tutor">
        </div>
      </div>

      <div class="card-box" style="box-shadow:none;border:1px solid rgba(2,21,44,.10);">
        <h3 style="margin:0 0 10px;">Agregar productos</h3>

        <div class="grid4">
          <div class="field">
            <label>Categoría (filtro)</label>
            <select id="selCat">
              <option value="0">Todas</option>
              <?php foreach($categories as $c): ?>
                <option value="<?= (int)$c["id"] ?>"><?= h($c["name"]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>Producto</label>
            <select id="selItem">
              <option value="0">-- Seleccionar --</option>
              <?php foreach($products as $p): $stk=(int)($p["stock_qty"] ?? 0); ?>
                <option value="<?= (int)$p["id"] ?>"
                        data-price="<?= h($p["sale_price"]) ?>"
                        data-cat-id="<?= (int)$p["category_id"] ?>"
                        data-stock="<?= $stk ?>">
                  <?= h($p["name"]) ?><?= ($stk <= 0 ? " (Sin stock)" : "") ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label>Cantidad</label>
            <input class="input" id="qty" type="number" min="1" value="1">
          </div>

          <button type="button" class="btn-ui btn-primary-ui" id="btnAdd">Añadir</button>
        </div>

        <table id="tblLines">
          <thead>
            <tr>
              <th>Producto</th>
              <th class="right" style="width:120px;">Cantidad</th>
              <th class="right" style="width:160px;">Precio</th>
              <th class="right" style="width:160px;">Total</th>
              <th style="width:90px;"></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>

        <div class="grid2" style="margin-top:14px;">
          <div></div>
          <div>
            <div style="display:flex;justify-content:space-between;margin:6px 0;">
              <span class="muted">Subtotal</span>
              <strong id="lblSubtotal">RD$ 0.00</strong>
            </div>
            <div style="display:flex;justify-content:space-between;margin:6px 0;">
              <span class="muted">Cobertura</span>
              <strong id="lblCoverage">RD$ 0.00</strong>
            </div>
            <div style="display:flex;justify-content:space-between;margin:10px 0;font-size:16px;">
              <span>Total a pagar</span>
              <strong id="lblTotal">RD$ 0.00</strong>
            </div>
            <div id="cashBox" style="display:flex;justify-content:space-between;margin:6px 0;">
              <span class="muted">Cambio</span>
              <strong id="lblChange">RD$ 0.00</strong>
            </div>
          </div>
        </div>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:14px;">
        <a class="btn-ui btn-secondary-ui" href="/private/facturacion/paciente.php?patient_id=<?= (int)$patient_id ?>">Cancelar</a>
        <button class="btn-ui btn-primary-ui" type="submit">Guardar y imprimir</button>
      </div>
    </form>

  </main>
</div>

<div class="footer">
  <div class="inner">© <?= h($year) ?> CEVIMEP. Todos los derechos reservados.</div>
</div>

<script>
(function(){
  const selCat = document.getElementById('selCat');
  const selItem = document.getElementById('selItem');
  const qty = document.getElementById('qty');
  const btnAdd = document.getElementById('btnAdd');
  const tbody = document.querySelector('#tblLines tbody');

  const payment = document.getElementById('payment_method');
  const cashReceived = document.getElementById('cash_received');
  const coverage = document.getElementById('coverage_amount');

  const lblSubtotal = document.getElementById('lblSubtotal');
  const lblCoverage = document.getElementById('lblCoverage');
  const lblTotal = document.getElementById('lblTotal');
  const lblChange = document.getElementById('lblChange');
  const cashBox = document.getElementById('cashBox');

  function money(n){ return "RD$ " + (Number(n||0).toFixed(2)); }

  // filtro por categoría
  selCat.addEventListener('change', () => {
    const cat = Number(selCat.value || 0);
    [...selItem.options].forEach(opt => {
      if (!opt.value || opt.value === "0") return;
      const oc = Number(opt.dataset.catId || 0);
      opt.hidden = (cat !== 0 && oc !== cat);
    });
    selItem.value = "0";
  });

  function recalc(){
    let subtotal = 0;
    tbody.querySelectorAll('tr').forEach(tr => {
      const lineTotal = Number(tr.dataset.total || 0);
      subtotal += lineTotal;
    });
    const cov = Math.max(0, Number(coverage.value || 0));
    const covApplied = Math.min(cov, subtotal);
    const total = Math.max(0, subtotal - covApplied);

    lblSubtotal.textContent = money(subtotal);
    lblCoverage.textContent = money(covApplied);
    lblTotal.textContent = money(total);

    const isCash = (payment.value === "EFECTIVO");
    // Mostrar/ocultar y desactivar "Efectivo recibido" si NO es efectivo
    const cashField = cashReceived && cashReceived.closest ? cashReceived.closest('.field') : null;
    if (cashField) cashField.style.display = isCash ? '' : 'none';
    if (cashReceived) {
      cashReceived.disabled = !isCash;
      cashReceived.readOnly = !isCash;
      if (!isCash) cashReceived.value = "0.00";
    }

    // Mostrar cambio solo si es efectivo
    cashBox.style.display = isCash ? 'flex' : 'none';

    if (isCash) {
      const recv = Math.max(0, Number(cashReceived.value || 0));
      lblChange.textContent = money(recv - total);
    }
  }

  function addLine(){
    const opt = selItem.selectedOptions[0];
    const id = Number(selItem.value || 0);
    const q = Math.max(1, Number(qty.value || 1));
    if (!id || !opt) return alert("Selecciona un producto.");

    const price = Number(opt.dataset.price || 0);
    const stock = Number(opt.dataset.stock || 0);
    const name = opt.textContent.trim();

    if (stock <= 0) return alert("Este producto está sin stock.");
    if (q > stock) return alert("Cantidad supera el stock disponible ("+stock+").");

    // si ya existe, suma qty (respetando stock)
    const existing = tbody.querySelector('tr[data-id="'+id+'"]');
    if (existing){
      const oldQ = Number(existing.dataset.qty || 0);
      const newQ = oldQ + q;
      if (newQ > stock) return alert("No puedes exceder el stock ("+stock+").");
      existing.dataset.qty = String(newQ);
      existing.dataset.total = String(newQ * price);
      existing.querySelector('.tdQty').textContent = newQ;
      existing.querySelector('.tdTotal').textContent = money(newQ * price);
      existing.querySelector('input[name="qty[]"]').value = newQ;
      recalc();
      return;
    }

    const tr = document.createElement('tr');
    tr.dataset.id = String(id);
    tr.dataset.qty = String(q);
    tr.dataset.total = String(q * price);

    tr.innerHTML = `
      <td>
        <strong>${name}</strong>
        <input type="hidden" name="item_id[]" value="${id}">
      </td>
      <td class="right tdQty">${q}<input type="hidden" name="qty[]" value="${q}"></td>
      <td class="right">${money(price)}</td>
      <td class="right tdTotal">${money(q * price)}</td>
      <td class="right"><button type="button" class="btn-ui btn-secondary-ui btnDel">Quitar</button></td>
    `;
    tr.querySelector('.btnDel').addEventListener('click', () => {
      tr.remove();
      recalc();
    });

    tbody.appendChild(tr);
    recalc();
  }

  btnAdd.addEventListener('click', addLine);
  payment.addEventListener('change', recalc);
  cashReceived.addEventListener('input', recalc);
  coverage.addEventListener('input', recalc);

  // default
  recalc();
})();
</script>

</body>
</html>
