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
    $age = $today->diff($dob)->y;
    return (string)$age;
  } catch (Exception $e) {
    return "";
  }
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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
if (!$p) die("Paciente no encontrado.");

$errors = [];

$first_name = $p["first_name"] ?? "";
$last_name  = $p["last_name"] ?? "";
$cedula     = $p["cedula"] ?? "";
$phone      = $p["phone"] ?? "";
$email      = $p["email"] ?? "";
$birth_date = $p["birth_date"] ?? "";
$gender     = $p["gender"] ?? "";
$blood_type = $p["blood_type"] ?? "";
$address    = $p["address"] ?? "";
$notes      = $p["notes"] ?? "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $first_name = trim($_POST["first_name"] ?? "");
  $last_name  = trim($_POST["last_name"] ?? "");
  $cedula     = trim($_POST["cedula"] ?? "");
  $phone      = trim($_POST["phone"] ?? "");
  $email      = trim($_POST["email"] ?? "");
  $birth_date = trim($_POST["birth_date"] ?? "");
  $gender     = trim($_POST["gender"] ?? "");
  $blood_type = trim($_POST["blood_type"] ?? "");
  $address    = trim($_POST["address"] ?? "");
  $notes      = trim($_POST["notes"] ?? "");

  if ($first_name === "") $errors[] = "Nombre es obligatorio.";
  if ($last_name === "")  $errors[] = "Apellido es obligatorio.";
  if ($birth_date !== "" && calcAge($birth_date) === "") $errors[] = "Fecha de nacimiento inválida.";

  if (!$errors) {
    $sql = "
      UPDATE patients SET
        first_name = :f,
        last_name  = :l,
        cedula     = :c,
        phone      = :p,
        email      = :e,
        birth_date = :b,
        gender     = :g,
        blood_type = :bt,
        address    = :a,
        notes      = :n
      WHERE id = :id AND branch_id = :branch_id
    ";

    if ($isAdmin) {
      $sql = str_replace(" AND branch_id = :branch_id", "", $sql);
    }

    $stmt = $pdo->prepare($sql);

    $params = [
      "f" => $first_name,
      "l" => $last_name,
      "c" => $cedula,
      "p" => $phone,
      "e" => $email,
      "b" => ($birth_date !== "" ? $birth_date : null),
      "g" => $gender,
      "bt" => $blood_type,
      "a" => $address,
      "n" => $notes,
      "id" => $id,
    ];
    if (!$isAdmin) { $params["branch_id"] = (int)$branchId; }

    $stmt->execute($params);

    header("Location: index.php");
    exit;
  }
}

$year = (int)date("Y");
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Editar paciente</title>

  <link rel="stylesheet" href="/assets/css/styles.css?v=11">

  <style>
    .muted{ color:#6b7280; font-weight:600; }
    .grid{ display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:10px; }
    @media(max-width:900px){ .grid{ grid-template-columns:1fr; } }

    label{ display:block; font-weight:900; margin:10px 0 6px; color:#0f172a; }
    input, select, textarea{
      width:100%;
      padding:11px 12px;
      border-radius:14px;
      border:1px solid #e6eef7;
      outline:none;
      background:#fff;
      font-size:14px;
    }
    input:focus, select:focus, textarea:focus{
      border-color:#93c5fd;
      box-shadow:0 0 0 4px rgba(59,130,246,.12);
    }
    textarea{ min-height:90px; resize:vertical; }

    .msg{
      margin-top:14px;
      padding:10px 12px;
      border-radius:14px;
      font-weight:900;
      font-size:13px;
    }
    .msg.err{ background:#ffe8e8; border:1px solid #ffb2b2; color:#7a1010; }

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
    .btn.primary{
      border:none;
      color:#fff;
      background:linear-gradient(135deg,#0b4be3,#052a7a);
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
      <h1>Editar paciente</h1>
      <p><?= h($first_name . " " . $last_name) ?></p>
    </section>

    <section class="card">
      <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
        <div>
          <h2 style="margin:0; color:#052a7a;">Editar paciente</h2>
          <p class="muted" style="margin:6px 0 0;"><?= h($first_name . " " . $last_name) ?></p>
        </div>

        <div style="display:flex; gap:10px; align-items:center;">
          <span class="pill">Edad: <span id="ageLabel">—</span></span>
          <a class="btn" href="index.php" style="text-decoration:none;">Volver</a>
        </div>
      </div>

      <?php if ($errors): ?>
        <div class="msg err">
          <?php foreach ($errors as $e): ?>
            <div>• <?= h($e) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" style="margin-top:10px;">
        <div class="grid">
          <div>
            <label>Nombre</label>
            <input name="first_name" value="<?= h($first_name) ?>" required>
          </div>

          <div>
            <label>Apellido</label>
            <input name="last_name" value="<?= h($last_name) ?>" required>
          </div>

          <div>
            <label>Cédula</label>
            <input name="cedula" value="<?= h($cedula) ?>">
          </div>

          <div>
            <label>Teléfono</label>
            <input name="phone" value="<?= h($phone) ?>">
          </div>

          <div>
            <label>Correo</label>
            <input type="email" name="email" value="<?= h($email) ?>">
          </div>

          <div>
            <label>Fecha de nacimiento</label>
            <input type="date" name="birth_date" id="birth_date" value="<?= h($birth_date) ?>">
          </div>

          <div>
            <label>Género</label>
            <select name="gender">
              <option value="">—</option>
              <option value="Masculino" <?= ($gender==="Masculino"?"selected":"") ?>>Masculino</option>
              <option value="Femenino" <?= ($gender==="Femenino"?"selected":"") ?>>Femenino</option>
              <option value="Otro" <?= ($gender==="Otro"?"selected":"") ?>>Otro</option>
            </select>
          </div>

          <div>
            <label>Tipo de sangre</label>
            <select name="blood_type">
              <option value="">—</option>
              <?php foreach (["A+","A-","B+","B-","AB+","AB-","O+","O-"] as $bt): ?>
                <option value="<?= $bt ?>" <?= ($blood_type===$bt?"selected":"") ?>><?= $bt ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div style="grid-column:1 / -1;">
            <label>Dirección</label>
            <input name="address" value="<?= h($address) ?>">
          </div>

          <div style="grid-column:1 / -1;">
            <label>Notas</label>
            <textarea name="notes"><?= h($notes) ?></textarea>
          </div>
        </div>

        <div class="rowActions">
          <button class="btn primary" type="submit">Guardar cambios</button>
          <a class="btn" href="index.php" style="text-decoration:none;">Cancelar</a>
        </div>
      </form>
    </section>

  </main>
</div>

<footer class="footer">
  <div class="footer-inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

<script>
  function calcAge(dateStr){
    if(!dateStr) return null;
    const dob = new Date(dateStr + "T00:00:00");
    if (isNaN(dob.getTime())) return null;
    const today = new Date();
    let age = today.getFullYear() - dob.getFullYear();
    const m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
    return age >= 0 ? age : null;
  }

  const bd = document.getElementById("birth_date");
  const label = document.getElementById("ageLabel");

  function updateAge(){
    if(!bd || !label) return;
    const a = calcAge(bd.value);
    label.textContent = (a === null ? "—" : String(a));
  }

  if (bd) {
    bd.addEventListener("change", updateAge);
    bd.addEventListener("input", updateAge);
    updateAge();
  }
</script>

</body>
</html>
