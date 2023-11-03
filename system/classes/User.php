<?php

class User
{

    public $vkId;
    public $firstName;
    public $lastName;
    public $avatar;
    private $access_token;
    private $private_token;

    function __construct($id)
    {
        global $db;
        $user = $db->query("SELECT * FROM `users` WHERE `id`=".abs(intval($id)))->fetch_assoc();
        $this->vkId = $user['id_vk'];
        $this->firstName = $user['first_name'];
        $this->lastName = $user['last_name'];
        $this->avatar = $user['avatar'];
        $this->access_token = $user['access_tocken'];
        $this->private_token = $user['private_tocken'];
    }

    function getVkId(){

    }

    function getFirstName(){

    }

    function getLastName()
    {

    }

    function getAvatar(){

    }
}