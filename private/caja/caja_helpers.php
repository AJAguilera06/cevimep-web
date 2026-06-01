<?php
// private/caja/caja_helpers.php
declare(strict_types=1);

function caja_set_timezone(): void {
  date_default_timezone_set('America/Santo_Domingo');
}

function caja_require_branch_id(): int {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }

  if (isset($_SESSION['branch_id'])) return (int)$_SESSION['branch_id'];
  if (isset($_SESSION['user']['branch_id'])) return (int)$_SESSION['user']['branch_id'];
  return 0;
}

function caja_current_date(): string {
  caja_set_timezone();
  return date('Y-m-d');
}

/**
 * Caja 1: 07:00 AM - 12:59 PM
 * Caja 2: 01:00 PM - 11:59 PM
 */
function caja_get_turno(): array {
  caja_set_timezone();
  $hora = date('H:i:s');

  if ($hora >= '07:00:00' && $hora <= '12:59:59') {
    return [1, '07:00:00', '12:59:59'];
  }

  return [2, '13:00:00', '23:59:59'];
}

function caja_shift_times(int $cajaNum): array {
  if ($cajaNum === 1) return ['07:00:00', '12:59:59'];
  if ($cajaNum === 2) return ['13:00:00', '23:59:59'];
  return ['00:00:00', '00:00:00'];
}

function caja_column_exists(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$column]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return false;
  }
}

function caja_table_columns(PDO $pdo, string $table): array {
  try {
    $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(fn($r) => (string)$r['Field'], $rows);
  } catch (Throwable $e) {
    return [];
  }
}

