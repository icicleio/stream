<?php

/*
 * This file is part of the pipe package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Stream\Pipe;

use Exception;
use Icicle\Awaitable\Exception\TimeoutException;
use Icicle\Coroutine\Coroutine;
use Icicle\Exception\InvalidArgumentError;
use Icicle\Loop;
use Icicle\Loop\Loop as LoopInterface;
use Icicle\Loop\Watcher\Io;
use Icicle\Stream\Exception\FailureException;
use Icicle\Stream\Exception\UnreadableException;
use Icicle\Stream\Pipe\ReadablePipe;
use Icicle\Stream\Pipe\WritablePipe;

class ReadablePipeTest extends PipeTest
{
    /**
     * @return \Icicle\Stream\ReadableStream[]|\Icicle\Stream\WritableStream[]
     */
    public function createStreams()
    {
        list($read, $write) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $readable = new ReadablePipe($read);

        $writable = $this->getMockBuilder(WritablePipe::class)
            ->disableOriginalConstructor()
            ->getMock();

        stream_set_blocking($write, 0);

        $writable->method('getResource')
            ->will($this->returnValue($write));

        $writable->method('isWritable')
            ->will($this->returnValue(true));

        $writable->method('write')
            ->will($this->returnCallback(function ($data) use ($write) {
                $length = strlen($data);
                if ($length) {
                    fwrite($write, $data);
                }
                yield $length;
            }));

        $writable->method('close')
            ->will($this->returnCallback(function () use ($write) {
                fclose($write);
            }));

        return [$readable, $writable];
    }

    public function testRead()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testReadAfterClose()
    {
        list($readable, $writable) = $this->createStreams();

        $readable->close();

        $this->assertFalse($readable->isReadable());

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnreadableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testReadThenClose()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(''));

        $promise->done($callback);

        $readable->close();

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testSimultaneousRead()
    {
        list($readable, $writable) = $this->createStreams();

        $promise1 = new Coroutine($readable->read());

        $promise2 = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise1->done($callback);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise2->done($callback);

        Loop\timer(self::TIMEOUT, function () use ($writable) {
            new Coroutine($writable->write(self::WRITE_STRING));
        });

        new Coroutine($writable->write(self::WRITE_STRING));

        Loop\run();
    }

    /**
     * @depends testSimultaneousRead
     */
    public function testSimultaneousReadThenClose()
    {
        list($readable, $writable) = $this->createStreams();

        $promise1 = new Coroutine($readable->read());

        $promise2 = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(''));

        $promise1->done($callback);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnreadableException::class));

        $promise2->done($this->createCallback(0), $callback);

        $readable->close();

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testReadWithLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $length = floor(strlen(self::WRITE_STRING) / 2);

        $promise = new Coroutine($readable->read($length));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(self::WRITE_STRING, 0, $length)));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($readable->read($length));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(self::WRITE_STRING, $length, $length)));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testReadWithInvalidLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $promise = new Coroutine($readable->read(-1));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(InvalidArgumentError::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testCancelRead()
    {
        $exception = new Exception();

        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read());

        $promise->cancel($exception);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($exception));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $promise = new Coroutine($readable->read());

        $this->assertTrue($promise->isPending());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise->done($callback);

        new Coroutine($writable->write(self::WRITE_STRING));

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testReadOnEmptyStream()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read()); // Nothing to read on this stream.

        Loop\tick();

        $this->assertTrue($promise->isPending());
    }

    /**
     * @depends testReadOnEmptyStream
     */
    public function testDrainThenRead()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($readable->read());

        Loop\tick();

        $this->assertTrue($promise->isPending());

        $string = "This is a string to write.\n";

        $promise2 = new Coroutine($writable->write($string));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen($string)));

        $promise2->done($callback);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($string));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testReadTo()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $offset = 5;
        $char = substr(self::WRITE_STRING, $offset, 1);

        $promise = new Coroutine($readable->read(0, $char));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(self::WRITE_STRING, 0, $offset + 1)));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadToMultibyteString()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $offset = 5;
        $length = 3;
        $string = substr(self::WRITE_STRING, $offset, $length);

        $promise = new Coroutine($readable->read(0, $string));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(self::WRITE_STRING, 0, $offset + 1)));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadToNoMatchInStream()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $char = '~';

        $promise = new Coroutine($readable->read(0, $char));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($readable->read(0, $char));

        Loop\tick();

        $this->assertTrue($promise->isPending());
    }

    /**
     * @depends testReadTo
     */
    public function testReadToEmptyString()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $promise = new Coroutine($readable->read(0, ''));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadToAfterClose()
    {
        list($readable, $writable) = $this->createStreams();

        $readable->close();

        $this->assertFalse($readable->isReadable());

        $promise = new Coroutine($readable->read(0, "\0"));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnreadableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadToThenClose()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read(0, "\0"));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(''));

        $promise->done($callback);

        $readable->close();

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testSimultaneousReadTo()
    {
        list($readable, $writable) = $this->createStreams();

        $promise1 = new Coroutine($readable->read(0, "\0"));

        $promise2 = new Coroutine($readable->read(0, "\0"));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise1->done($callback);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo("123\0"));

        $promise2->done($callback);

        Loop\timer(self::TIMEOUT, function () use ($writable) {
            new Coroutine($writable->write("123\0" . "4567890"));
        });

        new Coroutine($writable->write(self::WRITE_STRING));

        Loop\run();
    }

    /**
     * @depends testSimultaneousReadTo
     */
    public function testSimultaneousReadToThenClose()
    {
        list($readable, $writable) = $this->createStreams();

        $promise1 = new Coroutine($readable->read(0, "\0"));

        $promise2 = new Coroutine($readable->read(0, "\0"));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(''));

        $promise1->done($callback);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnreadableException::class));

        $promise2->done($this->createCallback(0), $callback);

        $readable->close();

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadToWithLength()
    {
        list($readable, $writable) = $this->createStreams();

        $offset = 10;
        $length = 5;
        $char = substr(self::WRITE_STRING, $offset, 1);

        $promise = new Coroutine($readable->read($length, $char));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(self::WRITE_STRING, 0, $length)));

        $promise->done($callback);

        new Coroutine($writable->write(self::WRITE_STRING));

        Loop\run();

        $promise = new Coroutine($readable->read(0, $char));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(self::WRITE_STRING, $length, $offset - $length + 1)));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadToWithInvalidLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $offset = 5;
        $char = substr(self::WRITE_STRING, $offset, 1);

        $promise = new Coroutine($readable->read(-1, $char));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(InvalidArgumentError::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testCancelReadTo()
    {
        $exception = new Exception();

        list($readable, $writable) = $this->createStreams();

        $char = substr(self::WRITE_STRING, 0, 1);

        $promise = new Coroutine($readable->read(0, $char));

        $promise->cancel($exception);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($exception));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $promise = new Coroutine($readable->read(0, $char));

        $this->assertTrue($promise->isPending());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($char));

        $promise->done($callback);

        new Coroutine($writable->write(self::WRITE_STRING));

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadToOnEmptyStream()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read(0, "\n")); // Nothing to read on this stream.

        Loop\tick();

        $this->assertTrue($promise->isPending());
    }

    /**
     * @depends testReadToOnEmptyStream
     */
    public function testDrainThenReadTo()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $char = "\n";

        $promise = new Coroutine($readable->read());

        Loop\run();

        $promise = new Coroutine($readable->read(0, $char));

        Loop\tick();

        $this->assertTrue($promise->isPending());

        $string1 = "This is a string to write.\n";
        $string2 = "This part should not be read.\n";

        new Coroutine($writable->write($string1 . $string2));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($string1));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadAfterReadTo()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $offset = 5;
        $char = substr(self::WRITE_STRING, $offset, 1);

        $promise = new Coroutine($readable->read(0, $char));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(self::WRITE_STRING, 0, $offset + 1)));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(self::WRITE_STRING, $offset + 1)));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadAfterCancelledReadTo()
    {
        $exception = new Exception();

        list($readable, $writable) = $this->createStreams();

        $offset = 5;
        $char = substr(self::WRITE_STRING, $offset, 1);

        $promise = new Coroutine($readable->read(0, $char));

        $promise->cancel($exception);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($exception));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise->done($callback);

        new Coroutine($writable->write(self::WRITE_STRING));

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testReadWithTimeout()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read(0, null, self::TIMEOUT));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testReadTo
     */
    public function testReadToWithTimeout()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read(0, "\0", self::TIMEOUT));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testReadAfterEof()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        fclose($writable->getResource()); // Close other end of pipe.

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise->done($callback);

        Loop\run(); // Drain readable buffer.

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(''));

        $promise->done($callback);

        Loop\run(); // Should get an empty string.

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnreadableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run(); // Should reject with UnreadableException.
    }

    /**
     * @depends testRead
     */
    public function testPendingReadThenEof()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read());

        fclose($writable->getResource()); // Close other end of pipe.

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(''));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnreadableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run(); // Should reject with UnreadableException.
    }

    /**
     * @depends testReadTo
     */
    public function testReadToAfterEof()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        fclose($writable->getResource()); // Close other end of pipe.

        $promise = new Coroutine($readable->read(0, "\0"));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise->done($callback);

        Loop\run(); // Drain readable buffer.

        $promise = new Coroutine($readable->read(0, "\0"));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(''));

        $promise->done($callback);

        Loop\run(); // Should get an empty string.

        $promise = new Coroutine($readable->read(0, "\0"));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnreadableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run(); // Should reject with UnreadableException.
    }

    /**
     * @depends testReadTo
     */
    public function testReadToThenReadWithLength()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $promise = new Coroutine($readable->read(0, substr(self::WRITE_STRING, 6, 1)));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(self::WRITE_STRING, 0, 7)));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($readable->read(10));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(self::WRITE_STRING, 7, 10)));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testUnshift()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $data = '1234567890';

        $readable->unshift($data);

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($data . self::WRITE_STRING));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testUnshift
     */
    public function testUnshiftWithPendingRead()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read());

        $data = '1234567890';

        $readable->unshift($data);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($data));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testRead
     */
    public function testPoll()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $promise = new Coroutine($readable->poll());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(''));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($readable->read()); // Empty the readable stream and ignore data.

        Loop\run();

        $promise = new Coroutine($readable->poll());

        $promise->done($this->createCallback(0));

        Loop\tick();
    }

    /**
     * @depends testPoll
     */
    public function testPollAfterClose()
    {
        list($readable, $writable) = $this->createStreams();

        $readable->close();

        $promise = new Coroutine($readable->poll());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnreadableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testPoll
     */
    public function testPollThenClose()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->poll());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(''));

        $promise->done($callback);

        $readable->close();

        Loop\run();
    }

    /**
     * @depends testPoll
     */
    public function testPollAfterRead()
    {
        list($readable, $writable) = $this->createStreams();

        $promise1 = new Coroutine($readable->read());

        $promise2 = new Coroutine($readable->poll());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(''));

        $promise2->done($callback);

        Loop\timer(self::TIMEOUT, function () use ($writable) {
            new Coroutine($writable->write(self::WRITE_STRING));
        });

        new Coroutine($writable->write(self::WRITE_STRING));

        Loop\run();
    }

    /**
     * @depends testPoll
     */
    public function testPollWithNonEmptyBuffer()
    {
        list($readable, $writable) = $this->createStreams();

        new Coroutine($writable->write(self::WRITE_STRING));

        $promise = new Coroutine($readable->read(0, 'b'));

        Loop\run();

        $promise = new Coroutine($readable->poll());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(FailureException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testPoll
     */
    public function testCancelPoll()
    {
        $exception = new Exception();

        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->poll());

        $promise->cancel($exception);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($exception));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $promise = new Coroutine($readable->poll());

        $this->assertTrue($promise->isPending());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(''));

        $promise->done($callback);

        new Coroutine($writable->write(self::WRITE_STRING));

        Loop\run();
    }

    public function testRebind()
    {
        list($readable, $writable) = $this->createStreams();

        $loop = $this->getMock(LoopInterface::class);

        $loop->expects($this->once())
            ->method('poll');

        Loop\loop($loop);

        $readable->rebind();
    }

    /**
     * @depends testRebind
     */
    public function testRebindAfterRead()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read());

        $timeout = 1;

        $poll = $this->getMockBuilder(Io::class)
            ->disableOriginalConstructor()
            ->getMock();

        $poll->expects($this->once())
            ->method('listen')
            ->with($timeout);

        $loop = $this->getMock(LoopInterface::class);
        $loop->expects($this->once())
            ->method('poll')
            ->will($this->returnValue($poll));

        Loop\loop($loop);

        $readable->rebind($timeout);
    }
}
