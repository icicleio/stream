<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream;

/**
 * Reads characters from a stream.
 *
 * The stream is read in a UTF-8 aware manner and text is assumed to be encoded
 * in UTF-8.
 */
class StreamReader
{
    /**
     * @var \Icicle\Stream\ReadableStreamInterface The stream to read from.
     */
    private $stream;

    /**
     * @var string[] A buffer of characters.
     */
    private $buffer = [];

    /**
     * Creates a new stream reader for a given stream.
     *
     * @param \Icicle\Stream\ReadableStreamInterface $stream The stream to read from.
     */
    public function __construct(ReadableStreamInterface $stream)
    {
        $this->stream = $stream;
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
     */
    public function peek($length = 1)
    {
        while (count($this->buffer) < $length) {
            array_push($this->buffer, (yield $this->read(1)));
        }

        yield implode('', array_slice($pieces, 0, $length));
    }

    /**
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
        $buffer = "";

        // Drain the buffer first.
        while (!empty($this->buffer)) {
            $buffer .= array_unshift($this->buffer);
            --$length;
        }

        // Read the specified number of characters.
        for (; $this->stream->isReadable() && $length > 0; --$length) {
            // Read the leading byte.
            $char = (yield $this->stream->read(1));
            $leadByte = ord($char);

            // Read the remaining bytes for the character.
            while (($leadByte & 0b11000000) === 0b11000000) {
                $char .= (yield $this->stream->read(1));
                $leadByte = $leadByte << 1;
            }

            $buffer .= $char;
        }

        yield $buffer;
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
     */
    public function readLine()
    {
        $buffer = "";

        while ($this->stream->isReadable()) {
            $char = (yield $this->read(1));
            $buffer .= $char;

            if ($char === "\n") {
                break;
            }
        }

        yield $buffer;
    }

    /**
     * @coroutine
     *
     * Reads all characters from the stream until the end of the stream is reached.
     *
     * @return \Generator
     *
     * @resolve string The contents of the stream.
     */
    public function readAll()
    {
        $buffer = "";

        // Drain the buffer first.
        if (!empty($this->buffer)) {
            $buffer = implode('', $this->buffer);
            $this->buffer = [];
        }

        while ($this->stream->isReadable()) {
            $buffer .= (yield $this->stream->read());
        }

        yield $buffer;
    }

    /**
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
     * @see http://php.net/sscanf
     */
    public function scan($format)
    {
        $buffer = "";

        // Read from the stream character by character, attempting to satisfy the
        // format string each time until the format successfully parses or the end
        // of the stream is reached.
        while ($this->stream->isReadable()) {
            $buffer .= $this->peek(1);

            $result = sscanf($buffer, $format . '%n');
            $length = array_pop($result);

            if ($length !== null && $length < strlen($buffer)) {
                yield $result;
                return;
            }

            yield $this->read(1);
        }

        yield null;
    }
}
