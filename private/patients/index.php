<?php
// private/patients/index.php
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

function calcAge($birthDate): string {
  if (!$birthDate) return '';
  try {
    $dob = new DateTime((string)$birthDate);
    $now = new DateTime();
    return (string)$now->diff($dob)->y;
  } catch (Throwable $e) {
    return '';
  }
}

/* ===============================
   BUSCADOR
   =============================== */
$search = trim((string)($_GET['q'] ?? ''));

/* ===============================
   PAGINACIÓN
   =============================== */
$perPage = 8;
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $perPage;

/* ===============================
   WHERE + PARAMS (posicionales)
   =============================== */
$where = " WHERE p.branch_id = ? ";
$params = [$branchId];

if ($search !== '') {
  $where .= " AND (
    p.no_libro LIKE ?
    OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?
    OR p.cedula LIKE ?
    OR p.phone LIKE ?
    OR p.email LIKE ?
  )";
  $like = "%{$search}%";
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}

/* ===============================
   TOTAL (para calcular páginas)
   =============================== */
$countSql = "SELECT COUNT(*) FROM patients p {$where}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$totalPages = (int)ceil($totalRows / $perPage);
if ($totalPages < 1) $totalPages = 1;

if ($page > $totalPages) {
  $page = $totalPages;
  $offset = ($page - 1) * $perPage;
}

/* ===============================
   LISTADO (limitado a 8)
   =============================== */
$sql = "
  SELECT
    p.id,
    p.no_libro,
    p.first_name,
    p.last_name,
    p.cedula,
    p.phone,
    p.email,
    p.birth_date,
    p.gender,
    p.blood_type
  FROM patients p
  {$where}
  ORDER BY p.id DESC
  LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Helper para links de paginación (mantiene q y page) */
