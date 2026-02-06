<?php
require_once 'config.php';
require_once 'auth.php';

// Vérifier si l'utilisateur est connecté
redirigerSiNonConnecte();

$db = connectDB();
$idAnnee = getAnneeActive($db);

// Récupérer l'ID de classe si spécifié
$idClasse = isset($_GET['classe']) ? intval($_GET['classe']) : null;

// Traitement du formulaire d'enregistrement de vente
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'enregistrer_vente') {
    $idEleveVente = isset($_POST['id_eleve']) ? intval($_POST['id_eleve']) : 0;
    $billetsVendus = isset($_POST['billets_vendus']) ? intval($_POST['billets_vendus']) : 0;
    $billetsRetournes = isset($_POST['billets_retournes']) ? intval($_POST['billets_retournes']) : 0;
    $montantTotal = isset($_POST['montant_total']) ? floatval(str_replace(',', '.', $_POST['montant_total'])) : 0;
    $dateVente = isset($_POST['date_vente']) ? $_POST['date_vente'] : date('Y-m-d');
    
    // INSÉRER ICI le code de vérification de disponibilité des billets
    // Avant d'enregistrer la vente, vérifier si l'élève a suffisamment de billets
    $stmt = $db->prepare("
        SELECT SUM(t.numero_fin - t.numero_debut + 1) as total_billets,
               COALESCE(SUM(v.billets_vendus), 0) as billets_deja_vendus,
               COALESCE(SUM(v.billets_retournes), 0) as billets_deja_retournes
        FROM tranches t
        LEFT JOIN ventes v ON v.id_eleve = t.id_eleve AND v.id_annee = t.id_annee
        WHERE t.id_eleve = ? AND t.id_annee = ?
    ");
    $stmt->execute([$idEleveVente, $idAnnee]);
    $disponibilite = $stmt->fetch();

    $billetsDisponibles = $disponibilite['total_billets'] - $disponibilite['billets_deja_vendus'] - $disponibilite['billets_deja_retournes'];

    if ($billetsVendus > $billetsDisponibles) {
        $message = 'Erreur : Cet élève n\'a que ' . $billetsDisponibles . ' billets disponibles. Impossible de vendre ' . $billetsVendus . ' billets.';
        $messageType = 'danger';
    } 
    else if ($idEleveVente <= 0) {
        $message = 'Élève non valide.';
        $messageType = 'danger';
    } 
    elseif ($billetsVendus < 0 || $billetsRetournes < 0 || $montantTotal < 0) {
        $message = 'Les valeurs ne peuvent pas être négatives.';
        $messageType = 'danger';
    } 
    else {
        // Le reste du code existant pour enregistrer la vente
        // Vérifier si une vente existe déjà pour cet élève cette année
        $stmt = $db->prepare("SELECT id_vente FROM ventes WHERE id_eleve = ? AND id_annee = ?");
        $stmt->execute([$idEleveVente, $idAnnee]);
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
            $stmt->execute([$billetsVendus, $billetsRetournes, $montantTotal, $idEleveVente, $idAnnee]);
            $message = 'La vente a été mise à jour avec succès.';
            $messageType = 'success';
        } else {
            // Créer une nouvelle vente
            $stmt = $db->prepare("
                INSERT INTO ventes (id_eleve, id_annee, billets_vendus, billets_retournes, montant_total, date_mise_a_jour)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$idEleveVente, $idAnnee, $billetsVendus, $billetsRetournes, $montantTotal]);
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
            $stmt->execute([$idEleveVente, $idAnnee]);
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
                      $stmt = $db->prepare("UPDATE billets SET statut = 'vendu' WHERE id_billet = ?");
						$stmt->execute([$billet['id_billet']]);
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
                            $stmt = $db->prepare("UPDATE billets SET statut = 'retourne', date_mise_a_jour = NOW() WHERE id_billet = ?");
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
    <title><?= $idClasse ? 'Élèves de la classe' : 'Classes' ?> - <?= APP_NAME ?></title>
        .table-eleves tr:hover {
            background-color: #f1f1f1;
        }

        .label-controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }

        .label-preview-wrapper {
            background: #f8f9fa;
            border: 1px dashed #cbd3da;
            border-radius: 8px;
            padding: 1rem;
        }

        .label-pages {
            display: grid;
            gap: 1.5rem;
        }

        .label-page {
            --label-cols: 2;
            --label-rows: 7;
            --label-width: 99mm;
            --label-height: 38mm;
            --label-gap: 2mm;
            width: 210mm;
            height: 297mm;
            margin: 0 auto;
        }

        .page-letter .label-page {
            width: 216mm;
            height: 279mm;
        }

        .label-grid {
            display: grid;
            grid-template-columns: repeat(var(--label-cols, 2), var(--label-width, 99mm));
            grid-template-rows: repeat(var(--label-rows, 7), var(--label-height, 38mm));
            gap: var(--label-gap, 2mm);
            justify-content: center;
        }

        .label-item {
            border: 1px solid #adb5bd;
            border-radius: 6px;
            padding: 6mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            font-size: 12px;
            background: #fff;
        }

        .label-item h6 {
            margin: 0 0 4px 0;
            font-size: 14px;
        }

        .label-meta {
            font-size: 11px;
            color: #495057;
        }

        .label-message {
            font-size: 12px;
            font-weight: 600;
            color: #212529;
        }

        @media print {
            body * {
                visibility: hidden;
            }

            .label-pages,
            .label-pages * {
                visibility: visible;
            }

            .label-pages {
                position: absolute;
                left: 0;
                top: 0;
            }

            .label-page {
                break-after: page;
            }
        }
    </style>
</head>

        /* Style pour les cartes de classe */
        .classe-card {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
            margin-bottom: 20px;
        }
        
        .classe-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        
        .card-header-custom {
            padding: 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }
        
        .card-body-custom {
            padding: 20px;
            flex-grow: 1;
        }
        
        .card-footer-custom {
            padding: 15px;
            background-color: rgba(0,0,0,0.03);
            border-top: 1px solid rgba(0,0,0,0.1);
        }
        
        .classe-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
        }
        
        .niveau-badge {
            padding: 5px 10px;
            border-radius: 20px;
                    $stmt = $db->prepare("
                        SELECT 
                            e.id_eleve, e.nom, e.prenom,
                            COALESCE(v.billets_vendus, 0) as billets_vendus,
                            COALESCE(v.billets_retournes, 0) as billets_retournes,
                            COALESCE(v.montant_total, 0) as montant_total,
                            (SELECT COUNT(*) FROM tranches t WHERE t.id_eleve = e.id_eleve AND t.id_annee = ?) as nb_tranches,
                            (SELECT MIN(t.numero_debut) FROM tranches t WHERE t.id_eleve = e.id_eleve AND t.id_annee = ?) as tickets_debut,
                            (SELECT MAX(t.numero_fin) FROM tranches t WHERE t.id_eleve = e.id_eleve AND t.id_annee = ?) as tickets_fin
                        FROM eleves e
                        LEFT JOIN ventes v ON e.id_eleve = v.id_eleve AND v.id_annee = ?
                        WHERE e.id_classe = ? AND e.id_annee = ?
                        ORDER BY e.nom, e.prenom
                    ");
                    $stmt->execute([$idAnnee, $idAnnee, $idAnnee, $idAnnee, $idClasse, $idAnnee]);
                    $eleves = $stmt->fetchAll();
                    $labelsData = [];

                    foreach ($eleves as $eleve) {
                        $ticketsDebut = $eleve['tickets_debut'] ? sprintf('%04d', $eleve['tickets_debut']) : '-';
                        $ticketsFin = $eleve['tickets_fin'] ? sprintf('%04d', $eleve['tickets_fin']) : '-';
                        $labelsData[] = [
                            'nom' => $eleve['nom'],
                            'prenom' => $eleve['prenom'],
                            'classe' => $classe['nom'],
                            'tickets_debut' => $ticketsDebut,
                            'tickets_fin' => $ticketsFin,
                        ];
                    }
                    
                    if (count($eleves) > 0) {
                        // Afficher les élèves en cartes
if (count($eleves) > 0) {
    echo '<div class="card mb-4">';
    echo '<div class="card-header bg-secondary text-white">';
    echo '<h5 class="mb-0">Impression des étiquettes (classe)</h5>';
    echo '</div>';
    echo '<div class="card-body">';
    echo '<div class="label-controls mb-3">';
    echo '<div>';
    echo '<label for="labelsPageFormat" class="form-label">Format de la page</label>';
    echo '<select id="labelsPageFormat" class="form-select">';
    echo '<option value="a4" selected>A4 (210 × 297 mm)</option>';
    echo '<option value="letter">Letter (216 × 279 mm)</option>';
    echo '</select>';
    echo '</div>';
    echo '<div>';
    echo '<label for="labelsFormat" class="form-label">Format d\'étiquette</label>';
    echo '<select id="labelsFormat" class="form-select">';
    echo '<option value="24" selected>24 étiquettes (99 × 38 mm)</option>';
    echo '<option value="14">14 étiquettes (99 × 55 mm)</option>';
    echo '<option value="8">8 étiquettes (99 × 67 mm)</option>';
    echo '<option value="4">4 étiquettes (105 × 148 mm)</option>';
    echo '<option value="2">2 étiquettes (210 × 148 mm)</option>';
    echo '<option value="1">1 étiquette pleine page</option>';
    echo '</select>';
    echo '</div>';
    echo '<div>';
    echo '<label for="labelsMessage" class="form-label">Message personnalisé</label>';
    echo '<input type="text" id="labelsMessage" class="form-control" placeholder="Ex: Merci pour votre participation">';
    echo '</div>';
    echo '</div>';
    echo '<div class="d-flex justify-content-end mb-3">';
    echo '<button type="button" class="btn btn-outline-primary" id="labelsPrintButton">';
    echo '<i class="bi bi-printer me-1"></i>Imprimer les étiquettes';
    echo '</button>';
    echo '</div>';
    echo '<div class="label-preview-wrapper">';
    echo '<div class="label-pages" id="labelPages"></div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    // Afficher les élèves en tableau
    echo '<div class="card">';
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .classe-stat i {
            width: 20px;
            margin-right: 10px;
            color: #555;
        }
        
        .vente-progress {
            height: 8px;
            border-radius: 4px;
            margin-top: 3px;
            background-color: #eaecef;
        }
        
        .btn-voir {
            width: 100%;
            border-radius: 6px;
            padding: 8px;
            font-weight: 500;
        }
        
        /* Couleurs par niveau - badges */
        .badge-ps {
            background-color: #FF6B95;
            color: white;
        }
        
        .badge-ms {
            background-color: #FFB347;
            color: white;
        }
        
        .badge-gs {
            background-color: #FFDE59;
            color: #664D03;
        }
        
        .badge-cp {
            background-color: #4ADE80;
            color: #14532D;
        }
        
        .badge-ce1 {
            background-color: #38BDF8;
            color: #0C4A6E;
        }
        
        .badge-ce2 {
            background-color: #60A5FA;
            color: #1E3A8A;
        }
        
        .badge-cm1 {
            background-color: #A78BFA;
            color: #4C1D95;
        }
        
        .badge-cm2 {
            background-color: #F472B6;
            color: #831843;
        }
        
        /* Couleurs par niveau - fonds */
        .bg-ps {
            background-color: #FFF1F2;
        }
        
        .bg-ms {
            background-color: #FFFBEB;
        }
        
        .bg-gs {
            background-color: #FEFCE8;
        }
        
        .bg-cp {
            background-color: #ECFDF5;
        }
        
        .bg-ce1 {
            background-color: #ECFEFF;
        }
        
        .bg-ce2 {
            background-color: #EFF6FF;
        }
        
        .bg-cm1 {
            background-color: #F5F3FF;
        }
        
        .bg-cm2 {
            background-color: #FDF2F8;
        }
        
        .page-title {
            margin-bottom: 30px;
            padding-bottom: 10px;
            border-bottom: 2px solid #007bff;
            color: #2D3748;
        }
        
        /* Styles pour la page des élèves */
        .eleve-card {
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: all 0.2s ease;
            margin-bottom: 15px;
        }
        
        .eleve-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .badge-tickets {
            font-size: 0.85rem;
            padding: 5px 10px;
            border-radius: 20px;
        }
        
        .classe-resume {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
       function updateTotal() {
           var billetsVendus = parseInt(document.getElementById('billets_vendus').value) || 0;
           // Par exemple, si chaque billet coûte 2€ 
           var prixUnitaire = 2; 
           var total = billetsVendus * prixUnitaire;
           document.getElementById('montant_total').value = total.toFixed(2);
       }

       document.addEventListener('DOMContentLoaded', function() {
           var labelsData = <?php echo isset($labelsData) ? json_encode($labelsData, JSON_UNESCAPED_UNICODE) : '[]'; ?>;
           var labelFormats = {
               24: { cols: 2, rows: 7, width: '99mm', height: '38mm', gap: '2mm' },
               14: { cols: 2, rows: 7, width: '99mm', height: '55mm', gap: '2mm' },
               8: { cols: 2, rows: 4, width: '99mm', height: '67mm', gap: '3mm' },
               4: { cols: 2, rows: 2, width: '105mm', height: '148mm', gap: '3mm' },
               2: { cols: 1, rows: 2, width: '210mm', height: '148mm', gap: '3mm' },
               1: { cols: 1, rows: 1, width: '210mm', height: '297mm', gap: '0mm' }
           };

           var formatSelect = document.getElementById('labelsFormat');
           var pageSelect = document.getElementById('labelsPageFormat');
           var messageInput = document.getElementById('labelsMessage');
           var labelPages = document.getElementById('labelPages');
           var printButton = document.getElementById('labelsPrintButton');

           if (!formatSelect || !pageSelect || !labelPages || !printButton) {
               return;
           }

           function updatePageClass() {
               document.body.classList.toggle('page-letter', pageSelect.value === 'letter');
           }

           function createLabelItem(label, messageText) {
               var item = document.createElement('div');
               item.className = 'label-item';
               item.innerHTML = '' +
                   '<div>' +
                   '<h6>' + label.prenom + ' ' + label.nom + '</h6>' +
                   '<div class="label-meta">Classe : ' + label.classe + '</div>' +
                   '<div class="label-meta">Tickets : ' + label.tickets_debut + ' à ' + label.tickets_fin + '</div>' +
                   '</div>' +
                   '<div class="label-message">' + (messageText || '') + '</div>';
               return item;
           }

           function renderLabels() {
               var selectedFormat = labelFormats[formatSelect.value] || labelFormats[24];
               var totalPerPage = selectedFormat.cols * selectedFormat.rows;
               var messageText = messageInput.value.trim();

               labelPages.innerHTML = '';

               if (!labelsData.length) {
                   labelPages.innerHTML = '<div class="text-muted">Aucune étiquette à afficher.</div>';
                   return;
               }

               for (var index = 0; index < labelsData.length; index += totalPerPage) {
                   var page = document.createElement('div');
                   page.className = 'label-page';
                   page.style.setProperty('--label-cols', selectedFormat.cols);
                   page.style.setProperty('--label-rows', selectedFormat.rows);
                   page.style.setProperty('--label-width', selectedFormat.width);
                   page.style.setProperty('--label-height', selectedFormat.height);
                   page.style.setProperty('--label-gap', selectedFormat.gap);

                   var grid = document.createElement('div');
                   grid.className = 'label-grid';
                   page.appendChild(grid);

                   var end = Math.min(index + totalPerPage, labelsData.length);
                   for (var i = index; i < end; i += 1) {
                       grid.appendChild(createLabelItem(labelsData[i], messageText));
                   }

                   labelPages.appendChild(page);
               }
           }

           formatSelect.addEventListener('change', renderLabels);
           pageSelect.addEventListener('change', function() {
               updatePageClass();
               renderLabels();
           });
           messageInput.addEventListener('input', renderLabels);
           printButton.addEventListener('click', function() {
               updatePageClass();
               renderLabels();
               window.print();
           });

           updatePageClass();
           renderLabels();
       });
       
       // Réinitialiser le formulaire quand le modal est fermé
       document.getElementById('venteModal').addEventListener('hidden.bs.modal', function () {
           document.querySelector('form.needs-validation').reset();
           document.querySelector('form.needs-validation').classList.remove('was-validated');
</html>

        
        .table-eleves tr:hover {
            background-color: #f1f1f1;
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
            // Récupérer l'année active
            $stmt = $db->prepare("SELECT libelle FROM annees WHERE id_annee = ?");
            $stmt->execute([$idAnnee]);
            $anneeActive = $stmt->fetch();
            
            // NOUVEAU: Afficher les élèves d'une classe spécifique si l'ID de classe est fourni
            if ($idClasse) {
                // Récupérer les informations de la classe
                $stmt = $db->prepare("
                    SELECT 
                        c.id_classe, c.nom, c.niveau,
                        COUNT(DISTINCT e.id_eleve) as nb_eleves,
                        COALESCE(SUM(v.billets_vendus), 0) as billets_vendus,
                        COALESCE(SUM(v.montant_total), 0) as montant_total
                    FROM classes c
                    LEFT JOIN eleves e ON c.id_classe = e.id_classe
                    LEFT JOIN ventes v ON e.id_eleve = v.id_eleve AND v.id_annee = ?
                    WHERE c.id_classe = ? AND c.id_annee = ?
                    GROUP BY c.id_classe
                ");
                $stmt->execute([$idAnnee, $idClasse, $idAnnee]);
                $classe = $stmt->fetch();
                
                if (!$classe) {
                    echo '<div class="alert alert-danger">Classe non trouvée.</div>';
                    echo '<a href="eleves.php" class="btn btn-secondary">Retour à la liste des classes</a>';
                } else {
                    $niveauClass = strtolower($classe['niveau']);
                    if (empty($niveauClass)) $niveauClass = "cp"; // Niveau par défaut si vide
                    
                    // Afficher le message de confirmation si présent
                    if ($message) {
                        echo '<div class="alert alert-' . $messageType . ' alert-dismissible fade show" role="alert">';
                        echo $message;
                        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
                        echo '</div>';
                    }
                    
                    // Afficher le titre et les infos de la classe
                    echo '<div class="d-flex justify-content-between align-items-center mb-4">';
                    echo '<h2 class="page-title mb-0">Élèves de la classe ' . htmlspecialchars($classe['nom']) . '</h2>';
                    echo '<a href="eleves.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour aux classes</a>';
                    echo '</div>';
                    
                    echo '<div class="classe-resume row bg-' . $niveauClass . '" style="border-radius: 12px; padding: 15px; margin-bottom: 20px;">';
                    echo '<div class="col-md-3">';
                    echo '<div class="d-flex align-items-center">';
                    echo '<i class="bi bi-people fs-1 me-2 text-primary"></i>';
                    echo '<div>';
                    echo '<div class="fs-5 fw-bold">' . $classe['nb_eleves'] . '</div>';
                    echo '<div class="text-muted small">Élèves</div>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                    
                    echo '<div class="col-md-3">';
                    echo '<div class="d-flex align-items-center">';
                    echo '<i class="bi bi-ticket-perforated fs-1 me-2 text-success"></i>';
                    echo '<div>';
                    echo '<div class="fs-5 fw-bold">' . $classe['billets_vendus'] . '</div>';
                    echo '<div class="text-muted small">Billets vendus</div>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                    
                    echo '<div class="col-md-3">';
                    echo '<div class="d-flex align-items-center">';
                    echo '<i class="bi bi-cash-coin fs-1 me-2 text-danger"></i>';
                    echo '<div>';
                    echo '<div class="fs-5 fw-bold">' . number_format($classe['montant_total'], 2, ',', ' ') . ' €</div>';
                    echo '<div class="text-muted small">Montant total</div>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                    
                    echo '<div class="col-md-3">';
                    echo '<div class="d-flex align-items-center">';
                    echo '<i class="bi bi-award fs-1 me-2 text-warning"></i>';
                    echo '<div>';
                    echo '<div class="fs-5 fw-bold">' . htmlspecialchars($classe['niveau']) . '</div>';
                    echo '<div class="text-muted small">Niveau</div>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                    
                    // Récupérer les élèves de la classe
                    $stmt = $db->prepare("
                        SELECT 
                            e.id_eleve, e.nom, e.prenom,
                            COALESCE(v.billets_vendus, 0) as billets_vendus,
                            COALESCE(v.billets_retournes, 0) as billets_retournes,
                            COALESCE(v.montant_total, 0) as montant_total,
                            (SELECT COUNT(*) FROM tranches t WHERE t.id_eleve = e.id_eleve AND t.id_annee = ?) as nb_tranches
                        FROM eleves e
                        LEFT JOIN ventes v ON e.id_eleve = v.id_eleve AND v.id_annee = ?
                        WHERE e.id_classe = ? AND e.id_annee = ?
                        ORDER BY e.nom, e.prenom
                    ");
                    $stmt->execute([$idAnnee, $idAnnee, $idClasse, $idAnnee]);
                    $eleves = $stmt->fetchAll();
                    
                    if (count($eleves) > 0) {
                        // Afficher les élèves en cartes
if (count($eleves) > 0) {
    // Afficher les élèves en tableau
    echo '<div class="card">';
    echo '<div class="card-header bg-' . $niveauClass . '">';
    echo '<h5 class="mb-0">Liste des élèves</h5>';
    echo '</div>';
    echo '<div class="card-body">';
    echo '<div class="table-responsive">';
    echo '<table class="table table-striped table-hover table-eleves">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Nom</th>';
    echo '<th>Prénom</th>';
    echo '<th>Tranches</th>';
    echo '<th>Billets vendus</th>';
    echo '<th>Billets retournés</th>';
    echo '<th>Montant</th>';
    echo '<th>Statut</th>';
    echo '<th>Actions</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    foreach ($eleves as $eleve) {
        // Déterminer le style en fonction des ventes
        $badgeClass = 'badge-secondary';
        $badgeText = 'Inactif';
        
        if ($eleve['billets_vendus'] > 0) {
            $badgeClass = 'bg-success';
            $badgeText = 'Ventes en cours';
        } elseif ($eleve['nb_tranches'] > 0) {
            $badgeClass = 'bg-warning text-dark';
            $badgeText = 'Tranches attribuées';
        }
        
        echo '<tr>';
        echo '<td>' . htmlspecialchars($eleve['nom']) . '</td>';
        echo '<td>' . htmlspecialchars($eleve['prenom']) . '</td>';
        echo '<td>' . $eleve['nb_tranches'] . '</td>';
        echo '<td>' . $eleve['billets_vendus'] . '</td>';
        echo '<td>' . $eleve['billets_retournes'] . '</td>';
        echo '<td>' . number_format($eleve['montant_total'], 2, ',', ' ') . ' €</td>';
        echo '<td><span class="badge ' . $badgeClass . '">' . $badgeText . '</span></td>';
        echo '<td>';
        echo '<a href="eleve_detail.php?id=' . $eleve['id_eleve'] . '" class="btn btn-primary btn-sm me-1" title="Voir détails"><i class="bi bi-info-circle"></i></a>';
        echo '<button type="button" class="btn btn-success btn-sm" onclick="prepareVente(' . $eleve['id_eleve'] . ', \'' . htmlspecialchars($eleve['prenom'] . ' ' . $eleve['nom']) . '\')" title="Enregistrer une vente"><i class="bi bi-cash"></i></button>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
} else {
    echo '<div class="alert alert-info mt-4"><i class="bi bi-info-circle"></i> Aucun élève n\'est enregistré dans cette classe.</div>';
}
                    } else {
                        echo '<div class="alert alert-info mt-4"><i class="bi bi-info-circle"></i> Aucun élève n\'est enregistré dans cette classe.</div>';
                    }
                    
                    // Boutons d'action en bas de page
                    echo '<div class="mt-4">';
                    echo '<a href="eleve_form.php?classe=' . $idClasse . '" class="btn btn-success me-2"><i class="bi bi-plus-circle"></i> Ajouter un élève</a>';
                    echo '<a href="attribution_form.php?classe=' . $idClasse . '" class="btn btn-primary me-2"><i class="bi bi-ticket-perforated"></i> Attribuer des billets</a>';
                    echo '</div>';
                }
            } else {
                // Code existant pour afficher les classes
                echo '<h2 class="page-title">Classes pour l\'année ' . htmlspecialchars($anneeActive['libelle']) . '</h2>';
                
                // Récupérer toutes les classes avec le nombre d'élèves et les ventes
                $stmt = $db->prepare("
                    SELECT 
                        c.id_classe, c.nom, c.niveau,
                        COUNT(DISTINCT e.id_eleve) as nb_eleves,
                        COALESCE(SUM(v.billets_vendus), 0) as billets_vendus,
                        COALESCE(SUM(v.montant_total), 0) as montant_total
                    FROM classes c
                    LEFT JOIN eleves e ON c.id_classe = e.id_classe
                    LEFT JOIN ventes v ON e.id_eleve = v.id_eleve AND v.id_annee = ?
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
                        END, c.nom
                ");
                $stmt->execute([$idAnnee, $idAnnee]);
                $classes = $stmt->fetchAll();
                
                if (count($classes) > 0) {
                    echo '<div class="row">';
                    
                    foreach ($classes as $classe) {
                        $niveauClass = strtolower($classe['niveau']);
                        if (empty($niveauClass)) $niveauClass = "cp"; // Niveau par défaut si vide
                        
                        // Déterminer l'icône en fonction du niveau
                        $icon = '';
                        switch ($niveauClass) {
                            case 'ps':
                                $icon = '<i class="bi bi-emoji-smile classe-icon"></i>';
                                break;
                            case 'ms':
                                $icon = '<i class="bi bi-emoji-laughing classe-icon"></i>';
                                break;
                            case 'gs':
                                $icon = '<i class="bi bi-emoji-sunglasses classe-icon"></i>';
                                break;
                            case 'cp':
                                $icon = '<i class="bi bi-book classe-icon"></i>';
                                break;
                            case 'ce1':
                            case 'ce2':
                                $icon = '<i class="bi bi-pencil classe-icon"></i>';
                                break;
                            case 'cm1':
                            case 'cm2':
                                $icon = '<i class="bi bi-calculator classe-icon"></i>';
                                break;
                            default:
                                $icon = '<i class="bi bi-mortarboard classe-icon"></i>';
                        }
                        
                        // Calculer le pourcentage des billets vendus
                        // Supposons que chaque élève a en moyenne 10 billets
                        $totalBillets = $classe['nb_eleves'] * 10;
                        $pctVendus = $totalBillets > 0 ? min(100, ($classe['billets_vendus'] * 100) / $totalBillets) : 0;
                        
                        echo '<div class="col-md-6 col-lg-3">';
                        echo '<div class="classe-card bg-' . $niveauClass . '">';
                        
                        echo '<div class="card-header-custom">';
                        echo $icon . '<h3 class="classe-title">' . htmlspecialchars($classe['nom']) . '</h3>';
                        echo '<span class="badge badge-' . $niveauClass . '">' . htmlspecialchars($classe['niveau']) . '</span>';
                        echo '</div>';
                        
                        echo '<div class="card-body-custom">';
                        echo '<div class="classe-stat"><i class="bi bi-people"></i> <strong>' . $classe['nb_eleves'] . '</strong> élèves</div>';
                        echo '<div class="classe-stat"><i class="bi bi-ticket-perforated"></i> <strong>' . $classe['billets_vendus'] . '</strong> billets vendus</div>';
                        echo '<div class="classe-stat"><i class="bi bi-cash-coin"></i> <strong>' . number_format($classe['montant_total'], 2, ',', ' ') . ' €</strong></div>';
                        
                        echo '<div class="mt-3">';
                        echo '<small>Progression des ventes</small>';
                        echo '<div class="progress vente-progress">';
                        echo '<div class="progress-bar bg-success" role="progressbar" style="width: ' . $pctVendus . '%" aria-valuenow="' . $pctVendus . '" aria-valuemin="0" aria-valuemax="100"></div>';
                        echo '</div>';
                        echo '</div>';
                        
                        echo '</div>';
                        
                        echo '<div class="card-footer-custom">';
                        echo '<a href="eleves.php?classe=' . $classe['id_classe'] . '" class="btn btn-primary btn-voir">Voir les élèves</a>';
                        echo '</div>';
                        
                        echo '</div>';
                        echo '</div>';
                    }
                    
                    echo '</div>';
                } else {
                    echo '<div class="alert alert-info">Aucune classe trouvée pour cette année scolaire.</div>';
                }
            }
        }
        ?>
    </div>
    
 <!-- Modal pour enregistrer une vente -->
   <div class="modal fade" id="venteModal" tabindex="-1" aria-labelledby="venteModalLabel" aria-hidden="true">
       <div class="modal-dialog">
           <div class="modal-content">
               <form method="post" action="" class="needs-validation" novalidate>
                   <input type="hidden" name="action" value="enregistrer_vente">
                   <input type="hidden" name="id_eleve" id="vente_id_eleve" value="">
                   
                   <div class="modal-header bg-primary text-white">
                       <h5 class="modal-title" id="venteModalLabel">Enregistrer une vente</h5>
                       <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                   </div>
                   
                   <div class="modal-body">
                       <div class="mb-3">
                           <label class="form-label">Élève</label>
                           <input type="text" class="form-control" id="vente_eleve_nom" readonly>
                       </div>
                       
                       <div class="mb-3">
                           <label for="billets_vendus" class="form-label">Billets vendus</label>
                           <input type="number" class="form-control" id="billets_vendus" name="billets_vendus" min="0" value="0" required>
                       </div>
                       
                       <div class="mb-3">
                           <label for="billets_retournes" class="form-label">Billets retournés</label>
                           <input type="number" class="form-control" id="billets_retournes" name="billets_retournes" min="0" value="0">
                       </div>
                       
                       <div class="mb-3">
                           <label for="montant_total" class="form-label">Montant total (€)</label>
                           <input type="number" step="0.01" class="form-control" id="montant_total" name="montant_total" min="0" value="0.00" required>
                       </div>
                       
                       <div class="mb-3">
                           <label for="date_vente" class="form-label">Date de vente</label>
                           <input type="date" class="form-control" id="date_vente" name="date_vente" value="<?= date('Y-m-d') ?>" required>
                       </div>
                   </div>
                   
                   <div class="modal-footer">
                       <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                       <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i>Enregistrer</button>
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
       
       // Préparer le modal de vente avec les informations de l'élève
       function prepareVente(idEleve, nomEleve) {
           document.getElementById('vente_id_eleve').value = idEleve;
           document.getElementById('vente_eleve_nom').value = nomEleve;
           document.getElementById('billets_vendus').value = 0;
           document.getElementById('billets_retournes').value = 0;
           document.getElementById('montant_total').value = '0.00';
           document.getElementById('date_vente').value = '<?= date('Y-m-d') ?>';
           
           var venteModal = new bootstrap.Modal(document.getElementById('venteModal'));
           venteModal.show();
       }
       
       // Calculer automatiquement le montant total
       document.getElementById('billets_vendus').addEventListener('input', updateTotal);
       
       function updateTotal() {
           var billetsVendus = parseInt(document.getElementById('billets_vendus').value) || 0;
           // Par exemple, si chaque billet coûte 2€
           var prixUnitaire = 2; 
           var total = billetsVendus * prixUnitaire;
           document.getElementById('montant_total').value = total.toFixed(2);
       }
       
       // Réinitialiser le formulaire quand le modal est fermé
       document.getElementById('venteModal').addEventListener('hidden.bs.modal', function () {
           document.querySelector('form.needs-validation').reset();
           document.querySelector('form.needs-validation').classList.remove('was-validated');
       });
   </script>
</body>
</html>