<?php
/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Smile Elastic Suite to newer
 * versions in the future.
 *
 *
 * @category  Smile_Elasticsuite
 * @package   Smile\ElasticsuiteCore
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 * @copyright 2016 Smile
 * @license   Open Software License ("OSL") v. 3.0
 */
namespace Smile\ElasticsuiteCore\Test\Unit\Index;

use Smile\ElasticsuiteCore\Index\IndexOperation;

/**
 * Index operation test case.
 *
 * @category  Smile_Elasticsuite
 * @package   Smile\ElasticsuiteCore
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 */
class IndexOperationTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \Smile\ElasticsuiteCore\Index\IndexOperation
     */
    private $indexOperation;

    /**
     * @var \Elasticsearch\Client|\PHPUnit_Framework_MockObject_MockObject
     */
    private $clientMock;

    /**
     * @var array
     */
    private $logRows = [];

    /**
     * Init stubs.
     *
     * {@inheritDoc}
     */
    protected function setUp()
    {
        $objectManager = $this->getObjectManagerMock();
        $clientFactory = $this->getClientFactoryMock();
        $indexSettings = $this->getIndexSettingsMock();
        $logger        = $this->getLoggerMock();

        $this->indexOperation = new IndexOperation($objectManager, $clientFactory, $indexSettings, $logger);
    }

    public function testIsAvailable()
    {
        $this->clientMock->method('ping')->will($this->returnValue(true));
        $this->assertEquals(true, $this->indexOperation->isAvailable());
    }

    public function testIsNotAvailable()
    {
        $this->clientMock->method('ping')->willThrowException(new \Exception());
        $this->assertEquals(false, $this->indexOperation->isAvailable());
    }

    public function testGetIndexByName()
    {
        $index = $this->indexOperation->getIndexByName('index_identifier', 'store_code');
        $this->assertInstanceOf(\Smile\ElasticsuiteCore\Api\Index\IndexInterface::class, $index);
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage invalid_index_identifier index does not exist yet. Make sure everything is reindexed.
     */
    public function testGetIndexInvalidByName()
    {
        $index = $this->indexOperation->getIndexByName('invalid_index_identifier', 'store_code');
        $this->assertInstanceOf(\Smile\ElasticsuiteCore\Api\Index\IndexInterface::class, $index);
    }

    public function testCreateBulk()
    {
        $bulk = $this->indexOperation->createBulk();
        $this->assertInstanceOf(\Smile\ElasticsuiteCore\Api\Index\Bulk\BulkRequestInterface::class, $bulk);
    }

    public function testExecuteBulk()
    {
        $this->clientMock->method('bulk')->will($this->returnValue([
            'errors' => true,
            'items'  => [
                ['index' => ['_index' => 'index', '_type' => 'type', '_id' => 'doc1']],
                ['index' => ['_index' => 'index', '_type' => 'type', '_id' => 'doc2']],
            ]
        ]));
        $bulkMock = $this->indexOperation->createBulk();
        $bulkMock->method('getOperations')->will($this->returnValue([]));
        $this->indexOperation->executeBulk($bulkMock);
        $this->assertArrayNotHasKey('errors', $this->logRows);
    }

    public function testExecuteBulkWithErrors()
    {
        $error1 = ['type' => 'reason1', 'reason' => 'Reason 1'];
        $error2 = ['type' => 'reason2', 'reason' => 'Reason 2'];
        $this->clientMock->method('bulk')->will($this->returnValue([
            'errors' => true,
            'items'  => [
                ['index' => ['_index' => 'index', '_type' => 'type', '_id' => 'doc1']],
                ['index' => ['_index' => 'index', '_type' => 'type', '_id' => 'doc2']],
                ['index' => ['_index' => 'index', '_type' => 'type', '_id' => 'doc3', 'error' => $error1]],
                ['index' => ['_index' => 'index', '_type' => 'type', '_id' => 'doc4', 'error' => $error1]],
                ['index' => ['_index' => 'index', '_type' => 'type', '_id' => 'doc5', 'error' => $error2]],
                ['index' => ['_index' => 'index', '_type' => 'type', '_id' => 'doc6', 'error' => $error2]],
            ]
        ]));
        $bulkMock = $this->indexOperation->createBulk();
        $bulkMock->method('getOperations')->will($this->returnValue([]));
        $this->indexOperation->executeBulk($bulkMock);
        $this->assertArrayHasKey('errors', $this->logRows);
        $this->assertCount(2, $this->logRows['errors']);
        $this->assertEquals(
            'Bulk index operation failed 2 times in index index for type type. Error (reason2) : Reason 2. Failed doc ids sample : doc5, doc6.',
            end($this->logRows['errors'])
        );
    }

    /**
     * @expectedException \LogicException
     * @expectedExceptionMessage Can not execute empty bulk.
     */
    public function testExecuteEmptyBulk()
    {
        $bulk = new \Smile\ElasticsuiteCore\Index\Bulk\BulkRequest();
        $this->indexOperation->executeBulk($bulk);
    }

    public function testGetBatchIndexingSize()
    {
        $this->assertEquals(100, $this->indexOperation->getBatchIndexingSize());
    }

    private function getObjectManagerMock()
    {
        $objectManagerMockBuilder = $this->getMockBuilder(\Magento\Framework\ObjectManagerInterface::class);
        $objectManagerMockBuilder->disableOriginalConstructor();

        $objectManagerMock = $objectManagerMockBuilder->getMock();

        $objectManagerMock->method('create')->will(
            $this->returnCallback(function ($className, $args) {
                $instance = null;

                if ($className === 'Smile\ElasticsuiteCore\Api\Index\Bulk\BulkResponseInterface') {
                    $reflect = new \ReflectionClass(\Smile\ElasticsuiteCore\Index\Bulk\BulkResponse::class);
                    $instance = $reflect->newInstanceArgs($args);
                }

                if ($instance === null) {
                    $mockBuilder = $this->getMockBuilder($className);
                    $mockBuilder->disableOriginalConstructor();
                    $instance = $mockBuilder->getMock();
                }

                return $instance;
            })
        );

        return $objectManagerMock;
    }

    private function getClientFactoryMock()
    {
        $clientFactoryMockBuilder = $this->getMockBuilder(\Smile\ElasticsuiteCore\Api\Client\ClientFactoryInterface::class);

        $clientMockBuilder = $this->getMockBuilder(\Elasticsearch\Client::class);
        $clientMockBuilder->disableOriginalConstructor();
        $this->clientMock = $clientMockBuilder->getMock();

        $indicesNamespaceMockBuilder = $this->getMockBuilder(\Elasticsearch\Namespaces\IndicesNamespace::class);
        $indicesNamespaceMockBuilder->disableOriginalConstructor();
        $indicesNamespaceMock = $indicesNamespaceMockBuilder->getMock();

        $indicesNamespaceMock->method('exists')
            ->will($this->returnCallback(function ($index) { return $index['index'] === 'index_identifier_store_code'; }));
        $this->clientMock->method('indices')->will($this->returnValue($indicesNamespaceMock));

        $clientFactoryMock = $clientFactoryMockBuilder->getMock();
        $clientFactoryMock->method('createClient')->will($this->returnValue($this->clientMock));

        return $clientFactoryMock;
    }

    private function getIndexSettingsMock()
    {
        $indexSettingsMockBuilder = $this->getMockBuilder(\Smile\ElasticsuiteCore\Api\Index\IndexSettingsInterface::class);

        $indexSettingsMock = $indexSettingsMockBuilder->getMock();
        $indexSettingsMock->method('getIndexAliasFromIdentifier')->will(
            $this->returnCallback(function ($indexIdentifier, $store) { return "{$indexIdentifier}_{$store}"; })
        );

        $indexSettingsMock->method('getIndicesConfig')->will($this->returnValue(['index_identifier' => []]));
        $indexSettingsMock->method('getBatchIndexingSize')->will($this->returnValue(100));

        return $indexSettingsMock;
    }

    private function getLoggerMock()
    {
        $loggerMockBuilder = $this->getMockBuilder(\Psr\Log\LoggerInterface::class);
        $loggerMock = $loggerMockBuilder->getMock();

        $loggerMock->method('error')->will(
            $this->returnCallback(function ($message, $context = '') {
                $this->logRows['errors'][] = $message;
            })
        );

        return $loggerMock;
    }
}
