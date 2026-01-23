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

// OJO: tomamos branch_id desde user (igual que dashboard.php)
$branchId = (int)($user['branch_id'] ?? 0);
$role     = (string)($user['role'] ?? '');
$branchName = (string)($user['full_name'] ?? 'CEVIMEP');

if ($branchId <= 0) {
  // Si no hay sucursal, por seguridad vuelve al dashboard o logout
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

/* ===============================
   Helpers
   =============================== */
function h($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function calcAge($birthDate): string {
  if (!$birthDate) return '';
  try {
    $dob = new DateTime((string)$birthDate);
    $now = new DateTime();
    return (string)$now->diff($dob)->y;
  } catch (Exception $e) {
    return '';
  }
}

/* ===============================
   BUSCADOR (GET ?q=)
   =============================== */
$search = trim((string)($_GET['q'] ?? ''));
$searchLike = '%' . $search . '%';

/* ===============================
   QUERY (siempre filtrado por sucursal)
   =============================== */
$where  = [];
$params = [];

$where[] = "p.branch_id = :branch_id";
$params['branch_id'] = $branchId;

if ($search !== '') {
  $where[] = "(
      p.no_libro LIKE :q
      OR CONCAT(p.first_name, ' ', p.last_name) LIKE :q
      OR p.cedula LIKE :q
      OR p.phone LIKE :q
      OR p.email LIKE :q
  )";
  $params['q'] = $searchLike;
}

$sqlWhere = "WHERE " . implode(" AND ", $where);

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
  $sqlWhere
  ORDER BY p.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Pacientes | CEVIMEP</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- MISMO CSS DEL DASHBOARD -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=50">
  <!-- CSS DEL MÓDULO (igual al styler según tú) -->
  <link rel="stylesheet" href="/assets/css/paciente.css?v=1">
</head>
<body>

<!-- TOPBAR (igual que dashboard.php) -->
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

  <!-- SIDEBAR (igual que dashboard.php) -->
  <aside class="sidebar">
    <div class="menu-title">Menú</div>

    <nav class="menu">
      <a href="/private/dashboard.php">🏠 Panel</a>
      <a class="active" href="/private/patients/index.php">👤 Pacientes</a>
      <a href="/private/citas/index.php">📅 Citas</a>
      <a href="/private/facturacion/index.php">🧾 Facturación</a>
      <a href="/private/caja/index.php">💳 Caja</a>
      <a href="/private/inventario/index.php">📦 Inventario</a>
      <a href="/private/estadistica/index.php">📊 Estadísticas</a>
    </nav>
  </aside>

  <!-- CONTENIDO -->
  <main class="content">

    <div class="module-head">
      <div class="module-head-left">
        <h1 class="module-title">Pacientes</h1>
        <p class="module-subtitle">
          Listado filtrado por sucursal (automático).
        </p>
      </div>

      <div class="module-head-right">
        <form class="searchbar" method="get" action="">
          <input
            class="searchbar-input"
            type="search"
            name="q"
            placeholder="Buscar por nombre, No. libro, cédula, teléfono, correo..."
            value="<?= h($search) ?>"
          >
          <button class="searchbar-btn" type="submit">Buscar</button>
        </form>

        <a class="btn btn-primary" href="/private/patients/create.php">Registrar nuevo paciente</a>
      </div>
    </div>

    <div class="card-table">
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
            <td colspan="9" class="td-empty">
              No hay pacientes para esta sucursal<?= $search ? " con ese filtro." : "." ?>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($patients as $row): ?>
            <?php
              $id = (int)$row['id'];
              $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
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

  </main>
</div>

<footer class="footer">
  © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>
