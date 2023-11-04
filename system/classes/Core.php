<?php
class Core
{

    public $url;
    public $name;
    function __construct($url, $name)
    {
        $this->url = $url;
        $this->name = $name;
    }

    public static function outputText($string){
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
}