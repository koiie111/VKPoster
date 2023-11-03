<?php
include_once($_SERVER["DOCUMENT_ROOT"].'/system/extensions.php');
include_once($_SERVER["DOCUMENT_ROOT"].'/vendor/autoload.php');

if(isset($User)){
    header('location:/');
}

$oauth = new VK\OAuth\VKOAuth();
$client_id = 51785244;
$client_secret = 'wbp4xtuz2NPqXl22PVOH';
$redirect_uri = $Core->url. '/auth_callback';
$code = $_GET['code'];

$response = $oauth->getAccessToken($client_id, $client_secret, $redirect_uri, $code);
$access_token = $response['access_token'];

$vk = new \VK\Client\VKApiClient();
$response = $vk->users()->get($access_token, array('fields' => array('id', 'first_name', 'last_name', 'photo_200_orig')));
$id_vk = abs(intval($response[0]['id']));
$query_user = "SELECT `id` FROM `users` WHERE `id_vk`=$id_vk";
if($db->query($query_user)->num_rows == 0){
    $query = "INSERT INTO users (id_vk, first_name, last_name, avatar, access_tocken) VALUES (?, ?, ?, ?, ?)";
    $stmt = $db->prepare($query);
    $stmt->bind_param("issss", $id_vk, $response[0]['first_name'], $response[0]['last_name'], $response[0]['photo_200_orig'], $access_token);
    $result = $stmt->execute();
    $stmt->close();
    $_SESSION['id'] = $db->insert_id;
    header('location:/');
}else{
    $_SESSION['id'] = $db->query($query_user)->fetch_assoc()['id'];
    header('location:/');
}
?>


