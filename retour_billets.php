<?php
// retour_billets.php - Script pour enregistrer le retour des billets

require_once 'config.php';
require_once 'billets.php';

// Vérifier si le formulaire a été soumis
if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
    isset($_POST['id_eleve']) && 
    isset($_POST['id_tranche']) && 
    isset($_POST['billets_vendus'])) {
    
    $idEleve = (int)$_POST['id_eleve'];
    $idTranche = (int)$_POST['id_tranche'];
    $billetsVendus = (int)$_POST['billets_vendus'];
    
    // Enregistrer le retour
    $resultat = enregistrerRetour($idEleve, $idTranche, $billetsVendus);
    
    // Rediriger vers la page de l'élève
    header("Location: eleves.php?eleve=" . $idEleve);
    exit;
} else {
    // Redirection en cas d'erreur
    header("Location: eleves.php");
    exit;
}
?>