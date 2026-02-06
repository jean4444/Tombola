<?php
// auto_backup.php - sauvegarde automatique via cron ou appel web sécurisé

require_once 'config.php';
require_once 'backup.php';
require_once 'backup_settings.php';

function backupLog($message, $backupDir) {
    $timestamp = date('Y-m-d H:i:s');
    $line = '[' . $timestamp . '] ' . $message . PHP_EOL;
    $logPath = rtrim($backupDir, '/\\') . '/backup.log';
    file_put_contents($logPath, $line, FILE_APPEND);
}

function ensureBackupDir($backupDir) {
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
            throw new RuntimeException('Impossible de créer le dossier de sauvegarde: ' . $backupDir);
        }
    }
}

function buildBackupFilename(array $settings) {
    $prefix = $settings['filename_prefix'];
    $datePart = date('Y-m-d_H-i-s');
    $extension = $settings['compress'] ? '.sql.gz' : '.sql';
    return $prefix . $datePart . $extension;
}

function writeBackupFile($sql, array $settings) {
    $backupDir = rtrim($settings['backup_dir'], '/\\');
    ensureBackupDir($backupDir);

    $payload = $settings['compress'] ? gzencode($sql, 9) : $sql;
    $filename = buildBackupFilename($settings);
    $path = $backupDir . '/' . $filename;

    if (file_put_contents($path, $payload) === false) {
        throw new RuntimeException('Échec de l\'écriture du fichier de sauvegarde.');
    }

    return $path;
}

function cleanupOldBackups(array $settings) {
    $backupDir = rtrim($settings['backup_dir'], '/\\');
    if (!is_dir($backupDir)) {
        return;
    }

    $retentionDays = (int) $settings['retention_days'];
    if ($retentionDays <= 0) {
        return;
    }

    $threshold = time() - ($retentionDays * 86400);
    $pattern = $backupDir . '/' . $settings['filename_prefix'] . '*.sql*';
    foreach (glob($pattern) as $file) {
        if (is_file($file) && filemtime($file) !== false && filemtime($file) < $threshold) {
            unlink($file);
        }
    }
}

function getGoogleAccessToken(array $googleSettings) {
    if (!empty($googleSettings['refresh_token'])
        && !empty($googleSettings['client_id'])
        && !empty($googleSettings['client_secret'])) {
        $postFields = http_build_query([
            'client_id' => $googleSettings['client_id'],
            'client_secret' => $googleSettings['client_secret'],
            'refresh_token' => $googleSettings['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Erreur OAuth Google: ' . $error);
        }
        curl_close($ch);

        $payload = json_decode($response, true);
        if ($httpCode >= 400 || !isset($payload['access_token'])) {
            throw new RuntimeException('Impossible d\'obtenir un token Google Drive: ' . $response);
        }

        return $payload['access_token'];
    }

    if (!empty($googleSettings['access_token'])) {
        return $googleSettings['access_token'];
    }

    throw new RuntimeException('Aucun token Google Drive configuré.');
}

function uploadBackupToGoogleDrive($filePath, array $googleSettings) {
    $accessToken = getGoogleAccessToken($googleSettings);
    $fileName = basename($filePath);
    $fileContents = file_get_contents($filePath);
    if ($fileContents === false) {
        throw new RuntimeException('Impossible de lire le fichier de sauvegarde.');
    }

    $metadata = ['name' => $fileName];
    if (!empty($googleSettings['folder_id'])) {
        $metadata['parents'] = [$googleSettings['folder_id']];
    }

    $boundary = 'tombola_backup_' . bin2hex(random_bytes(8));
    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= json_encode($metadata) . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: application/octet-stream\r\n\r\n";
    $body .= $fileContents . "\r\n";
    $body .= "--{$boundary}--";

    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: multipart/related; boundary=' . $boundary,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Erreur upload Google Drive: ' . $error);
    }
    curl_close($ch);

    if ($httpCode >= 400) {
        throw new RuntimeException('Échec upload Google Drive: ' . $response);
    }

    return $response;
}

function isCliRequest() {
    return PHP_SAPI === 'cli';
}

$settings = loadBackupSettings();

if (!isCliRequest()) {
    if (!$settings['allow_web_trigger']) {
        http_response_code(403);
        echo 'Accès interdit.';
        exit;
    }

    $token = $_GET['token'] ?? '';
    if ($settings['web_token'] === '' || !hash_equals($settings['web_token'], $token)) {
        http_response_code(403);
        echo 'Token invalide.';
        exit;
    }
}

if (!$settings['enabled']) {
    if (isCliRequest()) {
        echo "Sauvegarde désactivée.\n";
    }
    exit;
}

try {
    $sql = exporterBaseDeDonnees(true);
    $backupPath = writeBackupFile($sql, $settings);
    cleanupOldBackups($settings);

    backupLog('Sauvegarde locale créée: ' . $backupPath, $settings['backup_dir']);

    if (!empty($settings['google_drive']['enabled'])) {
        $response = uploadBackupToGoogleDrive($backupPath, $settings['google_drive']);
        backupLog('Sauvegarde envoyée vers Google Drive: ' . $response, $settings['backup_dir']);
    }

    if (isCliRequest()) {
        echo "Sauvegarde terminée: {$backupPath}\n";
    }
} catch (Exception $e) {
    backupLog('Erreur sauvegarde: ' . $e->getMessage(), $settings['backup_dir']);
    if (isCliRequest()) {
        fwrite(STDERR, "Erreur: " . $e->getMessage() . "\n");
    } else {
        http_response_code(500);
        echo 'Erreur sauvegarde.';
    }
    exit(1);
}
