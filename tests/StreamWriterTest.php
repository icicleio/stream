<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Stream;

use Icicle\Coroutine;
use Icicle\Loop;
use Icicle\Stream\Stream;
use Icicle\Stream\StreamWriter;

class StreamWriterTest extends TestCase
{
    public function testGetStream()
    {
        $stream = new Stream();
        $writer = new StreamWriter($stream);

        $this->assertSame($stream, $writer->getStream());
    }

    public function testIsOpen()
    {
        $stream = new Stream();
        $writer = new StreamWriter($stream);

        $this->assertTrue($writer->isOpen());
        $stream->close();
        $this->assertFalse($writer->isOpen());
    }

    public function testClose()
    {
        $stream = new Stream();
        $writer = new StreamWriter($stream);

        $this->assertTrue($stream->isOpen());
        $writer->close();
        $this->assertFalse($stream->isOpen());
    }

    public function testWriteString()
    {
        Coroutine\create(function () {
            $stream = new Stream();
            $writer = new StreamWriter($stream);

            yield $writer->write("hello");

            $this->assertEquals("hello", (yield $stream->read()));
        })->done();

        Loop\run();
    }

    public function testWriteInt()
    {
        Coroutine\create(function () {
            $stream = new Stream();
            $writer = new StreamWriter($stream);

            yield $writer->write(42);

            $this->assertEquals("42", (yield $stream->read()));
        })->done();

        Loop\run();
    }

    public function testWriteLine()
    {
        Coroutine\create(function () {
            $stream = new Stream();
            $writer = new StreamWriter($stream);

            yield $writer->writeLine("hello");

            $this->assertEquals("hello" . PHP_EOL, (yield $stream->read()));
        })->done();

        Loop\run();
    }

    public function testPrintf()
    {
        Coroutine\create(function () {
            $stream = new Stream();
            $writer = new StreamWriter($stream);

            yield $writer->printf("Hello, %d %s.", 42, "fools");

            $this->assertEquals("Hello, 42 fools.", (yield $stream->read()));
        })->done();

        Loop\run();
    }

    public function testPrintLine()
    {
        Coroutine\create(function () {
            $stream = new Stream();
            $writer = new StreamWriter($stream);

            yield $writer->printLine("Hello, %d %s.", 42, "fools");

            $this->assertEquals("Hello, 42 fools." . PHP_EOL, (yield $stream->read()));
        })->done();

        Loop\run();
    }
}
