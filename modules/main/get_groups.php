<?php
include_once($_SERVER["DOCUMENT_ROOT"].'/system/extensions.php');

header("Content-Type: application/json");

if(!isset($User)){
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'errors' => array('Вы не авторизованы!')));
    exit;
}

// Только группы, администратором которых является текущий пользователь
$stmt = $db->prepare("SELECT `id` FROM `groups` WHERE `id_admin`=?");
$stmt->bind_param("i", $User->vkId);
$stmt->execute();
$result = $stmt->get_result();
$data = array();

while ($group = $result->fetch_assoc()) {
    try {
        $groupObj = new Groups($group['id'], $User);
    } catch (Exception $e) {
        // Сообщение исключения Guzzle содержит URL с access_token — не логируем его
        error_log('Groups load error: '.get_class($e));
        continue;
    }
    $data[] = array(
        "id" => $groupObj->id,
        "screen_name" => $groupObj->screen_name,
        "avatar" => $groupObj->avatar,
        "admins" => null,
        "name" => $groupObj->name,
        "members" => $groupObj->members,
        "type" => $groupObj->type
    );
}
$stmt->close();

echo json_encode($data);
