<?php
include_once($_SERVER["DOCUMENT_ROOT"].'/system/extensions.php');
include_once($_SERVER["DOCUMENT_ROOT"].'/vendor/autoload.php');

if(isset($User)){
    redirect('/');
}

$client_id = getenv('VK_CLIENT_ID') ?: 51785244;
$client_secret = getenv('VK_CLIENT_SECRET');
if(empty($client_secret)){
    error_log('VK_CLIENT_SECRET is not configured');
    http_response_code(500);
    exit('Авторизация через ВК не настроена');
}

$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;
$expected_state = $_SESSION['oauth_state'] ?? null;
unset($_SESSION['oauth_state']);
if(!is_string($code) || $code === '' || !is_string($state) || !is_string($expected_state) || !hash_equals($expected_state, $state)){
    http_response_code(400);
    exit('Некорректный запрос авторизации');
}

try {
    $oauth = new VK\OAuth\VKOAuth();
    $redirect_uri = $Core->url. '/auth_callback';
    $response = $oauth->getAccessToken($client_id, $client_secret, $redirect_uri, $code);
    $access_token = $response['access_token'];

    $vk = new \VK\Client\VKApiClient();
    $response = $vk->users()->get($access_token, array('fields' => array('id', 'first_name', 'last_name', 'photo_200_orig')));
} catch (Exception $e) {
    error_log('VK auth error: '.$e->getMessage());
    http_response_code(502);
    exit('Не удалось авторизоваться через ВК');
}

$id_vk = abs(intval($response[0]['id']));
$stmt = $db->prepare("SELECT `id` FROM `users` WHERE `id_vk`=?");
$stmt->bind_param("i", $id_vk);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$row){
    $stmt = $db->prepare("INSERT INTO users (id_vk, first_name, last_name, avatar, access_tocken) VALUES (?, ?, ?, ?, ?)");
    $avatar = $response[0]['photo_200_orig'] ?? '';
    $stmt->bind_param("issss", $id_vk, $response[0]['first_name'], $response[0]['last_name'], $avatar, $access_token);
    $stmt->execute();
    $user_id = $db->insert_id;
    $stmt->close();
}else{
    $user_id = $row['id'];
}

// Защита от session fixation
session_regenerate_id(true);
$_SESSION['id'] = $user_id;
redirect('/');
