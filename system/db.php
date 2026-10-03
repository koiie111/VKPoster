<?php
$db = new mysqli(
    getenv('DB_HOST') ?: 'localhost',
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '',
    getenv('DB_NAME') ?: 'vkposter'
);

if($db->connect_errno){
    error_log('DB connection error: '.$db->connect_error);
    http_response_code(500);
    die('Ошибка подключения к БД');
}
$db->set_charset('utf8mb4');
?>
