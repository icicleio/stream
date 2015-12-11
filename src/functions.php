<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream;

use Icicle\Exception\{InvalidArgumentError, UnsupportedError};
use Icicle\Stream\Exception\{FailureException, UnwritableException};
use Icicle\Stream\Pipe\{ReadablePipe, WritablePipe};

// @codeCoverageIgnoreStart
if (strlen('…') !== 3) {
    throw new UnsupportedError(
        'The mbstring.func_overload ini setting is enabled. It must be disable to use the stream package.'
    );
} // @codeCoverageIgnoreEnd

if (!function_exists(__NAMESPACE__ . '\pipe')) {
    /**
     * @coroutine
     *
     * @param \Icicle\Stream\ReadableStream $source
     * @param \Icicle\Stream\WritableStream $destination
     * @param bool $end If true, calls end() on the destination stream when piping ends.
     * @param int $length The number of bytes to pipe. Use 0 for any number of bytes.
     * @param string|null $byte Stop piping when the given byte is read from the source stream. Use null to ignore
     *     this parameter.
     * @param float|int $timeout Number of seconds to wait while reading from the source or writing to the destination
     *     before failing. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve int
     *
     * @throws \Icicle\Awaitable\Exception\TimeoutException If the operation times out.
     * @throws \Icicle\Exception\InvalidArgumentError If the length is invalid.
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     */
    function pipe(
        ReadableStream $source,
        WritableStream $destination,
        bool $end = true,
        int $length = 0,
        string $byte = null,
        float $timeout = 0
    ): \Generator {
        if (!$destination->isWritable()) {
            throw new UnwritableException('The stream is not writable.');
        }

        if (0 > $length) {
            throw new InvalidArgumentError('The length should be a non-negative integer.');
        }

        $byte = strlen($byte) ? $byte[0] : null;

        $bytes = 0;

        do {
            $data = yield from $source->read($length, $byte, $timeout);

            $count = strlen($data);
            $bytes += $count;

            if ($count) {
                yield from $destination->write($data, $timeout);
            }
        } while ($source->isReadable()
            && $destination->isWritable()
            && (null === $byte || $data[$count - 1] !== $byte)
            && (0 === $length || 0 < $length -= $count)
        );

        if ($end && $destination->isWritable()) {
            yield from $destination->end();
        }

        return $bytes;
    }

    /**
     * @coroutine
     *
     * @param ReadableStream $stream
     * @param int $length
     * @param float|int $timeout
     *
     * @return \Generator
     *
     * @resolve string
     *
     * @throws \Icicle\Awaitable\Exception\TimeoutException If the operation times out.
     * @throws \Icicle\Exception\InvalidArgumentError If the length is invalid.
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     */
    function readTo(ReadableStream $stream, int $length, float $timeout = 0): \Generator
    {
        if (0 > $length) {
            throw new InvalidArgumentError('The length should be a non-negative integer.');
        }

        if (0 === $length) {
            return '';
        }

        $buffer = '';
        $remaining = $length;

        do {
            $buffer .= yield from $stream->read($remaining, null, $timeout);
        } while (0 === $length || 0 < ($remaining = $length - strlen($buffer)));

        return $buffer;
    }

    /**
     * @coroutine
     *
     * @param ReadableStream $stream
     * @param string $needle
     * @param int $maxlength
     * @param float|int $timeout
     *
     * @return \Generator
     *
     * @resolve string
     *
     * @throws \Icicle\Awaitable\Exception\TimeoutException If the operation times out.
     * @throws \Icicle\Exception\InvalidArgumentError If the length is invalid.
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     */
    function readUntil(
        ReadableStream $stream,
        string $needle,
        int $maxlength = 0,
        float $timeout = 0
    ): \Generator {
        $maxlength = (int) $maxlength;
        if (0 > $maxlength) {
            throw new InvalidArgumentError('The length should be a non-negative integer.');
        }

        $nlength = strlen($needle);

        if (0 === $nlength) {
            throw new InvalidArgumentError('The needle must be a non-empty string.');
        }

        $byte = $needle[$nlength - 1];
        $buffer = '';
        $remaining = $maxlength;

        do {
            $buffer .= yield from $stream->read($remaining, $byte, $timeout);
        } while ((0 === $maxlength || 0 < ($remaining = $maxlength - strlen($buffer)))
            && substr($buffer, -$nlength) !== $needle
        );

        return $buffer;
    }

    /**
     * @coroutine
     *
     * @param ReadableStream $stream
     * @param int $maxlength
     * @param float|int $timeout
     *
     * @return \Generator
     *
     * @resolve string
     *
     * @throws \Icicle\Awaitable\Exception\TimeoutException If the operation times out.
     * @throws \Icicle\Exception\InvalidArgumentError If the length is invalid.
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     */
    function readAll(ReadableStream $stream, int $maxlength = 0, float $timeout = 0): \Generator
    {
        $buffer = '';

        if (0 > $maxlength) {
            throw new InvalidArgumentError('The max length should be a non-negative integer.');
        }

        $remaining = $maxlength;

        while ($stream->isReadable() && (0 === $maxlength || 0 < ($remaining = $maxlength - strlen($buffer)))) {
            $buffer .= yield from $stream->read($remaining, null, $timeout);
        }

        return $buffer;
    }

    /**
     * Returns a pair of connected unix domain stream socket resources.
     *
     * @return resource[] Pair of socket resources.
     *
     * @throws \Icicle\Stream\Exception\FailureException If creating the sockets fails.
     */
    function pair(): array
    {
        if (false === ($sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP))) {
            $message = 'Failed to create socket pair.';
            if ($error = error_get_last()) {
                $message .= sprintf(' Errno: %d; %s', $error['type'], $error['message']);
            }
            throw new FailureException($message);
        }

        return $sockets;
    }

    /**
     * Returns the readable stream for STDIN.
     *
     * @return \Icicle\Stream\ReadableStream
     */
    function stdin(): ReadableStream
    {
        static $pipe;

        if (null === $pipe) {
            $pipe = new ReadablePipe(STDIN);
        }

        return $pipe;
    }

    /**
     * Returns the writable stream for STDOUT.
     *
     * @return \Icicle\Stream\WritableStream
     */
    function stdout(): WritableStream
    {
        static $pipe;

        if (null === $pipe) {
            $pipe = new WritablePipe(STDOUT);
        }

        return $pipe;
    }

    /**
     * Returns the writable stream for STDERR.
     *
     * @return \Icicle\Stream\WritableStream
     */
    function stderr(): WritableStream
    {
        static $pipe;

        if (null === $pipe) {
            $pipe = new WritablePipe(STDERR);
        }

        return $pipe;
    }
}
