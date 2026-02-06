<?php
// statistiques.php - Génération de statistiques et rapports

require_once 'config.php';
require_once 'lots.php'; 
// Obtenir les statistiques globales pour une année
function getStatistiquesAnnee($idAnnee) {
    $db = connectDB();
    
    try {
        // Statistiques des ventes
        $stmt = $db->prepare("
            SELECT 
                SUM(billets_vendus) as total_vendus,
                SUM(billets_retournes) as total_retournes,
                SUM(montant_total) as recette_totale
            FROM ventes 
            WHERE id_annee = ?
        ");
        $stmt->execute([$idAnnee]);
        $ventes = $stmt->fetch();
        
        // Statistiques des billets
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_billets,
                SUM(CASE WHEN statut = 'vendu' THEN 1 ELSE 0 END) as vendus,
                SUM(CASE WHEN statut = 'retourne' THEN 1 ELSE 0 END) as retournes,
                SUM(CASE WHEN statut = 'attribue' THEN 1 ELSE 0 END) as attribues,
                SUM(CASE WHEN statut = 'disponible' THEN 1 ELSE 0 END) as disponibles
            FROM billets 
            WHERE id_annee = ?
        ");
        $stmt->execute([$idAnnee]);
        $billets = $stmt->fetch();
        
        // Coût total des lots
        $coutLots = getTotalCoutsLots($idAnnee);
        
        // Bénéfice net
        $benefice = ($ventes['recette_totale'] ?? 0) - $coutLots;
        
        // Statistiques par classe
        $stmt = $db->prepare("
            SELECT 
                c.nom as classe,
                c.niveau,
                COUNT(e.id_eleve) as nb_eleves,
                SUM(v.billets_vendus) as billets_vendus,
                SUM(v.montant_total) as montant_total,
                AVG(v.billets_vendus) as moyenne_par_eleve
            FROM classes c
            LEFT JOIN eleves e ON c.id_classe = e.id_classe
            LEFT JOIN ventes v ON e.id_eleve = v.id_eleve AND v.id_annee = c.id_annee
            WHERE c.id_annee = ?
            GROUP BY c.id_classe
            ORDER BY montant_total DESC
        ");
        $stmt->execute([$idAnnee]);
        $classes = $stmt->fetchAll();
        
        return [
            "ventes" => $ventes,
            "billets" => $billets,
            "cout_lots" => $coutLots,
            "benefice" => $benefice,
            "classes" => $classes
        ];
    } catch (Exception $e) {
        return ["statut" => "erreur", "message" => "Erreur: " . $e->getMessage()];
    }
}

// Comparer les statistiques entre années
function comparerAnnees() {
    $db = connectDB();
    
    try {
        $stmt = $db->query("
            SELECT 
                a.id_annee,
                a.libelle,
                SUM(v.billets_vendus) as billets_vendus,
                SUM(v.montant_total) as recette,
                (SELECT SUM(l.valeur * l.quantite) FROM lots l WHERE l.id_annee = a.id_annee) as cout_lots
            FROM annees a
            LEFT JOIN ventes v ON a.id_annee = v.id_annee
            GROUP BY a.id_annee
            ORDER BY a.libelle DESC
        ");
        
        $annees = $stmt->fetchAll();
        
        // Calculer les bénéfices
        foreach ($annees as &$annee) {
            $annee['benefice'] = $annee['recette'] - $annee['cout_lots'];
        }
        
        return $annees;
    } catch (Exception $e) {
        return ["statut" => "erreur", "message" => "Erreur: " . $e->getMessage()];
    }
}

// Obtenir les meilleurs vendeurs
function getMeilleursVendeurs($idAnnee, $limite = 10) {
    $db = connectDB();
    
    try {
        $stmt = $db->prepare("
            SELECT 
                e.id_eleve,
                e.nom,
                e.prenom,
                c.nom as classe,
                v.billets_vendus,
                v.montant_total
            FROM ventes v
            JOIN eleves e ON v.id_eleve = e.id_eleve
            JOIN classes c ON e.id_classe = c.id_classe
            WHERE v.id_annee = ? AND v.billets_vendus > 0
            ORDER BY v.billets_vendus DESC, v.montant_total DESC
            LIMIT ?
        ");
        $stmt->execute([$idAnnee, $limite]);
        
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return ["statut" => "erreur", "message" => "Erreur: " . $e->getMessage()];
    }
}
?>