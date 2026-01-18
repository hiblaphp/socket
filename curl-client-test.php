<?php

use Hibla\HttpClient\Http;
use Hibla\Promise\Promise;

require __DIR__ . '/vendor/autoload.php';

$start = microtime(true);

$results = Promise::all([
    fetch('https://jsonplaceholder.typicode.com/todos/1'),
    fetch('https://jsonplaceholder.typicode.com/todos/2'),
    fetch('https://jsonplaceholder.typicode.com/todos/3'),
    fetch('https://jsonplaceholder.typicode.com/todos/4'),
    fetch('https://jsonplaceholder.typicode.com/todos/5'),
])->wait();

foreach ($results as $result) {
    echo $result->getBody() . "\n";
}

$end = microtime(true);
$elapsed = $end - $start;
echo "Total elapsed time: {$elapsed} seconds\n";
