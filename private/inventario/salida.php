<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

$user = $_SESSION["user"];
$today = date("Y-m-d");
$branch_id = (int)($user["branch_id"] ?? 0);
$created_by = (int)($user["id"] ?? 0);

if ($branch_id <= 0) { die("Sucursal inválida."); }

$branch_name = "";
try {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $branch_name = (string)($stB->fetchColumn() ?: "");
} catch (Exception $e) {}

$flash_success = "";
$flash_error = "";
$print_now_batch = "";

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
    SELECT i.id, i.name, s.stock
    FROM inventory_items i
    INNER JOIN inventory_stock s ON s.item_id=i.id AND s.branch_id=?
    ORDER BY i.name ASC
  ");
  $stI->execute([$branch_id]);
  $items = $stI->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

/* ===== Guardar salida ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "save_out") {
  $item_id = (int)($_POST["item_id"] ?? 0);
  $qty     = (int)($_POST["qty"] ?? 0);
  $note    = trim((string)($_POST["note"] ?? ""));

  if ($item_id <= 0 || $qty <= 0) {
    $flash_error = "Debes seleccionar un producto y una cantidad válida.";
  } else {
    try {
      $conn->beginTransaction();

      // Validar stock actual
      $stChk = $conn->prepare("SELECT stock FROM inventory_stock WHERE branch_id=? AND item_id=? LIMIT 1");
      $stChk->execute([$branch_id, $item_id]);
      $current = (int)($stChk->fetchColumn() ?? 0);

      if ($current < $qty) {
        $conn->rollBack();
        $flash_error = "Stock insuficiente. Disponible: {$current}.";
      } else {
        $batch = "S" . date("YmdHis") . "-" . $branch_id . "-" . random_int(100, 999);

        // Insert movimiento
        $stM = $conn->prepare("
          INSERT INTO inventory_movements 
            (branch_id, item_id, type, qty, price, note, created_by, created_at, batch)
          VALUES
            (?, ?, 'OUT', ?, 0, ?, ?, NOW(), ?)
        ");
        $stM->execute([$branch_id, $item_id, $qty, $note, $created_by, $batch]);

        // Resta stock
        $stS = $conn->prepare("
          UPDATE inventory_stock 
          SET stock = stock - ?
          WHERE branch_id=? AND item_id=?
        ");
        $stS->execute([$qty, $branch_id, $item_id]);

        $conn->commit();

        $flash_success = "Salida registrada correctamente.";
        $print_now_batch = $batch;
      }
    } catch (Exception $e) {
      if ($conn->inTransaction()) $conn->rollBack();
      $flash_error = "No se pudo guardar la salida.";
    }
  }
}

/* ===== Historial del día ===== */
$history = [];
try {
  $stH = $conn->prepare("
    SELECT m.created_at, i.name, m.qty, m.note, m.batch
    FROM inventory_movements m
    INNER JOIN inventory_items i ON i.id=m.item_id
    WHERE m.branch_id=? AND m.type='OUT' AND DATE(m.created_at)=?
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
  <title>Salida - Inventario</title>
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
    .select{min-width:320px}
    .btn{height:38px;border:none;border-radius:12px;padding:0 14px;font-weight:800;cursor:pointer}
    .btn-primary{background:#e8f4ff;color:#0b4d87}
    .btn-secondary{background:#eef2f6;color:#2b3b4a}
    .flash-ok{background:#e9fff1;border:1px solid #a7f0bf;color:#0a7a33;border-radius:12px;padding:10px 12px;font-size:13px;margin-top:10px}
    .flash-err{background:#ffecec;border:1px solid #ffb6b6;color:#a40000;border-radius:12px;padding:10px 12px;font-size:13px;margin-top:10px}
    table{width:100%;border-collapse:separate;border-spacing:0}
    th,td{padding:12px 10px;border-bottom:1px solid #eef2f6;font-size:13px}
    th{color:#0b4d87;text-align:left;font-weight:800;font-size:12px;letter-spacing:.2px}
    tr:last-child td{border-bottom:none}
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
            <h1>Salida</h1>
            <div class="subtitle">Sucursal: <b><?= h($branch_name ?: "—") ?></b></div>
          </div>
          <div class="toolbar">
            <a class="btn btn-secondary" href="/private/inventario/items.php">Productos</a>
            <a class="btn btn-secondary" href="/private/inventario/index.php">Volver</a>
          </div>
        </div>

        <?php if ($flash_success): ?><div class="flash-ok"><?= h($flash_success) ?></div><?php endif; ?>
        <?php if ($flash_error): ?><div class="flash-err"><?= h($flash_error) ?></div><?php endif; ?>

        <div class="card">
          <form method="post">
            <input type="hidden" name="action" value="save_out">
            <div class="row">
              <select class="select" name="item_id" required>
                <option value="">Selecciona un producto...</option>
                <?php foreach ($items as $it): ?>
                  <option value="<?= (int)$it["id"] ?>">
                    <?= h($it["name"]) ?> (Stock: <?= (int)$it["stock"] ?>)
                  </option>
                <?php endforeach; ?>
              </select>

              <input class="input" type="number" name="qty" min="1" placeholder="Cantidad" required>
              <input class="input" type="text" name="note" placeholder="Nota (opcional)" style="min-width:320px">
              <button class="btn btn-primary" type="submit">Guardar</button>
            </div>
          </form>
        </div>

        <div class="card">
          <div style="font-weight:900;color:#0b2b4a;margin-bottom:8px">Historial de hoy (<?= h($today) ?>)</div>
          <table>
            <thead>
              <tr>
                <th>Hora</th>
                <th>Producto</th>
                <th>Cant.</th>
                <th>Nota</th>
                <th>Batch</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$history): ?>
                <tr><td colspan="5">Sin registros hoy.</td></tr>
              <?php else: ?>
                <?php foreach ($history as $r): ?>
                  <tr>
                    <td><?= h(date("h:i A", strtotime((string)$r["created_at"]))) ?></td>
                    <td><b><?= h($r["name"]) ?></b></td>
                    <td><?= (int)$r["qty"] ?></td>
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
    © <?= (int)date("Y") ?> CEVIMEP. Todos los derechos reservados.
  </footer>

</body>
</html>
