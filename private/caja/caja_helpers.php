<?php
declare(strict_types=1);

function caja_set_timezone(): void {
    date_default_timezone_set('America/Santo_Domingo');
}

/**
 * Determina turno actual
 */
function caja_get_turno(): array {
    caja_set_timezone();
    $hora = date('H:i:s');

    if ($hora >= '07:00:00' && $hora <= '12:59:59') {
        return [1, '07:00:00', '12:59:59'];
    }

    return [2, '13:00:00', '23:59:59'];
}

/**
 * Obtiene o crea sesión automática usando PDO
 */
function caja_get_or_create_session_id(PDO $pdo, int $branch_id): int {

    if ($branch_id <= 0) {
        return 0;
    }

    caja_set_timezone();
    $fecha = date('Y-m-d');
    [$caja_num, $inicio, $fin] = caja_get_turno();

    // Buscar sesión abierta
    $sql = "SELECT id
            FROM caja_sesiones
            WHERE branch_id = :branch_id
              AND caja_num = :caja_num
              AND fecha = :fecha
              AND estado = 'abierta'
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':branch_id' => $branch_id,
        ':caja_num'  => $caja_num,
        ':fecha'     => $fecha
    ]);

    $id = $stmt->fetchColumn();

    if ($id) {
        return (int)$id;
    }

    // Crear sesión
    try {
        $insert = "INSERT INTO caja_sesiones
                   (branch_id, caja_num, fecha, hora_inicio, hora_fin, estado)
                   VALUES
                   (:branch_id, :caja_num, :fecha, :inicio, :fin, 'abierta')";

        $stmt2 = $pdo->prepare($insert);
        $stmt2->execute([
            ':branch_id' => $branch_id,
            ':caja_num'  => $caja_num,
            ':fecha'     => $fecha,
            ':inicio'    => $inicio,
            ':fin'       => $fin
        ]);

        return (int)$pdo->lastInsertId();

    } catch (Throwable $e) {
        // Si hubo carrera por UNIQUE, buscar otra vez
        $stmt->execute([
            ':branch_id' => $branch_id,
            ':caja_num'  => $caja_num,
            ':fecha'     => $fecha
        ]);

        $id = $stmt->fetchColumn();
        return $id ? (int)$id : 0;
    }
}

/**
 * Obtener branch_id
 */
function caja_require_branch_id(): int {

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (isset($_SESSION['branch_id'])) {
        return (int)$_SESSION['branch_id'];
    }

    if (isset($_SESSION['user']['branch_id'])) {
        return (int)$_SESSION['user']['branch_id'];
    }

    return 0;
}
