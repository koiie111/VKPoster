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

    function outputText($string){
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
}