<?php


namespace TheCodingMachine\Graphqlite\Bundle\DependencyInjection;

use function class_exists;
use Doctrine\Common\Annotations\AnnotationException;
use Doctrine\Common\Annotations\AnnotationReader as DoctrineAnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Cache\ApcuCache;
use function function_exists;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use function str_replace;
use function strpos;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use TheCodingMachine\GraphQLite\AnnotationReader;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\Graphqlite\Bundle\QueryProviders\ControllerQueryProvider;
use TheCodingMachine\GraphQLite\FieldsBuilder;
use TheCodingMachine\GraphQLite\FieldsBuilderFactory;
use TheCodingMachine\GraphQLite\InputTypeGenerator;
use TheCodingMachine\GraphQLite\InputTypeUtils;
use TheCodingMachine\GraphQLite\Mappers\CompositeTypeMapper;
use TheCodingMachine\GraphQLite\Mappers\GlobTypeMapper;
use TheCodingMachine\GraphQLite\Mappers\RecursiveTypeMapperInterface;
use TheCodingMachine\GraphQLite\Mappers\Root\CompositeRootTypeMapper;
use TheCodingMachine\GraphQLite\Mappers\StaticTypeMapper;
use TheCodingMachine\GraphQLite\NamingStrategy;
use TheCodingMachine\GraphQLite\TypeGenerator;
use TheCodingMachine\GraphQLite\Types\MutableObjectType;
use TheCodingMachine\GraphQLite\Types\ResolvableInputObjectType;
use function var_dump;

/**
 * Detects controllers and types automatically and tag them.
 */
class GraphqliteCompilerPass implements CompilerPassInterface
{
    /**
     * @var AnnotationReader
     */
    private $annotationReader;

    /**
     * You can modify the container here before it is dumped to PHP code.
     */
    public function process(ContainerBuilder $container)
    {
        $reader = $this->getAnnotationReader();
        //$inputTypeUtils = new InputTypeUtils($reader, $namingStrategy);

        // Let's scan the whole container and tag the services that belong to the namespace we want to inspect.
        $controllersNamespaces = $container->getParameter('graphqlite.namespace.controllers');
        $typesNamespaces = $container->getParameter('graphqlite.namespace.types');

        // 2 seconds of TTL in environment mode. Otherwise, let's cache forever!
        $env = $container->getParameter('kernel.environment');
        $globTtl = null;
        if ($env === 'dev') {
            $globTtl = 2;
        }

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isAbstract() || $definition->getClass() === null) {
                continue;
            }
            $class = $definition->getClass();
            foreach ($controllersNamespaces as $controllersNamespace) {
                if (strpos($class, $controllersNamespace) === 0) {
                    $definition->addTag('graphql.annotated.controller');
                }
            }

