<?php
include_once ($_SERVER["DOCUMENT_ROOT"].'/system/extensions.php');
include_once($_SERVER["DOCUMENT_ROOT"].'/vendor/autoload.php');

header('Content-Type: application/json');

function respond_error($code, $message){
    http_response_code($code);
    echo json_encode(array('status' => 'error', 'errors' => array($message)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error(405, 'Метод не поддерживается');
}

if (!isset($User)) {
    respond_error(401, 'Вы не авторизованы!');
}

if (!csrf_check($_POST['csrf'] ?? null)) {
    respond_error(403, 'Недействительный CSRF-токен. Обновите страницу.');
}

$inputUrl = $_POST['inputUrl'] ?? '';
if (!is_string($inputUrl) || strlen($inputUrl) > 2048) {
    respond_error(400, 'Введено неверное значение');
}

$codePattern = '/#access_token=([a-zA-Z0-9_\-\.]+).*?&user_id=(\d+)/';

if (!preg_match($codePattern, $inputUrl, $matches)) {
    respond_error(400, 'Введенное значение не содержит правильный access_token или user_id.');
}

$access_token = $matches[1];
try {
    $vk = new VK\Client\VKApiClient();
    $response = $vk->account()->getProfileInfo($access_token);
} catch (Exception $e) {
    respond_error(400, 'Не удалось авторизоваться по токену');
}

if (!isset($response['id'])) {
    respond_error(400, 'Не удалось авторизоваться по токену');
}
if ($response['id'] != $User->vkId) {
    respond_error(403, 'Указан чужой токен');
}
if (!$User->setPrivateTocken($access_token)) {
    respond_error(500, 'Произошла серверная ошибка при обновлении данных');
}

// Токен обратно клиенту не отдаём
echo json_encode(array('status' => 'success'));
