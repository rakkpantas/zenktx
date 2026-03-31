<?php
$config = include __DIR__.'/../config/omdb.php';
function fetchFromOMDb($title, $type='movie') {
    global $config;
    if(empty($config['api_key'])) return null;
    $url = "https://www.omdbapi.com/?apikey={$config['api_key']}&t=".urlencode($title)."&type=".$type."&plot=full";
    $json = @file_get_contents($url);
    return $json ? json_decode($json, true) : null;
}
