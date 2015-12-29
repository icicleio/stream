<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream;

use Icicle\Exception\InvalidArgumentError;

abstract class StreamResource implements Resource
{
    /**
     * Stream resource or null if the resource has been closed.
     *
     * @var resource|null
     */
    private $resource;

    /**
     * @var bool
     */
    private $autoClose = true;

    /**
     * @param resource $resource PHP stream resource.
     * @param bool $autoClose True to close the resource on destruct, false to leave it open.
     *
     * @throws \Icicle\Exception\InvalidArgumentError If a non-resource is given.
     */
    public function __construct($resource, bool $autoClose = true)
    {
        if (!is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw new InvalidArgumentError('Invalid resource given to constructor!');
        }

        $this->resource = $resource;
        $this->autoClose = $autoClose;

        stream_set_blocking($this->resource, 0);
    }

    public function __destruct()
    {
        if ($this->autoClose && is_resource($this->resource)) {
            fclose($this->resource);
        }
    }

    /**
     * Determines if the stream resource is still open.
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        return null !== $this->resource;
    }

    /**
     * Closes the socket.
     */
    public function close()
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }

        $this->resource = null;
        $this->autoClose = false;
    }

    /**
     * Returns the stream resource or null if the resource has been closed.
     *
     * @return resource|null
     */
    public function getResource()
    {
        return $this->resource;
    }
}