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

/* ===============================
   DB (ruta robusta)
   =============================== */
$db_candidates = [
  __DIR__ . "/../config/db.php",
  __DIR__ . "/../../config/db.php",
  __DIR__ . "/../db.php",
  __DIR__ . "/../../db.php",
];

$loaded = false;
foreach ($db_candidates as $p) {
  if (is_file($p)) {
    require_once $p;
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

/* ===============================
   Nombre de sucursal (si existe branches)
   =============================== */
$branchName = "Sucursal ID: " . $branchId;
try {
  if (tableExists($pdo, "branches")) {
    $stB = $pdo->prepare("SELECT name FROM branches WHERE id = ? LIMIT 1");
    $stB->execute([$branchId]);
    $bn = $stB->fetchColumn();
    if ($bn) $branchName = (string)$bn;
  }
} catch (Throwable $e) {}

/* ===============================
   Buscar paciente
   =============================== */
$search = trim((string)($_GET['q'] ?? ''));

/* ===============================
   Tabla de facturas (detecta nombre)
   =============================== */
$invoiceTable = null;
foreach (["invoices", "facturas", "billing_invoices"] as $cand) {
  if (tableExists($pdo, $cand)) {
    $invoiceTable = $cand;
    break;
  }
}

/* ===============================
   Query: pacientes de la sucursal + estado con/sin factura
   - Usamos parámetros POSICIONALES para evitar HY093
   =============================== */
$params = [];
if ($invoiceTable) {
  $sql = "
    SELECT
      p.id,
      CONCAT(p.first_name,' ',p.last_name) AS patient_name,
      COUNT(i.id) AS invoice_count
    FROM patients p
    LEFT JOIN {$invoiceTable} i
      ON i.patient_id = p.id
     AND i.branch_id  = p.branch_id
    WHERE p.branch_id = ?
  ";
  $params[] = $branchId;

  if ($search !== '') {
    $sql .= " AND (CONCAT(p.first_name,' ',p.last_name) LIKE ? OR p.no_libro LIKE ? OR p.cedula LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
  }

  $sql .= "
    GROUP BY p.id, p.first_name, p.last_name
    ORDER BY p.first_name ASC, p.last_name ASC
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
  // Si no existe tabla de facturas, igual listamos pacientes (estado = sin factura)
  $sql = "
    SELECT
      p.id,
      CONCAT(p.first_name,' ',p.last_name) AS patient_name,
      0 AS invoice_count
    FROM patients p
    WHERE p.branch_id = ?
  ";
  $params[] = $branchId;

  if ($search !== '') {
    $sql .= " AND (CONCAT(p.first_name,' ',p.last_name) LIKE ? OR p.no_libro LIKE ? OR p.cedula LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
  }

  $sql .= " ORDER BY p.first_name ASC, p.last_name ASC";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Facturación | CEVIMEP</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- MISMO CSS DEL DASHBOARD -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=60">
  <!-- Tu CSS (ahora mismo está vacío/“asd”, pero lo dejamos linkeado) -->
  <link rel="stylesheet" href="/assets/css/facturacion.css?v=2">

  <style>
    /* Layout centrado tipo "Pacientes" */
    .wrap{max-width:1100px;margin:0 auto;padding:24px 18px;}
    .head{text-align:center;margin-top:10px;margin-bottom:14px;}
    .head h1{margin:0;font-size:34px;font-weight:800;}
    .head p{margin:6px 0 0;opacity:.75;font-weight:600;}
    .actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin:18px 0 18px;}
    .searchform{display:flex;gap:10px;align-items:center;justify-content:center;flex-wrap:wrap;}
    .searchform .input{min-width:340px;}
    .card{max-width:780px;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.10);padding:16px;}
    .table{width:100%;border-collapse:separate;border-spacing:0;}
    .table thead th{font-weight:800;}
    .list-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 10px;border-bottom:1px solid rgba(0,0,0,.06);}
    .list-row:last-child{border-bottom:0;}
    .pname{font-weight:800;}
    .pname a{color:#0b3a8a;text-decoration:none;}
    .pname a:hover{text-decoration:underline;}
    .badge{padding:7px 12px;border-radius:999px;font-weight:800;font-size:12px;white-space:nowrap;}
    .ok{background:#e9fff1;border:1px solid #9be7b2;color:#0b7a2b;}
    .no{background:#ffecec;border:1px solid #ffb3b3;color:#b30000;}
    .empty{opacity:.7;text-align:center;padding:18px;font-weight:700;}
    @media (max-width: 520px){ .searchform .input{min-width:100%;} }
  </style>
</head>

<body>

<header class="navbar">
  <div class="inner">
    <div class="brand">
      <span class="dot"></span>
      <span>CEVIMEP</span>
    </div>
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
      <a href="/private/citas/index.php">📅 Citas</a>
      <a class="active" href="/private/facturacion/index.php">🧾 Facturación</a>
      <a href="/private/caja/index.php">💳 Caja</a>
      <a href="/private/inventario/index.php">📦 Inventario</a>
      <a href="/private/estadistica/index.php">📊 Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <div class="wrap">

      <div class="head">
        <h1>Facturación</h1>
        <p>Sucursal actual: <?= h($branchName) ?></p>
      </div>

      <div class="actions">
        <form class="searchform" method="get" action="/private/facturacion/index.php">
          <input
            class="input"
            type="search"
            name="q"
            placeholder="Buscar paciente por nombre, No. libro o cédula…"
            value="<?= h($search) ?>"
          >
          <button class="btn btn-primary" type="submit">Buscar</button>
        </form>
      </div>

      <div class="card">
        <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:10px;align-items:center;flex-wrap:wrap;">
          <div style="font-weight:900;">Pacientes</div>
          <div style="opacity:.7;font-weight:700;">Estado (por sucursal)</div>
        </div>

        <?php if (empty($patients)): ?>
          <div class="empty">No hay pacientes para esta sucursal<?= $search ? " con ese filtro." : "." ?></div>
        <?php else: ?>
          <?php foreach ($patients as $p): ?>
            <?php
              $pid = (int)($p['id'] ?? 0);
              $name = (string)($p['patient_name'] ?? '');
              $count = (int)($p['invoice_count'] ?? 0);
              $hasInvoice = $count > 0;
            ?>
            <div class="list-row">
              <div class="pname">
                <!-- Si tienes create.php en facturacion, esto abre la factura del paciente -->
                <a href="/private/facturacion/create.php?patient_id=<?= $pid ?>"><?= h($name) ?></a>
              </div>
              <div class="badge <?= $hasInvoice ? 'ok' : 'no' ?>">
                <?= $hasInvoice ? 'Con factura' : 'Sin factura' ?>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!$invoiceTable): ?>
          <div style="margin-top:12px;opacity:.65;font-weight:700;font-size:12px;">
            Nota: No se detectó tabla de facturas (invoices/facturas). Mostrando pacientes, estado por defecto “Sin factura”.
          </div>
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
