<?php
// private/facturacion/index.php
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
foreach ($db_candidates as $pp) {
  if (is_file($pp)) {
    require_once $pp;
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

/* ===============================
   BUSCADOR + PAGINACIÓN
   =============================== */
$q = trim((string)($_GET['q'] ?? ''));

$perPage = 8;
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $perPage;

/* WHERE + params */
$where = " WHERE branch_id = ? ";
$params = [$branchId];

if ($q !== '') {
  $where .= " AND (
    CONCAT(first_name, ' ', last_name) LIKE ?
    OR no_libro LIKE ?
    OR cedula LIKE ?
  )";
  $like = "%{$q}%";
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}

/* Total */
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM patients {$where}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($totalRows / $perPage);
if ($totalPages < 1) $totalPages = 1;

if ($page > $totalPages) {
  $page = $totalPages;
  $offset = ($page - 1) * $perPage;
}

/* Listado */
$listStmt = $pdo->prepare("
  SELECT id, first_name, last_name
  FROM patients
  {$where}
  ORDER BY id DESC
  LIMIT {$perPage} OFFSET {$offset}
");
$listStmt->execute($params);
$patients = $listStmt->fetchAll(PDO::FETCH_ASSOC);

function factPageUrl(int $toPage, string $q): string {
  $query = ['page' => $toPage];
  if ($q !== '') $query['q'] = $q;
  return '/private/facturacion/index.php?' . http_build_query($query);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Facturación | CEVIMEP</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="stylesheet" href="/assets/css/styles.css?v=60">

  <style>
    /* Wrapper para que NO se desbarate la vista */
    .content{ width:100%; }

    /* Panel derecho (lista de pacientes) */
    .fact-panel{
      position: relative;
      max-width: 520px;
      width: 520px;
      margin-left: auto;
      background: rgba(255,255,255,.92);
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(0,0,0,.12);
      border: 1px solid rgba(0,0,0,.06);
      overflow: hidden;
    }
    .fact-panel-inner{ padding: 16px 16px 14px; }

    .fact-top{
      display:flex;
      gap:10px;
      align-items:center;
      justify-content:space-between;
      margin-bottom: 8px;
    }
    .fact-top h3{ margin:0; font-size: 18px; font-weight: 900; }
    .fact-top .sub{ font-weight: 800; opacity: .7; margin: 2px 0 0; }

    .fact-search{
      display:flex;
      gap:10px;
      align-items:center;
      margin: 10px 0 12px;
    }
    .fact-search input{
      width: 100%;
      border-radius: 12px;
      min-height: 42px;
      padding: 9px 12px;
      border: 1px solid rgba(0,0,0,.15);
      outline: none;
    }
    .fact-search input:focus{
      border-color:#0f4fa8;
      box-shadow:0 0 0 4px rgba(15,79,168,.10);
    }

    .patient-list{ display:flex; flex-direction:column; gap: 0; }
    .patient-row{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 12px;
      padding: 14px 10px;
      border-top: 1px solid rgba(0,0,0,.06);
    }
    .patient-name{
      font-weight: 900;
      color:#0b3e86;
      text-decoration:none;
      max-width: 320px;
      overflow:hidden;
      text-overflow:ellipsis;
      white-space:nowrap;
    }
    .btn-history{
      display:inline-block;
      padding: 8px 14px;
      border-radius: 999px;
      text-decoration:none;
      font-weight: 900;
      border:1px solid rgba(0,160,60,.35);
      background: rgba(0,160,60,.10);
      color: #0b6b2b;
      white-space:nowrap;
    }
    .btn-history:hover{ background: rgba(0,160,60,.16); }

    /* Paginación */
    .pagination-wrap{ display:flex; justify-content:center; margin: 12px 0 0; }
    .pagination{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:center; }
    .page-btn{
      display:inline-block;
      padding:8px 12px;
      border-radius:10px;
      text-decoration:none;
      font-weight:900;
      border:1px solid rgba(0,0,0,.12);
      background:#fff;
      color:#0f4fa8;
    }
    .page-btn.active{ background:#0f4fa8; border-color:#0f4fa8; color:#fff; }
    .page-btn.disabled{ opacity:.5; pointer-events:none; }
    .page-info{ opacity:.75; font-weight:800; margin-left:6px; }

    /* Layout general como dashboard */
    .fact-wrap{
      padding: 28px 22px 40px;
      display:flex;
      gap: 18px;
      align-items:flex-start;
      justify-content:space-between;
    }
    .fact-left{
      flex: 1;
      min-width: 340px;
      padding: 60px 0 0;
      text-align:center;
    }
    .fact-left h1{
      margin:0;
      font-size: 44px;
      font-weight: 900;
    }
    .fact-left p{
      margin: 10px auto 0;
      max-width: 520px;
      opacity: .85;
      font-weight: 700;
    }

    @media (max-width: 1100px){
      .fact-wrap{ flex-direction:column; }
      .fact-panel{ width: 100%; max-width: 720px; margin: 0 auto; }
      .fact-left{ padding-top: 10px; }
    }
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
    <div class="fact-wrap">

      <div class="fact-left">
        <h1>Facturación</h1>
        <p>Selecciona un paciente para ver su historial y crear una factura.</p>
      </div>

      <div class="fact-panel">
        <div class="fact-panel-inner">
          <div class="fact-top">
            <div>
              <h3>Pacientes</h3>
              <div class="sub">Sucursal actual</div>
            </div>
          </div>

          <form class="fact-search" method="get" action="/private/facturacion/index.php">
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar paciente...">
            <button class="btn btn-primary" type="submit">Buscar</button>
          </form>

          <div class="patient-list">
            <?php if (empty($patients)): ?>
              <div class="patient-row" style="justify-content:center; font-weight:800; opacity:.75;">
                No hay pacientes<?= ($q !== '' ? " con ese filtro." : ".") ?>
              </div>
            <?php else: ?>
              <?php foreach ($patients as $p): ?>
                <?php
                  $pid = (int)$p['id'];
                  $name = trim((string)($p['first_name'] ?? '') . ' ' . (string)($p['last_name'] ?? ''));
                ?>
                <div class="patient-row">
                  <span class="patient-name" title="<?= h($name) ?>"><?= h($name) ?></span>

                  <!-- Ajusta esta ruta si tu historial se llama diferente -->
                  <a class="btn-history" href="/private/facturacion/historial.php?patient_id=<?= $pid ?>">Ver historial</a>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <?php if ($totalRows > $perPage): ?>
            <div class="pagination-wrap">
              <div class="pagination">
                <a class="page-btn <?= ($page <= 1 ? 'disabled' : '') ?>"
                   href="<?= h(factPageUrl(max(1, $page - 1), $q)) ?>">Anterior</a>

                <?php
                  $window = 2;
                  $start = max(1, $page - $window);
                  $end   = min($totalPages, $page + $window);

                  if ($start > 1) {
                    echo '<a class="page-btn" href="' . h(factPageUrl(1, $q)) . '">1</a>';
                    if ($start > 2) echo '<span class="page-info">…</span>';
                  }

                  for ($i = $start; $i <= $end; $i++) {
                    $active = ($i === $page) ? 'active' : '';
                    echo '<a class="page-btn '.$active.'" href="' . h(factPageUrl($i, $q)) . '">' . $i . '</a>';
                  }

                  if ($end < $totalPages) {
                    if ($end < $totalPages - 1) echo '<span class="page-info">…</span>';
                    echo '<a class="page-btn" href="' . h(factPageUrl($totalPages, $q)) . '">' . $totalPages . '</a>';
                  }
                ?>

                <a class="page-btn <?= ($page >= $totalPages ? 'disabled' : '') ?>"
                   href="<?= h(factPageUrl(min($totalPages, $page + 1), $q)) ?>">Siguiente</a>

                <span class="page-info">
                  Página <?= (int)$page ?> de <?= (int)$totalPages ?> (<?= (int)$totalRows ?>)
                </span>
              </div>
            </div>
          <?php endif; ?>

        </div>
      </div>

    </div>
  </main>
</div>

<footer class="footer">
  © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>
