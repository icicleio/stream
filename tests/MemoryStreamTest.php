<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Stream;

use Exception;
use Icicle\Awaitable\Exception\TimeoutException;
use Icicle\Coroutine\Coroutine;
use Icicle\Exception\InvalidArgumentError;
use Icicle\Loop;
use Icicle\Loop\SelectLoop;
use Icicle\Stream\Exception\ClosedException;
use Icicle\Stream\Exception\UnreadableException;
use Icicle\Stream\Exception\UnwritableException;
use Icicle\Stream\MemoryStream;

class MemoryStreamTest extends TestCase
{
    const WRITE_STRING = 'abcdefghijklmnopqrstuvwxyz';
    const CHUNK_SIZE = 8192;
    const TIMEOUT = 0.1;
    const HWM = 16384;

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

        Loop\tick(false);

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
        Loop\tick(false);

        $promise2 = new Coroutine($readable->read());
        Loop\tick(false);

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

        $promise = new Coroutine($writable->write(self::WRITE_STRING));

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
        Loop\tick(false);

        $promise->cancel($exception);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($exception));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $promise = new Coroutine($readable->read());
        Loop\tick(false);

        $this->assertTrue($promise->isPending());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise->done($callback);

        $promise = new Coroutine($writable->write(self::WRITE_STRING));

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
        Loop\tick(false);

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
        Loop\tick(false);

        $promise2 = new Coroutine($readable->read(0, "\0"));
        Loop\tick(false);

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
        Loop\tick();

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(substr(self::WRITE_STRING, 0, $length)));

        $promise->done($callback);

        $promise = new Coroutine($writable->write(self::WRITE_STRING));

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
        Loop\tick(false);

        $promise->cancel($exception);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($exception));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $promise = new Coroutine($readable->read(0, $char));
        Loop\tick(false);

        $this->assertTrue($promise->isPending());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($char));

        $promise->done($callback);

        $promise = new Coroutine($writable->write(self::WRITE_STRING));

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
        Loop\tick();

        $promise->cancel($exception);

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo($exception));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();

        $promise = new Coroutine($readable->read());
        Loop\tick();

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

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(self::WRITE_STRING)));

        $promise->done($callback);

        $this->assertTrue($writable->isOpen());

        $promise = new Coroutine($readable->read());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise->done($callback);

        Loop\run();

        $this->assertFalse($writable->isWritable());
    }

    /**
     * @depends testWrite
     */
    public function testWriteTimeout()
    {
        list($readable, $writable) = $this->createStreams();

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(self::WRITE_STRING, self::TIMEOUT));
            Loop\tick();
        } while (!$promise->isPending());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->isInstanceOf(TimeoutException::class));

        $promise->done($this->createCallback(0), $callback);

        Loop\run();
    }


    public function testEndWithPendingRead()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read());
        Loop\tick();

        $this->assertTrue($promise->isPending());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(self::WRITE_STRING));

        $promise->done($callback);

        $promise = new Coroutine($writable->end(self::WRITE_STRING));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(self::WRITE_STRING)));

        $promise->done($callback);

        Loop\run();


        $this->assertFalse($readable->isReadable());
    }

    /**
     * @depends testEndWithPendingRead
     */
    public function testEndWithPendingReadWritingNoData()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read());
        Loop\tick();

        $this->assertTrue($promise->isPending());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(''));

        $promise->done($callback);

        $promise = new Coroutine($writable->end());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(0));

        $promise->done($callback);

        Loop\run();

        $this->assertFalse($writable->isWritable());
        $this->assertFalse($readable->isReadable());
    }

    /**
     * @depends testWrite
     */
    public function testCloseAfterPendingWrite()
    {
        list($readable, $writable) = $this->createStreams(self::HWM);

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
        list($readable, $writable) = $this->createStreams(self::HWM);

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
            (new Coroutine($readable->read()))->done(); // Pull more data out of the buffer.
            Loop\tick();
        }
    }

    /**
     * @depends testEnd
     * @depends testWriteAfterPendingWrite
     */
    public function testEndAfterPendingWrite()
    {
        list($readable, $writable) = $this->createStreams(self::HWM);

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(self::WRITE_STRING));
            Loop\tick();
        } while (!$promise->isPending());

        $promise = new Coroutine($writable->end(self::WRITE_STRING));

        $this->assertFalse($writable->isWritable());

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(strlen(self::WRITE_STRING)));

        $promise->done($callback);

        $this->assertTrue($promise->isPending());

        while ($promise->isPending()) {
            (new Coroutine($readable->read()))->done(); // Pull more data out of the buffer.
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
        list($readable, $writable) = $this->createStreams(self::HWM);

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(self::WRITE_STRING));
            Loop\tick();
        } while (!$promise->isPending());

        $promise = new Coroutine($writable->write(''));

        $callback = $this->createCallback(1);
        $callback->method('__invoke')
            ->with($this->identicalTo(0));

        $promise->done($callback);

        $this->assertTrue($promise->isPending());

        while ($promise->isPending()) {
            (new Coroutine($readable->read()))->done(); // Pull more data out of the buffer.
            Loop\tick();
        }
    }

    /**
     * @depends testWrite
     */
    public function testWriteAfterPendingWriteAfterEof()
    {
        list($readable, $writable) = $this->createStreams(self::HWM);

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($writable->write(self::WRITE_STRING));
            Loop\tick();
        } while (!$promise->isPending());

        // Extra write to ensure queue is not empty when write callback is called.
        $promise = new Coroutine($writable->write(self::WRITE_STRING));

        $readable->close(); // Close readable stream.

        $promise->done($this->createCallback(0), $this->createCallback(1));

        Loop\run();
    }
}