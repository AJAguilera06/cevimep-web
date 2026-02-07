<?php
declare(strict_types=1);

require_once __DIR__ . '/../_guard.php';

$user = $_SESSION['user'] ?? [];
$hechoPor = $user['full_name'] ?? $user['nombre'] ?? $user['name'] ?? 'Usuario';

$rows = [];
$error = '';

try {
    // Intento con columnas nuevas
    $stmt = $pdo->query("
        SELECT d.id, d.fecha, d.hora, d.monto, d.motivo,
               COALESCE(u.nombre, u.full_name, u.name, '') AS usuario
        FROM caja_desembolsos d
        LEFT JOIN usuarios u ON d.usuario_id = u.id
        ORDER BY d.fecha DESC, d.id DESC
    ");
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    try {
        // Fallback a estructura vieja (descripcion)
        $stmt = $pdo->query("
            SELECT d.id, d.fecha, d.monto,
                   d.descripcion,
                   d.usuario_id
            FROM caja_desembolsos d
            ORDER BY d.fecha DESC, d.id DESC
        ");
        $rows = $stmt->fetchAll();
    } catch (Throwable $e2) {
        $error = "❌ No se pudo cargar el historial (revisa tabla/campos en BD).";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Caja | Historial de Desembolsos</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <link rel="stylesheet" href="/assets/css/styles.css?v=50">
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
            <a href="/private/patients/index.php">👤 Pacientes</a>
            <a href="/private/citas/index.php">📅 Citas</a>
            <a href="/private/facturacion/index.php">🧾 Facturación</a>
            <a class="active" href="/private/caja/index.php">💳 Caja</a>
            <a href="/private/inventario/index.php">📦 Inventario</a>
            <a href="/private/estadistica/index.php">📊 Estadísticas</a>
        </nav>
    </aside>

    <main class="content">

        <div class="welcome-center" style="text-align:left; max-width: 1100px;">
            <h1 style="margin-bottom:6px;">📄 Historial de Desembolsos</h1>
            <p style="margin-top:0;">
                <a href="/private/caja/desembolso.php">➕ Nuevo desembolso</a>
            </p>
        </div>

        <?php if ($error): ?>
            <div class="card" style="max-width:1100px; margin: 14px auto; padding: 14px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="card" style="max-width:1100px; margin: 0 auto; padding: 14px; overflow:auto;">
            <table style="width:100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left; padding:10px; border-bottom:1px solid #eee;">Fecha</th>
                        <th style="text-align:left; padding:10px; border-bottom:1px solid #eee;">Hora</th>
                        <th style="text-align:left; padding:10px; border-bottom:1px solid #eee;">Motivo</th>
                        <th style="text-align:right; padding:10px; border-bottom:1px solid #eee;">Monto</th>
                        <th style="text-align:left; padding:10px; border-bottom:1px solid #eee;">Hecho por</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($rows)): ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                          $fecha = (string)($r['fecha'] ?? '');
                          $hora  = (string)($r['hora'] ?? '');
                          $monto = (float)($r['monto'] ?? 0);

                          if (isset($r['motivo'])) {
                              $motivo = (string)($r['motivo'] ?? '');
                              $usuario = (string)($r['usuario'] ?? '');
                          } else {
                              // Fallback: extrae hora del texto "Hora: HH:MM | ..."
                              $desc = (string)($r['descripcion'] ?? '');
                              $motivo = $desc;
                              $hora = $hora ?: '';
                              if (preg_match('/Hora:\s*([0-9]{2}:[0-9]{2})\s*\|\s*(.*)$/u', $desc, $m)) {
                                  $hora = $m[1];
                                  $motivo = $m[2];
                              }
                              $usuario = (string)($r['usuario_id'] ?? '');
                          }
                        ?>
                        <tr>
                            <td style="padding:10px; border-bottom:1px solid #f3f3f3;"><?= htmlspecialchars($fecha) ?></td>
                            <td style="padding:10px; border-bottom:1px solid #f3f3f3;"><?= htmlspecialchars($hora) ?></td>
                            <td style="padding:10px; border-bottom:1px solid #f3f3f3;"><?= htmlspecialchars($motivo) ?></td>
                            <td style="padding:10px; border-bottom:1px solid #f3f3f3; text-align:right;">RD$ <?= number_format($monto, 2) ?></td>
                            <td style="padding:10px; border-bottom:1px solid #f3f3f3;"><?= htmlspecialchars($usuario ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="padding:14px; text-align:center;">No hay desembolsos registrados</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>
</div>

<footer class="footer">
    © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>
