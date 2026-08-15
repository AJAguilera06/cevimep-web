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

function normalize_date_to_mysql(string $date): string {
  $date = trim($date);

  if ($date === '') {
    return '';
  }

  // Si viene como DD/MM/AAAA, convertir a AAAA-MM-DD para MySQL
  if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $m)) {
    $day = (int)$m[1];
    $month = (int)$m[2];
    $year = (int)$m[3];

    if (checkdate($month, $day, $year)) {
      return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    return '';
  }

  // Si ya viene como AAAA-MM-DD, validar y dejar igual
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
    $year = (int)$m[1];
    $month = (int)$m[2];
    $day = (int)$m[3];

    if (checkdate($month, $day, $year)) {
      return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
  }

  return '';
}

function mysql_date_to_dmy(string $date): string {
  $date = trim($date);

  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
    return $m[3] . '/' . $m[2] . '/' . $m[1];
  }

  return $date;
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
$direccion = "";
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
  $direccion          = trim((string)($_POST['direccion'] ?? ''));
  $birth_date         = normalize_date_to_mysql((string)($_POST['birth_date'] ?? ''));
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
  } elseif ($first_name === '' || $last_name === '') {
    $error = "Nombre y apellido son obligatorios.";
  } elseif (trim((string)($_POST['birth_date'] ?? '')) !== '' && $birth_date === '') {
    $error = "Fecha de nacimiento inválida. Usa el formato DD/MM/AAAA.";
  } else {
    try {
      if ($no_libro !== '') {
        $chk = $pdo->prepare("SELECT id FROM patients WHERE branch_id = :bid AND no_libro = :nl LIMIT 1");
        $chk->execute(['bid' => $branch_id, 'nl' => $no_libro]);
        if ($chk->fetchColumn()) {
          $error = "Ya existe un paciente con ese No. Libro en esta sucursal.";
        }
      }

      if ($error === '') {
        $st = $pdo->prepare("
          INSERT INTO patients
            (no_libro, first_name, last_name, cedula, phone, direccion, birth_date, gender, blood_type, branch_id,
             medico_refiere, clinica_referencia, ars, numero_afiliado, registrado_por)
          VALUES
            (:nl, :fn, :ln, :ced, :ph, :dir, :bd, :ge, :bt, :bid,
             :mr, :cr, :ars, :na, :rp)
        ");

        $st->execute([
          'nl'  => $no_libro !== '' ? $no_libro : null,
          'fn'  => $first_name,
          'ln'  => $last_name,
          'ced' => $cedula !== '' ? $cedula : null,
          'ph'  => $phone !== '' ? $phone : null,
          'dir' => $direccion !== '' ? $direccion : null,
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

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <style>
    :root{
        --navy-deep:#071B32;
        --teal:#14B8A6;
        --teal-dark:#0D9488;
    }
    *{ box-sizing:border-box; }
    body{
        margin:0;
        font-family:'Inter',sans-serif;
        background:var(--navy-deep);
        color:#fff;
        min-height:100vh;
        display:flex;
        flex-direction:column;
    }
    .navbar{ background:rgba(255,255,255,.04); border-bottom:1px solid rgba(255,255,255,.08); }
    .navbar .inner{ display:grid; grid-template-columns:1fr auto 1fr; align-items:center; padding:16px 28px; }
    .navbar .brand{ grid-column:2; justify-self:center; }
    .navbar .nav-right{ grid-column:3; justify-self:end; }
    .brand{ display:flex; align-items:center; gap:10px; font-family:'Manrope'; font-weight:800; font-size:15px; letter-spacing:.04em; }
    .brand .dot{ width:9px;height:9px;border-radius:50%;background:var(--teal); box-shadow:0 0 0 4px rgba(20,184,166,.18); }
    .btn-pill{ background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.16); color:#fff; padding:9px 16px; border-radius:999px; text-decoration:none; font-weight:700; font-size:13px; }
    .btn-pill:hover{ background:rgba(255,255,255,.14); }

    .layout{ display:flex; width:100%; position:relative; flex:1; }
    .auth-bg{ position:fixed; inset:0; width:100%; height:100%; z-index:0; opacity:.55; pointer-events:none; }

    .sidebar{ position:relative; z-index:1; width:230px; flex-shrink:0; padding:24px 16px; }
    .menu-title{ font-weight:700; font-size:12px; letter-spacing:.08em; text-transform:uppercase; color:#7D95AC; margin:0 0 14px 6px; }
    .menu{ display:flex; flex-direction:column; gap:2px; }
    .menu a{ display:block; padding:10px 12px; border-radius:10px; text-decoration:none; font-size:14px; font-weight:600; color:#B9CBDD; }
    .menu a:hover{ background:rgba(255,255,255,.06); }
    .menu a.active{ background:rgba(20,184,166,.18); color:var(--teal); }

    .content{ position:relative; z-index:1; flex:1; padding:40px 40px 60px; width:100%; }
    .footer{ position:relative; z-index:1; text-align:center; padding:20px; font-size:12px; color:#5C7896; }

    .btn{ display:inline-block; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.16); color:#fff; padding:10px 18px; border-radius:999px; text-decoration:none; font-weight:700; font-size:14px; cursor:pointer; transition:background .15s; }
    .btn:hover{ background:rgba(255,255,255,.14); }
    .btn-primary{ background:var(--teal); border-color:var(--teal); color:#04211d; }
    .btn-primary:hover{ background:var(--teal-dark); color:#04211d; }

    .patients-wrap{max-width:1100px;margin:0 auto;}
    .patients-header{text-align:center;margin-top:0;margin-bottom:12px;}
    .patients-header h1{margin:0;font-family:'Manrope';font-size:34px;font-weight:900;letter-spacing:.2px;}
    .patients-header p{margin:8px 0 0;opacity:.78;font-weight:600;color:#9FB6CC;}
    .patients-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin:14px 0 24px;}

    .form-card{
        max-width:100%;
        margin:0 auto;
        background:rgba(255,255,255,.07);
        backdrop-filter:blur(14px);
        -webkit-backdrop-filter:blur(14px);
        border:1px solid rgba(255,255,255,.12);
        border-radius:16px;
        padding:22px 22px 18px;
    }
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px 14px;align-items:start;}
    .span2{grid-column:1 / -1;}
    .input{width:100%;}
    label{display:block;font-weight:700;margin-bottom:5px;font-size:13px;color:#B9CBDD;}
    .muted{font-weight:600;opacity:.65;font-size:.85em;color:#7D95AC;}
    .actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:18px;}

    .patients-wrap .input,
    .patients-wrap select,
    .patients-wrap textarea{
      width:100%;
      background:rgba(255,255,255,.06);
      border:1px solid rgba(255,255,255,.16);
      color:#fff;
      border-radius:12px;
      padding:9px 12px;
      min-height:42px;
      font:inherit;
      outline:none;
    }
    .patients-wrap .input::placeholder,
    .patients-wrap textarea::placeholder{ color:#7D95AC; }
    .patients-wrap .input:focus,
    .patients-wrap select:focus,
    .patients-wrap textarea:focus{ border-color:var(--teal); }
    .patients-wrap select option{ background:var(--navy-deep); color:#fff; }
    .patients-wrap textarea{min-height:86px;}

    .alert{max-width:100%;margin:0 auto 16px;padding:12px 14px;border-radius:12px;font-weight:700;}
    .alert-danger{background:rgba(220,38,38,.12);color:#f87171;border:1px solid rgba(220,38,38,.3);}

    @media (max-width: 980px){
      .layout{ flex-direction:column; }
      .sidebar{ width:100%; }
      .content{ padding:24px 18px 40px; }
    }
</style>
</head>
<body>

<svg class="auth-bg" viewBox="0 0 1400 800" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
    <circle cx="130" cy="80" r="240" fill="#0D3A5C" opacity=".5"/>
    <circle cx="1280" cy="650" r="280" fill="#0F4A63" opacity=".45"/>
    <g fill="none" stroke="#1D5C7A" stroke-width="1" opacity=".7">
        <line x1="60" y1="60" x2="180" y2="130"/><line x1="180" y1="130" x2="140" y2="250"/>
        <line x1="1220" y1="560" x2="1300" y2="620"/><line x1="1300" y1="620" x2="1250" y2="700"/>
    </g>
    <g fill="#14B8A6" opacity=".85">
        <circle cx="60" cy="60" r="3"/><circle cx="180" cy="130" r="4"/><circle cx="140" cy="250" r="3"/>
        <circle cx="1220" cy="560" r="3"/><circle cx="1300" cy="620" r="4"/><circle cx="1250" cy="700" r="3"/>
    </g>
</svg>

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
      <a href="#" onclick="return false;" style="opacity:.5;cursor:not-allowed;">
    📅 Citas (Próximamente)
</a>
      <a href="/private/facturacion/index.php">🧾 Facturación</a>
      <a href="/private/caja/index.php">💳 Caja</a>
      <a href="/private/inventario/index.php">📦 Inventario</a>
      <a href="/private/estadistica/index.php">📊 Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <div class="patients-wrap">

      <div class="patients-header">
        <h1>Registrar nuevo paciente</h1>
        <p>Completa los datos para agregarlo al listado de la sucursal.</p>
      </div>

      <div class="patients-actions">
        <a class="btn" href="/private/patients/index.php">← Volver</a>
      </div>

<?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
      <?php endif; ?>

      <div class="form-card">
        <form method="post" autocomplete="off">
          <?php if ($isAdmin): ?>
            <div class="grid" style="margin-bottom:12px;">
              <div class="span2">
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
              <label for="no_libro">No. Libro</label>
              <input id="no_libro" name="no_libro" class="input" value="<?= h($no_libro) ?>">
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
    <label for="direccion">Dirección</label>
    <input id="direccion" name="direccion" class="input" value="<?= h($direccion) ?>">
</div>

            <div>
              <label for="birth_date_view">Fecha de nacimiento</label>
              <input
                id="birth_date_view"
                type="text"
                class="input"
                placeholder="DD/MM/AAAA"
                maxlength="10"
                inputmode="numeric"
                value="<?= h(mysql_date_to_dmy($birth_date)) ?>"
              >
              <input id="birth_date" name="birth_date" type="hidden" value="<?= h($birth_date) ?>">
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

    </div>
  </main>
</div>

<footer class="footer">
  © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>


<script>
(function () {
  const view = document.getElementById('birth_date_view');
  const hidden = document.getElementById('birth_date');

  if (!view || !hidden) return;

  function formatDate() {
    let v = view.value.replace(/\D/g, '').slice(0, 8);

    if (v.length >= 5) {
      view.value = v.slice(0, 2) + '/' + v.slice(2, 4) + '/' + v.slice(4);
    } else if (v.length >= 3) {
      view.value = v.slice(0, 2) + '/' + v.slice(2);
    } else {
      view.value = v;
    }

    const parts = view.value.split('/');
    if (parts.length === 3 && parts[0].length === 2 && parts[1].length === 2 && parts[2].length === 4) {
      hidden.value = parts[2] + '-' + parts[1] + '-' + parts[0];
    } else {
      hidden.value = '';
    }
  }

  view.addEventListener('input', formatDate);

  const form = view.closest('form');
  if (form) {
    form.addEventListener('submit', formatDate);
  }
})();
</script>

</body>
</html>