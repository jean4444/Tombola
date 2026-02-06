<?php
// reset_database.php - Script temporaire pour vider la base de données
// ATTENTION : À UTILISER AVEC PRÉCAUTION ET À SUPPRIMER APRÈS USAGE

require_once 'config.php';

// Fonction pour vider la base de données
function viderBaseDeDonnees() {
    $db = connectDB();
    
    try {
        $db->beginTransaction();
        
        // Désactiver les contraintes de clé étrangère temporairement
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        
        // Liste des tables à vider (dans l'ordre pour éviter les conflits de clés étrangères)
        $tables = [
            'ventes',
            'billets',
            'tranches',
            'eleves',
            'classes',
            'lots'
            // Ne pas vider la table 'annees' pour conserver l'année active
        ];
        
        $resultats = [];
        
        // Vider chaque table
        foreach ($tables as $table) {
            $stmt = $db->prepare("TRUNCATE TABLE $table");
            $stmt->execute();
            $resultats[] = "Table '$table' vidée avec succès.";
        }
        
        // Réactiver les contraintes de clé étrangère
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        
        $db->commit();
        
        return [
            "statut" => "success",
            "message" => "Base de données vidée avec succès.",
            "details" => $resultats
        ];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return [
            "statut" => "erreur",
            "message" => "Erreur lors de la réinitialisation : " . $e->getMessage()
        ];
    }
}

// Protection par mot de passe pour éviter les suppressions accidentelles
$motDePasse = "tombola2024"; // Utilisez le même mot de passe que pour l'authentification

$resultat = null;
$confirmation = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['password']) && $_POST['password'] === $motDePasse) {
        if (isset($_POST['confirmation']) && $_POST['confirmation'] === 'OUI') {
            $resultat = viderBaseDeDonnees();
        } else {
            $confirmation = true;
        }
    } else if (isset($_POST['password'])) {
        $resultat = ["statut" => "erreur", "message" => "Mot de passe incorrect."];
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réinitialisation de la base de données - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            padding: 20px;
        }
        .danger-zone {
            border: 2px solid #dc3545;
            padding: 20px;
            margin-top: 20px;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mb-4">Réinitialisation de la base de données</h1>
        
        <div class="alert alert-danger">
            <h4>⚠️ ATTENTION ⚠️</h4>
            <p>Cette page permet de vider entièrement la base de données. Cette action est <strong>irréversible</strong>.</p>
            <p>Toutes les données seront supprimées, y compris :</p>
            <ul>
                <li>Les classes et élèves</li>
                <li>Les tranches de billets et leur attribution</li>
                <li>Les billets vendus et retournés</li>
                <li>Les lots de la tombola</li>
                <li>Toutes les statistiques de vente</li>
            </ul>
            <p>Seule la structure de la base de données et la table des années scolaires seront conservées.</p>
        </div>
        
        <?php if (isset($resultat)): ?>
            <div class="alert alert-<?= $resultat['statut'] === 'success' ? 'success' : 'danger' ?>">
                <h4><?= $resultat['statut'] === 'success' ? 'Succès' : 'Erreur' ?></h4>
                <p><?= $resultat['message'] ?></p>
                <?php if (isset($resultat['details'])): ?>
                    <ul>
                        <?php foreach ($resultat['details'] as $detail): ?>
                            <li><?= $detail ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            
            <?php if ($resultat['statut'] === 'success'): ?>
                <div class="alert alert-info">
                    <p>La base de données a été vidée avec succès. Par mesure de sécurité, veuillez <strong>supprimer ce fichier</strong> de votre serveur après utilisation.</p>
                    <p><a href="index.php" class="btn btn-primary">Retour à l'accueil</a></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if (!isset($resultat) || $resultat['statut'] !== 'success'): ?>
            <div class="danger-zone">
                <?php if ($confirmation): ?>
                    <h3 class="text-danger">Confirmation de réinitialisation</h3>
                    <form action="" method="post" class="mt-3">
                        <p>Veuillez taper "OUI" (en majuscules) pour confirmer que vous souhaitez vider entièrement la base de données :</p>
                        <div class="mb-3">
                            <input type="text" name="confirmation" class="form-control" required>
                            <input type="hidden" name="password" value="<?= htmlspecialchars($_POST['password']) ?>">
                        </div>
                        <button type="submit" class="btn btn-danger">Réinitialiser la base de données</button>
                        <a href="index.php" class="btn btn-secondary">Annuler</a>
                    </form>
                <?php else: ?>
                    <h3 class="text-danger">Authentification requise</h3>
                    <form action="" method="post" class="mt-3">
                        <div class="mb-3">
                            <label for="password" class="form-label">Mot de passe admin</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-warning">Continuer</button>
                        <a href="index.php" class="btn btn-secondary">Annuler</a>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>