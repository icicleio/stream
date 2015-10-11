<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream;

/**
 * Writes strings to a stream.
 */
class StreamWriter
{
    /**
     * @var \Icicle\Stream\WritableStreamInterface The stream to write to.
     */
    private $stream;

    /**
     * Creates a new stream writer for a given stream.
     *
     * @param \Icicle\Stream\WritableStreamInterface $stream The stream to write to.
     */
    public function __construct(WritableStreamInterface $stream)
    {
        $this->stream = $stream;
    }

    /**
     * Gets the underlying stream.
     *
     * @return \Icicle\Stream\WritableStreamInterface
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
     * Writes a value to the stream.
     *
     * The given value will be coerced to a string before being written.
     *
     * @param mixed $text A printable value that can be coerced to a string.
     *
     * @return \Generator
     */
    public function write($text)
    {
        yield $this->stream->write((string)$text);
    }

    /**
     * @coroutine
     *
     * Writes a value to the stream and then terminates the line.
     *
     * The given value will be coerced to a string before being written.
     *
     * @param mixed $text A printable value that can be coerced to a string.
     *
     * @return \Generator
     */
    public function writeLine($text)
    {
        yield $this->stream->write((string)$text . PHP_EOL);
    }

    /**
     * Writes a formatted string to the stream.
     *
     * Accepts a format string followed by a series of mixed values. The string
     * will be formatted in accordance with the specification of the built-in
     * function `printf()`.
     *
     * @param string $format The format string.
     *
     * @return \Generator
     *
     * @see http://php.net/prinf
     */
    public function printf($format /*, ...$args */)
    {
        $formatted = call_user_func_array('sprintf', func_get_args());
        yield $this->write($formatted);
    }

    /**
     * Writes a formatted string to the stream and then terminates the line.
     *
     * Accepts a format string followed by a series of mixed values. The string
     * will be formatted in accordance with the specification of the built-in
     * function `printf()`.
     *
     * @param string $format The format string.
     *
     * @return \Generator
     *
     * @see http://php.net/prinf
     */
    public function printLine($format /*, ...$args */)
    {
        $formatted = call_user_func_array('sprintf', func_get_args());
        yield $this->writeLine($formatted);
    }
}
