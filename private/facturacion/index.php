<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if (empty($_SESSION['user'])) {
  header("Location: /login.php");
  exit;
}

$user = $_SESSION['user'];
$branchId = (int)($user['branch_id'] ?? 0);

if ($branchId <= 0) {
  header("Location: /private/dashboard.php");
  exit;
}

/* ================= DB ================= */
$db_candidates = [
  __DIR__ . "/../config/db.php",
  __DIR__ . "/../../config/db.php",
  __DIR__ . "/../db.php",
  __DIR__ . "/../../db.php",
];

$loaded = false;
foreach ($db_candidates as $f) {
  if (is_file($f)) {
    require_once $f;
    $loaded = true;
    break;
  }
}

if (!$loaded || !isset($pdo)) {
  die("Error de conexión a la base de datos.");
}

function h($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* ================= Sucursal ================= */
$branchName = "Sucursal ID: {$branchId}";
try {
  $st = $pdo->prepare("SELECT name FROM branches WHERE id = ? LIMIT 1");
  $st->execute([$branchId]);
  if ($n = $st->fetchColumn()) {
    $branchName = $n;
  }
} catch (Throwable $e) {}

/* ================= Buscar ================= */
$search = trim((string)($_GET['q'] ?? ''));

/* ================= Detectar tabla facturas ================= */
$invoiceTable = null;
foreach (["invoices", "facturas"] as $t) {
  try {
    $chk = $pdo->prepare("SHOW TABLES LIKE ?");
    $chk->execute([$t]);
    if ($chk->fetchColumn()) {
      $invoiceTable = $t;
      break;
    }
  } catch (Throwable $e) {}
}

/* ================= Query ================= */
$params = [$branchId];

$sql = "
  SELECT
    p.id,
    CONCAT(p.first_name,' ',p.last_name) AS patient_name,
    COUNT(i.id) AS invoice_count
  FROM patients p
  LEFT JOIN {$invoiceTable} i
    ON i.patient_id = p.id
   AND i.branch_id = p.branch_id
  WHERE p.branch_id = ?
";

if ($search !== '') {
  $sql .= " AND (
    CONCAT(p.first_name,' ',p.last_name) LIKE ?
    OR p.no_libro LIKE ?
    OR p.cedula LIKE ?
  )";
  $like = "%{$search}%";
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}

$sql .= "
  GROUP BY p.id, p.first_name, p.last_name
  ORDER BY p.first_name, p.last_name
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Facturación | CEVIMEP</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="stylesheet" href="/assets/css/styles.css">
  <link rel="stylesheet" href="/assets/css/facturacion.css">
</head>
<body>

<header class="navbar">
  <div class="inner">
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
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
      <a class="active" href="/private/facturacion/index.php">🧾 Facturación</a>
      <a href="/private/caja/index.php">💳 Caja</a>
      <a href="/private/inventario/index.php">📦 Inventario</a>
      <a href="/private/estadistica/index.php">📊 Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <div class="fact-wrap">

      <div class="fact-head">
        <h1>Facturación</h1>
        <p>Sucursal actual: <?= h($branchName) ?></p>
      </div>

      <div class="fact-actions">
        <form class="fact-search" method="get">
          <input type="search" name="q"
            placeholder="Buscar paciente por nombre, No. libro o cédula…"
            value="<?= h($search) ?>">
          <button class="btn btn-primary">Buscar</button>
        </form>
      </div>

      <div class="fact-card">
        <div class="fact-card-head">
          <div class="title">Pacientes</div>
          <div class="subtitle">Estado (por sucursal)</div>
        </div>

        <?php if (empty($patients)): ?>
          <div class="fact-empty">No hay pacientes.</div>
        <?php else: ?>
          <?php foreach ($patients as $p): ?>
            <?php
              $pid = (int)$p['id'];
              $hasInvoice = ((int)$p['invoice_count'] > 0);
            ?>
            <div class="fact-row">
              <div class="fact-name">
                <a href="/private/facturacion/paciente.php?patient_id=<?= $pid ?>">
                  <?= h($p['patient_name']) ?>
                </a>
              </div>

              <a class="fact-badge <?= $hasInvoice ? 'ok' : 'no' ?>"
                 href="/private/facturacion/paciente.php?patient_id=<?= $pid ?>">
                <?= $hasInvoice ? 'Con factura' : 'Sin factura' ?>
              </a>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </div>
  </main>
</div>

<footer class="footer">
  © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>
