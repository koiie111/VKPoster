<?php
error_reporting(E_ALL);
include_once($_SERVER["DOCUMENT_ROOT"].'/vendor/autoload.php');

$oauth = new \VK\OAuth\VKOAuth();
$client_id = 51785244;
$redirect_uri = 'http://vkposter.ru/auth_callback';
$display = \VK\OAuth\VKOAuthDisplay::PAGE;
$scope = array(VK\OAuth\Scopes\VKOAuthUserScope::WALL, VK\OAuth\Scopes\VKOAuthUserScope::GROUPS);
$state = 'secret_state_code';

$browser_url = $oauth->getAuthorizeUrl(VK\OAuth\VKOAuthResponseType::CODE, $client_id, $redirect_uri, $display, $scope, $state);
header('location:'.$browser_url);
?>