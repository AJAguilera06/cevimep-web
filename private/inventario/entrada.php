<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

$user = $_SESSION["user"];
$year = (int)date("Y");
$today = date("Y-m-d");
$branch_id = (int)($user["branch_id"] ?? 0);
$created_by = (int)($user["id"] ?? 0);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

if ($branch_id <= 0) {
  http_response_code(400);
  die("Sucursal inválida (branch_id).");
}

/* Nombre sucursal */
$branch_name = "";
try {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $branch_name = (string)($stB->fetchColumn() ?: "");
} catch (Exception $e) {}

/* Productos disponibles para la sucursal (según inventory_stock) */
$items = [];
try {
  $stI = $conn->prepare("
    SELECT i.id, i.name, s.quantity
    FROM inventory_items i
    INNER JOIN inventory_stock s ON s.item_id=i.id AND s.branch_id=?
    ORDER BY i.name ASC
  ");
  $stI->execute([$branch_id]);
  $items = $stI->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$flash_ok = "";
$flash_err = "";

/* Guardar Entrada */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save_in") {
  $item_id = (int)($_POST["item_id"] ?? 0);
  $qty     = (int)($_POST["qty"] ?? 0);
  $note    = trim((string)($_POST["note"] ?? ""));

  if ($item_id <= 0 || $qty <= 0) {
    $flash_err = "Debes seleccionar un producto y una cantidad válida.";
  } else {
    try {
      $conn->beginTransaction();

      // Genera batch
      $batch = "E" . date("YmdHis") . "-" . $branch_id . "-" . random_int(100, 999);

      // Si existe inventory_movements, registra; si no, no rompe: (try/catch)
      try {
        $stM = $conn->prepare("
          INSERT INTO inventory_movements
            (branch_id, item_id, type, qty, note, created_by, created_at, batch)
          VALUES
            (?, ?, 'IN', ?, ?, ?, NOW(), ?)
        ");
        $stM->execute([$branch_id, $item_id, $qty, $note, $created_by, $batch]);
      } catch (Exception $e) {
        // Si tu tabla movements no tiene esas columnas exactas, luego me dices y la ajusto.
      }

      // Actualiza stock (TU columna es quantity)
      $stS = $conn->prepare("
        UPDATE inventory_stock
        SET quantity = quantity + ?
        WHERE branch_id=? AND item_id=?
      ");
      $stS->execute([$qty, $branch_id, $item_id]);

      $conn->commit();
      $_SESSION["flash_success"] = "Entrada registrada correctamente.";
      header("Location: /private/inventario/acuse.php?batch=" . urlencode($batch));
      exit;

    } catch (Exception $e) {
      if ($conn->inTransaction()) $conn->rollBack();
      $flash_err = "No se pudo guardar la entrada.";
    }
  }
}

/* Historial del día (si existe inventory_movements) */
$history = [];
try {
  $stH = $conn->prepare("
    SELECT m.created_at, i.name, m.qty, m.note, m.batch
    FROM inventory_movements m
    INNER JOIN inventory_items i ON i.id=m.item_id
    WHERE m.branch_id=? AND m.type='IN' AND DATE(m.created_at)=?
    ORDER BY m.created_at DESC
    LIMIT 200
  ");
  $stH->execute([$branch_id, $today]);
  $history = $stH->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $history = [];
}

$flash_ok = $_SESSION["flash_success"] ?? $flash_ok;
unset($_SESSION["flash_success"]);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Entrada - Inventario</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=60">
</head>
<body>
<!-- TOP BAR (inline, sin _topbar.php) -->
<style>
  .cev-topbar{position:sticky;top:0;z-index:50;background:linear-gradient(180deg,#0b3b86 0%, #062a63 100%);color:#fff}
  .cev-topbar .inner{max-width:1200px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:12px 18px}
  .cev-brand{display:flex;align-items:center;gap:10px;font-weight:800;letter-spacing:.4px}
  .cev-dot{width:8px;height:8px;border-radius:50%;background:#22c3b8;display:inline-block}
  .cev-btn{border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.10);color:#fff;padding:6px 12px;border-radius:999px;font-weight:700;text-decoration:none}
  .cev-btn:hover{background:rgba(255,255,255,.18)}
  .cev-shell{display:flex;min-height:calc(100vh - 56px);}
  .cev-sidebar{width:240px;flex:0 0 240px;background:#fff;border-right:1px solid rgba(0,0,0,.08)}
  .cev-sidebar .box{padding:14px}
  .cev-sidebar h4{margin:8px 0 12px 0;font-size:14px;color:#0b3b86}
  .cev-nav{display:flex;flex-direction:column;gap:6px}
  .cev-nav a{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:12px;color:#0b1f3a;text-decoration:none;font-weight:700}
  .cev-nav a:hover{background:rgba(11,59,134,.07)}
  .cev-nav a.active{background:rgba(255,153,51,.14);border:1px solid rgba(255,153,51,.25)}
  .cev-content{flex:1;min-width:0}
  body{background:radial-gradient(circle at 20% 10%, rgba(34,195,184,.18) 0%, rgba(255,255,255,0) 35%),
               radial-gradient(circle at 80% 0%, rgba(11,59,134,.20) 0%, rgba(255,255,255,0) 45%),
               #f6f8fb;}
  @media (max-width: 780px){ .cev-sidebar{display:none} }
</style>
<header class="cev-topbar">
  <div class="inner">
    <div class="cev-brand"><span class="cev-dot"></span><span>CEVIMEP</span></div>
    <div><a class="cev-btn" href="/public/logout.php">Salir</a></div>
  </div>
</header>
<div class="cev-shell">
  <aside class="cev-sidebar">
    <div class="box">
      <h4>Menú</h4>
      <nav class="cev-nav">
        <a href="/private/dashboard.php">🏠 Panel</a>
        <a href="/private/patients/index.php">👤 Pacientes</a>
        <a href="/private/citas/index.php">📅 Citas</a>
        <a href="/private/facturacion/index.php">🧾 Facturación</a>
        <a href="/private/caja/index.php">💵 Caja</a>
        <a class="active" href="/private/inventario/index.php">📦 Inventario</a>
        <a href="/private/estadisticas/index.php">📊 Estadísticas</a>
      </nav>
    </div>
  </aside>
  <div class="cev-content">

<main class="content">
  <div class="page-header">
    <div>
      <h2>Entrada</h2>
      <p class="muted">Sucursal: <b><?= h($branch_name ?: "—") ?></b></p>
    </div>
    <div class="page-actions">
      <a class="btn btn-light" href="/private/inventario/items.php">Productos</a>
      <a class="btn btn-light" href="/private/inventario/index.php">Volver</a>
    </div>
  </div>

  </div>
          <div class="toolbar">
            <a class="btn btn-secondary" href="/private/inventario/items.php">Productos</a>
            <a class="btn btn-secondary" href="/private/inventario/index.php">Volver</a>
          </div>
        </div>

        <?php if ($flash_ok): ?><div class="flash-ok"><?= h($flash_ok) ?></div><?php endif; ?>
        <?php if ($flash_err): ?><div class="flash-err"><?= h($flash_err) ?></div><?php endif; ?>

        <div class="card">
          <form method="post">
            <input type="hidden" name="action" value="save_in">
            <div class="row">
              <select class="select" name="item_id" required>
                <option value="">Selecciona un producto...</option>
                <?php foreach ($items as $it): ?>
                  <option value="<?= (int)$it["id"] ?>">
                    <?= h($it["name"]) ?> (Stock: <?= (int)$it["quantity"] ?>)
                  </option>
                <?php endforeach; ?>
              </select>

              <input class="input" type="number" name="qty" min="1" placeholder="Cantidad" required>
              <input class="input" type="text" name="note" placeholder="Nota (opcional)" style="min-width:280px">
              <button class="btn btn-primary" type="submit">Guardar</button>
            </div>
          </form>
        </div>

        <div class="card">
          <div style="font-weight:900;color:#0b2b4a;margin-bottom:8px;">
            Historial de hoy (<?= h($today) ?>)
          </div>

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

  <footer class="footer-bar">© <?= (int)$year ?> CEVIMEP. Todos los derechos reservados.</footer>
</main>
  </div><!-- cev-content -->
</div><!-- cev-shell -->
</body>
</html>