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

namespace Sylius\Bundle\ThemeBundle\Loader;

use Sylius\Bundle\ThemeBundle\Configuration\ConfigurationProviderInterface;
use Sylius\Bundle\ThemeBundle\Factory\ThemeAuthorFactoryInterface;
use Sylius\Bundle\ThemeBundle\Factory\ThemeFactoryInterface;
use Sylius\Bundle\ThemeBundle\Factory\ThemeScreenshotFactoryInterface;
use Sylius\Bundle\ThemeBundle\Model\ThemeAuthor;
use Sylius\Bundle\ThemeBundle\Model\ThemeInterface;
use Sylius\Bundle\ThemeBundle\Model\ThemeScreenshot;
use Webmozart\Assert\Assert;
use Zend\Hydrator\HydrationInterface;

final class ThemeLoader implements ThemeLoaderInterface
{
    /** @var ConfigurationProviderInterface */
    private $configurationProvider;

    /** @var ThemeFactoryInterface */
    private $themeFactory;

    /** @var ThemeAuthorFactoryInterface */
    private $themeAuthorFactory;

    /** @var ThemeScreenshotFactoryInterface */
    private $themeScreenshotFactory;

    /** @var HydrationInterface */
    private $themeHydrator;

    /** @var CircularDependencyCheckerInterface */
    private $circularDependencyChecker;

    public function __construct(
        ConfigurationProviderInterface $configurationProvider,
        ThemeFactoryInterface $themeFactory,
        ThemeAuthorFactoryInterface $themeAuthorFactory,
        ThemeScreenshotFactoryInterface $themeScreenshotFactory,
        HydrationInterface $themeHydrator,
        CircularDependencyCheckerInterface $circularDependencyChecker
    ) {
        $this->configurationProvider = $configurationProvider;
        $this->themeFactory = $themeFactory;
        $this->themeAuthorFactory = $themeAuthorFactory;
        $this->themeScreenshotFactory = $themeScreenshotFactory;
        $this->themeHydrator = $themeHydrator;
        $this->circularDependencyChecker = $circularDependencyChecker;
    }

    /**
     * {@inheritdoc}
     */
    public function load(): array
    {
        $configurations = $this->configurationProvider->getConfigurations();

        $themes = $this->initializeThemes($configurations);
        $themes = $this->hydrateThemes($configurations, $themes);

        $this->checkForCircularDependencies($themes);

        return array_values($themes);
    }

    /**
     * @return array|ThemeInterface[]
     */
    private function initializeThemes(array $configurations): array
    {
        $themes = [];
        foreach ($configurations as $configuration) {
            $themes[$configuration['name']] = $this->themeFactory->create($configuration['name'], $configuration['path']);
        }

        return $themes;
    }

    /**
     * @param array|ThemeInterface[] $themes
     *
     * @return array|ThemeInterface[]
     */
    private function hydrateThemes(array $configurations, array $themes): array
    {
        foreach ($configurations as $configuration) {
            $themeName = $configuration['name'];

            $configuration['parents'] = $this->convertParentsNamesToParentsObjects($themeName, $configuration['parents'], $themes);
            $configuration['authors'] = $this->convertAuthorsArraysToAuthorsObjects($configuration['authors']);
            $configuration['screenshots'] = $this->convertScreenshotsArraysToScreenshotsObjects($configuration['screenshots']);

            $theme = $this->themeHydrator->hydrate($configuration, $themes[$themeName]);
            /** @var ThemeInterface $theme */
            Assert::isInstanceOf($theme, ThemeInterface::class);

            $themes[$themeName] = $theme;
        }

        return $themes;
    }

    /**
     * @param array|ThemeInterface[] $themes
     */
    private function checkForCircularDependencies(array $themes): void
    {
        try {
            foreach ($themes as $theme) {
                $this->circularDependencyChecker->check($theme);
            }
        } catch (CircularDependencyFoundException $exception) {
            throw new ThemeLoadingFailedException('Circular dependency found.', 0, $exception);
        }
    }

    /**
     * @return array|ThemeInterface[]
     */
    private function convertParentsNamesToParentsObjects(string $themeName, array $parentsNames, array $existingThemes): array
    {
        return array_map(function (string $parentName) use ($themeName, $existingThemes): ThemeInterface {
            if (!isset($existingThemes[$parentName])) {
                throw new ThemeLoadingFailedException(sprintf(
                    'Unexisting theme "%s" is required by "%s".',
                    $parentName,
                    $themeName
                ));
            }

            return $existingThemes[$parentName];
        }, $parentsNames);
    }

    /**
     * @return array|ThemeAuthor[]
     */
    private function convertAuthorsArraysToAuthorsObjects(array $authorsArrays): array
    {
        return array_map(function (array $authorArray): ThemeAuthor {
            return $this->themeAuthorFactory->createFromArray($authorArray);
        }, $authorsArrays);
    }

    /**
     * @return array|ThemeScreenshot[]
     */
    private function convertScreenshotsArraysToScreenshotsObjects(array $screenshotsArrays): array
    {
        return array_map(function (array $screenshotArray): ThemeScreenshot {
            return $this->themeScreenshotFactory->createFromArray($screenshotArray);
        }, $screenshotsArrays);
    }
}
