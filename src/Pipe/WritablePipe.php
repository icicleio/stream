<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream\Pipe;

use Icicle\Awaitable\{Delayed, Exception\TimeoutException};
use Icicle\Loop;
use Icicle\Loop\Watcher\Io;
use Icicle\Stream\Exception\{ClosedException, FailureException, UnwritableException};
use Icicle\Stream\{StreamResource, WritableStream};
use Throwable;

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
     * @param bool $autoClose True to close the resource on destruct, false to leave it open.
     */
    public function __construct($resource, bool $autoClose = true)
    {
        parent::__construct($resource, $autoClose);

        stream_set_write_buffer($resource, 0);
        stream_set_chunk_size($resource, self::CHUNK_SIZE);

        $this->writeQueue = new \SplQueue();
    }

    /**
     * Frees resources associated with this object from the loop.
     */
    public function __destruct()
    {
        parent::__destruct();
        $this->free();
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        parent::close();
        $this->free();
    }

    /**
     * Frees all resources used by the writable stream.
     *
     * @param \Throwable|null $exception
     */
    private function free(Throwable $exception = null)
    {
        $this->writable = false;

        if (null !== $this->await) {
            $this->await->free();
        }

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
    public function write(string $data, float $timeout = 0): \Generator
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
     * @throws \Icicle\Stream\Exception\FailureException If writing to the stream fails.
     */
    private function send(string $data, float $timeout = 0, bool $end = false): \Generator
    {
        if (!$this->isWritable()) {
            throw new UnwritableException('The stream is no longer writable.');
        }

        $length = strlen($data);
        $written = 0;

        if ($end) {
            $this->writable = false;
        }

        try {
            if ($this->writeQueue->isEmpty()) {
                if (0 === $length) {
                    return $written;
                }

                // Error reporting suppressed since fwrite() emits E_WARNING if the pipe is broken or the buffer is full.
                $written = @fwrite($this->getResource(), $data, self::CHUNK_SIZE);

                if (false === $written) {
                    $message = 'Failed to write to stream.';
                    if ($error = error_get_last()) {
                        $message .= sprintf(' Errno: %d; %s', $error['type'], $error['message']);
                    }
                    throw new FailureException($message);
                }

                if ($length <= $written) {
                    return $written;
                }

                $data = substr($data, $written);
            }

            $delayed = new Delayed();
            $this->writeQueue->push([$data, $written, $timeout, $delayed]);

            if (null === $this->await) {
                $this->await = $this->createAwait($this->getResource(), $this->writeQueue);
                $this->await->listen($timeout);
            } elseif (!$this->await->isPending()) {
                $this->await->listen($timeout);
            }

            return yield $delayed;
        } catch (Throwable $exception) {
            $this->free($exception);
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
    public function end(string $data = '', float $timeout = 0): \Generator
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
    public function await(float $timeout = 0): \Generator
    {
        if (!$this->isWritable()) {
            throw new UnwritableException('The stream is no longer writable.');
        }

        $delayed = new Delayed();
        $this->writeQueue->push(['', 0, $timeout, $delayed]);

        if (null === $this->await) {
            $this->await = $this->createAwait($this->getResource(), $this->writeQueue);
            $this->await->listen($timeout);
        } elseif (!$this->await->isPending()) {
            $this->await->listen($timeout);
        }

        try {
            return yield $delayed;
        } catch (Throwable $exception) {
            $this->free($exception);
            throw $exception;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isWritable(): bool
    {
        return $this->writable;
    }

    /**
     * {@inheritdoc}
     *
     * @param float|int $timeout Timeout for await if a write was pending.
     */
    public function rebind(float $timeout = 0)
    {
        if (null !== $this->await) {
            $pending = $this->await->isPending();
            $this->await->free();

            $this->await = $this->createAwait($this->getResource(), $this->writeQueue);

            if ($pending) {
                $this->await->listen($timeout);
            }
        }
    }

    /**
     * @param resource $resource
     * @param \SplQueue $writeQueue
     *
     * @return \Icicle\Loop\Watcher\Io
     */
    private function createAwait($resource, \SplQueue $writeQueue): Io
    {
        return Loop\await($resource, static function ($resource, bool $expired, Io $await) use ($writeQueue) {
            /** @var \Icicle\Awaitable\Delayed $delayed */
            list($data, $previous, $timeout, $delayed) = $writeQueue->shift();

            if ($expired) {
                $delayed->reject(new TimeoutException('Writing to the socket timed out.'));
                return;
            }

            $length = strlen($data);

            if (0 === $length) {
                $delayed->resolve($previous);
            } else {
                // Error reporting suppressed since fwrite() emits E_WARNING if the pipe is broken or the buffer is full.
                $written = @fwrite($resource, $data, self::CHUNK_SIZE);

                if (false === $written || 0 === $written) {
                    $message = 'Failed to write to stream.';
                    if ($error = error_get_last()) {
                        $message .= sprintf(' Errno: %d; %s', $error['type'], $error['message']);
                    }
                    $delayed->reject(new FailureException($message));
                    return;
                }

                if ($length <= $written) {
                    $delayed->resolve($written + $previous);
                } else {
                    $data = substr($data, $written);
                    $written += $previous;
                    $writeQueue->unshift([$data, $written, $timeout, $delayed]);
                }
            }

            if (!$writeQueue->isEmpty()) {
                list( , , $timeout) = $writeQueue->bottom();
                $await->listen($timeout);
            }
        });
    }
}
