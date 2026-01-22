<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function money_plain($n): string { return number_format((float)$n, 2); } // 3,150.00

$user = $_SESSION["user"] ?? [];
$branch_id = (int)($user["branch_id"] ?? 0);

$id  = (int)($_GET["id"] ?? 0);
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

/* ===== factura ===== */
$invoice_date = "";
$patient_name = "";
$method = "EFECTIVO";
$subtotal = 0.0;
$coverage = 0.0;
$total = 0.0;
$cash_received = null;
$change_due = null;

try {
  // Ajusta estos nombres de tablas/campos si en tu BD se llaman distinto.
  // La idea: traer cabecera + paciente + método, etc.
  $has_method = columnExists($conn, "invoices", "payment_method");

  $sql = "
    SELECT i.*,
           p.full_name AS patient_name
    FROM invoices i
    LEFT JOIN patients p ON p.id = i.patient_id
    WHERE i.id=? AND i.branch_id=?
    LIMIT 1
  ";
  $st = $conn->prepare($sql);
  $st->execute([$id, $branch_id]);
  $inv = $st->fetch(PDO::FETCH_ASSOC);

  if (!$inv) { http_response_code(404); die("Factura no encontrada."); }

  $invoice_date = (string)($inv["created_at"] ?? $inv["date"] ?? "");
  $patient_name = (string)($inv["patient_name"] ?? "");
  if ($patient_name === "") $patient_name = (string)($inv["patient"] ?? "");

  if ($has_method) {
    $m = strtoupper(trim((string)($inv["payment_method"] ?? "")));
    $method = $m !== "" ? $m : $method;
  } else {
    $m = strtoupper(trim((string)($inv["metodo_pago"] ?? "")));
    $method = $m !== "" ? $m : $method;
  }

  $subtotal = (float)($inv["subtotal"] ?? 0);
  $coverage = (float)($inv["coverage"] ?? $inv["cobertura"] ?? 0);
  $total    = (float)($inv["total"] ?? 0);

  // Opcional (si manejas efectivo recibido / cambio)
  if (isset($inv["cash_received"])) $cash_received = (float)$inv["cash_received"];
  if (isset($inv["change_due"]))    $change_due    = (float)$inv["change_due"];

} catch (Throwable $e) {
  http_response_code(500);
  die("Error cargando factura.");
}

