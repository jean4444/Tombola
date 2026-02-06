<?php
require_once 'config.php';
require_once 'auth.php';

// Vérifier si l'utilisateur est connecté
redirigerSiNonConnecte();

$db = connectDB();
$idAnnee = getAnneeActive($db);

// Récupérer l'ID de l'élève
$idEleve = isset($_GET['eleve']) ? intval($_GET['eleve']) : null;

if (!$idEleve) {
    header('Location: eleves.php');
    exit;
}

// Récupérer les informations de l'élève
$stmt = $db->prepare("
    SELECT 
        e.id_eleve, e.nom, e.prenom,
        c.id_classe, c.nom as classe_nom, c.niveau
    FROM eleves e
    JOIN classes c ON e.id_classe = c.id_classe
    WHERE e.id_eleve = ? AND e.id_annee = ?
");
$stmt->execute([$idEleve, $idAnnee]);
$eleve = $stmt->fetch();

if (!$eleve) {
    header('Location: eleves.php');
    exit;
}

// Traitement du formulaire d'enregistrement de vente
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $billetsVendus = isset($_POST['billets_vendus']) ? intval($_POST['billets_vendus']) : 0;
    $billetsRetournes = isset($_POST['billets_retournes']) ? intval($_POST['billets_retournes']) : 0;
    $montantTotal = isset($_POST['montant_total']) ? floatval(str_replace(',', '.', $_POST['montant_total'])) : 0;
    $dateVente = isset($_POST['date_vente']) ? $_POST['date_vente'] : date('Y-m-d');
    
    if ($billetsVendus < 0 || $billetsRetournes < 0 || $montantTotal < 0) {
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
        } else {
            // Créer une nouvelle vente
            $stmt = $db->prepare("
                INSERT INTO ventes (id_eleve, id_annee, billets_vendus, billets_retournes, montant_total, date_vente, date_creation)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$idEleve, $idAnnee, $billetsVendus, $billetsRetournes, $montantTotal, $dateVente]);
            $message = 'La vente a été enregistrée avec succès.';
            $messageType = 'success';
        }
        
        // Mise à jour du statut des billets si nécessaire
