<?php

namespace Aex\PhpAsync;

class PhpAsync
{
    private $config = [];
    public function __construct($config = []){
        if(!empty($config) && is_array($config)){
            $this->config = $config;
        }
    }

    public function fireServer(){
        echo 'this is a test';
    }
}