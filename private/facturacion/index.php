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

function isAdminRole(string $role): bool {
  $r = mb_strtolower(trim($role));
  return in_array($r, ['administrador', 'admin', 'superadmin'], true);
}

$user = $_SESSION['user'];
$isAdmin = isAdminRole((string)($user['role'] ?? ''));

if ($isAdmin) {
  if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '') {
    $_SESSION['selected_branch_id'] = (int)$_GET['branch_id'];
  }
  $branchId = (int)($_SESSION['selected_branch_id'] ?? 0);
} else {
  $branchId = (int)($user['branch_id'] ?? 0);
  if ($branchId <= 0) {
    header("Location: /private/dashboard.php");
    exit;
  }
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
   SUCURSALES (solo admin)
   =============================== */
$branches = [];
if ($isAdmin) {
  $branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

/* ===============================
   BUSCADOR + PAGINACIÓN
   =============================== */
$q = trim((string)($_GET['q'] ?? ''));

$perPage = 8;
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $perPage;

$patients = [];
$totalRows = 0;
$totalPages = 1;

if ($branchId > 0) {
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
}

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

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <style>
    :root{
        --navy-deep:#071B32;
        --teal:#14B8A6;
        --teal-dark:#0D9488;
        --topbar-h: 72px;
        --footer-h: 56px;
    }
    *{ box-sizing:border-box; }
    html, body { height:100%; }
    body{
        margin:0;
        font-family:'Inter',sans-serif;
        background:var(--navy-deep);
        color:#fff;
        min-height:100vh;
        display:flex;
        flex-direction:column;
    }
    .navbar{ background:rgba(255,255,255,.04); border-bottom:1px solid rgba(255,255,255,.08); }
    .navbar .inner{ display:grid; grid-template-columns:1fr auto 1fr; align-items:center; padding:16px 28px; }
    .navbar .brand{ grid-column:2; justify-self:center; }
    .navbar .nav-right{ grid-column:3; justify-self:end; }
    .brand{ display:flex; align-items:center; gap:10px; font-family:'Manrope'; font-weight:800; font-size:15px; letter-spacing:.04em; }
    .brand .dot{ width:9px;height:9px;border-radius:50%;background:var(--teal); box-shadow:0 0 0 4px rgba(20,184,166,.18); }
    .btn-pill{ background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.16); color:#fff; padding:9px 16px; border-radius:999px; text-decoration:none; font-weight:700; font-size:13px; }
    .btn-pill:hover{ background:rgba(255,255,255,.14); }

    .layout{ display:flex; width:100%; position:relative; flex:1; min-height: calc(100vh - var(--topbar-h) - var(--footer-h)); }
    .auth-bg{ position:fixed; inset:0; width:100%; height:100%; z-index:0; opacity:.55; pointer-events:none; }

    .sidebar{ position:relative; z-index:1; width:230px; flex-shrink:0; padding:24px 16px; }
    .menu-title{ font-weight:700; font-size:12px; letter-spacing:.08em; text-transform:uppercase; color:#7D95AC; margin:0 0 14px 6px; }
    .menu{ display:flex; flex-direction:column; gap:2px; }
    .menu a{ display:block; padding:10px 12px; border-radius:10px; text-decoration:none; font-size:14px; font-weight:600; color:#B9CBDD; }
    .menu a:hover{ background:rgba(255,255,255,.06); }
    .menu a.active{ background:rgba(20,184,166,.18); color:var(--teal); }

    .content{ position:relative; z-index:1; flex:1; width:100%; padding:0; }
    .footer{ position:relative; z-index:1; text-align:center; padding:20px; font-size:12px; color:#5C7896; }

    .btn{ display:inline-block; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.16); color:#fff; padding:10px 18px; border-radius:999px; text-decoration:none; font-weight:700; font-size:14px; cursor:pointer; transition:background .15s; }
    .btn:hover{ background:rgba(255,255,255,.14); }
    .btn-primary{ background:var(--teal); border-color:var(--teal); color:#04211d; }
    .btn-primary:hover{ background:var(--teal-dark); color:#04211d; }

    /* Contenedor principal de facturación dentro del área */
    .fact-shell{
      height: calc(100vh - var(--topbar-h) - var(--footer-h));
      padding: 18px 18px 18px;
      box-sizing: border-box;
      display:flex;
      gap: 18px;
      align-items: stretch;
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
    .fact-left-inner{ max-width: 700px; }
    .fact-left h1{
      margin:0;
      font-family:'Manrope';
      font-size: 54px;
      font-weight: 900;
      letter-spacing: .2px;
    }
    .fact-left p{
      margin: 10px auto 0;
      max-width: 560px;
      opacity: .82;
      font-weight: 700;
      color:#9FB6CC;
    }

    /* Panel derecho: glass card oscura */
    .fact-panel{
      width: 560px;
      max-width: 560px;
      height: 100%;
      background: rgba(255,255,255,.07);
      backdrop-filter:blur(14px);
      -webkit-backdrop-filter:blur(14px);
      border-radius: 18px;
      box-shadow: 0 14px 34px rgba(0,0,0,.28);
      border: 1px solid rgba(255,255,255,.12);
      overflow: hidden;
      display:flex;
      flex-direction: column;
    }

    .fact-panel-header{
      padding: 16px 18px 10px;
      border-bottom: 1px solid rgba(255,255,255,.1);
      background: rgba(255,255,255,.03);
      text-align:center;
    }
    .fact-panel-header .title{
      font-family:'Manrope';
      font-size: 18px;
      font-weight: 900;
      margin: 0;
      line-height: 1.1;
      color:#fff;
    }
    .fact-panel-header .sub{
      margin: 4px 0 0;
      font-weight: 800;
      opacity: .78;
      color:#9FB6CC;
    }

    /* Selector de sucursal (admin) */
    .branch-select{
      width:100%;
      padding:9px 10px;
      border-radius:12px;
      border:1px solid rgba(255,255,255,.16);
      font-weight:800;
      outline:none;
      background:rgba(255,255,255,.06);
      color:#fff;
    }
    .branch-select option{ background:var(--navy-deep); color:#fff; }
    .branch-select:focus{
      border-color:var(--teal);
      box-shadow:0 0 0 4px rgba(20,184,166,.14);
    }

    /* Buscador horizontal bien alineado */
    .fact-search{
      padding: 12px 18px 12px;
      display:flex;
      gap: 10px;
      align-items:center;
      border-bottom: 1px solid rgba(255,255,255,.1);
      background: transparent;
    }
    .fact-search input{
      flex: 1;
      border-radius: 12px;
      min-height: 42px;
      padding: 9px 12px;
      border: 1px solid rgba(255,255,255,.16);
      outline:none;
      background:rgba(255,255,255,.06);
      color:#fff;
      font:inherit;
    }
    .fact-search input::placeholder{ color:#7D95AC; }
    .fact-search input:focus{
      border-color:var(--teal);
      box-shadow:0 0 0 4px rgba(20,184,166,.14);
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
      background: transparent;
    }
    .patient-row{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 12px;
      padding: 14px 18px;
      border-top: 1px solid rgba(255,255,255,.08);
    }
    .patient-name{
      font-weight: 900;
      color:#E7EEF6;
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
      border:1px solid rgba(20,184,166,.35);
      background: rgba(20,184,166,.1);
      color: var(--teal);
      white-space:nowrap;
    }
    .btn-history:hover{ background: rgba(20,184,166,.2); }

    /* Footer del panel (paginación) fijo abajo */
    .panel-footer{
      padding: 12px 14px 14px;
      border-top: 1px solid rgba(255,255,255,.1);
      background: rgba(255,255,255,.03);
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
      border:1px solid rgba(255,255,255,.16);
      background:rgba(255,255,255,.06);
      color:#fff;
    }
    .page-btn:hover{ background:rgba(255,255,255,.12); }
    .page-btn.active{background:var(--teal);border-color:var(--teal);color:#04211d;}
    .page-btn.disabled{opacity:.4;pointer-events:none;}
    .page-info{opacity:.75;font-weight:900;margin-left:6px;color:#9FB6CC;}

    /* Responsive */
    @media (max-width: 1100px){
      .layout{ flex-direction:column; }
      .sidebar{ width:100%; }
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

<svg class="auth-bg" viewBox="0 0 1400 800" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
    <circle cx="130" cy="80" r="240" fill="#0D3A5C" opacity=".5"/>
    <circle cx="1280" cy="650" r="280" fill="#0F4A63" opacity=".45"/>
    <g fill="none" stroke="#1D5C7A" stroke-width="1" opacity=".7">
        <line x1="60" y1="60" x2="180" y2="130"/><line x1="180" y1="130" x2="140" y2="250"/>
        <line x1="1220" y1="560" x2="1300" y2="620"/><line x1="1300" y1="620" x2="1250" y2="700"/>
    </g>
    <g fill="#14B8A6" opacity=".85">
        <circle cx="60" cy="60" r="3"/><circle cx="180" cy="130" r="4"/><circle cx="140" cy="250" r="3"/>
        <circle cx="1220" cy="560" r="3"/><circle cx="1300" cy="620" r="4"/><circle cx="1250" cy="700" r="3"/>
    </g>
</svg>

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
      <a href="#" onclick="return false;" style="opacity:.5;cursor:not-allowed;">
    📅 Citas (Próximamente)
</a>
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

          <?php if ($isAdmin): ?>
            <form method="get" action="/private/facturacion/index.php" style="margin-top:10px;">
              <select name="branch_id" class="branch-select" onchange="this.form.submit()">
                <option value="">— Selecciona una sucursal —</option>
                <?php foreach ($branches as $b): ?>
                  <option value="<?= (int)$b['id'] ?>" <?= ($branchId === (int)$b['id'] ? 'selected' : '') ?>>
                    <?= h($b['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php endif; ?>
        </div>

        <form class="fact-search" method="get" action="/private/facturacion/index.php">
          <?php if ($isAdmin && $branchId > 0): ?>
            <input type="hidden" name="branch_id" value="<?= (int)$branchId ?>">
          <?php endif; ?>
          <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar paciente...">
          <button class="btn btn-primary" type="submit">Buscar</button>
        </form>

        <div class="patient-list">
          <?php if ($isAdmin && $branchId <= 0): ?>
            <div class="patient-row" style="justify-content:center; font-weight:900; opacity:.75;">
              Selecciona una sucursal arriba para ver sus pacientes.
            </div>
          <?php elseif (empty($patients)): ?>
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