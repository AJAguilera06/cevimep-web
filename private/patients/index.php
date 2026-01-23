<?php
// private/patients/index.php
session_start();

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../config/db.php';

// Seguridad básica
$branchId = $_SESSION['branch_id'] ?? null;
$role     = $_SESSION['role'] ?? '';

// Si por alguna razón no hay branch_id, mandamos al dashboard (o login)
if (!$branchId) {
  header("Location: /private/dashboard.php");
  exit;
}

// ------------------------
// BUSCADOR (GET ?q=)
// ------------------------
$search = trim($_GET['q'] ?? '');
$searchLike = '%' . $search . '%';

// ------------------------
// QUERY BASE
// ------------------------
$where  = [];
$params = [];

// Siempre filtrado por sucursal (automático) como tú quieres
$where[] = "p.branch_id = :branch_id";
$params['branch_id'] = (int)$branchId;

// Si hay búsqueda, filtra por NO_LIBRO, nombre completo, cédula, teléfono, email
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

// ------------------------
// Helpers
// ------------------------
function h($v) {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function calcAge($birthDate) {
  if (!$birthDate) return '';
  try {
    $dob = new DateTime($birthDate);
    $now = new DateTime();
    return $now->diff($dob)->y;
  } catch (Exception $e) {
    return '';
  }
}

// Nombre sucursal para el título (si lo tienes en sesión)
$branchName = $_SESSION['branch_name'] ?? ($_SESSION['full_name'] ?? 'CEVIMEP');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pacientes | CEVIMEP</title>

  <!-- CSS GLOBAL (dashboard style) -->
  <link rel="stylesheet" href="/assets/css/styles.css">
  <!-- CSS DEL MÓDULO -->
  <link rel="stylesheet" href="/assets/css/paciente.css">
</head>

<body class="app-body">
  <!-- TOPBAR -->
  <header class="topbar">
    <div class="topbar-left"></div>

    <div class="topbar-center">
      <div class="brand-dot"></div>
      <div class="brand-title">CEVIMEP</div>
    </div>

    <div class="topbar-right">
      <a class="btn-logout" href="/logout.php">Salir</a>
    </div>
  </header>

  <div class="app-shell">
    <!-- SIDEBAR -->
    <aside class="sidebar">
      <div class="sidebar-title">Menú</div>

      <nav class="sidebar-nav">
        <a class="nav-item" href="/private/dashboard.php">
          <span class="nav-ico">🏠</span>
          <span>Panel</span>
        </a>

        <a class="nav-item active" href="/private/patients/index.php">
          <span class="nav-ico">👥</span>
          <span>Pacientes</span>
        </a>

        <a class="nav-item" href="/private/citas/index.php">
          <span class="nav-ico">🗓️</span>
          <span>Citas</span>
        </a>

        <a class="nav-item" href="/private/facturacion/index.php">
          <span class="nav-ico">🧾</span>
          <span>Facturación</span>
        </a>

        <a class="nav-item" href="/private/caja/index.php">
          <span class="nav-ico">💳</span>
          <span>Caja</span>
        </a>

        <a class="nav-item" href="/private/inventario/index.php">
          <span class="nav-ico">📦</span>
          <span>Inventario</span>
        </a>

        <a class="nav-item" href="/private/estadistica/index.php">
          <span class="nav-ico">📊</span>
          <span>Estadísticas</span>
        </a>
      </nav>
    </aside>

    <!-- MAIN -->
    <main class="main">
      <div class="module-wrap patients-wrap">

        <div class="module-head">
          <div class="module-head-left">
            <h1 class="module-title">Pacientes</h1>
            <p class="module-subtitle">Listado filtrado por sucursal (automático).</p>
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
            <a class="btn btn-soft" href="/private/dashboard.php">Volver</a>
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

      </div>
    </main>
  </div>

  <!-- FOOTER -->
  <footer class="footerbar">
    © 2026 CEVIMEP. Todos los derechos reservados.
  </footer>
</body>
</html>
