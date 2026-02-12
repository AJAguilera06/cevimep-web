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

/* Read patient */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header("Location: /private/patients/index.php"); exit; }

if ($isAdmin) {
  $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = :id");
  $stmt->execute(['id' => $id]);
} else {
  if ($userBranchId <= 0) { header("Location: /logout.php"); exit; }
  $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = :id AND branch_id = :bid");
  $stmt->execute(['id' => $id, 'bid' => $userBranchId]);
}

$p = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$p) { http_response_code(404); echo "Paciente no encontrado."; exit; }

$branch_id = (int)($p['branch_id'] ?? $userBranchId);
$branches = [];
if ($isAdmin) {
  $branches = $pdo->query("SELECT id, name FROM branches ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$error = "";

/* Defaults */
$no_libro = (string)($p['no_libro'] ?? '');
$first_name = (string)($p['first_name'] ?? '');
$last_name = (string)($p['last_name'] ?? '');
$cedula = (string)($p['cedula'] ?? '');
$phone = (string)($p['phone'] ?? '');
$email = (string)($p['email'] ?? '');
$birth_date = (string)($p['birth_date'] ?? '');
$gender = (string)($p['gender'] ?? '');
$blood_type = (string)($p['blood_type'] ?? '');
$medico_refiere = (string)($p['medico_refiere'] ?? '');
$clinica_referencia = (string)($p['clinica_referencia'] ?? '');
$ars = (string)($p['ars'] ?? '');
$numero_afiliado = (string)($p['numero_afiliado'] ?? '');
$registrado_por = (string)($p['registrado_por'] ?? '');

$fullName = trim($first_name . ' ' . $last_name);

/* POST */
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

  if ($isAdmin) { $branch_id = (int)($_POST['branch_id'] ?? $branch_id); }
  else { $branch_id = $userBranchId; }

  if ($branch_id <= 0) $error = "Sucursal inválida.";
  elseif ($no_libro === '') $error = "El No. Libro es obligatorio.";
  elseif ($first_name === '' || $last_name === '') $error = "Nombre y apellido son obligatorios.";
  else {
    try {
      $chk = $pdo->prepare("SELECT id FROM patients WHERE branch_id = :bid AND no_libro = :nl AND id <> :id LIMIT 1");
      $chk->execute(['bid' => $branch_id, 'nl' => $no_libro, 'id' => $id]);
      if ($chk->fetchColumn()) {
        $error = "Ya existe otro paciente con ese No. Libro en esta sucursal.";
      } else {
        $up = $pdo->prepare("
          UPDATE patients SET
            no_libro=:nl, first_name=:fn, last_name=:ln, cedula=:ced, phone=:ph, email=:em,
            birth_date=:bd, gender=:ge, blood_type=:bt, branch_id=:bid,
            medico_refiere=:mr, clinica_referencia=:cr, ars=:ars, numero_afiliado=:na, registrado_por=:rp
          WHERE id=:id
        ");

        $up->execute([
          'nl'=>$no_libro,'fn'=>$first_name,'ln'=>$last_name,
          'ced'=>($cedula!==''?$cedula:null),'ph'=>($phone!==''?$phone:null),'em'=>($email!==''?$email:null),
          'bd'=>($birth_date!==''?$birth_date:null),'ge'=>($gender!==''?$gender:null),'bt'=>($blood_type!==''?$blood_type:null),
          'bid'=>$branch_id,'mr'=>($medico_refiere!==''?$medico_refiere:null),'cr'=>($clinica_referencia!==''?$clinica_referencia:null),
          'ars'=>($ars!==''?$ars:null),'na'=>($numero_afiliado!==''?$numero_afiliado:null),'rp'=>($registrado_por!==''?$registrado_por:null),
          'id'=>$id
        ]);

        header("Location: /private/patients/view.php?id=".$id);
        exit;
      }
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  }
}

$fullName = trim($first_name . ' ' . $last_name);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Editar paciente</title>

  <link rel="stylesheet" href="/assets/css/styles.css?v=60">
  <link rel="stylesheet" href="/assets/css/paciente.css?v=2">

  <style>
    /* Layout tipo dashboard */
    .patients-page{max-width:1200px;margin:0 auto;padding:22px 18px 40px;}
    .page-title{text-align:center;margin:10px 0 16px;}
    .page-title h1{margin:0;font-size:40px;font-weight:900;}
    .page-title p{margin:6px 0 0;opacity:.75;font-weight:700;}

    /* Card igual al registrar */
    .form-shell{
      background:#fff;
      border-radius:18px;
      box-shadow:0 12px 28px rgba(0,0,0,.08);
      padding:18px 18px 22px;
    }

    .alert{max-width:1200px;margin:0 auto 14px;}

    /* Grid 4 columnas (como Create) */
    .grid{
      display:grid;
      grid-template-columns:repeat(4,minmax(0,1fr));
      gap:14px;
    }
    .col-2{grid-column:span 2;}
    .col-3{grid-column:span 3;}
    .col-4{grid-column:1 / -1;}

    .field label{
      display:block;
      text-align:center;
      font-weight:900;
      margin-bottom:6px;
    }
    .muted{font-weight:800;opacity:.65;font-size:.85em;}

    .input, select, input[type="date"], input[type="text"], input[type="email"], input[type="tel"]{
      width:100%;
      border:2px solid rgba(0,0,0,.65);
      border-radius:14px;
      padding:11px 14px;
      outline:none;
      background:#fff;
    }
    .input:focus, select:focus, input[type="date"]:focus{
      border-color:#0f4fa8;
      box-shadow:0 0 0 4px rgba(15,79,168,.10);
    }

    .actions{
      display:flex;
      justify-content:center;
      gap:14px;
      margin-top:16px;
      flex-wrap:wrap;
    }

    /* Responsive */
    @media (max-width: 1100px){
      .grid{grid-template-columns:repeat(2,minmax(0,1fr));}
      .col-3{grid-column:span 2;}
    }
    @media (max-width: 720px){
      .grid{grid-template-columns:1fr;}
      .col-2,.col-3,.col-4{grid-column:1 / -1;}
    }
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
    <div class="patients-page">

      <div class="page-title">
        <h1>Editar paciente</h1>
        <p><?= h($fullName) ?></p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
      <?php endif; ?>

      <div class="form-shell">
        <form method="post" autocomplete="off">

          <?php if ($isAdmin): ?>
            <div class="grid" style="margin-bottom:14px;">
              <div class="field col-4">
                <label for="branch_id">Sucursal</label>
                <select id="branch_id" name="branch_id" required>
                  <?php foreach ($branches as $b): ?>
                    <option value="<?= (int)$b['id'] ?>" <?= ((int)$b['id'] === (int)$branch_id) ? 'selected' : '' ?>>
                      <?= h($b['name'] ?? '') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          <?php endif; ?>

          <div class="grid">
            <div class="field col-1">
              <label for="no_libro">No. Libro <span class="muted">(obligatorio)</span></label>
              <input class="input" id="no_libro" name="no_libro" value="<?= h($no_libro) ?>" required>
            </div>

            <div class="field col-1">
              <label for="cedula">Cédula</label>
              <input class="input" id="cedula" name="cedula" value="<?= h($cedula) ?>">
            </div>

            <div class="field col-1">
              <label for="first_name">Nombre <span class="muted">(obligatorio)</span></label>
              <input class="input" id="first_name" name="first_name" value="<?= h($first_name) ?>" required>
            </div>

            <div class="field col-1">
              <label for="last_name">Apellido <span class="muted">(obligatorio)</span></label>
              <input class="input" id="last_name" name="last_name" value="<?= h($last_name) ?>" required>
            </div>

            <div class="field col-1">
              <label for="phone">Teléfono</label>
              <input class="input" id="phone" name="phone" value="<?= h($phone) ?>">
            </div>

            <div class="field col-1">
              <label for="email">Correo</label>
              <input class="input" id="email" name="email" type="email" value="<?= h($email) ?>">
            </div>

            <div class="field col-1">
              <label for="birth_date">Fecha de nacimiento</label>
              <input class="input" id="birth_date" name="birth_date" type="date" value="<?= h($birth_date) ?>">
            </div>

            <div class="field col-1">
              <label for="gender">Género</label>
              <select id="gender" name="gender">
                <option value="">— Seleccionar —</option>
                <option value="M" <?= ($gender === 'M' ? 'selected' : '') ?>>M</option>
                <option value="F" <?= ($gender === 'F' ? 'selected' : '') ?>>F</option>
                <option value="Otro" <?= ($gender === 'Otro' ? 'selected' : '') ?>>Otro</option>
              </select>
            </div>

            <div class="field col-1">
              <label for="blood_type">Tipo de sangre</label>
              <input class="input" id="blood_type" name="blood_type" placeholder="Ej: O+, A-" value="<?= h($blood_type) ?>">
            </div>

            <div class="field col-1">
              <label for="ars">ARS</label>
              <input class="input" id="ars" name="ars" value="<?= h($ars) ?>">
            </div>

            <div class="field col-1">
              <label for="numero_afiliado">Número de afiliado</label>
              <input class="input" id="numero_afiliado" name="numero_afiliado" value="<?= h($numero_afiliado) ?>">
            </div>

            <div class="field col-4">
              <label for="medico_refiere">Médico que refiere</label>
              <input class="input" id="medico_refiere" name="medico_refiere" value="<?= h($medico_refiere) ?>">
            </div>

            <div class="field col-4">
              <label for="clinica_referencia">Clínica de referencia</label>
              <input class="input" id="clinica_referencia" name="clinica_referencia" value="<?= h($clinica_referencia) ?>">
            </div>

            <div class="field col-4">
              <label for="registrado_por">Registrado por</label>
              <input class="input" id="registrado_por" name="registrado_por" value="<?= h($registrado_por) ?>">
            </div>
          </div>

          <div class="actions">
            <button class="btn btn-primary" type="submit">Guardar cambios</button>
            <a class="btn" href="/private/patients/view.php?id=<?= (int)$id ?>">Cancelar</a>
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
