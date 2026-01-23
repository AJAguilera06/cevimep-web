<?php
declare(strict_types=1);

// ==========================
//  CEVIMEP - LOGIN (PUBLIC)
// ==========================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Si ya está logueado, directo al dashboard */
if (isset($_SESSION['user'])) {
    header("Location: /private/dashboard.php");
    exit;
}

/* ===============================
   DB robusta (funciona en Railway)
   =============================== */
$db_candidates = [
  __DIR__ . "/config/db.php",
  __DIR__ . "/db.php",
  __DIR__ . "/private/config/db.php",
  __DIR__ . "/private/db.php",
  __DIR__ . "/config/database.php",
  __DIR__ . "/private/config/database.php",
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
  die("Error crítico: no se pudo cargar la conexión a la base de datos.");
}

// helper
if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

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

            // ✅ evita problemas de sesión/cookies y mejora seguridad
            session_regenerate_id(true);

            // ✅ IMPORTANTÍSIMO: dashboard.php espera $_SESSION["user"]
            $_SESSION["user"] = [
                "id"        => (int)$user["id"],
                "email"     => (string)$user["email"],
                "full_name" => (string)($user["full_name"] ?? "Usuario"),
                "role"      => (string)($user["role"] ?? ""),
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

    <!-- tu css de login -->
    <link rel="stylesheet" href="/assets/css/auth.css?v=4">
</head>

<body class="auth-ui">

<div class="auth-page">
    <div class="auth-card">

        <div class="auth-brand">
            <img src="/assets/img/logo.png" alt="CEVIMEP" class="auth-logo">
            <h1>CEVIMEP</h1>
            <p>Inicia sesión para continuar</p>
        </div>

        <?php if ($error): ?>
            <div class="auth-alert error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="auth-form" autocomplete="off">
            <label>
                <span>Correo</span>
                <input type="email" name="email" placeholder="correo@ejemplo.com" required>
            </label>

            <label>
                <span>Contraseña</span>
                <input type="password" name="password" placeholder="••••••••" required>
            </label>

            <button type="submit" class="auth-btn">Entrar</button>
        </form>

    </div>
</div>

</body>
</html>
