<?php
require_once __DIR__ . '/../private/config/db.php';
require_once __DIR__ . '/../private/bootstrap.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Debe completar todos los campos.';
    } else {
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.password, u.role, u.branch_id, b.name AS branch_name
            FROM users u
            LEFT JOIN branches b ON b.id = u.branch_id
            WHERE u.email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            $error = 'Credenciales incorrectas.';
        } else {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['email']     = $user['email'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['branch_id'] = $user['branch_id'];
            $_SESSION['branch']    = $user['branch_name'];

            header('Location: /private/dashboard.php');
            exit;
        }
    }
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>CEVIMEP | Iniciar sesión</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSS del login -->
    <link rel="stylesheet" href="/assets/css/auth.css?v=1">
</head>

<body class="auth-ui">

    <!-- CONTENIDO PRINCIPAL -->
    <div class="auth-page">
        <div class="auth-card">

            <!-- LOGO -->
            <div class="auth-logo">
                <img src="/assets/img/CEVIMEP.png" alt="CEVIMEP">
            </div>

            <!-- TITULOS -->
            <h1 class="auth-title">CEVIMEP</h1>
            <p class="auth-subtitle">
                Centro de Vacunación Integral y Medicina Preventiva
            </p>

            <!-- ERROR -->
            <?php if ($error): ?>
                <div class="auth-alert error">
                    <?= h($error) ?>
                </div>
            <?php endif; ?>

            <!-- FORMULARIO -->
            <form method="post" action="/login.php" autocomplete="on">

                <div class="form-group">
                    <label for="email">Correo</label>
                    <input
                        id="email"
                        class="input"
                        type="email"
                        name="email"
                        value="<?= h($_POST['email'] ?? '') ?>"
                        placeholder="ej: usuario@cevimep.com"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <input
                        id="password"
                        class="input"
                        type="password"
                        name="password"
                        placeholder="••••••••"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-primary">
                    Ingresar
                </button>
            </form>

        </div>
    </div>

    <!-- FOOTER -->
    <div class="auth-footer">
        © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
    </div>

</body>
</html>
