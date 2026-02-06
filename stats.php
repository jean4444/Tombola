<?php
// stats.php - Affichage des statistiques et rapports de la tombola
require_once 'config.php';
require_once 'auth.php';
require_once 'statistiques.php';
require_once 'lots.php';

// Vérifier si l'utilisateur est connecté
redirigerSiNonConnecte();

$db = connectDB();
$idAnnee = getAnneeActive($db);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistiques - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container my-4">
        <h2>Statistiques</h2>
        
        <?php
        if (!$idAnnee) {
            echo '<div class="alert alert-warning">Aucune année scolaire active.</div>';
        } else {
            // Récupérer l'année active
            $stmt = $db->prepare("SELECT libelle FROM annees WHERE id_annee = ?");
            $stmt->execute([$idAnnee]);
            $anneeActive = $stmt->fetch();
            
            // Récupérer les statistiques
            $stats = getStatistiquesAnnee($idAnnee);
            ?>
            
            <div class="alert alert-info">
                <h4>Statistiques pour l'année <?= $anneeActive['libelle'] ?></h4>
            </div>
            
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3>Récapitulatif</h3>
                        </div>
                        <div class="card-body">
                            <table class="table table-striped">
                                <tr>
                                    <th>Billets vendus</th>
                                    <td><?= number_format($stats['ventes']['total_vendus'] ?? 0, 0, ',', ' ') ?></td>
                                </tr>
                                <tr>
                                    <th>Billets retournés</th>
                                    <td><?= number_format($stats['ventes']['total_retournes'] ?? 0, 0, ',', ' ') ?></td>
                                </tr>
                                <tr>
                                    <th>Recette totale</th>
                                    <td><?= number_format($stats['ventes']['recette_totale'] ?? 0, 2, ',', ' ') ?> €</td>
                                </tr>
                                <tr>
                                    <th>Coût des lots</th>
                                    <td><?= number_format($stats['cout_lots'] ?? 0, 2, ',', ' ') ?> €</td>
                                </tr>
                                <tr class="table-info">
                                    <th>Bénéfice</th>
                                    <td><?= number_format($stats['benefice'] ?? 0, 2, ',', ' ') ?> €</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h3>Répartition des billets</h3>
                        </div>
                        <div class="card-body">
                            <canvas id="chartBillets"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h3>Classement des classes</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Classe</th>
                                    <th>Niveau</th>
                                    <th>Nb élèves</th>
                                    <th>Billets vendus</th>
                                    <th>Montant</th>
                                    <th>Moyenne par élève</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                if (isset($stats['classes']) && is_array($stats['classes'])) {
                                    foreach ($stats['classes'] as $classe): 
                                ?>
                                    <tr>
                                        <td><?= $classe['classe'] ?></td>
                                        <td><?= $classe['niveau'] ?></td>
                                        <td><?= $classe['nb_eleves'] ?></td>
                                        <td><?= $classe['billets_vendus'] ?? 0 ?></td>
                                        <td><?= number_format($classe['montant_total'] ?? 0, 2, ',', ' ') ?> €</td>
                                        <td><?= number_format($classe['moyenne_par_eleve'] ?? 0, 1, ',', ' ') ?></td>
                                    </tr>
                                <?php 
                                    endforeach;
                                } else {
                                    echo '<tr><td colspan="6" class="text-center">Aucune donnée disponible</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3>Comparaison entre années</h3>
                </div>
                <div class="card-body">
                    <?php
                    $comparaison = comparerAnnees();
                    
                    if (!empty($comparaison) && !isset($comparaison['statut'])) {
                    ?>
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Année</th>
                                            <th>Billets vendus</th>
                                            <th>Recette</th>
                                            <th>Coût lots</th>
                                            <th>Bénéfice</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($comparaison as $annee): ?>
                                            <tr>
                                                <td><?= $annee['libelle'] ?></td>
                                                <td><?= number_format($annee['billets_vendus'] ?? 0, 0, ',', ' ') ?></td>
                                                <td><?= number_format($annee['recette'] ?? 0, 2, ',', ' ') ?> €</td>
                                                <td><?= number_format($annee['cout_lots'] ?? 0, 2, ',', ' ') ?> €</td>
                                                <td><?= number_format($annee['benefice'] ?? 0, 2, ',', ' ') ?> €</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <canvas id="chartComparaison"></canvas>
                            </div>
                        </div>
                    <?php
                    } else {
                        echo '<div class="alert alert-info">Pas assez de données pour afficher une comparaison.</div>';
                    }
                    ?>
                </div>
            </div>
            
            <script>
                // Graphique des billets
                const ctxBillets = document.getElementById('chartBillets').getContext('2d');
                new Chart(ctxBillets, {
                    type: 'pie',
                    data: {
                        labels: ['Vendus', 'Retournés', 'En cours', 'Disponibles'],
                        datasets: [{
                            data: [
                                <?= $stats['billets']['vendus'] ?? 0 ?>,
                                <?= $stats['billets']['retournes'] ?? 0 ?>,
                                <?= $stats['billets']['attribues'] ?? 0 ?>,
                                <?= $stats['billets']['disponibles'] ?? 0 ?>
                            ],
                            backgroundColor: [
                                'rgba(75, 192, 192, 0.7)',
                                'rgba(201, 203, 207, 0.7)',
                                'rgba(255, 205, 86, 0.7)',
                                'rgba(54, 162, 235, 0.7)'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'right',
                            },
                            title: {
                                display: true,
                                text: 'État des billets'
                            }
                        }
                    }
                });
                
                <?php if (!empty($comparaison) && !isset($comparaison['statut']) && count($comparaison) > 0): ?>
                // Graphique de comparaison entre années
                const ctxComparaison = document.getElementById('chartComparaison').getContext('2d');
                new Chart(ctxComparaison, {
                    type: 'bar',
                    data: {
                        labels: [<?php echo implode(', ', array_map(function($a) { return "'" . $a['libelle'] . "'"; }, $comparaison)); ?>],
                        datasets: [{
                            label: 'Bénéfice (€)',
                            data: [<?php echo implode(', ', array_map(function($a) { return $a['benefice'] ?? 0; }, $comparaison)); ?>],
                            backgroundColor: 'rgba(75, 192, 192, 0.7)',
                            borderColor: 'rgba(75, 192, 192, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        },
                        plugins: {
                            title: {
                                display: true,
                                text: 'Bénéfice par année'
                            }
                        }
                    }
                });
                <?php endif; ?>
            </script>
            <?php
        }
        ?>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>