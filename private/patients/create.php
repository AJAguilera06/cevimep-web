<?php
declare(strict_types=1);

/* ===============================
   Sesión (sin warnings)
   =============================== */
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
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

/* ===============================
   Defaults
   =============================== */
$no_libro = "";
$first_name = "";
$last_name = "";
$cedula = "";
$phone = "";
$email = "";
$birth_date = "";
$gender = "";
$blood_type = "";

/* Campos nuevos */
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
      // evitar duplicados de No. Libro en la MISMA sucursal
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
  <link rel="stylesheet" href="/assets/css/paciente.css?v=1">
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
    <div class="module-head">
      <div class="module-head-left">
        <h1 class="module-title">Registrar nuevo paciente</h1>
        <p class="module-subtitle">Completa los datos y guarda.</p>
      </div>
      <div class="module-head-right">
        <a class="btn" href="/private/patients/index.php">← Volver</a>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="card-form">
      <form method="post" autocomplete="off">
        <?php if ($isAdmin): ?>
          <div class="grid">
            <div>
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
        <?php endif; ?>

        <div class="grid">
          <div>
            <label for="no_libro">No. Libro <span class="muted">(obligatorio)</span></label>
            <input id="no_libro" name="no_libro" class="input" value="<?= h($no_libro) ?>" required>
          </div>

          <div>
            <label for="cedula">Cédula</label>
            <input id="cedula" name="cedula" class="input" value="<?= h($cedula) ?>">
          </div>

          <div>
            <label for="first_name">Nombre <span class="muted">(obligatorio)</span></label>
            <input id="first_name" name="first_name" class="input" value="<?= h($first_name) ?>" required>
          </div>

          <div>
            <label for="last_name">Apellido <span class="muted">(obligatorio)</span></label>
            <input id="last_name" name="last_name" class="input" value="<?= h($last_name) ?>" required>
          </div>

          <div>
            <label for="phone">Teléfono</label>
            <input id="phone" name="phone" class="input" value="<?= h($phone) ?>">
          </div>

          <div>
            <label for="email">Correo</label>
            <input id="email" name="email" type="email" class="input" value="<?= h($email) ?>">
          </div>

          <div>
            <label for="birth_date">Fecha de nacimiento</label>
            <input id="birth_date" name="birth_date" type="date" class="input" value="<?= h($birth_date) ?>">
          </div>

          <div>
            <label for="gender">Género</label>
            <select id="gender" name="gender" class="input">
              <option value="">— Seleccionar —</option>
              <option value="M" <?= ($gender === 'M') ? 'selected' : '' ?>>Masculino</option>
              <option value="F" <?= ($gender === 'F') ? 'selected' : '' ?>>Femenino</option>
              <option value="O" <?= ($gender === 'O') ? 'selected' : '' ?>>Otro</option>
            </select>
          </div>

          <div>
            <label for="blood_type">Tipo de sangre</label>
            <input id="blood_type" name="blood_type" class="input" value="<?= h($blood_type) ?>" placeholder="Ej: O+, A-">
          </div>

          <div>
            <label for="ars">ARS</label>
            <input id="ars" name="ars" class="input" value="<?= h($ars) ?>">
          </div>

          <div>
            <label for="numero_afiliado">Número de afiliado</label>
            <input id="numero_afiliado" name="numero_afiliado" class="input" value="<?= h($numero_afiliado) ?>">
          </div>

          <div class="span2">
            <label for="medico_refiere">Médico que refiere</label>
            <input id="medico_refiere" name="medico_refiere" class="input" value="<?= h($medico_refiere) ?>">
          </div>

          <div class="span2">
            <label for="clinica_referencia">Clínica de referencia</label>
            <input id="clinica_referencia" name="clinica_referencia" class="input" value="<?= h($clinica_referencia) ?>">
          </div>

          <div class="span2">
            <label for="registrado_por">Registrado por</label>
            <input id="registrado_por" name="registrado_por" class="input" value="<?= h($registrado_por) ?>">
          </div>
        </div>

        <div class="actions">
          <button class="btn btn-primary" type="submit">Guardar paciente</button>
          <a class="btn" href="/private/patients/index.php">Cancelar</a>
        </div>
      </form>
    </div>
  </main>
</div>

<footer class="footer">
  © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>
