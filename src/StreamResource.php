<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream;

use Icicle\Stream\Exception\InvalidArgumentError;

abstract class StreamResource implements StreamResourceInterface
{
    /**
     * Stream resource.
     *
     * @var resource|null
     */
    private $resource;

    /**
     * @param resource $resource PHP stream resource.
     *
     * @throws \Icicle\Stream\Exception\InvalidArgumentError If a non-resource is given.
     */
    public function __construct($resource)
    {
        if (!is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw new InvalidArgumentError('Invalid resource given to constructor!');
        }

        $this->resource = $resource;

        stream_set_blocking($this->resource, 0);
    }

    /**
     * Determines if the stream resource is still open.
     *
     * @return bool
     */
    public function isOpen()
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