            foreach ($typesNamespaces as $typesNamespace) {
                if (strpos($class, $typesNamespace) === 0) {
                    //$definition->addTag('graphql.annotated.type');
                    // Set the types public
                    $reflectionClass = new ReflectionClass($class);
                    $typeAnnotation = $this->getAnnotationReader()->getTypeAnnotation($reflectionClass);
                    if ($typeAnnotation !== null && $typeAnnotation->isSelfType()) {
                        continue;
                    }
                    if ($typeAnnotation !== null || $this->getAnnotationReader()->getExtendTypeAnnotation($reflectionClass) !== null) {
                        $definition->setPublic(true);
                    } else {
                        foreach ($reflectionClass->getMethods() as $method) {
                            $factory = $reader->getFactoryAnnotation($method);
                            if ($factory !== null) {
                                $definition->setPublic(true);
                            }
                        }
                    }
                }
            }
        }

        foreach ($container->findTaggedServiceIds('graphql.annotated.controller') as $id => $tag) {
            $definition = $container->findDefinition($id);
            $class = $definition->getClass();
            if ($class === null) {
                throw new \RuntimeException(sprintf('Service %s has no class defined.', $id));
            }

            $reflectionClass = new ReflectionClass($class);
            $isController = false;
            $method = null;
            foreach ($reflectionClass->getMethods() as $method) {
                $query = $reader->getRequestAnnotation($method, Query::class);
                if ($query !== null) {
                    $isController = true;
                    break;
                }
                $mutation = $reader->getRequestAnnotation($method, Mutation::class);
                if ($mutation !== null) {
                    $isController = true;
                    break;
                }
            }

            if ($isController) {
                // Let's create a QueryProvider from this controller
                $controllerIdentifier = $class.'__QueryProvider';
                $queryProvider = new Definition(ControllerQueryProvider::class);
                $queryProvider->setPrivate(true);
                $queryProvider->setFactory([self::class, 'createQueryProvider']);
                $queryProvider->addArgument(new Reference($id));
                $queryProvider->addArgument(new Reference(FieldsBuilder::class));
                $queryProvider->addTag('graphql.queryprovider');
                $container->setDefinition($controllerIdentifier, $queryProvider);
            }
        }

        foreach ($typesNamespaces as $typesNamespace) {
            $definition = new Definition(GlobTypeMapper::class);
            $definition->addArgument($typesNamespace);
            $definition->setArgument('$globTtl', $globTtl);
            $definition->setAutowired(true);
            $definition->addTag('graphql.type_mapper');
            $container->setDefinition('globTypeMapper_'.str_replace('\\', '__', $typesNamespace), $definition);
        }


        // Register custom output types
        $taggedServices = $container->findTaggedServiceIds('graphql.output_type');

        $customTypes = [];
        $customNotMappedTypes = [];
        foreach ($taggedServices as $id => $tags) {
            foreach ($tags as $attributes) {
                if (isset($attributes["class"])) {
                    $phpClass = $attributes["class"];
                    if (!class_exists($phpClass)) {
                        throw new \RuntimeException(sprintf('The class attribute of the graphql.output_type annotation of the %s service must point to an existing PHP class. Value passed: %s', $id, $phpClass));
                    }
                    $customTypes[$phpClass] = new Reference($id);
                } else {
                    $customNotMappedTypes = new Reference($id);
                }
            }
        }

        if (!empty($customTypes)) {
            $definition = $container->getDefinition(StaticTypeMapper::class);
            $definition->addMethodCall('setTypes', [$customTypes]);
        }
        if (!empty($customNotMappedTypes)) {
            $definition = $container->getDefinition(StaticTypeMapper::class);
            $definition->addMethodCall('setNotMappedTypes', [$customNotMappedTypes]);
        }

        // Register type mappers
        $typeMapperServices = $container->findTaggedServiceIds('graphql.type_mapper');
        $compositeTypeMapper = $container->getDefinition(CompositeTypeMapper::class);
        foreach ($typeMapperServices as $id => $tags) {
            // add the transport service to the TransportChain service
            $compositeTypeMapper->addMethodCall('addTypeMapper', [new Reference($id)]);
        }
    }

    /**
     * @param object $controller
     */
    public static function createQueryProvider($controller, FieldsBuilder $fieldsBuilder): ControllerQueryProvider
    {
        return new ControllerQueryProvider($controller, $fieldsBuilder);
    }

    /**
     * Returns a cached Doctrine annotation reader.
     * Note: we cannot get the annotation reader service in the container as we are in a compiler pass.
     */
    private function getAnnotationReader(): AnnotationReader
    {
        if ($this->annotationReader === null) {
            AnnotationRegistry::registerLoader('class_exists');
            $doctrineAnnotationReader = new DoctrineAnnotationReader();

            if (function_exists('apcu_fetch')) {
                $doctrineAnnotationReader = new CachedReader($doctrineAnnotationReader, new ApcuCache(), true);
            }

            $this->annotationReader = new AnnotationReader($doctrineAnnotationReader, AnnotationReader::LAX_MODE);
        }
        return $this->annotationReader;
    }
}
