<?php
include_once ($_SERVER["DOCUMENT_ROOT"].'/system/db.php');
session_start();

spl_autoload_register(function ($class) {
    include $_SERVER["DOCUMENT_ROOT"].'/system/classes/' . $class . '.php';
});

$Core = new Core('http://vkposter.ru', 'VKPoster');

if(!empty($_SESSION['id'])){
    $User = new User($_SESSION['id']);
}