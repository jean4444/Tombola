
<?php
// attribuer_tranche.php - Script pour attribuer une tranche à un élève

require_once 'config.php';
require_once 'billets.php';

// Vérifier si le formulaire a été soumis
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_eleve']) && isset($_POST['id_tranche'])) {
    $idEleve = (int)$_POST['id_eleve'];
    $idTranche = (int)$_POST['id_tranche'];
    
    // Attribuer la tranche
    $resultat = attribuerTranche($idEleve, $idTranche);
    
    // Récupérer la classe de l'élève pour la redirection
    $db = connectDB();
    $stmt = $db->prepare("SELECT id_classe FROM eleves WHERE id_eleve = ?");
    $stmt->execute([$idEleve]);
    $eleve = $stmt->fetch();
    
    // Rediriger vers la page de l'élève
    if ($eleve) {
        header("Location: eleves.php?eleve=" . $idEleve);
        exit;
    } else {
        header("Location: eleves.php");
        exit;
    }
} else {
    // Redirection en cas d'erreur
    header("Location: eleves.php");
    exit;
}
?>

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