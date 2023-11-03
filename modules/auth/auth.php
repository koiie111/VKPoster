<?php
include_once($_SERVER["DOCUMENT_ROOT"].'/vendor/autoload.php');
include_once($_SERVER["DOCUMENT_ROOT"].'/system/extensions.php');

if(isset($User)){
    header('location:/');
}

$oauth = new \VK\OAuth\VKOAuth();
$client_id = 51785244;
$redirect_uri = $Core->url. '/auth_callback';
$display = \VK\OAuth\VKOAuthDisplay::PAGE;
$scope = array(VK\OAuth\Scopes\VKOAuthUserScope::OFFLINE);
$state = 'secret_state_code';

$browser_url = $oauth->getAuthorizeUrl(VK\OAuth\VKOAuthResponseType::CODE, $client_id, $redirect_uri, $display, $scope, $state);
header('location:'.$browser_url);
?>