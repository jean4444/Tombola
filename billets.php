<?php
// billets.php - Fonctions de gestion des billets et tranches

require_once 'config.php';

// Initialiser les billets pour une nouvelle année scolaire
function initialiserBillets($idAnnee) {
    $db = connectDB();
    
    try {
        $db->beginTransaction();
        
        // Vérifier si des billets existent déjà pour cette année
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM billets WHERE id_annee = ?");
        $stmt->execute([$idAnnee]);
        $count = $stmt->fetch()['total'];
        
        if ($count > 0) {
            $db->rollBack();
            return ["statut" => "erreur", "message" => "Des billets existent déjà pour cette année."];
        }
        
        // Créer les tranches de 10 billets
        $tranches = [];
        for ($i = BILLETS_MIN; $i <= BILLETS_MAX; $i += TAILLE_TRANCHE) {
            $debut = $i;
            $fin = min($i + TAILLE_TRANCHE - 1, BILLETS_MAX);
            
            $stmt = $db->prepare("INSERT INTO tranches (numero_debut, numero_fin, id_annee) VALUES (?, ?, ?)");
            $stmt->execute([$debut, $fin, $idAnnee]);
            $idTranche = $db->lastInsertId();
            $tranches[] = ["debut" => $debut, "fin" => $fin, "id" => $idTranche];
        }
        
        // Créer les billets individuels
        $stmt = $db->prepare("INSERT INTO billets (numero, id_tranche, prix_unitaire, id_annee) VALUES (?, ?, ?, ?)");
        
        foreach ($tranches as $tranche) {
            for ($num = $tranche["debut"]; $num <= $tranche["fin"]; $num++) {
                $stmt->execute([
                    $num, 
                    $tranche["id"], 
                    PRIX_BILLET_DEFAUT, 
                    $idAnnee
                ]);
            }
        }
        
        $db->commit();
        return [
            "statut" => "success", 
            "message" => "Billets initialisés avec succès pour l'année scolaire."
        ];
    } catch (Exception $e) {
        $db->rollBack();
        return ["statut" => "erreur", "message" => "Erreur: " . $e->getMessage()];
    }
}

