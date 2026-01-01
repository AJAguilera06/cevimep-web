<?php
session_start();
unset($_SESSION["estadistica_ok"]);
header("Location: /cevimep-web/private/estadistica/index.php");
exit;
