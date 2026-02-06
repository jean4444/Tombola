<?php
session_start();

// Définir un utilisateur par défaut
define('USER_DEFAULT', 'admin');
define('PASS_DEFAULT', 'tombola2024');

// Vérifier si l'utilisateur est connecté
function estConnecte() {
    return isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
}

// Vérifier les identifiants
function verifierIdentifiants($username, $password) {
    return ($username === USER_DEFAULT && $password === PASS_DEFAULT) ||
           ($username === 'jean-yves.genetet@orange.fr' && $password === 'votre_mot_de_passe'); // Ajoutez votre email
}

// Connecter l'utilisateur
function connecterUtilisateur($username) {
    $_SESSION['loggedin'] = true;
    $_SESSION['username'] = $username;
}

// Déconnecter l'utilisateur
function deconnecterUtilisateur() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

// Rediriger si non connecté
function redirigerSiNonConnecte() {
    if (!estConnecte()) {
        header("Location: login.php");
        exit;
    }
}
function estAdministrateur() {
    // Vérifiez ici comment vous gérez les rôles administrateurs
    // Par exemple :
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        return false;
    }
    return true;
}
?>