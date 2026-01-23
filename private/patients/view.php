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
$isAdmin = (($user['role'] ?? '') === 'admin');
$userBranchId = (int)($user['branch_id'] ?? 0);

/* DB */
$db_candidates = [
  __DIR__ . '/../config/db.php',
  __DIR__ . '/../../config/db.php',
  __DIR__ . '/../db.php',
  __DIR__ . '/../../db.php',
];

$loaded = false;
foreach ($db_candidates as $p) {
  if (is_file($p)) { require_once $p; $loaded = true; break; }
}
if (!$loaded || !isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo "Error crítico: no se pudo cargar la conexión a la base de datos.";
  exit;
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ageFrom($birthDate): string {
  if (!$birthDate) return '';
  try { return (string)((new DateTime())->diff(new DateTime((string)$birthDate))->y); }
  catch (Throwable $e) { return ''; }
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header("Location: /private/patients/index.php"); exit; }

if ($isAdmin) {
  $stmt = $pdo->prepare("SELECT p.*, b.name AS branch_name FROM patients p LEFT JOIN branches b ON b.id=p.branch_id WHERE p.id=:id");
  $stmt->execute(['id' => $id]);
} else {
  if ($userBranchId <= 0) { header("Location: /logout.php"); exit; }
  $stmt = $pdo->prepare("SELECT p.*, b.name AS branch_name FROM patients p LEFT JOIN branches b ON b.id=p.branch_id WHERE p.id=:id AND p.branch_id=:bid");
  $stmt->execute(['id' => $id, 'bid' => $userBranchId]);
}

$p = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$p) { http_response_code(404); echo "Paciente no encontrado."; exit; }

$fullName = trim((string)($p['first_name'] ?? '') . ' ' . (string)($p['last_name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Ver paciente</title>

  <link rel="stylesheet" href="/assets/css/styles.css?v=60">
  <link rel="stylesheet" href="/assets/css/paciente.css?v=2">

  <style>
    .patients-wrap{max-width:1100px;margin:0 auto;padding:24px 18px;}
    .patients-header{text-align:center;margin-top:10px;margin-bottom:14px;}
    .patients-header h1{margin:0;font-size:34px;font-weight:800;}
    .patients-header p{margin:6px 0 0;opacity:.75;}
    .patients-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin:18px 0 18px;}
    .view-card{max-width:880px;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.10);padding:18px;}
    .kv-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
    .kv{background:#f7f9fc;border:1px solid rgba(0,0,0,.06);border-radius:12px;padding:12px;}
    .k{font-weight:800;opacity:.8;margin-bottom:4px;}
    .v{font-weight:700;}
    .span2{grid-column:1 / -1;}
    @media (max-width: 820px){ .kv-grid{grid-template-columns:1fr;} .view-card{padding:14px;} }
  </style>
</head>
<body>

<header class="navbar">
  <div class="inner">
    <div class="brand"><span class="dot"></span><span>CEVIMEP</span></div>
    <div class="nav-right"><a href="/logout.php" class="btn-pill">Salir</a></div>
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
        <h1>Paciente</h1>
        <p><?= h($fullName) ?></p>
      </div>

      <div class="patients-actions">
        <a class="btn" href="/private/patients/index.php">← Volver</a>
        <a class="btn btn-primary" href="/private/patients/edit.php?id=<?= (int)$id ?>">Editar</a>
      </div>

      <div class="view-card">
        <div class="kv-grid">
          <div class="kv"><div class="k">No. Libro</div><div class="v"><?= h($p['no_libro'] ?? '') ?></div></div>
          <div class="kv"><div class="k">Sucursal</div><div class="v"><?= h($p['branch_name'] ?? '') ?></div></div>

          <div class="kv"><div class="k">Nombre</div><div class="v"><?= h($fullName) ?></div></div>
          <div class="kv"><div class="k">Cédula</div><div class="v"><?= h($p['cedula'] ?? '') ?></div></div>

          <div class="kv"><div class="k">Teléfono</div><div class="v"><?= h($p['phone'] ?? '') ?></div></div>
          <div class="kv"><div class="k">Correo</div><div class="v"><?= h($p['email'] ?? '') ?></div></div>

          <div class="kv"><div class="k">Fecha nacimiento</div><div class="v"><?= h($p['birth_date'] ?? '') ?></div></div>
          <div class="kv"><div class="k">Edad</div><div class="v"><?= h(ageFrom($p['birth_date'] ?? null)) ?></div></div>

          <div class="kv"><div class="k">Género</div><div class="v"><?= h($p['gender'] ?? '') ?></div></div>
          <div class="kv"><div class="k">Tipo de sangre</div><div class="v"><?= h($p['blood_type'] ?? '') ?></div></div>

          <div class="kv span2"><div class="k">Médico que refiere</div><div class="v"><?= h($p['medico_refiere'] ?? '') ?></div></div>
          <div class="kv span2"><div class="k">Clínica de referencia</div><div class="v"><?= h($p['clinica_referencia'] ?? '') ?></div></div>

          <div class="kv"><div class="k">ARS</div><div class="v"><?= h($p['ars'] ?? '') ?></div></div>
          <div class="kv"><div class="k">Número afiliado</div><div class="v"><?= h($p['numero_afiliado'] ?? '') ?></div></div>

          <div class="kv span2"><div class="k">Registrado por</div><div class="v"><?= h($p['registrado_por'] ?? '') ?></div></div>
        </div>
      </div>

    </div>
  </main>
</div>

<footer class="footer">© <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.</footer>
</body>
</html>
