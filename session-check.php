<?php
session_start();
echo "<pre>";
echo "Session active: " . (session_status() === PHP_SESSION_ACTIVE ? "Oui" : "Non") . "\n";
echo "Variables de session: \n";
print_r($_SESSION);
echo "</pre>";
?>