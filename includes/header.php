<?php
require_once __DIR__ . '/../config.php';
?>
<header class="bg-primary text-white py-3">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h1 class="mb-0 h3"><?= APP_NAME ?></h1>
            </div>
            <div class="col-md-6">
                <nav class="text-md-end mt-3 mt-md-0">
                    <a href="dashboard.php" class="btn btn-light me-2">Accueil</a>
                    <a href="eleves.php" class="btn btn-light me-2">Élèves</a>
                    <a href="etiquettes.php" class="btn btn-light me-2">Étiquettes</a>
                    <a href="billets.php" class="btn btn-light me-2">Billets</a>
                    <a href="lots.php" class="btn btn-light me-2">Lots</a>
                    <a href="stats.php" class="btn btn-light me-2">Statistiques</a>
                    <a href="admin.php#sauvegarde" class="btn btn-light me-2">Sauvegarde</a>
                    <a href="admin.php" class="btn btn-outline-light me-2">Administration</a>
                    <a href="logout.php" class="btn btn-outline-light">Déconnexion</a>
                </nav>
            </div>
        </div>
    </div>
</header>
