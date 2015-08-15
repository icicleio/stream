<?php
namespace Icicle\Stream;

interface SeekableStreamInterface extends StreamInterface
{
    /**
     * @coroutine
     *
     * Moves the pointer to a new position in the stream.
     *
     * @param int $offset Number of bytes to seek. Usage depends on value of $whence.
     * @param int $whence Values identical to $whence values for fseek().
     * @param float|int $timeout Number of seconds until the operation fails and the stream is closed and the promise
     *     is rejected with a TimeoutException. Use 0 for no timeout.
     *
     * @return \Generator
     *
     * @resolve int New pointer position.
     *
     * @throws \Icicle\Stream\Exception\InvalidArgumentError If the whence value is invalid.
     * @throws \Icicle\Stream\Exception\InvalidOffsetException If the new offset would be outside the stream.
     * @throws \Icicle\Stream\Exception\UnseekableException If the stream is no longer seekable (due to being closed or
     *     for another reason).
     * @throws \Icicle\Stream\Exception\BusyError If the stream was already waiting on a read or seek operation.
     * @throws \Icicle\Promise\Exception\TimeoutException If the operation times out.
     */
    public function seek($offset, $whence = SEEK_SET, $timeout = 0);

    /**
     * Current pointer position. Value returned may not reflect the future pointer position if a read, write, or seek
     * operation is pending.
     *
     * @return int
     */
    public function tell();

    /**
     * Returns the total length of the stream if known, otherwise null. Value returned may not reflect a pending write
     * operation.
     *
     * @return int|null
     */
    public function getLength();
}
