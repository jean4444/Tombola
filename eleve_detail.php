<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log pour déboguer
file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Fichier chargé\n", FILE_APPEND);

// Log des informations POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents('debug.log', date('Y-m-d H:i:s') . " - POST reçu: " . 
        json_encode($_POST) . "\n", FILE_APPEND);
}

require_once 'config.php';
require_once 'auth.php';
require_once 'billets.php';

// Vérifier si l'utilisateur est connecté
redirigerSiNonConnecte();

$db = connectDB();
$idAnnee = getAnneeActive($db);

// Récupérer l'ID de l'élève
$idEleve = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$idEleve) {
    header('Location: eleves.php');
    exit;
}

// Fonction pour vérifier si un carnet est complet
function verifierCarnetComplet($db, $idTranche) {
    // Log pour déboguer
    file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Vérification carnet complet: " . $idTranche . "\n", FILE_APPEND);
    
    // Vérifier si tous les billets du carnet sont vendus ou retournés
    $stmt = $db->prepare("
        SELECT 
            t.numero_debut, t.numero_fin,
            COUNT(CASE WHEN b.statut = 'vendu' OR b.statut = 'retourne' THEN 1 END) as billets_traites,
            (t.numero_fin - t.numero_debut + 1) as total_billets
        FROM tranches t
        LEFT JOIN billets b ON t.id_tranche = b.id_tranche
        WHERE t.id_tranche = ?
        GROUP BY t.id_tranche
    ");
    $stmt->execute([$idTranche]);
    $result = $stmt->fetch();
    
    if ($result && $result['billets_traites'] >= $result['total_billets']) {
        // Tous les billets sont traités, marquer le carnet comme terminé
        $stmt = $db->prepare("
            UPDATE tranches
            SET date_retour = NOW()
            WHERE id_tranche = ? AND date_retour IS NULL
        ");
        $stmt->execute([$idTranche]);
        file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Carnet marqué comme complet: " . $idTranche . "\n", FILE_APPEND);
        return true;
    }
    
    return false;
}

// Initialisation des variables pour les messages
$message = '';
$messageType = '';

// TRAITEMENT DES FORMULAIRES POST - Structure réorganisée
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupérer l'action du formulaire
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Action: " . $action . "\n", FILE_APPEND);
    
    // Traitement selon l'action
    switch ($action) {
        // ======== ATTRIBUER UN CARNET ========
        case 'attribuer_carnet':
            file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Traitement attribuer_carnet\n", FILE_APPEND);
            $idTranche = isset($_POST['id_tranche']) ? intval($_POST['id_tranche']) : 0;
            
            if ($idTranche <= 0) {
                $message = 'Veuillez sélectionner un carnet valide.';
                $messageType = 'danger';
            } else {
                // Vérifier si la tranche existe et est disponible
                $stmt = $db->prepare("
                    SELECT id_tranche, numero_debut, numero_fin
                    FROM tranches 
                    WHERE id_tranche = ? AND id_eleve IS NULL AND id_annee = ?
                ");
                $stmt->execute([$idTranche, $idAnnee]);
                $tranche = $stmt->fetch();
                
                if (!$tranche) {
                    $message = 'Ce carnet n\'est pas disponible ou n\'existe pas.';
                    $messageType = 'danger';
                } else {
                    // Attribuer la tranche à l'élève et réinitialiser la date de retour
                    $stmt = $db->prepare("
                        UPDATE tranches 
                        SET id_eleve = ?, date_attribution = NOW(), date_retour = NULL
                        WHERE id_tranche = ?
                    ");
                    $stmt->execute([$idEleve, $idTranche]);
                    
                    // Mettre également à jour les statuts des billets si nécessaire
                    $stmt = $db->prepare("
                        UPDATE billets
                        SET statut = 'attribue'
                        WHERE id_tranche = ? AND statut = 'retourne'
                    ");
                    $stmt->execute([$idTranche]);
                    
                    $message = 'Le carnet de billets #' . $idTranche . ' (' . $tranche['numero_debut'] . ' à ' . $tranche['numero_fin'] . ') a été attribué avec succès.';
                    $messageType = 'success';
                }
            }
            break;
            
        // ======== ENREGISTRER UNE VENTE ========
        case 'enregistrer_vente':
            file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Traitement enregistrer_vente\n", FILE_APPEND);
            $billetsVendus = isset($_POST['billets_vendus']) ? intval($_POST['billets_vendus']) : 0;
            $billetsRetournes = isset($_POST['billets_retournes']) ? intval($_POST['billets_retournes']) : 0;
            $montantTotal = isset($_POST['montant_total']) ? floatval(str_replace(',', '.', $_POST['montant_total'])) : 0;
            $dateVente = isset($_POST['date_vente']) ? $_POST['date_vente'] : date('Y-m-d');
            
            // Vérifier les billets disponibles avant d'enregistrer la vente
            $stmt = $db->prepare("
                SELECT 
                    t.id_tranche,
                    (t.numero_fin - t.numero_debut + 1) as total_billets,
                    COUNT(CASE WHEN b.statut = 'vendu' THEN 1 END) as billets_vendus,
                    COUNT(CASE WHEN b.statut = 'retourne' THEN 1 END) as billets_retournes
                FROM tranches t
                LEFT JOIN billets b ON t.id_tranche = b.id_tranche
                WHERE t.id_eleve = ? AND t.id_annee = ? AND t.date_retour IS NULL
                GROUP BY t.id_tranche
            ");
            $stmt->execute([$idEleve, $idAnnee]);
            $tranches = $stmt->fetchAll();

            $billetsDisponibles = 0;
            foreach ($tranches as $tranche) {
                $billetsDisponibles += $tranche['total_billets'] - $tranche['billets_vendus'] - $tranche['billets_retournes'];
            }

            if ($billetsVendus > $billetsDisponibles) {
                $message = 'Erreur : Cet élève n\'a que ' . $billetsDisponibles . ' billets disponibles. Impossible de vendre ' . $billetsVendus . ' billets.';
                $messageType = 'danger';
            } elseif ($billetsVendus < 0 || $billetsRetournes < 0 || $montantTotal < 0) {
                $message = 'Les valeurs ne peuvent pas être négatives.';
                $messageType = 'danger';
            } else {
                // Vérifier si une vente existe déjà pour cet élève cette année
                $stmt = $db->prepare("SELECT id_vente FROM ventes WHERE id_eleve = ? AND id_annee = ?");
                $stmt->execute([$idEleve, $idAnnee]);
                $venteExistante = $stmt->fetch();
                
                if ($venteExistante) {
                    // Mettre à jour la vente existante
                    $stmt = $db->prepare("
                        UPDATE ventes 
                        SET billets_vendus = billets_vendus + ?, 
                            billets_retournes = billets_retournes + ?, 
                            montant_total = montant_total + ?,
                            date_mise_a_jour = NOW()
                        WHERE id_eleve = ? AND id_annee = ?
                    ");
                    $stmt->execute([$billetsVendus, $billetsRetournes, $montantTotal, $idEleve, $idAnnee]);
                    $message = 'La vente a été mise à jour avec succès.';
                    $messageType = 'success';
                
                    // Vérifier si des carnets sont devenus complets
                    $stmt = $db->prepare("
                        SELECT id_tranche
                        FROM tranches
                        WHERE id_eleve = ? AND id_annee = ? AND date_retour IS NULL
                    ");
                    $stmt->execute([$idEleve, $idAnnee]);
                    $tranchesEleve = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    foreach ($tranchesEleve as $idTranche) {
                        verifierCarnetComplet($db, $idTranche);
                    }
                } else {
                    // Créer une nouvelle vente
                    $stmt = $db->prepare("
                        INSERT INTO ventes (id_eleve, id_annee, billets_vendus, billets_retournes, montant_total, date_vente, date_mise_a_jour)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$idEleve, $idAnnee, $billetsVendus, $billetsRetournes, $montantTotal, $dateVente]);
                    $message = 'La vente a été enregistrée avec succès.';
                    $messageType = 'success';
                    
                    // Vérifier si des carnets sont devenus complets
                    $stmt = $db->prepare("
                        SELECT id_tranche
                        FROM tranches
                        WHERE id_eleve = ? AND id_annee = ? AND date_retour IS NULL
                    ");
                    $stmt->execute([$idEleve, $idAnnee]);
                    $tranchesEleve = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    foreach ($tranchesEleve as $idTranche) {
                        verifierCarnetComplet($db, $idTranche);
                    }
                }
            }
            
            // Mise à jour du statut des billets si nécessaire
            if ($billetsVendus > 0 || $billetsRetournes > 0) {
                // Récupérer les tranches de billets disponibles
                $stmt = $db->prepare("
                    SELECT id_tranche, numero_debut, numero_fin,
                    (SELECT COUNT(*) FROM billets b WHERE b.id_tranche = t.id_tranche AND b.statut = 'vendu') as billets_vendus,
                    (SELECT COUNT(*) FROM billets b WHERE b.id_tranche = t.id_tranche AND b.statut = 'retourne') as billets_retournes
                    FROM tranches t
                    WHERE t.id_eleve = ? AND t.id_annee = ? AND t.date_retour IS NULL
                    ORDER BY t.numero_debut
                ");
                $stmt->execute([$idEleve, $idAnnee]);
                $tranches = $stmt->fetchAll();
                
                $billetsAVendre = $billetsVendus;
                $billetsARetourner = $billetsRetournes;
                $prixUnitaire = $billetsVendus > 0 ? $montantTotal / $billetsVendus : 0;
                
                foreach ($tranches as $tranche) {
                    $debut = $tranche['numero_debut'];
                    $fin = $tranche['numero_fin'];
                    
                    if ($billetsAVendre > 0) {
                        // Récupérer les billets disponibles (statut 'disponible' ou 'attribue')
                        $stmt = $db->prepare("
                            SELECT id_billet, numero, statut
                            FROM billets
                            WHERE id_tranche = ? AND (statut = 'disponible' OR statut = 'attribue')
                            ORDER BY numero
                            LIMIT ?
                        ");
                        $stmt->execute([$tranche['id_tranche'], $billetsAVendre]);
                        $billetsAModifier = $stmt->fetchAll();
                        
                        foreach ($billetsAModifier as $billet) {
                            // Mettre à jour le statut du billet
                            $stmt = $db->prepare("
                                UPDATE billets
                                SET statut = 'vendu', prix_unitaire = ?
                                WHERE id_billet = ?
                            ");
                            $stmt->execute([$prixUnitaire, $billet['id_billet']]);
                            $billetsAVendre--;
                            
                            if ($billetsAVendre <= 0) {
                                break;
                            }
                        }
                    }
                    
                    if ($billetsARetourner > 0) {
                        // Récupérer les billets disponibles restants (après avoir traité les ventes)
                        $stmt = $db->prepare("
                            SELECT id_billet, numero, statut
                            FROM billets
                            WHERE id_tranche = ? AND (statut = 'disponible' OR statut = 'attribue')
                            ORDER BY numero DESC
                            LIMIT ?
                        ");
                        $stmt->execute([$tranche['id_tranche'], $billetsARetourner]);
                        $billetsAModifier = $stmt->fetchAll();
                        
                        foreach ($billetsAModifier as $billet) {
                            // Mettre à jour le statut du billet
                            $stmt = $db->prepare("
                                UPDATE billets
                                SET statut = 'retourne'
                                WHERE id_billet = ?
                            ");
                            $stmt->execute([$billet['id_billet']]);
                            $billetsARetourner--;
                            
                            if ($billetsARetourner <= 0) {
                                break;
                            }
                        }
                    }
                    
                    if ($billetsAVendre <= 0 && $billetsARetourner <= 0) {
                        break;
                    }
                }
                
                // Vérifier si des carnets sont devenus complets
                foreach ($tranches as $tranche) {
                    verifierCarnetComplet($db, $tranche['id_tranche']);
                }
            }
            break;
            
        // ======== RETOURNER UN CARNET ========
        case 'retourner_carnet':
            file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Traitement retourner_carnet\n", FILE_APPEND);
            $idTranche = isset($_POST['id_tranche']) ? intval($_POST['id_tranche']) : 0;
            file_put_contents('debug.log', date('Y-m-d H:i:s') . " - ID tranche: " . $idTranche . "\n", FILE_APPEND);
            
            try {
                // Vérifier si le carnet appartient bien à cet élève
                $stmt = $db->prepare("
                    SELECT numero_debut, numero_fin, date_retour
                    FROM tranches 
                    WHERE id_tranche = ? AND id_eleve = ? AND id_annee = ?
                ");
                $stmt->execute([$idTranche, $idEleve, $idAnnee]);
                $tranche = $stmt->fetch();
                file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Vérification tranche: " . json_encode($tranche) . "\n", FILE_APPEND);
                
                if (!$tranche) {
                    $message = 'Ce carnet n\'appartient pas à cet élève ou n\'existe pas.';
                    $messageType = 'danger';
                    file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Carnet n'appartient pas à l'élève\n", FILE_APPEND);
                } elseif ($tranche['date_retour'] !== null) {
                    $message = 'Ce carnet a déjà été retourné.';
                    $messageType = 'warning';
                    file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Carnet déjà retourné\n", FILE_APPEND);
                } else {
                    // Compter les billets déjà vendus et retournés
                    $stmt = $db->prepare("
                        SELECT 
                            COUNT(CASE WHEN statut = 'vendu' THEN 1 END) as vendus,
                            COUNT(CASE WHEN statut = 'retourne' THEN 1 END) as retournes,
                            COUNT(CASE WHEN statut = 'disponible' OR statut = 'attribue' THEN 1 END) as disponibles
                        FROM billets
                        WHERE id_tranche = ?
                    ");
                    $stmt->execute([$idTranche]);
                    $stats = $stmt->fetch();
                    file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Stats billets: " . json_encode($stats) . "\n", FILE_APPEND);
                    
                    $billetsVendus = $stats['vendus'] ?? 0;
                    $billetsDejaRetournes = $stats['retournes'] ?? 0;
                    $billetsDisponibles = $stats['disponibles'] ?? 0;
                    
                    // Marquer tous les billets non vendus comme retournés
                    $stmt = $db->prepare("
                        UPDATE billets
                        SET statut = 'retourne'
                        WHERE id_tranche = ? AND (statut = 'disponible' OR statut = 'attribue')
                    ");
                    $stmt->execute([$idTranche]);
                    $billetsUpdated = $stmt->rowCount();
                    file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Billets mis à jour: " . $billetsUpdated . "\n", FILE_APPEND);
                    
                    // Mettre à jour les statistiques de vente si des billets ont été marqués comme retournés
                    if ($billetsUpdated > 0) {
                        $stmt = $db->prepare("
                            UPDATE ventes
                            SET billets_retournes = billets_retournes + ?
                            WHERE id_eleve = ? AND id_annee = ?
                        ");
                        $stmt->execute([$billetsUpdated, $idEleve, $idAnnee]);
                        file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Ventes mises à jour\n", FILE_APPEND);
                    }
                    
                    // Vérifier si des billets ont été vendus pour ce carnet
                    if ($billetsVendus > 0) {
                        // Si des billets ont déjà été vendus, on marque seulement le carnet comme retourné
                        // mais on le garde attribué à l'élève pour garder la trace des ventes
                        $stmt = $db->prepare("
                            UPDATE tranches 
                            SET date_retour = NOW()
                            WHERE id_tranche = ? 
                        ");
                        $stmt->execute([$idTranche]);
                        
                        $message = 'Le carnet a été retourné avec succès. Comme ' . $billetsVendus . ' billet(s) ont déjà été vendus, le carnet reste attribué à cet élève pour le suivi des ventes.';
                    } else {
                        // Aucun billet vendu, on peut libérer complètement le carnet
                        $stmt = $db->prepare("
                            UPDATE tranches 
                            SET date_retour = NOW(), id_eleve = NULL
                            WHERE id_tranche = ?
                        ");
                        $stmt->execute([$idTranche]);
                        
                        $message = 'Le carnet a été retourné avec succès et est maintenant disponible pour réattribution.';
                    }
                    
                    $messageType = 'success';
                    file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Retour réussi\n", FILE_APPEND);
                }
            } catch (Exception $e) {
                $message = 'Erreur lors du retour du carnet: ' . $e->getMessage();
                $messageType = 'danger';
                file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Exception: " . $e->getMessage() . "\n", FILE_APPEND);
            }
            break;
            
        // Cas par défaut pour les actions non reconnues
        default:
            file_put_contents('debug.log', date('Y-m-d H:i:s') . " - Action non reconnue: " . $action . "\n", FILE_APPEND);
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détail élève - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .page-title {
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #007bff;
            color: #2D3748;
        }
        
        .card {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.07);
            margin-bottom: 20px;
        }
        
        .card-header {
            padding: 15px 20px;
            font-weight: 600;
        }
        
        .niveau-ps, .niveau-ms, .niveau-gs, .niveau-cp, .niveau-ce1, .niveau-ce2, .niveau-cm1, .niveau-cm2 {
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        
        .niveau-ps { background-color: #FF6B95; }
        .niveau-ms { background-color: #FFB347; }
        .niveau-gs { background-color: #FFDE59; color: #664D03; }
        .niveau-cp { background-color: #4ADE80; color: #14532D; }
        .niveau-ce1 { background-color: #38BDF8; color: #0C4A6E; }
        .niveau-ce2 { background-color: #60A5FA; color: #1E3A8A; }
        .niveau-cm1 { background-color: #A78BFA; }
        .niveau-cm2 { background-color: #F472B6; }
        
        .badge {
            padding: 6px 10px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .progress-bar-billets {
            height: 10px;
            border-radius: 5px;
            margin-top: 5px;
        }
        
        .billet-card {
            border-left: 3px solid #4ADE80;
            border-radius: 8px;
            margin-bottom: 15px;
            padding: 10px;
            background-color: #f8f9fa;
        }
        
        .billet-complete {
            border-left-color: #F472B6;
        }
        
        .billet-progress {
            display: flex;
            align-items: center;
            margin-top: 5px;
        }
        
        .billet-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
        }
        
        .billet-stat {
            text-align: center;
            padding: 5px 10px;
            background-color: #ffffff;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .btn-sm {
            border-radius: 20px;
            padding: 5px 10px;
        }
        
        .summary-card {
            border-radius: 10px;
            background-color: #f8f9fa;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .summary-stat {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .summary-stat i {
            font-size: 1.5rem;
            margin-right: 10px;
            width: 30px;
            text-align: center;
        }
        
        .summary-number {
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .summary-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container my-4">
        <?php
        if (!$idAnnee) {
            echo '<div class="alert alert-warning">Aucune année scolaire active. Veuillez en créer une dans la section Administration.</div>';
        } else {
            // Récupérer les informations de l'élève
            $stmt = $db->prepare("
                SELECT 
                    e.id_eleve, e.nom, e.prenom,
                    c.id_classe, c.nom as classe, c.niveau,
                    COALESCE(v.billets_vendus, 0) as billets_vendus,
                    COALESCE(v.billets_retournes, 0) as billets_retournes,
                    COALESCE(v.montant_total, 0) as montant_total
                FROM eleves e
                JOIN classes c ON e.id_classe = c.id_classe
                LEFT JOIN ventes v ON e.id_eleve = v.id_eleve AND v.id_annee = ?
                WHERE e.id_eleve = ? AND e.id_annee = ?
            ");
            $stmt->execute([$idAnnee, $idEleve, $idAnnee]);
            $eleve = $stmt->fetch();
            
            if ($eleve) {
                // Afficher le message de confirmation si présent
                if ($message) {
                    echo '<div class="alert alert-' . $messageType . ' alert-dismissible fade show" role="alert">';
                    echo $message;
                    echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
                    echo '</div>';
                }
                
                echo '<div class="d-flex justify-content-between align-items-center mb-4">';
                echo '<h2 class="page-title mb-0">' . htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom']) . '</h2>';
                echo '<div>';
                echo '<a href="eleves.php?classe=' . $eleve['id_classe'] . '" class="btn btn-outline-secondary me-2"><i class="bi bi-arrow-left"></i> Retour</a>';
                echo '<div class="btn-group">';
                echo '<button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">';
                echo '<i class="bi bi-plus-circle me-1"></i> Actions';
                echo '</button>';
                echo '<ul class="dropdown-menu dropdown-menu-end">';
                echo '<li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#venteModal"><i class="bi bi-cash-coin me-1"></i> Enregistrer une vente</a></li>';
                echo '<li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#carnetModal"><i class="bi bi-journal-plus me-1"></i> Attribuer un carnet</a></li>';
                echo '<li><hr class="dropdown-divider"></li>';
                echo '<li><a class="dropdown-item" href="correction.php?id=' . $idEleve . '"><i class="bi bi-tools me-1"></i> Réparer les données</a></li>';
                echo '</ul>';
                echo '</div>';
                echo '</div>';
                echo '</div>';
                
                // Résumé des ventes
                echo '<div class="row mb-4">';
                
                // Carte d'information de l'élève
                echo '<div class="col-lg-4">';
                
                // Carte d'information générale
                echo '<div class="card mb-3">';
                echo '<div class="card-header bg-light">';
                echo '<h5 class="card-title mb-0">Informations</h5>';
                echo '</div>';
                echo '<div class="card-body">';
                echo '<p><strong>Classe:</strong> ' . htmlspecialchars($eleve['classe']) . '</p>';
echo '<p><strong>Niveau:</strong> <span class="badge niveau-' . strtolower($eleve['niveau']) . '">' . htmlspecialchars($eleve['niveau']) . '</span></p>';
                echo '</div>';
                echo '</div>';
                
                // Carte de résumé des ventes
                echo '<div class="card">';
                echo '<div class="card-header bg-primary text-white">';
                echo '<h5 class="card-title mb-0">Résumé des ventes</h5>';
                echo '</div>';
                echo '<div class="card-body">';
                echo '<div class="summary-stat">';
                echo '<i class="bi bi-ticket-perforated text-success"></i>';
                echo '<div>';
                echo '<div class="summary-number">' . $eleve['billets_vendus'] . '</div>';
                echo '<div class="summary-label">Billets vendus</div>';
                echo '</div>';
                echo '</div>';
                echo '<div class="summary-stat">';
                echo '<i class="bi bi-arrow-return-left text-warning"></i>';
                echo '<div>';
                echo '<div class="summary-number">' . $eleve['billets_retournes'] . '</div>';
                echo '<div class="summary-label">Billets retournés</div>';
                echo '</div>';
                echo '</div>';
                echo '<div class="summary-stat">';
                echo '<i class="bi bi-cash-coin text-danger"></i>';
                echo '<div>';
                echo '<div class="summary-number">' . number_format($eleve['montant_total'], 2, ',', ' ') . ' €</div>';
                echo '<div class="summary-label">Montant total</div>';
                echo '</div>';
                echo '</div>';
                
                // Calculer le prix moyen par billet
                if ($eleve['billets_vendus'] > 0) {
                    $prixMoyen = $eleve['montant_total'] / $eleve['billets_vendus'];
                    echo '<div class="summary-stat">';
                    echo '<i class="bi bi-calculator text-info"></i>';
                    echo '<div>';
                    echo '<div class="summary-number">' . number_format($prixMoyen, 2, ',', ' ') . ' €</div>';
                    echo '<div class="summary-label">Prix moyen par billet</div>';
                    echo '</div>';
                    echo '</div>';
                }
                
                echo '</div>';
                echo '</div>';
                echo '</div>';
                
                // Section des carnets de billets
                echo '<div class="col-lg-8">';
                echo '<div class="card">';
                echo '<div class="card-header bg-success text-white d-flex justify-content-between align-items-center">';
                echo '<h5 class="card-title mb-0">Carnets de billets</h5>';
                echo '<button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#carnetModal"><i class="bi bi-plus-circle me-1"></i>Nouveau carnet</button>';
                echo '</div>';
                echo '<div class="card-body">';
                
                // Récupérer les tranches de billets
                $stmt = $db->prepare("
                    SELECT 
                        t.id_tranche, t.numero_debut, t.numero_fin, 
                        t.date_attribution, t.date_retour,
                        COUNT(CASE WHEN b.statut = 'vendu' THEN 1 END) as nb_vendus,
                        COUNT(CASE WHEN b.statut = 'retourne' THEN 1 END) as nb_retournes,
                        COUNT(CASE WHEN b.statut = 'disponible' OR b.statut = 'attribue' THEN 1 END) as nb_disponibles
                    FROM tranches t
                    LEFT JOIN billets b ON t.id_tranche = b.id_tranche
                    WHERE t.id_eleve = ? AND t.id_annee = ?
                    GROUP BY t.id_tranche
                    ORDER BY t.date_attribution DESC, t.numero_debut
                ");
                $stmt->execute([$idEleve, $idAnnee]);
                $tranches = $stmt->fetchAll();
                
                if (count($tranches) > 0) {
                    foreach ($tranches as $tranche) {
                        $totalBillets = $tranche['numero_fin'] - $tranche['numero_debut'] + 1;
                        $pourcentageVendus = ($tranche['nb_vendus'] / $totalBillets) * 100;
                        $pourcentageRetournes = ($tranche['nb_retournes'] / $totalBillets) * 100;
                        $pourcentageDisponibles = ($tranche['nb_disponibles'] / $totalBillets) * 100;
                        
                        $estComplete = ($tranche['date_retour'] !== null);
                        $cardClass = $estComplete ? 'billet-card billet-complete' : 'billet-card';
                        
                        echo '<div class="' . $cardClass . '">';
                        echo '<div class="d-flex justify-content-between align-items-center">';
                        echo '<h5 class="mb-0">Carnet #' . $tranche['id_tranche'] . ' : ' . $tranche['numero_debut'] . ' à ' . $tranche['numero_fin'] . '</h5>';
                        
                        // Statut et date
                        if ($estComplete) {
                            echo '<span class="badge bg-secondary">Terminé le ' . date('d/m/Y', strtotime($tranche['date_retour'])) . '</span>';
                        } else {
                            echo '<span class="badge bg-success">En cours depuis le ' . date('d/m/Y', strtotime($tranche['date_attribution'])) . '</span>';
                        }
                        echo '</div>';
                        
                        // Progression
                        echo '<div class="mt-2">';
                        echo '<div class="small mb-1 d-flex justify-content-between">';
                        echo '<span>Progression</span>';
                        echo '<span>' . ($tranche['nb_vendus'] + $tranche['nb_retournes']) . ' / ' . $totalBillets . ' (' . round($pourcentageVendus + $pourcentageRetournes) . '%)</span>';
                        echo '</div>';
                        echo '<div class="progress progress-bar-billets">';
                        echo '<div class="progress-bar bg-success" role="progressbar" style="width: ' . $pourcentageVendus . '%" aria-valuenow="' . $pourcentageVendus . '" aria-valuemin="0" aria-valuemax="100" title="Vendus"></div>';
                        echo '<div class="progress-bar bg-warning" role="progressbar" style="width: ' . $pourcentageRetournes . '%" aria-valuenow="' . $pourcentageRetournes . '" aria-valuemin="0" aria-valuemax="100" title="Retournés"></div>';
                        echo '</div>';
                        
                        // Statistiques du carnet
                        echo '<div class="billet-stats">';
                        echo '<div class="billet-stat"><strong>' . $tranche['nb_vendus'] . '</strong><div class="small text-success">Vendus</div></div>';
                        echo '<div class="billet-stat"><strong>' . $tranche['nb_retournes'] . '</strong><div class="small text-warning">Retournés</div></div>';
                        echo '<div class="billet-stat"><strong>' . $tranche['nb_disponibles'] . '</strong><div class="small text-primary">Disponibles</div></div>';
                        echo '<div class="billet-stat"><strong>' . $totalBillets . '</strong><div class="small text-secondary">Total</div></div>';
                        echo '</div>';
                      
                        // Boutons d'action
                        if (!$estComplete) {
                            echo '<div class="mt-2 d-flex justify-content-end">';
							echo '<button type="button" class="btn btn-sm btn-primary me-2" onclick="prepareVente(' . $tranche['id_tranche'] . ', \'Carnet #' . $tranche['id_tranche'] . '\')"><i class="bi bi-cash-coin me-1"></i>Vendre</button>';
                           echo '<button type="button" class="btn btn-sm btn-warning" onclick="retournerCarnet(' . $tranche['id_tranche'] . ')"><i class="bi bi-box-arrow-in-left me-1"></i>Retourner</button>';
                            echo '</div>';
                        }
                        
                        echo '</div>';
                        echo '</div>';
                    }
                } else {
                    echo '<div class="alert alert-info">Aucun carnet de billets attribué à cet élève.</div>';
                    echo '<div class="text-center mt-3">';
                    echo '<button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#carnetModal">';
                    echo '<i class="bi bi-journal-plus me-1"></i> Attribuer un carnet de billets';
                    echo '</button>';
                    echo '</div>';
                }
                
                echo '</div>';
                echo '</div>';
                
                // Historique des ventes
                echo '<div class="card mt-4">';
                echo '<div class="card-header bg-info text-white">';
                echo '<h5 class="card-title mb-0">Historique des ventes</h5>';
                echo '</div>';
                echo '<div class="card-body">';
                
                // Récupérer l'historique des ventes
                $stmt = $db->prepare("
                    SELECT 
                        b.id_billet, b.numero, b.statut, 
                        t.id_tranche, t.numero_debut, t.numero_fin,
                        b.prix_unitaire
                    FROM billets b
                    JOIN tranches t ON b.id_tranche = t.id_tranche
                    WHERE t.id_eleve = ? AND t.id_annee = ? AND b.statut IN ('vendu', 'retourne')
                    ORDER BY b.id_billet DESC
                    LIMIT 50
                ");
                $stmt->execute([$idEleve, $idAnnee]);
                $billets = $stmt->fetchAll();
                
                if (count($billets) > 0) {
                    echo '<div class="table-responsive">';
                    echo '<table class="table table-sm table-striped table-hover">';
                    echo '<thead>';
                    echo '<tr>';
                    echo '<th>N° Billet</th>';
                    echo '<th>Carnet</th>';
                    echo '<th>Statut</th>';
                    echo '<th>Prix</th>';
                    echo '</tr>';
                    echo '</thead>';
                    echo '<tbody>';
                    
                    foreach ($billets as $billet) {
                        $statusClass = $billet['statut'] === 'vendu' ? 'bg-success' : 'bg-warning text-dark';
                        $statusText = $billet['statut'] === 'vendu' ? 'Vendu' : 'Retourné';
                        
                        echo '<tr>';
                        echo '<td>' . sprintf('%04d', $billet['numero']) . '</td>';
                        echo '<td>Carnet #' . $billet['id_tranche'] . '</td>';
                        echo '<td><span class="badge ' . $statusClass . '">' . $statusText . '</span></td>';
                        echo '<td>' . ($billet['prix_unitaire'] ? number_format($billet['prix_unitaire'], 2, ',', ' ') . ' €' : '-') . '</td>';
                        echo '</tr>';
                    }
                    
                    echo '</tbody>';
                    echo '</table>';
                    echo '</div>';
                    
                    if (count($billets) === 50) {
                        echo '<div class="text-center mt-2">';
                        echo '<span class="text-muted">Affichage limité aux 50 derniers billets</span>';
                        echo '</div>';
                    }
                } else {
                    echo '<div class="alert alert-info">Aucun billet vendu ou retourné.</div>';
                }
                
                echo '</div>';
                echo '</div>';
                
                echo '</div>';
                echo '</div>';
                
                // Modal pour enregistrer une vente
                echo '<div class="modal fade" id="venteModal" tabindex="-1" aria-labelledby="venteModalLabel" aria-hidden="true">';
                echo '<div class="modal-dialog">';
                echo '<div class="modal-content">';
                echo '<form method="post" action="" class="needs-validation" novalidate>';
                echo '<input type="hidden" name="action" value="enregistrer_vente">';
                
                echo '<div class="modal-header bg-primary text-white">';
                echo '<h5 class="modal-title" id="venteModalLabel">Enregistrer une vente</h5>';
                echo '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>';
                echo '</div>';
                
                echo '<div class="modal-body">';
                echo '<div class="mb-3">';
                echo '<label for="billets_vendus" class="form-label">Billets vendus</label>';
                echo '<input type="number" class="form-control" id="billets_vendus" name="billets_vendus" min="0" value="0" required>';
                echo '<div class="invalid-feedback">Veuillez saisir un nombre valide</div>';
                echo '</div>';
                
                echo '<div class="mb-3">';
                echo '<label for="billets_retournes" class="form-label">Billets retournés</label>';
                echo '<input type="number" class="form-control" id="billets_retournes" name="billets_retournes" min="0" value="0">';
                echo '<div class="invalid-feedback">Veuillez saisir un nombre valide</div>';
                echo '</div>';
                
                echo '<div class="mb-3">';
                echo '<label for="montant_total" class="form-label">Montant total (€)</label>';
                echo '<input type="number" step="0.01" class="form-control" id="montant_total" name="montant_total" min="0" value="0.00" required>';
                echo '<div class="invalid-feedback">Veuillez saisir un montant valide</div>';
                echo '</div>';
                
                echo '<div class="mb-3">';
                echo '<label for="date_vente" class="form-label">Date de vente</label>';
                echo '<input type="date" class="form-control" id="date_vente" name="date_vente" value="' . date('Y-m-d') . '" required>';
                echo '<div class="invalid-feedback">Veuillez sélectionner une date</div>';
                echo '</div>';
                echo '</div>';
                
                echo '<div class="modal-footer">';
                echo '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>';
                echo '<button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i>Enregistrer</button>';
                echo '</div>';
                
                echo '</form>';
                echo '</div>';
                echo '</div>';
                echo '</div>';
                
                // Modal pour attribuer un carnet
                echo '<div class="modal fade" id="carnetModal" tabindex="-1" aria-labelledby="carnetModalLabel" aria-hidden="true">';
                echo '<div class="modal-dialog">';
                echo '<div class="modal-content">';
                echo '<form method="post" action="" class="needs-validation" novalidate>';
                echo '<input type="hidden" name="action" value="attribuer_carnet">';

                echo '<div class="modal-header bg-success text-white">';
                echo '<h5 class="modal-title" id="carnetModalLabel">Attribuer un carnet de billets</h5>';
                echo '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>';
                echo '</div>';

                echo '<div class="modal-body">';

                // Récupérer les tranches disponibles
                $stmt = $db->prepare("
                    SELECT id_tranche, numero_debut, numero_fin
                    FROM tranches
                    WHERE id_eleve IS NULL AND id_annee = ?
                    ORDER BY numero_debut
                ");
                $stmt->execute([$idAnnee]);
                $tranchesDisponibles = $stmt->fetchAll();

                if (count($tranchesDisponibles) > 0) {
                    echo '<div class="mb-3">';
                    echo '<label for="id_tranche" class="form-label">Sélectionner un carnet disponible</label>';
                    echo '<select class="form-select" id="id_tranche" name="id_tranche" required>';
                    echo '<option value="">-- Choisir un carnet --</option>';
                    
                    foreach ($tranchesDisponibles as $tranche) {
                        echo '<option value="' . $tranche['id_tranche'] . '">Carnet #' . $tranche['id_tranche'] . ' : ' . $tranche['numero_debut'] . ' à ' . $tranche['numero_fin'] . '</option>';
                    }
                    
                    echo '</select>';
                    echo '<div class="invalid-feedback">Veuillez sélectionner un carnet</div>';
                    echo '</div>';
                    
                    echo '<div class="alert alert-info">';
                    echo '<i class="bi bi-info-circle-fill me-2"></i> Le carnet sélectionné sera attribué à cet élève.';
                    echo '</div>';
                } else {
                    echo '<div class="alert alert-warning">';
                    echo '<i class="bi bi-exclamation-triangle-fill me-2"></i> Aucun carnet disponible. Veuillez d\'abord créer des carnets dans la section de gestion des carnets.';
                    echo '</div>';
                }

                echo '</div>';

                echo '<div class="modal-footer">';
                echo '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>';

                if (count($tranchesDisponibles) > 0) {
                    echo '<button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i>Attribuer</button>';
                } else {
                    echo '<a href="tranches.php" class="btn btn-primary"><i class="bi bi-journal-plus me-1"></i>Créer des carnets</a>';
                }

                echo '</div>';

                echo '</form>';
                echo '</div>';
                echo '</div>';
                echo '</div>';
                
                // Modal pour confirmer le retour d'un carnet
                echo '<div class="modal fade" id="retournerCarnetModal" tabindex="-1" aria-labelledby="retournerCarnetModalLabel" aria-hidden="true">';
                echo '<div class="modal-dialog">';
                echo '<div class="modal-content">';
                echo '<form method="post" action="">';
                echo '<input type="hidden" name="action" value="retourner_carnet">';
                echo '<input type="hidden" name="id_tranche" id="id_tranche_retour" value="">';
                
                echo '<div class="modal-header bg-warning">';
                echo '<h5 class="modal-title" id="retournerCarnetModalLabel">Confirmer le retour du carnet</h5>';
                echo '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
                echo '</div>';
                
                echo '<div class="modal-body">';
                echo '<p>Voulez-vous vraiment marquer ce carnet comme retourné? Cette action ne peut pas être annulée.</p>';
                echo '<p>Tous les billets non vendus de ce carnet seront automatiquement marqués comme retournés.</p>';
                echo '</div>';
                
                echo '<div class="modal-footer">';
                echo '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>';
                echo '<button type="submit" class="btn btn-warning"><i class="bi bi-check-circle me-1"></i>Confirmer le retour</button>';
                echo '</div>';
                
                echo '</form>';
                echo '</div>';
                echo '</div>';
                echo '</div>';
                
            } else {
                echo '<div class="alert alert-danger">Élève non trouvé.</div>';
                echo '<a href="eleves.php" class="btn btn-secondary">Retour à la liste des classes</a>';
            }
        }
        ?>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Validation des formulaires Bootstrap
        (function() {
            'use strict'
            
            // Fetch all the forms we want to apply custom Bootstrap validation styles to
            var forms = document.querySelectorAll('.needs-validation')
            
            // Loop over them and prevent submission
            Array.prototype.slice.call(forms)
                .forEach(function(form) {
                    form.addEventListener('submit', function(event) {
                        if (!form.checkValidity()) {
                            event.preventDefault()
                            event.stopPropagation()
                        }
                        
                        form.classList.add('was-validated')
                    }, false)
                })
        })()
        
        // Calculer automatiquement le montant total
        document.getElementById('billets_vendus').addEventListener('input', updateTotal);
        
        function updateTotal() {
            var billetsVendus = parseInt(document.getElementById('billets_vendus').value) || 0;
            // Récupérer le prix unitaire du billet depuis la configuration
            var prixUnitaire = <?= PRIX_BILLET_DEFAUT ?>; 
            var total = billetsVendus * prixUnitaire;
            document.getElementById('montant_total').value = total.toFixed(2);
        }
        
        // Fonction pour ouvrir le modal de vente
        function prepareVente(idTranche, nomTranche) {
            // Réinitialiser le formulaire
            document.querySelector('#venteModal form').reset();
            
            // Mettre à jour le titre du modal
            document.getElementById('venteModalLabel').textContent = 'Enregistrer une vente - ' + nomTranche;
            
            // Afficher le modal
            var venteModal = new bootstrap.Modal(document.getElementById('venteModal'));
            venteModal.show();
        }
        
        // Fonction pour confirmer le retour d'un carnet
        function retournerCarnet(idTranche) {
            document.getElementById('id_tranche_retour').value = idTranche;
            var retourModal = new bootstrap.Modal(document.getElementById('retournerCarnetModal'));
            retourModal.show();
        }
        
        // Réinitialiser les formulaires quand les modals sont fermés
        document.getElementById('venteModal').addEventListener('hidden.bs.modal', function () {
            document.querySelector('#venteModal form').reset();
            document.querySelector('#venteModal form').classList.remove('was-validated');
        });
        
        document.getElementById('carnetModal').addEventListener('hidden.bs.modal', function () {
            document.querySelector('#carnetModal form').reset();
            document.querySelector('#carnetModal form').classList.remove('was-validated');
        });
    </script>
</body>
</html>