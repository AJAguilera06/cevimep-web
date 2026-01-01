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
    $age = $today->diff($dob)->y;
    return (string)$age;
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
  $last_name = trim($_POST["last_name"] ?? "");
  $cedula = trim($_POST["cedula"] ?? "");
  $phone = trim($_POST["phone"] ?? "");
  $email = trim($_POST["email"] ?? "");
  $birth_date = trim($_POST["birth_date"] ?? "");
  $gender = trim($_POST["gender"] ?? "");
  $blood_type = trim($_POST["blood_type"] ?? "");
  $address = trim($_POST["address"] ?? "");
  $notes = trim($_POST["notes"] ?? "");

  if ($first_name === "") $errors[] = "Nombre es obligatorio.";
  if ($last_name === "") $errors[] = "Apellido es obligatorio.";
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
      // Admin puede editar cualquier paciente: quitamos filtro de branch_id
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

$year = date("Y");
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Editar paciente</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <style>
    body{ overflow:auto; background:#f4f7fb; }
    .wrap{ max-width:900px; margin:auto; padding:22px; }
    .card{ background:#fff; border:1px solid #e6eef7; border-radius:22px; padding:16px; }
    .title{ margin:0; color:#052a7a; }
    .muted{ color:#6b7a90; }
    .grid{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .row{ display:flex; flex-direction:column; gap:6px; }
    input, select, textarea{ padding:12px; border:1px solid #dbe6f5; border-radius:14px; outline:none; }
    input:focus, select:focus, textarea:focus{ border-color:#b8cff3; }
    textarea{ min-height:90px; resize:vertical; }
    .btn{ display:inline-block; padding:10px 14px; border-radius:14px; background:#1c64f2; color:#fff; text-decoration:none; font-weight:700; border:none; cursor:pointer; }
    .btn2{ display:inline-block; padding:10px 14px; border-radius:14px; border:1px solid #dbe6f5; background:#fff; color:#052a7a; text-decoration:none; font-weight:700; }
    .err{ background:#ffe9e9; border:1px solid #ffb7b7; color:#7a1010; padding:10px 12px; border-radius:12px; margin-bottom:12px; }
    .agebox{ margin-top:8px; font-weight:700; color:#052a7a; }
    @media(max-width:900px){ .grid{ grid-template-columns:1fr; } }
  </style>
</head>
<body>
  <div class="wrap">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
      <div>
        <h2 class="title">Editar paciente</h2>
        <p class="muted"><?= htmlspecialchars($first_name." ".$last_name) ?></p>
      </div>
      <a class="btn2" href="index.php">← Volver</a>
    </div>

    <div class="card" style="margin-top:14px;">
      <?php if ($errors): ?>
        <div class="err">
          <?php foreach ($errors as $e): ?>
            <div>• <?= htmlspecialchars($e) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post">
        <div class="grid">
          <div class="row">
            <label>Nombre</label>
            <input name="first_name" value="<?= htmlspecialchars($first_name) ?>" required>
          </div>
          <div class="row">
            <label>Apellido</label>
            <input name="last_name" value="<?= htmlspecialchars($last_name) ?>" required>
          </div>

          <div class="row">
            <label>Cédula</label>
            <input name="cedula" value="<?= htmlspecialchars($cedula) ?>">
          </div>
          <div class="row">
            <label>Teléfono</label>
            <input name="phone" value="<?= htmlspecialchars($phone) ?>">
          </div>

          <div class="row">
            <label>Correo</label>
            <input type="email" name="email" value="<?= htmlspecialchars($email) ?>">
          </div>
          <div class="row">
            <label>Fecha de nacimiento</label>
            <input type="date" name="birth_date" id="birth_date" value="<?= htmlspecialchars($birth_date) ?>">
            <div class="agebox">Edad: <span id="ageLabel">—</span></div>
          </div>

          <div class="row">
            <label>Género</label>
            <select name="gender">
              <option value="">—</option>
              <option value="Masculino" <?= ($gender==="Masculino"?"selected":"") ?>>Masculino</option>
              <option value="Femenino" <?= ($gender==="Femenino"?"selected":"") ?>>Femenino</option>
              <option value="Otro" <?= ($gender==="Otro"?"selected":"") ?>>Otro</option>
            </select>
          </div>

          <div class="row">
            <label>Tipo de sangre</label>
            <select name="blood_type">
              <option value="">—</option>
              <?php foreach (["A+","A-","B+","B-","AB+","AB-","O+","O-"] as $bt): ?>
                <option value="<?= $bt ?>" <?= ($blood_type===$bt?"selected":"") ?>><?= $bt ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row" style="grid-column:1 / -1;">
            <label>Dirección</label>
            <input name="address" value="<?= htmlspecialchars($address) ?>">
          </div>

          <div class="row" style="grid-column:1 / -1;">
            <label>Notas</label>
            <textarea name="notes"><?= htmlspecialchars($notes) ?></textarea>
          </div>
        </div>

        <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn" type="submit">Guardar cambios</button>
          <a class="btn2" href="index.php">Cancelar</a>
        </div>
      </form>
    </div>

    <p class="muted">© <?= $year ?> CEVIMEP</p>
  </div>

  <script>
    function calcAge(dateStr){
      if(!dateStr) return "";
      const dob = new Date(dateStr);
      if (isNaN(dob.getTime())) return "";
      const today = new Date();
      let age = today.getFullYear() - dob.getFullYear();
      const m = today.getMonth() - dob.getMonth();
      if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
      return age >= 0 ? String(age) : "";
    }
    const bd = document.getElementById("birth_date");
    const label = document.getElementById("ageLabel");
    function updateAge(){
      const a = calcAge(bd.value);
      label.textContent = a !== "" ? a : "—";
    }
    bd.addEventListener("change", updateAge);
    bd.addEventListener("input", updateAge);
    updateAge();
  </script>
</body>
</html>
