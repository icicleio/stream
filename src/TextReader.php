<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream;

use Icicle\Stream\Exception\Error;
use Icicle\Stream\Structures\Buffer;

/**
 * Reads text from a stream.
 *
 * Requires mbstring to be available to do proper chracter decoding.
 */
class TextReader implements StreamInterface
{
    const DEFAULT_CHUNK_SIZE = 4096;

    /**
     * @var \Icicle\Stream\ReadableStreamInterface The stream to read from.
     */
    private $stream;

    /**
     * @var string The name of the character encoding to use.
     */
    private $encoding;

    /**
     * @var \Icicle\Stream\Structures\Buffer
     */
    private $buffer;

    /**
     * Creates a new stream reader for a given stream.
     *
     * @param \Icicle\Stream\ReadableStreamInterface $stream The stream to read from.
     * @param string $encoding The character encoding to use.
     */
    public function __construct(ReadableStreamInterface $stream, $encoding = 'UTF-8')
    {
        if (!extension_loaded('mbstring')) {
            throw new Error('The mbstring extension is not loaded.');
        }

        $this->stream = $stream;
        $this->encoding = $encoding;
        $this->buffer = new Buffer();
    }

    /**
     * Gets the underlying stream.
     *
     * @return \Icicle\Stream\ReadableStreamInterface
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Determines if the stream is still open.
     *
     * @return bool
     */
    public function isOpen()
    {
        return $this->stream->isOpen();
    }

    /**
     * Closes the stream reader and the underlying stream.
     */
    public function close()
    {
        $this->stream->close();
    }

    /**
     * @coroutine
     *
     * Returns the next sequence of characters without consuming them.
     *
     * @param int $length The number of characters to peek.
     *
     * @return \Generator
     *
     * @resolve string String of characters read from the stream.
     *
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    public function peek($length = 1)
    {
        // Read chunks of bytes until we reach the desired length.
        while (mb_strlen((string)$this->buffer, $this->encoding) < $length && $this->stream->isReadable()) {
            $this->buffer->push(yield $this->stream->read(self::DEFAULT_CHUNK_SIZE));
        }

        yield mb_substr((string)$this->buffer, 0, min($length, $this->buffer->getLength()), $this->encoding);
    }

    /**
     * @coroutine
     *
     * Reads a specific number of characters from the stream.
     *
     * @return \Generator
     *
     * @resolve string String of characters read from the stream.
     *
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    public function read($length = 1)
    {
        $text = (yield $this->peek($length));
        $this->buffer->shift(strlen($text));
    }

    /**
     * @coroutine
     *
     * Reads a single line from the stream.
     *
     * Reads from the stream until a newline is reached or the stream is closed.
     * The newline characters are included in the returned string.
     *
     * @return \Generator
     *
     * @resolve string A line of text read from the stream.
     *
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    public function readLine()
    {
        // Check if a new line is already in the buffer.
        if (($pos = $this->buffer->search("\n")) !== false)
        {
            yield $this->buffer->shift($pos + 1);
            return;
        }

        while ($this->stream->isReadable()) {
            $buffer = (yield $this->stream->read(0, "\n"));

            if (($pos = strpos($buffer, "\n")) !== false) {
                yield $this->buffer->drain() . substr($buffer, 0, $pos + 1);
                return;
            }

            $this->buffer->push($buffer);
        }
    }

    /**
     * @coroutine
     *
     * Reads all characters from the stream until the end of the stream is reached.
     *
     * @return \Generator
     *
     * @resolve string The contents of the stream.
     *
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    public function readAll()
    {
        while ($this->stream->isReadable()) {
            $this->buffer->push(yield $this->stream->read(0));
        }

        yield $this->buffer->drain();
    }

    /**
     * @coroutine
     *
     * Reads and parses characters from the stream according to a format.
     *
     * The format string is of the same format as `sscanf()`.
     *
     * @param string $format The parse format.
     *
     * @return \Generator
     *
     * @resolve array An array of parsed values.
     *
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     *
     * @see http://php.net/sscanf
     */
    public function scan($format)
    {
        // Read from the stream chunk by chunk, attempting to satisfy the format
        // string each time until the format successfully parses or the end of
        // the stream is reached.
        while (true) {
            $result = sscanf((string)$this->buffer, $format . '%n');
            $length = $result ? array_pop($result) : null;

            // If the format string was satisfied, consume the used characters and
            // return the parsed results.
            if ($length !== null && $length < $this->buffer->getLength()) {
                $this->buffer->shift($length);
                yield $result;
                return;
            }

            // Read more into the buffer if possible.
            if ($this->stream->isReadable()) {
                $this->buffer->push(yield $this->stream->read(self::DEFAULT_CHUNK_SIZE));
            } else {
                // Format string can't be satisfied.
                yield null;
                return;
            }
        }
    }
}
