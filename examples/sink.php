#!/usr/bin/env php
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Icicle\Coroutine;
use Icicle\Loop;
use Icicle\Stream\MemorySink;

$coroutine = Coroutine\create(function () {
    $sink = new MemorySink();

    yield $sink->write("This is just a test.\n");

    yield $sink->seek(15);

    yield $sink->write("sink ");

    yield $sink->seek(0);

    $data = (yield $sink->read(0, "\n"));

    echo $data; // Echoes "This is just a sink test."
});

Loop\run();
