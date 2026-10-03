<?php
// Локальный вход без VK OAuth. Работает только при DEV_LOGIN=1.
include_once($_SERVER["DOCUMENT_ROOT"].'/system/extensions.php');

if(getenv('DEV_LOGIN') !== '1'){
    http_response_code(404);
    exit;
}

$id_vk = 1;
$row = $db->query("SELECT `id` FROM `users` WHERE `id_vk`=$id_vk")->fetch_assoc();
if(!$row){
    $stmt = $db->prepare("INSERT INTO users (id_vk, first_name, last_name, avatar, access_tocken) VALUES (?, 'Dev', 'User', '', '')");
    $stmt->bind_param("i", $id_vk);
    $stmt->execute();
    $stmt->close();
    $_SESSION['id'] = $db->insert_id;
}else{
    $_SESSION['id'] = $row['id'];
}
header('location:/');
