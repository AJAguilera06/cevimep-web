<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================
   CONEXIÓN BD (RUTA REAL)
   ========================= */
require_once __DIR__ . '/../private/config/db.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    die('Error crítico: no se pudo cargar la conexión a la base de datos.');
}

/* Si ya hay sesión, ir al dashboard */
if (isset($_SESSION['user'])) {
    header("Location: /private/dashboard.php");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Debe completar todos los campos.';
    } else {

        $stmt = $pdo->prepare("
            SELECT id, full_name, email, password_hash, role, branch_id
            FROM users
            WHERE email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Credenciales incorrectas.';
        } else {

            session_regenerate_id(true);

            $_SESSION['user'] = [
                'id'        => (int)$user['id'],
                'full_name' => (string)$user['full_name'],
                'email'     => (string)$user['email'],
                'role'      => (string)$user['role'],
                'branch_id' => (int)$user['branch_id'],
            ];

            header("Location: /private/dashboard.php");
            exit;
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

    <!-- ✅ ESTILO OFICIAL AUTH -->
    <link rel="stylesheet" href="/assets/css/auth.css?v=1">
</head>

<body class="auth-ui">

<div class="auth-page">

    <div class="auth-card">

        <!-- Logo -->
        <div class="auth-logo">
            <img src="/assets/img/logo.png" alt="CEVIMEP">
        </div>

        <!-- Títulos -->
        <h1 class="auth-title">CEVIMEP</h1>
        <p class="auth-subtitle">Iniciar sesión</p>

        <?php if ($error): ?>
            <div class="auth-alert error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <!-- Formulario -->
        <form method="POST" autocomplete="off">

            <div class="form-group">
                <label for="email">Correo</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    class="input"
                    placeholder="correo@ejemplo.com"
                    required
                >
            </div>

            <div class="form-group">
                <label for="password">Contraseña</label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    class="input"
                    placeholder="••••••••"
                    required
                >
            </div>

            <button type="submit" class="btn btn-primary">
                Entrar
            </button>

        </form>

    </div>

</div>

<!-- Footer fijo -->
<div class="auth-footer">
    © <?= date('Y') ?> CEVIMEP. Todos los derechos reservados.
</div>

</body>
</html>
