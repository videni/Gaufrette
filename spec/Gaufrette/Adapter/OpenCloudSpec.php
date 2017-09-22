<?php

namespace spec\Gaufrette\Adapter;

use Guzzle\Http\Exception\BadResponseException;
use OpenCloud\Common\Collection;
use OpenCloud\Common\Exceptions\CreateUpdateError;
use OpenCloud\Common\Exceptions\DeleteError;
use OpenCloud\ObjectStore\Exception\ObjectNotFoundException;
use OpenCloud\ObjectStore\Resource\Container;
use OpenCloud\ObjectStore\Resource\DataObject;
use OpenCloud\ObjectStore\Service;
use PhpSpec\ObjectBehavior;

/**
 * OpenCloudSpec
 *
 * @author  Chris Warner <cdw.lighting@gmail.com>
 * @author  Daniel Richter <nexyz9@gmail.com>
 */
class OpenCloudSpec extends ObjectBehavior
{
    function let(Service $objectStore, Container $container)
    {
        $objectStore->getContainer('test')->willReturn($container);
        $this->beConstructedWith($objectStore, 'test', false);
    }

    function it_is_adapter()
    {
        $this->shouldHaveType('Gaufrette\Adapter');
    }

    function it_reads_file(Container $container, DataObject $object)
    {
        $object->getContent()->willReturn('Hello World');
        $container->getObject('test')->willReturn($object);

        $this->read('test')->shouldReturn('Hello World');
    }

    function it_throws_file_not_found_exception_when_trying_to_read_an_unexisting_file(Container $container)
    {
        $container->getObject('test')->willThrow(new ObjectNotFoundException());

        $this->shouldThrow('Gaufrette\Exception\FileNotFound')->duringread('test');
    }

    function it_turns_exception_into_storage_failure_while_reading_a_file(Container $container)
    {
        $container->getObject('test')->willThrow(new \Exception('test'));

        $this->shouldThrow('Gaufrette\Exception\StorageFailure')->duringread('test');
    }

    function it_writes_file_returns_size(Container $container, DataObject $object)
    {
        $testData     = 'Hello World!';
        $testDataSize = strlen($testData);

        $object->getContentLength()->willReturn($testDataSize);
        $container->uploadObject('test', $testData)->willReturn($object);

        $this->write('test', $testData)->shouldReturn($testDataSize);
    }

    function it_turns_exception_into_storage_failure_while_writing_a_file(Container $container)
    {
        $testData = 'Hello World!';

        $container->uploadObject('test', $testData)->willThrow(new CreateUpdateError());

        $this->shouldThrow('Gaufrette\Exception\StorageFailure')->duringwrite('test', $testData);
    }

    function it_returns_true_if_key_exists(Container $container, DataObject $object)
    {
        $container->getPartialObject('test')->willReturn($object);

        $this->exists('test')->shouldReturn(true);
    }

    function it_returns_false_if_key_does_not_exist(Container $container)
    {
        $container->getPartialObject('test')->willReturn(false);

        $this->exists('test')->shouldReturn(false);
    }

    function it_returns_false_if_key_does_not_exist_due_to_bad_response(Container $container)
    {
        $container->getPartialObject('test')->willThrow(new BadResponseException());

        $this->exists('test')->shouldReturn(false);
    }

    function it_turns_exception_into_storage_failure_while_checking_if_file_exists(Container $container)
    {
        $container->getPartialObject('test')->willThrow(new \Exception('test'));

        $this->shouldThrow('Gaufrette\Exception\StorageFailure')->duringexists('test');
    }

    function it_deletes_file(Container $container, DataObject $object)
    {
        $object->delete()->willReturn(null);
        $container->getObject('test')->willReturn($object);

        $this->delete('test')->shouldReturn(null);
    }

    function it_throws_file_not_found_exception_when_trying_to_delete_an_unexisting_file(Container $container, DataObject $object)
    {
        $object->delete()->willThrow(new ObjectNotFoundException());
        $container->getObject('test')->willReturn($object);

        $this->shouldThrow('Gaufrette\Exception\FileNotFound')->duringdelete('test');
    }

    function it_turns_exception_into_storage_failure_while_deleting_a_file(Container $container)
    {
        $container->getObject('test')->willThrow(new \Exception('test'));

        $this->shouldThrow('Gaufrette\Exception\StorageFailure')->duringdelete('test');
    }

