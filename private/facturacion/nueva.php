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

/* ===== helpers ===== */
function columnExists(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$column]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return false;
  }
}

function number0($v): float {
  if ($v === null) return 0.0;
  if (is_string($v)) $v = str_replace([",", " "], ["", ""], $v);
  return (float)$v;
}

/* ===== input ===== */
$patient_id = (int)($_GET["patient_id"] ?? 0);
$patient_q = trim((string)($_GET["q"] ?? ""));

$err = "";
$ok = "";

/* ===== cargar paciente ===== */
$patient = null;
if ($patient_id > 0) {
  $st = $conn->prepare("SELECT id, full_name, document_no, phone, nationality FROM patients WHERE id=? LIMIT 1");
  $st->execute([$patient_id]);
  $patient = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$patient && $patient_q !== "") {
  $st = $conn->prepare("SELECT id, full_name, document_no, phone, nationality
                        FROM patients
                        WHERE full_name LIKE ?
                        ORDER BY id DESC LIMIT 1");
  $st->execute(["%{$patient_q}%"]);
  $patient = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($patient) $patient_id = (int)$patient["id"];
}

if (!$patient) {
  $err = "Paciente no encontrado.";
}

/* ===== categorías e items ===== */
$cats = [];
try {
  $cats = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$items_all = [];
try {
  $items_all = $conn->query("SELECT id, category_id, name, sale_price FROM inventory_items WHERE active=1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

/* ===== POST: guardar ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "save_invoice") {
  try {
    if ($patient_id <= 0) throw new Exception("Paciente inválido.");

    $invoice_date = trim((string)($_POST["invoice_date"] ?? $today));
    if ($invoice_date === "") $invoice_date = $today;

    $payment_method = trim((string)($_POST["payment_method"] ?? "EFECTIVO"));
    $coverage_amount = number0($_POST["coverage_amount"] ?? 0);

    $cash_received = number0($_POST["cash_received"] ?? 0);
    $representative = trim((string)($_POST["representative"] ?? ""));

    // líneas (items)
    $lines = $_POST["lines"] ?? [];
    if (!is_array($lines) || count($lines) === 0) throw new Exception("Debe agregar al menos un producto.");

    // Validar cantidades
    $cleanLines = [];
    foreach ($lines as $k => $v) {
      $iid = (int)$k;
      $qty = (int)$v;
      if ($iid > 0 && $qty > 0) $cleanLines[$iid] = $qty;
    }
    if (count($cleanLines) === 0) throw new Exception("Debe agregar al menos un producto con cantidad válida.");

    // Mapa de items
    $ids = array_keys($cleanLines);
    $in = implode(",", array_fill(0, count($ids), "?"));
    $stMap = $conn->prepare("SELECT id, category_id, name, sale_price FROM inventory_items WHERE id IN ($in)");
    $stMap->execute($ids);
    $mapRows = $stMap->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    foreach ($mapRows as $r) $map[(int)$r["id"]] = $r;

    // Calcular subtotal
    $subtotal = 0.0;
    foreach ($cleanLines as $iid => $q) {
      if (!isset($map[(int)$iid])) continue;
      $price = (float)$map[(int)$iid]["sale_price"];
      $subtotal += ($price * (int)$q);
    }

    $total = max(0.0, $subtotal - $coverage_amount);

    // efectivo y cambio
    $change_due = 0.0;
    if (mb_strtoupper($payment_method) === "EFECTIVO") {
      $change_due = $cash_received - $total;
      if ($change_due < 0) {
        // permitir si quieres, pero normalmente se valida
        // throw new Exception("Efectivo recibido insuficiente. Falta: " . number_format(abs($change_due), 2));
      }
    } else {
      $cash_received = null;
      $change_due = null;
    }

    $conn->beginTransaction();

    // Inserción flexible
    $motivo = 'FACTURA';
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

    // Motivo (requerido en algunas instalaciones)
    if (columnExists($conn, "invoices", "motivo")) {
      $cols[] = "motivo";
      $vals[] = $motivo;
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

    foreach ($cleanLines as $iid => $q) {
      if (!isset($map[(int)$iid])) continue;
      $price = (float)$map[(int)$iid]["sale_price"];
      $catId = (int)($map[(int)$iid]["category_id"] ?? 0);
      $line_total = $price * (int)$q;

      $stItem->execute([$invoice_id, (int)$iid, $catId, (int)$q, $price, $line_total]);

      // actualizar stock
      try { $stU->execute([(int)$q, (int)$iid, $branch_id]); } catch (Throwable $e) {}

      // registrar movimiento
      $note = "Factura #{$invoice_id}";
      try { $stMov->execute([(int)$iid, $branch_id, (int)$q, $note, $created_by]); } catch (Throwable $e) {}
    }

    $conn->commit();

    // imprimir
    header("Location: imprimir.php?id=" . $invoice_id);
    exit;

  } catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    $err = $e->getMessage();
  }
}

/* ===== UI ===== */
$patient_name = $patient ? ($patient["full_name"] ?? "") : "";
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Nueva factura - CEVIMEP</title>
  <link rel="stylesheet" href="/public/assets/css/paciente.css?v=<?php echo time(); ?>">
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
    .btnrow{display:flex;gap:10px;justify-content:flex-end;margin-top:16px}
    .btn{border:none;border-radius:12px;padding:10px 14px;font-weight:900;cursor:pointer}
    .btn.primary{background:#0b4aa2;color:#fff}
    .btn.light{background:#eef2ff;color:#0b4aa2}
    .tbl{width:100%;border-collapse:separate;border-spacing:0 8px}
    .tbl th{font-size:12px;color:#334155;text-align:left;padding:0 10px}
    .tbl td{background:#f8fafc;padding:10px;border:1px solid #e2e8f0}
    .tbl td:first-child{border-top-left-radius:12px;border-bottom-left-radius:12px}
    .tbl td:last-child{border-top-right-radius:12px;border-bottom-right-radius:12px}
    .totals{margin-top:10px;display:flex;justify-content:flex-end}
    .totbox{min-width:260px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:12px}
    .totline{display:flex;justify-content:space-between;margin:6px 0;font-weight:800}
    .totline.big{font-size:14px}
  </style>
</head>
<body>

<?php include __DIR__ . "/../partials/_topbar.php"; ?>
<div class="layout">
  <?php include __DIR__ . "/../partials/_sidebar.php"; ?>

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

        <form method="post" id="frmInvoice" autocomplete="off">
          <input type="hidden" name="action" value="save_invoice">

          <div class="section">
            <h3>Datos de la factura</h3>

            <div class="grid">
              <div>
                <label>Fecha</label>
                <input type="date" name="invoice_date" value="<?php echo h($_POST["invoice_date"] ?? $today); ?>">
              </div>
              <div>
                <label>Método de pago</label>
                <select name="payment_method" id="payment_method">
                  <?php
                  $pm = strtoupper((string)($_POST["payment_method"] ?? "EFECTIVO"));
                  $methods = ["EFECTIVO", "TARJETA", "TRANSFERENCIA"];
                  foreach ($methods as $mth) {
                    $sel = ($pm === $mth) ? "selected" : "";
                    echo "<option value=\"{$mth}\" {$sel}>{$mth}</option>";
                  }
                  ?>
                </select>
              </div>
            </div>

            <div class="grid">
              <div>
                <label>Efectivo recibido (solo efectivo)</label>
                <input type="number" step="0.01" name="cash_received" id="cash_received" value="<?php echo h($_POST["cash_received"] ?? "0.00"); ?>">
              </div>
              <div>
                <label>Cobertura (RD$)</label>
                <input type="number" step="0.01" name="coverage_amount" id="coverage_amount" value="<?php echo h($_POST["coverage_amount"] ?? "0.00"); ?>">
              </div>
            </div>

            <div>
              <label>Representante (opcional)</label>
              <input type="text" name="representative" value="<?php echo h($_POST["representative"] ?? ""); ?>" placeholder="Nombre del representante / tutor">
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

              <div>
                <label>Cantidad</label>
                <input type="number" id="qty" value="1" min="1">
              </div>

              <div style="display:flex;align-items:flex-end;">
                <button type="button" class="btn primary" id="btnAdd" style="width:100%;">Añadir</button>
              </div>
            </div>

            <table class="tbl" style="margin-top:10px;">
              <thead>
                <tr>
                  <th>Producto</th>
                  <th style="width:110px;">Cantidad</th>
                  <th style="width:140px;">Precio</th>
                  <th style="width:140px;">Total</th>
                  <th style="width:60px;"></th>
                </tr>
              </thead>
              <tbody id="linesBody"></tbody>
            </table>

            <div class="totals">
              <div class="totbox">
                <div class="totline"><span>Subtotal</span><span id="tSubtotal">RD$ 0.00</span></div>
                <div class="totline"><span>Cobertura</span><span id="tCoverage">RD$ 0.00</span></div>
                <div class="totline big"><span>Total a pagar</span><span id="tTotal">RD$ 0.00</span></div>
                <div class="totline"><span>Cambio</span><span id="tChange">RD$ 0.00</span></div>
              </div>
            </div>

            <div class="btnrow">
              <a href="index.php" class="btn light">Cancelar</a>
              <button type="submit" class="btn primary">Guardar y imprimir</button>
            </div>
          </div>

          <!-- inputs hidden de líneas -->
          <div id="hiddenLines"></div>
        </form>
      </div>
    </div>
  </main>
</div>

<script>
(function(){
  const fmt = (n)=> 'RD$ ' + (Number(n||0).toFixed(2));
  const cat = document.getElementById('cat_filter');
  const sel = document.getElementById('item_select');
  const qty = document.getElementById('qty');
  const btn = document.getElementById('btnAdd');
  const body = document.getElementById('linesBody');
  const hidden = document.getElementById('hiddenLines');

  const cashInput = document.getElementById('cash_received');
  const covInput = document.getElementById('coverage_amount');
  const pmSelect = document.getElementById('payment_method');

  const tSubtotal = document.getElementById('tSubtotal');
  const tCoverage = document.getElementById('tCoverage');
  const tTotal = document.getElementById('tTotal');
  const tChange = document.getElementById('tChange');

  let lines = {}; // {item_id: {name, cat, price, qty}}

  function filterItems(){
    const c = Number(cat.value||0);
    [...sel.options].forEach((op, i)=>{
      if(i===0) return;
      const oc = Number(op.dataset.cat||0);
      op.hidden = (c!==0 && oc!==c);
    });
    if (sel.selectedOptions[0] && sel.selectedOptions[0].hidden) sel.value = "0";
  }

  function recalc(){
    let subtotal = 0;
    Object.values(lines).forEach(l => subtotal += (l.price * l.qty));
    const coverage = Number(covInput.value||0);
    const total = Math.max(0, subtotal - coverage);

    tSubtotal.textContent = fmt(subtotal);
    tCoverage.textContent = fmt(coverage);
    tTotal.textContent = fmt(total);

    let change = 0;
    if ((pmSelect.value||'').toUpperCase() === 'EFECTIVO') {
      change = Number(cashInput.value||0) - total;
    } else {
      change = 0;
    }
    tChange.textContent = fmt(change);

    // hidden inputs para POST
    hidden.innerHTML = '';
    Object.values(lines).forEach(l=>{
      const inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = 'lines['+l.id+']';
      inp.value = l.qty;
      hidden.appendChild(inp);
    });
  }

  function render(){
    body.innerHTML = '';
    Object.values(lines).forEach(l=>{
      const tr = document.createElement('tr');

      const td1 = document.createElement('td');
      td1.textContent = l.name;
      const td2 = document.createElement('td');
      td2.textContent = l.qty;
      const td3 = document.createElement('td');
      td3.textContent = fmt(l.price);
      const td4 = document.createElement('td');
      td4.textContent = fmt(l.price * l.qty);

      const td5 = document.createElement('td');
      const x = document.createElement('button');
      x.type='button'; x.textContent='✕';
      x.className='btn light';
      x.style.padding='6px 10px';
      x.onclick=()=>{ delete lines[l.id]; render(); recalc(); };
      td5.appendChild(x);

      tr.appendChild(td1);
      tr.appendChild(td2);
      tr.appendChild(td3);
      tr.appendChild(td4);
      tr.appendChild(td5);
      body.appendChild(tr);
    });
  }

  btn.addEventListener('click', ()=>{
    const id = Number(sel.value||0);
    if(!id) return;
    const op = sel.selectedOptions[0];
    const name = op.textContent.trim();
    const price = Number(op.dataset.price||0);
    const q = Math.max(1, Number(qty.value||1));

    if(lines[id]) lines[id].qty += q;
    else lines[id] = {id, name, price, qty:q};

    render(); recalc();
  });

  cat.addEventListener('change', filterItems);
  covInput.addEventListener('input', recalc);
  cashInput.addEventListener('input', recalc);
  pmSelect.addEventListener('change', ()=>{
    const isCash = (pmSelect.value||'').toUpperCase() === 'EFECTIVO';
    cashInput.disabled = !isCash;
    if(!isCash) cashInput.value = '0.00';
    recalc();
  });

  filterItems();
  recalc();
})();
</script>

</body>
</html>
