<?php
// private/caja/caja_lib.php
declare(strict_types=1);

date_default_timezone_set("America/Santo_Domingo");

/**
 * Horarios oficiales (para mostrar en pantalla / reportes):
 * Caja 1: 08:00:00 -> 13:00:00
 * Caja 2: 13:00:00 -> 18:00:00
 *
 * Nota: para que NO se queden facturas fuera por pacientes que llegan temprano o tarde,
 * la asignación operativa de turnos se maneja con tolerancia:
 * - Caja 1 opera: 07:00:00 -> 13:00:00
 * - Caja 2 opera: 13:00:00 -> 19:00:00
 */

if (!function_exists("caja_get_current_caja_num")) {
  function caja_get_current_caja_num(): int {
    $now = date("H:i:s");
    if ($now >= "07:00:00" && $now < "13:00:00") return 1;
    if ($now >= "13:00:00" && $now < "19:00:00") return 2;
    return 0;
  }
}

if (!function_exists("caja_shift_times")) {
  function caja_shift_times(int $cajaNum): array {
    if ($cajaNum === 1) return ["07:00:00","13:00:00"];
    if ($cajaNum === 2) return ["13:00:00","19:00:00"];
    return ["00:00:00","00:00:00"];
  }
}

if (!function_exists("caja_current_shift_context")) {
  function caja_current_shift_context(): array {
    $now = date("H:i:s");
    $today = date("Y-m-d");

    if ($now >= "07:00:00" && $now < "13:00:00") {
      [$ss, $se] = caja_shift_times(1);
      return [
        "caja_num"    => 1,
        "date_open"   => $today,
        "shift_start" => $ss,
        "shift_end"   => $se,
        "in_shift"    => true
      ];
    }

    if ($now >= "13:00:00" && $now < "19:00:00") {
      [$ss, $se] = caja_shift_times(2);
      return [
        "caja_num"    => 2,
        "date_open"   => $today,
        "shift_start" => $ss,
        "shift_end"   => $se,
        "in_shift"    => true
      ];
    }

    return [
      "caja_num"    => 0,
      "date_open"   => $today,
      "shift_start" => "00:00:00",
      "shift_end"   => "00:00:00",
      "in_shift"    => false
    ];
  }
}

