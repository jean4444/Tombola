<?php
// billets_non_rendus.php - Liste des élèves qui n'ont pas rendu leurs billets

require_once 'config.php';
require_once 'auth.php';

// Vérifier si l'utilisateur est connecté
redirigerSiNonConnecte();

$db = connectDB();
$idAnnee = getAnneeActive($db);

// Fonction pour récupérer les élèves qui n'ont pas rendu leurs billets
function getElevesNonRendus($idAnnee) {
    $db = connectDB();
    
    $stmt = $db->prepare("
        SELECT 
            c.id_classe,
            c.nom as classe,
            c.niveau,
            e.id_eleve,
            e.nom,
            e.prenom,
            COUNT(t.id_tranche) as nb_tranches_attribuees,
            COUNT(CASE WHEN t.date_retour IS NULL THEN 1 END) as nb_tranches_non_rendues,
            SUM(CASE WHEN t.date_retour IS NULL THEN 
                (t.numero_fin - t.numero_debut + 1) -
                (SELECT COUNT(*) FROM billets b WHERE b.id_tranche = t.id_tranche AND (b.statut = 'vendu' OR b.statut = 'retourne'))
                ELSE 0 END) as billets_vraiment_non_rendus,
            GROUP_CONCAT(
                CASE WHEN t.date_retour IS NULL 
                THEN CONCAT(
                    'Carnet #', t.id_tranche, ' (', t.numero_debut, '-', t.numero_fin, ') - ',
                    (t.numero_fin - t.numero_debut + 1) -
                    (SELECT COUNT(*) FROM billets b WHERE b.id_tranche = t.id_tranche AND (b.statut = 'vendu' OR b.statut = 'retourne')), 
                    ' billets restants'
                )
                END 
                SEPARATOR ' | '
            ) as carnets_non_rendus,
            MIN(t.date_attribution) as date_attribution
        FROM classes c
        JOIN eleves e ON c.id_classe = e.id_classe
        JOIN tranches t ON e.id_eleve = t.id_eleve
        WHERE c.id_annee = ? AND t.id_annee = ?
        GROUP BY e.id_eleve
        HAVING billets_vraiment_non_rendus > 0
        ORDER BY 
            CASE 
                WHEN c.niveau = 'PS' THEN 1
                WHEN c.niveau = 'MS' THEN 2
                WHEN c.niveau = 'GS' THEN 3
                WHEN c.niveau = 'CP' THEN 4
                WHEN c.niveau = 'CE1' THEN 5
                WHEN c.niveau = 'CE2' THEN 6
                WHEN c.niveau = 'CM1' THEN 7
                WHEN c.niveau = 'CM2' THEN 8
                ELSE 9
            END, 
            c.nom, e.nom, e.prenom
    ");
    $stmt->execute([$idAnnee, $idAnnee]);
    
    return $stmt->fetchAll();
}

// Fonction pour obtenir les statistiques par classe
function getStatistiquesParClasse($idAnnee) {
    $db = connectDB();
    
    $stmt = $db->prepare("
        SELECT 
            c.nom as classe,
            c.niveau,
            COUNT(DISTINCT e.id_eleve) as total_eleves,
            COUNT(DISTINCT CASE WHEN t.id_eleve IS NOT NULL THEN e.id_eleve END) as eleves_avec_billets,
            COUNT(DISTINCT CASE WHEN t.date_retour IS NULL AND t.id_eleve IS NOT NULL THEN e.id_eleve END) as eleves_non_rendus
        FROM classes c
        LEFT JOIN eleves e ON c.id_classe = e.id_classe
        LEFT JOIN tranches t ON e.id_eleve = t.id_eleve AND t.id_annee = c.id_annee
        WHERE c.id_annee = ?
        GROUP BY c.id_classe
        ORDER BY 
            CASE 
                WHEN c.niveau = 'PS' THEN 1
                WHEN c.niveau = 'MS' THEN 2
                WHEN c.niveau = 'GS' THEN 3
                WHEN c.niveau = 'CP' THEN 4
                WHEN c.niveau = 'CE1' THEN 5
                WHEN c.niveau = 'CE2' THEN 6
                WHEN c.niveau = 'CM1' THEN 7
                WHEN c.niveau = 'CM2' THEN 8
                ELSE 9
            END, 
            c.nom
    ");
    $stmt->execute([$idAnnee]);
    
    return $stmt->fetchAll();
}

// Préparer les données
$annee = null;
$eleves = [];
$statistiques = [];
$elevesParClasse = [];

if ($idAnnee) {
    // Récupérer l'année active
    $stmt = $db->prepare("SELECT libelle FROM annees WHERE id_annee = ?");
    $stmt->execute([$idAnnee]);
    $annee = $stmt->fetch();
    
    // Récupérer les données
    $eleves = getElevesNonRendus($idAnnee);
    $statistiques = getStatistiquesParClasse($idAnnee);
    
    // Grouper par classe
    foreach ($eleves as $eleve) {
        $cle = $eleve['classe'] . '|' . $eleve['niveau'];
        if (!isset($elevesParClasse[$cle])) {
            $elevesParClasse[$cle] = [
                'classe' => $eleve['classe'],
                'niveau' => $eleve['niveau'],
                'eleves' => []
            ];
        }
        $elevesParClasse[$cle]['eleves'][] = $eleve;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billets Non Rendus - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .page-title {
            margin-bottom: 30px;
            padding-bottom: 10px;
            border-bottom: 2px solid #007bff;
            color: #2D3748;
        }
        
        .classe-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px 8px 0 0;
            padding: 15px 20px;
            margin-bottom: 0;
        }
        
        .niveau-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            margin-left: 10px;
        }
        
        .niveau-ps { background-color: #FF6B95; color: white; }
        .niveau-ms { background-color: #FFB347; color: white; }
        .niveau-gs { background-color: #FFDE59; color: #664D03; }
        .niveau-cp { background-color: #4ADE80; color: #14532D; }
        .niveau-ce1 { background-color: #38BDF8; color: #0C4A6E; }
        .niveau-ce2 { background-color: #60A5FA; color: #1E3A8A; }
        .niveau-cm1 { background-color: #A78BFA; color: #4C1D95; }
        .niveau-cm2 { background-color: #F472B6; color: #831843; }
        
        .eleve-card {
            border-left: 4px solid #dc3545;
            transition: all 0.3s ease;
        }
        
        .eleve-card:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .carnets-details {
            font-size: 0.9rem;
            color: #6c757d;
            background-color: #f8f9fa;
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 8px;
        }
        
        .stats-card {
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .progress-custom {
            height: 10px;
            border-radius: 5px;
        }
        
        .alert-success-custom {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            border: none;
            border-radius: 12px;
        }
        
        .print-button {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
        }
        
        .print-version {
            display: none !important;
        }
        
        @media print {
            .no-print,
            .screen-version,
            .card,
            .eleve-card,
            .classe-header {
                display: none !important;
            }
            
            .print-version {
                display: block !important;
            }
            
            * {
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            
            body {
                font-family: 'Arial', sans-serif;
                font-size: 11px;
                line-height: 1.2;
                margin: 0 !important;
                padding: 10mm !important;
                color: #000;
            }
            
            .print-header {
                text-align: center;
                margin-bottom: 25px;
                border-bottom: 2px solid #000;
                padding-bottom: 15px;
                page-break-after: avoid;
            }
            
            .print-header h2 {
                font-size: 18px;
                font-weight: bold;
                margin: 0 0 8px 0;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            
            .print-header h3 {
                font-size: 14px;
                font-weight: normal;
                margin: 5px 0;
                color: #333;
            }
            
            .print-header p {
                font-size: 11px;
                margin: 3px 0;
                color: #666;
            }
            
            .classe-title {
                background-color: #f0f0f0 !important;
                font-weight: bold;
                text-align: center;
                padding: 12px;
                border: 2px solid #000;
                margin: 0 0 0 0;
                font-size: 14px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                page-break-after: avoid;
                page-break-before: auto;
            }
            
            .classe-stats {
                font-size: 10px;
                font-style: italic;
                font-weight: normal;
                text-transform: none;
                color: #666;
                margin-left: 10px;
            }
            
            .classe-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 0;
                page-break-inside: avoid;
                border: 2px solid #000;
            }
            
            .classe-table th {
                background-color: #e8e8e8 !important;
                font-weight: bold;
                text-align: center;
                padding: 10px 6px;
                border: 1px solid #000;
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }
            
            .classe-table td {
                border: 1px solid #000;
                padding: 8px 6px;
                text-align: left;
                vertical-align: top;
                font-size: 10px;
                line-height: 1.3;
            }
            
            .classe-table td:first-child {
                text-align: center;
                font-weight: bold;
                background-color: #f8f8f8 !important;
            }
            
            .classe-table td:nth-child(4) {
                text-align: center;
                font-weight: bold;
                font-size: 11px;
            }
            
            .classe-table td:nth-child(6) {
                text-align: center;
                font-size: 9px;
            }
            
            .classe-table td:last-child {
                text-align: center;
                font-size: 16px;
                padding: 8px 4px;
            }
            
            .page-break {
                page-break-before: always;
            }
            
            .classe-section {
                page-break-inside: avoid;
                margin-bottom: 20px;
            }
            
            /* Style pour les noms en gras */
            .nom-eleve {
                font-weight: bold;
                text-transform: uppercase;
            }
            
            .prenom-eleve {
                font-weight: normal;
                text-transform: capitalize;
            }
            
            /* Amélioration des détails des carnets */
            .details-carnets {
                font-size: 9px;
                line-height: 1.2;
                color: #333;
            }
            
            /* Masquer la zone de signature */
            .signature-area {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container my-4">
        <?php if (!$idAnnee): ?>
            <div class="alert alert-warning">Aucune année scolaire active.</div>
        <?php else: ?>
            
            <div class="d-flex justify-content-between align-items-center mb-4 no-print">
                <h2 class="page-title mb-0">Suivi des billets non rendus</h2>
                <div>
                    <button onclick="window.print()" class="btn btn-outline-primary me-2">
                        <i class="bi bi-printer me-1"></i>Imprimer
                    </button>
                    <a href="eleves.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Retour
                    </a>
                </div>
            </div>
            
            <div class="alert alert-info no-print">
                <i class="bi bi-calendar3 me-2"></i>
                <strong>Année scolaire :</strong> <?= htmlspecialchars($annee['libelle']) ?>
                <span class="float-end">
                    <i class="bi bi-clock me-1"></i>
                    Mis à jour le <?= date('d/m/Y à H:i') ?>
                </span>
            </div>
            
            <!-- Statistiques globales -->
            <div class="row mb-4 no-print">
                <div class="col-md-4">
                    <div class="card stats-card text-center">
                        <div class="card-body">
                            <i class="bi bi-people fs-1 text-primary mb-2"></i>
                            <h3 class="text-primary"><?= count($eleves) ?></h3>
                            <p class="mb-0">Élèves concernés</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card stats-card text-center">
                        <div class="card-body">
                            <i class="bi bi-exclamation-triangle fs-1 text-warning mb-2"></i>
                            <h3 class="text-warning">
                                <?= array_sum(array_column($eleves, 'billets_vraiment_non_rendus')) ?>
                            </h3>
                            <p class="mb-0">Billets non rendus</p>
                            <small class="text-muted"><?= array_sum(array_column($eleves, 'nb_tranches_non_rendues')) ?> carnets concernés</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card stats-card text-center">
                        <div class="card-body">
                            <i class="bi bi-building fs-1 text-info mb-2"></i>
                            <h3 class="text-info">
                                <?= count($elevesParClasse) ?>
                            </h3>
                            <p class="mb-0">Classes concernées</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if (empty($eleves)): ?>
                <div class="alert alert-success-custom text-center py-5">
                    <i class="bi bi-check-circle-fill display-1 text-success mb-3"></i>
                    <h3 class="text-success">Excellente nouvelle !</h3>
                    <p class="lead">Tous les billets ont été rendus. Il n'y a aucun suivi à effectuer.</p>
                </div>
            <?php else: ?>
                
                <!-- Vue d'ensemble par classe -->
                <div class="card mb-4 no-print">
                    <div class="card-header">
                        <h4 class="mb-0">Vue d'ensemble par classe</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Classe</th>
                                        <th>Niveau</th>
                                        <th>Total élèves</th>
                                        <th>Avec billets</th>
                                        <th>Non rendus</th>
                                        <th>Progression</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($statistiques as $stat): ?>
                                        <?php 
                                        $pourcentageRendu = $stat['eleves_avec_billets'] > 0 
                                            ? round((($stat['eleves_avec_billets'] - $stat['eleves_non_rendus']) / $stat['eleves_avec_billets']) * 100) 
                                            : 100;
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($stat['classe']) ?></td>
                                            <td>
                                                <span class="niveau-badge niveau-<?= strtolower($stat['niveau']) ?>">
                                                    <?= htmlspecialchars($stat['niveau']) ?>
                                                </span>
                                            </td>
                                            <td><?= $stat['total_eleves'] ?></td>
                                            <td><?= $stat['eleves_avec_billets'] ?></td>
                                            <td>
                                                <?php if ($stat['eleves_non_rendus'] > 0): ?>
                                                    <span class="badge bg-danger"><?= $stat['eleves_non_rendus'] ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="progress progress-custom">
                                                    <div class="progress-bar bg-success" style="width: <?= $pourcentageRendu ?>%"></div>
                                                </div>
                                                <small class="text-muted"><?= $pourcentageRendu ?>% rendus</small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Liste détaillée par classe (version écran) -->
                <div class="screen-version">
                    <?php foreach ($elevesParClasse as $groupe): ?>
                        <div class="card mb-4">
                            <div class="classe-header">
                                <h4 class="mb-0">
                                    <?= htmlspecialchars($groupe['classe']) ?>
                                    <span class="niveau-badge niveau-<?= strtolower($groupe['niveau']) ?>">
                                        <?= htmlspecialchars($groupe['niveau']) ?>
                                    </span>
                                    <span class="badge bg-light text-dark ms-3">
                                        <?= count($groupe['eleves']) ?> élève(s) concerné(s)
                                    </span>
                                </h4>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach ($groupe['eleves'] as $eleve): ?>
                                        <div class="col-md-6 col-lg-4 mb-3">
                                            <div class="card eleve-card h-100">
                                                <div class="card-body">
                                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                                        <h6 class="card-title mb-0">
                                                            <?= htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom']) ?>
                                                        </h6>
                                                        <span class="badge bg-danger">
                                                            <?= $eleve['nb_tranches_non_rendues'] ?> carnet(s)
                                                        </span>
                                                    </div>
                                                    
                                                    <div class="carnets-details">
                                                        <strong>Carnets non rendus :</strong><br>
                                                        <?= htmlspecialchars($eleve['carnets_non_rendus'] ?: 'Aucun détail') ?>
                                                    </div>
                                                    
                                                    <div class="mt-3 d-flex justify-content-between align-items-center">
                                                        <small class="text-muted">
                                                            <i class="bi bi-calendar3 me-1"></i>
                                                            Attribué le <?= date('d/m/Y', strtotime($eleve['date_attribution'])) ?>
                                                        </small>
                                                        <a href="eleve_detail.php?id=<?= $eleve['id_eleve'] ?>" 
                                                           class="btn btn-sm btn-outline-primary no-print">
                                                            <i class="bi bi-eye me-1"></i>Voir
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Version print (tableaux) -->
                <div class="print-version">
                    <?php $indexClasse = 0; ?>
                    <?php foreach ($elevesParClasse as $groupe): ?>
                        <?php if ($indexClasse > 0): ?>
                            <div class="page-break"></div>
                        <?php endif; ?>
                        
                        <div class="print-header">
                            <h2>Suivi des billets de tombola non rendus</h2>
                            <h3>Année scolaire : <?= htmlspecialchars($annee['libelle']) ?></h3>
                            <p>Date d'édition : <?= date('d/m/Y à H:i') ?></p>
                            <p><strong>Total : <?= count($eleves) ?> élève(s) concerné(s) dans <?= count($elevesParClasse) ?> classe(s)</strong></p>
                        </div>
                        
                        <div class="classe-section">
                            <div class="classe-title">
                                Classe <?= strtoupper(htmlspecialchars($groupe['classe'])) ?> - <?= strtoupper(htmlspecialchars($groupe['niveau'])) ?>
                                <span class="classe-stats">(<?= count($groupe['eleves']) ?> élève(s) concerné(s))</span>
                            </div>
                            
                            <table class="classe-table">
                                <thead>
                                    <tr>
                                        <th style="width: 4%;">N°</th>
                                        <th style="width: 22%;">NOM</th>
                                        <th style="width: 18%;">PRÉNOM</th>
                                        <th style="width: 8%;">BILLETS</th>
                                        <th style="width: 32%;">DÉTAILS DES CARNETS NON RENDUS</th>
                                        <th style="width: 11%;">DATE ATTRIB.</th>
                                        <th style="width: 5%;">RENDU</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($groupe['eleves'] as $indexEleve => $eleve): ?>
                                        <tr>
                                            <td><?= $indexEleve + 1 ?></td>
                                            <td class="nom-eleve"><?= strtoupper(htmlspecialchars($eleve['nom'])) ?></td>
                                            <td class="prenom-eleve"><?= ucfirst(strtolower(htmlspecialchars($eleve['prenom']))) ?></td>
                                            <td style="color: #d63384; font-weight: bold;"><?= $eleve['billets_vraiment_non_rendus'] ?></td>
                                            <td class="details-carnets">
                                                <?= htmlspecialchars($eleve['carnets_non_rendus'] ?: 'Aucun détail disponible') ?>
                                            </td>
                                            <td><?= date('d/m/Y', strtotime($eleve['date_attribution'])) ?></td>
                                            <td>☐</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php $indexClasse++; ?>
                    <?php endforeach; ?>
                </div>
                
                <!-- Instructions -->
                <div class="card mt-4 no-print">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">
                            <i class="bi bi-info-circle me-2"></i>Instructions pour le suivi
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Actions recommandées :</h6>
                                <ul>
                                    <li>Contacter les familles des élèves listés</li>
                                    <li>Rappeler les dates limites de retour</li>
                                    <li>Vérifier si les billets ont été perdus</li>
                                    <li>Organiser une collecte en classe</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6>Gestion dans l'application :</h6>
                                <ul>
                                    <li>Cliquez sur "Voir" pour accéder au détail de l'élève</li>
                                    <li>Utilisez le bouton "Retourner" pour marquer un carnet comme rendu</li>
                                    <li>Enregistrez les ventes si des billets ont été vendus</li>
                                    <li>Cette page se met à jour automatiquement</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <!-- Bouton d'impression flottant -->
    <?php if (!empty($eleves)): ?>
        <button onclick="window.print()" class="btn btn-primary btn-lg print-button no-print shadow">
            <i class="bi bi-printer"></i>
        </button>
    <?php endif; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Smooth scroll pour les liens internes
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });
    </script>
</body>
</html>