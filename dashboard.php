<?php
// dashboard.php - Tableau de bord principal de l'application

require_once 'config.php';
require_once 'statistiques.php';
require_once 'lots.php'; // Ajout pour getTotalCoutsLots()

// Vérifier si authentification requise
if (file_exists('auth.php')) {
    require_once 'auth.php';
    redirigerSiNonConnecte();
}

$db = connectDB();
$idAnnee = getAnneeActive($db);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <!-- Contenu principal -->
    <div class="container my-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2>Tableau de bord</h2>
            <?php if ($idAnnee): ?>
                <a href="billets_non_rendus.php" class="btn btn-outline-warning">
                    <i class="bi bi-exclamation-triangle"></i> Billets non rendus
                </a>
            <?php endif; ?>
        </div>
        
        <?php if (!$idAnnee): ?>
            <div class="alert alert-warning">
                Aucune année scolaire active. Veuillez en créer une dans la section Administration.
                <a href="admin.php" class="btn btn-primary btn-sm ms-2">Aller à l'administration</a>
            </div>
        <?php else: ?>
            
            <?php
            // Récupérer l'année active
            $stmt = $db->prepare("SELECT libelle FROM annees WHERE id_annee = ?");
            $stmt->execute([$idAnnee]);
            $anneeActive = $stmt->fetch();
            
            // Récupérer les statistiques
            $stats = getStatistiquesAnnee($idAnnee);
            
            // Calculer les statistiques détaillées des carnets
            $stmt = $db->prepare("
                SELECT 
                    COUNT(DISTINCT e.id_eleve) as nb_eleves_non_rendus,
                    COUNT(DISTINCT t.id_tranche) as nb_carnets_total_attribues,
                    COUNT(DISTINCT CASE WHEN t.date_retour IS NULL THEN t.id_tranche END) as nb_carnets_non_rendus,
                    SUM(CASE WHEN t.date_retour IS NULL THEN 
                        (t.numero_fin - t.numero_debut + 1) -
                        (SELECT COUNT(*) FROM billets b WHERE b.id_tranche = t.id_tranche AND (b.statut = 'vendu' OR b.statut = 'retourne'))
                        ELSE 0 END) as billets_vraiment_en_cours
                FROM eleves e
                JOIN tranches t ON e.id_eleve = t.id_eleve
                WHERE t.id_annee = ?
            ");
            $stmt->execute([$idAnnee]);
            $billetsStats = $stmt->fetch();
            
            $elevesNonRendus = $billetsStats['nb_eleves_non_rendus'] ?? 0;
            $carnetsNonRendus = $billetsStats['nb_carnets_non_rendus'] ?? 0;
            $carnetsAttribues = $billetsStats['nb_carnets_total_attribues'] ?? 0;
            $billetsVraimentEnCours = $billetsStats['billets_vraiment_en_cours'] ?? 0;
            
            // Calculer les carnets partiellement rendus
            $carnetsPartiellementRendus = $carnetsAttribues - $carnetsNonRendus;
            ?>
            
            <div class="row mb-4">
                <div class="col-12">
                    <div class="alert alert-info d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0">Année scolaire active: <?= htmlspecialchars($anneeActive['libelle']) ?></h4>
                        </div>
                        <?php if ($elevesNonRendus > 0): ?>
                            <div>
                                <span class="badge bg-warning text-dark fs-6 me-2">
                                    <?= $elevesNonRendus ?> élève(s) avec billets non rendus
                                </span>
                                <span class="badge bg-danger fs-6 me-2">
                                    <?= $carnetsNonRendus ?> carnet(s) complets non rendus
                                </span>
                                <?php if ($carnetsPartiellementRendus > 0): ?>
                                    <span class="badge bg-info fs-6">
                                        <?= $carnetsPartiellementRendus ?> carnet(s) partiellement rendus
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Statistiques principales -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-ticket-perforated fs-1 mb-2"></i>
                            <h5 class="card-title">Billets vendus</h5>
                            <h2 class="display-4"><?= number_format($stats['ventes']['total_vendus'] ?? 0, 0, ',', ' ') ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-cash-coin fs-1 mb-2"></i>
                            <h5 class="card-title">Recette totale</h5>
                            <h2 class="display-4"><?= number_format($stats['ventes']['recette_totale'] ?? 0, 2, ',', ' ') ?> €</h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-danger text-white h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-gift fs-1 mb-2"></i>
                            <h5 class="card-title">Coût des lots</h5>
                            <h2 class="display-4"><?= number_format($stats['cout_lots'] ?? 0, 2, ',', ' ') ?> €</h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-dark text-white h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-graph-up fs-1 mb-2"></i>
                            <h5 class="card-title">Bénéfice</h5>
                            <h2 class="display-4 <?= ($stats['benefice'] ?? 0) >= 0 ? '' : 'text-danger' ?>">
                                <?= number_format($stats['benefice'] ?? 0, 2, ',', ' ') ?> €
                            </h2>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Statistiques détaillées -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card text-center h-100">
                        <div class="card-body">
                            <i class="bi bi-people fs-1 text-info mb-2"></i>
                            <h5>Total carnets</h5>
                            <h3><?= number_format($stats['billets']['total_billets'] / 10 ?? 0, 0, ',', ' ') ?></h3>
                            <small class="text-muted">Dans le système</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center h-100">
                        <div class="card-body">
                            <i class="bi bi-journal-text fs-1 text-warning mb-2"></i>
                            <h5>Carnets distribués</h5>
                            <h3><?= number_format(($stats['billets']['attribues'] + $stats['billets']['vendus'] + $stats['billets']['retournes']) / 10 ?? 0, 0, ',', ' ') ?></h3>
                            <small class="text-muted">Carnets de 10 billets</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card text-center h-100 <?= $elevesNonRendus > 0 ? 'border-warning' : 'border-success' ?>">
                        <div class="card-body">
                            <i class="bi bi-exclamation-triangle fs-1 <?= $elevesNonRendus > 0 ? 'text-warning' : 'text-success' ?> mb-2"></i>
                            <h5>Billets non rendus</h5>
                            <h3 class="<?= $elevesNonRendus > 0 ? 'text-warning' : 'text-success' ?>"><?= $elevesNonRendus ?></h3>
                            <small class="text-muted">Élèves concernés</small>
                            <?php if ($elevesNonRendus > 0): ?>
                                <div class="mt-2">
                                    <a href="billets_non_rendus.php" class="btn btn-sm btn-warning">Voir détails</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h3 class="mb-0">Progression de la vente</h3>
                        </div>
                        <div class="card-body">
                            <?php 
                            $totalBillets = $stats['billets']['total_billets'] ?? 0;
                            $vendus = $stats['billets']['vendus'] ?? 0;
                            $attribues = $stats['billets']['attribues'] ?? 0;
                            $retournes = $stats['billets']['retournes'] ?? 0;
                            $disponibles = $stats['billets']['disponibles'] ?? 0;
                            
                            $pcVendus = $totalBillets > 0 ? round(($vendus / $totalBillets) * 100) : 0;
                            $pcAttribues = $totalBillets > 0 ? round(($attribues / $totalBillets) * 100) : 0;
                            $pcRetournes = $totalBillets > 0 ? round(($retournes / $totalBillets) * 100) : 0;
                            $pcDisponibles = $totalBillets > 0 ? round(($disponibles / $totalBillets) * 100) : 0;
                            ?>
                            
                            <div class="row mb-3">
                                <div class="col-6">
                                    <h4 class="text-success">Vendus: <?= number_format($vendus, 0, ',', ' ') ?></h4>
                                    <span class="badge bg-success"><?= $pcVendus ?>%</span>
                                </div>
                                <div class="col-6">
                                    <h4 class="text-warning">En cours: <?= number_format($attribues, 0, ',', ' ') ?></h4>
                                    <span class="badge bg-warning text-dark"><?= $pcAttribues ?>%</span>
                                </div>
                            </div>
                            
                            <div class="progress mb-3" style="height: 25px;">
                                <div class="progress-bar bg-success" style="width: <?= $pcVendus ?>%" 
                                     title="<?= $vendus ?> billets vendus (<?= $pcVendus ?>%)">
                                    <?= $pcVendus ?>%
                                </div>
                                <div class="progress-bar bg-warning" style="width: <?= $pcAttribues ?>%" 
                                     title="<?= $attribues ?> billets en cours (<?= $pcAttribues ?>%)">
                                    <?= $pcAttribues ?>%
                                </div>
                                <div class="progress-bar bg-secondary" style="width: <?= $pcRetournes ?>%" 
                                     title="<?= $retournes ?> billets retournés (<?= $pcRetournes ?>%)">
                                    <?= $pcRetournes ?>%
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-6">
                                    <small class="text-muted">Retournés: <?= number_format($retournes, 0, ',', ' ') ?> (<?= $pcRetournes ?>%)</small>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Disponibles: <?= number_format($disponibles, 0, ',', ' ') ?> (<?= $pcDisponibles ?>%)</small>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <a href="billets.php" class="btn btn-primary btn-sm">Gérer les billets</a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h3 class="mb-0">Meilleurs vendeurs</h3>
                        </div>
                        <div class="card-body">
                            <?php
                            $meilleurs = getMeilleursVendeurs($idAnnee, 5);
                            if (!empty($meilleurs) && !isset($meilleurs['statut'])) {
                            ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Position</th>
                                                <th>Élève</th>
                                                <th>Classe</th>
                                                <th>Billets</th>
                                                <th>Montant</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($meilleurs as $index => $eleve): ?>
                                                <tr>
                                                    <td>
                                                        <?php if ($index === 0): ?>
                                                            <i class="bi bi-trophy-fill text-warning"></i> 1er
                                                        <?php elseif ($index === 1): ?>
                                                            <i class="bi bi-award-fill text-secondary"></i> 2e
                                                        <?php elseif ($index === 2): ?>
                                                            <i class="bi bi-award-fill text-warning"></i> 3e
                                                        <?php else: ?>
                                                            <?= $index + 1 ?>e
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom']) ?></strong>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-light text-dark"><?= htmlspecialchars($eleve['classe']) ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-primary"><?= $eleve['billets_vendus'] ?></span>
                                                    </td>
                                                    <td>
                                                        <strong><?= number_format($eleve['montant_total'], 2, ',', ' ') ?> €</strong>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="eleves.php" class="btn btn-outline-primary">Voir tous les élèves</a>
                                    <a href="stats.php" class="btn btn-outline-success">Statistiques détaillées</a>
                                </div>
                            <?php
                            } else {
                                echo '<div class="alert alert-info text-center">';
                                echo '<i class="bi bi-info-circle-fill fs-1 mb-3"></i>';
                                echo '<h5>Aucune vente enregistrée</h5>';
                                echo '<p>Commencez par distribuer des carnets de billets aux élèves.</p>';
                                echo '<a href="eleves.php" class="btn btn-primary">Gérer les élèves</a>';
                                echo '</div>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Actions rapides -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="mb-0">Actions rapides</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 mb-2">
                                    <a href="eleves.php" class="btn btn-outline-primary w-100">
                                        <i class="bi bi-people me-2"></i>Gérer les élèves
                                    </a>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <a href="billets.php" class="btn btn-outline-success w-100">
                                        <i class="bi bi-ticket-perforated me-2"></i>Gérer les billets
                                    </a>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <a href="lots.php" class="btn btn-outline-danger w-100">
                                        <i class="bi bi-gift me-2"></i>Gérer les lots
                                    </a>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <?php if ($elevesNonRendus > 0): ?>
                                        <a href="billets_non_rendus.php" class="btn btn-warning w-100">
                                            <i class="bi bi-exclamation-triangle me-2"></i>Billets non rendus
                                        </a>
                                    <?php else: ?>
                                        <a href="stats.php" class="btn btn-outline-info w-100">
                                            <i class="bi bi-graph-up me-2"></i>Statistiques
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
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