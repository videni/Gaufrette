<?php

namespace Gaufrette\Adapter;

use Gaufrette\Adapter;
use Gaufrette\Exception\FileNotFound;
use Gaufrette\Exception\StorageFailure;
use Gaufrette\Util;
use Guzzle\Http\Exception\BadResponseException;
use OpenCloud\ObjectStore\Resource\Container;
use OpenCloud\ObjectStore\Service;
use OpenCloud\ObjectStore\Exception\ObjectNotFoundException;

/**
 * OpenCloud adapter.
 *
 * @author  James Watson <james@sitepulse.org>
 * @author  Daniel Richter <nexyz9@gmail.com>
 */
class OpenCloud implements Adapter,
                           ChecksumCalculator
{
    /**
     * @var Service
     */
    protected $objectStore;

    /**
     * @var string
     */
    protected $containerName;

    /**
     * @var bool
     */
    protected $createContainer;

    /**
     * @var Container
     */
    protected $container;

    /**
     * @param Service $objectStore
     * @param string  $containerName   The name of the container
     * @param bool    $createContainer Whether to create the container if it does not exist
     */
    public function __construct(Service $objectStore, $containerName, $createContainer = false)
    {
        $this->objectStore = $objectStore;
        $this->containerName = $containerName;
        $this->createContainer = $createContainer;
    }

    /**
     * Returns an initialized container.
     *
     * @throws \RuntimeException
     *
     * @return Container
     */
    protected function getContainer()
    {
        if ($this->container) {
            return $this->container;
        }

        try {
            return $this->container = $this->objectStore->getContainer($this->containerName);
        } catch (BadResponseException $e) { //OpenCloud lib does not wrap this exception
            if (!$this->createContainer) {
                throw new \RuntimeException(sprintf('Container "%s" does not exist.', $this->containerName));
            }
        }

        if (!$container = $this->objectStore->createContainer($this->containerName)) {
            throw new \RuntimeException(sprintf('Container "%s" could not be created.', $this->containerName));
        }

        return $this->container = $container;
    }

    /**
     * {@inheritdoc}
     */
    public function read($key)
    {
        try {
            return $this->getObject($key)->getContent();
        } catch (\Exception $e) {
            if ($e instanceof ObjectNotFoundException) {
                throw new FileNotFound($key, $e->getCode(), $e);
            }

            throw StorageFailure::unexpectedFailure('read', ['key' => $key], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function write($key, $content)
    {
        try {
            $this->getContainer()->uploadObject($key, $content);
        } catch (\Exception $e) {
            throw StorageFailure::unexpectedFailure(
                'write',
                ['key' => $key, 'content' => $content],
                $e
            );
        }

        return Util\Size::fromContent($content);
    }

    /**
     * {@inheritdoc}
     */
    public function exists($key)
    {
        try {
            return $this->getContainer()->getPartialObject($key) !== false;
        } catch (\Exception $e) {
            if ($e instanceof BadResponseException) {
                return false;
            }

            throw StorageFailure::unexpectedFailure('exists', ['key' => $key], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function keys()
    {
        try {
            $objectList = $this->getContainer()->objectList();
            $keys = array();

            while ($object = $objectList->next()) {
                $keys[] = $object->getName();
            }

            sort($keys);

            return $keys;
        } catch (\Exception $e) {
            throw StorageFailure::unexpectedFailure('keys', [], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function mtime($key)
    {
        try {
            $object = $this->getObject($key);

            return (new \DateTime($object->getLastModified()))->format('U');
        } catch (\Exception $e) {
            if ($e instanceof ObjectNotFoundException) {
                throw new FileNotFound($key, $e->getCode(), $e);
            }

            throw StorageFailure::unexpectedFailure('mtime', ['key' => $key], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        try {
            $this->getObject($key)->delete();
        } catch (\Exception $e) {
            if ($e instanceof ObjectNotFoundException) {
                throw new FileNotFound($key, $e->getCode(), $e);
            }

            throw StorageFailure::unexpectedFailure('delete', ['key' => $key], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rename($sourceKey, $targetKey)
    {
        $this->write($targetKey, $this->read($sourceKey));

        $this->delete($sourceKey);
    }

    /**
     * {@inheritdoc}
     */
    public function isDirectory($key)
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function checksum($key)
    {
        try {
            return $this->getObject($key)->getETag();
        } catch (\Exception $e) {
            if ($e instanceof ObjectNotFoundException) {
                throw new FileNotFound($key, $e->getCode(), $e);
            }

            throw StorageFailure::unexpectedFailure('checksum', ['key' => $key], $e);
        }
    }

    /**
     * @param string $key
     *
     * @throws \OpenCloud\ObjectStore\Exception\ObjectNotFoundException
     *
     * @return \OpenCloud\ObjectStore\Resource\DataObject
     */
    protected function getObject($key)
    {
        return $this->getContainer()->getObject($key);
    }
}
