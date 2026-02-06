<?php
// admin.php - Administration du système

require_once 'config.php';
require_once 'billets.php';

// Créer une nouvelle année scolaire
function creerAnnee($libelle) {
    $db = connectDB();
    
    try {
        $db->beginTransaction();
        
        // Vérifier si l'année existe déjà
        $stmt = $db->prepare("SELECT id_annee FROM annees WHERE libelle = ?");
        $stmt->execute([$libelle]);
        if ($stmt->fetch()) {
            $db->rollBack();
            return ["statut" => "erreur", "message" => "Cette année scolaire existe déjà."];
        }
        
        // Désactiver toutes les années
        $db->query("UPDATE annees SET active = FALSE");
        
        // Créer la nouvelle année
        $stmt = $db->prepare("INSERT INTO annees (libelle, active) VALUES (?, TRUE)");
        $stmt->execute([$libelle]);
        $idAnnee = $db->lastInsertId();
        
        $db->commit();
        return [
            "statut" => "success", 
            "message" => "Année scolaire créée et activée.",
            "id_annee" => $idAnnee
        ];
    } catch (Exception $e) {
        $db->rollBack();
        return ["statut" => "erreur", "message" => "Erreur: " . $e->getMessage()];
    }
}

// Activer une année existante
function activerAnnee($idAnnee) {
    $db = connectDB();
    
    try {
        $db->beginTransaction();
        
        // Vérifier si l'année existe
        $stmt = $db->prepare("SELECT id_annee FROM annees WHERE id_annee = ?");
        $stmt->execute([$idAnnee]);
        if (!$stmt->fetch()) {
            $db->rollBack();
            return ["statut" => "erreur", "message" => "Cette année scolaire n'existe pas."];
        }
        
        // Désactiver toutes les années
        $db->query("UPDATE annees SET active = FALSE");
        
        // Activer l'année demandée
        $stmt = $db->prepare("UPDATE annees SET active = TRUE WHERE id_annee = ?");
        $stmt->execute([$idAnnee]);
        
        $db->commit();
        return ["statut" => "success", "message" => "Année scolaire activée."];
    } catch (Exception $e) {
        $db->rollBack();
        return ["statut" => "erreur", "message" => "Erreur: " . $e->getMessage()];
    }
}

// Traitement des formulaires
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'creer_annee':
                if (isset($_POST['libelle'])) {
                    $resultat = creerAnnee($_POST['libelle']);
                    
                    if ($resultat['statut'] === 'success' && isset($resultat['id_annee'])) {
                        // Initialiser les billets pour la nouvelle année
                        initialiserBillets($resultat['id_annee']);
                    }
                }
                break;
                
            case 'activer_annee':
                if (isset($_POST['id_annee'])) {
                    $resultat = activerAnnee($_POST['id_annee']);
                }
                break;
                
            case 'initialiser_billets':
                if (isset($_POST['id_annee'])) {
                    $resultat = initialiserBillets($_POST['id_annee']);
                }
                break;
        }
                        <h4 class="mt-4" id="sauvegarde">Sauvegarde de la base de données</h4>
                        <p>Il est recommandé de faire régulièrement une sauvegarde de la base de données.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="backup.php" class="btn btn-success">Exporter la base de données</a>
                            <a href="BACKUP_GUIDE.md" class="btn btn-outline-primary">Guide & paramètres de sauvegarde</a>
                        </div>
</html>
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container my-4">
        <h2>Administration</h2>
        
        <?php if (isset($resultat)): ?>
            <div class="alert alert-<?= $resultat['statut'] === 'success' ? 'success' : 'danger' ?>">
                <?= $resultat['message'] ?>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h3>Années scolaires</h3>
                    </div>
                    <div class="card-body">
                        <?php
                        $db = connectDB();
                        $stmt = $db->query("SELECT id_annee, libelle, active FROM annees ORDER BY libelle DESC");
                        $annees = $stmt->fetchAll();
                        
                        if (!empty($annees)) {
                            ?>
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Année</th>
                                        <th>Statut</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($annees as $annee): ?>
                                        <tr>
                                            <td><?= $annee['libelle'] ?></td>
                                            <td>
                                                <?php if ($annee['active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!$annee['active']): ?>
                                                    <form action="" method="post" class="d-inline">
                                                        <input type="hidden" name="action" value="activer_annee">
                                                        <input type="hidden" name="id_annee" value="<?= $annee['id_annee'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-primary">Activer</button>
                                                    </form>
                                                <?php endif; ?>
                                                
                                                <form action="" method="post" class="d-inline">
                                                    <input type="hidden" name="action" value="initialiser_billets">
                                                    <input type="hidden" name="id_annee" value="<?= $annee['id_annee'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-warning">Réinitialiser billets</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php
                        } else {
                            echo '<div class="alert alert-info">Aucune année scolaire créée.</div>';
                        }
                        ?>
                        
                        <h4 class="mt-4">Créer une nouvelle année</h4>
                        <form action="" method="post">
                            <input type="hidden" name="action" value="creer_annee">
                            <div class="form-group mb-3">
                                <label for="libelle">Libellé de l'année (ex: 2024-2025):</label>
                                <input type="text" class="form-control" id="libelle" name="libelle" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Créer et Activer</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h3>Configuration du système</h3>
                    </div>
                    <div class="card-body">
                        <h4>Paramètres actuels</h4>
                        <table class="table">
                            <tr>
                                <th>Nombre total de billets</th>
                                <td><?= BILLETS_MAX ?></td>
                            </tr>
                            <tr>
                                <th>Taille des tranches</th>
                                <td><?= TAILLE_TRANCHE ?> billets</td>
                            </tr>
                            <tr>
                                <th>Prix unitaire par défaut</th>
                                <td><?= PRIX_BILLET_DEFAUT ?> €</td>
                            </tr>
                        </table>
                        
                        <div class="alert alert-info">
                            <p>Pour modifier ces paramètres, veuillez éditer le fichier <code>config.php</code>.</p>
                            <p>Note: La modification des paramètres ne s'appliquera qu'aux nouvelles années scolaires.</p>
                        </div>
                        
                        <h4 class="mt-4">Sauvegarde de la base de données</h4>
                        <p>Il est recommandé de faire régulièrement une sauvegarde de la base de données.</p>
                        <a href="backup.php" class="btn btn-success">Exporter la base de données</a>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3>Importation des élèves</h3>
                    </div>
                    <div class="card-body">
                        <p>Pour importer la liste des élèves depuis un fichier Excel:</p>
                        <a href="import.php" class="btn btn-primary">Aller à la page d'importation</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>