// Attribuer une tranche à un élève
function attribuerTranche($idEleve, $idTranche) {
    $db = connectDB();
    
    try {
        $db->beginTransaction();
        
        // Vérifier si la tranche est disponible
        $stmt = $db->prepare("SELECT id_eleve FROM tranches WHERE id_tranche = ?");
        $stmt->execute([$idTranche]);
        $tranche = $stmt->fetch();
        
        if ($tranche && $tranche['id_eleve'] !== null) {
            $db->rollBack();
            return ["statut" => "erreur", "message" => "Cette tranche est déjà attribuée."];
        }
        
        // Attribuer la tranche
        $stmt = $db->prepare("UPDATE tranches SET id_eleve = ?, date_attribution = NOW() WHERE id_tranche = ?");
        $stmt->execute([$idEleve, $idTranche]);
        
        // Mettre à jour le statut des billets
        $stmt = $db->prepare("UPDATE billets SET statut = 'attribue' WHERE id_tranche = ?");
        $stmt->execute([$idTranche]);
        
        // Récupérer l'année de l'élève
        $stmt = $db->prepare("SELECT id_annee FROM eleves WHERE id_eleve = ?");
        $stmt->execute([$idEleve]);
        $eleve = $stmt->fetch();
        
        // Créer ou mettre à jour l'entrée dans la table ventes
        $stmt = $db->prepare("
            INSERT INTO ventes (id_eleve, id_annee) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE date_mise_a_jour = NOW()
        ");
        $stmt->execute([$idEleve, $eleve['id_annee']]);
        
        $db->commit();
        return [
            "statut" => "success", 
            "message" => "Tranche attribuée avec succès à l'élève."
        ];
    } catch (Exception $e) {
        $db->rollBack();
        return ["statut" => "erreur", "message" => "Erreur: " . $e->getMessage()];
    }
}

// Enregistrer le retour de billets
function enregistrerRetour($idEleve, $idTranche, $billetsVendus) {
    $db = connectDB();
    
    try {
        $db->beginTransaction();
        
        // Vérifier si la tranche appartient à l'élève
        $stmt = $db->prepare("
            SELECT t.id_tranche, t.numero_debut, t.numero_fin, t.id_annee
            FROM tranches t 
            WHERE t.id_tranche = ? AND t.id_eleve = ?
        ");
        $stmt->execute([$idTranche, $idEleve]);
        $tranche = $stmt->fetch();
        
        if (!$tranche) {
            $db->rollBack();
            return ["statut" => "erreur", "message" => "Cette tranche n'est pas attribuée à cet élève."];
        }
        
        // Vérifier le nombre total de billets dans la tranche
        $totalBillets = $tranche['numero_fin'] - $tranche['numero_debut'] + 1;
        
        // Vérifier que le nombre de billets vendus est cohérent
        if ($billetsVendus < 0 || $billetsVendus > $totalBillets) {
            $db->rollBack();
            return ["statut" => "erreur", "message" => "Nombre de billets vendus incorrect."];
        }
        
        // Vérifier combien de billets sont déjà vendus ou retournés
        $stmt = $db->prepare("
            SELECT 
                SUM(CASE WHEN statut = 'vendu' THEN 1 ELSE 0 END) as billets_vendus,
                SUM(CASE WHEN statut = 'retourne' THEN 1 ELSE 0 END) as billets_retournes
            FROM billets
            WHERE id_tranche = ?
        ");
        $stmt->execute([$idTranche]);
        $statutBillets = $stmt->fetch();
        
        $billetsDejaVendus = $statutBillets['billets_vendus'] ?? 0;
        $billetsDejaRetournes = $statutBillets['billets_retournes'] ?? 0;
        
        // Calcul des billets restants
        $billetsDisponibles = $totalBillets - $billetsDejaVendus - $billetsDejaRetournes;
        
        // Vérifier que le nombre de billets vendus ne dépasse pas les billets disponibles
        if ($billetsVendus > $billetsDisponibles) {
            $db->rollBack();
            return [
                "statut" => "erreur", 
                "message" => "Impossible de vendre $billetsVendus billets. Seulement $billetsDisponibles billets disponibles."
            ];
        }
        
        // Calculer le nombre de billets retournés
        $billetsRetournes = $totalBillets - $billetsVendus;
        
        // Mettre à jour la tranche
        $stmt = $db->prepare("UPDATE tranches SET date_retour = NOW() WHERE id_tranche = ?");
        $stmt->execute([$idTranche]);
        
        // Obtenir le prix unitaire du billet
        $stmt = $db->prepare("SELECT prix_unitaire FROM billets WHERE id_tranche = ? LIMIT 1");
        $stmt->execute([$idTranche]);
        $billet = $stmt->fetch();
        $prixUnitaire = $billet ? $billet['prix_unitaire'] : PRIX_BILLET_DEFAUT;
        
        // Mettre à jour les billets vendus
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
        
        // Mettre à jour les billets retournés
        if ($billetsRetournes > 0) {
            $stmt = $db->prepare("
                UPDATE billets 
                SET statut = 'retourne' 
                WHERE id_tranche = ? AND statut = 'attribue'
            ");
            $stmt->execute([$idTranche]);
        }
        
        // Calculer le montant total
        $montantTotal = $billetsVendus * $prixUnitaire;
        
        // Mettre à jour les ventes
        $stmt = $db->prepare("
            INSERT INTO ventes 
            (id_eleve, id_annee, billets_vendus, billets_retournes, montant_total, date_vente) 
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                billets_vendus = billets_vendus + ?,
                billets_retournes = billets_retournes + ?,
                montant_total = montant_total + ?,
                date_mise_a_jour = NOW()
        ");
        $stmt->execute([
            $idEleve, 
            $tranche['id_annee'], 
            $billetsVendus, 
            $billetsRetournes, 
            $montantTotal,
            $billetsVendus,
            $billetsRetournes,
            $montantTotal
        ]);
        
        $db->commit();
        return [
            "statut" => "success", 
            "message" => "Retour enregistré: $billetsVendus billets vendus, $billetsRetournes billets retournés, total: $montantTotal €"
        ];
    } catch (Exception $e) {
        $db->rollBack();
        return ["statut" => "erreur", "message" => "Erreur: " . $e->getMessage()];
    }
}
// Si ce fichier est appelé directement (et non inclus), afficher l'interface utilisateur
if (basename($_SERVER['SCRIPT_NAME']) === basename(__FILE__)) {
    // Vérifier si l'utilisateur est connecté
    if (function_exists('redirigerSiNonConnecte')) {
        redirigerSiNonConnecte();
    }
    
    $db = connectDB();
    $idAnnee = getAnneeActive($db);
    
    // Traitement des actions
    if (isset($_GET['action'])) {
        switch ($_GET['action']) {
            case 'initialiser':
                $resultat = initialiserBillets($idAnnee);
                break;
        }
    }
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Billets - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container my-4">
        <h2>Gestion des Billets</h2>
        
        <?php if (!$idAnnee): ?>
            <div class="alert alert-warning">Aucune année scolaire active. Veuillez en créer une dans la section Administration.</div>
        <?php else: ?>
            
            <?php if (isset($resultat)): ?>
                <div class="alert alert-<?= $resultat['statut'] === 'success' ? 'success' : 'danger' ?>">
                    <?= $resultat['message'] ?>
                </div>
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-12 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h3>Actions globales</h3>
                        </div>
                        <div class="card-body">
                            <a href="?action=initialiser" class="btn btn-warning" onclick="return confirm('Êtes-vous sûr de vouloir initialiser les billets ? Cette action ne peut pas être annulée.')">
                                Initialiser les billets
                            </a>
                            <p class="mt-2 text-muted">Cette action crée <?= BILLETS_MAX ?> billets numérotés de <?= sprintf('%04d', BILLETS_MIN) ?> à <?= sprintf('%04d', BILLETS_MAX) ?>.</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <h3>État des billets</h3>
                        </div>
                        <div class="card-body">
                            <?php
                            // Récupérer l'état des billets
                            $stmt = $db->prepare("
                                SELECT 
                                    COUNT(*) as total,
                                    SUM(CASE WHEN statut = 'disponible' THEN 1 ELSE 0 END) as disponibles,
                                    SUM(CASE WHEN statut = 'attribue' THEN 1 ELSE 0 END) as attribues,
                                    SUM(CASE WHEN statut = 'vendu' THEN 1 ELSE 0 END) as vendus,
                                    SUM(CASE WHEN statut = 'retourne' THEN 1 ELSE 0 END) as retournes
                                FROM billets
                                WHERE id_annee = ?
                            ");
                            $stmt->execute([$idAnnee]);
                            $stats = $stmt->fetch();
                            
                            if ($stats && $stats['total'] > 0) {
                                $pcDisponibles = round(($stats['disponibles'] / $stats['total']) * 100);
                                $pcAttribues = round(($stats['attribues'] / $stats['total']) * 100);
                                $pcVendus = round(($stats['vendus'] / $stats['total']) * 100);
                                $pcRetournes = round(($stats['retournes'] / $stats['total']) * 100);
                            ?>
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table">
                                            <tr>
                                                <th>Total des billets</th>
                                                <td><?= $stats['total'] ?></td>
                                            </tr>
                                            <tr>
                                                <th>Billets disponibles</th>
                                                <td><?= $stats['disponibles'] ?> (<?= $pcDisponibles ?>%)</td>
                                            </tr>
                                            <tr>
                                                <th>Billets attribués</th>
                                                <td><?= $stats['attribues'] ?> (<?= $pcAttribues ?>%)</td>
                                            </tr>
                                            <tr>
                                                <th>Billets vendus</th>
                                                <td><?= $stats['vendus'] ?> (<?= $pcVendus ?>%)</td>
                                            </tr>
                                            <tr>
                                                <th>Billets retournés</th>
                                                <td><?= $stats['retournes'] ?> (<?= $pcRetournes ?>%)</td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <h4>Progression</h4>
                                        <div class="progress mb-3">
                                            <div class="progress-bar bg-success" style="width: <?= $pcVendus ?>%" title="Vendus">
                                                <?= $pcVendus ?>%
                                            </div>
                                            <div class="progress-bar bg-warning" style="width: <?= $pcAttribues ?>%" title="Attribués">
                                                <?= $pcAttribues ?>%
                                            </div>
                                            <div class="progress-bar bg-secondary" style="width: <?= $pcRetournes ?>%" title="Retournés">
                                                <?= $pcRetournes ?>%
                                            </div>
                                        </div>
                                        <div class="mt-3">
                                            <span class="badge bg-success">Vendus</span>
                                            <span class="badge bg-warning">Attribués</span>
                                            <span class="badge bg-secondary">Retournés</span>
                                            <span class="badge bg-light text-dark">Disponibles</span>
                                        </div>
                                    </div>
                                </div>
                            <?php
                            } else {
                                echo '<div class="alert alert-info">Aucun billet n\'a été créé pour cette année scolaire. Utilisez le bouton "Initialiser les billets" pour les créer.</div>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}
?>