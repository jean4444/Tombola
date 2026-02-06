<!DOCTYPE html>
<?php                        <a href="eleves.php" class="btn btn-light me-2">Élèves</a>
                        <a href="etiquettes.php" class="btn btn-light me-2">Étiquettes</a>
                        <a href="billets.php" class="btn btn-light me-2">Billets</a>

require_once 'statistiques.php'; 

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
?>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- header.php -->
    <header class="bg-primary text-white p-3">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 class="mb-0"><?= APP_NAME ?></h1>
                </div>
                <div class="col-md-6">
                    <nav class="text-end">
                        <a href="index.php" class="btn btn-light me-2">Accueil</a>
                        <a href="eleves.php" class="btn btn-light me-2">Élèves</a>
                        <a href="billets.php" class="btn btn-light me-2">Billets</a>
                        <a href="lots.php" class="btn btn-light me-2">Lots</a>
                        <a href="stats.php" class="btn btn-light me-2">Statistiques</a>
                        <a href="admin.php" class="btn btn-outline-light">Administration</a>
						<a href="logout.php" class="btn btn-outline-light">Déconnexion</a>
                    </nav>
                </div>
            </div>
        </div>
    </header>
