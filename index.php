<?php
session_start();
require_once 'config.php';

// Si l'utilisateur est déjà connecté, afficher directement le tableau de bord
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    include 'dashboard.php';
    exit;
}

// Sinon, rediriger vers la page de connexion
header("Location: login.php");
exit;
?>