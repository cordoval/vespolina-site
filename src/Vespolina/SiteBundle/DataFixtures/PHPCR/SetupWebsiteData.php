<?php
/**
 * This file and its content is copyright of Beeldspraak Website Creators BV - (c) Beeldspraak 2012. All rights reserved.
 * Any redistribution or reproduction of part or all of the contents in any form is prohibited.
 * You may not, except with our express written permission, distribute or commercially exploit the content.
 *
 * @author      Beeldspraak <info@beeldspraak.com>
 * @copyright   Copyright 2012, Beeldspraak Website Creators BV
 * @link        http://beeldspraak.com
 *
 */

namespace Vespolina\SiteBundle\DataFixtures\PHPCR;


use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\ODM\PHPCR\ChildrenCollection;
use Doctrine\ODM\PHPCR\DocumentManager;
use PHPCR\Util\NodeHelper;
use Sonata\BlockBundle\Model\BlockInterface;
use Symfony\Cmf\Bundle\BlockBundle\Document\ContainerBlock;
use Symfony\Cmf\Bundle\ContentBundle\Document\MultilangStaticContent;
use Symfony\Cmf\Bundle\RoutingExtraBundle\Document\Route;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class SetupWebsiteData implements FixtureInterface, ContainerAwareInterface
{
    /** @var DocumentManager */
    protected $dm;

    /** @var ContainerInterface */
    protected $container;

    /** @var \Symfony\Component\Console\Output\ConsoleOutput */
    protected $output;

    /** @var \Symfony\Component\Yaml\Yaml */
    protected $yaml;

    /** @var string */
    protected $routeRoot;

    /** @var string */
    protected $contentRoot;

    /** @var string */
    protected $menuRoot;

    /** @var string */
    protected $defaultLocale;

    public function __construct()
    {
        $this->output = new ConsoleOutput();
        $this->yaml = new Yaml();
    }

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * Load data fixtures with the passed EntityManager
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $manager
     */
    function load(ObjectManager $manager)
    {
        /** @var $manager DocumentManager */
        $this->dm = $manager;

        $this->routeRoot = $this->container->getParameter('symfony_cmf_routing_extra.route_basepath');
        $this->contentRoot = $this->container->getParameter('symfony_cmf_routing_extra.content_basepath');
        $this->menuRoot = $this->container->getParameter('symfony_cmf_menu.menu_basepath');

        $this->defaultLocale = $this->container->getParameter('kernel.default_locale');
        $this->availableLocales = $this->dm->getLocaleChooserStrategy()->getDefaultLocale();

        try {
            $this->createRootNodes();
            $this->loadBasicData();
        } catch (\Exception $e) {
            $this->output->writeln(sprintf('<error>Error while loading WebsiteData: %s</error>', $e->getMessage()));
        }
    }

    protected function createRootNodes()
    {
        $this->output->writeln(sprintf('<info>Creating %s, %s and %s</info>', $this->routeRoot, $this->contentRoot, $this->menuRoot));

        NodeHelper::createPath($this->dm->getPhpcrSession(), $this->routeRoot);
        NodeHelper::createPath($this->dm->getPhpcrSession(), $this->contentRoot);
        NodeHelper::createPath($this->dm->getPhpcrSession(), $this->menuRoot);

        $this->dm->getPhpcrSession()->save();
    }

    protected function loadBasicData()
    {
        $contentRoot = $this->dm->find(null, $this->contentRoot);
        $routeRoot = $this->dm->find(null, $this->routeRoot);
        $data = $this->loadYaml('01-basic.yml');

        foreach ($data as $pageName => $pageData) {

            $page = new MultilangStaticContent();
            $page->setName($pageName);
            $page->setLocale($this->defaultLocale);

            if (isset($pageData['parent'])) {
                $parent = $this->dm->find(null, $pageData['parent']);
                if (!$parent) {
                    throw new \DomainException(sprintf('Parent document %s not found', $pageData['parent']));
                }
                $page->setParent($parent);
            } else {
                $page->setParent($contentRoot);
            }

            $localeData = array();

            // Title
            if (isset($pageData['title'])) {
                if (is_array($pageData['title'])) {
                    foreach ($pageData['title'] as $locale => $title) {
                        $localeData[$locale]['title'] = $title;
                    }
                } else {
                    $page->setTitle($pageData['title']);
                }
            }

            // Body
            if (isset($pageData['body'])) {
                if (is_array($pageData['body'])) {
                    foreach ($pageData['body'] as $locale => $title) {
                        $localeData[$locale]['body'] = $title;
                    }
                } else {
                    $page->setBody($pageData['body']);
                }
            }

            if (isset($pageData['route'])) {
                $route = new Route();
                $routeName = ('/' === $pageData['route']) ? 'home' : $pageData['route'];
                $route->setName($routeName);
                $route->setParent($routeRoot);
                $route->setRouteContent($page);
                $this->dm->persist($route);
            }

            if (isset($pageData['additionalInfoBlock'])) {
                $infoBlockData = $pageData['additionalInfoBlock'];
                $infoBlockClass = isset($infoBlockData['type']) ? $infoBlockData['type'] : 'Symfony\Cmf\Bundle\BlockBundle\Document\ContainerBlock';
                /** @var $infoBlock ContainerBlock */
                $infoBlock = new $infoBlockClass();
                $infoBlock->setParentDocument($page);
                $infoBlock->setName('additionalInfoBlock');
                $page->setAdditionalInfoBlock($infoBlock);

                $this->dm->persist($infoBlock);
                $this->dm->flush();

                if (isset($infoBlockData['children'])) {
                     $infoBlock->setChildren($this->processChildren($infoBlock, $infoBlockData['children']));
                }
            }

            $this->dm->persist($page);

            // Set locale based data
            if (count($localeData)) {
                foreach ($localeData as $locale => $data) {
                    foreach ($data as $key => $value) {
                        $method = 'set' . ucfirst($key);
                        $page->{$method}($value);
                    }
                    $this->dm->bindTranslation($page, $locale);
                }
            }
        }

        $this->dm->flush();
    }

    protected function processChildren($parent, $childrenData)
    {
        $children = new ChildrenCollection($this->dm, $parent);
        foreach ($childrenData as $childName => $child) {
            $childBlockClass = 'Symfony\Cmf\Bundle\BlockBundle\Document\SimpleBlock';
            if (isset($child['class'])) {
                $childBlockClass = $child['class'];
            }
            /** @var $block BlockInterface */
            $block = new $childBlockClass;
            $block->setName($childName);
            $block->setParentDocument($parent);
            if (isset($child['template'])) {
                $block->getSetting('template', $child['template']);
            }

            $localeData = array();

            if (isset($child['title'])) {
                if (is_array($child['title'])) {
                    if (!$this->dm->isDocumentTranslatable($block)) {
                        throw new \DomainException(sprintf('Block %s isn\'t translatable', $childName));
                    }
                    foreach ($child['title'] as $locale => $title) {
                        $localeData[$locale]['title'] = $title;
                    }
                } else {
                    $block->setTitle($child['title']); // We just hope this block type supports it here ;)
                }
            }

            $this->dm->persist($block);

            foreach ($localeData as $locale => $data) {
                foreach ($data as $key => $value) {
                    $method = 'set' . ucfirst($key);
                    $block->{$method}($value);
                }
                $this->dm->bindTranslation($block, $locale);
            }

            $children->add($block);

        }

        return $children;
    }

    /**
     * @param $filename
     * @return array
     * @throws \InvalidArgumentException
     */
    protected function loadYaml($filename)
    {
        try {
            $file = realpath(__DIR__ . '/../../') . '/Resources/data/' . $filename;
            if (!file_exists($file)) {
                throw new \InvalidArgumentException($file . ' could not be found');
            }
            return $this->yaml->parse(file_get_contents($file), true);
        } catch (ParseException $e) {
            $this->output->writeln(sprintf('<error>Error while parsing %s: %s</error>', $filename, $e->getMessage()));
        }
    }

}