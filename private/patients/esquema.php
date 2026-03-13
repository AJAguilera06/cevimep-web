<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if (empty($_SESSION['user'])) {
  header('Location: /login.php');
  exit;
}

$user = $_SESSION['user'];

$id = (int)($_GET['id'] ?? 0);
$isAdmin = (($user['role'] ?? '') === 'admin');
$userBranchId = (int)($user['branch_id'] ?? 0);

if ($id <= 0) {
  header("Location: /private/patients/index.php");
  exit;
}

/* DB */
$db_candidates = [
  __DIR__ . '/../config/db.php',
  __DIR__ . '/../../config/db.php',
  __DIR__ . '/../db.php',
  __DIR__ . '/../../db.php',
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

function ageFrom($birthDate): string {
  if (!$birthDate) return '';
  try {
    return (string)((new DateTime())->diff(new DateTime((string)$birthDate))->y);
  } catch (Throwable $e) {
    return '';
  }
}

/* CONSULTA PACIENTE */

if ($isAdmin) {

  $stmt = $pdo->prepare("
    SELECT p.*, b.name AS branch_name
    FROM patients p
    LEFT JOIN branches b ON b.id = p.branch_id
    WHERE p.id = :id
  ");

  $stmt->execute(['id' => $id]);

} else {

  if ($userBranchId <= 0) {
    header("Location: /logout.php");
    exit;
  }

  $stmt = $pdo->prepare("
    SELECT p.*, b.name AS branch_name
    FROM patients p
    LEFT JOIN branches b ON b.id = p.branch_id
    WHERE p.id = :id AND p.branch_id = :bid
  ");

  $stmt->execute([
    'id' => $id,
    'bid' => $userBranchId
  ]);
}

$p = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$p) {
  http_response_code(404);
  echo "Paciente no encontrado.";
  exit;
}

$fullName = trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? ''));
?>

<!DOCTYPE html>
<html lang="es">
<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CEVIMEP | Paciente</title>

<link rel="stylesheet" href="/assets/css/styles.css?v=60">
<link rel="stylesheet" href="/assets/css/paciente.css?v=2">

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

<h1>Paciente</h1>

<p><?= h($fullName) ?></p>

<div class="view-card">

<p><b>No. Libro:</b> <?= h($p['no_libro']) ?></p>
<p><b>Cédula:</b> <?= h($p['cedula']) ?></p>
<p><b>Teléfono:</b> <?= h($p['phone']) ?></p>
<p><b>Correo:</b> <?= h($p['email']) ?></p>
<p><b>Edad:</b> <?= h(ageFrom($p['birth_date'])) ?></p>
<p><b>Género:</b> <?= h($p['gender']) ?></p>
<p><b>Tipo de sangre:</b> <?= h($p['blood_type']) ?></p>

</div>

</main>
</div>

<footer class="footer">
© <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>