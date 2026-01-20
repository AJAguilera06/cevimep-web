<?php
declare(strict_types=1);

/**
 * CEVIMEP - Pacientes (Railway OK)
 * - Nombre sale de first_name + last_name (tu BD real)
 * - UI completa con Buscar / + Nuevo paciente / Volver
 * - Sin el HERO grande de arriba
 */

session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

if (empty($_SESSION['user'])) {
  header('Location: /login.php');
  exit;
}

require_once __DIR__ . "/../../config/db.php";
$db = $pdo;

$user = $_SESSION['user'];
$isAdmin = (($user['role'] ?? '') === 'admin');
$branchId = (int)($user['branch_id'] ?? 0);

$search = trim((string)($_GET['q'] ?? ''));

function calcAge(?string $birthDate): string {
  if (!$birthDate) return '';
  try {
    $dob = new DateTime($birthDate);
    $today = new DateTime();
    $age = $today->diff($dob)->y;
    return (string)$age;
  } catch (Throwable $e) {
    return '';
  }
}

$params = [];
$where  = [];

if (!$isAdmin && !empty($branchId)) {
  $where[] = "branch_id = :branch_id";
  $params[':branch_id'] = $branchId;
}

if ($search !== '') {
  $where[] = "(
    first_name LIKE :q OR
    last_name  LIKE :q OR
    cedula     LIKE :q OR
    phone      LIKE :q OR
    no_libro   LIKE :q
  )";
  $params[':q'] = "%{$search}%";
}

$sql = "
  SELECT
    id,
    no_libro,
    first_name,
    last_name,
    cedula,
    phone,
    email,
    birth_date,
    gender,
    blood_type
  FROM patients
";

if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY id DESC";

try {
  $stmt = $db->prepare($sql);
  $stmt->execute($params);
  $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // Fallback mínimo (por si faltan columnas en alguna instalación)
  $sql2 = "
    SELECT id, no_libro, first_name, last_name, cedula, phone
    FROM patients
  ";
  if ($where) $sql2 .= " WHERE " . implode(" AND ", $where);
  $sql2 .= " ORDER BY id DESC";

  $stmt = $db->prepare($sql2);
  $stmt->execute($params);
  $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$year = date('Y');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Pacientes</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=11">
</head>

<body>

<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right">
      <a class="btn-pill" href="/logout.php">Cerrar sesión</a>
    </div>
  </div>
</header>

<div class="layout">

  <aside class="sidebar">
    <div class="menu-title">Menú</div>
    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a class="active" href="/private/patients/index.php"><span class="ico">👥</span> Pacientes</a>
      <a href="javascript:void(0)" style="opacity:.45; cursor:not-allowed;"><span class="ico">🗓️</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadistica/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">

    <section class="card">
      <div class="card-head" style="
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:16px;
  flex-wrap:nowrap;
">

  <!-- TÍTULO -->
  <div>
    <h1 style="margin:0;">Pacientes</h1>
    <p class="muted" style="margin:6px 0 0;">
      Listado filtrado por sucursal (automático).
    </p>
  </div>

  <!-- ACCIONES DERECHA -->
  <div style="
    display:flex;
    align-items:center;
    gap:12px;
    margin-left:auto;
    white-space:nowrap;
  ">

    <!-- BUSCADOR -->
    <form method="get" style="display:flex; align-items:center; gap:8px;">
      <input
        class="input"
        type="search"
        name="q"
        placeholder="Buscar por nombre, cédula, teléfono..."
        value="<?= htmlspecialchars($search) ?>"
        style="
          width:320px;
        "
      >
      <button class="btn" type="submit">Buscar</button>
    </form>

    <!-- BOTÓN PRINCIPAL -->
    <a
      href="/private/patients/create.php"
      class="btn primary"
      style="padding:10px 16px;"
    >
      Registrar nuevo paciente
    </a>

    <!-- VOLVER -->
    <a href="/private/dashboard.php" class="btn">
      Volver
    </a>

  </div>
</div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:120px;">No. Libro</th>
              <th>Nombre</th>
              <th>Cédula</th>
              <th>Teléfono</th>
              <th>Correo</th>
              <th style="width:90px;">Edad</th>
              <th style="width:110px;">Género</th>
              <th style="width:110px;">Sangre</th>
              <th style="width:170px;">Acciones</th>
            </tr>
          </thead>

          <tbody>
            <?php if (!$patients): ?>
              <tr>
                <td colspan="9" style="padding:18px; color:#6b7280;">No hay pacientes registrados.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($patients as $p): ?>
                <?php
                  $id = (int)($p['id'] ?? 0);
                  $no_libro = trim((string)($p['no_libro'] ?? ''));
                  $nombre = trim((string)($p['first_name'] ?? '') . ' ' . (string)($p['last_name'] ?? ''));
                  $cedula = (string)($p['cedula'] ?? '');
                  $phone  = (string)($p['phone'] ?? '');
                  $email  = (string)($p['email'] ?? '');
                  $birth  = $p['birth_date'] ?? null;
                  $gender = (string)($p['gender'] ?? '');
                  $blood  = (string)($p['blood_type'] ?? '');
                ?>
                <tr>
                  <td><?= $no_libro !== '' ? htmlspecialchars($no_libro) : '—' ?></td>
                  <td><?= htmlspecialchars($nombre) ?></td>
                  <td><?= htmlspecialchars($cedula) ?></td>
                  <td><?= htmlspecialchars($phone) ?></td>
                  <td><?= htmlspecialchars($email) ?></td>
                  <td><?= htmlspecialchars(calcAge(is_string($birth) ? $birth : null)) ?></td>
                  <td><?= $gender !== '' ? '<span class="pill">'.htmlspecialchars($gender).'</span>' : '' ?></td>
                  <td><?= $blood  !== '' ? '<span class="pill">'.htmlspecialchars($blood).'</span>' : '' ?></td>
                  <td class="td-actions">
                    <a class="btn btn-small" href="/private/patients/view.php?id=<?= $id ?>">Ver</a>
                    <a class="btn btn-small btn-ghost" href="/private/patients/edit.php?id=<?= $id ?>">Editar</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>
</div>

<footer class="footer">
  <div class="footer-inner">© <?= htmlspecialchars((string)$year) ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

</body>
</html>
