<?php

/*
 * This file is part of the puli/repository package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Repository;

use ArrayIterator;
use BadMethodCallException;
use FilesystemIterator;
use Puli\Repository\Api\EditableRepository;
use Puli\Repository\Api\Resource\FilesystemResource;
use Puli\Repository\Api\ResourceNotFoundException;
use Puli\Repository\Resource\Collection\ArrayResourceCollection;
use Puli\Repository\Resource\LinkResource;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Webmozart\Assert\Assert;
use Webmozart\Glob\Glob;
use Webmozart\Glob\Iterator\RegexFilterIterator;
use Webmozart\PathUtil\Path;

/**
 * A development path mapping resource repository.
 * Each resource is resolved at `get()` time to improve
 * developer experience.
 *
 * Resources can be added with the method {@link add()}:
 *
 * ```php
 * use Puli\Repository\PathMappingRepository;
 *
 * $repo = new PathMappingRepository();
 * $repo->add('/css', new DirectoryResource('/path/to/project/res/css'));
 * ```
 *
 * This repository only supports instances of FilesystemResource.
 *
 * @since  1.0
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 * @author Titouan Galopin <galopintitouan@gmail.com>
 */
class PathMappingRepository extends AbstractPathMappingRepository implements EditableRepository
{
    /**
     * {@inheritdoc}
     */
    public function get($path)
    {
        $path = $this->sanitizePath($path);
        $filesystemPaths = $this->resolveFilesystemPaths($path);

        if (0 === count($filesystemPaths)) {
            throw ResourceNotFoundException::forPath($path);
        }

        return $this->createResource(reset($filesystemPaths), $path);
    }

    /**
     * {@inheritdoc}
     */
    public function find($query, $language = 'glob')
    {
        $this->validateSearchLanguage($language);
        $query = $this->sanitizePath($query);

        return $this->search($query);
    }

    /**
     * {@inheritdoc}
     */
    public function contains($query, $language = 'glob')
    {
        $this->validateSearchLanguage($language);
        $query = $this->sanitizePath($query);

        return !$this->search($query, true)->isEmpty();
    }

