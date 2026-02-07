<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/_guard.php'; // valida sesión y carga $pdo (PDO)

$rows = [];
$error = '';

try {
    // Si no tienes tabla usuarios o el campo nombre varía, puedes quitar el LEFT JOIN.
    $stmt = $pdo->query("
        SELECT d.id, d.fecha, d.monto, d.descripcion,
               COALESCE(u.nombre, u.name, '') AS usuario
        FROM caja_desembolsos d
        LEFT JOIN usuarios u ON d.usuario_id = u.id
        ORDER BY d.fecha DESC, d.id DESC
    ");
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    // Fallback: sin join por si tu BD no tiene tabla usuarios o campos distintos
    try {
        $stmt = $pdo->query("
            SELECT id, fecha, monto, descripcion, usuario_id
            FROM caja_desembolsos
            ORDER BY fecha DESC, id DESC
        ");
        $rows = $stmt->fetchAll();
    } catch (Throwable $e2) {
        $error = "❌ No se pudo cargar el historial (revisa la tabla/campos en BD).";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Historial de Desembolsos</title>

  <link rel="stylesheet" href="/assets/css/styles.css">
  <link rel="stylesheet" href="/assets/css/items.css">
</head>
<body>

<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="container">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>

  <main class="content">
    <div class="header-flex" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
      <h1 style="margin:0;">📄 Historial de Desembolsos</h1>
      <a href="/private/caja/desembolso.php" class="btn-secondary">➕ Nuevo Desembolso</a>
    </div>

    <?php if ($error): ?>
      <div class="alert error" style="margin-top:14px;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div style="margin-top:14px; overflow:auto;">
      <table class="table">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Monto</th>
            <th>Descripción</th>
            <th>Usuario</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($rows)): ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= htmlspecialchars((string)($r['fecha'] ?? '')) ?></td>
                <td>RD$ <?= number_format((float)($r['monto'] ?? 0), 2) ?></td>
                <td><?= htmlspecialchars((string)($r['descripcion'] ?? '')) ?></td>
                <td>
                  <?php
                    $u = $r['usuario'] ?? '';
                    if ($u !== '') echo htmlspecialchars((string)$u);
                    else echo htmlspecialchars((string)($r['usuario_id'] ?? '—'));
                  ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="4" style="text-align:center;">No hay desembolsos registrados</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>

</body>
</html>
