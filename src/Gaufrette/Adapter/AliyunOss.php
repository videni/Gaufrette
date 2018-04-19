<?php

namespace Gaufrette\Adapter;

use Gaufrette\Adapter;
use OSS\OssClient;
use Gaufrette\Util;
use Gaufrette\Adapter\MetadataSupporter;
use Gaufrette\Adapter\ListKeysAware;
use Gaufrette\Adapter\MimeTypeProvider;
use Gaufrette\Adapter\SizeCalculator;

/**
 * Aliyun OSS  adapter using aliyuncs/oss-sdk-php
 *
 * @author vidy.videni@gmail.com
 */
class AliyunOss implements
    Adapter,
    MetadataSupporter,
    ListKeysAware,
    SizeCalculator,
    MimeTypeProvider
{
    /** @var OssClient */
    protected $service;
    /** @var string */
    protected $bucket;
    /** @var array */
    protected $options;
    /** @var bool */
    protected $bucketExists;
    /** @var array */
    protected $metadata = [];
    /** @var bool */
    protected $detectContentType;

    /**
     * @param OssClient $service
     * @param string   $bucket
     * @param array    $options
     * @param bool     $detectContentType
     */
    public function __construct(OssClient $service, $bucket, array $options = [], $detectContentType = false)
    {
        $this->service = $service;
        $this->bucket = $bucket;
        $this->options = array_replace(
            [
                'create' => false,
                'directory' => '',
                'acl' => 'private',
            ],
            $options
        );

        $this->detectContentType = $detectContentType;
    }

    /**
     * {@inheritdoc}
     */
    public function setMetadata($key, $metadata)
    {
        $this->metadata[$key] = $metadata;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($key)
    {
        return isset($this->metadata[$key]) ? $this->metadata[$key] : [];
    }

    /**
     * {@inheritdoc}
     */
    public function read($key)
    {
        $this->ensureBucketExists();

        try {
            // Get remote object
            $object = $this->service->getObject($this->bucket, $this->computePath($key), $this->options);
            // If there's no metadata array set up for this object, set it up
            if (!array_key_exists($key, $this->metadata) || !is_array($this->metadata[$key])) {
                $this->metadata[$key] = [];
            }
            // Make remote ContentType metadata available locally
            $this->metadata[$key]['ContentType'] = $this->guessContentType($object);

            return  $object;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rename($sourceKey, $targetKey)
    {
        $this->ensureBucketExists();

        try {
            $this->service->copyObject($this->bucket, $this->computePath($sourceKey), $this->bucket, $this->computePath($targetKey), $this->options);

            return $this->delete($this->computePath($sourceKey));
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function write($key, $content)
    {
        $this->ensureBucketExists();

        /*
         * If the ContentType was not already set in the metadata, then we autodetect
         * it to prevent everything being served up as binary/octet-stream.
         */
        if (!isset($options['ContentType']) && $this->detectContentType) {
            $options['ContentType'] = $this->guessContentType($content);
        }

        try {
            $this->service->putObject($this->bucket, $this->computePath($key), $content, $this->options);

            if (is_resource($content)) {
                return Util\Size::fromResource($content);
            }

            return Util\Size::fromContent($content);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function exists($key)
    {
        return $this->service->doesObjectExist($this->bucket, $this->computePath($key), $this->options);
    }

    /**
     * {@inheritdoc}
     */
    public function mtime($key)
    {
        try {
            $result = $this->service->getObjectMeta($this->getOptions($key));

            return strtotime($result['LastModified']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function size($key)
    {
        try {
            $result = $this->service->getObjectMeta($this->getOptions($key));

            return $result['ContentLength'];
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function keys()
    {
        return $this->listKeys();
    }

    /**
     * {@inheritdoc}
     */
    public function listKeys($prefix = '')
    {
        $this->ensureBucketExists();

        $options = [OssClient::OSS_BUCKET => $this->bucket];
        if ((string) $prefix != '') {
            $options['Prefix'] = $this->computePath($prefix);
        } elseif (!empty($this->options['directory'])) {
            $options['Prefix'] = $this->options['directory'];
        }

        $keys = [];

        /**
         * @var OSS\Model\ObjectListInfo $objectListInfo
         */
        $objectListInfo = $this->service->ListObjects($this->bucket, $options);
        foreach ($objectListInfo->getObjectList() as $objectInfo) {
            $keys[] = $objectInfo->key;
        }

        return $keys;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        try {
            $this->service->deleteObject($this->bucket, $this->computePath($key));

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isDirectory($key)
    {
        $result = $this->service->listObjects([
            OssClient::OSS_BUCKET => $this->bucket,
            'Prefix' => rtrim($this->computePath($key), '/').'/',
            'MaxKeys' => 1,
        ]);
        if (isset($result['Contents'])) {
            if (is_array($result['Contents']) || $result['Contents'] instanceof \Countable) {
                return count($result['Contents']) > 0;
            }
        }

        return false;
    }

    /**
     *
     * The user may not have permission to check if a bucket existed, so alway return true
     *
     */
    protected function ensureBucketExists()
    {
        if ($this->bucketExists) {
            return true;
        }

        if ($this->bucketExists = $this->service->doesBucketExist($this->bucket)) {
            return true;
        }

        if (!$this->options['create']) {
            throw new \RuntimeException(sprintf(
                'The configured bucket "%s" does not exist.',
                $this->bucket
            ));
        }

        $this->service->createBucket($this->bucket, $this->options['acl'], $this->options);
        $this->bucketExists = true;

        return true;
    }

    protected function getOptions($key, array $options = [])
    {
        $options[OssClient::OSS_ACL] = $this->options['acl'];
        $options[OssClient::OSS_BUCKET] = $this->bucket;
        $options[OssClient::OSS_OBJECT] = $this->computePath($key);

        /*
         * Merge global options for adapter, which are set in the constructor, with metadata.
         * Metadata will override global options.
         */
        $options = array_merge($this->options, $options, $this->getMetadata($key));

        return $options;
    }

    protected function computePath($key)
    {
        if (empty($this->options['directory'])) {
            return $key;
        }

        return sprintf('%s/%s', $this->options['directory'], $key);
    }

    /**
     * Computes the key from the specified path.
     *
     * @param string $path
     *
     * return string
     */
    protected function computeKey($path)
    {
        return ltrim(substr($path, strlen($this->options['directory'])), '/');
    }

    /**
     * @param string $content
     *
     * @return string
     */
    private function guessContentType($content)
    {
        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);

        if (is_resource($content)) {
            return $fileInfo->file(stream_get_meta_data($content)['uri']);
        }

        return $fileInfo->buffer($content);
    }

    public function mimeType($key)
    {
        try {
            $result = $this->service->getObjectMeta($this->getOptions($key));
            return ($result['ContentType']);
        } catch (\Exception $e) {
            return false;
        }
    }
}
