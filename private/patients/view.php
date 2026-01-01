<?php
session_start();
if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }
require_once __DIR__ . "/../../config/db.php";

$isAdmin = (($_SESSION["user"]["role"] ?? "") === "admin");
$branchId = $_SESSION["user"]["branch_id"] ?? null;

if (!$isAdmin && empty($branchId)) {
  header("Location: ../../public/logout.php");
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
if ($id <= 0) die("ID inválido.");

if ($isAdmin) {
  $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = :id");
  $stmt->execute(["id" => $id]);
} else {
  $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = :id AND branch_id = :branch_id");
  $stmt->execute(["id" => $id, "branch_id" => (int)$branchId]);
}
$p = $stmt->fetch();
if (!$p) { die("Paciente no encontrado."); }

$age = calcAge($p["birth_date"] ?? null);
$year = date("Y");
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Ver paciente</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <style>
    body{ overflow:auto; background:#f4f7fb; }
    .wrap{ max-width:900px; margin:auto; padding:22px; }
    .card{ background:#fff; border:1px solid #e6eef7; border-radius:22px; padding:16px; }
    .title{ margin:0; color:#052a7a; }
    .muted{ color:#6b7a90; }
    .btn2{ display:inline-block; padding:10px 14px; border-radius:14px; border:1px solid #dbe6f5; background:#fff; color:#052a7a; text-decoration:none; font-weight:700; }
    .kv{ display:grid; grid-template-columns: 180px 1fr; gap:10px; padding:8px 0; border-bottom:1px dashed #eef3fb; }
  </style>
</head>
<body>
  <div class="wrap">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
      <div>
        <h2 class="title">Paciente</h2>
        <p class="muted"><?= htmlspecialchars(($p["first_name"] ?? "")." ".($p["last_name"] ?? "")) ?></p>
      </div>
      <a class="btn2" href="index.php">← Volver</a>
    </div>

    <div class="card" style="margin-top:14px;">
      <div class="kv"><strong>Nombre</strong><div><?= htmlspecialchars($p["first_name"] ?? "") ?></div></div>
      <div class="kv"><strong>Apellido</strong><div><?= htmlspecialchars($p["last_name"] ?? "") ?></div></div>
      <div class="kv"><strong>Cédula</strong><div><?= htmlspecialchars($p["cedula"] ?? "") ?></div></div>
      <div class="kv"><strong>Teléfono</strong><div><?= htmlspecialchars($p["phone"] ?? "") ?></div></div>
      <div class="kv"><strong>Correo</strong><div><?= htmlspecialchars($p["email"] ?? "") ?></div></div>
      <div class="kv"><strong>Fecha nac.</strong><div><?= htmlspecialchars($p["birth_date"] ?? "") ?></div></div>
      <div class="kv"><strong>Edad</strong><div><?= htmlspecialchars($age) ?></div></div>
      <div class="kv"><strong>Género</strong><div><?= htmlspecialchars($p["gender"] ?? "") ?></div></div>
      <div class="kv"><strong>Tipo sangre</strong><div><?= htmlspecialchars($p["blood_type"] ?? "") ?></div></div>
      <div class="kv"><strong>Dirección</strong><div><?= htmlspecialchars($p["address"] ?? "") ?></div></div>
      <div class="kv" style="border-bottom:none;"><strong>Notas</strong><div><?= nl2br(htmlspecialchars($p["notes"] ?? "")) ?></div></div>
    </div>

    <p class="muted">© <?= $year ?> CEVIMEP</p>
  </div>
</body>
</html>
