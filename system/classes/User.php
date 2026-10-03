<?php

class User
{

    public $id;
    public $vkId;
    public $firstName;
    public $lastName;
    public $avatar;
    private $access_token;
    private $private_token;

    function __construct($id)
    {
        global $db;
        $id = abs(intval($id));
        $this->id = $id;
        $stmt = $db->prepare("SELECT * FROM `users` WHERE `id`=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$user){
            // Пользователь удалён — сессия недействительна
            unset($_SESSION['id']);
            throw new RuntimeException('User not found');
        }
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

    function getPrivateTocken(){
        return Core::outputText((string)$this->private_token);
    }

    function setPrivateTocken($tocken){
        global $db;
        $query = "UPDATE users SET `private_tocken` = ? WHERE `id` = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("si", $tocken, $this->id);
        $result = $stmt->execute();
        $stmt->close();
        if(!$result){
            return false;
        }else{
            $this->private_token = $tocken;
            return true;
        }
    }

    function checkPrivateTocken(){
        try {
            $vk = new VK\Client\VKApiClient();
            $response = $vk->account()->getProfileInfo($this->private_token);
            if (!isset($response['id']) || $response['id'] != $this->vkId) {
                return false;
            } else {
                return true;
            }
        }catch (Exception $e){
            return false;
        }
    }
}