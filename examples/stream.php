#!/usr/bin/env php
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Icicle\Coroutine\Coroutine;
use Icicle\Loop;
use Icicle\Stream\DuplexStreamInterface;
use Icicle\Stream\Stream;

$generator = function (DuplexStreamInterface $stream) {
    yield $stream->write("This is just a test.\nThis will not be read.");

    $data = (yield $stream->read(0, "\n"));

    echo $data; // Echoes "This is just a test."
};

$stream = new Stream();
$coroutine = new Coroutine($generator($stream));

Loop\run();
