<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Stream\Pipe;

use Exception;
use Icicle\Coroutine\Coroutine;
use Icicle\Loop;
use Icicle\Promise\Exception\TimeoutException;
use Icicle\Stream\Exception\BusyError;
use Icicle\Stream\Exception\ClosedException;
use Icicle\Stream\Exception\FailureException;
use Icicle\Stream\Exception\UnreadableException;
use Icicle\Stream\Exception\UnwritableException;
use Icicle\Stream\Pipe\DuplexPipe;
use Icicle\Tests\Stream\StreamResourceTest;

class DuplexPipeTest extends StreamResourceTest
{
    const CHUNK_SIZE = 8192;
    const TIMEOUT = 0.1;
    const WRITE_STRING = 'abcdefghijklmnopqrstuvwxyz';

    /**
     * @return \Icicle\Stream\ReadableStreamInterface[]|\Icicle\Stream\WritableStreamInterface[]
     */
    public function createStreams()
    {
        list($read, $write) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $readable = new DuplexPipe($read);
        $writable = new DuplexPipe($write);

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
            ->with($this->isInstanceOf(ClosedException::class));

        $promise->done($this->createCallback(0), $callback);

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
            ->with($this->isInstanceof(BusyError::class));

        $promise2->done($this->createCallback(0), $callback);

        new Coroutine($writable->write(self::WRITE_STRING));

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
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise->done($callback);

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
            ->with($this->isInstanceOf(ClosedException::class));

        $promise->done($this->createCallback(0), $callback);

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
            ->with($this->isInstanceof(BusyError::class));

        $promise2->done($this->createCallback(0), $callback);

        new Coroutine($writable->write(self::WRITE_STRING));

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
            ->with($this->identicalTo(substr(self::WRITE_STRING, 0, $offset + 1)));

        $promise->done($callback);

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
            ->with($this->isInstanceOf(ClosedException::class));

        $promise->done($this->createCallback(0), $callback);

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
            ->with($this->isInstanceOf(BusyError::class));

        $promise2->done($this->createCallback(0), $callback);

        $promise1->cancel();

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

    public function testWrite()
    {
        list($readable, $writable) = $this->createStreams();

        $string = "{'New String\0To Write'}\r\n";

        $promise = new Coroutine($writable->write($string));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen($string)));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($string));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testWrite
     */
    public function testWriteAfterClose()
    {
        list($readable, $writable) = $this->createStreams();

        $writable->close();

        $this->assertFalse($writable->isWritable());

        $promise = new Coroutine($writable->write(self::WRITE_STRING));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnwritableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testWrite
     */
    public function testWriteEmptyString()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($writable->write(''));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(0));

        $promise->done($callback);

        Loop\run();

        $promise = new Coroutine($writable->write('0'));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(1));

        $promise->done($callback);

        $promise = new Coroutine($readable->read(1));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo('0'));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testWrite
     */
    public function testEnd()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($writable->end(self::WRITE_STRING));

        $this->assertFalse($writable->isWritable());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(self::WRITE_STRING)));

        $promise->done($callback);

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise->done($callback);

        Loop\run();

        $this->assertFalse($writable->isOpen());
    }

    /**
     * @depends testWrite
     */
    public function testWriteTimeout()
    {
        list($readable, $writable) = $this->createStreams();

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(self::WRITE_STRING, self::TIMEOUT));
            Loop\tick(false);
        } while (!$promise->isPending());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testWrite
     */
    public function testCloseAfterPendingWrite()
    {
        list($readable, $writable) = $this->createStreams();

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(self::WRITE_STRING));
            Loop\tick(false);
        } while (!$promise->isPending());

        $writable->close();

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(ClosedException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testWrite
     */
    public function testWriteAfterPendingWrite()
    {
        list($readable, $writable) = $this->createStreams();

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(self::WRITE_STRING));
            Loop\tick(false);
        } while (!$promise->isPending());

        $buffer = '';

        for ($i = 0; $i < self::CHUNK_SIZE + 1; ++$i) {
            $buffer .= '1';
        }

        $promise = new Coroutine($writable->write($buffer));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen($buffer)));

        $promise->done($callback);

        $this->assertTrue($promise->isPending());

        while ($promise->isPending()) {
            new Coroutine($readable->read()); // Pull more data out of the buffer.
            Loop\tick();
        }
    }

    /**
     * @depends testEnd
     * @depends testWriteAfterPendingWrite
     */
    public function testEndAfterPendingWrite()
    {
        list($readable, $writable) = $this->createStreams();

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(self::WRITE_STRING));
            Loop\tick(false);
        } while (!$promise->isPending());

        $promise = new Coroutine($writable->end(self::WRITE_STRING));

        $this->assertFalse($writable->isWritable());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(self::WRITE_STRING)));

        $promise->done($callback);

        $this->assertTrue($promise->isPending());

        while ($promise->isPending()) {
            new Coroutine($readable->read(self::CHUNK_SIZE)); // Pull more data out of the buffer.
            Loop\tick();
        }

        $this->assertFalse($writable->isWritable());
    }

    /**
     * @depends testWriteEmptyString
     * @depends testWriteAfterPendingWrite
     */
    public function testWriteEmptyStringAfterPendingWrite()
    {
        list($readable, $writable) = $this->createStreams();

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(self::WRITE_STRING));
            Loop\tick(false);
        } while (!$promise->isPending());

        $promise = new Coroutine($writable->write(''));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(0));

        $promise->done($callback);

        $this->assertTrue($promise->isPending());

        while ($promise->isPending()) {
            new Coroutine($readable->read()); // Pull more data out of the buffer.
            Loop\tick();
        }
    }

    /**
     * @depends testWrite
     */
    public function testWriteAfterPendingWriteAfterEof()
    {
        list($readable, $writable) = $this->createStreams();

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(self::WRITE_STRING));
            Loop\tick(false);
        } while (!$promise->isPending());

        // Extra write to ensure queue is not empty when write callback is called.
        $promise = new Coroutine($writable->write(self::WRITE_STRING));

        $readable->close(); // Close readable stream.

        $promise->done($this->createCallback(0), $this->createCallback(1));

        Loop\run();
    }

    /**
     * @depends testWrite
     */
    public function testWriteFailure()
    {
        list($readable, $writable) = $this->createStreams();

        // Use fclose() manually since $writable->close() will prevent behavior to be tested.
        fclose($writable->getResource());

        $promise = new Coroutine($writable->write(self::WRITE_STRING));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(FailureException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    public function testAwait()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($writable->await());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(0));

        $promise->done($callback);

        Loop\run();
    }

    /**
     * @depends testAwait
     */
    public function testAwaitAfterClose()
    {
        list($readable, $writable) = $this->createStreams();

        $writable->close();

        $promise = new Coroutine($writable->await());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(UnwritableException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }

    /**
     * @depends testAwait
     */
    public function testAwaitThenClose()
    {
        list($readable, $writable) = $this->createStreams();

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(self::WRITE_STRING));
            Loop\tick(false);
        } while (!$promise->isPending());

        $promise = new Coroutine($writable->await());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(ClosedException::class));

        $promise->done($this->createCallback(0), $callback);

        $writable->close();

        Loop\run();
    }
}
