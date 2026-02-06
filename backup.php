// Fonction pour exporter la base de données
function exporterBaseDeDonnees($throwOnError = false) {
    $db = connectDB();
    
    try {
        return $output;
    } catch (Exception $e) {
        if ($throwOnError) {
            throw $e;
        }
        return "Erreur lors de l'exportation: " . $e->getMessage();
    }
}
?>

function exporterBaseDeDonnees() {
    $db = connectDB();
    
    try {
        // Récupérer toutes les tables
        $tables = [];
        $stmt = $db->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        $output = "-- Sauvegarde de la base de données " . DB_NAME . "\n";
        $output .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";
        $output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        
        // Pour chaque table
        foreach ($tables as $table) {
            $output .= "-- Structure de la table `$table`\n";
            $stmt = $db->query("SHOW CREATE TABLE `$table`");
            $row = $stmt->fetch(PDO::FETCH_NUM);
            $output .= $row[1] . ";\n\n";
            
            $output .= "-- Données de la table `$table`\n";
            $stmt = $db->query("SELECT * FROM `$table`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($rows) > 0) {
                $columns = array_keys($rows[0]);
                $output .= "INSERT INTO `$table` (`" . implode("`, `", $columns) . "`) VALUES\n";
                
                $values = [];
                foreach ($rows as $row) {
                    $rowValues = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $rowValues[] = "NULL";
                        } else {
                            $rowValues[] = "'" . addslashes($value) . "'";
                        }
                    }
                    $values[] = "(" . implode(", ", $rowValues) . ")";
                }
                $output .= implode(",\n", $values) . ";\n\n";
            }
        }
        
        $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        return $output;
    } catch (Exception $e) {
        return "Erreur lors de l'exportation: " . $e->getMessage();
    }
}

// Si le script est appelé directement
if (basename($_SERVER['SCRIPT_NAME']) === basename(__FILE__)) {
    $sql = exporterBaseDeDonnees();
    
    // Définir l'en-tête pour le téléchargement
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="backup_tombola_' . date('Y-m-d_H-i-s') . '.sql"');
    header('Content-Length: ' . strlen($sql));
    
    // Envoyer le contenu
    echo $sql;
    exit;
}
?>