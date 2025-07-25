<?php

namespace Draw\Component\DependencyInjection\Integration\Test;

use Draw\Component\DependencyInjection\Integration\IntegrationInterface;
use Draw\Component\DependencyInjection\Integration\PrependIntegrationInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

abstract class IntegrationTestCase extends TestCase
{
    protected IntegrationInterface|PrependIntegrationInterface $integration;

    abstract public function createIntegration(): IntegrationInterface;

    abstract public function getConfigurationSectionName(): string;

    abstract public function getDefaultConfiguration(): array;

    protected function mockExtension(string $name): ExtensionInterface
    {
        $extension = $this->createMock(ExtensionInterface::class);

        $extension->expects(static::any())
            ->method('getAlias')
            ->willReturn($name)
        ;

        $extension
            ->expects(static::any())
            ->method('getNamespace')
            ->willReturn($name)
        ;

        return $extension;
    }

    protected function setUp(): void
    {
        $this->integration = $this->createIntegration();
    }

    public function testGetConfigSectionName(): void
    {
        static::assertSame(
            $this->getConfigurationSectionName(),
            $this->integration->getConfigSectionName()
        );
    }

    public function testDefaultConfiguration(): void
    {
        static::assertJsonStringEqualsJsonString(
            json_encode($this->processConfiguration()),
            json_encode($this->getDefaultConfiguration()),
        );
    }

    #[DataProvider('provideLoadCases')]
    public function testLoad(
        array $configuration = [],
        array $services = [],
        array $extraAliases = [],
        array $expectedParameters = [],
    ): void {
        $container = new ContainerBuilder();
        $loader = new PhpFileLoader($container, new FileLocator([]));

        $configuration = $this->processConfiguration($configuration);

        $this->integration->load(
            $configuration,
            $loader,
            $container,
        );

        static::assertContainerBuilderServices(
            $services,
            $container,
            $extraAliases,
        );

        static::assertContainerBuilderParameters(
            $expectedParameters,
            $container,
        );
    }

    abstract public static function provideLoadCases(): iterable;

    protected function processConfiguration(array $configs = [[]]): array
    {
        $treeBuilder = new TreeBuilder($this->integration->getConfigSectionName());
        $this->integration->addConfiguration($treeBuilder->getRootNode());

        return (new Processor())->processConfiguration(
            new class($treeBuilder) implements ConfigurationInterface {
                public function __construct(private TreeBuilder $treeBuilder)
                {
                }

                public function getConfigTreeBuilder(): TreeBuilder
                {
                    return $this->treeBuilder;
                }
            },
            $configs
        );
    }

    public static function assertContainerExtensionConfiguration(ContainerBuilder $containerBuilder, array $expectedConfiguration): void
    {
        $currentConfiguration = [];
        foreach (array_keys($containerBuilder->getExtensions()) as $extension) {
            $currentConfiguration[$extension] = $containerBuilder->getExtensionConfig($extension);
        }

        static::assertSame(
            $expectedConfiguration,
            $currentConfiguration
        );
    }

    /**
     * @param array|ServiceConfiguration[] $services
     */
    public static function assertContainerBuilderServices(
        array $services,
        ContainerBuilder $container,
        array $extraAliases = [],
    ): void {
        $definedServiceIds = array_values(
            array_diff(
                array_keys($container->getDefinitions()),
                array_keys((new ContainerBuilder())->getDefinitions())
            )
        );

        $definedAliasIds = array_values(
            array_diff(
                array_keys($container->getAliases()),
                array_keys((new ContainerBuilder())->getAliases())
            )
        );

        $serviceAliases = [];

        foreach ($definedAliasIds as $aliasId) {
            $serviceAliases[(string) $container->getAlias($aliasId)][] = $aliasId;
        }

        foreach ($services as $service) {
            static::assertContains(
                $service->getId(),
                $definedServiceIds,
                'Available service ids: '.implode(', ', $definedServiceIds)
            );

            unset($definedServiceIds[array_search($service->getId(), $definedServiceIds, true)]);

            static::assertSame(
                $service->getAliases(),
                $serviceAliases[$service->getId()] ?? [],
                'Service ['.$service->getId().'] aliases do not match.'
            );

            unset($serviceAliases[$service->getId()]);

            if ($callback = $service->getDefinitionCheckCallback()) {
                $callback($container->getDefinition($service->getId()));
            }
        }

        static::assertSame(
            [],
            $definedServiceIds,
            'All service should be tested'
        );

        foreach ($extraAliases as $serviceId => $aliases) {
            static::assertArrayHasKey(
                $serviceId,
                $serviceAliases,
                'Available aliases ids: '.implode(', ', array_keys($serviceAliases))
            );

            static::assertSame(
                $serviceAliases[$serviceId],
                $aliases
            );
            unset($serviceAliases[$serviceId]);
        }

        static::assertSame(
            [],
            $serviceAliases,
            'All aliases need to be accounted for'
        );
    }

    public static function assertContainerBuilderParameters(
        array $expectedParameters,
        ContainerBuilder $container,
    ): void {
        static::assertSame(
            $expectedParameters,
            array_diff_key(
                $container->getParameterBag()->all(),
                (new ContainerBuilder())->getParameterBag()->all()
            ),
            'Defined parameters do not match'
        );
    }
}

class ServiceConfiguration
{
    private $definitionCheckCallback;

    public function __construct(private string $id, private array $aliases, ?callable $definitionCheckCallback = null)
    {
        $this->definitionCheckCallback = $definitionCheckCallback;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function getDefinitionCheckCallback(): ?callable
    {
        return $this->definitionCheckCallback;
    }
}
