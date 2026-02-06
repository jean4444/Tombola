<?php
require_once 'config.php';
require_once 'auth.php';

redirigerSiNonConnecte();

$db = connectDB();
$idAnnee = getAnneeActive($db);

$labelsData = [];

if ($idAnnee) {
    $stmt = $db->prepare("SELECT 
            e.id_eleve,
            e.nom,
            e.prenom,
            c.nom AS classe,
            MIN(t.numero_debut) AS tickets_debut,
            MAX(t.numero_fin) AS tickets_fin
        FROM eleves e
        JOIN classes c ON e.id_classe = c.id_classe
        LEFT JOIN tranches t ON t.id_eleve = e.id_eleve AND t.id_annee = e.id_annee
        WHERE e.id_annee = ?
        GROUP BY e.id_eleve, e.nom, e.prenom, c.nom
        ORDER BY c.nom, e.nom, e.prenom
    ");
    $stmt->execute([$idAnnee]);
    $eleves = $stmt->fetchAll();

    foreach ($eleves as $eleve) {
        $ticketsDebut = $eleve['tickets_debut'] ? sprintf('%04d', $eleve['tickets_debut']) : '-';
        $ticketsFin = $eleve['tickets_fin'] ? sprintf('%04d', $eleve['tickets_fin']) : '-';
        $labelsData[] = [
            'nom' => $eleve['nom'],
            'prenom' => $eleve['prenom'],
            'classe' => $eleve['classe'],
            'tickets_debut' => $ticketsDebut,
            'tickets_fin' => $ticketsFin,
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Étiquettes - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
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
            box-sizing: border-box;
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
            justify-content: start;
            align-content: start;
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

            .label-item {
                border: none;
                border-radius: 0;
                padding: 4mm;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <main class="container my-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Impression des étiquettes (toutes classes)</h2>
        </div>

        <?php if (!$idAnnee): ?>
            <div class="alert alert-warning">
                Aucune année scolaire active. Veuillez en créer une dans la section Administration.
                <a href="admin.php" class="btn btn-primary btn-sm ms-2">Aller à l'administration</a>
            </div>
        <?php else: ?>
            <div class="card mb-4">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0">Paramètres d'impression</h5>
                </div>
                <div class="card-body">
                    <div class="label-controls mb-3">
                        <div>
                            <label for="labelsPageFormat" class="form-label">Format de la page</label>
                            <select id="labelsPageFormat" class="form-select">
                                <option value="a4" selected>A4 (210 × 297 mm)</option>
                                <option value="letter">Letter (216 × 279 mm)</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Étiquettes personnalisées</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small mb-1" for="customLabelWidth">Largeur (mm)</label>
                                    <input type="number" min="10" step="1" class="form-control" id="customLabelWidth" value="99">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1" for="customLabelHeight">Hauteur (mm)</label>
                                    <input type="number" min="10" step="1" class="form-control" id="customLabelHeight" value="38">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1" for="customLabelCols">Colonnes par page</label>
                                    <input type="number" min="1" step="1" class="form-control" id="customLabelCols" value="2">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small mb-1" for="customLabelRows">Lignes par page</label>
                                    <input type="number" min="1" step="1" class="form-control" id="customLabelRows" value="7">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small mb-1" for="customLabelGap">Espacement entre étiquettes (mm)</label>
                                    <input type="number" min="0" step="1" class="form-control" id="customLabelGap" value="2">
                                </div>
                            </div>
                            <div class="form-text">Ces dimensions définissent la taille et la grille d'étiquettes par page.</div>
                        </div>
                        <div>
                            <label for="labelsMessage" class="form-label">Message personnalisé</label>
                            <input type="text" id="labelsMessage" class="form-control" placeholder="Ex: Merci pour votre participation">
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mb-3">
                        <button type="button" class="btn btn-outline-primary" id="labelsPrintButton">
                            <i class="bi bi-printer me-1"></i>Imprimer les étiquettes
                        </button>
                    </div>
                    <div class="label-preview-wrapper">
                        <div class="label-pages" id="labelPages"></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <script>
        (function () {
            var labelsData = <?php echo json_encode($labelsData, JSON_UNESCAPED_UNICODE); ?>;
            var pageSelect = document.getElementById('labelsPageFormat');
            var messageInput = document.getElementById('labelsMessage');
            var labelPages = document.getElementById('labelPages');
            var printButton = document.getElementById('labelsPrintButton');
            var customWidthInput = document.getElementById('customLabelWidth');
            var customHeightInput = document.getElementById('customLabelHeight');
            var customColsInput = document.getElementById('customLabelCols');
            var customRowsInput = document.getElementById('customLabelRows');
            var customGapInput = document.getElementById('customLabelGap');

            if (!pageSelect || !labelPages || !printButton || !customWidthInput || !customHeightInput || !customColsInput || !customRowsInput || !customGapInput) {
                return;
            }

            function parsePositive(value, fallback) {
                var parsed = parseFloat(value);
                return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
            }

            function getSelectedFormat() {
                return {
                    cols: parsePositive(customColsInput.value, 2),
                    rows: parsePositive(customRowsInput.value, 7),
                    width: parsePositive(customWidthInput.value, 99) + 'mm',
                    height: parsePositive(customHeightInput.value, 38) + 'mm',
                    gap: parsePositive(customGapInput.value, 2) + 'mm'
                };
            }

            function createLabelItem(label, messageText) {
                var item = document.createElement('div');
                item.className = 'label-item';
                item.innerHTML = '' +
                    '<h6>' + label.prenom + ' ' + label.nom + '</h6>' +
                    '<div class="label-meta">Classe : ' + label.classe + '</div>' +
                    '<div class="label-meta">Tickets : ' + label.tickets_debut + ' à ' + label.tickets_fin + '</div>' +
                    '<div class="label-message">' + (messageText || '') + '</div>';
                return item;
            }

            function renderLabels() {
                var selectedFormat = getSelectedFormat();
                var totalPerPage = selectedFormat.cols * selectedFormat.rows;
                var messageText = messageInput.value;

                labelPages.innerHTML = '';
                document.body.classList.toggle('page-letter', pageSelect.value === 'letter');

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

                    var end = Math.min(index + totalPerPage, labelsData.length);
                    for (var i = index; i < end; i++) {
                        grid.appendChild(createLabelItem(labelsData[i], messageText));
                    }

                    page.appendChild(grid);
                    labelPages.appendChild(page);
                }
            }

            pageSelect.addEventListener('change', renderLabels);
            messageInput.addEventListener('input', renderLabels);
            customWidthInput.addEventListener('input', renderLabels);
            customHeightInput.addEventListener('input', renderLabels);
            customColsInput.addEventListener('input', renderLabels);
            customRowsInput.addEventListener('input', renderLabels);
            customGapInput.addEventListener('input', renderLabels);
            printButton.addEventListener('click', function () {
                window.print();
            });

            renderLabels();
        })();
    </script>
</body>
</html>
