<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function caja_set_timezone(): void {
    date_default_timezone_set('America/Santo_Domingo');
}

/**
 * Determina caja según hora actual
 */
function caja_get_turno(): array {
    caja_set_timezone();
    $hora = date('H:i:s');

    if ($hora >= '07:00:00' && $hora <= '12:59:59') {
        return [1, '07:00:00', '12:59:59'];
    }

    // 13:00:00 a 23:59:59
    return [2, '13:00:00', '23:59:59'];
}

/**
 * Obtiene o crea sesión del día automáticamente
 */
function caja_get_or_create_session_id(mysqli $conn, int $branch_id): int {

    if ($branch_id <= 0) {
        throw new Exception("Sucursal inválida.");
    }

    caja_set_timezone();
    $fecha = date('Y-m-d');
    [$caja_num, $inicio, $fin] = caja_get_turno();

    // 1️⃣ Buscar sesión abierta
    $sql = "SELECT id FROM caja_sesiones
            WHERE branch_id = ?
              AND caja_num = ?
              AND fecha = ?
              AND estado = 'abierta'
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iis", $branch_id, $caja_num, $fecha);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        return (int)$row['id'];
    }

    // 2️⃣ Crear sesión si no existe
    $insert = "INSERT INTO caja_sesiones
              (branch_id, caja_num, fecha, hora_inicio, hora_fin, estado)
              VALUES (?, ?, ?, ?, ?, 'abierta')";

    $stmt2 = $conn->prepare($insert);
    $stmt2->bind_param("iisss", $branch_id, $caja_num, $fecha, $inicio, $fin);
    $stmt2->execute();

    return (int)$conn->insert_id;
}

/**
 * Obtener branch_id desde sesión
 */
function caja_require_branch_id(): int {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!isset($_SESSION['branch_id'])) {
        throw new Exception("No se encontró sucursal en sesión.");
    }

    return (int)$_SESSION['branch_id'];
}
