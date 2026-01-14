<?php
declare(strict_types=1);

/**
 * CEVIMEP - Pacientes (Railway OK + UI completa)
 * - Sesión compartida path=/
 * - Rutas absolutas (sin /public)
 * - Tabla completa + buscador + botones (Nuevo paciente / Volver)
 * - SIN el cuadro grande (hero) arriba
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
$params = [];
$where  = [];

if (!$isAdmin && !empty($branchId)) {
  $where[] = "branch_id = :branch_id";
  $params[':branch_id'] = $branchId;
}

if ($search !== '') {
  // Busca por nombre / cedula / telefono / correo
  $where[] = "(full_name LIKE :q OR cedula LIKE :q OR phone LIKE :q OR email LIKE :q)";
  $params[':q'] = "%{$search}%";
}

/**
 * Columnas esperadas según tu vista vieja:
 * full_name, cedula, phone, email, birth_date, gender, blood_type
 * (Si alguna columna no existe en tu BD, dime el nombre real y lo ajusto.)
 */
$sql = "
  SELECT
    id,
    full_name,
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

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$year = date('Y');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pacientes | CEVIMEP</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=4">

  <!-- Estilos mínimos para que quede como tu captura "antes" -->
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
      border:1px solid rgba(255,255,255,.0);
      color:#fff;
      font-weight:900;
    }
    .table-wrap{ margin-top:14px; overflow:auto; }
    .table th{ white-space:nowrap; }
    .table td{ vertical-align:middle; }
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
      <a href="/private/citas/index.php"><span class="ico">🗓️</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadistica/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <div class="page-wrap">

      <!-- SIN HERO: aquí va el header compacto como tu vista de antes -->
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
                <th style="width:80px;">Edad</th>
                <th style="width:110px;">Género</th>
                <th style="width:110px;">Sangre</th>
                <th style="width:140px;">Acciones</th>
              </tr>
            </thead>

            <tbody>
              <?php if (!$patients): ?>
                <tr>
                  <td colspan="9" class="muted">No hay pacientes registrados.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($patients as $p): ?>
                  <tr>
                    <td><?= (int)$p['id'] ?></td>
                    <td><?= htmlspecialchars((string)($p['full_name'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string)($p['cedula'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string)($p['phone'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string)($p['email'] ?? '')) ?></td>
                    <td><?= htmlspecialchars(calcAge($p['birth_date'] ?? null)) ?></td>
                    <td>
                      <?php $g = (string)($p['gender'] ?? ''); ?>
                      <?= $g !== '' ? '<span class="pill">'.htmlspecialchars($g).'</span>' : '' ?>
                    </td>
                    <td>
                      <?php $bt = (string)($p['blood_type'] ?? ''); ?>
                      <?= $bt !== '' ? '<span class="pill">'.htmlspecialchars($bt).'</span>' : '' ?>
                    </td>
                    <td style="display:flex; gap:8px; flex-wrap:wrap;">
                      <a class="btn btn-small" href="/private/patients/view.php?id=<?= (int)$p['id'] ?>">Ver</a>
                      <a class="btn btn-small btn-ghost" href="/private/patients/edit.php?id=<?= (int)$p['id'] ?>">Editar</a>
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

</body>
</html>
