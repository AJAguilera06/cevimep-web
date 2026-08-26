<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
require_once __DIR__ . "/../caja/caja_helpers.php";
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

function tableExistsAnular(PDO $conn, string $table): bool {
    try {
        $db = (string)$conn->query("SELECT DATABASE()")->fetchColumn();
        $st = $conn->prepare("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
        ");
        $st->execute([$db, $table]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function tableColumnsAnular(PDO $conn, string $table): array {
    try {
        $rows = $conn->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn($r) => (string)$r["Field"], $rows);
    } catch (Throwable $e) {
        return [];
    }
}

$user = $_SESSION["user"] ?? [];
$role = (string)($user["role"] ?? "");
$userId = (int)($user["id"] ?? 0);

if (!isAdminRole($role)) {
    http_response_code(403);
    die("No tienes permiso para anular facturas.");
}

$branchId = isAdminRole($role)
    ? (int)($_SESSION['selected_branch_id'] ?? 0)
    : (int)($user["branch_id"] ?? 0);

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
    die("Sucursal inválida. Selecciona una sucursal en Facturación primero.");
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

/* ===============================
   Detectar tabla de stock (igual que nueva.php)
   =============================== */
$stockTable = null;
$stockCandidates = ["inventory_stock","inventory_branch_stock","inventario_stock","inventory_sucursal_stock","stock"];
foreach ($stockCandidates as $t) {
    if (tableExistsAnular($conn, $t)) { $stockTable = $t; break; }
}

$stockItemCol = null;
$stockBranchCol = null;
$stockQtyCol = null;

if ($stockTable !== null) {
    $stockCols = tableColumnsAnular($conn, $stockTable);

    if (in_array("item_id", $stockCols, true)) $stockItemCol = "item_id";
    elseif (in_array("product_id", $stockCols, true)) $stockItemCol = "product_id";
    elseif (in_array("inventory_item_id", $stockCols, true)) $stockItemCol = "inventory_item_id";

    if (in_array("branch_id", $stockCols, true)) $stockBranchCol = "branch_id";
    elseif (in_array("sucursal_id", $stockCols, true)) $stockBranchCol = "sucursal_id";
    elseif (in_array("branch", $stockCols, true)) $stockBranchCol = "branch";

    if (in_array("quantity", $stockCols, true)) $stockQtyCol = "quantity";
    elseif (in_array("qty", $stockCols, true)) $stockQtyCol = "qty";
    elseif (in_array("existencia", $stockCols, true)) $stockQtyCol = "existencia";
    elseif (in_array("stock", $stockCols, true)) $stockQtyCol = "stock";
    elseif (in_array("balance", $stockCols, true)) $stockQtyCol = "balance";

    if ($stockItemCol === null || $stockBranchCol === null || $stockQtyCol === null) {
        $stockTable = null; // inutilizable
    }
}

/* ===============================
   Detectar columna stock_qty_discounted en invoice_items
   =============================== */
$hasStockDiscounted = colExists($conn, "invoice_items", "stock_qty_discounted");
$hasSubtotal = colExists($conn, "invoices", "subtotal");

try {
    $conn->beginTransaction();

    $st = $conn->prepare("
        SELECT id, patient_id, branch_id, invoice_code, status, total" . ($hasSubtotal ? ", subtotal" : "") . "
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

    /* ===============================
       1) Devolver inventario
       Si no se puede confirmar la devolución, se detiene TODA la
       anulación (rollback) en vez de continuar en silencio.
       =============================== */
    if ($stockTable === null) {
        throw new RuntimeException(
            "No se detectó la tabla de inventario (stock). No se pudo devolver el producto. " .
            "Revisa el nombre real de la tabla de stock en la base de datos."
        );
    }

    $itemsCols = tableColumnsAnular($conn, "invoice_items");
    $colItem = in_array("item_id", $itemsCols, true)
        ? "item_id"
        : (in_array("inventory_item_id", $itemsCols, true) ? "inventory_item_id" : "item_id");
    $colQty  = in_array("qty", $itemsCols, true)
        ? "qty"
        : (in_array("quantity", $itemsCols, true) ? "quantity" : "qty");

    $selectExtra = $hasStockDiscounted ? ", stock_qty_discounted" : "";
    $stLines = $conn->prepare("
        SELECT {$colItem} AS item_id, {$colQty} AS qty {$selectExtra}
        FROM invoice_items
        WHERE invoice_id = ?
    ");
    $stLines->execute([$invoiceId]);
    $invoiceLines = $stLines->fetchAll(PDO::FETCH_ASSOC);

    if (empty($invoiceLines)) {
        throw new RuntimeException(
            "Esta factura no tiene productos en invoice_items (o la columna '{$colItem}'/'{$colQty}' " .
            "no es la correcta). No se pudo devolver nada al inventario."
        );
    }

    $stRestore = $conn->prepare("
        UPDATE {$stockTable}
        SET {$stockQtyCol} = {$stockQtyCol} + ?
        WHERE {$stockBranchCol} = ?
          AND {$stockItemCol} = ?
    ");

    $restoredAny = false;

    foreach ($invoiceLines as $line) {
        $iid = (int)$line["item_id"];
        if ($iid <= 0) continue;

        $restoreQty = $hasStockDiscounted && $line["stock_qty_discounted"] !== null
            ? (float)$line["stock_qty_discounted"]
            : (float)$line["qty"]; // fallback 1:1 para facturas anteriores a la migración

        if ($restoreQty <= 0) continue;

        $stRestore->execute([$restoreQty, $branchId, $iid]);

        if ($stRestore->rowCount() === 0) {
            throw new RuntimeException(
                "No existe una fila de stock para el producto ID {$iid} en la sucursal {$branchId} " .
                "(tabla {$stockTable}). No se pudieron devolver {$restoreQty} unidad(es). " .
                "Verifica que ese producto tenga registro de inventario en esta sucursal."
            );
        }

        $restoredAny = true;
    }

    if (!$restoredAny) {
        throw new RuntimeException("No se pudo determinar ninguna cantidad válida a devolver al inventario.");
    }

    /* ===============================
       2) Revertir movimientos de caja
       Se buscan los ingresos ORIGINALES de esta factura (por método
       de pago exacto: efectivo/tarjeta/transferencia/cobertura) y se
       revierte cada uno con type='anulacion' (NO 'desembolso'), para
       que reste del método de pago correcto y no infle la columna
       de Desembolsos en la pantalla de Caja.

       ✅ CORRECCIÓN: se busca también el caja_sesion_id EXACTO donde
       se registró cada cobro original, y la reversa se aplica ahí
       mismo — sin importar si esa caja (1 o 2) ya está cerrada. Antes
       se pedía/creaba una sesión "actual" para reversar, lo que
       obligaba a abrir la otra caja del turno y descontaba el dinero
       de la caja equivocada.
       =============================== */
    $montoReversar = $hasSubtotal && (float)($invoice["subtotal"] ?? 0) > 0
        ? (float)$invoice["subtotal"]
        : (float)($invoice["total"] ?? 0);

    $motivoCaja = "Anulación factura " . ($invoice["invoice_code"] ?? ("#" . $invoiceId)) . " | " . $reason;

    $origPayments = [];
    if (!empty($invoice["invoice_code"])) {
        try {
            $stOrig = $conn->prepare("
                SELECT metodo_pago, amount, caja_sesion_id
                FROM cash_movements
                WHERE branch_id = ?
                  AND type = 'ingreso'
                  AND motivo LIKE ?
            ");
            $stOrig->execute([$branchId, '%Ingreso por factura ' . $invoice["invoice_code"] . '%']);
            $origPayments = $stOrig->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $origPayments = [];
        }
    }

    // Solo se exige abrir/crear una sesión "actual" si NO encontramos
    // los cobros originales (facturas viejas sin match), porque ahí sí
    // no hay forma de saber en qué caja se cobró.
    $cajaSesionId = 0;
    if (empty($origPayments)) {
        $cajaSesionId = (int)caja_get_or_create_session_id($conn, $branchId);
        if ($cajaSesionId <= 0) {
            throw new RuntimeException("Debes abrir una caja en esta sucursal antes de anular esta factura.");
        }
    }


    $stCaja = $conn->prepare("
        INSERT INTO cash_movements
            (branch_id, caja_sesion_id, type, motivo, metodo_pago, amount, created_by)
        VALUES
            (?, ?, 'anulacion', ?, ?, ?, ?)
    ");

    if (!empty($origPayments)) {
        // Reversa exacta por cada método de pago que se usó al facturar (soporta MIXTO).
        // Se usa la MISMA caja_sesion_id del cobro original (abierta o cerrada).
        foreach ($origPayments as $op) {
            $amt = (float)($op["amount"] ?? 0);
            if ($amt <= 0) continue;
            $metodo = (string)($op["metodo_pago"] ?? "efectivo");
            $origSessionId = (int)($op["caja_sesion_id"] ?? 0);
            $targetSessionId = $origSessionId > 0 ? $origSessionId : $cajaSesionId;
            $stCaja->execute([$branchId, $targetSessionId, $motivoCaja, $metodo, -abs($amt), $userId > 0 ? $userId : null]);
        }
    } elseif ($montoReversar > 0) {
        // Fallback: facturas antiguas sin invoice_code o sin match en cash_movements.
        // Aquí sí usamos/creamos la sesión actual porque no hay forma de saber la original.
        if ($cajaSesionId <= 0) {
            $cajaSesionId = (int)caja_get_or_create_session_id($conn, $branchId);
        }
        if ($cajaSesionId <= 0) {
            throw new RuntimeException("Debes abrir una caja en esta sucursal antes de anular esta factura.");
        }
        $stCaja->execute([$branchId, $cajaSesionId, $motivoCaja, "efectivo", -abs($montoReversar), $userId > 0 ? $userId : null]);
    }

    /* ===============================
       3) Marcar factura como anulada
       =============================== */
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