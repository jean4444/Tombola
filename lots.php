<?php
// lots.php - Gestion des lots de la tombola

require_once 'config.php';

// Ajouter un lot
function ajouterLot($nom, $description, $valeur, $quantite, $idAnnee) {
    $db = connectDB();
    
    try {
        $stmt = $db->prepare("
            INSERT INTO lots (nom, description, valeur, quantite, id_annee) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$nom, $description, $valeur, $quantite, $idAnnee]);
        
        return [
            "statut" => "success", 
            "message" => "Lot ajouté avec succès.",
            "id_lot" => $db->lastInsertId()
        ];
    } catch (Exception $e) {
        return ["statut" => "erreur", "message" => "Erreur: " . $e->getMessage()];
    }
}

// Modifier un lot
function modifierLot($idLot, $nom, $description, $valeur, $quantite) {
    $db = connectDB();
    
    try {
        $stmt = $db->prepare("
            UPDATE lots 
            SET nom = ?, description = ?, valeur = ?, quantite = ? 
            WHERE id_lot = ?
        ");
        $stmt->execute([$nom, $description, $valeur, $quantite, $idLot]);
        
        return [
            "statut" => "success", 
            "message" => "Lot modifié avec succès."
        ];
    } catch (Exception $e) {
        return ["statut" => "erreur", "message" => "Erreur: " . $e->getMessage()];
    }
}

// Supprimer un lot
function supprimerLot($idLot) {
    $db = connectDB();
    
    try {
        $stmt = $db->prepare("DELETE FROM lots WHERE id_lot = ?");
        $stmt->execute([$idLot]);
        
        return [
            "statut" => "success", 
            "message" => "Lot supprimé avec succès."
        ];
    } catch (Exception $e) {
        return ["statut" => "erreur", "message" => "Erreur: " . $e->getMessage()];
    }
}

// Obtenir le total des coûts des lots pour une année
function getTotalCoutsLots($idAnnee) {
    $db = connectDB();
    
    $stmt = $db->prepare("
        SELECT SUM(valeur * quantite) as cout_total 
        FROM lots 
        WHERE id_annee = ?
    ");
    $stmt->execute([$idAnnee]);
    $result = $stmt->fetch();
    
    return $result['cout_total'] ?? 0;
}

// Obtenir les lots pour une année
function getLots($idAnnee) {
    $db = connectDB();
    
    $stmt = $db->prepare("
        SELECT id_lot, nom, description, valeur, quantite, (valeur * quantite) as cout_total 
        FROM lots 
        WHERE id_annee = ? 
        ORDER BY valeur DESC
    ");
    $stmt->execute([$idAnnee]);
    
    return $stmt->fetchAll();
}
// Si ce fichier est appelé directement (et non inclus), afficher l'interface utilisateur
if (basename($_SERVER['SCRIPT_NAME']) === basename(__FILE__)) {
    require_once 'auth.php';
    
    // Vérifier si l'utilisateur est connecté
    if (function_exists('redirigerSiNonConnecte')) {
        redirigerSiNonConnecte();
    }
    
    $db = connectDB();
    $idAnnee = getAnneeActive($db);
    
    // Traitement du formulaire d'ajout/modification
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'ajouter':
                    if (isset($_POST['nom']) && isset($_POST['valeur']) && isset($_POST['quantite'])) {
                        $resultat = ajouterLot(
                            $_POST['nom'],
                            $_POST['description'] ?? '',
                            (float)$_POST['valeur'],
                            (int)$_POST['quantite'],
                            $idAnnee
                        );
                    }
                    break;
                    
                case 'modifier':
                    if (isset($_POST['id_lot']) && isset($_POST['nom']) && isset($_POST['valeur']) && isset($_POST['quantite'])) {
                        $resultat = modifierLot(
                            (int)$_POST['id_lot'],
                            $_POST['nom'],
                            $_POST['description'] ?? '',
                            (float)$_POST['valeur'],
                            (int)$_POST['quantite']
                        );
                    }
                    break;
                    
                case 'supprimer':
                    if (isset($_POST['id_lot'])) {
                        $resultat = supprimerLot((int)$_POST['id_lot']);
                    }
                    break;
            }
        }
    }
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Lots - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container my-4">
        <h2>Gestion des Lots</h2>
        
        <?php if (!$idAnnee): ?>
            <div class="alert alert-warning">Aucune année scolaire active.</div>
        <?php else: ?>
            
            <?php if (isset($resultat)): ?>
                <div class="alert alert-<?= $resultat['statut'] === 'success' ? 'success' : 'danger' ?>">
                    <?= $resultat['message'] ?>
                </div>
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-7">
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h3>Liste des lots</h3>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLotModal">
                                Ajouter un lot
                            </button>
                        </div>
                        <div class="card-body">
                            <?php
                            $lots = getLots($idAnnee);
                            $totalCout = 0;
                            
                            if (!empty($lots)) {
                            ?>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Lot</th>
                                                <th>Description</th>
                                                <th>Valeur unitaire</th>
                                                <th>Quantité</th>
                                                <th>Coût total</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            foreach ($lots as $lot): 
                                                $totalCout += $lot['cout_total'];
                                            ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($lot['nom']) ?></td>
                                                    <td><?= htmlspecialchars($lot['description']) ?></td>
                                                    <td><?= number_format($lot['valeur'], 2, ',', ' ') ?> €</td>
                                                    <td><?= $lot['quantite'] ?></td>
                                                    <td><?= number_format($lot['cout_total'], 2, ',', ' ') ?> €</td>
                                                    <td>
                                                        <button class="btn btn-sm btn-info" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#editLotModal<?= $lot['id_lot'] ?>">
                                                            Modifier
                                                        </button>
                                                        
                                                        <form action="" method="post" class="d-inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce lot?')">
                                                            <input type="hidden" name="action" value="supprimer">
                                                            <input type="hidden" name="id_lot" value="<?= $lot['id_lot'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-danger">Supprimer</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                                
                                                <!-- Modal de modification -->
                                                <div class="modal fade" id="editLotModal<?= $lot['id_lot'] ?>" tabindex="-1">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Modifier un lot</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <form action="" method="post">
                                                                    <input type="hidden" name="action" value="modifier">
                                                                    <input type="hidden" name="id_lot" value="<?= $lot['id_lot'] ?>">
                                                                    
                                                                    <div class="mb-3">
                                                                        <label for="nom<?= $lot['id_lot'] ?>" class="form-label">Nom du lot</label>
                                                                        <input type="text" class="form-control" id="nom<?= $lot['id_lot'] ?>" name="nom" value="<?= htmlspecialchars($lot['nom']) ?>" required>
                                                                    </div>
                                                                    
                                                                    <div class="mb-3">
                                                                        <label for="description<?= $lot['id_lot'] ?>" class="form-label">Description</label>
                                                                        <textarea class="form-control" id="description<?= $lot['id_lot'] ?>" name="description" rows="3"><?= htmlspecialchars($lot['description']) ?></textarea>
                                                                    </div>
                                                                    
                                                                    <div class="mb-3">
                                                                        <label for="valeur<?= $lot['id_lot'] ?>" class="form-label">Valeur unitaire (€)</label>
                                                                        <input type="number" class="form-control" id="valeur<?= $lot['id_lot'] ?>" name="valeur" step="0.01" value="<?= $lot['valeur'] ?>" required>
                                                                    </div>
                                                                    
                                                                    <div class="mb-3">
                                                                        <label for="quantite<?= $lot['id_lot'] ?>" class="form-label">Quantité</label>
                                                                        <input type="number" class="form-control" id="quantite<?= $lot['id_lot'] ?>" name="quantite" min="1" value="<?= $lot['quantite'] ?>" required>
                                                                    </div>
                                                                    
                                                                    <button type="submit" class="btn btn-primary">Enregistrer les modifications</button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            
                                            <tr class="table-info">
                                                <th colspan="4">Coût total des lots</th>
                                                <th><?= number_format($totalCout, 2, ',', ' ') ?> €</th>
                                                <td></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            <?php
                            } else {
                                echo '<div class="alert alert-info">Aucun lot n\'a été ajouté pour cette année scolaire.</div>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-5">
                    <div class="card">
                        <div class="card-header">
                            <h3>Résumé financier</h3>
                        </div>
                        <div class="card-body">
                            <?php
                            // Récupérer les données financières
                            $stmt = $db->prepare("
                                SELECT SUM(montant_total) as recette_totale
                                FROM ventes 
                                WHERE id_annee = ?
                            ");
                            $stmt->execute([$idAnnee]);
                            $recette = $stmt->fetch()['recette_totale'] ?? 0;
                            
                            $benefice = $recette - $totalCout;
                            ?>
                            
                            <table class="table table-striped">
                                <tr>
                                    <th>Recette totale (billets vendus)</th>
                                    <td><?= number_format($recette, 2, ',', ' ') ?> €</td>
                                </tr>
                                <tr>
                                    <th>Coût total des lots</th>
                                    <td><?= number_format($totalCout, 2, ',', ' ') ?> €</td>
                                </tr>
                                <tr class="table-<?= $benefice >= 0 ? 'success' : 'danger' ?>">
                                    <th>Bénéfice</th>
                                    <td><?= number_format($benefice, 2, ',', ' ') ?> €</td>
                                </tr>
                            </table>
                            
                            <div class="progress mt-3">
                                <?php 
                                $pcRecette = $totalCout > 0 ? min(100, ($recette / $totalCout) * 100) : 100;
                                ?>
                                <div class="progress-bar bg-success" style="width: <?= $pcRecette ?>%">
                                    <?= round($pcRecette) ?>% de l'objectif
                                </div>
                            </div>
                            
                            <div class="alert alert-info mt-3">
                                <p>L'objectif minimum est de couvrir le coût total des lots.</p>
                                <?php if ($benefice < 0): ?>
                                    <p>Il manque encore <strong><?= number_format(abs($benefice), 2, ',', ' ') ?> €</strong> pour atteindre l'objectif.</p>
                                <?php else: ?>
                                    <p>L'objectif est atteint avec un bénéfice de <strong><?= number_format($benefice, 2, ',', ' ') ?> €</strong>.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Modal d'ajout de lot -->
            <div class="modal fade" id="addLotModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Ajouter un lot</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form action="" method="post">
                                <input type="hidden" name="action" value="ajouter">
                                
                                <div class="mb-3">
                                    <label for="nom" class="form-label">Nom du lot</label>
                                    <input type="text" class="form-control" id="nom" name="nom" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="valeur" class="form-label">Valeur unitaire (€)</label>
                                    <input type="number" class="form-control" id="valeur" name="valeur" step="0.01" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="quantite" class="form-label">Quantité</label>
                                    <input type="number" class="form-control" id="quantite" name="quantite" min="1" value="1" required>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">Ajouter</button>
                            </form>
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
