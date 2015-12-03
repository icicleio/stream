#!/usr/bin/env php
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Icicle\Coroutine\Coroutine;
use Icicle\Loop;
use Icicle\Stream\DuplexStream;
use Icicle\Stream\MemoryStream;

$generator = function (DuplexStream $stream) {
    yield $stream->write("This is just a test.\nThis will not be read.");

    $data = (yield from $stream->read(0, "\n"));

    echo $data; // Echoes "This is just a test."
};

$stream = new MemoryStream();
$coroutine = new Coroutine($generator($stream));

Loop\run();
