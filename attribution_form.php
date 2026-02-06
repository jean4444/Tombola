<?php
require_once 'config.php';
require_once 'auth.php';
require_once 'billets.php';

// Vérifier si l'utilisateur est connecté
redirigerSiNonConnecte();

$db = connectDB();
$idAnnee = getAnneeActive($db);

// Récupérer l'ID de classe si spécifié
$idClasse = isset($_GET['classe']) ? intval($_GET['classe']) : null;

// Vérifier si des billets sont initialisés
$stmt = $db->prepare("SELECT COUNT(*) as total FROM billets WHERE id_annee = ?");
$stmt->execute([$idAnnee]);
$billetsExistes = $stmt->fetch()['total'] > 0;

// Récupérer les élèves de la classe
$eleves = [];
if ($idClasse) {
    $stmt = $db->prepare("
        SELECT 
            e.id_eleve, 
            e.nom, 
            e.prenom,
            (SELECT COUNT(*) FROM tranches t WHERE t.id_eleve = e.id_eleve AND t.id_annee = ?) as nb_tranches
        FROM eleves e
        WHERE e.id_classe = ? AND e.id_annee = ?
        ORDER BY e.nom, e.prenom
    ");
    $stmt->execute([$idAnnee, $idClasse, $idAnnee]);
    $eleves = $stmt->fetchAll();
}

// Récupérer les tranches disponibles
$stmt = $db->prepare("
    SELECT 
        id_tranche, 
        numero_debut, 
        numero_fin 
    FROM tranches 
    WHERE id_eleve IS NULL AND id_annee = ?
    ORDER BY numero_debut
");
$stmt->execute([$idAnnee]);
$tranchesDisponibles = $stmt->fetchAll();

// Traitement du formulaire d'attribution
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['attribution'])) {
    try {
        $db->beginTransaction();
        
        // Parcourir les attributions
        foreach ($_POST['attribution'] as $idEleve => $idTranche) {
            if (empty($idTranche)) continue;
            
            // Attribuer la tranche
            $stmt = $db->prepare("
                UPDATE tranches 
                SET id_eleve = ?, date_attribution = NOW() 
                WHERE id_tranche = ? AND id_eleve IS NULL
            ");
            $stmt->execute([$idEleve, $idTranche]);
            
            // Mettre à jour le statut des billets
            $stmt = $db->prepare("
                UPDATE billets 
                SET statut = 'attribue' 
                WHERE id_tranche = ? AND statut = 'disponible'
            ");
            $stmt->execute([$idTranche]);
        }
        
        $db->commit();
        $message = 'Tranches attribuées avec succès.';
        $messageType = 'success';
        
        // Recharger les données
        $stmt = $db->prepare("
            SELECT 
                id_tranche, 
                numero_debut, 
                numero_fin 
            FROM tranches 
            WHERE id_eleve IS NULL AND id_annee = ?
            ORDER BY numero_debut
        ");
        $stmt->execute([$idAnnee]);
        $tranchesDisponibles = $stmt->fetchAll();
    } catch (Exception $e) {
        $db->rollBack();
        $message = 'Erreur lors de l\'attribution : ' . $e->getMessage();
        $messageType = 'danger';
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attribution des Carnets - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container my-4">
        <h2>Attribution des Carnets de Billets</h2>
        
        <?php if (!$idAnnee): ?>
            <div class="alert alert-warning">Aucune année scolaire active.</div>
        <?php elseif (!$billetsExistes): ?>
            <div class="alert alert-warning">
                Les billets n'ont pas été initialisés. 
                <a href="billets.php?action=initialiser" class="btn btn-primary btn-sm">Initialiser les billets</a>
            </div>
        <?php else: ?>
            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?>">
                    <?= $message ?>
                </div>
            <?php endif; ?>
            
            <?php if (!$idClasse): ?>
                <div class="alert alert-info">
                    Veuillez sélectionner une classe pour attribuer des carnets.
                </div>
            <?php else: ?>
                <?php 
                // Récupérer les informations de la classe
                $stmt = $db->prepare("SELECT nom, niveau FROM classes WHERE id_classe = ?");
                $stmt->execute([$idClasse]);
                $classe = $stmt->fetch(); 
                ?>
                
                <div class="card">
                    <div class="card-header">
                        <h3>
                            Attribution des carnets - 
                            <?= htmlspecialchars($classe['nom']) ?> 
                            (<?= htmlspecialchars($classe['niveau']) ?>)
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Élève</th>
                                        <th>Carnet à attribuer</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($eleves)): ?>
                                        <tr>
                                            <td colspan="2" class="text-center">
                                                Aucun élève dans cette classe.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($eleves as $eleve): ?>
                                            <tr>
                                                <td>
                                                    <?= htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom']) ?>
                                                    <?php if ($eleve['nb_tranches'] > 0): ?>
                                                        <span class="badge bg-warning">
                                                            <?= $eleve['nb_tranches'] ?> carnet(s) déjà attribué(s)
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <select name="attribution[<?= $eleve['id_eleve'] ?>]" class="form-select">
                                                        <option value="">Aucun</option>
                                                        <?php foreach ($tranchesDisponibles as $tranche): ?>
                                                            <option value="<?= $tranche['id_tranche'] ?>">
                                                                Carnet <?= $tranche['numero_debut'] ?> - <?= $tranche['numero_fin'] ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            
                            <?php if (!empty($eleves) && !empty($tranchesDisponibles)): ?>
                                <div class="alert alert-info">
                                    <strong>Tranches disponibles :</strong>
                                    <?php 
                                    $infos = array_map(function($t) {
                                        return $t['numero_debut'] . '-' . $t['numero_fin'];
                                    }, $tranchesDisponibles);
                                    echo implode(', ', $infos);
                                    ?>
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">
                                        Attribuer les carnets
                                    </button>
                                </div>
                            <?php else: ?>
                                <?php if (empty($tranchesDisponibles)): ?>
                                    <div class="alert alert-warning">
                                        Aucune tranche de billets disponible. 
                                        <a href="billets.php" class="btn btn-sm btn-outline-primary">
                                            Initialiser les billets
                                        </a>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <div class="mt-3">
            <a href="eleves.php" class="btn btn-secondary">Retour à la liste des classes</a>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>