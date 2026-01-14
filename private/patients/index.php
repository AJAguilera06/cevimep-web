<?php
declare(strict_types=1);

/**
 * CEVIMEP - Pacientes (Railway OK)
 */
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

if (empty($_SESSION["user"])) { header("Location: /login.php"); exit; }

require_once __DIR__ . "/../../config/db.php";

$isAdmin = (($_SESSION["user"]["role"] ?? "") === "admin");
$branchId = $_SESSION["user"]["branch_id"] ?? null;

if (!$isAdmin && empty($branchId)) {
  header("Location: /logout.php");
  exit;
}

function calcAge(?string $birthDate): string {
  if (!$birthDate) return "";
  try {
    $dob = new DateTime($birthDate);
    $now = new DateTime();
    return (string)$now->diff($dob)->y;
  } catch (Throwable $e) {
    return "";
  }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $password = (string)($_POST['password'] ?? '');

  if ($email === '' || $password === '') {
    $error = 'Completa correo y contraseña.';
  } else {
    try {
      $stmt = $pdo->prepare("
        SELECT id, full_name, email, password_hash, role, branch_id, is_active
        FROM users
        WHERE email = :email
        LIMIT 1
      ");
      $stmt->execute([':email' => $email]);
      $u = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$u || (int)$u['is_active'] !== 1 || !password_verify($password, (string)$u['password_hash'])) {
        $error = 'Correo o contraseña incorrectos.';
      } else {
        session_regenerate_id(true);
        $_SESSION['user'] = [
          'id'        => (int)$u['id'],
          'full_name' => (string)$u['full_name'],
          'email'     => (string)$u['email'],
          'role'      => (string)$u['role'],
          'branch_id' => $u['branch_id'],
        ];
        header('Location: /private/dashboard.php');
        exit;
      }
    } catch (Throwable $e) {
      $error = 'Error al conectar con la base de datos.';
    }
  }
}

/** ====== TU CÓDIGO ORIGINAL (consulta pacientes) ====== */
$search = trim($_GET['search'] ?? '');
$params = [];

$where = [];
if (!$isAdmin && !empty($branchId)) {
  $where[] = "branch_id = :branch_id";
  $params[':branch_id'] = $branchId;
}

if ($search !== '') {
  $where[] = "(full_name LIKE :search OR cedula LIKE :search OR phone LIKE :search)";
  $params[':search'] = "%{$search}%";
}

$sql = "SELECT * FROM patients";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll();

$year = date("Y");
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Pacientes</title>

  <link rel="stylesheet" href="/assets/css/styles.css">
</head>

<body>

<!-- NAVBAR -->
<div class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right">
      <a class="btn" href="/logout.php">Cerrar sesión</a>
    </div>
  </div>
</div>

<div class="layout">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <h3 class="menu-title">Menú</h3>

    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a class="active" href="/private/patients/index.php"><span class="ico">👥</span> Pacientes</a>
      <a href="/private/citas/index.php"><span class="ico">📅</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadistica/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <!-- CONTENT -->
  <main class="content">

    <div class="hero">
      <h1>Pacientes</h1>
      <p class="muted">Listado y gestión de pacientes</p>
    </div>

    <div class="card" style="margin-top:14px;">
      <form method="get" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input type="text" name="search" placeholder="Buscar por nombre, cédula o teléfono" value="<?= htmlspecialchars($search) ?>" style="min-width:280px;">
        <button class="btn" type="submit">Buscar</button>
        <a class="btn" href="/private/patients/index.php">Limpiar</a>
      </form>
    </div>

    <div class="card" style="margin-top:14px; overflow:auto;">
      <table class="table" style="width:100%; border-collapse:collapse;">
        <thead>
          <tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Cédula</th>
            <th>Teléfono</th>
            <th>Edad</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$patients): ?>
          <tr><td colspan="6" class="muted">No hay pacientes.</td></tr>
        <?php else: ?>
          <?php foreach ($patients as $p): ?>
            <tr>
              <td><?= (int)$p['id'] ?></td>
              <td><?= htmlspecialchars((string)$p['full_name']) ?></td>
              <td><?= htmlspecialchars((string)($p['cedula'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string)($p['phone'] ?? '')) ?></td>
              <td><?= htmlspecialchars(calcAge($p['birth_date'] ?? null)) ?></td>
              <td>
                <a class="btn" href="/private/patients/view.php?id=<?= (int)$p['id'] ?>">Ver</a>
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
  <div class="inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

</body>
</html>
