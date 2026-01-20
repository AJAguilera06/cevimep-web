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

require_once __DIR__ . "/../../config/db.php";

if (empty($_SESSION["user"])) { header("Location: /login.php"); exit; }

$user = $_SESSION["user"];
$isAdmin = (($user["role"] ?? "") === "admin");
$userBranchId = (int)($user["branch_id"] ?? 0);
$year = (int)date("Y");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$error = "";

$first_name = "";
$last_name = "";
$cedula = "";
$phone = "";
$email = "";
$birth_date = "";
$gender = "";
$blood_type = "";
$branch_id = $userBranchId;

$branches = [];
if ($isAdmin) {
  $branches = $pdo->query("SELECT id, name FROM branches ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
  if ($branch_id === 0 && count($branches) > 0) $branch_id = (int)$branches[0]["id"];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $first_name = trim($_POST["first_name"] ?? "");
  $last_name  = trim($_POST["last_name"] ?? "");
  $cedula     = trim($_POST["cedula"] ?? "");
  $phone      = trim($_POST["phone"] ?? "");
  $email      = trim($_POST["email"] ?? "");
  $birth_date = trim($_POST["birth_date"] ?? "");
  $gender     = trim($_POST["gender"] ?? "");
  $blood_type = trim($_POST["blood_type"] ?? "");

  if ($isAdmin) {
    $branch_id = (int)($_POST["branch_id"] ?? 0);
  } else {
    $branch_id = $userBranchId;
  }

  try {
    if ($first_name === "" || $last_name === "") throw new Exception("Nombre y apellido son obligatorios.");
    if ($branch_id <= 0) throw new Exception("Sucursal inválida. (branch_id)");

    if ($birth_date !== "") {
      $dt = DateTime::createFromFormat("Y-m-d", $birth_date);
      if (!$dt) throw new Exception("Fecha de nacimiento inválida.");
      $birth_date = $dt->format("Y-m-d");
    } else {
      $birth_date = null;
    }

    $st = $pdo->prepare("
      INSERT INTO patients
        (first_name, last_name, cedula, phone, email, birth_date, gender, blood_type, branch_id)
      VALUES
        (:fn, :ln, :ced, :ph, :em, :bd, :ge, :bt, :bid)
    ");
    $st->execute([
      "fn"  => $first_name,
      "ln"  => $last_name,
      "ced" => $cedula !== "" ? $cedula : null,
      "ph"  => $phone !== "" ? $phone : null,
      "em"  => $email !== "" ? $email : null,
      "bd"  => $birth_date,
      "ge"  => $gender !== "" ? $gender : null,
      "bt"  => $blood_type !== "" ? $blood_type : null,
      "bid" => $branch_id
    ]);

    header("Location: index.php");
    exit;

  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Nuevo paciente</title>

  <!-- MISMO CSS Y MISMA VERSION QUE DASHBOARD -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=11">

  <style>
    /* Estilos del formulario (sin romper el layout global) */
    .card-form{
      background:#fff;
      border:1px solid #e6eef7;
      border-radius:22px;
      padding:18px;
      box-shadow:0 10px 30px rgba(2,6,23,.08);
      max-width:1200px;
      margin:0 auto;
    }
    .muted{color:#6b7280; font-weight:600;}
    .grid{display:grid; grid-template-columns:1fr 1fr; gap:12px;}
    @media(max-width:900px){ .grid{grid-template-columns:1fr;} }

    label{display:block; font-weight:900; margin:10px 0 6px; color:#0f172a;}
    .input, select, input[type="date"], input[type="email"]{
      width:100%;
      padding:11px 12px;
      border-radius:14px;
      border:1px solid #e6eef7;
      outline:none;
      font-size:14px;
      background:#fff;
    }
    .input:focus, select:focus, input[type="date"]:focus, input[type="email"]:focus{
      border-color:#93c5fd;
      box-shadow:0 0 0 4px rgba(59,130,246,.12);
    }

    .rowActions{display:flex; gap:10px; flex-wrap:wrap; margin-top:14px;}
    .btn.primary{
      border:none;
      color:#fff;
      background:linear-gradient(135deg,#0b4be3,#052a7a);
    }

    .msg{
      margin:12px 0 0;
      padding:10px 12px;
      border-radius:14px;
      font-weight:900;
      font-size:13px;
    }
    .msg.err{background:#ffe8e8;border:1px solid #ffb2b2;color:#7a1010;}

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
      <h1>Nuevo paciente</h1>
      <p>Completa los datos y guarda.</p>
    </section>

    <section class="card-form">
      <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
        <div>
          <h2 style="margin:0; color:#052a7a;">+ Nuevo paciente</h2>
          <p class="muted" style="margin:6px 0 0;">Completa los datos y guarda.</p>
        </div>

        <div style="display:flex; gap:10px; align-items:center;">
          <span class="pill" id="agePill">Edad: —</span>
          <a class="btn" href="index.php" style="text-decoration:none;">Volver</a>
        </div>
      </div>

      <?php if($error): ?>
        <div class="msg err"><?php echo h($error); ?></div>
      <?php endif; ?>

      <form method="POST" action="store.php">

  <div class="form-grid">

    <div class="form-group">
      <label>No. Libro</label>
      <input type="text" name="no_libro" class="form-control">
    </div>

    <div class="form-group">
      <label>Nombre</label>
      <input type="text" name="first_name" class="form-control" required>
    </div>

    <div class="form-group">
      <label>Apellido</label>
      <input type="text" name="last_name" class="form-control" required>
    </div>

    <div class="form-group">
      <label>Cédula</label>
      <input type="text" name="cedula" class="form-control">
    </div>

    <div class="form-group">
      <label>Teléfono</label>
      <input type="text" name="phone" class="form-control">
    </div>

    <div class="form-group">
      <label>Correo</label>
      <input type="email" name="email" class="form-control">
    </div>

    <div class="form-group">
      <label>Fecha de nacimiento</label>
      <input type="date" name="birth_date" class="form-control">
    </div>

    <div class="form-group">
      <label>Género</label>
      <select name="gender" class="form-control">
        <option value="">—</option>
        <option value="M">Masculino</option>
        <option value="F">Femenino</option>
      </select>
    </div>

    <div class="form-group">
      <label>Tipo de sangre</label>
      <select name="blood_type" class="form-control">
        <option value="">—</option>
        <option value="O+">O+</option>
        <option value="O-">O-</option>
        <option value="A+">A+</option>
        <option value="A-">A-</option>
        <option value="B+">B+</option>
        <option value="B-">B-</option>
        <option value="AB+">AB+</option>
        <option value="AB-">AB-</option>
      </select>
    </div>

    <div class="form-group">
      <label>Médico que refiere</label>
      <input type="text" name="medico_refiere" class="form-control">
    </div>

    <div class="form-group">
      <label>Clínica de referencia</label>
      <input type="text" name="clinica_referencia" class="form-control">
    </div>

    <div class="form-group">
      <label>ARS</label>
      <input type="text" name="ars" class="form-control">
    </div>

    <div class="form-group">
      <label>Número de afiliado</label>
      <input type="text" name="numero_afiliado" class="form-control">
    </div>

    <div class="form-group">
      <label>Registrado por</label>
      <input type="text" name="registrado_por" class="form-control">
    </div>

  </div>

  <div class="form-actions">
    <button type="submit" class="btn-primary">Guardar</button>
    <a href="index.php" class="btn-secondary">Cancelar</a>
  </div>

</form>

    </section>

  </main>
</div>

<footer class="footer">
  <div class="footer-inner">© <?php echo $year; ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

<script>
  function calcAge(dateStr){
    if(!dateStr) return null;
    const dob = new Date(dateStr + "T00:00:00");
    if(isNaN(dob.getTime())) return null;
    const today = new Date();
    let age = today.getFullYear() - dob.getFullYear();
    const m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
    return age;
  }

  const birth = document.getElementById("birth_date");
  const pill = document.getElementById("agePill");

  function refreshAge(){
    if(!pill || !birth) return;
    const a = calcAge(birth.value);
    pill.textContent = "Edad: " + (a === null ? "—" : a);
  }

  if (birth) {
    birth.addEventListener("change", refreshAge);
    birth.addEventListener("input", refreshAge);
    refreshAge();
  }
</script>

</body>
</html>
