<?php
// ==========================
//  CEVIMEP - LOGIN (PUBLIC)
// ==========================

require_once __DIR__ . '/../private/config/db.php';

// Bootstrap opcional (si existe)
$bootstrapPath = __DIR__ . '/../private/bootstrap.php';
if (file_exists($bootstrapPath)) {
    require_once $bootstrapPath;
} else {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// helper
if (!function_exists('h')) {
    function h($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Debe completar todos los campos.';
    } else {

        // ✅ Tu columna real es password_hash
        $stmt = $pdo->prepare("
            SELECT u.id,
                   u.full_name,
                   u.email,
                   u.password_hash,
                   u.role,
                   u.branch_id,
                   b.name AS branch_name
            FROM users u
            LEFT JOIN branches b ON b.id = u.branch_id
            WHERE u.email = ?
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            $error = 'Credenciales incorrectas.';
        } else {
            // ✅ IMPORTANTÍSIMO: dashboard.php espera $_SESSION["user"]
            $_SESSION["user"] = [
                "id"        => (int)$user["id"],
                "email"     => $user["email"],
                "full_name" => $user["full_name"] ?? "Usuario",
                "role"      => $user["role"] ?? "",
                "branch_id" => (int)($user["branch_id"] ?? 0),
            ];

            // (Opcional) si en otros módulos usas esto
            $_SESSION["branch"] = $user["branch_name"] ?? null;

            header("Location: /private/dashboard.php");
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>CEVIMEP | Iniciar sesión</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/auth.css?v=4">
</head>

<body class="auth-ui">

    <div class="auth-page">
        <div class="auth-card">

            <div class="auth-logo">
                <img src="/assets/img/CEVIMEP.png" alt="CEVIMEP">
            </div>

            <h1 class="auth-title">CEVIMEP</h1>
            <p class="auth-subtitle">Amamos la prevención, cuidamos tu salud.</p>

            <?php if ($error): ?>
                <div class="auth-alert error"><?= h($error) ?></div>
            <?php endif; ?>

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

                <button type="submit" class="btn btn-primary">Ingresar</button>
            </form>

        </div>
    </div>

    <div class="auth-footer">
        © <?= (int)date('Y') ?> CEVIMEP — Todos los derechos reservados.
    </div>

</body>
</html>
