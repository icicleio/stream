<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream;

interface WritableStream extends Stream
{
    /**
     * @coroutine
     *
     * Queues data to be sent on the stream. The promise returned is fulfilled once the data has successfully been
     * written to the stream.
     *
     * @param string $data
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     and the stream is closed if the data cannot be written to the stream. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve int Number of bytes written to the stream.
     *
     * @throws \Icicle\Awaitable\Exception\TimeoutException If the operation times out.
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     */
    public function write($data, $timeout = 0);

    /**
     * @coroutine
     *
     * Queues the data to be sent on the stream and marks the stream as unwritable.
     *
     * @param string $data
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     and the stream is closed if the data cannot be written to the stream. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve int Number of bytes written to the stream.
     *
     * @throws \Icicle\Awaitable\Exception\TimeoutException If the operation times out.
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     */
    public function end($data = '', $timeout = 0);
    
    /**
     * Determines if the stream is still writable.
     *
     * @return bool
     */
    public function isWritable();
}