if (!function_exists("caja_get_or_create_session")) {
  function caja_get_or_create_session(PDO $conn, int $branch_id, int $caja_num, string $date_open, string $shift_start, string $shift_end): array {
    $st = $conn->prepare("
      SELECT *
      FROM cash_sessions
      WHERE branch_id = ?
        AND caja_num = ?
        AND date_open = ?
        AND shift_start = ?
        AND shift_end = ?
      LIMIT 1
    ");
    $st->execute([$branch_id, $caja_num, $date_open, $shift_start, $shift_end]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    $st = $conn->prepare("
      INSERT INTO cash_sessions (branch_id, caja_num, date_open, shift_start, shift_end, created_at)
      VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $st->execute([$branch_id, $caja_num, $date_open, $shift_start, $shift_end]);
    $id = (int)$conn->lastInsertId();

    $st = $conn->prepare("SELECT * FROM cash_sessions WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: ["id" => $id];
  }
}

if (!function_exists("caja_register_movement")) {
  function caja_register_movement(PDO $conn, int $session_id, int $branch_id, string $type, string $method, float $amount, string $note, ?int $ref_invoice_id = null, ?int $user_id = null): bool {
    $amount = (float)$amount;

    $st = $conn->prepare("
      INSERT INTO cash_movements
        (session_id, branch_id, type, method, amount, note, ref_invoice_id, user_id, created_at)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    return $st->execute([
      $session_id,
      $branch_id,
      $type,
      $method,
      $amount,
      $note,
      $ref_invoice_id,
      $user_id
    ]);
  }
}

if (!function_exists("caja_record_invoice_income")) {
  function caja_record_invoice_income(PDO $conn, int $branch_id, int $user_id, int $invoice_id, float $total, string $method): bool {
    $ctx = caja_current_shift_context();
    if (empty($ctx["in_shift"])) return false;

    $session = caja_get_or_create_session(
      $conn,
      $branch_id,
      (int)$ctx["caja_num"],
      (string)$ctx["date_open"],
      (string)$ctx["shift_start"],
      (string)$ctx["shift_end"]
    );

    $session_id = (int)($session["id"] ?? 0);
    if ($session_id <= 0) return false;

    $note = "Ingreso por Factura #{$invoice_id}";
    return caja_register_movement(
      $conn,
      $session_id,
      $branch_id,
      "IN",
      $method,
      (float)$total,
      $note,
      $invoice_id,
      $user_id
    );
  }
}

if (!function_exists("caja_record_disbursement")) {
  function caja_record_disbursement(PDO $conn, int $branch_id, int $user_id, float $amount, string $note = "Desembolso"): bool {
    $ctx = caja_current_shift_context();
    if (empty($ctx["in_shift"])) return false;

    $session = caja_get_or_create_session(
      $conn,
      $branch_id,
      (int)$ctx["caja_num"],
      (string)$ctx["date_open"],
      (string)$ctx["shift_start"],
      (string)$ctx["shift_end"]
    );

    $session_id = (int)($session["id"] ?? 0);
    if ($session_id <= 0) return false;

    // desembolso siempre efectivo por defecto (ajústalo si tú manejas egresos con otros métodos)
    return caja_register_movement(
      $conn,
      $session_id,
      $branch_id,
      "OUT",
      "EFECTIVO",
      (float)$amount,
      $note,
      null,
      $user_id
    );
  }
}

if (!function_exists("caja_sum_session")) {
  function caja_sum_session(PDO $conn, int $session_id): array {
    $st = $conn->prepare("
      SELECT
        SUM(CASE WHEN type='IN' AND method='EFECTIVO' THEN amount ELSE 0 END) AS efectivo,
        SUM(CASE WHEN type='IN' AND method='TARJETA' THEN amount ELSE 0 END) AS tarjeta,
        SUM(CASE WHEN type='IN' AND method='TRANSFERENCIA' THEN amount ELSE 0 END) AS transferencia,
        SUM(CASE WHEN type='IN' AND method='COBERTURA' THEN amount ELSE 0 END) AS cobertura,
        SUM(CASE WHEN type='OUT' THEN amount ELSE 0 END) AS desembolsos,
        SUM(CASE WHEN type='IN' THEN amount ELSE 0 END) AS total_in,
        (SUM(CASE WHEN type='IN' THEN amount ELSE 0 END) - SUM(CASE WHEN type='OUT' THEN amount ELSE 0 END)) AS neto
      FROM cash_movements
      WHERE session_id = ?
    ");
    $st->execute([$session_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
      "efectivo"      => (float)($row["efectivo"] ?? 0),
      "tarjeta"       => (float)($row["tarjeta"] ?? 0),
      "transferencia" => (float)($row["transferencia"] ?? 0),
      "cobertura"     => (float)($row["cobertura"] ?? 0),
      "desembolsos"   => (float)($row["desembolsos"] ?? 0),
      "total_in"      => (float)($row["total_in"] ?? 0),
      "neto"          => (float)($row["neto"] ?? 0),
    ];
  }
}

if (!function_exists("caja_daily_sessions_for_branch")) {
  function caja_daily_sessions_for_branch(PDO $conn, int $branch_id, string $date_open): array {
    $st = $conn->prepare("
      SELECT *
      FROM cash_sessions
      WHERE branch_id = ?
        AND date_open = ?
      ORDER BY caja_num ASC, shift_start ASC
    ");
    $st->execute([$branch_id, $date_open]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists("caja_auto_create_columns_if_missing")) {
  function caja_auto_create_columns_if_missing(PDO $conn, string $table, array $cols): void {
    // Evita errores si tu DB no tiene exactamente las columnas esperadas.
    // Lo dejamos tal cual estaba para no romper nada.
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if (!$table) return;

    $st = $conn->prepare("SHOW COLUMNS FROM `$table`");
    $st->execute();
    $existing = array_map(fn($r) => $r['Field'] ?? '', $st->fetchAll(PDO::FETCH_ASSOC));

    foreach ($cols as $col => $def) {
      if (!in_array($col, $existing, true)) {
        $colSafe = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$col);
        if (!$colSafe) continue;
        $sql = "ALTER TABLE `$table` ADD COLUMN `$colSafe` $def";
        try { $conn->exec($sql); } catch (Throwable $e) { /* silencio */ }
      }
    }
  }
}

if (!function_exists("caja_try_touch_table")) {
  function caja_try_touch_table(PDO $conn, string $table): bool {
    // intenta insertar fecha/created_at si existe para "despertar" tablas en algunos hosts
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if (!$table) return false;

    $st = $conn->prepare("SHOW COLUMNS FROM `$table`");
    $st->execute();
    $cols = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $names = array_map(fn($r) => $r['Field'] ?? '', $cols);

    $now = date("Y-m-d H:i:s");

    $data = [];
    $has = fn($c) => in_array($c, $names, true);

    foreach (['created_at','createdAt','created_on','createdOn','fecha','date','created'] as $c) {
      if ($has($c)) { $data[$c] = $now; break; }
    }

    foreach (['updated_at','updatedAt','updated_on','updatedOn'] as $c) {
      if ($has($c)) { $data[$c] = $now; break; }
    }

    foreach (['fecha','date','created_on'] as $c) {
      if ($has($c)) { $data[$c] = $now; break; }
    }

    if (!$data) return true;

    $fields = array_keys($data);
    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $sql = "INSERT INTO `$table` (`" . implode('`,`', $fields) . "`) VALUES ($placeholders)";
    $st = $conn->prepare($sql);
    return $st->execute(array_values($data));
  }
}
