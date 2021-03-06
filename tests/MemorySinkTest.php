<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Stream;

use Icicle\Coroutine\Coroutine;
use Icicle\Exception\InvalidArgumentError;
use Icicle\Loop;
use Icicle\Loop\SelectLoop;
use Icicle\Stream\Exception\OutOfBoundsException;
use Icicle\Stream\Exception\UnreadableException;
use Icicle\Stream\Exception\UnseekableException;
use Icicle\Stream\Exception\UnwritableException;
use Icicle\Stream\MemorySink;

class MemorySinkTest extends TestCase
{
    const WRITE_STRING = 'abcdefghijklmnopqrstuvwxyz';
    const CHUNK_SIZE = 8192;
    const TIMEOUT = 0.1;
    
    public function setUp()
    {
        Loop\loop(new SelectLoop());
    }

    /**
     * @return \Icicle\Stream\MemorySink
     */
    public function createSink()
    {
        return new MemorySink();
    }

    public function testEmptySinkIsUnreadable()
    {
        $sink = $this->createSink();

        $this->assertFalse($sink->isReadable());
        $this->assertSame(0, $sink->getLength());

        $promise = new Coroutine($sink->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnreadableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    public function testEmptySinkIsWritable()
    {
        $sink = $this->createSink();

        $this->assertTrue($sink->isWritable());

        $promise = new Coroutine($sink->write(self::WRITE_STRING));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(self::WRITE_STRING)));

        $promise->done($callback);

        Loop\run();

        $this->assertSame(strlen(self::WRITE_STRING), $sink->getLength());

        return $sink;
    }

    /**
     * @depends testEmptySinkIsWritable
     *
     * @param \Icicle\Stream\Sink $sink
     */
    public function testWriteThenSeekThenRead($sink)
    {

        $promise = new Coroutine($sink->seek(0));

        Loop\run();

        $this->assertFalse($promise->isPending());
        $this->assertSame(0, $sink->tell());

        $promise = new Coroutine($sink->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise->done($callback);

        Loop\run();

        $this->assertFalse($sink->isReadable());
    }

    /**
     * @depends testEmptySinkIsWritable
     *
     * @param \Icicle\Stream\Sink $sink
     */
    public function testReadWithLength($sink)
    {

        $length = 10;

        $promise = new Coroutine($sink->seek(0));

        Loop\run();

        $this->assertFalse($promise->isPending());

        $promise = new Coroutine($sink->read($length));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(self::WRITE_STRING, 0, $length)));

        $promise->done($callback);

        Loop\run();

