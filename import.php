<?php
// import_unifie.php - Version unifiée pour l'importation et l'attribution des tranches
require_once 'config.php';
require_once 'auth.php';
require_once 'billets.php'; 

// Vérifier si l'utilisateur est connecté
redirigerSiNonConnecte();

// Fonction unifiée pour importer les données et attribuer les tranches
function importerEtAttribuer($fichier, $idAnnee) {
    $db = connectDB();
    
    try {
        if (!file_exists($fichier)) {
            return ["statut" => "erreur", "message" => "Fichier non trouvé."];
        }
        
        // Vérifier que l'extension est xlsx
        $extension = pathinfo($fichier, PATHINFO_EXTENSION);
        if ($extension !== 'xlsx') {
            return ["statut" => "erreur", "message" => "Format de fichier non pris en charge. Seuls les fichiers .xlsx sont acceptés."];
        }
        
        // Charger la bibliothèque PhpSpreadsheet
        require 'vendor/autoload.php';
        
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $spreadsheet = $reader->load($fichier);
        
        // Analyser le fichier pour déterminer sa structure
        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        
        // Analyser les en-têtes
        $headerRow = $worksheet->rangeToArray('A1:' . $highestColumn . '1', null, true, false)[0];
        $colonnes = [];
        
        foreach ($headerRow as $colIndex => $colValue) {
            $colValue = strtolower(trim($colValue));
            
            // Détecter les colonnes importantes
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
            } else if (preg_match('/(nbre|nombre|billets?|tickets?).*(vendus)/i', $colValue)) {
                $colonnes['vendus'] = $colIndex;
            } else if (preg_match('/(montant|euros|€)/i', $colValue)) {
                $colonnes['montant'] = $colIndex;
            }
        }
        
        // Vérifier les colonnes minimales requises
        $colonnesRequises = ['classe', 'nom', 'prenom', 'debut', 'fin'];
        $colonnesManquantes = [];
        
        foreach ($colonnesRequises as $colonne) {
            if (!isset($colonnes[$colonne])) {
                $colonnesManquantes[] = ucfirst($colonne);
            }
        }
        
        if (!empty($colonnesManquantes)) {
            return [
                "statut" => "erreur", 
                "message" => "Colonnes manquantes dans le fichier: " . implode(", ", $colonnesManquantes)
            ];
        }
        
        $db->beginTransaction();
        
        // Compteurs pour le suivi
        $nbClasses = 0;
        $nbEleves = 0;
        $nbTranches = 0;
        $nbIgnores = 0;
        $nbNonAttribuees = 0;
        
        // Vérifier si les billets ont été initialisés
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM billets WHERE id_annee = ?");
        $stmt->execute([$idAnnee]);
        $billetsExistants = $stmt->fetch()['total'] > 0;
        
        if (!$billetsExistants) {
            // Initialiser les billets si nécessaire
            $resultatInit = initialiserBillets($idAnnee);
            if ($resultatInit['statut'] !== 'success') {
                $db->rollBack();
                return $resultatInit;
            }
        }
        
        // Tableau pour suivre les tranches déjà traitées
        $tranchesTraitees = [];
        
        // Parcourir les lignes du fichier
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
            
            if ($isEmpty) {
                continue;
            }
            
            // Extraire les données
            $nomClasse = isset($colonnes['classe']) ? trim($rowData[$colonnes['classe']]) : '';
            $nom = isset($colonnes['nom']) ? trim($rowData[$colonnes['nom']]) : '';
            $prenom = isset($colonnes['prenom']) ? trim($rowData[$colonnes['prenom']]) : '';
            $debut = isset($colonnes['debut']) ? intval($rowData[$colonnes['debut']]) : 0;
            $fin = isset($colonnes['fin']) ? intval($rowData[$colonnes['fin']]) : 0;
            
            // Valider les données de base
            if (empty($nomClasse) || empty($nom) || empty($prenom) || $debut <= 0 || $fin <= 0 || $debut > $fin) {
                continue;
            }
            
            // Identifier la classe ou la créer
            $idClasse = null;
            
            // Déduire le niveau à partir du nom de la classe
            $niveau = '';
            if (preg_match('/^(PS|MS|GS|CP|CE[12]|CM[12])/', $nomClasse, $matches)) {
                $niveau = $matches[1];
            }
            
            // Vérifier si la classe existe déjà
            $stmt = $db->prepare("SELECT id_classe FROM classes WHERE nom = ? AND id_annee = ?");
            $stmt->execute([$nomClasse, $idAnnee]);
            $classeExistante = $stmt->fetch();
            
            if ($classeExistante) {
                $idClasse = $classeExistante['id_classe'];
            } else {
                // Créer la classe
                $stmt = $db->prepare("INSERT INTO classes (nom, niveau, id_annee) VALUES (?, ?, ?)");
                $stmt->execute([$nomClasse, $niveau, $idAnnee]);
                $idClasse = $db->lastInsertId();
                $nbClasses++;
            }
            
            // Identifier l'élève ou le créer
            $idEleve = null;
            
            // Vérifier si l'élève existe déjà
            $stmt = $db->prepare("
                SELECT id_eleve 
                FROM eleves 
                WHERE nom = ? AND prenom = ? AND id_classe = ? AND id_annee = ?
            ");
            $stmt->execute([$nom, $prenom, $idClasse, $idAnnee]);
            $eleveExistant = $stmt->fetch();
            
            if ($eleveExistant) {
                $idEleve = $eleveExistant['id_eleve'];
            } else {
                // Créer l'élève
                $stmt = $db->prepare("
                    INSERT INTO eleves (nom, prenom, id_classe, id_annee) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$nom, $prenom, $idClasse, $idAnnee]);
                $idEleve = $db->lastInsertId();
                $nbEleves++;
            }
            
            // Clé unique pour la tranche
            $trancheKey = "$debut-$fin-$idAnnee";
            
            // Vérifier si nous avons déjà traité cette tranche
            if (isset($tranchesTraitees[$trancheKey])) {
                $nbIgnores++;
                continue;
            }
            
            // Marquer cette tranche comme traitée
            $tranchesTraitees[$trancheKey] = true;
            
            // ÉTAPE 1: Vérifier si la tranche existe déjà
            $stmt = $db->prepare("
                SELECT id_tranche, id_eleve, date_attribution
                FROM tranches 
                WHERE numero_debut = ? AND numero_fin = ? AND id_annee = ?
            ");
            $stmt->execute([$debut, $fin, $idAnnee]);
            $trancheExistante = $stmt->fetch();
            
            // ÉTAPE 2: Traiter selon que la tranche existe ou non
            if ($trancheExistante) {
                // Si la tranche existe mais n'est pas attribuée
                if ($trancheExistante['id_eleve'] === null) {
                    // Attribuer la tranche à l'élève
                    $stmt = $db->prepare("
                        UPDATE tranches 
                        SET id_eleve = ?, date_attribution = NOW() 
                        WHERE id_tranche = ?
                    ");
                    $stmt->execute([$idEleve, $trancheExistante['id_tranche']]);
                    
                    // Mettre à jour le statut des billets
                    $stmt = $db->prepare("
                        UPDATE billets 
                        SET statut = 'attribue' 
                        WHERE id_tranche = ? AND statut = 'disponible'
                    ");
                    $stmt->execute([$trancheExistante['id_tranche']]);
                    
                    $idTranche = $trancheExistante['id_tranche'];
                    $nbTranches++;
                }
                // Si la tranche est déjà attribuée au même élève, on peut traiter les billets vendus
                else if ($trancheExistante['id_eleve'] == $idEleve) {
                    $idTranche = $trancheExistante['id_tranche'];
                } 
                // Si la tranche est attribuée à un autre élève, on l'ignore
                else {
                    $nbIgnores++;
                    continue;
                }
            } else {
                // Vérifier s'il y a chevauchement avec une tranche existante
                $stmt = $db->prepare("
                    SELECT COUNT(*) as nb_chevauchement
                    FROM tranches 
                    WHERE ((numero_debut <= ? AND numero_fin >= ?) OR
                           (numero_debut <= ? AND numero_fin >= ?) OR
                           (? <= numero_debut AND ? >= numero_fin))
                          AND id_annee = ?
                ");
                $stmt->execute([$debut, $debut, $fin, $fin, $debut, $fin, $idAnnee]);
                $chevauchement = $stmt->fetch()['nb_chevauchement'] > 0;
                
                if ($chevauchement) {
                    $nbIgnores++;
                    continue;
                }
                
                // Insérer la nouvelle tranche
                try {
                    $stmt = $db->prepare("
                        INSERT INTO tranches (numero_debut, numero_fin, id_eleve, date_attribution, id_annee) 
                        VALUES (?, ?, ?, NOW(), ?)
                    ");
                    $stmt->execute([$debut, $fin, $idEleve, $idAnnee]);
                    $idTranche = $db->lastInsertId();
                    
                    // Mettre à jour les billets
                    $stmt = $db->prepare("
                        UPDATE billets 
                        SET id_tranche = ?, statut = 'attribue' 
                        WHERE numero >= ? AND numero <= ? AND id_annee = ? AND statut = 'disponible'
                    ");
                    $stmt->execute([$idTranche, $debut, $fin, $idAnnee]);
                    
                    $nbTranches++;
                } catch (Exception $e) {
                    // Si c'est une erreur de doublon, ignorer
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                        $nbIgnores++;
                        continue;
                    } else {
                        throw $e;
                    }
                }
            }
            
            // ÉTAPE 3: Traiter les billets vendus si l'information est disponible
            $billetsVendus = 0;
            
            if (isset($colonnes['vendus']) && !empty($rowData[$colonnes['vendus']])) {
                $billetsVendus = intval($rowData[$colonnes['vendus']]);
            } else if (isset($colonnes['montant']) && !empty($rowData[$colonnes['montant']])) {
                $montant = floatval(str_replace(['€', ','], ['', '.'], $rowData[$colonnes['montant']]));
                $billetsVendus = round($montant / PRIX_BILLET_DEFAUT);
            }
            
            if ($billetsVendus > 0) {
                $totalBillets = $fin - $debut + 1;
                $billetsRetournes = $totalBillets - $billetsVendus;
                
                if ($billetsRetournes < 0) {
                    $billetsRetournes = 0;
                    $billetsVendus = $totalBillets;
                }
                
                // Enregistrer les ventes
                $stmt = $db->prepare("
                    INSERT INTO ventes (id_eleve, id_annee, billets_vendus, billets_retournes, montant_total) 
                    VALUES (?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE 
                        billets_vendus = billets_vendus + ?, 
                        billets_retournes = billets_retournes + ?,
                        montant_total = montant_total + ?
                ");
                $montantTotal = $billetsVendus * PRIX_BILLET_DEFAUT;
                $stmt->execute([
                    $idEleve, $idAnnee, $billetsVendus, $billetsRetournes, $montantTotal,
                    $billetsVendus, $billetsRetournes, $montantTotal
                ]);
                
                // Marquer les billets comme vendus
                if ($billetsVendus > 0) {
                    $stmt = $db->prepare("
                        UPDATE billets 
                        SET statut = 'vendu' 
                        WHERE id_tranche = ? AND statut = 'attribue' 
                        ORDER BY numero 
                        LIMIT ?
                    ");
                    $stmt->execute([$idTranche, $billetsVendus]);
                }
                
                // Marquer les billets restants comme retournés
                if ($billetsRetournes > 0) {
                    $stmt = $db->prepare("
                        UPDATE billets 
                        SET statut = 'retourne' 
                        WHERE id_tranche = ? AND statut = 'attribue'
                    ");
                    $stmt->execute([$idTranche]);
                    
                    // Mettre à jour la date de retour de la tranche
                    $stmt = $db->prepare("
                        UPDATE tranches 
                        SET date_retour = NOW() 
                        WHERE id_tranche = ? AND date_retour IS NULL
                    ");
                    $stmt->execute([$idTranche]);
                }
            }
        }
        
        $db->commit();
        
        // Vérifier s'il reste des tranches non attribuées
        $stmt = $db->prepare("
            SELECT COUNT(*) as total 
            FROM tranches 
            WHERE id_eleve IS NULL AND id_annee = ?
        ");
        $stmt->execute([$idAnnee]);
        $nbNonAttribuees = $stmt->fetch()['total'];
        
        $messageTranches = $nbTranches > 0 
            ? "$nbTranches tranches de billets attribuées" 
            : "Aucune tranche de billets attribuée";
            
        $messageIgnores = $nbIgnores > 0 
            ? " ($nbIgnores entrées ignorées car en double ou en conflit)" 
            : "";
            
        $messageNonAttribuees = $nbNonAttribuees > 0 
            ? " Il reste encore $nbNonAttribuees tranches non attribuées." 
            : "";
        
        return [
            "statut" => "success", 
            "message" => "Importation réussie : $nbClasses classes, $nbEleves élèves, $messageTranches$messageIgnores.$messageNonAttribuees"
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idAnnee = getAnneeActive(connectDB());
    
    if (!$idAnnee) {
        $resultat = ["statut" => "erreur", "message" => "Aucune année scolaire active."];
    } else if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['fichier']['tmp_name'];
        $fileName = $_FILES['fichier']['name'];
        
        // Créer le dossier uploads s'il n'existe pas
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $filePath = $uploadDir . $fileName;
        if (move_uploaded_file($tmpName, $filePath)) {
            $resultat = importerEtAttribuer($filePath, $idAnnee);
            
            // Supprimer le fichier après traitement
            unlink($filePath);
        } else {
            $resultat = ["statut" => "erreur", "message" => "Erreur lors du téléchargement du fichier."];
        }
    } else if (isset($_FILES['fichier'])) {
        $erreurs = [
            UPLOAD_ERR_INI_SIZE => "Le fichier dépasse la taille maximale autorisée par PHP.",
            UPLOAD_ERR_FORM_SIZE => "Le fichier dépasse la taille maximale autorisée par le formulaire.",
            UPLOAD_ERR_PARTIAL => "Le fichier n'a été que partiellement téléchargé.",
            UPLOAD_ERR_NO_FILE => "Aucun fichier n'a été téléchargé.",
            UPLOAD_ERR_NO_TMP_DIR => "Dossier temporaire manquant.",
            UPLOAD_ERR_CANT_WRITE => "Échec de l'écriture du fichier sur le disque.",
            UPLOAD_ERR_EXTENSION => "Une extension PHP a arrêté le téléchargement du fichier."
        ];
        
        $errMsg = isset($erreurs[$_FILES['fichier']['error']]) 
            ? $erreurs[$_FILES['fichier']['error']] 
            : "Erreur inconnue lors du téléchargement.";
            
        $resultat = ["statut" => "erreur", "message" => $errMsg];
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importation Unifiée - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container my-4">
        <h2>Importation Unifiée</h2>
        
        <?php
        $db = connectDB();
        $idAnnee = getAnneeActive($db);
        
        if (!$idAnnee) {
            echo '<div class="alert alert-warning">Aucune année scolaire active. Veuillez en créer une dans la section Administration.</div>';
        } else {
            // Récupérer l'année active
            $stmt = $db->prepare("SELECT libelle FROM annees WHERE id_annee = ?");
            $stmt->execute([$idAnnee]);
            $anneeActive = $stmt->fetch();
            
            if (isset($resultat)) {
                $alertClass = $resultat['statut'] === 'success' ? 'success' : 'danger';
                echo "<div class='alert alert-{$alertClass}'>{$resultat['message']}</div>";
            }
        ?>
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3>Importer depuis un fichier Excel</h3>
                        </div>
                        <div class="card-body">
                            <p>Année scolaire active : <strong><?= $anneeActive['libelle'] ?></strong></p>
                            
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
                                        <li>Optionnel: Nombre de billets vendus ou Montant</li>
                                    </ul>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">Importer et Attribuer</button>
                            </form>
                            
                            <div class="alert alert-warning mt-3">
                                <p><strong>Important :</strong> Ce formulaire permet à la fois :</p>
                                <ul>
                                    <li>D'importer les données des classes et des élèves</li>
                                    <li>D'attribuer automatiquement les tranches de billets aux élèves</li>
                                    <li>D'enregistrer les billets vendus si cette information est présente</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3>État actuel</h3>
                        </div>
                        <div class="card-body">
                            <?php
                            // Récupérer les statistiques
                            $stmt = $db->prepare("SELECT COUNT(*) as total FROM classes WHERE id_annee = ?");
                            $stmt->execute([$idAnnee]);
                            $nbClasses = $stmt->fetch()['total'];
                            
                            $stmt = $db->prepare("SELECT COUNT(*) as total FROM eleves WHERE id_annee = ?");
                            $stmt->execute([$idAnnee]);
                            $nbEleves = $stmt->fetch()['total'];
                            
                            $stmt = $db->prepare("SELECT COUNT(*) as total FROM tranches WHERE id_annee = ?");
                            $stmt->execute([$idAnnee]);
                            $nbTranches = $stmt->fetch()['total'];
                            
                            $stmt = $db->prepare("SELECT COUNT(*) as total FROM tranches WHERE id_eleve IS NOT NULL AND id_annee = ?");
                            $stmt->execute([$idAnnee]);
                            $nbTranchesAttribuees = $stmt->fetch()['total'];
                            
                            $stmt = $db->prepare("
                                SELECT 
                                    COUNT(*) as total,
                                    SUM(CASE WHEN statut = 'vendu' THEN 1 ELSE 0 END) as vendus,
                                    SUM(CASE WHEN statut = 'retourne' THEN 1 ELSE 0 END) as retournes,
                                    SUM(CASE WHEN statut = 'attribue' THEN 1 ELSE 0 END) as attribues,
                                    SUM(CASE WHEN statut = 'disponible' THEN 1 ELSE 0 END) as disponibles
                                FROM billets
                                WHERE id_annee = ?
                            ");
                            $stmt->execute([$idAnnee]);
                            $billets = $stmt->fetch();
                            ?>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="card bg-info text-white">
                                        <div class="card-body">
                                            <h5 class="card-title">Classes</h5>
                                            <p class="card-text display-4"><?= $nbClasses ?></p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="card bg-primary text-white">
                                        <div class="card-body">
                                            <h5 class="card-title">Élèves</h5>
                                            <p class="card-text display-4"><?= $nbEleves ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="card bg-success text-white">
                                        <div class="card-body">
                                            <h5 class="card-title">Tranches attribuées</h5>
                                            <p class="card-text display-4"><?= $nbTranchesAttribuees ?> / <?= $nbTranches ?></p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="card bg-warning text-dark">
                                        <div class="card-body">
                                            <h5 class="card-title">Billets vendus</h5>
                                            <p class="card-text display-4"><?= $billets['vendus'] ?? 0 ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($nbClasses > 0): ?>
                                <div class="alert alert-success">
                                    Les données sont déjà importées pour cette année scolaire. 
                                    Une nouvelle importation ajoutera uniquement les classes, élèves et tranches qui n'existent pas encore.
                                </div>
                                <div class="btn-group w-100">
                                    <a href="eleves.php" class="btn btn-outline-primary">Voir les élèves</a>
                                    <a href="stats.php" class="btn btn-outline-success">Voir les statistiques</a>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    Aucune donnée n'a été importée pour cette année scolaire.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php
        }
        ?>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>