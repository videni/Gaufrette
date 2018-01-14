<?php

namespace Gaufrette\Adapter;

use Gaufrette\Adapter;
use Gaufrette\Exception\FileNotFound;
use Gaufrette\Exception\StorageFailure;
use Gaufrette\Util;
use OpenStack\Common\Error\BadResponseError;
use OpenStack\ObjectStore\v1\Models\Container;
use OpenStack\ObjectStore\v1\Models\Object;
use OpenStack\ObjectStore\v1\Service;

/**
 * OpenCloud adapter.
 *
 * @author  James Watson <james@sitepulse.org>
 * @author  Daniel Richter <nexyz9@gmail.com>
 * @author  Nicolas MURE <nicolas.mure@knplabs.com>
 *
 * @see http://docs.os.php-opencloud.com/en/latest/services/object-store/v1/objects.html
 * @see http://refdocs.os.php-opencloud.com/OpenStack/OpenStack.html
 */
class OpenCloud implements Adapter,
                           ChecksumCalculator,
                           ListKeysAware,
                           MetadataSupporter,
                           MimeTypeProvider,
                           SizeCalculator
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
            if ($this->objectStore->containerExists($this->containerName)) {
                return $this->container = $this->objectStore->getContainer($this->containerName);
            }

            if (!$this->createContainer) {
                throw new \RuntimeException(sprintf('Container "%s" does not exist.', $this->containerName));
            }

            try {
                return $this->container = $this->objectStore->createContainer(['name' => $this->containerName]);
            } catch (BadResponseError $e) {
                throw new \RuntimeException(
                    sprintf('Container "%s" could not be created (HTTP %d response received)', $this->containerName, $e->getResponse()->getStatusCode())
                );
            }
        } catch (BadResponseError $e) {
            // non 404 status error received
            throw new \RuntimeException(
                sprintf('HTTP %d response received when checking the existence of the container "%s"', $e->getResponse()->getStatusCode(), $this->containerName)
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read($key)
    {
        try {
            /** @var \Psr\Http\Message\StreamInterface $stream */
            // @WARNING: This could attempt to load a large amount of data into memory.
            return (string) $this->getObject($key)->download();
        } catch (BadResponseError $e) {
            if (404 === $e->getResponse()->getStatusCode()) {
                throw new FileNotFound($key);
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
            $this->getContainer()->createObject([
                'name' => $key,
                'content' => $content,
            ]);
        } catch (BadResponseError $e) {
            throw StorageFailure::unexpectedFailure(
                'write',
                ['key' => $key, 'content' => $content],
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function exists($key)
    {
        try {
            return $this->getContainer()->objectExists($key);
        } catch (BadResponseError $e) {
            throw StorageFailure::unexpectedFailure('exists', ['key' => $key], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function keys()
    {
        try {
            return array_map(function (Object $object) {
                return $object->name;
            }, iterator_to_array($this->getContainer()->listObjects()));
        } catch (BadResponseError $e ) {
            throw StorageFailure::unexpectedFailure('keys', [], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function listKeys($prefix = '')
    {
        try {
            return array_filter($this->keys(), function ($key) use ($prefix) {
                return 0 === strpos($key, $prefix);
            });
        } catch (StorageFailure $e) {
            throw StorageFailure::unexpectedFailure('listKeys', ['prefix' => $prefix], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function mtime($key)
    {
        try {
            return (new \DateTime($this->retrieveObject($key)->lastModified))->format('U');
        } catch (BadResponseError $e) {
            if (404 === $e->getResponse()->getStatusCode()) {
                throw new FileNotFound($key);
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
        } catch (BadResponseError $e) {
            if (404 === $e->getResponse()->getStatusCode()) {
                throw new FileNotFound($key);
            }

            throw StorageFailure::unexpectedFailure('delete', ['key' => $key], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rename($sourceKey, $targetKey)
    {
        if ($this->exists($targetKey)) {
            throw StorageFailure::unexpectedFailure(
                'rename',
                ['sourceKey' => $sourceKey, 'targetKey' => $targetKey]
            );
        }

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
            return $this->retrieveObject($key)->hash;
        } catch (BadResponseError $e) {
            if (404 === $e->getResponse()->getStatusCode()) {
                throw new FileNotFound($key);
            }

            throw StorageFailure::unexpectedFailure('checksum', ['key' => $key], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($key)
    {
        try {
            return $this->getObject($key)->getMetadata();
        } catch (BadResponseError $e) {
            if (404 === $e->getResponse()->getStatusCode()) {
                throw new FileNotFound($key);
            }

            throw StorageFailure::unexpectedFailure('getMetadata', [], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setMetadata($key, $content)
    {
        try {
            $this->getObject($key)->resetMetadata($content);
        } catch (BadResponseError $e) {
            if (404 === $e->getResponse()->getStatusCode()) {
                throw new FileNotFound($key);
            }

            throw StorageFailure::unexpectedFailure(
                'setMetadata',
                ['key' => $key, 'content' => $content],
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function mimeType($key)
    {
        try {
            return $this->retrieveObject($key)->contentType;
        } catch (BadResponseError $e) {
            if (404 === $e->getResponse()->getStatusCode()) {
                throw new FileNotFound($key);
            }

            throw StorageFailure::unexpectedFailure('mimeType', ['key' => $key], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function size($key)
    {
        try {
            return $this->retrieveObject($key)->contentLength;
        } catch (BadResponseError $e) {
            if (404 === $e->getResponse()->getStatusCode()) {
                throw new FileNotFound($key);
            }

            throw StorageFailure::unexpectedFailure('size', ['key' => $key], $e);
        }
    }

    /**
     * Shortcut to get an object from the container.
     * This function will NOT perform an HTTP request.
     *
     * @param string $key
     *
     * @throws BadResponseError
     *
     * @return Object
     */
    protected function getObject($key)
    {
        return $this->getContainer()->getObject($key);
    }

    /**
     * Shortcut to get an object from the container.
     * The returned object will have its infos available (but not its content).
     * This function WILL perform an HTTP request.
     *
     * @param string $key
     *
     * @throws BadResponseError
     *
     * @return Object
     */
    protected function retrieveObject($key)
    {
        $object = $this->getObject($key);
        $object->retrieve();

        return $object;
    }
}
