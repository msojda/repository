<?php

/*
 * This file is part of the puli/repository package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Repository\Iterator;

use ArrayIterator;
use EmptyIterator;
use FilterIterator;
use Iterator;
use Puli\Repository\Selector\Selector;
use RecursiveIteratorIterator;
use Webmozart\PathUtil\Path;

/**
 * Filters an iterator by a selector.
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 * @see    Selector
 */
class SelectorIterator extends RegexIterator
{
    /**
     * Creates a new iterator.
     *
     * @param string   $selector      The canonical selector.
     * @param Iterator $innerIterator The filtered iterator.
     */
    public function __construct($selector, Iterator $innerIterator)
    {
        parent::__construct(
            Selector::toRegEx($selector),
            Selector::getStaticPrefix($selector),
            $innerIterator
        );
    }
}
