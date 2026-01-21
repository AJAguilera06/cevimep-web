<?php
declare(strict_types=1);

/**
 * CEVIMEP - Login (estable para Railway/XAMPP + Front Controller)
 */

// DEBUG (quita cuando funcione)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Iniciar sesión SOLO si no está activa (evita doble session_start cuando index.php incluye)
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* ===============================
   Cargar conexión a la BD
   - Incluye tu caso real: private/config/db.php
   =============================== */
$db_candidates = [
    __DIR__ . '/../config/db.php',           // recomendado (si existe)
    __DIR__ . '/../db.php',                  // si existe en raíz
    __DIR__ . '/../private/config/db.php',   // TU CASO ACTUAL (importante)
    __DIR__ . '/db.php',                     // fallback
];

$loaded = false;
foreach ($db_candidates as $path) {
    if (is_file($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded || !isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo "Error crítico: no se pudo cargar la conexión a la base de datos.";
    exit;
}

// Si ya está logueado → dashboard
if (!empty($_SESSION['user'])) {
    header("Location: /private/dashboard.php");
    exit;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Completa correo y contraseña.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id, full_name, email, password_hash, role, branch_id, is_active
                FROM users
                WHERE email = :email
                LIMIT 1
            ");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                !$user ||
                (int)$user['is_active'] !== 1 ||
                !password_verify($password, (string)$user['password_hash'])
            ) {
                $error = 'Correo o contraseña incorrectos.';
            } else {
                $_SESSION['user'] = [
                    'id'        => (int)$user['id'],
                    'full_name' => (string)$user['full_name'],
                    'email'     => (string)$user['email'],
                    'role'      => (string)$user['role'],
                    'branch_id' => (int)$user['branch_id'],
                ];

                session_regenerate_id(true);

                header("Location: /private/dashboard.php");
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Error interno del sistema.';
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Iniciar sesión</title>

  <!-- Assets correctos en Railway (document root = public) -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=11">
</head>
<body class="auth-page">

  <div class="auth-wrap">
    <div class="auth-card">

      <div class="auth-header">
        <img src="/assets/img/logo.png" alt="CEVIMEP" class="auth-logo" onerror="this.style.display='none'">
        <h2>CEVIMEP</h2>
        <p>Centro de Vacunación y Medicina Preventiva</p>
      </div>

      <?php if ($error): ?>
        <div class="alert-danger"><?= h($error) ?></div>
      <?php endif; ?>

      <form method="post" action="/login.php" autocomplete="on">
        <div class="field">
          <label>Correo</label>
          <input class="input" type="email" name="email" required autocomplete="email">
        </div>

        <div class="field">
          <label>Contraseña</label>
          <input class="input" type="password" name="password" required autocomplete="current-password">
        </div>

        <div class="actions">
          <button type="submit" class="btn-primary-pill">Ingresar</button>
        </div>
      </form>

      <div class="auth-footer">
        <small>© <?= (int)date("Y") ?> CEVIMEP</small>
      </div>

    </div>
  </div>

</body>
</html>
