<?php
declare(strict_types=1);

// Iniciar sesión SOLO si no está activa
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* ===============================
   Cargar conexión a la BD
   =============================== */
$db_candidates = [
    __DIR__ . '/../config/db.php',
    __DIR__ . '/../db.php',
    __DIR__ . '/../private/config/db.php',
    __DIR__ . '/db.php',
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

  <!-- subí el v para que no se quede cacheado -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=14">
</head>

<body class="cev-auth-body">

  <div class="cev-auth-bg"></div>

  <main class="cev-auth-wrap">
    <section class="cev-auth-card">

      <div class="cev-auth-header">
        <div class="cev-auth-logo">
          <img src="/assets/img/logo.png" alt="CEVIMEP" onerror="this.style.display='none'">
        </div>
        <h1 class="cev-auth-title">CEVIMEP</h1>
        <p class="cev-auth-subtitle">Centro de Vacunación y Medicina Preventiva</p>
      </div>

      <?php if ($error): ?>
        <div class="cev-alert cev-alert-danger"><?= h($error) ?></div>
      <?php endif; ?>

      <form class="cev-auth-form" method="post" action="/login.php" autocomplete="on">
        <div class="cev-field">
          <label class="cev-label">Correo</label>
          <input class="cev-input" type="email" name="email" required autocomplete="email" placeholder="ej: usuario@cevimep.com">
        </div>

        <div class="cev-field">
          <label class="cev-label">Contraseña</label>
          <input class="cev-input" type="password" name="password" required autocomplete="current-password" placeholder="••••••••">
        </div>

        <button class="cev-btn cev-btn-primary" type="submit">Ingresar</button>
      </form>

    </section>
  </main>

  <!-- Barra azul inferior fija -->
  <div class="cev-auth-bottom-bar">
    © <?= (int)date("Y") ?> CEVIMEP — Todos los derechos reservados.
  </div>

</body>
</html>