        $this->assertTrue($sink->isReadable());
        $this->assertSame($length, $sink->tell());
    }

    /**
     * @depends testEmptySinkIsWritable
     *
     * @param \Icicle\Stream\Sink $sink
     */
    public function testReadWithLengthLongerThanSinkLength($sink)
    {

        $length = self::CHUNK_SIZE;

        $promise = new Coroutine($sink->seek(0));

        Loop\run();

        $this->assertFalse($promise->isPending());

        $promise = new Coroutine($sink->read($length));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise->done($callback);

        Loop\run();

        $this->assertFalse($sink->isReadable());
        $this->assertSame(strlen(self::WRITE_STRING), $sink->tell());
    }

    /**
     * @depends testEmptySinkIsWritable
     *
     * @param \Icicle\Stream\Sink $sink
     */
    public function testReadWithZeroLength($sink)
    {

        $promise = new Coroutine($sink->seek(0));

        Loop\run();

        $this->assertFalse($promise->isPending());

        $promise = new Coroutine($sink->read(0));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise->done($callback);

        Loop\run();

        $this->assertFalse($sink->isReadable());
        $this->assertSame(strlen(self::WRITE_STRING), $sink->tell());
    }

    /**
     * @depends testEmptySinkIsWritable
     *
     * @param \Icicle\Stream\Sink $sink
     */
    public function testReadWithInvalidLength($sink)
    {

        $promise = new Coroutine($sink->seek(0));

        Loop\run();

        $this->assertFalse($promise->isPending());

        $promise = new Coroutine($sink->read(-1));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(InvalidArgumentError::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $this->assertTrue($sink->isReadable());
        $this->assertSame(0, $sink->tell());
    }

    /**
     * @depends testEmptySinkIsWritable
     *
     * @param \Icicle\Stream\Sink $sink
     */
    public function testReadTo($sink)
    {

        $position = 10;
        $byte = substr(self::WRITE_STRING, $position, 1);

        $promise = new Coroutine($sink->seek(0));

        Loop\run();

        $this->assertFalse($promise->isPending());

        $promise = new Coroutine($sink->read(0, $byte));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(self::WRITE_STRING, 0, $position + 1)));

        $promise->done($callback);

        Loop\run();

        $this->assertTrue($sink->isReadable());
        $this->assertSame($position + 1, $sink->tell());
    }

    /**
     * @depends testEmptySinkIsWritable
     *
     * @param \Icicle\Stream\Sink $sink
     */
    public function testSeekThenWrite($sink)
    {

        $promise = new Coroutine($sink->seek(0));

        Loop\run();

        $promise = new Coroutine($sink->write(self::WRITE_STRING));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(self::WRITE_STRING)));

        $promise->done($callback);

        Loop\run();

        $this->assertFalse($promise->isPending());

        $promise = new Coroutine($sink->seek(0));

        Loop\run();

        $this->assertFalse($promise->isPending());
        $this->assertSame(0, $sink->tell());

        $promise = new Coroutine($sink->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING . self::WRITE_STRING));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testWriteThenSeekThenRead
     */
    public function testWriteThenWrite()
    {
        $string = "{'New String\0To Write'}\r\n";

        $sink = $this->createSink();

        new Coroutine($sink->write(self::WRITE_STRING));

        Loop\run();

        $promise = new Coroutine($sink->write($string));

        Loop\run();

        $this->assertFalse($promise->isPending());

        $promise = new Coroutine($sink->seek(0));

        Loop\run();

        $this->assertFalse($promise->isPending());
        $this->assertSame(0, $sink->tell());

        $promise = new Coroutine($sink->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING . $string));

        $promise->done($callback);

        Loop\run();

        $this->assertFalse($sink->isReadable());
    }

    public function testWriteEmptyString()
    {
        $sink = $this->createSink();

        $promise = new Coroutine($sink->write(''));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(0));

        $promise->done($callback);

        Loop\run();

        $this->assertFalse($promise->isPending());
        $this->assertFalse($sink->isReadable());
        $this->assertSame(0, $sink->getLength());
    }

    /**
     * @depends testSeekThenWrite
     */
    public function testSeekToPositionThenRead()
    {
        $position = 10;

        $sink = $this->createSink();

        new Coroutine($sink->write(self::WRITE_STRING));

        Loop\run();

        $promise = new Coroutine($sink->seek($position));

        Loop\run();

        $this->assertFalse($promise->isPending());
        $this->assertSame($position, $sink->tell());

        $promise = new Coroutine($sink->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(self::WRITE_STRING, $position)));

        $promise->done($callback);

        Loop\run();

        $this->assertFalse($sink->isReadable());
    }

    /**
     * @depends testSeekThenWrite
     */
    public function testSeekToPositionThenWrite()
    {
        $position = 10;

        $sink = $this->createSink();

        new Coroutine($sink->write(self::WRITE_STRING));

        Loop\run();

        $promise = new Coroutine($sink->seek($position));

        Loop\run();

        $this->assertFalse($promise->isPending());
        $this->assertSame($position, $sink->tell());

        $promise = new Coroutine($sink->write(self::WRITE_STRING));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(self::WRITE_STRING)));

        $promise->done($callback);

        Loop\run();

        $this->assertSame($position + strlen(self::WRITE_STRING), $sink->tell());

        $promise = new Coroutine($sink->seek(0));

        Loop\run();

        $this->assertFalse($promise->isPending());
        $this->assertSame(0, $sink->tell());

        $promise = new Coroutine($sink->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(
                  substr(self::WRITE_STRING, 0 , $position)
                . self::WRITE_STRING
                . substr(self::WRITE_STRING, $position)));

        $promise->done($callback);

        Loop\run();

        $this->assertFalse($sink->isReadable());
    }

    /**
     * @depends testWriteThenSeekThenRead
     */
    public function testSeekFromCurrentPosition()
    {
        $position = 5;

        $sink = $this->createSink();

        new Coroutine($sink->write(self::WRITE_STRING));

        Loop\run();

        $promise = new Coroutine($sink->seek(-$position, SEEK_CUR));

        Loop\run();

        $this->assertSame(strlen(self::WRITE_STRING) - $position, $sink->tell());

        $promise = new Coroutine($sink->seek(-$position, SEEK_CUR));

        Loop\run();

        $this->assertSame(strlen(self::WRITE_STRING) - $position * 2, $sink->tell());

        $promise = new Coroutine($sink->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(self::WRITE_STRING, -($position * 2))));

        $promise->done($callback);

        Loop\run();

        $this->assertFalse($sink->isReadable());
    }

    public function testSeekFromEnd()
    {
        $position = 10;

        $sink = $this->createSink();

        new Coroutine($sink->write(self::WRITE_STRING));

        Loop\run();

        $promise = new Coroutine($sink->seek(-$position, SEEK_END));

        Loop\run();

        $this->assertFalse($promise->isPending());
        $this->assertSame(strlen(self::WRITE_STRING) - $position, $sink->tell());

        $promise = new Coroutine($sink->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(self::WRITE_STRING, -$position)));

        $promise->done($callback);

        Loop\run();

        $this->assertFalse($sink->isReadable());
    }

    public function testSeekWithInvalidOffset()
    {
        $sink = $this->createSink();

        new Coroutine($sink->write(self::WRITE_STRING));

        Loop\run();

        $promise = new Coroutine($sink->seek(-1));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(OutOfBoundsException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $promise = new Coroutine($sink->seek($sink->getLength() + 1));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(OutOfBoundsException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        return $sink;
    }

    /**
     * @depends testSeekWithInvalidOffset
     *
     * @param \Icicle\Stream\Sink $sink
     */
    public function testSeekWithInvalidWhence($sink)
    {

        $promise = new Coroutine($sink->seek(0, -1));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(InvalidArgumentError::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testSeekWithInvalidOffset
     *
     * @param \Icicle\Stream\Sink $sink
     */
    public function testSeekOnClosedSink($sink)
    {

        $sink->close();

        $promise = new Coroutine($sink->seek(0));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnseekableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testWriteThenSeekThenRead
     */
    public function testEnd()
    {
        $sink = $this->createSink();

        $promise = new Coroutine($sink->end(self::WRITE_STRING));

        Loop\run();

        $this->assertFalse($sink->isWritable());
        $this->assertFalse($promise->isPending());

        $promise = new Coroutine($sink->seek(0));

        Loop\run();

        $this->assertFalse($promise->isPending());
        $this->assertSame(0, $sink->tell());
        $this->assertTrue($sink->isReadable());

        $promise = new Coroutine($sink->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise->done($callback);

        Loop\run();

        $this->assertFalse($sink->isReadable());

        return $sink;
    }

    /**
     * @depends testEnd
     *
     * @param \Icicle\Stream\Sink $sink
     */
    public function testWriteToEnded($sink)
    {

        $promise = new Coroutine($sink->write(self::WRITE_STRING));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnwritableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }
}
