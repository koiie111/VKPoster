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
        $group = $db->query("SELECT * FROM `groups` WHERE `id`=".$id)->fetch_assoc();
        $this->id_group = $group['id_group'];
        $this->id_admin = $group['id_admin'];

        $vk = new \VK\Client\VKApiClient();
        $response = $vk->groups()->getById($User->getPrivateTocken(), array('group_id' => $this->id_group, 'fields' => 'members_count,type,screen_name'));

        $this->name = Core::outputText($response[0]['name']);
        $this->avatar = Core::outputText($response[0]['photo_100']);
        $this->members = Core::outputText($response[0]['members_count']);
        if($response[0]['type'] == 'group'){
            $this->type = 'Группа';
        }elseif($response[0]['type'] == 'page'){
            $this->type = 'Публичная страница';
        }elseif($response[0]['type'] == 'event'){
            $this->type = 'Мероприятие';
        }
        $this->screen_name = Core::outputText($response[0]['screen_name']);
    }

    public static function countGroupsOnAdmin($db, $User){
        return $db->query("SELECT `id` FROM `groups` WHERE `id_admin`='".$User->vkId."'")->num_rows;
    }
}