<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Paweł Jędrzejewski
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Sylius\Bundle\ThemeBundle\DependencyInjection;

use Sylius\Bundle\ThemeBundle\Configuration\ConfigurationSourceFactoryInterface;
use Sylius\Bundle\ThemeBundle\Context\ThemeContextInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

final class SyliusThemeExtension extends Extension implements PrependExtensionInterface
{
    /** @var ConfigurationSourceFactoryInterface[] */
    private $configurationSourceFactories = [];

    /**
     * @internal
     *
     * {@inheritdoc}
     */
    public function load(array $config, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration($this->getConfiguration([], $container), $config);
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');

        if ($config['assets']['enabled']) {
            $loader->load('services/integrations/assets.xml');
        }

        if ($config['templating']['enabled']) {
            $loader->load('services/integrations/templating.xml');
        }

        if ($config['translations']['enabled']) {
            $loader->load('services/integrations/translations.xml');
        }

        $this->resolveConfigurationSources($container, $config);

        $container->setAlias('sylius.context.theme', $config['context']);
        $container->setAlias(ThemeContextInterface::class, 'sylius.context.theme');
    }

    /**
     * @internal
     *
     * {@inheritdoc}
     */
    public function prepend(ContainerBuilder $container): void
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        $this->prependTwig($container, $loader);
    }

    public function addConfigurationSourceFactory(ConfigurationSourceFactoryInterface $configurationSourceFactory): void
    {
        $this->configurationSourceFactories[$configurationSourceFactory->getName()] = $configurationSourceFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        $configuration = new Configuration($this->configurationSourceFactories);

        $container->addObjectResource($configuration);

        return $configuration;
    }

    private function prependTwig(ContainerBuilder $container, LoaderInterface $loader): void
    {
        if (!$container->hasExtension('twig')) {
            return;
        }

        $loader->load('services/integrations/twig.xml');
    }

    private function resolveConfigurationSources(ContainerBuilder $container, array $config): void
    {
        $configurationProviders = [];
        foreach ($this->configurationSourceFactories as $configurationSourceFactory) {
            $sourceName = $configurationSourceFactory->getName();
            if (isset($config['sources'][$sourceName]) && $config['sources'][$sourceName]['enabled']) {
                $sourceConfig = $config['sources'][$sourceName];

                $configurationProviders[] = $configurationSourceFactory->initializeSource($container, $sourceConfig);
            }
        }

        $compositeConfigurationProvider = $container->getDefinition('sylius.theme.configuration.provider');
        $compositeConfigurationProvider->replaceArgument(0, $configurationProviders);

        foreach ($this->configurationSourceFactories as $configurationSourceFactory) {
            $container->addObjectResource($configurationSourceFactory);
        }
    }
}
