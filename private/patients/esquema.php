<?php
/**
 * esquema.php
 * Página para registrar y visualizar el esquema de vacunas.
 *
 * Requisitos sugeridos (BD):
 *  Tabla: patient_vaccines
 *   - id INT AI PK
 *   - patient_id INT NULL
 *   - vacuna VARCHAR(150) NOT NULL
 *   - fecha_vacuna DATE NOT NULL
 *   - comentario TEXT NULL
 *   - created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
 *
 * Esta página intenta reutilizar una conexión existente ($conn mysqli o $pdo PDO) si tu proyecto ya la define
 * mediante includes. Si no existe, igual renderiza el UI.
 */

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

/* Intenta cargar inicializadores comunes (ajusta según tu proyecto) */
$possible_includes = [
  __DIR__ . '/../../config/db.php',
  __DIR__ . '/../config/db.php',
  __DIR__ . '/../../includes/db.php',
  __DIR__ . '/../includes/db.php',
  __DIR__ . '/../../includes/init.php',
  __DIR__ . '/../includes/init.php',
];
foreach ($possible_includes as $inc) {
  if (file_exists($inc)) {
    @require_once $inc;
  }
}

/* Helpers */
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : null;

$errors = [];
$flash_success = null;

/* Detecta tipo de conexión disponible */
$has_mysqli = isset($conn) && ($conn instanceof mysqli);
$has_pdo    = isset($pdo)  && ($pdo instanceof PDO);

/* Inserción */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_vaccine') {
  $vacuna       = trim($_POST['vacuna'] ?? '');
  $fecha_vacuna = trim($_POST['fecha_vacuna'] ?? '');
  $comentario   = trim($_POST['comentario'] ?? '');

  if ($vacuna === '') $errors[] = 'La vacuna es obligatoria.';
  if ($fecha_vacuna === '') $errors[] = 'La fecha de vacuna es obligatoria.';

  // Normaliza fecha (YYYY-MM-DD)
  if ($fecha_vacuna !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_vacuna)) {
    $errors[] = 'La fecha de vacuna debe tener formato YYYY-MM-DD.';
  }

  if (!$errors && ($has_mysqli || $has_pdo)) {
    try {
      if ($has_mysqli) {
        $stmt = $conn->prepare("INSERT INTO patient_vaccines (patient_id, vacuna, fecha_vacuna, comentario) VALUES (?,?,?,?)");
        $pid = $patient_id ?: null;
        $stmt->bind_param("isss", $pid, $vacuna, $fecha_vacuna, $comentario);
        $stmt->execute();
        $stmt->close();
      } else { // PDO
        $stmt = $pdo->prepare("INSERT INTO patient_vaccines (patient_id, vacuna, fecha_vacuna, comentario) VALUES (:patient_id,:vacuna,:fecha,:comentario)");
        $stmt->execute([
          ':patient_id' => $patient_id ?: null,
          ':vacuna' => $vacuna,
          ':fecha' => $fecha_vacuna,
          ':comentario' => $comentario,
        ]);
      }

      $flash_success = 'Vacuna registrada correctamente.';
      // Limpia POST para no re-enviar datos al refrescar
      $_POST = [];
    } catch (Throwable $e) {
      $errors[] = 'No se pudo registrar la vacuna. Revisa la tabla/BD. Detalle: ' . $e->getMessage();
    }
  } elseif (!$errors) {
    // Sin BD: aún mostramos éxito "solo UI" para no bloquear al usuario
    $flash_success = 'Vacuna registrada (modo UI). Conecta la BD para guardar permanentemente.';
    $_POST = [];
  }
}

