<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream\Pipe;

use Icicle\Awaitable\{Delayed, Exception\TimeoutException};
use Icicle\Exception\InvalidArgumentError;
use Icicle\Loop;
use Icicle\Loop\Watcher\Io;
use Icicle\Stream\Exception\{FailureException, UnreadableException};
use Icicle\Stream\{ReadableStream, StreamResource};
use Throwable;

class ReadablePipe extends StreamResource implements ReadableStream
{
    /**
     * @var \Icicle\Awaitable\Delayed|null
     */
    private $delayed;

    /**
     * @var \Icicle\Loop\Watcher\Io
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
        parent::close();

        $this->poll->free();

        if (null !== $this->delayed) {
            $this->delayed->resolve('');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read(int $length = 0, string $byte = null, float $timeout = 0): \Generator
    {
        while (null !== $this->delayed) {
            yield $this->delayed; // Wait for previous read to complete.
        }

        if (!$this->isReadable()) {
            throw new UnreadableException('The stream is no longer readable.');
        }

        if (0 > $length) {
            throw new InvalidArgumentError('The length must be a non-negative integer.');
        }

        if (0 === $length) {
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

            $this->delayed = new Delayed();

            try {
                yield $this->delayed;
            } catch (Throwable $exception) {
                $this->poll->cancel();
                throw $exception;
            } finally {
                $this->delayed = null;
            }
        }

        return $data;
    }

    /**
     * @coroutine
     *
     * Returns a coroutine fulfilled when there is data available to read in the internal stream buffer. Note that
     * this method does not consider data that may be available in the internal buffer. This method can be used to
     * implement functionality that uses the stream socket resource directly.
     *
     * @param float|int $timeout Number of seconds until the returned coroutine is rejected with a TimeoutException
     *     if no data is received. Use 0 for no timeout.
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
        while (null !== $this->delayed) {
            yield $this->delayed; // Wait for previous read to complete.
        }

        if (!$this->isReadable()) {
            throw new UnreadableException('The stream is no longer readable.');
        }

        if ('' !== $this->buffer) {
            throw new FailureException('Stream buffer is not empty. Perform another read before polling.');
        }

        $this->poll->listen($timeout);

        $this->delayed = new Delayed();

        try {
            yield $this->delayed;
        } catch (Throwable $exception) {
            $this->poll->cancel();
            throw $exception;
        } finally {
            $this->delayed = null;
        }

        if ('' !== $this->buffer) {
            throw new FailureException('Data unshifted to stream buffer while polling.');
        }

        return ''; // Resolve with empty string.
    }

    /**
     * Shifts the given data back to the front of the stream and will be the first bytes returned from any pending or
     * subsequent read.
     *
     * @param string $data
     */
    public function unshift(string $data)
    {
        $this->buffer = $data . $this->buffer;

        if (null !== $this->delayed) {
            $this->delayed->resolve($this->buffer);
            $this->poll->cancel();
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
    public function rebind(float $timeout = 0)
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
     * @return \Icicle\Loop\Watcher\Io
     */
    private function createPoll(): Io
    {
        return Loop\poll($this->getResource(), function ($resource, $expired) {
            if ($expired) {
                $this->delayed->reject(new TimeoutException('The connection timed out.'));
                return;
            }

            $this->delayed->resolve($this->buffer);
        });
    }
}