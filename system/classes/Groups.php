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
    function __construct($id, $User){
        global $db;
        $id = abs(intval($id));
        $this->id = $id;
        $group = $db->query("SELECT * FROM `groups` WHERE `id`=".$id)->fetch_assoc();
        $this->id_group = $group['id_group'];
        $this->id_admin = $group['id_admin'];
        $this->name = $this->getName($User);
        $this->avatar = $this->getAvatar($User);
        $this->members = $this->getMembers($User);
        $this->type = $this->getType($User);
    }

    public static function countGroupsOnAdmin($db, $User){
        return $db->query("SELECT `id` FROM `groups` WHERE `id_admin`='".$User->vkId."'")->num_rows;
    }

    function getName($User){
        $vk = new \VK\Client\VKApiClient();
        $response = $vk->groups()->getById($User->getPrivateTocken(), array('group_id' => $this->id_group));
        return Core::outputText($response[0]['name']);
    }

    function getAvatar($User){
        $vk = new \VK\Client\VKApiClient();
        $response = $vk->groups()->getById($User->getPrivateTocken(), array('group_id' => $this->id_group));
        return Core::outputText($response[0]['photo_100']);
    }

    function getMembers($User){
        $vk = new \VK\Client\VKApiClient();
        $response = $vk->groups()->getById($User->getPrivateTocken(), array('group_id' => $this->id_group, 'fields' => 'members_count'));
        return Core::outputText($response[0]['members_count']);
    }

    function getType($User){
        $vk = new \VK\Client\VKApiClient();
        $response = $vk->groups()->getById($User->getPrivateTocken(), array('group_id' => $this->id_group, 'fields' => 'type'));
        if($response[0]['type'] == 'group'){
            $ret = 'Группа';
        }elseif($response[0]['type'] == 'page'){
            $ret = 'Публичная страница';
        }elseif($response[0]['type'] == 'event'){
            $ret = 'Мероприятие';
        }

        return $ret;
    }
}