/* Consulta para listado */
$rows = [];
if ($has_mysqli || $has_pdo) {
  try {
    if ($has_mysqli) {
      if ($patient_id) {
        $stmt = $conn->prepare("SELECT id, vacuna, fecha_vacuna, comentario FROM patient_vaccines WHERE patient_id = ? ORDER BY fecha_vacuna DESC, id DESC");
        $stmt->bind_param("i", $patient_id);
        $stmt->execute();
        $res = $stmt->get_result();
      } else {
        $res = $conn->query("SELECT id, vacuna, fecha_vacuna, comentario FROM patient_vaccines ORDER BY fecha_vacuna DESC, id DESC LIMIT 50");
      }
      if ($res) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
      }
      if (isset($stmt) && $stmt) $stmt->close();
    } else {
      if ($patient_id) {
        $stmt = $pdo->prepare("SELECT id, vacuna, fecha_vacuna, comentario FROM patient_vaccines WHERE patient_id = :pid ORDER BY fecha_vacuna DESC, id DESC");
        $stmt->execute([':pid' => $patient_id]);
      } else {
        $stmt = $pdo->query("SELECT id, vacuna, fecha_vacuna, comentario FROM patient_vaccines ORDER BY fecha_vacuna DESC, id DESC LIMIT 50");
      }
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
  } catch (Throwable $e) {
    // Si falla el select, solo no mostramos lista.
    $errors[] = 'No se pudo cargar el listado de vacunas. Detalle: ' . $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Esquema de Vacuna</title>
  <style>
    :root{
      --bg:#0b1220;
      --card:#0f1a2f;
      --muted:#94a3b8;
      --text:#e5e7eb;
      --border:rgba(148,163,184,.18);
      --btn:#2563eb;
      --btn2:#0ea5e9;
      --danger:#ef4444;
      --ok:#22c55e;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:linear-gradient(180deg,#070b14,var(--bg));color:var(--text)}
    .wrap{max-width:980px;margin:0 auto;padding:22px}
    .top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
    h1{margin:0;font-size:20px;letter-spacing:.2px}
    .btn{appearance:none;border:0;border-radius:10px;padding:10px 14px;color:white;background:var(--btn);cursor:pointer;font-weight:600}
    .btn:hover{filter:brightness(1.05)}
    .btn.secondary{background:transparent;border:1px solid var(--border);color:var(--text)}
    .card{margin-top:14px;background:rgba(15,26,47,.85);border:1px solid var(--border);border-radius:14px;padding:14px}
    .grid{display:grid;grid-template-columns:1fr 220px;gap:12px}
    @media (max-width:720px){.grid{grid-template-columns:1fr}}
    label{display:block;font-size:13px;color:var(--muted);margin:0 0 6px}
    input, textarea{
      width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--border);
      background:rgba(2,6,23,.55);color:var(--text);outline:none
    }
    textarea{min-height:92px;resize:vertical}
    .row{display:flex;gap:10px;align-items:center;justify-content:flex-end;margin-top:10px}
    .alert{padding:10px 12px;border-radius:12px;margin-top:12px;border:1px solid var(--border);background:rgba(2,6,23,.4)}
    .alert.ok{border-color:rgba(34,197,94,.35)}
    .alert.err{border-color:rgba(239,68,68,.35)}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th,td{padding:10px 10px;border-bottom:1px solid var(--border);vertical-align:top}
    th{color:var(--muted);font-weight:600;text-align:left;font-size:13px}
    td{font-size:14px}
    .muted{color:var(--muted);font-size:13px}
    .pill{display:inline-block;padding:3px 8px;border-radius:999px;border:1px solid var(--border);color:var(--muted);font-size:12px}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div>
        <h1>Esquema de Vacuna</h1>
        <?php if ($patient_id): ?>
          <div class="muted">Paciente ID: <span class="pill"><?php echo h($patient_id); ?></span></div>
        <?php endif; ?>
      </div>
      <button class="btn" type="button" id="toggleForm">Registrar nueva vacuna</button>
    </div>

    <?php if ($flash_success): ?>
      <div class="alert ok"><?php echo h($flash_success); ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert err">
        <div style="font-weight:700;margin-bottom:6px;color:#fecaca;">Revisa lo siguiente:</div>
        <ul style="margin:0;padding-left:18px;">
          <?php foreach ($errors as $e): ?>
            <li><?php echo h($e); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="card" id="formCard" style="display:none;">
      <form method="post" autocomplete="off">
        <input type="hidden" name="action" value="add_vaccine">
        <div class="grid">
          <div>
            <label for="vacuna">Vacuna</label>
            <input id="vacuna" name="vacuna" type="text" placeholder="Ej: Influenza, HPV, Neumococo..." value="<?php echo h($_POST['vacuna'] ?? ''); ?>" required>
          </div>
          <div>
            <label for="fecha_vacuna">Fecha de vacuna</label>
            <input id="fecha_vacuna" name="fecha_vacuna" type="date" value="<?php echo h($_POST['fecha_vacuna'] ?? ''); ?>" required>
          </div>
        </div>

        <div style="margin-top:12px;">
          <label for="comentario">Comentario</label>
          <textarea id="comentario" name="comentario" placeholder="Observaciones, lote, reacción, etc."><?php echo h($_POST['comentario'] ?? ''); ?></textarea>
        </div>

        <div class="row">
          <button class="btn secondary" type="button" id="cancelBtn">Cancelar</button>
          <button class="btn" type="submit">Guardar</button>
        </div>
        <?php if (!($has_mysqli || $has_pdo)): ?>
          <div class="muted" style="margin-top:10px;">
            Nota: no detecté conexión a BD en este archivo. El formulario funciona para UI, pero no guardará hasta conectar tu $conn (mysqli) o $pdo (PDO).
          </div>
        <?php endif; ?>
      </form>
    </div>

    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
        <div style="font-weight:700;">Vacunas registradas</div>
        <div class="muted"><?php echo ($has_mysqli || $has_pdo) ? 'Mostrando registros guardados.' : 'Mostrando sin BD (vacío).'; ?></div>
      </div>

      <?php if (!$rows): ?>
        <div class="muted" style="padding:12px 0;">No hay vacunas registradas.</div>
      <?php else: ?>
        <div style="overflow:auto;">
          <table>
            <thead>
              <tr>
                <th>Vacuna</th>
                <th>Fecha de vacuna</th>
                <th>Comentario</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?php echo h($r['vacuna'] ?? ''); ?></td>
                  <td><?php echo h($r['fecha_vacuna'] ?? ''); ?></td>
                  <td><?php echo nl2br(h($r['comentario'] ?? '')); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script>
    (function(){
      const btn = document.getElementById('toggleForm');
      const card = document.getElementById('formCard');
      const cancel = document.getElementById('cancelBtn');

      function openForm(){
        card.style.display = 'block';
        btn.textContent = 'Ocultar formulario';
        const v = document.getElementById('vacuna');
        if (v) setTimeout(()=>v.focus(), 60);
      }
      function closeForm(){
        card.style.display = 'none';
        btn.textContent = 'Registrar nueva vacuna';
      }

      btn.addEventListener('click', function(){
        if (card.style.display === 'none' || card.style.display === '') openForm();
        else closeForm();
      });
      cancel.addEventListener('click', function(){
        closeForm();
        // Limpia campos visualmente (sin tocar POST)
        const form = card.querySelector('form');
        if (form) form.reset();
      });

      // Si hubo errores, abre el formulario automáticamente
      const hasErrors = <?php echo $errors ? 'true' : 'false'; ?>;
      if (hasErrors) openForm();
    })();
  </script>
</body>
</html>
