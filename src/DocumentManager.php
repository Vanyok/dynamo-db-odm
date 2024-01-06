<?php

namespace Cpliakas\DynamoDb\ODM;

use Aws\Common\Iterator\AwsResourceIterator;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Model\Attribute;
use Aws\DynamoDb\Model\Item;
use Aws\DynamoDb\Model\BatchRequest\WriteRequestBatch;
use Aws\DynamoDb\Model\BatchRequest\PutRequest;
use Aws\DynamoDb\Model\BatchRequest\DeleteRequest;
use Guzzle\Service\Resource\Model;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class DocumentManager implements DocumentManagerInterface
{
    const CONSUMED_CAPACITY_NONE    = 'NONE';
    const CONSUMED_CAPACITY_TOTAL   = 'TOTAL';
    const CONSUMED_CAPACITY_INDEXES = 'INDEXES';

    /**
     * @var \Aws\DynamoDb\DynamoDbClient
     */
    protected $dynamoDb;

    /**
     * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
     */
    protected $dispatcher;

    /**
     * @var bool
     */
    protected $consistentRead;

    /**
     * @var string
     */
    protected $returnConsumedCapacity;

    /**
     * @var array
     */
    protected $entityNamespaces;

    /**
     * @var array
     */
    protected $entityArguments;

    /**
     * @var string
     */
    protected $tablePrefix;

    /**
     * @var string
     */
    protected $tableSuffix;

    /**
     * @var \Guzzle\Batch\FlushingBatch
     */
    protected $putBatch = null;

    /**
     * @var \Guzzle\Batch\FlushingBatch
     */
    protected $deleteBatch = null;

    /**
     * @var array
     */
    protected $entityClasses = array();

    /**
     * @param \Aws\DynamoDb\DynamoDbClient $dynamoDb
     * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher
     * @param array $conf
     */
    public function __construct(DynamoDbClient $dynamoDb, EventDispatcherInterface $dispatcher = null, array $conf = array())
    {
        $this->dynamoDb   = $dynamoDb;
        $this->dispatcher = $dispatcher ?: new EventDispatcher();

        $conf += array(
            'command.consistent_read'          => false,
            'command.return_consumed_capacity' => self::CONSUMED_CAPACITY_NONE,
            'entity.namespaces'                => array(),
            'entity.arguments'                 => array(),
            'table.prefix'                     => '',
            'table.suffix'                     => '',
        );

        $this->consistentRead         = (bool) $conf['command.consistent_read'];
        $this->returnConsumedCapacity = $conf['command.return_consumed_capacity'];
        $this->entityNamespaces       = $conf['entity.namespaces'];
        $this->entityArguments        = $conf['entity.arguments'];
        $this->tablePrefix            = $conf['table.prefix'];
        $this->tableSuffix            = $conf['table.suffix'];
    }

    /**
     * {@inheritDoc}
     *
     * @throws \DomainException
     * @throws \UnexpectedValueException
     */
    public function entityFactory($entityClass, $data = array())
    {
        $fqcn = $this->getEntityClass($entityClass);
        $reflection = new \ReflectionClass($fqcn);

        $arguments = array($this->dispatcher, $data);
        if (isset($this->entityArguments[$fqcn])) {
            $arguments = array_merge($arguments, $this->entityArguments[$fqcn]);
        }

        $document = $reflection->newInstanceArgs($arguments);
        if (!$document instanceof EntityInterface) {
            throw new \UnexpectedValueException('Expecting document to be an instance of \Cpliakas\DynamoDb\ODM\EntityInterface');
        }
        return $document;
    }

    /**
     * {@inheritDoc}
     *
     * @throws \Aws\DynamoDb\Exception\DynamoDBException
     */
    public function create(EntityInterface $entity, array $commandOptions = array())
    {
        $this->dispatchEntityRequestEvent(Events::ENTITY_PRE_CREATE, $entity);
        $commandOptions += $this->formatPutItemCommandOptions($entity, false);
        $model = $this->dynamoDb->putItem($commandOptions);

        $this->dispatchEntityResponseEvent(Events::ENTITY_POST_CREATE, $entity, $model);
        return true;
    }


    /**
     * {@inheritDoc}
     *
     * @throws \Aws\DynamoDb\Exception\DynamoDBException
     *
     * @see http://docs.aws.amazon.com/aws-sdk-php/latest/class-Aws.DynamoDb.DynamoDbClient.html#_getItem
     */
    public function read($entityClass, $primaryKey, array $commandOptions = array())
    {
        $entity = $this->initEntity($entityClass, $primaryKey);
        $this->dispatchEntityRequestEvent(Events::ENTITY_PRE_READ, $entity);

        $commandOptions += array(
            'ConsistentRead'         => $this->consistentRead,
            'TableName'              => $this->getEntityTable($entity),
            'Key'                    => $this->formatKeyCondition($entity),
            'ReturnConsumedCapacity' => $this->returnConsumedCapacity,
        );

        $model = $this->dynamoDb->getItem($commandOptions);

        if (isset($model['Item'])) {
            $this->populateEntity($entity, $model['Item']);
            $this->dispatchEntityResponseEvent(Events::ENTITY_POST_READ, $entity, $model);
            return $entity;
        }

        return false;
    }

    /**
     * {@inheritDoc}
     *
     * @throws \Aws\DynamoDb\Exception\DynamoDBException
     */
    public function update(EntityInterface $entity, array $commandOptions = array())
    {
        $this->dispatchEntityRequestEvent(Events::ENTITY_PRE_UPDATE, $entity);

        $commandOptions += $this->formatPutItemCommandOptions($entity, true);
        $model = $this->dynamoDb->putItem($commandOptions);

        $this->dispatchEntityResponseEvent(Events::ENTITY_POST_UPDATE, $entity, $model);
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * @throws \Aws\DynamoDb\Exception\DynamoDBException
     *
     * @see http://docs.aws.amazon.com/aws-sdk-php/latest/class-Aws.DynamoDb.DynamoDbClient.html#_deleteItem
     */
    public function delete(EntityInterface $entity, array $commandOptions = array())
    {
        $this->dispatchEntityRequestEvent(Events::ENTITY_PRE_DELETE, $entity);

        $commandOptions += array(
            'TableName' => $this->getEntityTable($entity),
            'Key'       => $this->formatKeyCondition($entity),
        );
        $res = $model = $this->dynamoDb->deleteItem($commandOptions);
        $this->dispatchEntityResponseEvent( Events::ENTITY_POST_DELETE, $entity, $model);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByKey($entityClass, $primaryKey, array $commandOptions = array())
    {
        $entity = $this->initEntity($entityClass, $primaryKey);
        return $this->delete($entity, $commandOptions);
    }

    /**
     * {@inheritDoc}
     */
    public function exists($entityClass, $primaryKey, array $commandOptions = array())
    {
        $entity = $this->initEntity($entityClass, $primaryKey);
        $commandOptions = array(
            'ConsistentRead'         => $this->consistentRead,
            'TableName'              => $this->getEntityTable($entity),
            'Key'                    => $this->formatKeyCondition($entity),
            'ReturnConsumedCapacity' => $this->returnConsumedCapacity,
        );

        $model = $this->dynamoDb->getItem($commandOptions);

        return isset($model['Item']);
    }

    /**
     * {@inheritDoc}
     *
     * @throws \InvalidArgumentException
     * @throws \Aws\DynamoDb\Exception\DynamoDBException
     *
     * @see http://docs.aws.amazon.com/aws-sdk-php/latest/class-Aws.DynamoDb.DynamoDbClient.html#_query
     */
    public function query($entityClass, $commandOptions)
    {
        return $this->executeSearchCommand($entityClass, $commandOptions, 'Query', 'KeyConditions');
    }

    /**
     * {@inheritDoc}
     *
     * @throws \InvalidArgumentException
     * @throws \Aws\DynamoDb\Exception\DynamoDBException
     *
     * @see http://docs.aws.amazon.com/aws-sdk-php/latest/class-Aws.DynamoDb.DynamoDbClient.html#_scan
     */
    public function scan($entityClass, $commandOptions)
    {
        return $this->executeSearchCommand($entityClass, $commandOptions, 'Scan', 'ScanFilter');
    }

    /**
     * Add entity to the batch creation
     * @param  EntityInterface $entity
     * @throws \Aws\DynamoDb\Exception\DynamoDBException
     */
    public function createBatch(EntityInterface $entity)
    {
        if (is_null($this->putBatch)) {
            $this->putBatch = WriteRequestBatch::factory($this->dynamoDb);
        }
        $this->putBatch->add(new PutRequest(Item::fromArray($entity->getArrayCopy()), $this->getEntityTable($entity)));
    }

    /**
     * Add entity to the batch deletion
     * @param  EntityInterface $entity
     * @throws \Aws\DynamoDb\Exception\DynamoDBException
     */
    public function deleteBatch(EntityInterface $entity)
    {
        if (is_null($this->deleteBatch)) {
            $this->deleteBatch = WriteRequestBatch::factory($this->dynamoDb);
        }
        $this->deleteBatch->add(new DeleteRequest($this->formatKeyCondition($entity), $this->getEntityTable($entity)));
    }

    /**
     * Flush creation and/or deletion batch
     * @return [type] [description]
     */
    public function flush()
    {
        if (!is_null($this->putBatch)) {
            $this->putBatch->flush();
            $this->putBatch = null;
        }
        if (!is_null($this->deleteBatch)) {
            $this->deleteBatch->flush();
            $this->deleteBatch = null;
        }
    }

    /**
     * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher
     *
     * @return \Cpliakas\DynamoDb\ODM\DocumentManager
     */
    public function setDispatcher(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
        return $this;
    }

    /**
     * @return \Symfony\Component\EventDispatcher\EventDispatcherInterface
     */
    public function getDispatcher()
    {
        return $this->dispatcher;
    }

    /**
     * @param bool $consistentRead
     *
     * @return \Cpliakas\DynamoDb\ODM\DocumentManager
     */
    public function consistentRead($consistentRead = true)
    {
        $this->consistentRead = (bool) $consistentRead;
        return $this;
    }

    /**
     * @return bool
     */
    public function getConsistentRead()
    {
        return $this->consistentRead;
    }

    /**
     * @param string $type
     *
     * @return \Cpliakas\DynamoDb\ODM\DocumentManager
     */
    public function returnConsumedCapacity($type)
    {
        $this->returnConsumedCapacity = $type;
        return $this;
    }

    /**
     * @return string
     */
    public function getReturnConsumedCapacity()
    {
        return $this->returnConsumedCapacity;
    }

    /**
     * @param string $namespace
     *
     * @return \Cpliakas\DynamoDb\ODM\DocumentManager
     */
    public function registerEntityNamesapce($namespace)
    {
        $this->entityNamespaces[] = $namespace;
        return $this;
    }

    /**
     * Set the additional arguments used to instantiate an entity.
     *
     * @param string $entityClass
     * @param array $arguments
     *
     * @return \Cpliakas\DynamoDb\ODM\DocumentManager
     */
    public function setEntityArguments($entityClass, array $arguments)
    {
        $this->entityArguments[$this->getEntityClass($entityClass)] = $arguments;
        return $this;
    }

    /**
     * @param string $prefix
     *
     * @return \Cpliakas\DynamoDb\ODM\DocumentManager
     */
    public function setTablePrefix($prefix)
    {
        $this->tablePrefix = $prefix;
        return $this;
    }

    /**
     * @return string
     */
    public function getTablePrefix()
    {
        return $this->tablePrefix;
    }

    /**
     * @param string $suffix
     *
     * @return \Cpliakas\DynamoDb\ODM\DocumentManager
     */
    public function setTableSuffix($suffix)
    {
        $this->tableSuffix = $suffix;
        return $this;
    }

    /**
     * @return string
     */
    public function getTableSuffix()
    {
        return $this->tableSuffix;
    }

    /**
     * Returns the entity's fully qualified class name.
     *
     * @param string $entityClass
     *
     * @throws \DomainException
     */
    protected function getEntityClass($entityClass)
    {
        if (!isset($this->entityClasses[$entityClass])) {

            $found = class_exists($entityClass);

            if ($found) {
                $reflection = new \ReflectionClass($entityClass);
                $fqcn = '\\' . $reflection->getName();
            } elseif (strpos('\\', $entityClass) !== 0) {
                foreach ($this->entityNamespaces as $namespace) {
                    $fqcn = '\\' . trim($namespace, '\\') . '\\' . $entityClass;
                    if (class_exists($fqcn)) {
                        $found = true;
                        break;
                    }
                }
            }

            if (!$found) {
                throw new \DomainException('Entity class not found: ' . $entityClass);
            }

            $this->entityClasses[$entityClass] = $fqcn;
        }

        return $this->entityClasses[$entityClass];
    }

    /**
     * Returns the entity's table name as defined in DynamoDB.
     *
     * @param string $entityClass
     *
     * @return \Cpliakas\DynamoDb\ODM\EntityInterface|string
     *
     * @throws \DomainException
     */
    protected function getEntityTable($entity)
    {
        if (!$entity instanceof EntityInterface) {
            $entity = $this->getEntityClass($entity);
        }
        return $this->tablePrefix . $entity::getTable() . $this->tableSuffix;
    }

    /**
     * Initializes an entity with a primary and range key.
     *
     * @param string $entityClass
     * @param mixed $primaryKey
     *
     * @return \Cpliakas\DynamoDb\ODM\EntityInterface
     *
     * @throw new \InvalidArgumentException
     */
    protected function initEntity($entityClass, $primaryKey)
    {
        $primaryKey = (array) $primaryKey;

        if (!isset($primaryKey[0])) {
            throw new \InvalidArgumentException('Hash key is required');
        }

        $entity = $this->entityFactory($entityClass)->setHashKey($primaryKey[0]);
        if (isset($primaryKey[1])) {
            $entity->setRangeKey($primaryKey[1]);
        }

        return $entity;
    }

    /**
     * Returns baseline options for putItem commands.
     *
     * @param \Cpliakas\DynamoDb\ODM\EntityInterface $entity
     * @param bool $exists
     *
     * @return array
     *
     * @throws \Aws\DynamoDb\Exception\DynamoDBException
     *
     * @see http://docs.aws.amazon.com/aws-sdk-php/latest/class-Aws.DynamoDb.DynamoDbClient.html#_putItem
     */
    protected function formatPutItemCommandOptions(EntityInterface $entity, $mustExist)
    {
        $attributes = array($entity::getHashKeyAttribute() => $entity->getHashKey());
        $rangeKeyAttribute = $entity::getRangeKeyAttribute();
        if ($rangeKeyAttribute) {
            if(is_array($rangeKeyAttribute)){
                foreach ($rangeKeyAttribute as $attribute){
                    $attributes = $entity->getRangeKey();
                }
            }else{
                $attributes[$rangeKeyAttribute] = $entity->getRangeKey();
            }
        }

        $commandOptions = array(
            'TableName'              => $this->getEntityTable($entity),
            'Item'                   => $this->formatEntityAttributes($entity),
            'ReturnConsumedCapacity' => $this->returnConsumedCapacity,
        );

        // Adds conditions based on whether items is being added or updated.
        if ($entity::enforceEntityIntegrity()) {
            if ($mustExist) {
                $entityClass = get_class($entity);
                $commandOptions['Expected'] = $this->formatAttributes($entityClass, $attributes, null);
            } else {
                foreach ($attributes as $attribute => $value) {
                    $commandOptions['Expected'][$attribute] = array('Exists' => false);
                }
            }
        }

        return $commandOptions;
    }

    /**
     * Formats an entity's attributes to the SDK's native data structure.
     *
     * @param \Cpliakas\DynamoDb\ODM\EntityInterface $entity
     * @param string $format
     *
     * @return array
     */
    protected function formatEntityAttributes(EntityInterface $entity, $format = null)
    {
        return $this->formatAttributes(get_class($entity), $entity->getAttributes());
    }

    /**
     * Formats an array of attributes to the SDK's native data structure.
     *
     * @param string $entityClass
     * @param array $attributes
     * @param string $format
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     *
     * @see \Aws\DynamoDb\DynamoDbClient::formatAttributes()
     */
    protected function formatAttributes($entityClass, array $attributes, $format = 'FORMAT_PUT')
    {
        $entityClass = $this->getEntityClass($entityClass);
        $formatted = array();

        $mappings = $entityClass::getDataTypeMappings();

        foreach ($attributes as $attribute => $value) {
            if (isset($mappings[$attribute])) {
                $dataType = $mappings[$attribute];
                if ('FORMAT_PUT' == $format) {
                    $formatted[$attribute] = array($dataType => $value);
                } else {
                    $formatted[$attribute] = array('Value' => array($dataType => $value));
                }
            } else {
                $formatted[$attribute] = array('S' => $value);
            }
        }

        return $formatted;
    }

    /**
     * @param \Cpliakas\DynamoDb\ODM\EntityInterface $entity
     *
     * @return array
     */
    protected function formatKeyCondition(EntityInterface $entity)
    {
        $attributes = array(
            $entity::getHashKeyAttribute() => $entity->getHashKey(),
        );

        $rangeKeyAttribute = $entity::getRangeKeyAttribute();

        if ($rangeKeyAttribute !== false) {
            if(is_array($rangeKeyAttribute)){
                foreach ($rangeKeyAttribute as $attribute){
                    $attributes = $entity->getRangeKey();
                }
            }else{
                $attributes[$rangeKeyAttribute] = $entity->getRangeKey();
            }

        }

        return $this->formatAttributes(get_class($entity), $attributes);
    }

    /**
     * Renders the key conditions.
     *
     * @param \Cpliakas\DynamoDb\ODM\CommandConditionsInterface $conditions
     *
     * @return array
     */
    protected function formatConditions($entityClass, ConditionsInterface $conditions)
    {
        $rendered = array();
        foreach ($conditions->getConditions() as $attribute => $condition) {
            $rendered[$attribute] = array(
                'ComparisonOperator' => $condition['operator'],
            );
            if (isset($condition['values'])) {
                $rendered[$attribute]['AttributeValueList'] = $this->formatAttributes($entityClass, $condition['values']);
            }
        }
        return $rendered;
    }

    /**
     * Executes a scan or query command.
     *
     * @param string $entityClass
     * @param array|\Cpliakas\DynamoDb\ODM\KeyConditionsInterface $options
     * @param string $command
     * @param string $optionKey
     *
     * @return \Cpliakas\DynamoDb\ODM\EntityInterface[]
     *
     * @throws \InvalidArgumentException
     * @throws \Aws\DynamoDb\Exception\DynamoDBException
     */
    protected function executeSearchCommand($entityClass, $commandOptions, $command, $optionKey)
    {
        if ($commandOptions instanceof ConditionsInterface) {
            $commandOptions = array(
                    $optionKey => $this->formatConditions($entityClass, $commandOptions)
                ) + $commandOptions->getOptions();
        } elseif (!is_array($commandOptions)) {
            throw new \InvalidArgumentException('Expecting command options to be an array or instance of \Cpliakas\DynamoDb\ODM\KeyConditionsInterface');
        }

        $commandOptions['TableName'] = $this->getEntityTable($entityClass);
        /* $commandOptions += array(
             'ConsistentRead'         => $this->consistentRead,
             'ReturnConsumedCapacity' => $this->returnConsumedCapacity,
         );*/

        $command = strtolower($command);
        $eventNamePrefix = 'dynamo_db.search.';

        $this->dispatchSearchRequestEvent($eventNamePrefix . 'pre_' . $command, $entityClass);
        $iterator =  $this->dynamoDb->scan( $commandOptions);
        $this->dispatchSearchResponseEvent($eventNamePrefix . 'post_' . $command, $entityClass, $iterator);

        return $iterator['Items'];

    }

    /**
     * @param array $item
     *
     * @return array
     */
    protected function flattenArray(array $item)
    {
        $array = array();
        foreach ($item as $property => $value) {
            $array[$property] = current($value);
        }
        return $array;
    }

    /**
     * @param \Cpliakas\DynamoDb\ODM\EntityInterface $entity
     * @param array $item
     */
    protected function populateEntity(EntityInterface $entity, array $item)
    {
        $attributes = $this->flattenArray($item);
        $entity->setAttributes($attributes);
    }

    /**
     * @param string $eventName
     * @param \Cpliakas\DynamoDb\ODM\EntityInterface $entity
     */
    protected function dispatchEntityRequestEvent($eventName, Entity $entity)
    {
        $event = new Event\EntityRequestEvent($entity);
        $this->dispatcher->dispatch( $event, $eventName);
    }

    /**
     * @param string $eventName
     * @param \Cpliakas\DynamoDb\ODM\EntityInterface $entity
     * @param \Aws\Result $model
     */
    protected function dispatchEntityResponseEvent($eventName, Entity $entity, \Aws\Result $model)
    {
        $event = new Event\EntityResponseEvent($entity, $model);
        $this->dispatcher->dispatch( $event, $eventName);
    }

    /**
     * @param string $eventName
     * @param string $entityClass
     */
    protected function dispatchSearchRequestEvent($eventName, $entityClass)
    {
        $event = new Event\SearchRequestEvent($entityClass);
        $this->dispatcher->dispatch( $event,$eventName);
    }
    /**
     * @param string $eventName
     * @param string $entityClass
     * @param  $iterator
     */
    protected function dispatchSearchResponseEvent($eventName, $entityClass, $iterator)
    {
        $event = new Event\SearchResponseEvent($entityClass, $iterator);
        $this->dispatcher->dispatch( $event,$eventName );
    }


    /**
     * Create Dynamo DB table
     *
     * @param $tableName
     * @param array $attributes
     * @return void
     */
    public function createTable($tableName, array $attributes)
    {
        $keySchema = [];
        $attributeDefinitions = [];
        foreach ($attributes as $attribute) {
            if (is_a($attribute, DynamoDBAttribute::class)) {
                $keySchema[] = ['AttributeName' => $attribute->AttributeName, 'KeyType' => $attribute->KeyType];
                $attributeDefinitions[] =
                    ['AttributeName' => $attribute->AttributeName, 'AttributeType' => $attribute->AttributeType];
            }
        }

        $this->dynamoDb->createTable([
            'TableName' => $tableName,
            'KeySchema' => $keySchema,
            'AttributeDefinitions' => $attributeDefinitions,
            'ProvisionedThroughput' => ['ReadCapacityUnits' => 10, 'WriteCapacityUnits' => 10],
        ]);
    }

    /**
     * Delete Dynamo DB table by name
     *
     * @param string $TableName
     * @return void
     */
    public function deleteTable(string $TableName)
    {

        return $this->dynamoDb->deleteTable([
            'TableName' => $TableName,
        ]);

    }
}