function buildPageUrl(int $toPage, string $search): string {
  $query = [];
  if ($search !== '') $query['q'] = $search;
  $query['page'] = $toPage;
  return '/private/patients/index.php?' . http_build_query($query);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Pacientes | CEVIMEP</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="stylesheet" href="/assets/css/styles.css?v=60">
  <link rel="stylesheet" href="/assets/css/paciente.css?v=2">

  <style>
    .layout{display:flex;min-height:100vh;background:#eef3f8;}
    .sidebar{
      width:260px;
      background:linear-gradient(180deg,#0b3e86 0%, #073064 100%);
      color:#fff;
      padding:18px 14px;
      position:sticky;
      top:0;
      height:100vh;
    }
    .sidebar .brand{
      display:flex;
      align-items:center;
      justify-content:center;
      gap:10px;
      padding:10px 10px 16px;
      font-weight:900;
      letter-spacing:.5px;
      border-bottom:1px solid rgba(255,255,255,.15);
      margin-bottom:12px;
    }
    .dot{width:10px;height:10px;border-radius:999px;background:#12d27c;display:inline-block;}
    .sidebar .menu-title{opacity:.9;font-weight:800;margin:10px 10px 10px;}
    .nav{display:flex;flex-direction:column;gap:8px;padding:0 6px;}
    .nav a{
      display:flex;align-items:center;gap:10px;
      padding:12px 12px;border-radius:14px;
      color:#eaf2ff;text-decoration:none;font-weight:800;
      background:transparent;
    }
    .nav a:hover{background:rgba(255,255,255,.12);}
    .nav a.active{background:rgba(255,255,255,.16);}
    .nav .icon{width:18px;display:inline-flex;justify-content:center;opacity:.95}

    .main{flex:1;display:flex;flex-direction:column;}
    .topbar{
      height:64px;
      background:linear-gradient(180deg,#0b3e86 0%, #073064 100%);
      display:flex;align-items:center;justify-content:center;
      position:sticky;top:0;z-index:5;
      position:relative;
    }
    .topbar .center{
      display:flex;align-items:center;gap:10px;
      color:#fff;font-weight:900;letter-spacing:.6px;
    }
    .topbar .right{position:absolute;right:18px;}
    .btn-logout{
      display:inline-block;
      padding:10px 18px;
      border-radius:999px;
      border:1px solid rgba(255,255,255,.35);
      color:#fff;
      text-decoration:none;
      font-weight:900;
      background:rgba(255,255,255,.08);
    }
    .btn-logout:hover{background:rgba(255,255,255,.14);}

    .patients-wrap{max-width:1200px;margin:0 auto;padding:24px 18px;}
    .patients-header{text-align:center;margin-top:10px;margin-bottom:18px;}
    .patients-header h1{margin:0;font-size:34px;font-weight:900;}
    .patients-header p{margin:6px 0 0;opacity:.75;font-weight:600;}
    .patients-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin:18px 0 18px;}
    .patients-actions form{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .patients-actions input[type="text"]{min-width:340px;max-width:520px;width:50vw;}
    .card-table{background:#fff;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,.08);overflow:hidden;}
    .td-empty{text-align:center;padding:22px 10px;opacity:.75;font-weight:700;}
    .td-actions{white-space:nowrap;}
    .link-action{font-weight:900;text-decoration:none;}
    .sep{opacity:.5;margin:0 8px;}

    .pagination-wrap{display:flex;justify-content:center;margin-top:14px;margin-bottom:10px;}
    .pagination{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:center;}
    .page-btn{
      display:inline-block;
      padding:8px 12px;
      border-radius:10px;
      text-decoration:none;
      font-weight:900;
      border:1px solid rgba(0,0,0,.12);
      background:#fff;
      color:#0b3e86;
    }
    .page-btn.active{background:#0f4fa8;border-color:#0f4fa8;color:#fff;}
    .page-btn.disabled{opacity:.5;pointer-events:none;}
    .page-info{opacity:.75;font-weight:800;margin:0 6px;}

    .footer{text-align:center;padding:14px 10px;opacity:.75;font-weight:700;}

    @media (max-width: 900px){
      .sidebar{display:none;}
      .patients-actions input[type="text"]{min-width:240px;width:72vw;}
      .topbar .right{right:10px;}
    }
  </style>
</head>

<body>
<div class="layout">

  <aside class="sidebar">
    <div class="brand">
      <span class="dot"></span>
      <span>CEVIMEP</span>
    </div>

    <div class="menu-title">Menú</div>

    <nav class="nav">
      <a href="/private/dashboard.php"><span class="icon">🏠</span><span>Panel</span></a>
      <a class="active" href="/private/patients/index.php"><span class="icon">👤</span><span>Pacientes</span></a>
      <a href="/private/citas/index.php"><span class="icon">🗓️</span><span>Citas</span></a>
      <a href="/private/facturacion/index.php"><span class="icon">🧾</span><span>Facturación</span></a>
      <a href="/private/caja/index.php"><span class="icon">💵</span><span>Caja</span></a>
      <a href="/private/inventario/index.php"><span class="icon">📦</span><span>Inventario</span></a>
      <a href="/private/estadistica/index.php"><span class="icon">📊</span><span>Estadísticas</span></a>
    </nav>
  </aside>

  <main class="main">
    <header class="topbar">
      <div class="center">
        <span class="dot"></span>
        <span>CEVIMEP</span>
      </div>
      <div class="right">
        <a class="btn-logout" href="/logout.php">Salir</a>
      </div>
    </header>

    <div class="patients-wrap">
      <div class="patients-header">
        <h1>Pacientes</h1>
        <p>Listado filtrado por sucursal (automático).</p>
      </div>

      <div class="patients-actions">
        <form method="get" action="/private/patients/index.php">
          <input type="text" name="q" value="<?= h($search) ?>" placeholder="Buscar por nombre, No. libro, cédula, teléfono, correo">
          <button class="btn btn-primary" type="submit">Buscar</button>
        </form>

        <a class="btn" href="/private/patients/create.php">Registrar nuevo paciente</a>
      </div>

      <div class="patients-table card-table">
        <table class="table">
          <thead>
            <tr>
              <th>No. Libro</th>
              <th>Nombre</th>
              <th>Cédula</th>
              <th>Teléfono</th>
              <th>Correo</th>
              <th>Edad</th>
              <th>Género</th>
              <th>Sangre</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($patients)): ?>
            <tr>
              <td colspan="9" class="td-empty">No hay pacientes<?= $search ? " con ese filtro." : "." ?></td>
            </tr>
          <?php else: ?>
            <?php foreach ($patients as $row): ?>
              <?php
                $id = (int)$row['id'];
                $fullName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
              ?>
              <tr>
                <td><?= h($row['no_libro'] ?? '') ?></td>
                <td><?= h($fullName) ?></td>
                <td><?= h($row['cedula'] ?? '') ?></td>
                <td><?= h($row['phone'] ?? '') ?></td>
                <td><?= h($row['email'] ?? '') ?></td>
                <td><?= h(calcAge($row['birth_date'] ?? null)) ?></td>
                <td><?= h($row['gender'] ?? '') ?></td>
                <td><?= h($row['blood_type'] ?? '') ?></td>
                <td class="td-actions">
                  <a class="link-action" href="/private/patients/view.php?id=<?= $id ?>">Ver</a>
                  <span class="sep">·</span>
                  <a class="link-action" href="/private/patients/edit.php?id=<?= $id ?>">Editar</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalRows > $perPage): ?>
        <div class="pagination-wrap">
          <div class="pagination">
            <a class="page-btn <?= ($page <= 1 ? 'disabled' : '') ?>"
               href="<?= h(buildPageUrl(max(1, $page - 1), $search)) ?>">Anterior</a>

            <?php
              $window = 2;
              $start = max(1, $page - $window);
              $end = min($totalPages, $page + $window);

              if ($start > 1) {
                echo '<a class="page-btn" href="' . h(buildPageUrl(1, $search)) . '">1</a>';
                if ($start > 2) echo '<span class="page-info">…</span>';
              }

              for ($i = $start; $i <= $end; $i++) {
                $active = ($i === $page) ? 'active' : '';
                echo '<a class="page-btn ' . $active . '" href="' . h(buildPageUrl($i, $search)) . '">' . $i . '</a>';
              }

              if ($end < $totalPages) {
                if ($end < $totalPages - 1) echo '<span class="page-info">…</span>';
                echo '<a class="page-btn" href="' . h(buildPageUrl($totalPages, $search)) . '">' . $totalPages . '</a>';
              }
            ?>

            <a class="page-btn <?= ($page >= $totalPages ? 'disabled' : '') ?>"
               href="<?= h(buildPageUrl(min($totalPages, $page + 1), $search)) ?>">Siguiente</a>

            <span class="page-info">
              Página <?= (int)$page ?> de <?= (int)$totalPages ?> (<?= (int)$totalRows ?>)
            </span>
          </div>
        </div>
      <?php endif; ?>

      <div class="footer">
        © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
      </div>

    </div>
  </main>
</div>
</body>
</html>
