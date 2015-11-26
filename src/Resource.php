<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream;

interface Resource
{
    const CHUNK_SIZE = 8192; // 8kB

    /**
     * Determines if the resource is still open.
     *
     * @return bool
     */
    public function isOpen();

    /**
     * Closes the resource, making it unreadable or unwritable.
     */
    public function close();

    /**
     * Returns the stream resource or null if the resource has been closed.
     *
     * @return resource|null
     */
    public function getResource();

    /**
     * Rebinds the object to the current global event loop instance.
     */
    public function rebind();
}