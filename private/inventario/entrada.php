<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

$user = $_SESSION["user"];
$year = date("Y");
$today = date("Y-m-d");
$branch_id = (int)($user["branch_id"] ?? 0);
$created_by = (int)($user["id"] ?? 0);

$made_by_name = trim(($user["name"] ?? "") . " " . ($user["lastname"] ?? ""));
if ($made_by_name === "") $made_by_name = ($user["username"] ?? "Usuario");

if ($branch_id <= 0) { die("Sucursal inválida."); }

$branch_name = "";
try {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $branch_name = (string)($stB->fetchColumn() ?: "");
} catch (Exception $e) {}

$flash_success = "";
$flash_error = "";

/* ===== Categorías ===== */
$categories = [];
try {
  $stC = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
  $categories = $stC->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

/* ===== Items disponibles SOLO por sucursal (inner join stock) ===== */
$items = [];
try {
  $stI = $conn->prepare("
    SELECT i.id, i.name
    FROM inventory_items i
    INNER JOIN inventory_stock s ON s.item_id=i.id AND s.branch_id=?
    ORDER BY i.name ASC
  ");
  $stI->execute([$branch_id]);
  $items = $stI->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

/* ===== Guardar entrada ===== */
$print_now_batch = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "save_entry") {
  $item_id = (int)($_POST["item_id"] ?? 0);
  $qty     = (int)($_POST["qty"] ?? 0);
  $price   = (float)($_POST["price"] ?? 0);
  $note    = trim((string)($_POST["note"] ?? ""));

  if ($item_id <= 0 || $qty <= 0) {
    $flash_error = "Debes seleccionar un producto y una cantidad válida.";
  } else {
    try {
      $conn->beginTransaction();

      // Insert movimiento
      $batch = "E" . date("YmdHis") . "-" . $branch_id . "-" . random_int(100, 999);

      $stM = $conn->prepare("
        INSERT INTO inventory_movements 
          (branch_id, item_id, type, qty, price, note, created_by, created_at, batch)
        VALUES
          (?, ?, 'IN', ?, ?, ?, ?, NOW(), ?)
      ");
      $stM->execute([$branch_id, $item_id, $qty, $price, $note, $created_by, $batch]);

      // Suma al stock
      $stS = $conn->prepare("
        UPDATE inventory_stock 
        SET stock = stock + ?
        WHERE branch_id=? AND item_id=?
      ");
      $stS->execute([$qty, $branch_id, $item_id]);

      $conn->commit();

      $flash_success = "Entrada registrada correctamente.";
      $print_now_batch = $batch;

      header("Content-Type: text/html; charset=utf-8");
      header("Location: /private/inventario/entrada.php?print_batch=" . urlencode($batch));
      exit;

    } catch (Exception $e) {
      if ($conn->inTransaction()) $conn->rollBack();
      $flash_error = "No se pudo guardar la entrada.";
    }
  }
}

/* ===== Imprimir batch ===== */
$batch_to_print = trim((string)($_GET["print_batch"] ?? ""));

/* ===== Historial del día ===== */
$history = [];
try {
  $stH = $conn->prepare("
    SELECT m.created_at, i.name, m.qty, m.price, m.note, m.batch
    FROM inventory_movements m
    INNER JOIN inventory_items i ON i.id=m.item_id
    WHERE m.branch_id=? AND m.type='IN' AND DATE(m.created_at)=?
    ORDER BY m.created_at DESC
    LIMIT 200
  ");
  $stH->execute([$branch_id, $today]);
  $history = $stH->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Entrada - Inventario</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=60">
  <style>
    .content-wrap{padding:22px 24px;}
    .page-title{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:12px}
    .page-title h1{margin:0;font-size:30px;font-weight:800;color:#0b2b4a}
    .subtitle{margin:2px 0 0;color:#5b6b7a;font-size:13px}
    .toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:flex-end}
    .card{background:#fff;border-radius:16px;box-shadow:0 12px 30px rgba(0,0,0,.08);padding:14px 14px;margin-top:12px}
    .row{display:flex;gap:10px;flex-wrap:wrap}
    .input,.select{height:38px;border:1px solid #d8e1ea;border-radius:12px;padding:0 12px;background:#fff;outline:none}
    .input{min-width:220px}
    .select{min-width:260px}
    .btn{height:38px;border:none;border-radius:12px;padding:0 14px;font-weight:800;cursor:pointer}
    .btn-primary{background:#e8f4ff;color:#0b4d87}
    .btn-secondary{background:#eef2f6;color:#2b3b4a}
    .flash-ok{background:#e9fff1;border:1px solid #a7f0bf;color:#0a7a33;border-radius:12px;padding:10px 12px;font-size:13px;margin-top:10px}
    .flash-err{background:#ffecec;border:1px solid #ffb6b6;color:#a40000;border-radius:12px;padding:10px 12px;font-size:13px;margin-top:10px}
    table{width:100%;border-collapse:separate;border-spacing:0}
    th,td{padding:12px 10px;border-bottom:1px solid #eef2f6;font-size:13px}
    th{color:#0b4d87;text-align:left;font-weight:800;font-size:12px;letter-spacing:.2px}
    tr:last-child td{border-bottom:none}
    .print-card{background:#fff;border:1px dashed #c7d5e5;border-radius:16px;padding:14px;margin-top:12px}
    @media print{
      .topbar,.sidebar,.toolbar,.footer,.no-print{display:none !important}
      .layout{display:block}
      .main{margin:0;padding:0}
      .content-wrap{padding:0}
      .print-card{border:none}
    }
  </style>
</head>
<body>

  <div class="topbar">
    <div class="topbar-inner">
      <div class="brand"><span class="dot"></span><span class="name">CEVIMEP</span></div>
      <div class="right"><a class="logout" href="/logout.php">Salir</a></div>
    </div>
  </div>

  <div class="layout">
    <aside class="sidebar">
      <div class="sidebar-title">Menú</div>
      <nav class="menu">
        <a class="menu-item" href="/private/dashboard.php">🏠 Panel</a>
        <a class="menu-item" href="/private/patients/index.php">👤 Pacientes</a>
        <a class="menu-item" href="/private/citas/index.php">📅 Citas</a>
        <a class="menu-item" href="/private/facturacion/index.php">🧾 Facturación</a>
        <a class="menu-item" href="/private/caja/index.php">💵 Caja</a>
        <a class="menu-item active" href="/private/inventario/index.php">📦 Inventario</a>
        <a class="menu-item" href="/private/estadisticas/index.php">📊 Estadísticas</a>
      </nav>
    </aside>

    <main class="main">
      <div class="content-wrap">

        <div class="page-title">
          <div>
            <h1>Entrada</h1>
            <div class="subtitle">Sucursal: <b><?= h($branch_name ?: "—") ?></b></div>
          </div>
          <div class="toolbar no-print">
            <a class="btn btn-secondary" href="/private/inventario/items.php">Productos</a>
            <a class="btn btn-secondary" href="/private/inventario/index.php">Volver</a>
          </div>
        </div>

        <?php if ($flash_success): ?><div class="flash-ok"><?= h($flash_success) ?></div><?php endif; ?>
        <?php if ($flash_error): ?><div class="flash-err"><?= h($flash_error) ?></div><?php endif; ?>

        <?php if ($batch_to_print !== ""): ?>
          <div class="print-card">
            <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
              <div>
                <div style="font-size:18px;font-weight:900;color:#0b2b4a">Entrada</div>
                <div style="color:#5b6b7a;font-size:13px">Sucursal: <b><?= h($branch_name ?: "—") ?></b></div>
              </div>
              <div style="text-align:right">
                <div style="font-size:12px;color:#5b6b7a">Batch</div>
                <div style="font-weight:900"><?= h($batch_to_print) ?></div>
              </div>
            </div>

            <div style="height:10px"></div>

            <?php
              $printRows = [];
              try {
                $stP = $conn->prepare("
                  SELECT m.created_at, i.name, m.qty, m.price, m.note
                  FROM inventory_movements m
                  INNER JOIN inventory_items i ON i.id=m.item_id
                  WHERE m.branch_id=? AND m.type='IN' AND m.batch=?
                  ORDER BY m.created_at ASC
                ");
                $stP->execute([$branch_id, $batch_to_print]);
                $printRows = $stP->fetchAll(PDO::FETCH_ASSOC);
              } catch (Exception $e) {}
            ?>

            <table>
              <thead>
                <tr>
                  <th>Hora</th>
                  <th>Producto</th>
                  <th>Cant.</th>
                  <th>Precio</th>
                  <th>Nota</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$printRows): ?>
                  <tr><td colspan="5">No se encontraron registros para imprimir.</td></tr>
                <?php else: ?>
                  <?php foreach ($printRows as $r): ?>
                    <tr>
                      <td><?= h(date("h:i A", strtotime((string)$r["created_at"]))) ?></td>
                      <td><b><?= h($r["name"]) ?></b></td>
                      <td><?= (int)$r["qty"] ?></td>
                      <td><?= number_format((float)$r["price"], 2) ?></td>
                      <td><?= h($r["note"]) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>

            <div style="height:10px"></div>
            <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
              <div style="font-size:13px;color:#5b6b7a">Hecho por: <b><?= h($made_by_name) ?></b></div>
              <button class="btn btn-primary no-print" onclick="window.print()">Imprimir</button>
            </div>
          </div>
        <?php endif; ?>

        <div class="card no-print">
          <form method="post">
            <input type="hidden" name="action" value="save_entry">
            <div class="row">
              <select class="select" name="item_id" required>
                <option value="">Selecciona un producto...</option>
                <?php foreach ($items as $it): ?>
                  <option value="<?= (int)$it["id"] ?>"><?= h($it["name"]) ?></option>
                <?php endforeach; ?>
              </select>

              <input class="input" type="number" name="qty" min="1" placeholder="Cantidad" required>

              <input class="input" type="number" step="0.01" name="price" placeholder="Precio (opcional)">

              <input class="input" type="text" name="note" placeholder="Nota (opcional)" style="min-width:320px">

              <button class="btn btn-primary" type="submit">Guardar</button>
            </div>
          </form>
        </div>

        <div class="card no-print">
          <div style="font-weight:900;color:#0b2b4a;margin-bottom:8px">Historial de hoy (<?= h($today) ?>)</div>
          <table>
            <thead>
              <tr>
                <th>Hora</th>
                <th>Producto</th>
                <th>Cant.</th>
                <th>Precio</th>
                <th>Nota</th>
                <th>Batch</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$history): ?>
                <tr><td colspan="6">Sin registros hoy.</td></tr>
              <?php else: ?>
                <?php foreach ($history as $r): ?>
                  <tr>
                    <td><?= h(date("h:i A", strtotime((string)$r["created_at"]))) ?></td>
                    <td><b><?= h($r["name"]) ?></b></td>
                    <td><?= (int)$r["qty"] ?></td>
                    <td><?= number_format((float)$r["price"], 2) ?></td>
                    <td><?= h($r["note"]) ?></td>
                    <td><?= h($r["batch"]) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </main>
  </div>

  <footer class="footer">
    © <?= (int)$year ?> CEVIMEP. Todos los derechos reservados.
  </footer>

</body>
</html>
