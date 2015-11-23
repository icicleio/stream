<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream\Pipe;

use Icicle\Loop;
use Icicle\Loop\Events\SocketEventInterface;
use Icicle\Promise\{Deferred, Exception\TimeoutException};
use Icicle\Stream\Exception\{BusyError, ClosedException, InvalidArgumentError, FailureException, UnreadableException};
use Icicle\Stream\{ReadableStreamInterface, StreamResource};
use Throwable;

class ReadablePipe extends StreamResource implements ReadableStreamInterface
{
    /**
     * @var \Icicle\Promise\Deferred|null
     */
    private $deferred;

    /**
     * @var \Icicle\Loop\Events\SocketEventInterface
     */
    private $poll;

    /**
     * @var string
     */
    private $buffer = '';

    /**
     * @param resource $resource Stream resource.
     */
    public function __construct($resource)
    {
        parent::__construct($resource);

        stream_set_read_buffer($resource, 0);
        stream_set_chunk_size($resource, self::CHUNK_SIZE);

        $this->poll = $this->createPoll();
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->free();
    }

    /**
     * Frees all resources used by the writable stream.
     *
     * @param \Throwable|null $exception
     */
    private function free(Throwable $exception = null)
    {
        $this->poll->free();

        if (null !== $this->deferred) {
            $this->deferred->getPromise()->cancel(
                $exception ?: new ClosedException('The stream was unexpectedly closed.')
            );
        }

        parent::close();
    }

    /**
     * {@inheritdoc}
     */
    public function read(int $length = 0, string $byte = null, float $timeout = 0): \Generator
    {
        if (null !== $this->deferred) {
            throw new BusyError('Already waiting on stream.');
        }

        if (!$this->isReadable()) {
            throw new UnreadableException('The stream is no longer readable.');
        }

        if (0 > $length) {
            throw new InvalidArgumentError('The length must be a non-negative integer.');
        } elseif (0 === $length) {
            $length = self::CHUNK_SIZE;
        }

        $byte = strlen($byte) ? $byte[0] : null;

        $resource = $this->getResource();

        while ('' === ($data = $this->fetch($resource, $length, $byte))) {
            if ($this->eof($resource)) { // Close only if no data was read and at EOF.
                $this->close();
                return $data; // Resolve with empty string on EOF.
            }

            $this->poll->listen($timeout);

            $this->deferred = new Deferred(function () {
                $this->poll->cancel();
            });

            try {
                yield $this->deferred->getPromise();
            } finally {
                $this->deferred = null;
            }
        }

        return $data;
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
     * @return \Icicle\Promise\PromiseInterface
     *
     * @resolve string Empty string.
     *
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\FailureException If the stream buffer is not empty.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream has been closed.
     */
    public function poll(float $timeout = 0): \Generator
    {
        if (null !== $this->deferred) {
            throw new BusyError('Already waiting on stream.');
        }

        if (!$this->isReadable()) {
            throw new UnreadableException('The stream is no longer readable.');
        }

        if ('' !== $this->buffer) {
            throw new FailureException('Stream buffer is not empty. Perform another read before polling.');
        }

        $this->poll->listen($timeout);

        $this->deferred = new Deferred(function () {
            $this->poll->cancel();
        });

        try {
            return yield $this->deferred->getPromise();
        } finally {
            $this->deferred = null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isReadable(): bool
    {
        return $this->isOpen();
    }

    /**
     * {@inheritdoc}
     *
     * @param float|int $timeout Timeout for poll if a read was pending.
     */
    public function rebind($timeout = 0)
    {
        $pending = $this->poll->isPending();
        $this->poll->free();

        $this->poll = $this->createPoll();

        if ($pending) {
            $this->poll->listen($timeout);
        }
    }

    /**
     * Reads data from the stream socket resource based on set length and read-to byte.
     *
     * @param resource $resource
     * @param int $length
     * @param string|null $byte
     *
     * @return string
     */
    private function fetch($resource, int $length = self::CHUNK_SIZE, string $byte = null): string
    {
        $remaining = $length;

        if (('' === $this->buffer || 0 < ($remaining -= strlen($this->buffer))) && is_resource($resource)) {
            $this->buffer .= fread($resource, $remaining);
        }

        if (null === $byte || false === ($position = strpos($this->buffer, $byte))) {
            if (strlen($this->buffer) <= $length) {
                $data = $this->buffer;
                $this->buffer = '';
                return $data;
            }

            $position = $length;
        } else {
            ++$position; // Include byte in result.
        }

        $data = (string) substr($this->buffer, 0, $position);
        $this->buffer = (string) substr($this->buffer, $position);
        return $data;
    }

    /**
     * @param resource $resource
     *
     * @return bool
     */
    private function eof($resource): bool
    {
        return (!is_resource($resource) || feof($resource)) && '' === $this->buffer;
    }

    /**
     * @return \Icicle\Loop\Events\SocketEventInterface
     */
    private function createPoll(): SocketEventInterface
    {
        return Loop\poll($this->getResource(), function ($resource, $expired) {
            if ($expired) {
                $this->deferred->reject(new TimeoutException('The connection timed out.'));
                return;
            }

            $this->deferred->resolve($this->buffer);
        });
    }
}