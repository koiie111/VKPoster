<?php
include_once($_SERVER["DOCUMENT_ROOT"].'/system/extensions.php');

if(!isset($User)){
    header('location:/');
}

$query = $db->query("SELECT `id` FROM `groups`");
$data = array();

while ($group = $query->fetch_assoc()) {
    $groupObj = new Groups($group['id'], $User);
    // Добавляем данные в массив
    $data[] = array(
        "id" => $groupObj->id,
        "screen_name" => $groupObj->screen_name,
        "avatar" => $groupObj->avatar,
        "admins" => null,
        "name" => $groupObj->name,
        "members" => $groupObj->members,
        "type" => $groupObj->type,
        "Action" => '<button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modalDel" data-bs-target="#confirmModal" data-group-id="" data-group-name="">
                      <i class="bi bi-trash3"></i>
                    </button>'
    );
}

// Возвращаем данные в формате JSON
header("Content-Type: application/json");
echo json_encode($data);
?>