<?php
$db = new mysqli(
    getenv('DB_HOST') ?: 'localhost',
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '',
    getenv('DB_NAME') ?: 'vkposter'
);

if($db->connect_errno){
    die('Ошибка подключения к БД: '.$db->connect_error);
}
$db->set_charset('utf8mb4');
?>
