<?php
include_once ($_SERVER["DOCUMENT_ROOT"].'/system/db.php');
include_once($_SERVER["DOCUMENT_ROOT"].'/vendor/autoload.php');

ini_set('display_errors', '0');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
session_start();

if(!headers_sent()){
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

spl_autoload_register(function ($class) {
    if(preg_match('/^[A-Za-z0-9_]+$/', $class)){
        $file = $_SERVER["DOCUMENT_ROOT"].'/system/classes/' . $class . '.php';
        if(is_file($file)){
            include $file;
        }
    }
});

$Core = new Core(getenv('APP_URL') ?: 'http://vkposter.ru', 'VKPoster');

if(!empty($_SESSION['id'])){
    try {
        $User = new User($_SESSION['id']);
    } catch (RuntimeException $e) {
        unset($_SESSION['id']);
    }
}

function csrf_token(){
    if(empty($_SESSION['csrf'])){
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check($token){
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function redirect($path){
    header('Location: '.$path);
    exit;
}
