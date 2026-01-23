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
   SQL (PARAMS POSICIONALES -> NO HY093)
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
  WHERE p.branch_id = ?
";

$params = [$branchId];

if ($search !== '') {
  $sql .= " AND (
    p.no_libro LIKE ?
    OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?
    OR p.cedula LIKE ?
    OR p.phone LIKE ?
    OR p.email LIKE ?
  )";
  $like = "%{$search}%";
  // repetir el mismo valor por cada LIKE
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}

$sql .= " ORDER BY p.id DESC";

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

  <link rel="stylesheet" href="/assets/css/styles.css?v=60">
  <link rel="stylesheet" href="/assets/css/paciente.css?v=2">

  <style>
    /* Solo para acomodar como pediste, sin romper tu CSS */
    .patients-wrap{max-width:1200px;margin:0 auto;padding:24px 18px;}
    .patients-header{text-align:center;margin-top:10px;margin-bottom:18px;}
    .patients-header h1{margin:0;font-size:34px;font-weight:800;}
    .patients-header p{margin:6px 0 0;opacity:.75;}
    .patients-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin:18px 0 18px;}
    .patients-actions form{display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:center;}
    .patients-actions input{min-width:320px;}
    .patients-table{margin-top:10px;}
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
      <a class="active" href="/private/patients/index.php">👤 Pacientes</a>
      <a href="/private/citas/index.php">📅 Citas</a>
      <a href="/private/facturacion/index.php">🧾 Facturación</a>
      <a href="/private/caja/index.php">💳 Caja</a>
      <a href="/private/inventario/index.php">📦 Inventario</a>
      <a href="/private/estadistica/index.php">📊 Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <div class="patients-wrap">

      <div class="patients-header">
        <h1>Pacientes</h1>
        <p>Listado filtrado por sucursal (automático).</p>
      </div>

      <div class="patients-actions">
        <form method="get" action="/private/patients/index.php">
          <input
            class="input"
            type="search"
            name="q"
            placeholder="Buscar por nombre, No. libro, cédula, teléfono, correo..."
            value="<?= h($search) ?>"
          >
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
              <td colspan="9" class="td-empty">
                No hay pacientes<?= $search ? " con ese filtro." : "." ?>
              </td>
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

    </div>
  </main>
</div>

<footer class="footer">
  © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>
