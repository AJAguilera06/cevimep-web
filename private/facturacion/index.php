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

if (!$loaded || !isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo "Error crítico: no se pudo cargar la conexión a la base de datos.";
  exit;
}

function h($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function tableExists(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

/* ================= Sucursal (nombre opcional) ================= */
$branchName = "Sucursal ID: {$branchId}";
try {
  if (tableExists($pdo, "branches")) {
    $st = $pdo->prepare("SELECT name FROM branches WHERE id = ? LIMIT 1");
    $st->execute([$branchId]);
    $n = $st->fetchColumn();
    if ($n) $branchName = (string)$n;
  }
} catch (Throwable $e) {}

/* ================= Buscar ================= */
$search = trim((string)($_GET['q'] ?? ''));

/* ================= Detectar tabla facturas ================= */
$invoiceTable = null;
foreach (["invoices", "facturas", "billing_invoices", "invoice_headers"] as $t) {
  if (tableExists($pdo, $t)) {
    $invoiceTable = $t;
    break;
  }
}

/* ================= Query ================= */
$params = [$branchId];

if ($invoiceTable) {
  // Con tabla de facturas: podemos calcular Con/Sin factura por sucursal
  $sql = "
    SELECT
      p.id,
      CONCAT(p.first_name,' ',p.last_name) AS patient_name,
      COUNT(i.id) AS invoice_count
    FROM patients p
    LEFT JOIN {$invoiceTable} i
      ON i.patient_id = p.id
     AND (i.branch_id = p.branch_id OR i.branch_id IS NULL)
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

} else {
  // SIN tabla de facturas: NO hacemos JOIN (evita el error railway.i)
  $sql = "
    SELECT
      p.id,
      CONCAT(p.first_name,' ',p.last_name) AS patient_name,
      0 AS invoice_count
    FROM patients p
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

  $sql .= " ORDER BY p.first_name, p.last_name";
}

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

  <link rel="stylesheet" href="/assets/css/styles.css?v=60">
  <link rel="stylesheet" href="/assets/css/facturacion.css?v=2">

  <style>
    /* Por si tu facturacion.css todavía no está aplicado */
    .fact-wrap{max-width:1100px;margin:0 auto;padding:24px 18px;}
    .fact-head{text-align:center;margin-top:10px;margin-bottom:14px;}
    .fact-head h1{margin:0;font-size:34px;font-weight:800;}
    .fact-head p{margin:6px 0 0;opacity:.75;font-weight:600;}
    .fact-actions{display:flex;justify-content:center;margin:18px 0;}
    .fact-search{display:flex;gap:10px;align-items:center;justify-content:center;flex-wrap:wrap;}
    .fact-search input[type="search"]{width:min(520px,85vw);height:42px;padding:10px 14px;border-radius:12px;border:1px solid rgba(0,0,0,.12);}
    .fact-card{max-width:780px;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.10);padding:16px;}
    .fact-card-head{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px;}
    .fact-card-head .title{font-weight:900;}
    .fact-card-head .subtitle{font-weight:800;opacity:.7;}
    .fact-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 10px;border-bottom:1px solid rgba(0,0,0,.06);}
    .fact-row:last-child{border-bottom:0;}
    .fact-name{font-weight:800;}
    .fact-name a{color:#0b3a8a;text-decoration:none;}
    .fact-name a:hover{text-decoration:underline;}
    .fact-badge{padding:7px 12px;border-radius:999px;font-weight:800;font-size:12px;white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;}
    .fact-badge.ok{background:#e9fff1;border:1px solid #9be7b2;color:#0b7a2b;}
    .fact-badge.no{background:#ffecec;border:1px solid #ffb3b3;color:#b30000;}
    .fact-empty{opacity:.7;text-align:center;padding:18px;font-weight:700;}
    .fact-note{margin-top:12px;opacity:.65;font-weight:700;font-size:12px;}
  </style>
</head>
<body>

<header class="navbar">
  <div class="inner">
    <div class="brand"><span class="dot"></span><span>CEVIMEP</span></div>
    <div class="nav-right"><a href="/logout.php" class="btn-pill">Salir</a></div>
  </div>
</header>

<div class="layout">
  <aside class="sidebar">
    <div class="menu-title">Menú</div>
    <nav class="menu">
      <a href="/private/dashboard.php">🏠 Panel</a>
      <a href="/private/patients/index.php">👤 Pacientes</a>
      <a href="/private/citas/index.php">📅 Citas</a>
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
        <form class="fact-search" method="get" action="/private/facturacion/index.php">
          <input type="search" name="q"
            placeholder="Buscar paciente por nombre, No. libro o cédula…"
            value="<?= h($search) ?>">
          <button class="btn btn-primary" type="submit">Buscar</button>
        </form>
      </div>

      <div class="fact-card">
        <div class="fact-card-head">
          <div class="title">Pacientes</div>
          <div class="subtitle">Estado (por sucursal)</div>
        </div>

        <?php if (empty($patients)): ?>
          <div class="fact-empty">No hay pacientes<?= $search ? " con ese filtro." : "." ?></div>
        <?php else: ?>
          <?php foreach ($patients as $p): ?>
            <?php
              $pid = (int)$p['id'];
              $hasInvoice = ((int)$p['invoice_count'] > 0);
            ?>
            <div class="fact-row">
              <div class="fact-name">
                <a href="/private/facturacion/paciente.php?patient_id=<?= $pid ?>">
                  <?= h($p['patient_name'] ?? '') ?>
                </a>
              </div>

              <a class="fact-badge <?= $hasInvoice ? 'ok' : 'no' ?>"
                 href="/private/facturacion/paciente.php?patient_id=<?= $pid ?>">
                <?= $hasInvoice ? 'Con factura' : 'Sin factura' ?>
              </a>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!$invoiceTable): ?>
          <div class="fact-note">
            Nota: No se detectó tabla de facturas (invoices/facturas). Por eso todos salen “Sin factura”.
          </div>
        <?php endif; ?>
      </div>

    </div>
  </main>
</div>

<footer class="footer">© <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.</footer>
</body>
</html>
