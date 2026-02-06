<?php
require_once 'auth.php';
deconnecterUtilisateur();
header("Location: login.php");
exit;
?>