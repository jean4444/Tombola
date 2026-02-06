<?php
require_once 'config.php';
require_once 'auth.php';

// Vérifier si l'utilisateur est connecté
redirigerSiNonConnecte();

$db = connectDB();
$idAnnee = getAnneeActive($db);

if (!$idAnnee) {
    die("Aucune année scolaire active.");
}

// Fonction pour afficher les erreurs
function afficherErreur($message) {
    echo "<div class='alert alert-danger'>$message</div>";
}

// Fonction pour afficher les corrections
function afficherCorrection($message) {
    echo "<div class='alert alert-success'>$message</div>";
}

// Vérification globale de l'intégrité des données
function verifierIntegriteDonnees($db, $idAnnee) {
    $erreurs = [];
    $corrections = [];

    // 1. Vérifier la cohérence des tranches
    $stmt = $db->prepare("
        SELECT 
            e.id_eleve, 
            e.nom, 
            e.prenom, 
            COUNT(t.id_tranche) as nb_tranches,
            SUM(t.numero_fin - t.numero_debut + 1) as total_billets_carnets,
            COALESCE(v.billets_vendus, 0) as billets_vendus,
            COALESCE(v.billets_retournes, 0) as billets_retournes,
            COALESCE(v.montant_total, 0) as montant_total,
            (
                SELECT COUNT(*) 
                FROM billets b
                JOIN tranches tr ON b.id_tranche = tr.id_tranche
                WHERE tr.id_eleve = e.id_eleve AND tr.id_annee = ?
            ) as total_billets_enregistres
        FROM eleves e
        LEFT JOIN tranches t ON e.id_eleve = t.id_eleve AND t.id_annee = ?
        LEFT JOIN ventes v ON e.id_eleve = v.id_eleve AND v.id_annee = ?
        WHERE e.id_annee = ?
        GROUP BY e.id_eleve
    ");
    $stmt->execute([$idAnnee, $idAnnee, $idAnnee, $idAnnee]);
    $eleves = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($eleves as $eleve) {
        // Vérifier la cohérence des billets
        $incoherences = [];

        // Billets dans les carnets vs billets vendus/retournés
        if ($eleve['total_billets_carnets'] > 0) {
            if ($eleve['total_billets_enregistres'] != ($eleve['billets_vendus'] + $eleve['billets_retournes'])) {
                $incoherences[] = "Incohérence entre billets des carnets et billets enregistrés";
                $incoherences[] = "Billets carnets : " . $eleve['total_billets_carnets'];
                $incoherences[] = "Billets enregistrés : " . $eleve['total_billets_enregistres'];
                $incoherences[] = "Billets vendus : " . $eleve['billets_vendus'];
                $incoherences[] = "Billets retournés : " . $eleve['billets_retournes'];
            }
        }

        // Vérifier les prix
        if ($eleve['billets_vendus'] > 0) {
            $prixMoyen = $eleve['montant_total'] / $eleve['billets_vendus'];
            if (round($prixMoyen, 2) != PRIX_BILLET_DEFAUT) {
                $incoherences[] = "Prix moyen du billet incorrect (calculé : " . round($prixMoyen, 2) . " € vs attendu : " . PRIX_BILLET_DEFAUT . " €)";
            }
        }

        // Rapport des incohérences
        if (!empty($incoherences)) {
            $erreurs[] = [
                'eleve' => $eleve['prenom'] . ' ' . $eleve['nom'],
                'incoherences' => $incoherences,
                'donnees' => $eleve
            ];
        }
    }

    return [
        'erreurs' => $erreurs,
        'corrections' => $corrections
    ];
}

// Corriger les incohérences
function corrigerIncohérences($db, $idAnnee) {
    $corrections = [];

    // Réinitialisation des ventes pour recalculer
    $stmt = $db->prepare("
        UPDATE ventes v
        JOIN (
            SELECT 
                id_eleve, 
                COUNT(CASE WHEN b.statut = 'vendu' THEN 1 END) as billets_vendus,
                COUNT(CASE WHEN b.statut = 'retourne' THEN 1 END) as billets_retournes,
                SUM(CASE WHEN b.statut = 'vendu' THEN b.prix_unitaire ELSE 0 END) as montant_total
            FROM tranches t
            JOIN billets b ON t.id_tranche = b.id_tranche
            WHERE t.id_annee = ?
            GROUP BY id_eleve
        ) stats ON v.id_eleve = stats.id_eleve AND v.id_annee = ?
        SET 
            v.billets_vendus = stats.billets_vendus,
            v.billets_retournes = stats.billets_retournes,
            v.montant_total = stats.montant_total,
            v.date_mise_a_jour = NOW()
    ");
    $stmt->execute([$idAnnee, $idAnnee]);
    $corrections[] = "Mise à jour des statistiques de vente pour l'année " . $idAnnee;

    // Mise à jour des prix unitaires des billets si nécessaire
    $stmt = $db->prepare("
        UPDATE billets b
        JOIN tranches t ON b.id_tranche = t.id_tranche
        JOIN ventes v ON t.id_eleve = v.id_eleve AND t.id_annee = v.id_annee
        SET b.prix_unitaire = ?
        WHERE b.statut = 'vendu' AND t.id_annee = ? AND (b.prix_unitaire IS NULL OR b.prix_unitaire = 0)
    ");
    $stmt->execute([PRIX_BILLET_DEFAUT, $idAnnee]);
    $corrections[] = "Mise à jour des prix unitaires des billets vendus";

    // Suppression des entrées de ventes avec 0 billets
    $stmt = $db->prepare("
        DELETE FROM ventes 
        WHERE id_annee = ? AND billets_vendus = 0 AND billets_retournes = 0
    ");
    $stmt->execute([$idAnnee]);
    $corrections[] = "Suppression des entrées de ventes vides";

    return $corrections;
}

// Traitement des actions
$action = $_GET['action'] ?? null;

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Vérification de l'intégrité des données</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1>Vérification de l'intégrité des données</h1>
        
        <?php
        if ($action === 'verifier') {
            $resultats = verifierIntegriteDonnees($db, $idAnnee);
            
            if (!empty($resultats['erreurs'])) {
                echo "<h2>Erreurs détectées</h2>";
                foreach ($resultats['erreurs'] as $erreur) {
                    echo "<div class='card mb-3'>";
                    echo "<div class='card-header bg-warning'>Élève : " . htmlspecialchars($erreur['eleve']) . "</div>";
                    echo "<div class='card-body'>";
                    echo "<ul>";
                    foreach ($erreur['incoherences'] as $incoherence) {
                        echo "<li>" . htmlspecialchars($incoherence) . "</li>";
                    }
                    echo "</ul>";
                    echo "</div>";
                    echo "</div>";
                }
            } else {
                echo "<div class='alert alert-success'>Aucune incohérence détectée.</div>";
            }
        }

        if ($action === 'corriger') {
            $corrections = corrigerIncohérences($db, $idAnnee);
            
            echo "<h2>Corrections effectuées</h2>";
            foreach ($corrections as $correction) {
                echo "<div class='alert alert-success'>" . htmlspecialchars($correction) . "</div>";
            }
        }
        ?>

        <div class="row">
            <div class="col">
                <a href="?action=verifier" class="btn btn-primary">Vérifier l'intégrité</a>
            </div>
            <div class="col">
                <a href="?action=corriger" class="btn btn-danger">Corriger les incohérences</a>
            </div>
        </div>
    </div>
</body>
</html>