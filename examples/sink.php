#!/usr/bin/env php
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Icicle\Coroutine;
use Icicle\Loop;
use Icicle\Stream\MemorySink;

$coroutine = Coroutine\create(function () {
    $sink = new MemorySink();

    yield from $sink->write("This is just a test.\n");

    yield from $sink->seek(15);

    yield from $sink->write("sink ");

    yield from $sink->seek(0);

    $data = (yield from $sink->read(0, "\n"));

    echo $data; // Echoes "This is just a sink test."
});

Loop\run();
