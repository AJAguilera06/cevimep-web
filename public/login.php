<?php
declare(strict_types=1);

/* ===============================
   CEVIMEP - Login Seguro
   Compatible Railway / XAMPP
   =============================== */

// Mostrar errores SOLO en desarrollo (puedes quitar luego)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Iniciar sesión SOLO si no está activa
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* ===============================
   Cargar conexión a la BD
   (autodetección de ruta)
   =============================== */
$db_candidates = [
    __DIR__ . '/../config/db.php', // recomendado
    __DIR__ . '/../db.php',        // si está en la raíz
    __DIR__ . '/db.php',           // fallback
];

$loaded = false;
foreach ($db_candidates as $path) {
    if (is_file($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded || !isset($pdo)) {
    http_response_code(500);
    echo "Error crítico: no se pudo cargar la conexión a la base de datos.";
    exit;
}

/* ===============================
   Si ya está logueado → dashboard
   =============================== */
if (!empty($_SESSION['user'])) {
    header("Location: /private/dashboard.php");
    exit;
}

$error = '';

/* ===============================
   Procesar login
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

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
                !password_verify($password, $user['password_hash'])
            ) {
                $error = 'Correo o contraseña incorrectos.';
            } else {
                // Guardar sesión
                $_SESSION['user'] = [
                    'id'        => (int)$user['id'],
                    'full_name' => $user['full_name'],
                    'email'     => $user['email'],
                    'role'      => $user['role'],
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

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>CEVIMEP | Iniciar sesión</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Assets correctos para Railway -->
    <link rel="stylesheet" href="/assets/css/styles.css?v=11">
</head>

<body class="auth-page">

<div class="auth-wrap">
    <div class="auth-card">

        <div class="auth-header">
            <img src="/assets/img/logo.png" alt="CEVIMEP" class="auth-logo">
            <h2>CEVIMEP</h2>
            <p>Centro de Vacunación y Medicina Preventiva</p>
        </div>

        <?php if ($error): ?>
            <div class="alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/login.php">
            <div class="field">
                <label>Correo</label>
                <input type="email" name="email" required>
            </div>

            <div class="field">
                <label>Contraseña</label>
                <input type="password" name="password" required>
            </div>

            <button type="submit" class="btn-primary-pill">Ingresar</button>
        </form>

        <div class="auth-footer">
            <small>© <?= date('Y') ?> CEVIMEP</small>
        </div>

    </div>
</div>

</body>
</html>
