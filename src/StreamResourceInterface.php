<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream;

interface StreamResourceInterface extends StreamInterface
{
    const CHUNK_SIZE = 8192; // 8kB

    /**
     * Returns the stream resource or null if the resource has been closed.
     *
     * @return resource|null
     */
    public function getResource();
}