if ($billetsVendus > 0 || $billetsRetournes > 0) {
    // Récupérer les tranches de billets disponibles
    $stmt = $db->prepare("
        SELECT id_tranche, numero_debut, numero_fin
        FROM tranches
        WHERE id_eleve = ? AND id_annee = ? AND date_retour IS NULL
        ORDER BY numero_debut
    ");
    $stmt->execute([$idEleve, $idAnnee]);
    $tranches = $stmt->fetchAll();
    
    $billetsAVendre = $billetsVendus;
    $billetsARetourner = $billetsRetournes;
    
    foreach ($tranches as $tranche) {
        $debut = $tranche['numero_debut'];
        $fin = $tranche['numero_fin'];
        $nbBillets = $fin - $debut + 1;
        
        // Mise à jour des billets vendus
        if ($billetsAVendre > 0) {
            $aVendre = min($billetsAVendre, $nbBillets);
            
            for ($i = 0; $i < $aVendre; $i++) {
                $numeroBillet = $debut + $i;
                
                // Vérifier si le billet existe déjà
                $stmt = $db->prepare("SELECT id_billet FROM billets WHERE numero = ? AND id_tranche = ?");
                $stmt->execute([$numeroBillet, $tranche['id_tranche']]);
                $billet = $stmt->fetch();
                
                if ($billet) {
                    // Mettre à jour le statut du billet
                    $stmt = $db->prepare("UPDATE billets SET statut = 'vendu', date_modification = NOW() WHERE id_billet = ?");
                    $stmt->execute([$billet['id_billet']]);
                } else {
                    // Créer un nouveau billet
                    $stmt = $db->prepare("
                        INSERT INTO billets (id_tranche, numero, statut, date_creation)
                        VALUES (?, ?, 'vendu', NOW())
                    ");
                    $stmt->execute([$tranche['id_tranche'], $numeroBillet]);
                }
            }
            
            $billetsAVendre -= $aVendre;
        }
        
        // Mise à jour des billets retournés
        if ($billetsARetourner > 0) {
            $aRetourner = min($billetsARetourner, $nbBillets);
            
            for ($i = 0; $i < $aRetourner; $i++) {
                $numeroBillet = $fin - $i;
                
                // Vérifier si le billet existe déjà
                $stmt = $db->prepare("SELECT id_billet FROM billets WHERE numero = ? AND id_tranche = ?");
                $stmt->execute([$numeroBillet, $tranche['id_tranche']]);
                $billet = $stmt->fetch();
                
                if ($billet) {
                    // Mettre à jour le statut du billet
                    $stmt = $db->prepare("UPDATE billets SET statut = 'retourne', date_modification = NOW() WHERE id_billet = ?");
                    $stmt->execute([$billet['id_billet']]);
                } else {
                    // Créer un nouveau billet
                    $stmt = $db->prepare("
                        INSERT INTO billets (id_tranche, numero, statut, date_creation)
                        VALUES (?, ?, 'retourne', NOW())
                    ");
                    $stmt->execute([$tranche['id_tranche'], $numeroBillet]);
                }
            }
           
            $billetsARetourner -= $aRetourner;
        }
        
        if ($billetsAVendre <= 0 && $billetsARetourner <= 0) {
            break;
        }
    }
}
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enregistrer une vente - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .page-title {
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #007bff;
        }
        .card {
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid rgba(0,0,0,0.125);
            padding: 15px 20px;
        }
        .form-label {
            font-weight: 500;
        }
        .btn-icon {
            margin-right: 8px;
        }
        .eleve-info {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .eleve-info .niveau {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container my-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="page-title">Enregistrer une vente</h2>
            <a href="eleve_detail.php?id=<?= $idEleve ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left btn-icon"></i>Retour à l'élève
            </a>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <div class="eleve-info">
            <h4>
                <?= htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom']) ?>
                <span class="niveau badge bg-<?= strtolower($eleve['niveau']) ?>"><?= htmlspecialchars($eleve['niveau']) ?></span>
            </h4>
            <p>Classe: <?= htmlspecialchars($eleve['classe_nom']) ?></p>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h4 class="mb-0">Détails de la vente</h4>
            </div>
            <div class="card-body">
                <form action="" method="post" class="needs-validation" novalidate>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="billets_vendus" class="form-label">Billets vendus</label>
                            <input type="number" class="form-control" id="billets_vendus" name="billets_vendus" value="0" min="0" required>
                            <div class="invalid-feedback">
                                Veuillez entrer un nombre valide de billets vendus.
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="billets_retournes" class="form-label">Billets retournés</label>
                            <input type="number" class="form-control" id="billets_retournes" name="billets_retournes" value="0" min="0">
                            <div class="invalid-feedback">
                                Veuillez entrer un nombre valide de billets retournés.
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="montant_total" class="form-label">Montant total (€)</label>
                            <input type="number" step="0.01" class="form-control" id="montant_total" name="montant_total" value="0.00" min="0" required>
                            <div class="invalid-feedback">
                                Veuillez entrer un montant valide.
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="date_vente" class="form-label">Date de vente</label>
                            <input type="date" class="form-control" id="date_vente" name="date_vente" value="<?= date('Y-m-d') ?>" required>
                            <div class="invalid-feedback">
                                Veuillez sélectionner une date.
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle btn-icon"></i>Enregistrer la vente
                        </button>
                    </div>
                </form>
            </div>
        </div>
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
            // Par exemple, si chaque billet coûte 2€
            var prixUnitaire = 2; 
            var total = billetsVendus * prixUnitaire;
            document.getElementById('montant_total').value = total.toFixed(2);
        }
    </script>
</body>
</html>