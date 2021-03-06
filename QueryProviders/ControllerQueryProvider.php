<?php


namespace TheCodingMachine\Graphqlite\Bundle\QueryProviders;


use TheCodingMachine\GraphQLite\FieldsBuilder;
use TheCodingMachine\GraphQLite\QueryField;
use TheCodingMachine\GraphQLite\QueryProviderInterface;

class ControllerQueryProvider implements QueryProviderInterface
{
    /**
     * @var object
     */
    private $controller;
    /**
     * @var FieldsBuilder
     */
    private $fieldsBuilder;

    /**
     * @param object $controller
     * @param FieldsBuilder $fieldsBuilder
     */
    public function __construct($controller, FieldsBuilder $fieldsBuilder)
    {
        $this->controller = $controller;
        $this->fieldsBuilder = $fieldsBuilder;
    }

    /**
     * @return QueryField[]
     */
    public function getQueries(): array
    {
        return $this->fieldsBuilder->getQueries($this->controller);
    }

    /**
     * @return QueryField[]
     */
    public function getMutations(): array
    {
        return $this->fieldsBuilder->getMutations($this->controller);
    }
}
