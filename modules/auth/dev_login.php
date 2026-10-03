<?php
// Локальный вход без VK OAuth. Работает только при DEV_LOGIN=1.
include_once($_SERVER["DOCUMENT_ROOT"].'/system/extensions.php');

if(getenv('DEV_LOGIN') !== '1'){
    http_response_code(404);
    exit;
}

$id_vk = 1;
$stmt = $db->prepare("SELECT `id` FROM `users` WHERE `id_vk`=?");
$stmt->bind_param("i", $id_vk);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if(!$row){
    $stmt = $db->prepare("INSERT INTO users (id_vk, first_name, last_name, avatar, access_tocken) VALUES (?, 'Dev', 'User', '', '')");
    $stmt->bind_param("i", $id_vk);
    $stmt->execute();
    $stmt->close();
    $user_id = $db->insert_id;
}else{
    $user_id = $row['id'];
}
session_regenerate_id(true);
$_SESSION['id'] = $user_id;
redirect('/');
