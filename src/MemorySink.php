<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream;

use Icicle\Stream\Exception\InvalidArgumentError;
use Icicle\Stream\Exception\OutOfBoundsException;
use Icicle\Stream\Exception\UnreadableException;
use Icicle\Stream\Exception\UnseekableException;
use Icicle\Stream\Exception\UnwritableException;
use Icicle\Stream\Structures\Buffer;

/**
 * Acts as a buffered sink with a seekable read/write pointer. All data written to the sink remains in the sink. The
 * read/write pointer may be moved anywhere within the buffered sink using seek(). The current position of the pointer
 * may be determined with tell(). Since all data remains in the sink, the entire length of the sink is available with
 * getLength().
 */
class MemorySink implements DuplexStreamInterface, SeekableStreamInterface
{
    /**
     * @var bool
     */
    private $open = true;

    /**
     * @var bool
     */
    private $writable = true;

    /**
     * @var \Icicle\Stream\Structures\Buffer
     */
    private $buffer;

    /**
     * @var \Icicle\Stream\Structures\BufferIterator
     */
    private $iterator;

    /**
     * Initializes a sink with the given data.
     *
     * @param string $data
     */
    public function __construct($data = '')
    {
        $this->buffer = new Buffer($data);
        $this->iterator = $this->buffer->getIterator();
    }

    /**
     * {@inheritdoc}
     */
    public function isOpen()
    {
        return $this->open;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->open = false;
        $this->writable = false;
    }

    /**
     * {@inheritdoc}
     */
    public function isReadable()
    {
        return $this->isOpen() && $this->iterator->valid();
    }

    /**
     * {@inheritdoc}
     */
    public function read($length = 0, $byte = null, $timeout = 0)
    {
        if (!$this->isReadable()) {
            throw new UnreadableException('The stream is no longer readable.');
        }

        $length = (int) $length;
        if (0 > $length) {
            throw new InvalidArgumentError('The length should be a positive integer.');
        }

        $byte = (string) $byte;
        $byte = strlen($byte) ? $byte[0] : null;

        if (null !== $byte) {
            $data = '';
            $i = 0;
            do {
                $char = $this->iterator->current();
                $this->iterator->next();
                $data .= $char;
            } while ($char !== $byte && (0 === $length || ++$i < $length) && $this->iterator->valid());

            yield $data;
            return;
        }

        if (0 === $length) {
            $length = $this->buffer->getLength();
        }

        $position = $this->iterator->key();
        $data = $this->buffer->peek($length, $position);
        $position = $length + $position;

        if ($position > $this->buffer->getLength()) {
            $position = $this->buffer->getLength();
        }

        $this->iterator->seek($position);

        yield $data;
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
     */
    public function write($data, $timeout = 0)
    {
        return $this->send($data, $timeout, false);
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
     * @param string $data
     * @param float|int $timeout
     * @param bool $end
     *
     * @return \Generator
     *
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     */
    protected function send($data, $timeout = 0, $end = false)
    {
        if (!$this->isWritable()) {
            throw new UnwritableException('The stream is no longer writable.');
        }

        if ($end) {
            $this->writable = false;
        }

        $length = strlen($data);

        if (!$this->iterator->valid()) {
            $this->buffer->push($data);
        } else {
            $this->iterator->insert($data);
        }

        $this->iterator->seek($this->iterator->key() + $length);

        yield $length;
    }

    /**
     * {@inheritdoc}
     */
    public function seek($offset, $whence = SEEK_SET, $timeout = 0)
    {
        if (!$this->isOpen()) {
            throw new UnseekableException('The stream is no longer seekable.');
        }

        $offset = (int) $offset;

        switch ($whence) {
            case SEEK_SET:
                break;

            case SEEK_CUR:
                $offset += $this->tell();
                break;

            case SEEK_END:
                $offset += $this->getLength();
                break;

            default:
                throw new InvalidArgumentError('Invalid value for whence. Use SEEK_SET, SEEK_CUR, or SEEK_END.');
        }

        if (0 > $offset || $this->buffer->getLength() < $offset) {
            throw new OutOfBoundsException(sprintf('Invalid offset: %s.', $offset));
        }

        $this->iterator->seek($offset);

        yield $offset;
    }

    /**
     * {@inheritdoc}
     */
    public function tell()
    {
        return $this->iterator->key();
    }

    /**
     * {@inheritdoc}
     */
    public function getLength()
    {
        return $this->buffer->getLength();
    }
}