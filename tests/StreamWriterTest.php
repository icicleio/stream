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

    public function testWrite()
    {
        Coroutine\create(function () {
            $stream = new Stream();
            $writer = new StreamWriter($stream);

            yield $writer->write("hello");
            $this->assertEquals("hello", (yield $stream->read()));
        })->done();

        Loop\run();
    }
}
