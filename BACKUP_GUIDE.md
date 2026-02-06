# Guide de sauvegarde automatique Tombola

Ce guide explique comment activer une sauvegarde automatique locale et une copie de secours sur Google Drive.

## 1) Paramétrage local

Par défaut, la configuration est lue via les variables d'environnement et peut être surchargée par un fichier local **non versionné** :

```
backup_settings.local.php
```

Créez ce fichier à la racine du site pour personnaliser le comportement :

```php
<?php
return [
    'enabled' => true,
    'backup_dir' => __DIR__ . '/backups',
    'retention_days' => 30,
    'filename_prefix' => 'backup_tombola_',
    'compress' => true,
    'allow_web_trigger' => false,
    'web_token' => 'CHANGE_ME',
    'google_drive' => [
        'enabled' => true,
        'folder_id' => 'ID_DOSSIER_DRIVE',
        'access_token' => '',
        'refresh_token' => 'REFRESH_TOKEN',
        'client_id' => 'CLIENT_ID',
        'client_secret' => 'CLIENT_SECRET',
    ],
];
```

Les mêmes paramètres peuvent être fournis via les variables d'environnement suivantes :

- `TOMBOLA_BACKUP_ENABLED`
- `TOMBOLA_BACKUP_DIR`
- `TOMBOLA_BACKUP_RETENTION_DAYS`
- `TOMBOLA_BACKUP_PREFIX`
- `TOMBOLA_BACKUP_COMPRESS`
- `TOMBOLA_BACKUP_ALLOW_WEB`
- `TOMBOLA_BACKUP_WEB_TOKEN`
- `TOMBOLA_BACKUP_GDRIVE_ENABLED`
- `TOMBOLA_BACKUP_GDRIVE_FOLDER_ID`
- `TOMBOLA_BACKUP_GDRIVE_ACCESS_TOKEN`
- `TOMBOLA_BACKUP_GDRIVE_REFRESH_TOKEN`
- `TOMBOLA_BACKUP_GDRIVE_CLIENT_ID`
- `TOMBOLA_BACKUP_GDRIVE_CLIENT_SECRET`

## 2) Mise en place du cron

Ajoutez une tâche cron pour lancer la sauvegarde :

```
*/30 * * * * /usr/bin/php /chemin/vers/auto_backup.php >> /chemin/vers/backups/cron.log 2>&1
```

Le script `auto_backup.php` :
- crée une sauvegarde locale,
- purge les sauvegardes anciennes selon `retention_days`,
- envoie la copie sur Google Drive si activé.

## 3) Google Drive (copie de secours)

Le script utilise l'API Google Drive via OAuth2. Vous pouvez :

- fournir un `access_token` déjà valide,
- **ou** fournir `refresh_token`, `client_id` et `client_secret` pour générer automatiquement un nouveau token.

Le champ `folder_id` est optionnel : s'il est vide, le fichier est déposé à la racine du Drive.

## 4) Appel web sécurisé (optionnel)

Si vous devez déclencher la sauvegarde via HTTP :

```php
'allow_web_trigger' => true,
'web_token' => 'TOKEN_COMPLEXE',
```

Puis appelez :

```
https://votre-site/auto_backup.php?token=TOKEN_COMPLEXE
```

## 5) Fichiers concernés

- `backup_settings.php` : paramètres et lecture des variables d'environnement.
- `auto_backup.php` : exécution de la sauvegarde automatique.
- `backup.php` : export SQL (téléchargement manuel et utilisé par l'automatique).
