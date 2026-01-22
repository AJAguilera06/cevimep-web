<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function money($n): string { return "RD$ " . number_format((float)$n, 2); }

$user = $_SESSION["user"] ?? [];
$branch_id = (int)($user["branch_id"] ?? 0);

$id = (int)($_GET["id"] ?? 0);
$rep = trim((string)($_GET["rep"] ?? ""));

if ($branch_id <= 0) { http_response_code(400); die("Sucursal inválida."); }
if ($id <= 0) { http_response_code(400); die("Factura inválida."); }

function columnExists(PDO $conn, string $table, string $column): bool {
  try {
    $db = $conn->query("SELECT DATABASE()")->fetchColumn();
    $st = $conn->prepare("
      SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?
    ");
    $st->execute([$db, $table, $column]);
    return ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) {
    return false;
  }
}

/* ===== sucursal ===== */
$branch_name = "";
try {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $branch_name = (string)($stB->fetchColumn() ?: "");
} catch (Throwable $e) {}
if ($branch_name === "") $branch_name = "Sede #".$branch_id;

/* ===== factura ===== */
$has_discount = columnExists($conn, "invoices", "discount_amount");
$selDiscount = $has_discount ? ", i.discount_amount" : ", 0 AS discount_amount";

$stInv = $conn->prepare("
  SELECT
    i.id, i.branch_id, i.patient_id, i.invoice_date, i.payment_method,
    i.subtotal, i.total, i.cash_received, i.change_due
    $selDiscount
  FROM invoices i
  WHERE i.id = ? AND i.branch_id = ?
  LIMIT 1
");
$stInv->execute([$id, $branch_id]);
$inv = $stInv->fetch(PDO::FETCH_ASSOC);

if (!$inv) { http_response_code(404); die("Factura no encontrada en esta sucursal."); }

/* ===== paciente ===== */
$patient_name = "Paciente";
try {
  $stP = $conn->prepare("
    SELECT TRIM(CONCAT(first_name,' ',last_name)) AS full_name
    FROM patients
    WHERE id=? AND branch_id=?
    LIMIT 1
  ");
  $stP->execute([(int)$inv["patient_id"], $branch_id]);
  $patient_name = (string)($stP->fetchColumn() ?: "Paciente");
} catch (Throwable $e) {}

/* ===== items ===== */
$items = [];
$stItems = $conn->prepare("
  SELECT ii.qty, ii.unit_price, ii.line_total, it.name
  FROM invoice_items ii
  INNER JOIN inventory_items it ON it.id = ii.item_id
  WHERE ii.invoice_id = ?
  ORDER BY ii.id ASC
");
$stItems->execute([$id]);
$items = $stItems->fetchAll(PDO::FETCH_ASSOC);

/* ===== totales ===== */
$subtotal = (float)($inv["subtotal"] ?? 0);
$total    = (float)($inv["total"] ?? 0);
$coverage = (float)($inv["discount_amount"] ?? 0); // lo usamos como cobertura
$method   = (string)($inv["payment_method"] ?? "");
$cash_received = $inv["cash_received"] !== null ? (float)$inv["cash_received"] : null;
$change_due    = $inv["change_due"] !== null ? (float)$inv["change_due"] : null;

$invoice_date = (string)($inv["invoice_date"] ?? "");
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Factura #<?= (int)$inv["id"] ?> | CEVIMEP</title>

  <style>
    :root{ --ink:#0b2a4a; --muted:rgba(2,21,44,.70); --line:rgba(2,21,44,.14); }
    body{ font-family: Arial, Helvetica, sans-serif; color:var(--ink); background:#fff; margin:0; }
    .page{ max-width: 820px; margin: 0 auto; padding: 18px; }
    .top{ display:flex; justify-content:space-between; gap:16px; align-items:flex-start; }
    .brand{ font-weight:900; font-size:18px; letter-spacing:.2px; }
    .small{ color:var(--muted); font-size:12px; }
    .meta{ text-align:right; }
    .hr{ height:1px; background:var(--line); margin:14px 0; }

    table{ width:100%; border-collapse:collapse; }
    th,td{ padding:10px 6px; border-bottom:1px solid var(--line); font-size:13px; }
    th{ text-align:left; font-size:12px; color:var(--muted); }
    td.r, th.r{ text-align:right; }
    .totals{ margin-top:10px; width:100%; }
    .row{ display:flex; justify-content:space-between; padding:6px 0; font-size:13px; }
    .row strong{ font-size:14px; }
    .grand{ font-size:16px; font-weight:900; }
    .note{ margin-top:12px; font-size:12px; color:var(--muted); }

    .btns{ display:flex; gap:10px; justify-content:flex-end; margin-top:14px; }
    .btn{
      border:1px solid var(--line);
      background:#fff;
      padding:8px 12px;
      border-radius:10px;
      font-weight:900;
      cursor:pointer;
    }

    @media print{
      .btns{ display:none !important; }
      .page{ padding:0; }
      body{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
  </style>
</head>

<body>
  <div class="page">
    <div class="top">
      <div>
        <div class="brand">CEVIMEP</div>
        <div class="small"><?= h($branch_name) ?></div>
        <div class="small">Paciente: <strong><?= h($patient_name) ?></strong></div>
        <?php if ($rep !== ""): ?>
          <div class="small">Representante: <strong><?= h($rep) ?></strong></div>
        <?php endif; ?>
      </div>

      <div class="meta">
        <div class="brand">Factura #<?= (int)$inv["id"] ?></div>
        <div class="small">Fecha: <strong><?= h($invoice_date) ?></strong></div>
        <div class="small">Método: <strong><?= h($method) ?></strong></div>
      </div>
    </div>

    <div class="hr"></div>

    <table>
      <thead>
        <tr>
          <th>Producto</th>
          <th class="r" style="width:90px;">Cant.</th>
          <th class="r" style="width:140px;">Precio</th>
          <th class="r" style="width:160px;">Importe</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$items): ?>
          <tr><td colspan="4" class="small">Sin items.</td></tr>
        <?php else: ?>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?= h($it["name"] ?? "") ?></td>
              <td class="r"><?= (int)($it["qty"] ?? 0) ?></td>
              <td class="r"><?= money($it["unit_price"] ?? 0) ?></td>
              <td class="r"><?= money($it["line_total"] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="totals">
      <div class="row">
        <span>Subtotal</span>
        <strong><?= money($subtotal) ?></strong>
      </div>

      <?php if ($coverage > 0): ?>
        <div class="row">
          <span>Cobertura</span>
          <strong>- <?= money($coverage) ?></strong>
        </div>
      <?php endif; ?>

      <div class="row grand">
        <span>Total a pagar</span>
        <span><?= money($total) ?></span>
      </div>

      <?php if ($method === "EFECTIVO"): ?>
        <div class="row">
          <span>Efectivo recibido</span>
          <strong><?= money($cash_received ?? 0) ?></strong>
        </div>
        <div class="row">
          <span>Cambio</span>
          <strong><?= money($change_due ?? 0) ?></strong>
        </div>
      <?php endif; ?>
    </div>

    <div class="note">
      Gracias por preferirnos. • Documento generado por CEVIMEP
    </div>

    <div class="btns">
      <button class="btn" onclick="window.print()">Imprimir</button>
      <button class="btn" onclick="window.close()">Cerrar</button>
    </div>
  </div>

  <script>
    // Auto-imprimir si quieres (opcional)
    // window.onload = () => window.print();
  </script>
</body>
</html>
