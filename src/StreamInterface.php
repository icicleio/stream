<?php

/*
 * This file is part of the stream package for Icicle, a library for writing asynchronous code in PHP.
 *
 * @copyright 2014-2015 Aaron Piotrowski. All rights reserved.
 * @license MIT See the LICENSE file that was distributed with this source code for more information.
 */

namespace Icicle\Stream;

interface StreamInterface
{
    /**
     * Determines if the stream is still open.
     *
     * @return bool
     */
    public function isOpen();
    
    /**
     * Closes the stream, making it unreadable or unwritable.
     */
    public function close();
}