function caja_get_sessions(PDO $pdo, int $branchId, string $fecha, int $cajaNum): array {
  if ($branchId <= 0) return [];

  $st = $pdo->prepare("\n    SELECT *\n    FROM caja_sesiones\n    WHERE branch_id = ?\n      AND fecha = ?\n      AND caja_num = ?\n    ORDER BY id ASC\n  ");
  $st->execute([$branchId, $fecha, $cajaNum]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function caja_get_latest_session(PDO $pdo, int $branchId, string $fecha, int $cajaNum): ?array {
  if ($branchId <= 0) return null;

  $st = $pdo->prepare("\n    SELECT *\n    FROM caja_sesiones\n    WHERE branch_id = ?\n      AND fecha = ?\n      AND caja_num = ?\n    ORDER BY id DESC\n    LIMIT 1\n  ");
  $st->execute([$branchId, $fecha, $cajaNum]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function caja_get_open_session(PDO $pdo, int $branchId, ?int $cajaNum = null, ?string $fecha = null): ?array {
  if ($branchId <= 0) return null;

  $fecha = $fecha ?: caja_current_date();

  // Si se pide una caja específica, buscar esa caja abierta.
  if ($cajaNum !== null) {
    $st = $pdo->prepare("\n      SELECT *\n      FROM caja_sesiones\n      WHERE branch_id = ?\n        AND fecha = ?\n        AND caja_num = ?\n        AND estado = 'abierta'\n      ORDER BY id DESC\n      LIMIT 1\n    ");
    $st->execute([$branchId, $fecha, $cajaNum]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }

  // Para facturas/desembolsos: primero intenta el turno actual.
  [$turnoActual] = caja_get_turno();
  $st = $pdo->prepare("\n    SELECT *\n    FROM caja_sesiones\n    WHERE branch_id = ?\n      AND fecha = ?\n      AND caja_num = ?\n      AND estado = 'abierta'\n    ORDER BY id DESC\n    LIMIT 1\n  ");
  $st->execute([$branchId, $fecha, $turnoActual]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if ($row) return $row;

  // Si el turno actual no está abierto, usa cualquier caja abierta del día.
  // Esto evita que una factura se quede fuera si el usuario abrió Caja 1 y todavía está trabajando ahí.
  $st2 = $pdo->prepare("\n    SELECT *\n    FROM caja_sesiones\n    WHERE branch_id = ?\n      AND fecha = ?\n      AND estado = 'abierta'\n    ORDER BY caja_num ASC, id DESC\n    LIMIT 1\n  ");
  $st2->execute([$branchId, $fecha]);
  $row2 = $st2->fetch(PDO::FETCH_ASSOC);
  return $row2 ?: null;
}

function caja_get_open_session_id(PDO $pdo, int $branchId, ?int $cajaNum = null, ?string $fecha = null): int {
  $row = caja_get_open_session($pdo, $branchId, $cajaNum, $fecha);
  return $row ? (int)$row['id'] : 0;
}

/**
 * Mantiene compatibilidad con los archivos existentes.
 * Ahora NO crea la sesión automáticamente: debe estar abierta desde Caja.
 */
function caja_get_or_create_session_id(PDO $pdo, int $branch_id): int {
  return caja_get_open_session_id($pdo, $branch_id);
}

function caja_abrir_sesion(PDO $pdo, int $branchId, int $cajaNum, float $montoInicial = 0, ?int $userId = null): int {
  if ($branchId <= 0) throw new RuntimeException('Sucursal inválida.');
  if (!in_array($cajaNum, [1, 2], true)) throw new RuntimeException('Caja inválida.');

  $fecha = caja_current_date();
  [$inicio, $fin] = caja_shift_times($cajaNum);

  $open = caja_get_open_session($pdo, $branchId, $cajaNum, $fecha);
  if ($open) return (int)$open['id'];

  $cols = caja_table_columns($pdo, 'caja_sesiones');

  $fields = ['branch_id', 'caja_num', 'fecha', 'hora_inicio', 'hora_fin', 'estado'];
  $values = [$branchId, $cajaNum, $fecha, $inicio, $fin, 'abierta'];

  if (in_array('monto_inicial', $cols, true)) { $fields[] = 'monto_inicial'; $values[] = $montoInicial; }
  if (in_array('abierta_por', $cols, true)) { $fields[] = 'abierta_por'; $values[] = $userId ?: null; }
  if (in_array('opened_by', $cols, true)) { $fields[] = 'opened_by'; $values[] = $userId ?: null; }
  if (in_array('abierta_at', $cols, true)) { $fields[] = 'abierta_at'; $values[] = date('Y-m-d H:i:s'); }
  if (in_array('opened_at', $cols, true)) { $fields[] = 'opened_at'; $values[] = date('Y-m-d H:i:s'); }

  $ph = implode(',', array_fill(0, count($fields), '?'));
  $sql = 'INSERT INTO caja_sesiones (`' . implode('`,`', $fields) . "`) VALUES ($ph)";
  $st = $pdo->prepare($sql);
  $st->execute($values);

  return (int)$pdo->lastInsertId();
}

function caja_total_efectivo_sesion(PDO $pdo, int $sessionId): float {
  if ($sessionId <= 0) return 0.0;

  $st = $pdo->prepare("\n    SELECT COALESCE(SUM(CASE\n      WHEN type='ingreso' AND metodo_pago='efectivo' THEN amount\n      WHEN type='desembolso' THEN amount\n      ELSE 0 END), 0)\n    FROM cash_movements\n    WHERE caja_sesion_id = ?\n  ");
  $st->execute([$sessionId]);
  return (float)$st->fetchColumn();
}

function caja_cerrar_sesion(PDO $pdo, int $branchId, int $cajaNum, float $montoFinal = 0, ?int $userId = null): void {
  if ($branchId <= 0) throw new RuntimeException('Sucursal inválida.');
  if (!in_array($cajaNum, [1, 2], true)) throw new RuntimeException('Caja inválida.');

  $fecha = caja_current_date();
  $session = caja_get_open_session($pdo, $branchId, $cajaNum, $fecha);
  if (!$session) throw new RuntimeException('Esa caja no está abierta.');

  $sessionId = (int)$session['id'];
  $cols = caja_table_columns($pdo, 'caja_sesiones');

  $montoInicial = isset($session['monto_inicial']) ? (float)$session['monto_inicial'] : 0.0;
  $efectivoSistema = caja_total_efectivo_sesion($pdo, $sessionId);
  $esperado = $montoInicial + $efectivoSistema;
  $diferencia = $montoFinal - $esperado;

  $sets = ['estado = ?'];
  $vals = ['cerrada'];

  if (in_array('monto_final', $cols, true)) { $sets[] = 'monto_final = ?'; $vals[] = $montoFinal; }
  if (in_array('efectivo_sistema', $cols, true)) { $sets[] = 'efectivo_sistema = ?'; $vals[] = $efectivoSistema; }
  if (in_array('efectivo_esperado', $cols, true)) { $sets[] = 'efectivo_esperado = ?'; $vals[] = $esperado; }
  if (in_array('diferencia', $cols, true)) { $sets[] = 'diferencia = ?'; $vals[] = $diferencia; }
  if (in_array('cerrada_por', $cols, true)) { $sets[] = 'cerrada_por = ?'; $vals[] = $userId ?: null; }
  if (in_array('closed_by', $cols, true)) { $sets[] = 'closed_by = ?'; $vals[] = $userId ?: null; }
  if (in_array('cerrada_at', $cols, true)) { $sets[] = 'cerrada_at = ?'; $vals[] = date('Y-m-d H:i:s'); }
  if (in_array('closed_at', $cols, true)) { $sets[] = 'closed_at = ?'; $vals[] = date('Y-m-d H:i:s'); }

  $vals[] = $sessionId;
  $sql = 'UPDATE caja_sesiones SET ' . implode(', ', $sets) . ' WHERE id = ? LIMIT 1';
  $st = $pdo->prepare($sql);
  $st->execute($vals);
}
