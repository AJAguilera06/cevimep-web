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

require_once __DIR__ . '/../../config/db.php';

$db = $pdo ?? null;
if (!$db || !($db instanceof PDO)) {
  http_response_code(500);
  echo "DB no inicializada (PDO). Revisa config/db.php";
  exit;
}

$isAdmin  = (($_SESSION['user']['role'] ?? '') === 'admin');
$branchId = $_SESSION['user']['branch_id'] ?? null;

if (!$isAdmin && empty($branchId)) {
  header('Location: /logout.php');
  exit;
}

function calcAge(?string $birthDate): string {
  if (!$birthDate) return '';
  try {
    $dob = new DateTime($birthDate);
    $now = new DateTime();
    return (string)$now->diff($dob)->y;
  } catch (Throwable $e) {
    return '';
  }
}

$search = trim((string)($_GET['search'] ?? ''));

/**
 * Consulta a prueba de fallos:
 * - Seleccionamos columnas reales: first_name, last_name, cedula, phone
 * - Si no tienes email/gender/blood_type en tu tabla, no rompe (se deja vacío)
 */
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
    phone      LIKE :q
  )";
  $params[':q'] = "%{$search}%";
}

$sql = "
  SELECT
    id,
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
  // Si en tu tabla NO existen email/birth_date/gender/blood_type, esto podría fallar.
  // Entonces caemos a una consulta mínima que seguro existe.
  $sql2 = "
    SELECT id, first_name, last_name, cedula, phone
    FROM patients
  ";
  if ($where) $sql2 .= " WHERE " . implode(" AND ", $where);
  $sql2 .= " ORDER BY id DESC";

  $stmt2 = $db->prepare($sql2);
  $stmt2->execute($params);
  $patients = $stmt2->fetchAll(PDO::FETCH_ASSOC);
}

$year = date('Y');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pacientes | CEVIMEP</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=6">

  <style>
    .page-wrap{ padding:18px 22px 28px; }
    .panel-card{
      background:#fff;
      border:1px solid #e6eef7;
      border-radius:22px;
      box-shadow:0 12px 24px rgba(2,21,44,.06);
      padding:18px;
    }
    .page-head{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:14px;
      flex-wrap:wrap;
      margin-bottom:14px;
    }
    .page-title{
      margin:0;
      font-size:22px;
      font-weight:900;
      color:#052a7a;
      line-height:1.1;
    }
    .page-sub{
      margin:6px 0 0;
      color:#6b7a90;
      font-weight:700;
      font-size:13px;
    }
    .actions{
      display:flex;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
      justify-content:flex-end;
    }
    .search-input{
      width:min(360px, 70vw);
      padding:10px 12px;
      border-radius:14px;
      border:1px solid #e6eef7;
      outline:none;
    }
    .btn-primary{
      border:1px solid #1d4ed8;
      background:linear-gradient(135deg, rgba(29,78,216,.18), rgba(5,42,122,.08));
      color:#052a7a;
      font-weight:900;
    }
    .btn-solid{
      background:linear-gradient(135deg,#0ea5e9,#052a7a);
      border:1px solid rgba(255,255,255,0);
      color:#fff;
      font-weight:900;
    }
    .table-wrap{ margin-top:14px; overflow:auto; }
    .table th{ white-space:nowrap; }
    .td-actions{ display:flex; gap:8px; flex-wrap:wrap; }
    .pill{
      display:inline-flex;
      align-items:center;
      padding:6px 10px;
      border-radius:999px;
      border:1px solid #e6eef7;
      background:#f8fbff;
      color:#0f172a;
      font-weight:800;
      font-size:12px;
      white-space:nowrap;
    }
  </style>
</head>

<body>

<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right"><a class="btn-pill" href="/logout.php">Cerrar sesión</a></div>
  </div>
</header>

<div class="layout">
  <aside class="sidebar">
    <div class="menu-title">Menú</div>

    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a class="active" href="/private/patients/index.php"><span class="ico">👥</span> Pacientes</a>
      <a href="javascript:void(0)" 
   class="menu-item disabled"
   style="pointer-events: none; opacity: 0.5; cursor: not-allowed;">
    <i class="icon-calendar"></i>
    <span>Citas</span> 
  </a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadistica/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <div class="page-wrap">

      <div class="panel-card">
        <div class="page-head">
          <div>
            <h2 class="page-title">Pacientes</h2>
            <p class="page-sub">Listado filtrado por sucursal (automático).</p>
          </div>

          <div class="actions">
            <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin:0;">
              <input class="search-input" type="text" name="search" placeholder="Buscar por nombre, cédula, teléfono..." value="<?= htmlspecialchars($search) ?>">
              <button class="btn btn-primary" type="submit">Buscar</button>
            </form>

            <a class="btn btn-solid" href="/private/patients/create.php">+ Nuevo paciente</a>
            <a class="btn btn-ghost" href="/private/dashboard.php">Volver</a>
          </div>
        </div>

        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th style="width:70px;">ID</th>
                <th>Nombre</th>
                <th>Cédula</th>
                <th>Teléfono</th>
                <th>Correo</th>
                <th style="width:90px;">Edad</th>
                <th style="width:110px;">Género</th>
                <th style="width:110px;">Sangre</th>
                <th style="width:160px;">Acciones</th>
              </tr>
            </thead>

            <tbody>
            <?php if (!$patients): ?>
              <tr><td colspan="9" class="muted">No hay pacientes registrados.</td></tr>
            <?php else: ?>
              <?php foreach ($patients as $p): ?>
                <?php
                  $id = (int)($p['id'] ?? 0);
                  $nombre = trim((string)($p['first_name'] ?? '') . ' ' . (string)($p['last_name'] ?? ''));
                  $cedula = (string)($p['cedula'] ?? '');
                  $phone  = (string)($p['phone'] ?? '');
                  $email  = (string)($p['email'] ?? '');
                  $birth  = $p['birth_date'] ?? null;
                  $gender = (string)($p['gender'] ?? '');
                  $blood  = (string)($p['blood_type'] ?? '');
                ?>
                <tr>
                  <td><?= $id ?></td>
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

      </div>

    </div>
  </main>
</div>

<footer class="footer">
  <div class="inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>
