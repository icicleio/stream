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
use Icicle\Stream\TextReader;

class TextReaderTest extends TestCase
{
    public function testGetStream()
    {
        $stream = new Stream();
        $reader = new TextReader($stream);

        $this->assertSame($stream, $reader->getStream());
    }

    public function testIsOpen()
    {
        $stream = new Stream();
        $reader = new TextReader($stream);

        $this->assertTrue($reader->isOpen());
        $stream->close();
        $this->assertFalse($reader->isOpen());
    }

    public function testClose()
    {
        $stream = new Stream();
        $reader = new TextReader($stream);

        $this->assertTrue($stream->isOpen());
        $reader->close();
        $this->assertFalse($stream->isOpen());
    }

    public function testReadSingleChar()
    {
        Coroutine\create(function () {
            $stream = new Stream();
            $reader = new TextReader($stream);

            yield $stream->end("hello");

            $this->assertEquals("h", (yield $reader->read(1)));
        })->done();

        Loop\run();
    }

    public function testReadSingleMultibyteChar()
    {
        Coroutine\create(function () {
            $stream = new Stream();
            $reader = new TextReader($stream);

            yield $stream->end("õwen");

            $this->assertEquals("õ", (yield $reader->read(1)));
        })->done();

        Loop\run();
    }

    public function testReadLine()
    {
        Coroutine\create(function () {
            $stream = new Stream();
            $reader = new TextReader($stream);

            yield $stream->end("In theory, there is no difference between theory and practice.\nBut, in practice, there is.\n");

            $this->assertEquals("In theory, there is no difference between theory and practice.\n", (yield $reader->readLine()));
        })->done();

        Loop\run();
    }

    public function testReadAll()
    {
        Coroutine\create(function () {
            $stream = new Stream();
            $reader = new TextReader($stream);

            $string = "In theory, there is no difference between theory and practice.\nBut, in practice, there is.\n";
            yield $stream->end($string);

            $this->assertEquals($string, (yield $reader->readAll()));
            $this->assertFalse($reader->isOpen());
        })->done();

        Loop\run();
    }

    public function testPeekDoesNotConsume()
    {
        Coroutine\create(function () {
            $stream = new Stream();
            $reader = new TextReader($stream);

            yield $stream->end("õwen");

            $this->assertEquals("õ", (yield $reader->peek(1)));
            $this->assertEquals("õ", (yield $reader->read(1)));
        })->done();

        Loop\run();
    }

    public function testReadLineAfterPeek()
    {
        Coroutine\create(function () {
            $stream = new Stream();
            $reader = new TextReader($stream);

            yield $stream->end("In theory, there is no difference between theory and practice.\nBut, in practice, there is.\n");
            yield $reader->peek(10);

            $this->assertEquals("In theory, there is no difference between theory and practice.\n", (yield $reader->readLine()));
        })->done();

        Loop\run();
    }

    public function testReadAllAfterPeek()
    {
        Coroutine\create(function () {
            $stream = new Stream();
            $reader = new TextReader($stream);

            $string = "In theory, there is no difference between theory and practice.\nBut, in practice, there is.\n";
            yield $stream->end($string);
            yield $reader->peek(10);

            $this->assertEquals($string, (yield $reader->readAll()));
        })->done();

        Loop\run();
    }

    public function testScanConsumesOnlyMatchedCharacters()
    {
        Coroutine\create(function () {
            $stream = new Stream();
            $reader = new TextReader($stream);

            yield $stream->end("99 bottles of beer\non the wall");

            $this->assertEquals([99], (yield $reader->scan("%d")));
            $this->assertEquals(" bottles of beer\non the wall", (yield $reader->readAll()));
        })->done();

        Loop\run();
    }
}
