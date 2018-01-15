<?php

namespace Gaufrette\Functional\Adapter;

use Gaufrette\Adapter\OpenCloud;
use Gaufrette\Filesystem;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use OpenStack\Identity\v2\Service as IdentityService;
use OpenStack\OpenStack;

class OpenCloudTest extends FunctionalTestCase
{
    /** @var \OpenStack\ObjectStore\v1\Service */
    private $objectStore;

    /** @var string */
    private $container;

    public function setUp()
    {
        $username = getenv('RACKSPACE_USERNAME') ?: '';
        $password = getenv('RACKSPACE_PASSWORD') ?: '';
        $tenantId = getenv('RACKSPACE_TENANT_ID') ?: '';
        $region = getenv('RACKSPACE_REGION') ?: '';

        if (empty($username) || empty($password) || empty($tenantId) || empty($region)) {
            $this->markTestSkipped('Either RACKSPACE_USERNAME, RACKSPACE_PASSWORD, RACKSPACE_TENANT_ID and/or RACKSPACE_REGION env vars are missing.');
        }

        $authUrl = 'https://identity.api.rackspacecloud.com/v2.0/';

        /*
         * Rackspace uses OpenStack Identity v2
         * @see https://github.com/php-opencloud/openstack/issues/127
         */
        $this->container = uniqid('gaufretteci');
        $this->objectStore = (new OpenStack([
                'username' => $username,
                'password' => $password,
                'tenantId' => $tenantId,
                'authUrl' => $authUrl,
                'region' => $region,
                'identityService' => IdentityService::factory(
                    new Client([
                        'base_uri' => $authUrl,
                        'handler' => HandlerStack::create(),
                    ])
                ),
            ]))
            ->objectStoreV1([
                'catalogName' => 'cloudFiles',
            ]);

        $this->objectStore->createContainer([
            'name' => $this->container,
        ]);
        $adapter = new OpenCloud($this->objectStore, $this->container);
        $this->filesystem = new Filesystem($adapter);
    }

    public function tearDown()
    {
        if ($this->filesystem === null) {
            return;
        }

        // rackspace container must be empty to be deleted
        array_map(function ($key) {
            $this->filesystem->delete($key);
        }, $this->filesystem->keys());

        $this->objectStore->getContainer($this->container)->delete();
    }

    /**
     * @test
     * @group functional
     */
    public function shouldGetChecksum()
    {
        $this->filesystem->write('foo', 'Some content');

        $this->assertEquals(md5('Some content'), $this->filesystem->checksum('foo'));
    }

    /**
     * @test
     * @group functional
     */
    public function shouldGetSize()
    {
        $this->filesystem->write('foo', 'Some content');

        $this->assertEquals(strlen('Some content'), $this->filesystem->size('foo'));
    }

    /**
     * @test
     * @group functional
     */
    public function shouldGetMimeType()
    {
        $this->filesystem->write('foo.txt', 'Some content');

        $this->assertEquals('text/plain', $this->filesystem->mimeType('foo.txt'));
    }

    /**
     * @test
     * @group functional
     */
    public function shouldSetAndGetMetadata()
    {
        $this->filesystem->write('test.txt', 'Some content');
        $this->filesystem->getAdapter()->setMetadata('test.txt', [
            'Some-Meta' => 'foo',
            'Custom-Stuff' => 'bar',
        ]);

        $this->assertEquals([
            'Some-Meta' => 'foo',
            'Custom-Stuff' => 'bar',
        ], $this->filesystem->getAdapter()->getMetadata('test.txt'));
    }

    /*
     * OVERWRITE PARENT FUNCTIONS
     * @TODO : REMOVE THE BELOW OVERWRITES WHEN
     *         https://github.com/KnpLabs/Gaufrette/pull/521 WILL BE MERGED.
     */

    /**
     * @test
     * @group functional
     */
    public function shouldWriteAndRead()
    {
        $this->filesystem->write('foo', 'Some content');
        $this->filesystem->write('test/subdir/foo', 'Some content1', true);

        $this->assertEquals('Some content', $this->filesystem->read('foo'));
        $this->assertEquals('Some content1', $this->filesystem->read('test/subdir/foo'));
    }
}
