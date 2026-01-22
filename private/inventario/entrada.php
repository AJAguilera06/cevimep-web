<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["user"])) {
    header("Location: ../../public/login.php");
    exit;
}

require_once __DIR__ . "/../../../config/db.php";

$user = $_SESSION["user"];
$branch_id   = (int)$user["branch_id"];
$branch_name = $user["branch_name"] ?? "";
$today = date("Y-m-d");

if (!isset($_SESSION["entrada_items"])) {
    $_SESSION["entrada_items"] = [];
}

/* =========================
   AGREGAR PRODUCTO
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_item"])) {

    $item_id  = (int)$_POST["item_id"];
    $category = trim($_POST["category"]);
    $qty      = (int)$_POST["qty"];

    if ($item_id > 0 && $qty > 0) {

        $st = $pdo->prepare("
            SELECT id, name
            FROM inventory_items
            WHERE id = ? AND branch_id = ?
            LIMIT 1
        ");
        $st->execute([$item_id, $branch_id]);
        $item = $st->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            if (isset($_SESSION["entrada_items"][$item_id])) {
                $_SESSION["entrada_items"][$item_id]["qty"] += $qty;
            } else {
                $_SESSION["entrada_items"][$item_id] = [
                    "category" => $category,
                    "name"     => $item["name"],
                    "qty"      => $qty
                ];
            }
        }
    }
}

/* =========================
   ELIMINAR PRODUCTO
========================= */
if (isset($_GET["remove"])) {
    unset($_SESSION["entrada_items"][(int)$_GET["remove"]]);
}

/* =========================
   GUARDAR ENTRADA
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_entry"])) {

    if (!empty($_SESSION["entrada_items"])) {

        $pdo->beginTransaction();

        $stMov = $pdo->prepare("
            INSERT INTO inventory_movements (type, branch_id, note, created_at)
            VALUES ('entrada', ?, ?, NOW())
        ");
        $stMov->execute([$branch_id, $_POST["note"] ?? null]);
        $movement_id = $pdo->lastInsertId();

        $stItem = $pdo->prepare("
            INSERT INTO inventory_movement_items (movement_id, item_id, quantity)
            VALUES (?, ?, ?)
        ");

        $stStock = $pdo->prepare("
            INSERT INTO inventory_stock (item_id, branch_id, quantity)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
        ");

        foreach ($_SESSION["entrada_items"] as $item_id => $data) {
            $stItem->execute([$movement_id, $item_id, $data["qty"]]);
            $stStock->execute([$item_id, $branch_id, $data["qty"]]);
        }

        $pdo->commit();
        $_SESSION["entrada_items"] = [];

        header("Location: entrada.php?ok=1");
        exit;
    }
}

/* =========================
   PRODUCTOS DE LA SEDE
========================= */
$st = $pdo->prepare("
    SELECT id, name
    FROM inventory_items
    WHERE branch_id = ?
    ORDER BY name
");
$st->execute([$branch_id]);
$products = $st->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include __DIR__ . "/../partials/header.php"; ?>
<?php include __DIR__ . "/../partials/sidebar.php"; ?>

<main class="content">
<div class="card">

<h2>Entrada</h2>
<p><strong>Sucursal:</strong> <?= htmlspecialchars($branch_name) ?></p>

<form method="post">

<div class="grid-2">
    <div>
        <label>Fecha</label>
        <input type="date" value="<?= $today ?>" disabled>
    </div>
    <div>
        <label>Suplidor</label>
        <input type="text" value="Almacén <?= htmlspecialchars($branch_name) ?>" disabled>
    </div>
</div>

<div class="grid-2">
    <div>
        <label>Área destino</label>
        <input type="text" value="<?= htmlspecialchars($branch_name) ?>" disabled>
    </div>
    <div>
        <label>Nota</label>
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
<?php if (empty($_SESSION["entrada_items"])): ?>
<tr><td colspan="4">No hay productos agregados.</td></tr>
<?php else: foreach ($_SESSION["entrada_items"] as $id => $it): ?>
<tr>
    <td><?= htmlspecialchars($it["category"]) ?></td>
    <td><?= htmlspecialchars($it["name"]) ?></td>
    <td><?= $it["qty"] ?></td>
    <td><a href="?remove=<?= $id ?>">Eliminar</a></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>

<form method="post">
    <button type="submit" name="save_entry">Guardar e imprimir</button>
</form>

</div>
</main>

<?php include __DIR__ . "/../partials/footer.php"; ?>
