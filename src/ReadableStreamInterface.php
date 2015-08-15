<?php
namespace Icicle\Stream;

interface ReadableStreamInterface extends StreamInterface
{
    /**
     * @coroutine
     *
     * @param int $length Max number of bytes to read. Fewer bytes may be returned. Use 0 to read as much data
     *     as possible.
     * @param string|int|null $byte Reading will stop once the given byte occurs in the stream. Note that reading may
     *     stop before the byte is found in the stream. The search byte will be included in the resolving string.
     *     Use null to effectively ignore this parameter and read any bytes.
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if no data is received. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve string Data read from the stream.
     *
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    public function read(int $length = 0, $byte = null, float $timeout = 0): \Generator;

    /**
     * Determines if the stream is still readable. A stream being readable does not mean there is data immediately
     * available to read. Use read() or poll() to wait for data.
     *
     * @return bool
     */
    public function isReadable(): bool;
    
    /**
     * @coroutine
     *
     * Pipes data read on this stream into the given writable stream destination.
     *
     * @param WritableStreamInterface $stream
     * @param bool $end Set to true to automatically call end() on the writable stream when piping ends, either due
     *     to the readable stream closing or reaching the given length or byte.
     * @param int $length If not null, only $length bytes will be piped.
     * @param string|int $byte Piping will stop once the given byte occurs in the stream. The search character will
     *     be piped to the writable stream string. Use null to ignore this parameter and pipe all bytes.
     * @param float|int $timeout Number of seconds until the returned promise is rejected with a TimeoutException
     *     if no data is received. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve int Resolves when the writable stream closes, after $length bytes (if $length was not 0) have been
     *     read from the stream, or $byte was read from the stream (if $byte was not null). Resolves with the number of
     *     bytes read from the stream.
     *
     * @throws \Icicle\Stream\Exception\BusyError If a read was already pending on the stream.
     * @throws \Icicle\Stream\Exception\UnreadableException If the stream is no longer readable.
     * @throws \Icicle\Stream\Exception\UnwritableException If the stream is no longer writable.
     * @throws \Icicle\Stream\Exception\ClosedException If the stream is unexpectedly closed.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    public function pipe(
        WritableStreamInterface $stream,
        bool $end = true,
        int $length = 0,
        $byte = null,
        float $timeout = 0
    ): \Generator;
}
