<?php
// attribuer_tranches.php - Script pour attribuer les tranches existantes aux élèves

require_once 'config.php';
require_once 'auth.php';
require_once 'billets.php';

// Vérifier si l'utilisateur est connecté
redirigerSiNonConnecte();

// Fonction pour attribuer toutes les tranches selon un fichier Excel
function attribuerTranchesExistantes($fichier, $idAnnee) {
    $db = connectDB();
    
    try {
        if (!file_exists($fichier)) {
            return ["statut" => "erreur", "message" => "Fichier non trouvé."];
        }
        
        // Vérifier que l'extension est xlsx
        $extension = pathinfo($fichier, PATHINFO_EXTENSION);
        if ($extension !== 'xlsx') {
            return ["statut" => "erreur", "message" => "Format de fichier non pris en charge."];
        }
        
        // Charger la bibliothèque PhpSpreadsheet
        require 'vendor/autoload.php';
        
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $spreadsheet = $reader->load($fichier);
        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        
        // Analyser la première ligne pour trouver les en-têtes
        $headerRow = $worksheet->rangeToArray('A1:' . $highestColumn . '1', null, true, false)[0];
        $colonnes = [];
        foreach ($headerRow as $colIndex => $colValue) {
            $colValue = strtolower(trim($colValue));
            if (preg_match('/(classe)/i', $colValue)) {
                $colonnes['classe'] = $colIndex;
            } else if (preg_match('/^nom$/i', $colValue)) {
                $colonnes['nom'] = $colIndex;
            } else if (preg_match('/^prénom$|^prenom$/i', $colValue)) {
                $colonnes['prenom'] = $colIndex;
            } else if (preg_match('/(début|debut)/i', $colValue)) {
                $colonnes['debut'] = $colIndex;
            } else if (preg_match('/^fin$/i', $colValue)) {
                $colonnes['fin'] = $colIndex;
            }
        }
        
        // Vérifier les colonnes requises
        if (!isset($colonnes['classe']) || !isset($colonnes['nom']) || 
            !isset($colonnes['prenom']) || !isset($colonnes['debut']) || 
            !isset($colonnes['fin'])) {
            return ["statut" => "erreur", "message" => "Colonnes manquantes dans le fichier."];
        }
        
        $db->beginTransaction();
        
        // Compteurs
        $nbTranchesAttribuees = 0;
        $nbTranchesIgnorees = 0;
        
        // Parcourir les lignes à partir de la 2ème
        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            $rowData = $worksheet->rangeToArray('A'.$rowIndex.':'.$highestColumn.$rowIndex, null, true, false)[0];
            
            // Vérifier si la ligne n'est pas vide
            $isEmpty = true;
            foreach ($rowData as $cellValue) {
                if (!empty($cellValue)) {
                    $isEmpty = false;
                    break;
                }
            }
            
            if ($isEmpty) continue;
            
            // Extraire les données
            $nomClasse = trim($rowData[$colonnes['classe']]);
            $nom = trim($rowData[$colonnes['nom']]);
            $prenom = trim($rowData[$colonnes['prenom']]);
            $debut = intval($rowData[$colonnes['debut']]);
            $fin = intval($rowData[$colonnes['fin']]);
            
            if (empty($nomClasse) || empty($nom) || empty($prenom) || $debut <= 0 || $fin <= 0) {
                continue;
            }
            
            // Trouver l'ID de l'élève
            $stmt = $db->prepare("
                SELECT e.id_eleve 
                FROM eleves e
                JOIN classes c ON e.id_classe = c.id_classe
                WHERE e.nom = ? AND e.prenom = ? AND c.nom = ? AND e.id_annee = ?
            ");
            $stmt->execute([$nom, $prenom, $nomClasse, $idAnnee]);
            $eleve = $stmt->fetch();
            
            if (!$eleve) {
                $nbTranchesIgnorees++;
                continue; // Élève non trouvé
            }
            
            $idEleve = $eleve['id_eleve'];
            
            // Rechercher une tranche qui correspond à ces numéros
            $stmt = $db->prepare("
                SELECT id_tranche, id_eleve 
                FROM tranches 
                WHERE numero_debut = ? AND numero_fin = ? AND id_annee = ?
            ");
            $stmt->execute([$debut, $fin, $idAnnee]);
            $tranche = $stmt->fetch();
            
            if (!$tranche) {
                $nbTranchesIgnorees++;
                continue; // Tranche non trouvée
            }
            
            // Si la tranche est déjà attribuée à cet élève, passer
            if ($tranche['id_eleve'] == $idEleve) {
                continue;
            }
            
            // Si la tranche est attribuée à un autre élève, ignorer
            if ($tranche['id_eleve'] !== null) {
                $nbTranchesIgnorees++;
                continue;
            }
            
            // Attribuer la tranche à l'élève
            $stmt = $db->prepare("
                UPDATE tranches 
                SET id_eleve = ?, date_attribution = NOW() 
                WHERE id_tranche = ?
            ");
            $stmt->execute([$idEleve, $tranche['id_tranche']]);
            
            // Mettre à jour le statut des billets
            $stmt = $db->prepare("
                UPDATE billets 
                SET statut = 'attribue' 
                WHERE id_tranche = ?
            ");
            $stmt->execute([$tranche['id_tranche']]);
            
            $nbTranchesAttribuees++;
        }
        
        $db->commit();
        
        return [
            "statut" => "success",
            "message" => "$nbTranchesAttribuees tranches attribuées avec succès. $nbTranchesIgnorees tranches ignorées."
        ];
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ["statut" => "erreur", "message" => "Erreur: " . $e->getMessage()];
    }
}

// Traitement du formulaire
$resultat = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fichier'])) {
    $idAnnee = getAnneeActive(connectDB());
    
    if (!$idAnnee) {
        $resultat = ["statut" => "erreur", "message" => "Aucune année scolaire active."];
    } else if ($_FILES['fichier']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['fichier']['tmp_name'];
        $fileName = $_FILES['fichier']['name'];
        
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $filePath = $uploadDir . $fileName;
        if (move_uploaded_file($tmpName, $filePath)) {
            $resultat = attribuerTranchesExistantes($filePath, $idAnnee);
            unlink($filePath);
        } else {
            $resultat = ["statut" => "erreur", "message" => "Erreur lors du téléchargement."];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attribution des tranches - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container my-4">
        <h2>Attribution des Tranches</h2>
        
        <?php
        $db = connectDB();
        $idAnnee = getAnneeActive($db);
        
        if (!$idAnnee) {
            echo '<div class="alert alert-warning">Aucune année scolaire active.</div>';
        } else {
            if (isset($resultat)) {
                $alertClass = $resultat['statut'] === 'success' ? 'success' : 'danger';
                echo "<div class='alert alert-{$alertClass}'>{$resultat['message']}</div>";
            }
            
            // Vérifier si des tranches non attribuées existent
            $stmt = $db->prepare("
                SELECT COUNT(*) as total 
                FROM tranches 
                WHERE id_eleve IS NULL AND id_annee = ?
            ");
            $stmt->execute([$idAnnee]);
            $tranchesNonAttribuees = $stmt->fetch()['total'];
        ?>
            <div class="card">
                <div class="card-header">
                    <h3>Attribuer les tranches</h3>
                </div>
                <div class="card-body">
                    <p>Cette fonctionnalité permet d'attribuer des tranches existantes aux élèves selon un fichier Excel.</p>
                    
                    <?php if ($tranchesNonAttribuees > 0): ?>
                        <div class="alert alert-info">
                            Il y a actuellement <?= $tranchesNonAttribuees ?> tranches non attribuées dans la base de données.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success">
                            Toutes les tranches sont déjà attribuées.
                        </div>
                    <?php endif; ?>
                    
                    <form action="" method="post" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="fichier" class="form-label">Fichier Excel (.xlsx)</label>
                            <input type="file" class="form-control" id="fichier" name="fichier" accept=".xlsx" required>
                        </div>
                        
                        <div class="alert alert-info">
                            <h4>Format attendu</h4>
                            <p>Le fichier doit contenir les colonnes suivantes :</p>
                            <ul>
                                <li>Classe</li>
                                <li>Nom</li>
                                <li>Prénom</li>
                                <li>Début (numéro du premier billet)</li>
                                <li>Fin (numéro du dernier billet)</li>
                            </ul>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Attribuer les tranches</button>
                    </form>
                </div>
            </div>
        <?php } ?>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>