    function it_renames_file(Container $container, DataObject $source, DataObject $dest)
    {
        $testData     = 'Hello World!';
        $testDataSize = strlen($testData);

        $container->getPartialObject('dest')->willReturn(false);

        $source->getContent()->willReturn($testData);
        $container->getObject('source')->willReturn($source);
        $source->delete()->willReturn(null);

        $dest->getContentLength()->willReturn($testDataSize);
        $container->uploadObject('dest', $testData)->willReturn($dest);

        $this->rename('source', 'dest')->shouldReturn(null);
    }

    function it_throws_storage_failure_when_dest_already_exists_during_rename(Container $container, DataObject $dest)
    {
        $container->getPartialObject('dest')->willReturn($dest);

        $this->shouldThrow('Gaufrette\Exception\StorageFailure')->duringrename('source', 'dest');
    }

    function it_returns_checksum(Container $container, DataObject $object)
    {
        $object->getEtag()->willReturn('test String');
        $container->getObject('test')->willReturn($object);

        $this->checksum('test')->shouldReturn('test String');
    }

    function it_throws_file_not_found_exception_when_trying_to_get_the_checksum_of_an_unexisting_file(Container $container)
    {
        $container->getObject('test')->willThrow(new ObjectNotFoundException());

        $this->shouldThrow('Gaufrette\Exception\FileNotFound')->duringchecksum('test');
    }

    function it_turns_exception_into_storage_failure_while_getting_the_checksum_of_a_file(Container $container)
    {
        $container->getObject('test')->willThrow(new \Exception('test'));

        $this->shouldThrow('Gaufrette\Exception\StorageFailure')->duringchecksum('test');
    }

    function it_returns_files_as_sorted_array(Container $container, Collection $objectList, DataObject $object1, DataObject $object2, DataObject $object3)
    {
        $outputArray = array('key1', 'key2', 'key5');
        $index = 0;

        $object1->getName()->willReturn('key5');
        $object2->getName()->willReturn('key2');
        $object3->getName()->willReturn('key1');

        $objects = array($object1, $object2, $object3);

        $objectList->next()->will(
                   function () use ($objects, &$index) {
                       if ($index < count($objects)) {
                           $index++;

                           return $objects[$index - 1];
                       }
                   }
        )          ->shouldBeCalledTimes(count($objects) + 1);

        $container->objectList()->willReturn($objectList);

        $this->keys()->shouldReturn($outputArray);
    }

    function it_throws_exception_if_container_does_not_exist(Service $objectStore)
    {
        $containerName = 'container-does-not-exist';

        $objectStore->getContainer($containerName)->willThrow(new BadResponseException());
        $this->beConstructedWith($objectStore, $containerName);

        $this->shouldThrow('\RuntimeException')->duringExists('test');
    }

    function it_creates_container(Service $objectStore, Container $container)
    {
        $containerName = 'container-does-not-yet-exist';
        $filename = 'test';

        $objectStore->getContainer($containerName)->willThrow(new BadResponseException());
        $objectStore->createContainer($containerName)->willReturn($container);
        $container->getPartialObject($filename)->willThrow(new BadResponseException());

        $this->beConstructedWith($objectStore, $containerName, true);

        $this->exists($filename)->shouldReturn(false);
    }

    function it_throws_exeption_if_container_creation_fails(Service $objectStore)
    {
        $containerName = 'container-does-not-yet-exist';

        $objectStore->getContainer($containerName)->willThrow(new BadResponseException());
        $objectStore->createContainer($containerName)->willReturn(false);

        $this->beConstructedWith($objectStore, $containerName, true);

        $this->shouldThrow('\RuntimeException')->duringExists('test');
    }

    function it_fetches_mtime(DataObject $object, Container $container)
    {
        $container->getObject('foo')->willReturn($object);
        $object->getLastModified()->willReturn('Tue, 13 Jun 2017 22:02:34 GMT');

        $this->mtime('foo')->shouldReturn('1497391354');
    }

    function it_throws_file_not_found_exception_when_trying_to_fetch_the_mtime_of_an_unexisting_file(Container $container)
    {
        $container->getObject('foo')->willThrow(ObjectNotFoundException::class);

        $this->shouldThrow('Gaufrette\Exception\FileNotFound')->duringmtime('foo');
    }

    function it_turns_exception_into_storage_failure_while_getting_file_mtime(Container $container)
    {
        $container->getObject('foo')->willThrow(new \Exception('foo'));

        $this->shouldThrow('Gaufrette\Exception\StorageFailure')->duringmtime('foo');
    }
}
