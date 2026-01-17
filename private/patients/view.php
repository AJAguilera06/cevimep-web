<?php
declare(strict_types=1);

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
    $today = new DateTime();
    return (string)$today->diff($dob)->y;
  } catch (Exception $e) {
    return "";
  }
}

$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) { die("ID inválido."); }

if ($isAdmin) {
  $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = :id");
  $stmt->execute(["id" => $id]);
} else {
  $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = :id AND branch_id = :branch_id");
  $stmt->execute(["id" => $id, "branch_id" => (int)$branchId]);
}
$p = $stmt->fetch();
if (!$p) { die("Paciente no encontrado."); }

$age  = calcAge($p["birth_date"] ?? null);
$year = (int)date("Y");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Ver paciente</title>

  <!-- MISMO CSS Y VERSION QUE DASHBOARD -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=11">

  <style>
    /* Solo estilos del contenido (sin romper layout global) */
    .card-form{
      background:#fff;
      border:1px solid #e6eef7;
      border-radius:22px;
      padding:18px;
      box-shadow:0 10px 30px rgba(2,6,23,.08);
      max-width:1200px;
      margin:0 auto;
    }
    .muted{ color:#6b7280; font-weight:600; }
    .kv{
      display:grid;
      grid-template-columns: 180px 1fr;
      gap:10px;
      padding:10px 0;
      border-bottom:1px dashed #eef3fb;
      align-items:start;
    }
    @media(max-width:900px){
      .kv{ grid-template-columns:1fr; }
    }
    .pill{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:6px 10px;
      border-radius:999px;
      background:#f3f7ff;
      border:1px solid #dbeafe;
      color:#052a7a;
      font-weight:900;
      font-size:12px;
      white-space:nowrap;
    }
    .rowActions{ display:flex; gap:10px; flex-wrap:wrap; margin-top:14px; }
  </style>
</head>

<body>

<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right">
      <a class="btn-pill" href="/logout.php">Salir</a>
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

    <section class="hero">
      <h1>Paciente</h1>
      <p><?php echo h(($p["first_name"] ?? "")." ".($p["last_name"] ?? "")); ?></p>
    </section>

    <section class="card-form">
      <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
        <div>
          <h2 style="margin:0; color:#052a7a;">Detalle del paciente</h2>
          <p class="muted" style="margin:6px 0 0;">Información registrada en el sistema</p>
        </div>

        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
          <span class="pill">Edad: <?php echo h($age !== "" ? $age : "—"); ?></span>
          <a class="btn" href="index.php" style="text-decoration:none;">← Volver</a>
          <a class="btn" href="edit.php?id=<?php echo (int)$id; ?>" style="text-decoration:none;">Editar</a>
        </div>
      </div>

      <div style="margin-top:12px;">
        <div class="kv"><strong>Nombre</strong><div><?php echo h($p["first_name"] ?? ""); ?></div></div>
        <div class="kv"><strong>Apellido</strong><div><?php echo h($p["last_name"] ?? ""); ?></div></div>
        <div class="kv"><strong>Cédula</strong><div><?php echo h($p["cedula"] ?? ""); ?></div></div>
        <div class="kv"><strong>Teléfono</strong><div><?php echo h($p["phone"] ?? ""); ?></div></div>
        <div class="kv"><strong>Correo</strong><div><?php echo h($p["email"] ?? ""); ?></div></div>
        <div class="kv"><strong>Fecha nac.</strong><div><?php echo h($p["birth_date"] ?? ""); ?></div></div>
        <div class="kv"><strong>Género</strong><div><?php echo h($p["gender"] ?? ""); ?></div></div>
        <div class="kv"><strong>Tipo sangre</strong><div><?php echo h($p["blood_type"] ?? ""); ?></div></div>
        <div class="kv"><strong>Dirección</strong><div><?php echo h($p["address"] ?? ""); ?></div></div>
        <div class="kv" style="border-bottom:none;">
          <strong>Notas</strong>
          <div><?php echo nl2br(h($p["notes"] ?? "")); ?></div>
        </div>
      </div>

      <div class="rowActions">
        <a class="btn" href="index.php" style="text-decoration:none;">Volver al listado</a>
        <a class="btn" href="edit.php?id=<?php echo (int)$id; ?>" style="text-decoration:none;">Editar paciente</a>
      </div>
    </section>

  </main>
</div>

<footer class="footer">
  <div class="footer-inner">© <?php echo $year; ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

</body>
</html>
