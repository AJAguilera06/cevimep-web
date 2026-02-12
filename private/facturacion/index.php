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

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM patients {$where}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($totalRows / $perPage);
if ($totalPages < 1) $totalPages = 1;

if ($page > $totalPages) {
  $page = $totalPages;
  $offset = ($page - 1) * $perPage;
}

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
    /* Ajusta estas alturas si tu navbar/footer cambian en tu CSS */
    :root{
      --topbar-h: 72px;
      --footer-h: 56px;
    }

    /* Asegura que el área central sea EXACTA entre las dos barras */
    html, body { height:100%; }
    body { margin:0; }

    .layout { min-height: calc(100vh - var(--topbar-h) - var(--footer-h)); }
    .content{
      width:100%;
      padding:0; /* para que no empuje el contenido hacia abajo */
    }

    /* Contenedor principal de facturación dentro del área blanca */
    .fact-shell{
      height: calc(100vh - var(--topbar-h) - var(--footer-h));
      padding: 18px 18px 18px;
      box-sizing: border-box;
      display:flex;
      gap: 18px;
      align-items: stretch; /* ✅ misma altura en ambos lados */
      justify-content: space-between;
    }

    /* Lado izquierdo */
    .fact-left{
      flex: 1;
      min-width: 360px;
      background: transparent;
      display:flex;
      align-items:center;
      justify-content:center;
      text-align:center;
      padding: 10px;
    }
    .fact-left-inner{
      max-width: 700px;
    }
    .fact-left h1{
      margin:0;
      font-size: 54px;
      font-weight: 900;
      letter-spacing: .2px;
    }
    .fact-left p{
      margin: 10px auto 0;
      max-width: 560px;
      opacity: .85;
      font-weight: 700;
    }

    /* Panel derecho: ocupa la altura completa del área blanca */
    .fact-panel{
      width: 560px;
      max-width: 560px;
      height: 100%;
      background: rgba(255,255,255,.92);
      border-radius: 18px;
      box-shadow: 0 14px 34px rgba(0,0,0,.14);
      border: 1px solid rgba(0,0,0,.06);
      overflow: hidden;
      display:flex;
      flex-direction: column;
    }

    .fact-panel-header{
      padding: 16px 18px 10px;
      border-bottom: 1px solid rgba(0,0,0,.06);
      background: rgba(245,248,252,.9);
      text-align:center;
    }
    .fact-panel-header .title{
      font-size: 18px;
      font-weight: 900;
      margin: 0;
      line-height: 1.1;
    }
    .fact-panel-header .sub{
      margin: 4px 0 0;
      font-weight: 800;
      opacity: .7;
    }

    /* Buscador horizontal bien alineado */
    .fact-search{
      padding: 12px 18px 12px;
      display:flex;
      gap: 10px;
      align-items:center;
      border-bottom: 1px solid rgba(0,0,0,.06);
      background: #fff;
    }
    .fact-search input{
      flex: 1;
      border-radius: 12px;
      min-height: 42px;
      padding: 9px 12px;
      border: 1px solid rgba(0,0,0,.16);
      outline:none;
    }
    .fact-search input:focus{
      border-color:#0f4fa8;
      box-shadow:0 0 0 4px rgba(15,79,168,.10);
    }
    .fact-search button{
      min-height: 42px;
      border-radius: 12px;
      padding: 10px 16px;
      font-weight: 900;
      white-space: nowrap;
    }

    /* LISTA: aquí va el scroll, no en el panel entero */
    .patient-list{
      flex: 1;
      overflow: auto;
      background: #fff;
    }
    .patient-row{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 12px;
      padding: 14px 18px;
      border-top: 1px solid rgba(0,0,0,.06);
    }
    .patient-name{
      font-weight: 900;
      color:#0b3e86;
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

    /* Footer del panel (paginación) fijo abajo */
    .panel-footer{
      padding: 12px 14px 14px;
      border-top: 1px solid rgba(0,0,0,.06);
      background: rgba(245,248,252,.9);
    }
    .pagination{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      align-items:center;
      justify-content:center;
    }
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
    .page-btn.active{background:#0f4fa8;border-color:#0f4fa8;color:#fff;}
    .page-btn.disabled{opacity:.5;pointer-events:none;}
    .page-info{opacity:.75;font-weight:900;margin-left:6px;}

    /* Responsive */
    @media (max-width: 1100px){
      .fact-shell{
        height: auto;
        min-height: calc(100vh - var(--topbar-h) - var(--footer-h));
        flex-direction: column;
        align-items: stretch;
      }
      .fact-panel{ width:100%; max-width: 780px; margin: 0 auto; height: auto; }
      .patient-list{ max-height: 520px; }
      .fact-left{ min-width: auto; }
      .fact-left h1{ font-size: 44px; }
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
    <div class="fact-shell">

      <section class="fact-left">
        <div class="fact-left-inner">
          <h1>Facturación</h1>
          <p>Selecciona un paciente para ver su historial y crear una factura.</p>
        </div>
      </section>

      <section class="fact-panel">
        <div class="fact-panel-header">
          <p class="title">Pacientes</p>
          <p class="sub">Sucursal actual</p>
        </div>

        <form class="fact-search" method="get" action="/private/facturacion/index.php">
          <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar paciente...">
          <button class="btn btn-primary" type="submit">Buscar</button>
        </form>

        <div class="patient-list">
          <?php if (empty($patients)): ?>
            <div class="patient-row" style="justify-content:center; font-weight:900; opacity:.75;">
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

                <!-- AJUSTA ESTA RUTA si tu historial se llama diferente -->
                <a class="btn-history" href="/private/facturacion/paciente.php?patient_id=<?= $pid ?>">Ver historial</a>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <?php if ($totalRows > $perPage): ?>
          <div class="panel-footer">
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

              <span class="page-info">Página <?= (int)$page ?> de <?= (int)$totalPages ?> (<?= (int)$totalRows ?>)</span>
            </div>
          </div>
        <?php endif; ?>

      </section>

    </div>
  </main>
</div>

<footer class="footer">
  © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>
