<?php
session_start();
if (!isset($_SESSION["user"])) {
    header("Location: ../../public/login.php");
    exit;
}

require_once __DIR__ . "/../../config/db.php";

$user = $_SESSION["user"];
$branch_id = (int)$user["branch_id"];
$branch_name = $user["branch_name"] ?? "";
$today = date("Y-m-d");

if (!isset($_SESSION["salida_items"])) {
    $_SESSION["salida_items"] = [];
}

/* =========================
   AGREGAR PRODUCTO A SESIÓN
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_item"])) {

    $item_id = (int)$_POST["item_id"];
    $category = trim($_POST["category"]);
    $qty = (int)$_POST["qty"];

    if ($item_id > 0 && $qty > 0) {

        // Validar producto y stock en esta sede
        $st = $pdo->prepare("
            SELECT i.id, i.name, IFNULL(s.quantity,0) AS stock
            FROM inventory_items i
            LEFT JOIN inventory_stock s 
                ON s.item_id = i.id AND s.branch_id = ?
            WHERE i.id = ? AND i.branch_id = ?
            LIMIT 1
        ");
        $st->execute([$branch_id, $item_id, $branch_id]);
        $item = $st->fetch(PDO::FETCH_ASSOC);

        if ($item && $item["stock"] >= $qty) {

            if (isset($_SESSION["salida_items"][$item_id])) {
                $newQty = $_SESSION["salida_items"][$item_id]["qty"] + $qty;
                if ($newQty <= $item["stock"]) {
                    $_SESSION["salida_items"][$item_id]["qty"] = $newQty;
                }
            } else {
                $_SESSION["salida_items"][$item_id] = [
                    "category" => $category,
                    "name" => $item["name"],
                    "qty" => $qty
                ];
            }
        }
    }
}

/* =========================
   ELIMINAR PRODUCTO
========================= */
if (isset($_GET["remove"])) {
    $rid = (int)$_GET["remove"];
    unset($_SESSION["salida_items"][$rid]);
}

/* =========================
   GUARDAR SALIDA
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_exit"])) {

    if (!empty($_SESSION["salida_items"])) {

        $pdo->beginTransaction();

        $stMov = $pdo->prepare("
            INSERT INTO inventory_movements 
            (type, branch_id, note, created_at)
            VALUES ('salida', ?, ?, NOW())
        ");
        $stMov->execute([$branch_id, $_POST["note"]]);
        $movement_id = $pdo->lastInsertId();

        $stItem = $pdo->prepare("
            INSERT INTO inventory_movement_items
            (movement_id, item_id, quantity)
            VALUES (?, ?, ?)
        ");

        $stStock = $pdo->prepare("
            UPDATE inventory_stock
            SET quantity = quantity - ?
            WHERE item_id = ? AND branch_id = ?
        ");

        foreach ($_SESSION["salida_items"] as $item_id => $data) {
            $stItem->execute([$movement_id, $item_id, $data["qty"]]);
            $stStock->execute([$data["qty"], $item_id, $branch_id]);
        }

        $pdo->commit();
        $_SESSION["salida_items"] = [];

        header("Location: salida.php?ok=1");
        exit;
    }
}

/* =========================
   PRODUCTOS CON STOCK > 0
========================= */
$items = $pdo->prepare("
    SELECT i.id, i.name
    FROM inventory_items i
    INNER JOIN inventory_stock s 
        ON s.item_id = i.id AND s.branch_id = ?
    WHERE i.branch_id = ? AND s.quantity > 0
    ORDER BY i.name
");
$items->execute([$branch_id, $branch_id]);
$products = $items->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include __DIR__ . "/../partials/header.php"; ?>
<?php include __DIR__ . "/../partials/sidebar.php"; ?>

<main class="content">
<div class="card">

<h2>Salida</h2>
<p><strong>Sucursal:</strong> <?= htmlspecialchars($branch_name) ?></p>

<form method="post">

<div class="grid-2">
    <div>
        <label>Fecha</label>
        <input type="date" value="<?= $today ?>" disabled>
    </div>
    <div>
        <label>Área solicitante</label>
        <input type="text" value="<?= htmlspecialchars($branch_name) ?>" disabled>
    </div>
</div>

<div class="grid-2">
    <div>
        <label>Destino</label>
        <input type="text" value="<?= htmlspecialchars($branch_name) ?>" disabled>
    </div>
    <div>
        <label>Nota (opcional)</label>
        <input type="text" name="note">
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
                <option value="<?= $p["id"] ?>"><?= htmlspecialchars($p["name"]) ?></option>
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
<?php else: ?>
<?php foreach ($_SESSION["salida_items"] as $id => $it): ?>
<tr>
    <td><?= htmlspecialchars($it["category"]) ?></td>
    <td><?= htmlspecialchars($it["name"]) ?></td>
    <td><?= $it["qty"] ?></td>
    <td><a href="?remove=<?= $id ?>">Eliminar</a></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>

<form method="post">
    <button type="submit" name="save_exit">Guardar e imprimir</button>
</form>

</div>
</main>

<?php include __DIR__ . "/../partials/footer.php"; ?>
