<?php
include_once($_SERVER["DOCUMENT_ROOT"].'/vendor/autoload.php');
include_once($_SERVER["DOCUMENT_ROOT"].'/system/extensions.php');

if(isset($User)){
    redirect('/');
}

$oauth = new \VK\OAuth\VKOAuth();
$client_id = getenv('VK_CLIENT_ID') ?: 51785244;
$redirect_uri = $Core->url. '/auth_callback';
$display = \VK\OAuth\VKOAuthDisplay::PAGE;
$scope = array(VK\OAuth\Scopes\VKOAuthUserScope::OFFLINE);
// Случайный state защищает от CSRF при входе (проверяется в auth_callback)
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$browser_url = $oauth->getAuthorizeUrl(VK\OAuth\VKOAuthResponseType::CODE, $client_id, $redirect_uri, $display, $scope, $state);
redirect($browser_url);
