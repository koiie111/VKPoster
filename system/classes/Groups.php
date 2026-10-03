<?php

class Groups
{

    public $id;
    public $id_group;
    public $id_admin;
    public $name;
    public $avatar;
    public $members;
    public $type;
    public $screen_name;
    function __construct($id, $User){
        global $db;
        $id = abs(intval($id));
        $this->id = $id;
        // Группа доступна только своему администратору
        $stmt = $db->prepare("SELECT * FROM `groups` WHERE `id`=? AND `id_admin`=?");
        $stmt->bind_param("ii", $id, $User->vkId);
        $stmt->execute();
        $group = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$group){
            throw new RuntimeException('Group not found');
        }
        $this->id_group = $group['id_group'];
        $this->id_admin = $group['id_admin'];

        $vk = new \VK\Client\VKApiClient();
        $response = $vk->groups()->getById($User->getPrivateTocken(), array('group_id' => $this->id_group, 'fields' => 'members_count,type,screen_name'));

        $this->name = $response[0]['name'];
        $this->avatar = $response[0]['photo_100'];
        $this->members = intval($response[0]['members_count']);
        if($response[0]['type'] == 'group'){
            $this->type = 'Группа';
        }elseif($response[0]['type'] == 'page'){
            $this->type = 'Публичная страница';
        }elseif($response[0]['type'] == 'event'){
            $this->type = 'Мероприятие';
        }
        $this->screen_name = $response[0]['screen_name'];
    }

    public static function countGroupsOnAdmin($db, $User){
        $stmt = $db->prepare("SELECT `id` FROM `groups` WHERE `id_admin`=?");
        $stmt->bind_param("i", $User->vkId);
        $stmt->execute();
        $count = $stmt->get_result()->num_rows;
        $stmt->close();
        return $count;
    }
}