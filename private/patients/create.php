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

/* ===============================
   DB (ruta robusta)
   =============================== */
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

/* ===============================
   Sucursal (admin puede elegir)
   =============================== */
$branch_id = $userBranchId;
$branches = [];

if ($isAdmin) {
  $branches = $pdo->query("SELECT id, name FROM branches ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
  if ($branch_id <= 0 && !empty($branches)) {
    $branch_id = (int)$branches[0]['id'];
  }
}

$error = "";

/* Defaults */
$no_libro = "";
$first_name = "";
$last_name = "";
$cedula = "";
$phone = "";
$email = "";
$birth_date = "";
$gender = "";
$blood_type = "";
$medico_refiere = "";
$clinica_referencia = "";
$ars = "";
$numero_afiliado = "";
$registrado_por = "";

/* ===============================
   POST (guardar)
   =============================== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $no_libro           = trim((string)($_POST['no_libro'] ?? ''));
  $first_name         = trim((string)($_POST['first_name'] ?? ''));
  $last_name          = trim((string)($_POST['last_name'] ?? ''));
  $cedula             = trim((string)($_POST['cedula'] ?? ''));
  $phone              = trim((string)($_POST['phone'] ?? ''));
  $email              = trim((string)($_POST['email'] ?? ''));
  $birth_date         = trim((string)($_POST['birth_date'] ?? ''));
  $gender             = trim((string)($_POST['gender'] ?? ''));
  $blood_type         = trim((string)($_POST['blood_type'] ?? ''));

  $medico_refiere     = trim((string)($_POST['medico_refiere'] ?? ''));
  $clinica_referencia = trim((string)($_POST['clinica_referencia'] ?? ''));
  $ars                = trim((string)($_POST['ars'] ?? ''));
  $numero_afiliado    = trim((string)($_POST['numero_afiliado'] ?? ''));
  $registrado_por     = trim((string)($_POST['registrado_por'] ?? ''));

  if ($isAdmin) {
    $branch_id = (int)($_POST['branch_id'] ?? $branch_id);
  } else {
    $branch_id = $userBranchId;
  }

  if ($branch_id <= 0) {
    $error = "Sucursal inválida.";
  } elseif ($no_libro === '') {
    $error = "El No. Libro es obligatorio.";
  } elseif ($first_name === '' || $last_name === '') {
    $error = "Nombre y apellido son obligatorios.";
  } else {
    try {
      $chk = $pdo->prepare("SELECT id FROM patients WHERE branch_id = :bid AND no_libro = :nl LIMIT 1");
      $chk->execute(['bid' => $branch_id, 'nl' => $no_libro]);
      if ($chk->fetchColumn()) {
        $error = "Ya existe un paciente con ese No. Libro en esta sucursal.";
      } else {
        $st = $pdo->prepare("
          INSERT INTO patients
            (no_libro, first_name, last_name, cedula, phone, email, birth_date, gender, blood_type, branch_id,
             medico_refiere, clinica_referencia, ars, numero_afiliado, registrado_por)
          VALUES
            (:nl, :fn, :ln, :ced, :ph, :em, :bd, :ge, :bt, :bid,
             :mr, :cr, :ars, :na, :rp)
        ");

        $st->execute([
          'nl'  => $no_libro,
          'fn'  => $first_name,
          'ln'  => $last_name,
          'ced' => $cedula !== '' ? $cedula : null,
          'ph'  => $phone !== '' ? $phone : null,
          'em'  => $email !== '' ? $email : null,
          'bd'  => $birth_date !== '' ? $birth_date : null,
          'ge'  => $gender !== '' ? $gender : null,
          'bt'  => $blood_type !== '' ? $blood_type : null,
          'bid' => $branch_id,
          'mr'  => $medico_refiere !== '' ? $medico_refiere : null,
          'cr'  => $clinica_referencia !== '' ? $clinica_referencia : null,
          'ars' => $ars !== '' ? $ars : null,
          'na'  => $numero_afiliado !== '' ? $numero_afiliado : null,
          'rp'  => $registrado_por !== '' ? $registrado_por : null,
        ]);

        header("Location: /private/patients/index.php");
        exit;
      }
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Registrar paciente</title>

  <link rel="stylesheet" href="/assets/css/styles.css?v=60">
  <link rel="stylesheet" href="/assets/css/paciente.css?v=2">

  <style>

    

    .patients-wrap{
      width:100%;
      max-width:none;
      margin:0;
      padding:26px 26px 22px;
    }

    .patients-top{
      display:flex;
      align-items:flex-end;
      justify-content:space-between;
      gap:18px;
      margin:6px 0 18px;
    }

    .patients-top h1{
      margin:0;
      font-size:44px;
      font-weight:900;
      letter-spacing:-.5px;
      line-height:1.05;
    }
    .patients-top p{
      margin:8px 0 0;
      opacity:.78;
      font-size:15px;
    }

    .btn-ghost{
      background:rgba(255,255,255,.75);
      border:1px solid rgba(0,0,0,.08);
      color:#1f2a37;
      backdrop-filter: blur(6px);
    }
    .btn-ghost:hover{background:#fff}

    .patients-surface{
      width:100%;
      background:rgba(255,255,255,.55);
      border:1px solid rgba(0,0,0,.06);
      border-radius:18px;
      box-shadow:0 18px 50px rgba(0,0,0,.10);
      padding:18px;
    }

    .section{
      background:rgba(255,255,255,.72);
      border:1px solid rgba(0,0,0,.06);
      border-radius:16px;
      padding:16px;
      margin-bottom:14px;
    }
    .section-title{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin:0 0 12px;
      font-weight:900;
      font-size:14px;
      letter-spacing:.4px;
      text-transform:uppercase;
      color:#0b2e59;
    }

    .grid{
      display:grid;
      grid-template-columns: repeat(4, minmax(220px, 1fr));
      gap:14px;
    }
    @media (max-width: 1200px){
      .grid{grid-template-columns: repeat(2, minmax(220px, 1fr));}
      .patients-top h1{font-size:38px;}
    }
    @media (max-width: 720px){
      .grid{grid-template-columns: 1fr;}
      .patients-top{align-items:flex-start; flex-direction:column;}
      .patients-top h1{font-size:34px;}
    }

    .span2{grid-column:1 / -1;}

    .field label{
      display:flex;
      align-items:baseline;
      justify-content:space-between;
      gap:10px;
      font-weight:800;
      font-size:13px;
      margin:0 0 6px;
      color:#0b2e59;
    }
    .muted{opacity:.65;font-weight:700;font-size:12px;}

    .input{
      width:100%;
      border:1px solid rgba(17,24,39,.25);
      border-radius:12px;
      padding:11px 12px;
      background:#fff;
      outline:none;
      transition: box-shadow .15s ease, border-color .15s ease, transform .08s ease;
    }
    .input:focus{
      border-color: rgba(3,105,161,.55);
      box-shadow: 0 0 0 4px rgba(3,105,161,.18);
    }

    .actions{
      display:flex;
      justify-content:center;
      gap:12px;
      flex-wrap:wrap;
      margin-top:10px;
      padding-top:10px;
    }

    .btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      padding:10px 16px;
      border-radius:12px;
      border:1px solid rgba(0,0,0,.10);
      text-decoration:none;
      cursor:pointer;
      font-weight:800;
      background:#fff;
    }
    .btn-primary{
      background: linear-gradient(180deg, #0b4aa0, #063b7b);
      color:#fff;
      border-color: rgba(255,255,255,.15);
      box-shadow: 0 14px 26px rgba(6,59,123,.25);
    }
    .btn-primary:hover{filter:brightness(1.05)}


</style>
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
    <div class="patients-wrap">

      <div class="patients-top">
        <div>
          <h1>Registrar nuevo paciente</h1>
          <p>Completa los datos y guarda.</p>
        </div>

        <a class="btn btn-ghost" href="/private/patients/index.php">← Volver</a>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
      <?php endif; ?>

      <div class="patients-surface">
        <form method="post" autocomplete="off">
          <?php if ($isAdmin): ?>
            <div class="section">
              <div class="section-title">Administración</div>
              <div class="grid">
              <div class="span2 field">
                <label for="branch_id">Sucursal</label>
                <select id="branch_id" name="branch_id" class="input" required>
                  <?php foreach ($branches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= ((int)$b['id'] === (int)$branch_id) ? 'selected' : '' ?>>
                      <?= h($b['name'] ?? '') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              </div>
            </div>
          <?php endif; ?>

          <div class="section">
            <div class="section-title">Datos del paciente</div>
            <div class="grid">
            <div class="field">
              <label for="no_libro">No. Libro <span class="muted">(obligatorio)</span></label>
              <input id="no_libro" name="no_libro" class="input" value="<?= h($no_libro) ?>" required>
            </div>

            <div class="field">
              <label for="cedula">Cédula</label>
              <input id="cedula" name="cedula" class="input" value="<?= h($cedula) ?>">
            </div>

            <div class="field">
              <label for="first_name">Nombre <span class="muted">(obligatorio)</span></label>
              <input id="first_name" name="first_name" class="input" value="<?= h($first_name) ?>" required>
            </div>

            <div class="field">
              <label for="last_name">Apellido <span class="muted">(obligatorio)</span></label>
              <input id="last_name" name="last_name" class="input" value="<?= h($last_name) ?>" required>
            </div>

            <div class="field">
              <label for="phone">Teléfono</label>
              <input id="phone" name="phone" class="input" value="<?= h($phone) ?>">
            </div>

            <div class="field">
              <label for="email">Correo</label>
              <input id="email" name="email" type="email" class="input" value="<?= h($email) ?>">
            </div>

            <div class="field">
              <label for="birth_date">Fecha de nacimiento</label>
              <input id="birth_date" name="birth_date" type="date" class="input" value="<?= h($birth_date) ?>">
            </div>

            <div class="field">
              <label for="gender">Género</label>
              <select id="gender" name="gender" class="input">
                <option value="">— Seleccionar —</option>
                <option value="M" <?= ($gender === 'M') ? 'selected' : '' ?>>Masculino</option>
                <option value="F" <?= ($gender === 'F') ? 'selected' : '' ?>>Femenino</option>
                <option value="O" <?= ($gender === 'O') ? 'selected' : '' ?>>Otro</option>
              </select>
            </div>

            <div class="field">
              <label for="blood_type">Tipo de sangre</label>
              <input id="blood_type" name="blood_type" class="input" value="<?= h($blood_type) ?>" placeholder="Ej: O+, A-">
            </div>

            <div class="field">
              <label for="ars">ARS</label>
              <input id="ars" name="ars" class="input" value="<?= h($ars) ?>">
            </div>

            <div class="field">
              <label for="numero_afiliado">Número de afiliado</label>
              <input id="numero_afiliado" name="numero_afiliado" class="input" value="<?= h($numero_afiliado) ?>">
            </div>

            <div class="span2 field">
              <label for="medico_refiere">Médico que refiere</label>
              <input id="medico_refiere" name="medico_refiere" class="input" value="<?= h($medico_refiere) ?>">
            </div>

            <div class="span2 field">
              <label for="clinica_referencia">Clínica de referencia</label>
              <input id="clinica_referencia" name="clinica_referencia" class="input" value="<?= h($clinica_referencia) ?>">
            </div>

            <div class="span2 field">
              <label for="registrado_por">Registrado por</label>
              <input id="registrado_por" name="registrado_por" class="input" value="<?= h($registrado_por) ?>">
            </div>
          </div>
          </div>

          <div class="actions">
            <button class="btn btn-primary" type="submit">Guardar paciente</button>
            <a class="btn" href="/private/patients/index.php">Cancelar</a>
          </div>
        </form>
      </div>

    </div>
  </main>
</div>

<footer class="footer">
  © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>
