<?php

/*
 * This file is part of the pipe package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Tests\Stream\Pipe;

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
}
