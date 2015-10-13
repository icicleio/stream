<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Stream;

use Exception;
use Icicle\Coroutine\Coroutine;
use Icicle\Loop;
use Icicle\Loop\SelectLoop;
use Icicle\Promise\Exception\TimeoutException;
use Icicle\Stream;
use Icicle\Stream\Exception\InvalidArgumentError;
use Icicle\Stream\Exception\UnwritableException;
use Icicle\Stream\MemoryStream;
use Icicle\Stream\WritableStreamInterface;

class FunctionsTest extends TestCase
{
    const WRITE_STRING = 'abcdefghijklmnopqrstuvwxyz';
    const CHUNK_SIZE = 8192;
    const TIMEOUT = 0.1;

    public function setUp()
    {
        Loop\loop(new SelectLoop());
    }
    
    /**
     * @param int $hwm
     *
     * @return \Icicle\Stream\MemoryStream[] Same stream instance for readable and writable.
     */
    public function createStreams($hwm = self::CHUNK_SIZE)
    {
        $stream = new MemoryStream($hwm);

        return [$stream, $stream];
    }

    public function testPipe()
    {
        list($readable, $writable) = $this->createStreams();

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnCallback(function () {
                static $count = 0;
                return 3 >= ++$count;
            }));

        $mock->expects($this->exactly(3))
            ->method('write')
            ->will($this->returnCallback(function ($data) {
                $this->assertSame(self::WRITE_STRING, $data);
                $generator = function () use ($data) {
                    yield strlen($data);
                };
                return $generator();
            }));

        $promise = new Coroutine(Stream\pipe($readable, $mock));
        new Coroutine($writable->write(self::WRITE_STRING));

        Loop\tick();

        $this->assertTrue($promise->isPending());
        new Coroutine($writable->write(self::WRITE_STRING));

        Loop\tick();

        $this->assertTrue($promise->isPending());
        new Coroutine($writable->write(self::WRITE_STRING));

        Loop\tick();

