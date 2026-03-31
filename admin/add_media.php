<?php
require_once __DIR__.'/../lib/omdb_fetch.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $title = trim($_POST['title']);
    $type  = $_POST['type'] ?? 'movie';

    $data = fetchFromOMDb($title, $type);
    if(!$data || $data['Response']!=='True'){
        die('OMDb fetch failed');
    }

    $movie = [
        'title' => $data['Title'] ?? '',
        'year' => $data['Year'] ?? '',
        'type' => $type,
        'genre' => $data['Genre'] ?? '',
        'runtime' => $data['Runtime'] ?? '',
        'plot' => $data['Plot'] ?? '',
        'director' => $data['Director'] ?? '',
        'actors' => $data['Actors'] ?? '',
        'poster' => $data['Poster'] ?? ''
    ];

    $path = __DIR__.'/../movies.json';
    $list = file_exists($path) ? json_decode(file_get_contents($path), true) : [];
    $list[] = $movie;
    file_put_contents($path, json_encode($list, JSON_PRETTY_PRINT));
    header('Location: /admin');
}
