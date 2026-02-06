<?php
// config.php - Configuration de la connexion à la base de données
define('DB_HOST', '91.216.107.162');
define('DB_NAME', 'comit999702_4p7yqh');
define('DB_USER', 'comit999702_4p7yqh');
define('DB_PASS', 'aW6!ueFmPgsnVuT');
define('APP_NAME', 'Gestion Tombola');
define('BILLETS_MIN', 1);
define('BILLETS_MAX', 4500);
define('TAILLE_TRANCHE', 10);
define('PRIX_BILLET_DEFAUT', 2.00);

// Connexion à la base de données
function connectDB() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        die("Erreur de connexion : " . $e->getMessage());
    }
}

// Fonction utilitaire pour obtenir l'année active
function getAnneeActive($db) {
    $stmt = $db->query("SELECT id_annee FROM annees WHERE active = TRUE LIMIT 1");
    $annee = $stmt->fetch();
    return $annee ? $annee['id_annee'] : null;
}
?>