/* ===== items ===== */
$items = [];
try {
  // Ajusta tabla si se llama distinto (invoice_items, invoice_details, etc.)
  $stI = $conn->prepare("
    SELECT ii.*,
           COALESCE(ii.product_name, ii.name, '') AS name,
           COALESCE(ii.qty, ii.quantity, 0) AS qty
    FROM invoice_items ii
    WHERE ii.invoice_id=?
    ORDER BY ii.id ASC
  ");
  $stI->execute([$id]);
  $items = $stI->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // si tu tabla no se llama invoice_items, déjala vacía sin romper
  $items = [];
}

/* ===== logo (ajusta si tu ruta es otra) =====
   - Recomendado: usa un PNG oscuro/monocromo para que la térmica lo imprima bien.
   - Si no existe, simplemente no se mostrará.
*/
$logo_path = __DIR__ . "/../../public/assets/img/cevimep.png"; // <-- AJUSTA si tu archivo se llama distinto
$logo_url  = "../../public/assets/img/cevimep.png";            // <-- AJUSTA relativo a este print.php
$has_logo  = file_exists($logo_path);

$year = date("Y");
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Factura #<?= (int)$id ?> | CEVIMEP</title>

  <style>
    /* ===== FACTURA TÉRMICA (ESTÁNDAR CEVIMEP) =====
       - Predeterminado: 80mm. Si tu impresora es 58mm, cambia:
         @page size: 58mm auto;  y .ticket width: 58mm;
    */
    @page{
      size: 80mm auto;
      margin: 2mm;
    }

    html, body{
      background:#fff;
      margin:0;
      padding:0;
      color:#000;
      font-family: Arial, Helvetica, sans-serif;
      font-size: 12px;
      line-height: 1.25;
    }

    .ticket{
      width: 80mm;
      margin: 0 auto;
      padding: 0;
    }

    .center{ text-align:center; }
    .right{ text-align:right; }
    .bold{ font-weight:700; }
    .muted{ color:#000; opacity:.75; }

    .logo{
      display:block;
      margin: 2mm auto 1mm auto;
      max-width: 46mm;     /* se ve bien en térmica */
      height: auto;
      filter: grayscale(100%);
    }

    .title{
      font-size: 16px;
      font-weight: 800;
      letter-spacing: .5px;
      margin: 1mm 0 0 0;
    }

    .subtitle{
      font-size: 10px;
      margin: .5mm 0 0 0;
    }

    .branch{
      font-size: 12px;
      font-weight: 800;
      margin: 2mm 0 0 0;
    }

    .divider{
      border-top: 1px dashed #000;
      margin: 2mm 0;
    }

    .row{
      display:flex;
      justify-content: space-between;
      gap: 6px;
      margin: .6mm 0;
      word-break: break-word;
    }

    .row .label{ flex: 0 0 auto; }
    .row .value{ flex: 1 1 auto; text-align:left; }

    .block{
      margin: 1mm 0;
    }

    .item{
      margin: 1.2mm 0;
    }

    .item-name{
      font-weight: 800;
      text-transform: uppercase;
    }

    .item-meta{
      margin-top: .3mm;
    }

    .total{
      font-size: 14px;
      font-weight: 900;
      display:flex;
      justify-content: space-between;
      margin-top: 1mm;
    }

    .footer{
      margin-top: 2mm;
      font-size: 10px;
      text-align:center;
      opacity: .85;
    }

    .btns{
      display:flex;
      gap:8px;
      justify-content:center;
      margin: 10px 0 6px 0;
    }
    .btn{
      border:1px solid #000;
      background:#fff;
      padding:6px 10px;
      border-radius:8px;
      font-weight:800;
      cursor:pointer;
      font-size:12px;
    }

    @media print{
      .btns{ display:none !important; }
    }
  </style>
</head>

<body>
  <div class="ticket">

    <?php if ($has_logo): ?>
      <img class="logo" src="<?= h($logo_url) ?>" alt="CEVIMEP">
    <?php else: ?>
      <div class="center title">CEVIMEP</div>
    <?php endif; ?>

    <?php if ($has_logo): ?>
      <div class="center title">CEVIMEP</div>
      <div class="center subtitle">CENTRO DE VACUNACIÓN INTEGRAL</div>
      <div class="center subtitle">Y MEDICINA PREVENTIVA</div>
    <?php endif; ?>

    <div class="center branch"><?= h($branch_name) ?></div>

    <div class="divider"></div>

    <div class="block">
      <div class="row">
        <span class="label bold">Factura:</span>
        <span class="value right bold">#<?= (int)$id ?></span>
      </div>

      <div class="row">
        <span class="label bold">Fecha:</span>
        <span class="value right"><?= h($invoice_date) ?></span>
      </div>

      <div class="row">
        <span class="label bold">Paciente:</span>
        <span class="value"><?= h($patient_name) ?></span>
      </div>

      <?php if ($rep !== ""): ?>
        <div class="row">
          <span class="label bold">Representante:</span>
          <span class="value"><?= h($rep) ?></span>
        </div>
      <?php endif; ?>

      <div class="row">
        <span class="label bold">Pago:</span>
        <span class="value"><?= h($method) ?></span>
      </div>
    </div>

    <div class="divider"></div>

    <?php if (!$items): ?>
      <div class="center muted">Sin items.</div>
    <?php else: ?>
      <?php foreach ($items as $it): ?>
        <div class="item">
          <div class="item-name"><?= h((string)($it["name"] ?? "")) ?></div>
          <div class="item-meta">
            <span class="bold">Cantidad:</span> <?= (int)($it["qty"] ?? 0) ?>
          </div>
        </div>
        <div class="divider"></div>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="total">
      <span>TOTAL A PAGAR</span>
      <span><?= money_plain($total) ?></span>
    </div>

    <div class="divider"></div>

    <div class="footer">© <?= (int)$year ?> CEVIMEP. Todos los derechos reservados.</div>

    <div class="btns">
      <button class="btn" onclick="window.print()">Imprimir</button>
      <button class="btn" onclick="window.close()">Cerrar</button>
    </div>
  </div>

  <script>
    // Si quieres que imprima automáticamente al abrir:
    // window.addEventListener("load", () => window.print());
  </script>
</body>
</html>
