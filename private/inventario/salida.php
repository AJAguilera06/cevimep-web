<?php
declare(strict_types=1);

/* ===============================
   Bootstrap / Auth / DB
   =============================== */
require_once __DIR__ . "/../_guard.php";

$conn = $pdo;

$user = $_SESSION["user"];
$branch_id   = (int)($user["branch_id"] ?? 0);

/* Nombre de sucursal (session si existe, si no, consulta branches) */
$branch_name = (string)($user["branch_name"] ?? "");
if ($branch_name === "" && $branch_id > 0) {
    $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
    $stB->execute([$branch_id]);
    $branch_name = (string)($stB->fetchColumn() ?? "");
}
if ($branch_name === "") $branch_name = "CEVIMEP";

$today = date("Y-m-d");

if (!isset($_SESSION["salida_items"]) || !is_array($_SESSION["salida_items"])) {
    $_SESSION["salida_items"] = [];
}

/* =========================
   AGREGAR PRODUCTO
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_item"])) {

    $item_id  = (int)($_POST["item_id"] ?? 0);
    $category = trim((string)($_POST["category"] ?? ""));
    $qty      = (int)($_POST["qty"] ?? 0);

    if ($item_id > 0 && $qty > 0) {

        /* Validar producto y stock en esta sede
           (NO existe i.branch_id en inventory_items) */
        $st = $conn->prepare("
            SELECT i.id, i.name, IFNULL(s.quantity,0) AS stock
            FROM inventory_items i
            INNER JOIN inventory_stock s
              ON s.item_id = i.id
            WHERE i.id = ? AND s.branch_id = ?
            LIMIT 1
        ");
        $st->execute([$item_id, $branch_id]);
        $item = $st->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            $stock = (int)$item["stock"];

            $current = (int)($_SESSION["salida_items"][$item_id]["qty"] ?? 0);
            $newQty  = $current + $qty;

            if ($stock > 0 && $newQty <= $stock) {
                $_SESSION["salida_items"][$item_id] = [
                    "category" => ($category !== "" ? $category : (string)($_SESSION["salida_items"][$item_id]["category"] ?? "")),
                    "name"     => (string)$item["name"],
                    "qty"      => $newQty,
                    "stock"    => $stock
                ];
            }
        }
    }
}

/* =========================
   ELIMINAR PRODUCTO
========================= */
if (isset($_GET["remove"])) {
    unset($_SESSION["salida_items"][(int)$_GET["remove"]]);
}

/* =========================
   GUARDAR SALIDA
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_exit"])) {

    if (!empty($_SESSION["salida_items"])) {

        $note = trim((string)($_POST["note"] ?? ""));

        $conn->beginTransaction();

        /* Validar stock dentro de transacción para evitar negativos */
        $stCheck = $conn->prepare("
            SELECT quantity
            FROM inventory_stock
            WHERE item_id = ? AND branch_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        foreach ($_SESSION["salida_items"] as $item_id => $data) {
            $q = (int)($data["qty"] ?? 0);
            if ($q <= 0) continue;

            $stCheck->execute([(int)$item_id, $branch_id]);
            $currentStock = (int)($stCheck->fetchColumn() ?? 0);

            if ($currentStock < $q) {
                $conn->rollBack();
                header("Location: salida.php?err=stock");
                exit;
            }
        }

        $stMov = $conn->prepare("
            INSERT INTO inventory_movements (type, branch_id, note, created_at)
            VALUES ('salida', ?, ?, NOW())
        ");
        $stMov->execute([$branch_id, ($note === "" ? null : $note)]);
        $movement_id = (int)$conn->lastInsertId();

        $stItem = $conn->prepare("
            INSERT INTO inventory_movement_items (movement_id, item_id, quantity)
            VALUES (?, ?, ?)
        ");

        $stStock = $conn->prepare("
            UPDATE inventory_stock
            SET quantity = quantity - ?
            WHERE item_id = ? AND branch_id = ?
        ");

        foreach ($_SESSION["salida_items"] as $item_id => $data) {
            $q = (int)($data["qty"] ?? 0);
            if ($q <= 0) continue;

            $stItem->execute([$movement_id, (int)$item_id, $q]);
            $stStock->execute([$q, (int)$item_id, $branch_id]);
        }

        $conn->commit();
        $_SESSION["salida_items"] = [];

        header("Location: salida.php?ok=1");
        exit;
    }
}

/* =========================
   PRODUCTOS CON STOCK > 0 EN LA SEDE
========================= */
$st = $conn->prepare("
    SELECT i.id, i.name, s.quantity
    FROM inventory_items i
    INNER JOIN inventory_stock s
      ON s.item_id = i.id
    WHERE s.branch_id = ? AND s.quantity > 0
    ORDER BY i.name
");
$st->execute([$branch_id]);
$products = $st->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include __DIR__ . "/../partials/header.php"; ?>
<?php include __DIR__ . "/../partials/sidebar.php"; ?>

<main class="content">
<div class="card">

<h2>Salida</h2>
<p><strong>Sucursal:</strong> <?= htmlspecialchars($branch_name) ?></p>

<?php if (isset($_GET["ok"])): ?>
  <div class="alert success">Salida guardada correctamente.</div>
<?php elseif (isset($_GET["err"]) && $_GET["err"] === "stock"): ?>
  <div class="alert danger">Stock insuficiente para uno de los productos. Vuelve a intentar.</div>
<?php endif; ?>

<form method="post">

<div class="grid-2">
    <div>
        <label>Fecha</label>
        <input type="date" value="<?= $today ?>" disabled>
    </div>
    <div>
        <label>Destino</label>
        <input type="text" value="<?= htmlspecialchars($branch_name) ?>" disabled>
    </div>
</div>

<div class="grid-2">
    <div>
        <label>Área solicitante</label>
        <input type="text" value="<?= htmlspecialchars($branch_name) ?>" disabled>
    </div>
    <div>
        <label>Nota (opcional)</label>
        <input type="text" name="note" value="">
    </div>
</div>

<hr>

<div class="grid-4">
    <div>
        <label>Categoría</label>
        <input type="text" name="category" required>
    </div>
    <div>
        <label>Producto</label>
        <select name="item_id" required>
            <option value="">Seleccione</option>
            <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p["id"] ?>">
                    <?= htmlspecialchars((string)$p["name"]) ?> (Stock: <?= (int)$p["quantity"] ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>Cantidad</label>
        <input type="number" name="qty" min="1" value="1" required>
    </div>
    <div class="btn-col">
        <button type="submit" name="add_item">Añadir</button>
    </div>
</div>

</form>

<hr>

<table class="table">
<thead>
<tr>
    <th>Categoría</th>
    <th>Producto</th>
    <th>Cantidad</th>
    <th>Acción</th>
</tr>
</thead>
<tbody>
<?php if (empty($_SESSION["salida_items"])): ?>
<tr><td colspan="4">No hay productos agregados.</td></tr>
<?php else: foreach ($_SESSION["salida_items"] as $id => $it): ?>
<tr>
    <td><?= htmlspecialchars((string)($it["category"] ?? "")) ?></td>
    <td><?= htmlspecialchars((string)($it["name"] ?? "")) ?></td>
    <td><?= (int)($it["qty"] ?? 0) ?></td>
    <td><a href="?remove=<?= (int)$id ?>">Eliminar</a></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>

<form method="post">
    <button type="submit" name="save_exit">Guardar e imprimir</button>
</form>

</div>
</main>

<?php include __DIR__ . "/../partials/footer.php"; ?>
