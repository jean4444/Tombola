<?php
// backup_settings.php - Paramètres de sauvegarde (surcharge via backup_settings.local.php)

function backupEnv($key, $default = null) {
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    return $value;
}

function backupEnvBool($key, $default = false) {
    $value = backupEnv($key, null);
    if ($value === null) {
        return $default;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

function backupEnvInt($key, $default = 0) {
    $value = backupEnv($key, null);
    if ($value === null || $value === '') {
        return $default;
    }
    return (int) $value;
}

function mergeBackupSettings(array $base, array $override) {
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = mergeBackupSettings($base[$key], $value);
        } else {
            $base[$key] = $value;
        }
    }
    return $base;
}

function loadBackupSettings() {
    $settings = [
        'enabled' => backupEnvBool('TOMBOLA_BACKUP_ENABLED', false),
        'backup_dir' => backupEnv('TOMBOLA_BACKUP_DIR', __DIR__ . '/backups'),
        'retention_days' => backupEnvInt('TOMBOLA_BACKUP_RETENTION_DAYS', 30),
        'filename_prefix' => backupEnv('TOMBOLA_BACKUP_PREFIX', 'backup_tombola_'),
        'compress' => backupEnvBool('TOMBOLA_BACKUP_COMPRESS', true),
        'allow_web_trigger' => backupEnvBool('TOMBOLA_BACKUP_ALLOW_WEB', false),
        'web_token' => backupEnv('TOMBOLA_BACKUP_WEB_TOKEN', ''),
        'google_drive' => [
            'enabled' => backupEnvBool('TOMBOLA_BACKUP_GDRIVE_ENABLED', false),
            'folder_id' => backupEnv('TOMBOLA_BACKUP_GDRIVE_FOLDER_ID', ''),
            'access_token' => backupEnv('TOMBOLA_BACKUP_GDRIVE_ACCESS_TOKEN', ''),
            'refresh_token' => backupEnv('TOMBOLA_BACKUP_GDRIVE_REFRESH_TOKEN', ''),
            'client_id' => backupEnv('TOMBOLA_BACKUP_GDRIVE_CLIENT_ID', ''),
            'client_secret' => backupEnv('TOMBOLA_BACKUP_GDRIVE_CLIENT_SECRET', ''),
        ],
    ];

    $localSettingsPath = __DIR__ . '/backup_settings.local.php';
    if (file_exists($localSettingsPath)) {
        $localSettings = include $localSettingsPath;
        if (is_array($localSettings)) {
            $settings = mergeBackupSettings($settings, $localSettings);
        }
    }

    return $settings;
}
