<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream\Text;

use Icicle\Exception\InvalidArgumentError;
use Icicle\Exception\UnsupportedError;
use Icicle\Stream;
use Icicle\Stream\ReadableStream;
use Icicle\Stream\Structures\Buffer;

/**
 * Reads text from a stream.
 *
 * Requires mbstring to be available to do proper character decoding.
 */
class TextReader
{
    /**
     * @var \Icicle\Stream\ReadableStream The stream to read from.
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
     * @var string The string of bytes representing a newline in the configured encoding.
     */
    private $newLine;

    /**
     * Creates a new stream reader for a given stream.
     *
     * @param \Icicle\Stream\ReadableStream $stream The stream to read from.
     * @param string $encoding The character encoding to use.
     *
     * @throws \Icicle\Exception\UnsupportedError Thrown if the mbstring extension is not loaded.
     */
    public function __construct(ReadableStream $stream, $encoding = 'UTF-8')
    {
        if (!extension_loaded('mbstring')) {
            throw new UnsupportedError('The mbstring extension is not loaded.');
        }

        if (!in_array($encoding, mb_list_encodings())) {
            throw new InvalidArgumentError("The encoding '$encoding' is not available.");
        }

        $this->stream = $stream;
        $this->encoding = $encoding;
        $this->buffer = new Buffer();
        $this->newLine = mb_convert_encoding("\n", $encoding, 'UTF-8');
    }

    /**
     * Gets the underlying stream.
     *
     * @return \Icicle\Stream\ReadableStream
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
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if no data is received. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve string String of characters read from the stream.
     *
     * @throws \Icicle\Awaitable\Exception\TimeoutException If the operation times out.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     */
    public function peek($length = 1, $timeout = 0)
    {
        // Read chunks of bytes until we reach the desired length.
        while (mb_strlen((string) $this->buffer, $this->encoding) < $length && $this->stream->isReadable()) {
            $this->buffer->push(yield $this->stream->read(0, null, $timeout));
        }

        yield mb_substr((string) $this->buffer, 0, min($length, $this->buffer->getLength()), $this->encoding);
    }

    /**
     * @coroutine
     *
     * Reads a specific number of characters from the stream.
     *
     * @param int $length The number of characters to read.
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if no data is received. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve string String of characters read from the stream.
     *
     * @throws \Icicle\Awaitable\Exception\TimeoutException If the operation times out.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     */
    public function read($length = 1, $timeout = 0)
    {
        yield $this->buffer->shift(strlen(yield $this->peek($length, $timeout)));
    }

    /**
     * @coroutine
     *
     * Reads a single line from the stream.
     *
     * Reads from the stream until a newline is reached or the stream is closed.
     * The newline characters are included in the returned string. If reading ends
     * in the middle of a character, the trailing bytes are not consumed.
     *
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if no data is received. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve string A line of text read from the stream.
     *
     * @throws \Icicle\Awaitable\Exception\TimeoutException If the operation times out.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     */
    public function readLine($timeout = 0)
    {
        $newLineSize = strlen($this->newLine);

        // Check if a new line is already in the buffer.
        if (($pos = $this->buffer->search($this->newLine)) !== false) {
            yield $this->buffer->shift($pos + $newLineSize);
            return;
        }

        $this->buffer->push(yield Stream\readUntil($this->stream, $this->newLine, 0, $timeout));

        if (($pos = $this->buffer->search($this->newLine)) !== false) {
            yield $this->buffer->shift($pos + $newLineSize);
            return;
        }

        yield $this->buffer->shift(strlen(mb_strcut((string) $this->buffer, 0, null, $this->encoding)));
    }

    /**
     * @coroutine
     *
     * Reads all characters from the stream until the end of the stream is reached.
     *
     * If the stream ends in the middle of a character, the left over bytes will be discarded.
     *
     * @param int $maxLength The maximum number of bytes to read.
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if no data is received. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve string The contents of the stream.
     *
     * @throws \Icicle\Awaitable\Exception\TimeoutException If the operation times out.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     */
    public function readAll($maxlength = 0, $timeout = 0)
    {
        $this->buffer->push(yield Stream\readAll($this->stream, $maxlength, $timeout));
        yield $this->buffer->shift(strlen(mb_strcut((string) $this->buffer, 0, null, $this->encoding)));
    }

    /**
     * @coroutine
     *
     * Reads and parses characters from the stream according to a format.
     *
     * The format string is of the same format as `sscanf()`.
     *
     * @param string $format The parse format.
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if no data is received. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve array An array of parsed values.
     *
     * @throws \Icicle\Awaitable\Exception\TimeoutException If the operation times out.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     *
     * @see http://php.net/sscanf
     */
    public function scan($format, $timeout = 0)
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
                $this->buffer->push(yield $this->stream->read(0, null, $timeout));
            } else {
                // Format string can't be satisfied.
                yield [];
                return;
            }
        }
    }
}
