<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream\Pipe;

use Icicle\Stream\{DuplexStream, Exception\UnwritableException, Resource};

class DuplexPipe implements DuplexStream, Resource
{
    /**
     * @var \Icicle\Stream\Pipe\ReadablePipe
     */
    private $readable;

    /**
     * @var \Icicle\Stream\Pipe\WritablePipe
     */
    private $writable;

    /**
     * @param resource $resource Stream socket resource.
     */
    public function __construct($resource)
    {
        $this->readable = new ReadablePipe($resource);
        $this->writable = new WritablePipe($resource);
    }

    /**
     * {@inheritdoc}
     */
    public function isOpen(): bool
    {
        return $this->readable->isOpen() || $this->writable->isOpen();
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->readable->close();
        $this->writable->close();
    }

    /**
     * {@inheritdoc}
     */
    public function getResource()
    {
        return $this->readable->getResource();
    }

    /**
     * {@inheritdoc}
     */
    public function read(int $length = 0, string $byte = null, float $timeout = 0): \Generator
    {
        return $this->readable->read($length, $byte, $timeout);
    }

    /**
     * @coroutine
     *
     * Returns a coroutine fulfilled when there is data available to read in the internal stream buffer. Note that
     * this method does not consider data that may be available in the internal buffer. This method should be used to
     * implement functionality that uses the stream socket resource directly.
     *
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if no data is received. Use null for no timeout.
     *
     * @return \Generator
     *
     * @resolve string Empty string.
     *
     * @throws \Icicle\Awaitable\Exception\TimeoutException If the operation times out.
     * @throws \Icicle\Stream\Exception\FailureException If the stream buffer is not empty.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream has been closed.
     */
    public function poll(float $timeout = 0): \Generator
    {
        return $this->readable->poll($timeout);
    }

    /**
     * {@inheritdoc}
     */
    public function isReadable(): bool
    {
        return $this->readable->isReadable();
    }

    /**
     * Shifts the given data back to the front of the stream and will be the first bytes returned from any pending or
     * subsequent read.
     *
     * @param string $data
     */
    public function unshift(string $data)
    {
        $this->readable->unshift($data);
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $data, float $timeout = 0): \Generator
    {
        return $this->writable->write($data, $timeout);
    }

    /**
     * {@inheritdoc}
     */
    public function end(string $data = '', float $timeout = 0): \Generator
    {
        if (!$this->writable->isWritable()) {
            throw new UnwritableException('The stream is no longer writable.');
        }

        try {
            return yield from $this->writable->end($data, $timeout);
        } finally {
            $this->readable->close();
        }
    }

    /**
     * @coroutine
     *
     * Returns a coroutine that is fulfilled when the stream is ready to receive data (output buffer is not full).
     *
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if the data cannot be written to the stream. Use null for no timeout.
     *
     * @return \Generator
     *
     * @resolve int Always resolves with 0.
     *
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream has been closed.
     */
    public function await(float $timeout = 0): \Generator
    {
        return $this->writable->await($timeout);
    }

    /**
     * {@inheritdoc}
     */
    public function isWritable(): bool
    {
        return $this->writable->isWritable();
    }

    /**
     * {@inheritdoc}
     *
     * @param float|int $timeout Timeout for poll and await if a read or write was pending.
     */
    public function rebind(float $timeout = 0)
    {
        $this->readable->rebind($timeout);
        $this->writable->rebind($timeout);
    }
}