    /**
     * {@inheritdoc}
     */
    public function remove($query, $language = 'glob')
    {
        $query = $this->sanitizePath($query);

        Assert::notEmpty(trim($query, '/'), 'The root directory cannot be removed.');

        $results = $this->find($query, $language);
        $invalid = array();

        foreach ($results as $result) {
            if (!$this->store->exists($result->getPath())) {
                $invalid[] = $result->getFilesystemPath();
            }
        }

        if (count($invalid) === 1) {
            throw new BadMethodCallException(sprintf(
                'The remove query "%s" matched a resource that is not a path mapping', $query
            ));
        } elseif (count($invalid) > 1) {
            throw new BadMethodCallException(sprintf(
                'The remove query "%s" matched %s resources that are not path mappings', $query, count($invalid)
            ));
        }

        $removed = 0;

        foreach ($results as $result) {
            foreach ($this->getVirtualPathChildren($result->getPath(), true) as $virtualChild) {
                if ($this->store->remove($virtualChild['path'])) {
                    ++$removed;
                }
            }

            if ($this->store->remove($result->getPath())) {
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * {@inheritdoc}
     */
    public function listChildren($path)
    {
        if (!$this->isPathResolvable($path)) {
            throw ResourceNotFoundException::forPath($path);
        }

        return $this->getDirectChildren($this->sanitizePath($path));
    }

    /**
     * {@inheritdoc}
     */
    public function hasChildren($path)
    {
        if (!$this->isPathResolvable($path)) {
            throw ResourceNotFoundException::forPath($path);
        }

        return !$this->getDirectChildren($this->sanitizePath($path), true)->isEmpty();
    }

    /**
     * {@inheritdoc}
     */
    protected function addFilesystemResource($path, FilesystemResource $resource)
    {
        $resource->attachTo($this, $path);
        $this->storeUnshift($path, Path::makeRelative($resource->getFilesystemPath(), $this->baseDirectory));
    }

    /**
     * {@inheritdoc}
     */
    protected function addLinkResource($path, LinkResource $resource)
    {
        $resource->attachTo($this, $path);
        $this->storeUnshift($path, 'l:'.$resource->getTargetPath());
    }

    /**
     * Add a target path (link or filesystem path) to the beginning of the stack in the store at a path.
     *
     * @param string $path
     * @param string $targetPath
     */
    private function storeUnshift($path, $targetPath)
    {
        $previousPaths = array();

        if ($this->store->exists($path)) {
            $previousPaths = (array) $this->store->get($path);
        }

        if (!in_array($targetPath, $previousPaths, true)) {
            array_unshift($previousPaths, $targetPath);
        }

        $this->store->set($path, $previousPaths);
    }

    /**
     * Return the filesystem path associated to the given repository path
     * or null if no filesystem path is found.
     *
     * @param string $path      The repository path.
     * @param bool   $onlyFirst Should the method stop on the first path found?
     *
     * @return string[]|null[]
     */
    private function resolveFilesystemPaths($path, $onlyFirst = true)
    {
        /*
         * If the path exists in the store, return it directly
         */
        if ($this->store->exists($path)) {
            $filesystemPaths = $this->store->get($path);

            if (is_array($filesystemPaths) && count($filesystemPaths) > 0) {
                if ($onlyFirst) {
                    return $this->resolveRelativePaths(array(reset($filesystemPaths)));
                }

                return $this->resolveRelativePaths($filesystemPaths);
            }

            return array(null);
        }

        /*
         * Otherwise, we need to "resolve" it in two steps:
         *      1.  find the resources from the store that are potential parents
         *          of the path (we filter them using Path::isBasePath)
         *      2.  for each of these potential parent, we try to find a real
         *          file or directory on the filesystem and if we do find one,
         *          we stop
         */
        $basePaths = array_reverse($this->store->keys());
        $filesystemPaths = array();

        foreach ($basePaths as $key => $basePath) {
            if (!Path::isBasePath($basePath, $path)) {
                continue;
            }

            $filesystemBasePaths = $this->resolveRelativePaths((array) $this->store->get($basePath));
            $basePathLength = strlen(rtrim($basePath, '/').'/');

            foreach ($filesystemBasePaths as $filesystemBasePath) {
                $filesystemBasePath = rtrim($filesystemBasePath, '/').'/';
                $filesystemPath = substr_replace($path, $filesystemBasePath, 0, $basePathLength);

                // File
                if (file_exists($filesystemPath)) {
                    $filesystemPaths[] = $filesystemPath;

                    if ($onlyFirst) {
                        return $this->resolveRelativePaths($filesystemPaths);
                    }
                }

                // Link
                if (0 === strpos($filesystemPath, 'l:')) {
                    $filesystemPaths[] = ltrim($filesystemPath, 'l:');
                }
            }
        }

        return $this->resolveRelativePaths($filesystemPaths);
    }

    /**
     * Search for resources by querying their path.
     *
     * @param string $query        The glob query.
     * @param bool   $singleResult Should this method stop after finding a
     *                             first result, for performances.
     *
     * @return ArrayResourceCollection The results of search.
     */
    private function search($query, $singleResult = false)
    {
        $resources = new ArrayResourceCollection();

        // If the query is not a glob, return it directly
        if (!Glob::isDynamic($query)) {
            $filesystemPaths = $this->resolveFilesystemPaths($query);

            if (count($filesystemPaths) > 0) {
                $resources->add($this->createResource(reset($filesystemPaths), $query));
            }

            return $resources;
        }

        // If the glob is dynamic, we search
        $children = $this->getRecursiveChildren(Glob::getBasePath($query));

        foreach ($children as $path => $filesystemPath) {
            if (Glob::match($path, $query)) {
                $resources->add($this->createResource($filesystemPath, $path));

                if ($singleResult) {
                    return $resources;
                }
            }
        }

        return $resources;
    }

    /**
     * Get all the tree of children under given repository path.
     *
     * @param string $path The repository path.
     *
     * @return array
     */
    private function getRecursiveChildren($path)
    {
        $children = array();

        /*
         * Children of a given path either come from real filesystem children
         * or from other mappings (virtual resources).
         *
         * First we check for the real children.
         */
        $filesystemPaths = $this->resolveFilesystemPaths($path, false);

        foreach ($filesystemPaths as $filesystemPath) {
            $filesystemChildren = $this->getFilesystemPathChildren($path, $filesystemPath, true);

            foreach ($filesystemChildren as $filesystemChild) {
                $children[$filesystemChild['path']] = $filesystemChild['filesystemPath'];
            }
        }

        /*
         * Then we add the children of other path mappings.
         * These other path mappings should override possible precedent real children.
         */
        $virtualChildren = $this->getVirtualPathChildren($path, true);

        foreach ($virtualChildren as $virtualChild) {
            $children[$virtualChild['path']] = $virtualChild['filesystemPath'];

            if ($virtualChild['filesystemPath'] && file_exists($virtualChild['filesystemPath'])) {
                $filesystemChildren = $this->getFilesystemPathChildren(
                    $virtualChild['path'],
                    $virtualChild['filesystemPath'],
                    true
                );

                foreach ($filesystemChildren as $filesystemChild) {
                    $children[$filesystemChild['path']] = $filesystemChild['filesystemPath'];
                }
            }
        }

        return $children;
    }

    /**
     * Get the direct children of the given repository path.
     *
     * @param string $path         The repository path.
     * @param bool   $singleResult Should this method stop after finding a
     *                             first result, for performances.
     *
     * @return ArrayResourceCollection
     */
    private function getDirectChildren($path, $singleResult = false)
    {
        $children = array();

        /*
         * Children of a given path either come from real filesystem children
         * or from other mappings (virtual resources).
         *
         * First we check for the real children.
         */
        $filesystemPaths = $this->resolveFilesystemPaths($path, false);

        foreach ($filesystemPaths as $filesystemPath) {
            $filesystemChildren = $this->getFilesystemPathChildren($path, $filesystemPath, false);

            foreach ($this->createResources($filesystemChildren) as $child) {
                if ($singleResult) {
                    return new ArrayResourceCollection(array($child));
                }

                $children[$child->getPath()] = $child;
            }
        }

        /*
         * Then we add the children of other path mappings.
         * These other path mappings should override possible precedent real children.
         */
        $virtualChildren = $this->createResources($this->getVirtualPathChildren($path, false));

        foreach ($virtualChildren as $child) {
            if ($singleResult) {
                return new ArrayResourceCollection(array($child));
            }

            if ($child->getPath() !== $path) {
                $children[$child->getPath()] = $child;
            }
        }

        return new ArrayResourceCollection(array_values($children));
    }

    /**
     * Find the children paths of a given filesystem path.
     *
     * @param string $repositoryPath The repository path
     * @param string $filesystemPath The filesystem path
     * @param bool   $recursive      Should the method do a recursive listing?
     *
     * @return array The children paths.
     */
    private function getFilesystemPathChildren($repositoryPath, $filesystemPath, $recursive = false)
    {
        if (!is_dir($filesystemPath)) {
            return array();
        }

        $iterator = new RecursiveDirectoryIterator(
            $filesystemPath,
            FilesystemIterator::KEY_AS_PATHNAME
            | FilesystemIterator::CURRENT_AS_FILEINFO
            | FilesystemIterator::SKIP_DOTS
        );

        if ($recursive) {
            $iterator = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::SELF_FIRST);
        }

        $childrenFilesystemPaths = array_keys(iterator_to_array($iterator));

        // RecursiveDirectoryIterator is not guaranteed to return sorted results
        sort($childrenFilesystemPaths);

        $children = array();

        foreach ($childrenFilesystemPaths as $childFilesystemPath) {
            $childFilesystemPath = Path::canonicalize($childFilesystemPath);

            $childRepositoryPath = preg_replace(
                '~^'.preg_quote(rtrim($filesystemPath, '/').'/', '~').'~',
                rtrim($repositoryPath, '/').'/',
                $childFilesystemPath
            );

            $children[] = array('path' => $childRepositoryPath, 'filesystemPath' => $childFilesystemPath);
        }

        return $children;
    }

    /**
     * Find the children paths of a given virtual path.
     *
     * @param string $repositoryPath The repository path
     * @param bool   $recursive      Should the method do a recursive listing?
     *
     * @return array The children paths.
     */
    private function getVirtualPathChildren($repositoryPath, $recursive = false)
    {
        $staticPrefix = rtrim($repositoryPath, '/').'/';
        $regExp = '~^'.preg_quote($staticPrefix, '~');

        if ($recursive) {
            $regExp .= '.*$~';
        } else {
            $regExp .= '[^/]*$~';
        }

        $iterator = new RegexFilterIterator(
            $regExp,
            $staticPrefix,
            new ArrayIterator($this->store->keys())
        );

        $children = array();

        foreach ($iterator as $path) {
            $filesystemPaths = $this->store->get($path);

            if (!is_array($filesystemPaths)) {
                $children[] = array('path' => $path, 'filesystemPath' => null);
                continue;
            }

            foreach ($filesystemPaths as $filesystemPath) {
                $children[] = array('path' => $path, 'filesystemPath' => $this->resolveRelativePath($filesystemPath));
            }
        }

        return $children;
    }

    /**
     * Create an array of resources using an internal array of children.
     *
     * @param array $children
     *
     * @return array
     */
    private function createResources($children)
    {
        $resources = array();

        foreach ($children as $child) {
            $resources[] = $this->createResource($child['filesystemPath'], $child['path']);
        }

        return $resources;
    }

    /**
     * Check a given path is resolvable.
     *
     * @param string $path
     *
     * @return bool
     *
     * @throws ResourceNotFoundException
     */
    private function isPathResolvable($path)
    {
        return count($this->resolveFilesystemPaths($this->sanitizePath($path))) > 0;
    }
}
