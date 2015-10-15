#!/usr/bin/env php
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Icicle\Coroutine;
use Icicle\Loop;
use Icicle\Stream;
use Icicle\Stream\Pipe\DuplexPipe;

$coroutine = Coroutine\create(function () {
    list($pipe1, $pipe2) = Stream\pair();

    $pipe1 = new DuplexPipe($pipe1);
    $pipe2 = new DuplexPipe($pipe2);

    // Write data to pipe 2.
    yield from $pipe2->write("This is just a test.\n");

    // Data written to pipe 2 is readable on pipe 1.
    $data = (yield from $pipe1->read());

    echo $data; // Echoes "This is just a test."
});

Loop\run();