        $this->assertFalse($promise->isPending());
        $this->assertTrue($promise->isFulfilled());
        $this->assertSame(strlen(self::WRITE_STRING) * 3, $promise->wait());
    }

    /**
     * @depends testPipe
     */
    public function testPipeOnUnwritableStream()
    {
        list($readable) = $this->createStreams();

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(false));

        $promise = new Coroutine(Stream\pipe($readable, $mock));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf('Icicle\Stream\Exception\UnwritableException'));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testPipe
     */
    public function testPipeEndOnUnexpectedClose()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $stream = $this->prophesize(WritableStreamInterface::class);

        $stream->isWritable()->willReturn(true);

        $generator = function () {
            yield strlen(self::WRITE_STRING);
        };
        $stream->write(self::WRITE_STRING, 0)->willReturn($generator());

        $stream->end()->shouldBeCalled();

        $promise = new Coroutine(Stream\pipe($readable, $stream->reveal(), true));

        $promise->done($this->createCallback(0), $this->createCallback(1));

        Loop\tick();

        $this->assertTrue($promise->isPending());

        $readable->close();

        Loop\run();
    }

    /**
     * @depends testPipe
     */
    public function testPipeEndOnNormalClose()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($readable) {
                $readable->close();
                $generator = function () use ($data) {
                    yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->once())
            ->method('end');

        $promise = new Coroutine(Stream\pipe($readable, $mock, true));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(self::WRITE_STRING)));

        $promise->done($callback);

        Loop\tick();
    }

    /**
     * @depends testPipe
     */
    public function testPipeDoNotEndOnUnexpectedClose()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) {
                $this->assertSame(self::WRITE_STRING, $data);
                $generator = function () use ($data) {
                    yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine(Stream\pipe($readable, $mock, false));

        $promise->done($this->createCallback(0), $this->createCallback(1));

        Loop\tick();

        $this->assertTrue($promise->isPending());

        $readable->close();

        Loop\run();
    }

    /**
     * @depends testPipe
     */
    public function testPipeDoNotEndOnNormalClose()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($readable) {
                $readable->close();
                $generator = function () use ($data) {
                    yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine(Stream\pipe($readable, $mock, false));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(self::WRITE_STRING)));

        $promise->done($callback);

        Loop\tick();
    }

    /**
     * @depends testPipe
     */
    public function testPipeCancel()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $stream = $this->prophesize(WritableStreamInterface::class);

        $stream->isWritable()->willReturn(true);

        $generator = function () {
            yield strlen(self::WRITE_STRING);
        };
        $stream->write(self::WRITE_STRING, 0)->willReturn($generator());

        $stream->end()->shouldNotBeCalled();

        $promise = new Coroutine(Stream\pipe($readable, $stream->reveal()));

        $exception = new Exception();

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($exception));

        $promise->done($this->createCallback(0), $callback);

        Loop\tick();

        $this->assertTrue($promise->isPending());

        $promise->cancel($exception);

        Loop\run();
    }

    /**
     * @depends testPipe
     */
    public function testPipeWithLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $length = 8;

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($length) {
                $this->assertSame(substr(self::WRITE_STRING, 0, $length), $data);
                $generator = function () use ($data) {
                    yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine(Stream\pipe($readable, $mock, false, $length));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($length));

        $promise->done($callback);

        Loop\tick();

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->exactly(2))
            ->method('write')
            ->will($this->returnCallback(function ($data) {
                $generator = function () use ($data) {
                    yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine(Stream\pipe($readable, $mock, false, strlen(self::WRITE_STRING)));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(self::WRITE_STRING)));

        $promise->done($callback);

        Loop\tick();

        $this->assertTrue($promise->isPending());

        new Coroutine($writable->write(self::WRITE_STRING));

        Loop\tick();

        $this->assertFalse($promise->isPending());
    }

    /**
     * @depends testPipeWithLength
     */
    public function testPipeWithInvalidLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnCallback(function () {
                static $i = 0;
                return !$i++;
            }));

        $mock->expects($this->never())
            ->method('write');

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine(Stream\pipe($readable, $mock, false, -1));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(InvalidArgumentError::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\tick();
    }

    /**
     * @depends testPipe
     */
    public function testPipeTo()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $offset = 10;
        $char = substr(self::WRITE_STRING, $offset, 1);

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($offset) {
                $this->assertSame(substr(self::WRITE_STRING, 0, $offset + 1), $data);
                $generator = function () use ($data) {
                    yield strlen($data);
                };
                return $generator();
            }));

        $promise = new Coroutine(Stream\pipe($readable, $mock, true, 0, $char));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($offset + 1));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testPipeTo
     */
    public function testPipeToOnUnwritableStream()
    {
        list($readable, $writable) = $this->createStreams();

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(false));

        $promise = new Coroutine(Stream\pipe($readable, $mock, true, 0, '!'));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnwritableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testPipeTo
     */
    public function testPipeToMultibyteString()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $offset = 5;
        $length = 3;
        $string = substr(self::WRITE_STRING, $offset, $length);

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($offset) {
                $this->assertSame(substr(self::WRITE_STRING, 0, $offset + 1), $data);
                $generator = function () use ($data) {
                    yield strlen($data);
                };
                return $generator();
            }));

        $promise = new Coroutine(Stream\pipe($readable, $mock, true, 0, $string));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($offset + 1));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testPipeTo
     */
    public function testPipeToEndOnUnexpectedClose()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $stream = $this->prophesize(WritableStreamInterface::class);

        $stream->isWritable()->willReturn(true);

        $generator = function () {
            yield strlen(self::WRITE_STRING);
        };
        $stream->write(self::WRITE_STRING, 0)->willReturn($generator());

        $stream->end()->shouldBeCalled();

        $promise = new Coroutine(Stream\pipe($readable, $stream->reveal(), true, 0, '!'));

        $promise->done($this->createCallback(0), $this->createCallback(1));

        Loop\tick();

        $this->assertTrue($promise->isPending());

        $readable->close();

        Loop\run();
    }

    /**
     * @depends testPipeTo
     */
    public function testPipeToEndOnNormalClose()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($readable) {
                $readable->close();
                $generator = function () use ($data) {
                    yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->once())
            ->method('end');

        $promise = new Coroutine(Stream\pipe($readable, $mock, true, 0, '!'));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(self::WRITE_STRING)));

        $promise->done($callback);

        Loop\tick();
    }

    /**
     * @depends testPipeTo
     */
    public function testPipeToDoNotEndOnUnexpectedClose()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) {
                $this->assertSame(self::WRITE_STRING, $data);
                $generator = function () use ($data) {
                    yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine(Stream\pipe($readable, $mock, false, 0, '!'));

        $promise->done($this->createCallback(0), $this->createCallback(1));

        Loop\tick();

        $this->assertTrue($promise->isPending());

        $readable->close();

        Loop\run();
    }

    /**
     * @depends testPipeTo
     */
    public function testPipeToDoNotEndOnNormalClose()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($readable) {
                $readable->close();
                $generator = function () use ($data) {
                    yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine(Stream\pipe($readable, $mock, false, 0, '!'));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(self::WRITE_STRING)));

        $promise->done($callback);

        Loop\tick();
    }

    /**
     * @depends testPipeTo
     */
    public function testPipeToWithLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $length = 8;
        $offset = 10;
        $char = substr(self::WRITE_STRING, $offset, 1);

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($length) {
                $this->assertSame(substr(self::WRITE_STRING, 0, $length), $data);
                $generator = function () use ($data) {
                    yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine(Stream\pipe($readable, $mock, false, $length, $char));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($length));

        $promise->done($callback);

        Loop\tick();

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($offset, $length) {
                $this->assertSame(substr(self::WRITE_STRING, $length, $offset - $length + 1), $data);
                $generator = function () use ($data) {
                    yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine(Stream\pipe($readable, $mock, false, strlen(self::WRITE_STRING), $char));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($offset - $length + 1));

        $promise->done($callback);

        Loop\tick();

        $this->assertFalse($promise->isPending());
    }

    /**
     * @depends testPipeToWithLength
     */
    public function testPipeToWithInvalidLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnCallback(function () {
                static $i = 0;
                return !$i++;
            }));

        $mock->expects($this->never())
            ->method('write');

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine(Stream\pipe($readable, $mock, false, -1, '!'));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(InvalidArgumentError::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\tick();
    }

    /**
     * @depends testPipe
     */
    public function testPipeTimeout()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) {
                $this->assertSame(self::WRITE_STRING, $data);
                $generator = function () use ($data) {
                    yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine(Stream\pipe($readable, $mock, false, 0, null, self::TIMEOUT));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $this->assertTrue($readable->isOpen());
    }

    /**
     * @depends testPipeTimeout
     */
    public function testPipeWithLengthTimeout()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $length = 8;

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($length) {
                $generator = function () use ($data) {
                    yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine(
            Stream\pipe($readable, $mock, false, strlen(self::WRITE_STRING) + 1, null, self::TIMEOUT)
        );

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $this->assertTrue($readable->isOpen());
    }

    /**
     * @depends testPipeTo
     */
    public function testPipeToTimeout()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) {
                $this->assertSame(self::WRITE_STRING, $data);
                $generator = function () use ($data) {
                    yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine(Stream\pipe($readable, $mock, false, 0, '!', self::TIMEOUT));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $this->assertTrue($readable->isOpen());
    }

    /**
     * @depends testPipeToTimeout
     */
    public function testPipeToWithLengthTimeout()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $length = 8;

        $mock = $this->getMock(WritableStreamInterface::class);

        $mock->method('isWritable')
            ->will($this->returnValue(true));

        $mock->expects($this->once())
            ->method('write')
            ->will($this->returnCallback(function ($data) use ($length) {
                $generator = function () use ($data) {
                    yield strlen($data);
                };
                return $generator();
            }));

        $mock->expects($this->never())
            ->method('end');

        $promise = new Coroutine(
            Stream\pipe($readable, $mock, false, strlen(self::WRITE_STRING) + 1, '!', self::TIMEOUT)
        );

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $this->assertTrue($readable->isOpen());
    }

    public function testReadTo()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $length = strlen(self::WRITE_STRING) * 2;

        $promise = new Coroutine(Stream\readTo($readable, $length));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING . self::WRITE_STRING));

        $promise->done($callback);

        Loop\run();

        $this->assertTrue($promise->isPending());

        new Coroutine($writable->write(self::WRITE_STRING));

        Loop\run();

        $this->assertFalse($promise->isPending());
        $this->assertTrue($promise->isFulfilled());
    }

    /**
     * @depends testReadTo
     */
    public function testReadToInvalidLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $length = strlen(self::WRITE_STRING) * 2;

        $promise = new Coroutine(Stream\readTo($readable, -1));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(InvalidArgumentError::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadToNeedle()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $needle = '@#$';

        $promise = new Coroutine(Stream\readTo($readable, 0, $needle));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING . '$!@#$'));

        $promise->done($callback);

        Loop\run();

        $this->assertTrue($promise->isPending());

        new Coroutine($writable->write('$!@#$' . self::WRITE_STRING));

        Loop\run();

        $this->assertFalse($promise->isPending());
        $this->assertTrue($promise->isFulfilled());
    }

    public function testReadAll()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $promise = new Coroutine(Stream\readAll($readable));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING . self::WRITE_STRING));

        $promise->done($callback);

        Loop\run();

        $this->assertTrue($promise->isPending());

        new Coroutine($writable->end(self::WRITE_STRING));

        Loop\run();

        $this->assertFalse($promise->isPending());
        $this->assertTrue($promise->isFulfilled());
    }

    /**
     * @depends testReadAll
     */
    public function testReadAllWithMaxLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $length = strlen(self::WRITE_STRING) + 1;

        $promise = new Coroutine(Stream\readAll($readable, $length));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING . substr(self::WRITE_STRING, 0, 1)));

        $promise->done($callback);

        Loop\run();

        $this->assertTrue($promise->isPending());

        new Coroutine($writable->write(self::WRITE_STRING));

        Loop\run();

        $this->assertFalse($promise->isPending());
        $this->assertTrue($promise->isFulfilled());
    }

    /**
     * @depends testReadAll
     */
    public function testReadAllInvalidMaxLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $promise = new Coroutine(Stream\readAll($readable, -1));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(InvalidArgumentError::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }
}