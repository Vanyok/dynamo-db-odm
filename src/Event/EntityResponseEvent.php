<?php

namespace Cpliakas\DynamoDb\ODM\Event;

use Cpliakas\DynamoDb\ODM\EntityInterface;
use \Aws\Result;

class EntityResponseEvent extends EntityRequestEvent
{
    /**
     * @var Aws\Result
     */
    protected $model;

    /**
     * @param \Cpliakas\DynamoDb\ODM\EntityInterface $entity
     * @param \Aws\Result $model
     */
    public function __construct(EntityInterface $entity, \Aws\Result $model)
    {
        parent::__construct($entity);
        $this->model = $model;
    }

    /**
     * @return \Aws\Result
     */
    public function getModel()
    {
        return $this->model;
    }
}
