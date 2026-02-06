<?php
// utils.php - Fonctions utilitaires diverses

/**
 * Formater un numéro de billet sur 4 chiffres
 */
function formatNumeroBillet($numero) {
    return sprintf('%04d', $numero);
}

/**
 * Obtenir une couleur en fonction d'un pourcentage
 */
function getColorFromPercentage($percentage) {
    if ($percentage < 50) {
        return 'danger';
    } elseif ($percentage < 80) {
        return 'warning';
    } else {
        return 'success';
    }
}

/**
 * Tronquer un texte à une longueur donnée
 */
function tronquerTexte($texte, $longueur = 50) {
    if (strlen($texte) <= $longueur) {
        return $texte;
    }
    
    return substr($texte, 0, $longueur) . '...';
}

/**
 * Calculer le pourcentage
 */
function calculerPourcentage($partie, $total) {
    if ($total == 0) {
        return 0;
    }
    
    return round(($partie / $total) * 100);
}
?>