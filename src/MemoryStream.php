<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream;

use Icicle\Awaitable\Delayed;
use Icicle\Exception\InvalidArgumentError;
use Icicle\Stream\Exception\{ClosedException, UnreadableException, UnwritableException};

/**
 * Serves as buffer that implements the stream interface, allowing consumers to be notified when data is available in
 * the buffer. This class by itself is not particularly useful, but it can be extended to add functionality upon reading 
 * or writing, as well as acting as an example of how stream classes can be implemented.
 */
class MemoryStream implements DuplexStream
{
    /**
     * @var \Icicle\Stream\Structures\Buffer
     */
    private $buffer;
    
    /**
     * @var bool
     */
    private $readable = true;
    
    /**
     * @var bool
     */
    private $writable = true;
    
    /**
     * @var \Icicle\Awaitable\Delayed|null
     */
    private $delayed;
    
    /**
     * @var int
     */
    private $length;
    
    /**
     * @var string|null
     */
    private $byte;

    /**
     * @var int
     */
    private $hwm;

    /**
     * @var \SplQueue|null
     */
    private $queue;

    /**
     * @var \Closure|null
     */
    private $onCancelled;
    
    /**
     * @param int $hwm High water mark. If the internal buffer has more than $hwm bytes, writes to the stream will
     *     return pending promises until the data is consumed.
     * @param string $data
     */
    public function __construct(int $hwm = 0, string $data = '')
    {
        $this->buffer = new Structures\Buffer($data);
        $this->hwm = (int) $hwm;
        if (0 > $this->hwm) {
            $this->hwm = 0;
        }

        if (0 !== $this->hwm) {
            $this->queue = new \SplQueue();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isOpen(): bool
    {
        return $this->readable || $this->writable;
    }
    
    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->free();
    }

    /**
     * Closes the stream and rejects any pending promises.
     *
     * @param \Throwable|null $exception
     */
    protected function free(\Throwable $exception = null)
    {
        $this->readable = false;
        $this->writable = false;

        if (null !== $this->delayed) {
            $this->delayed->resolve('');
        }

        if (0 !== $this->hwm) {
            while (!$this->queue->isEmpty()) {
                /** @var \Icicle\Awaitable\Delayed $delayed */
                $delayed = $this->queue->shift();
                $delayed->reject(
                    $exception = $exception ?: new ClosedException('The stream was unexpectedly closed.')
                );
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read(int $length = 0, string $byte = null, float $timeout = 0): \Generator
    {
        while (null !== $this->delayed) {
            yield $this->delayed;
        }

        if (!$this->isReadable()) {
            throw new UnreadableException('The stream is no longer readable.');
        }

        $this->length = $length;
        if (0 > $this->length) {
            throw new InvalidArgumentError('The length should be a positive integer.');
        }

        $this->byte = strlen($byte) ? $byte[0] : null;

        if (!$this->buffer->isEmpty()) {
            $data = $this->remove();

            if (0 !== $this->hwm && $this->buffer->getLength() <= $this->hwm) {
                while (!$this->queue->isEmpty()) {
                    /** @var \Icicle\Awaitable\Delayed $delayed */
                    $delayed = $this->queue->shift();
                    $delayed->resolve();
                }
            }

            if (!$this->writable && $this->buffer->isEmpty()) {
                $this->free();
            }

            return $data;
        }

        $awaitable = $this->delayed = new Delayed();

        if ($timeout) {
            $awaitable = $this->delayed->timeout($timeout);
        }

        return yield $awaitable;
    }

    /**
     * Returns bytes from the buffer based on the current length or current search byte.
     *
     * @return string
     */
    private function remove(): string
    {
        if (null !== $this->byte && false !== ($position = $this->buffer->search($this->byte))) {
            if (0 === $this->length || $position < $this->length) {
                return $this->buffer->shift($position + 1);
            }

            return $this->buffer->shift($this->length);
        }

        if (0 === $this->length) {
            return $this->buffer->drain();
        }

        return $this->buffer->shift($this->length);
    }

    /**
     * Shifts the given data back to the front of the stream and will be the first bytes returned from any pending or
     * subsequent read.
     *
     * @param string $data
     */
    public function unshift(string $data)
    {
        if (!strlen($data)) {
            return;
        }

        $this->buffer->unshift($data);

        if (null !== $this->delayed && !$this->buffer->isEmpty()) {
            $this->delayed->resolve($this->remove());
            $this->delayed = null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isReadable(): bool
    {
        return $this->readable;
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $data, float $timeout = 0): \Generator
    {
        return $this->send($data, $timeout, false);
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
     * @param string $data
     * @param float|int $timeout
     * @param bool $end
     *
     * @return \Generator
     *
     * @resolve int Number of bytes written to the stream.
     *
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     */
    protected function send(string $data, float $timeout = 0, bool $end = false): \Generator
    {
        if (!$this->isWritable()) {
            throw new UnwritableException('The stream is no longer writable.');
        }

        $this->buffer->push($data);

        if (null !== $this->delayed && $this->delayed->isPending() && !$this->buffer->isEmpty()) {
            $this->delayed->resolve($this->remove());
            $this->delayed = null;
        }

        if ($end) {
            if ($this->buffer->isEmpty()) {
                $this->free();
            } else {
                $this->writable = false;
            }
        }

        if (0 !== $this->hwm && $this->buffer->getLength() > $this->hwm) {
            $awaitable = new Delayed($this->onCancelled = $this->onCancelled ?: function () {
                $this->free();
            });
            $this->queue->push($awaitable);

            if ($timeout) {
                $awaitable = $awaitable->timeout($timeout);
            }

            yield $awaitable;
        }

        return strlen($data);
    }

    /**
     * {@inheritdoc}
     */
    public function isWritable(): bool
    {
        return $this->writable;
    }
}
