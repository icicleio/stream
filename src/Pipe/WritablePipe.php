<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream\Pipe;

use Exception;
use Icicle\Awaitable\Delayed;
use Icicle\Awaitable\Exception\TimeoutException;
use Icicle\Loop;
use Icicle\Stream\Exception\ClosedException;
use Icicle\Stream\Exception\FailureException;
use Icicle\Stream\Exception\UnwritableException;
use Icicle\Stream\StreamResource;
use Icicle\Stream\WritableStream;

class WritablePipe extends StreamResource implements WritableStream
{
    /**
     * Queue of data to write and promises to resolve when that data is written (or fails to write).
     * Data is stored as an array: [string, int, int|float|null, Delayed].
     *
     * @var \SplQueue
     */
    private $writeQueue;

    /**
     * @var bool
     */
    private $writable = true;

    /**
     * @var \Icicle\Loop\Watcher\Io
     */
    private $await;

    /**
     * @param resource $resource Stream resource.
     */
    public function __construct($resource)
    {
        parent::__construct($resource);

        stream_set_write_buffer($resource, 0);
        stream_set_chunk_size($resource, self::CHUNK_SIZE);

        $this->writeQueue = new \SplQueue();
        $this->await = $this->createAwait();
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
     * @param \Exception|null $exception
     */
    private function free(Exception $exception = null)
    {
        parent::close();

        $this->writable = false;

        $this->await->free();

        while (!$this->writeQueue->isEmpty()) {
            /** @var \Icicle\Awaitable\Delayed $delayed */
            list( , , , $delayed) = $this->writeQueue->shift();
            $delayed->cancel(
                $exception = $exception ?: new ClosedException('The stream was unexpectedly closed.')
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function write($data, $timeout = 0)
    {
        return $this->send($data, $timeout, false);
    }

    /**
     * Writes the given data to the stream, immediately making the stream unwritable if $end is true.
     *
     * @param string $data
     * @param int $timeout
     * @param bool $end
     *
     * @return \Generator
     *
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream has been closed.
     */
    private function send($data, $timeout = 0, $end = false)
    {
        if (!$this->isWritable()) {
            throw new UnwritableException('The stream is no longer writable.');
        }

        $data = (string) $data;
        $length = strlen($data);
        $written = 0;

        if ($end) {
            $this->writable = false;
        }

        try {
            if ($this->writeQueue->isEmpty()) {
                if (0 === $length) {
                    yield $written;
                    return;
                }

                $written = $this->push($this->getResource(), $data, false);

                if ($length <= $written) {
                    yield $written;
                    return;
                }

                $data = substr($data, $written);
            }

            $delayed = new Delayed();
            $this->writeQueue->push([$data, $written, $timeout, $delayed]);

            if (!$this->await->isPending()) {
                $this->await->listen($timeout);
            }

            yield $delayed;
        } catch (Exception $exception) {
            if ($this->isOpen()) {
                $this->free($exception);
            }
            throw $exception;
        } finally {
            if ($end && $this->isOpen()) {
                $this->close();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function end($data = '', $timeout = 0)
    {
        return $this->send($data, $timeout, true);
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
    public function await($timeout = 0)
    {
        if (!$this->isWritable()) {
            throw new UnwritableException('The stream is no longer writable.');
        }

        $delayed = new Delayed();
        $this->writeQueue->push(['', 0, $timeout, $delayed]);

        if (!$this->await->isPending()) {
            $this->await->listen($timeout);
        }

        try {
            yield $delayed;
        } catch (Exception $exception) {
            if ($this->isOpen()) {
                $this->free($exception);
            }
            throw $exception;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isWritable()
    {
        return $this->writable;
    }

    /**
     * {@inheritdoc}
     *
     * @param float|int $timeout Timeout for await if a write was pending.
     */
    public function rebind($timeout = 0)
    {
        $pending = $this->await->isPending();
        $this->await->free();

        $this->await = $this->createAwait();

        if ($pending) {
            $this->await->listen($timeout);
        }
    }

    /**
     * @param resource $resource
     * @param string $data
     * @param bool $strict If true, fail if no bytes are written.
     *
     * @return int Number of bytes written.
     *
     * @throws FailureException If writing fails.
     */
    private function push($resource, $data, $strict = false)
    {
        // Error reporting suppressed since fwrite() emits E_WARNING if the pipe is broken or the buffer is full.
        $written = @fwrite($resource, $data, self::CHUNK_SIZE);

        if (false === $written || (0 === $written && $strict)) {
            $message = 'Failed to write to stream.';
            if ($error = error_get_last()) {
                $message .= sprintf(' Errno: %d; %s', $error['type'], $error['message']);
            }
            throw new FailureException($message);
        }

        return $written;
    }

    /**
     * @return \Icicle\Loop\Watcher\Io
     */
    private function createAwait()
    {
        return Loop\await($this->getResource(), function ($resource, $expired) {
            if ($expired) {
                $this->free(new TimeoutException('Writing to the socket timed out.'));
                return;
            }

            /** @var \Icicle\Awaitable\Delayed $delayed */
            list($data, $previous, $timeout, $delayed) = $this->writeQueue->shift();

            $length = strlen($data);

            if (0 === $length) {
                $delayed->resolve($previous);
            } else {
                try {
                    $written = $this->push($resource, $data, true);
                } catch (Exception $exception) {
                    $delayed->reject($exception);
                    return;
                }

                if ($length <= $written) {
                    $delayed->resolve($written + $previous);
                } else {
                    $data = substr($data, $written);
                    $written += $previous;
                    $this->writeQueue->unshift([$data, $written, $timeout, $delayed]);
                }
            }

            if (!$this->writeQueue->isEmpty()) {
                list( , , $timeout) = $this->writeQueue->top();
                $this->await->listen($timeout);
            }
        });
    }
}
