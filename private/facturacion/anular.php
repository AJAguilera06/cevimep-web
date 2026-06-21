<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

function isAdminRole(string $role): bool {
    $r = mb_strtolower(trim($role));
    return in_array($r, ['administrador', 'admin', 'superadmin'], true);
}

function colExists(PDO $conn, string $table, string $col): bool {
    try {
        $db = (string)$conn->query("SELECT DATABASE()")->fetchColumn();
        $st = $conn->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?
        ");
        $st->execute([$db, $table, $col]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$user = $_SESSION["user"] ?? [];
$role = (string)($user["role"] ?? "");
$userId = (int)($user["id"] ?? 0);
$branchId = (int)($user["branch_id"] ?? 0);

if (!isAdminRole($role)) {
    http_response_code(403);
    die("No tienes permiso para anular facturas.");
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    die("Método no permitido.");
}

$csrf = (string)($_POST["csrf_token"] ?? "");
if (empty($_SESSION["csrf_token"]) || !hash_equals((string)$_SESSION["csrf_token"], $csrf)) {
    http_response_code(403);
    die("Token de seguridad inválido.");
}

$invoiceId = (int)($_POST["invoice_id"] ?? 0);
$patientId = (int)($_POST["patient_id"] ?? 0);
$reason = trim((string)($_POST["reason"] ?? ""));

if ($branchId <= 0) {
    http_response_code(400);
    die("Sucursal inválida.");
}

if ($invoiceId <= 0) {
    http_response_code(400);
    die("Factura inválida.");
}

if (mb_strlen($reason) < 5) {
    http_response_code(400);
    die("Debe indicar un motivo de anulación válido.");
}

$requiredColumns = ["status", "cancelled_by", "cancelled_at", "cancel_reason"];
foreach ($requiredColumns as $col) {
    if (!colExists($conn, "invoices", $col)) {
        http_response_code(500);
        die("Falta la columna invoices.$col. Ejecuta primero el SQL de anulación.");
    }
}

try {
    $conn->beginTransaction();

    $st = $conn->prepare("
        SELECT id, patient_id, branch_id, invoice_code, status
        FROM invoices
        WHERE id = ?
          AND branch_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $st->execute([$invoiceId, $branchId]);
    $invoice = $st->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        throw new RuntimeException("Factura no encontrada en esta sucursal.");
    }

    $currentStatus = strtoupper((string)($invoice["status"] ?? "ACTIVE"));
    if ($currentStatus === "CANCELLED" || $currentStatus === "ANULADA") {
        throw new RuntimeException("Esta factura ya está anulada.");
    }

    $up = $conn->prepare("
        UPDATE invoices
        SET status = 'CANCELLED',
            cancelled_by = ?,
            cancelled_at = NOW(),
            cancel_reason = ?
        WHERE id = ?
          AND branch_id = ?
        LIMIT 1
    ");
    $up->execute([$userId, $reason, $invoiceId, $branchId]);

    if (colExists($conn, "audit_logs", "id")) {
        try {
            $details = "Factura anulada";
            if (!empty($invoice["invoice_code"])) {
                $details .= ": " . (string)$invoice["invoice_code"];
            }
            $details .= " | Motivo: " . $reason;

            $audit = $conn->prepare("
                INSERT INTO audit_logs
                    (branch_id, user_id, action, entity_type, entity_id, details, ip_address)
                VALUES
                    (?, ?, 'FACTURA_ANULADA', 'invoice', ?, ?, ?)
            ");
            $audit->execute([
                $branchId,
                $userId,
                $invoiceId,
                $details,
                $_SERVER["REMOTE_ADDR"] ?? ""
            ]);
        } catch (Throwable $e) {
            // No detenemos la anulación si la auditoría falla.
        }
    }

    $conn->commit();

    $redirectPatientId = $patientId > 0 ? $patientId : (int)($invoice["patient_id"] ?? 0);
    header("Location: /private/facturacion/paciente.php?patient_id=" . $redirectPatientId);
    exit;

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    http_response_code(400);
    echo "Error anulando factura: " . h($e->getMessage());
    exit;
}
