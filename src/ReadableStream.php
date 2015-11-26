<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream;

interface ReadableStream extends Stream
{
    /**
     * @coroutine
     *
     * @param int $length Max number of bytes to read. Fewer bytes may be returned. Use 0 to read as much data
     *     as possible.
     * @param string|null $byte Reading will stop once the given byte occurs in the stream. Note that reading may
     *     stop before the byte is found in the stream. The search byte will be included in the resolving string.
     *     Use null to effectively ignore this parameter and read any bytes.
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if no data is received. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve string Data read from the stream.
     *
     * @throws \Icicle\Awaitable\Exception\TimeoutException If the operation times out.
     * @throws \Icicle\Exception\InvalidArgumentError If the length is invalid.
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     */
    public function read($length = 0, $byte = null, $timeout = 0);

    /**
     * Determines if the stream is still readable. A stream being readable does not mean there is data immediately
     * available to read. Use read() or poll() to wait for data.
     *
     * @return bool
     */
    public function isReadable();
}
