<?php
session_start();
if (!isset($_SESSION["user"])) {
    header("Location: ../../public/login.php");
    exit;
}

require_once __DIR__ . "/../../config/db.php";
$conn = $pdo;

$user = $_SESSION["user"];
$branch_id = (int)($user["branch_id"] ?? 0);

// Campos (según tu tabla patients)
$first_name = trim($_POST["first_name"] ?? "");
$last_name  = trim($_POST["last_name"] ?? "");
$cedula     = trim($_POST["cedula"] ?? "");
$phone      = trim($_POST["phone"] ?? "");
$email      = trim($_POST["email"] ?? "");
$birth_date = trim($_POST["birth_date"] ?? "");
$gender     = trim($_POST["gender"] ?? "");
$blood_type = trim($_POST["blood_type"] ?? "");
$address    = trim($_POST["address"] ?? "");

// Nuevos campos que agregaste
$no_libro           = trim($_POST["no_libro"] ?? "");
$medico_refiere     = trim($_POST["medico_refiere"] ?? "");
$clinica_referencia = trim($_POST["clinica_referencia"] ?? "");
$ars                = trim($_POST["ars"] ?? "");
$numero_afiliado    = trim($_POST["numero_afiliado"] ?? "");
$registrado_por     = trim($_POST["registrado_por"] ?? "");

// Validación mínima
if ($first_name === "" || $last_name === "") {
    header("Location: create.php?error=" . urlencode("Nombre y apellido son obligatorios"));
    exit;
}

try {
    $sql = "INSERT INTO patients
        (first_name, last_name, cedula, phone, email, birth_date, gender, blood_type, address, branch_id,
         no_libro, medico_refiere, clinica_referencia, ars, numero_afiliado, registrado_por)
        VALUES
        (:first_name, :last_name, :cedula, :phone, :email, :birth_date, :gender, :blood_type, :address, :branch_id,
         :no_libro, :medico_refiere, :clinica_referencia, :ars, :numero_afiliado, :registrado_por)";

    $st = $conn->prepare($sql);
    $st->execute([
        ":first_name" => $first_name,
        ":last_name"  => $last_name,
        ":cedula"     => ($cedula !== "" ? $cedula : null),
        ":phone"      => ($phone !== "" ? $phone : null),
        ":email"      => ($email !== "" ? $email : null),
        ":birth_date" => ($birth_date !== "" ? $birth_date : null),
        ":gender"     => ($gender !== "" ? $gender : null),
        ":blood_type" => ($blood_type !== "" ? $blood_type : null),
        ":address"    => ($address !== "" ? $address : null),
        ":branch_id"  => ($branch_id > 0 ? $branch_id : null),

        ":no_libro"           => ($no_libro !== "" ? $no_libro : null),
        ":medico_refiere"     => ($medico_refiere !== "" ? $medico_refiere : null),
        ":clinica_referencia" => ($clinica_referencia !== "" ? $clinica_referencia : null),
        ":ars"                => ($ars !== "" ? $ars : null),
        ":numero_afiliado"    => ($numero_afiliado !== "" ? $numero_afiliado : null),
        ":registrado_por"     => ($registrado_por !== "" ? $registrado_por : null),
    ]);

    header("Location: index.php?ok=1");
    exit;

} catch (Exception $e) {
    // Para depurar rápido si algo falla:
    // echo $e->getMessage(); exit;
    header("Location: create.php?error=" . urlencode("Error al guardar paciente"));
    exit;
}
