<?php
include_once ($_SERVER["DOCUMENT_ROOT"].'/system/extensions.php');
include_once($_SERVER["DOCUMENT_ROOT"].'/vendor/autoload.php');

$inputUrl = $_POST['inputUrl'];

$errors = array();

$codePattern = '/#access_token=([a-zA-Z0-9_\-\.]+).*?&user_id=(\d+)/';


if (!isset($User)) {
    $errors[] = 'Вы не авторизованы!';
}

if (preg_match($codePattern, $inputUrl, $matches)) {
    $access_token = $matches[1];
    $user_id = $matches[2];
    $vk = new VK\Client\VKApiClient();
    $response = $vk->account()->getProfileInfo($access_token);
    if(!isset($response['id'])){
        $errors[] = 'Не удалось авторизоваться по токену';
    }else{
        if($response['id'] != $User->vkId){
            $errors[] = 'Указан чужой токен';
        }else{
            if(!$User->setPrivateTocken($access_token)){
                $errors[] = 'Произошла серверная ошибка при обновлении данных';
            }
        }
    }
} else {
    $errors[] = "Введенное значение не содержит правильный access_token или user_id.";
}

$response = array();

if (empty($errors)) {
    $response = array('status' => 'success', 'access_token' => $access_token);
} else {
    $response = array('status' => 'error', 'errors' => $errors);
}

header('Content-Type: application/json');
echo json_encode($response);
?>
