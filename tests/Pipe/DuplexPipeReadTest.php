<?php

/*
 * This file is part of the pipe package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Stream\Pipe;

use Icicle\Coroutine\Coroutine;
use Icicle\Loop;
use Icicle\Loop\{Loop as LoopInterface, Watcher\Io};
use Icicle\Stream\Pipe\DuplexPipe;

class DuplexPipeReadTest extends ReadablePipeTest
{
    public function createStreams()
    {
        list($read, $write) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $readable = new DuplexPipe($read);
        $writable = new DuplexPipe($write);

        return [$readable, $writable];
    }

    public function testRebind()
    {
        list($readable, $writable) = $this->createStreams();

        $promise = new Coroutine($readable->read());

        do { // Write until a pending promise is returned.
            $promise = new Coroutine($readable->write(self::WRITE_STRING));
            Loop\tick(false);
        } while (!$promise->isPending());

        $timeout = 1;

        $io = $this->getMockBuilder(Io::class)
            ->disableOriginalConstructor()
            ->getMock();

        $io->expects($this->exactly(2))
            ->method('listen')
            ->with($timeout);

        $loop = $this->getMock(LoopInterface::class);
        $loop->expects($this->once())
            ->method('poll')
            ->will($this->returnValue($io));
        $loop->expects($this->once())
            ->method('await')
            ->will($this->returnValue($io));

        Loop\loop($loop);

        $readable->rebind($timeout);
    }
}
