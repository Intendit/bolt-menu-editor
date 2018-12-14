<?php

namespace Bolt\Extension\Bacboslab\Menueditor;

use Bolt\Asset\File\JavaScript;
use Bolt\Asset\File\Stylesheet;
use Bolt\Controller\Zone;
use Bolt\Extension\SimpleExtension;
use Bolt\Extension\Bacboslab\Menueditor\Event\FieldBuilderEvent;
use Bolt\Menu\MenuEntry;
use Bolt\Translation\Translator as Trans;
use Bolt\Version;
use Silex\Application;
use Silex\ControllerCollection;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Parser;

/**
 * Menueditor extension class.
 *
 * @package MenuEditor
 * @author Svante Richter <svante.richter@gmail.com>
 */
class MenueditorExtension extends SimpleExtension
{
    /**
     * {@inheritdoc}
     */
    protected function registerBackendRoutes(ControllerCollection $collection)
    {
        $baseUrl = Version::compare('3.2.999', '<')
            ? '/extensions/menueditor'
            : '/extend/menueditor'
        ;

        $collection->match($baseUrl, [$this, 'menuEditor'])->bind('menuEditor');
        $collection->match($baseUrl . '/search', [$this, 'menuEditorSearch'])->bind('menuEditorSearch');
    }

    /**
     * {@inheritdoc}
     */
    protected function registerMenuEntries()
    {
        $config = $this->getConfig();
        $menu = new MenuEntry('menueditor');
        $menu->setLabel(Trans::__('menueditor.menuitem', ['DEFAULT' => 'Menu editor']))
            ->setRoute('menuEditor')
            ->setIcon('fa:bars')
            ->setPermission($config['permission']);

        return [
            $menu,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function registerTwigPaths()
    {
        return [
            'templates' => [
                'position' => 'prepend',
                'namespace' => 'bolt'
            ]
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultConfig()
    {
        return [
            'fields' => [],
            'backups' => [
                'enabled' => false
            ],
            'permission' => 'files:config'
        ];
    }

    /**
     * Menueditor route
     *
     * @param  Application $app
     * @param  Request $request
     * @return Response|RedirectResponse
     */
    public function menuEditor(Application $app, Request $request)
    {
        $config = $this->getConfig();

        $assets = [
            new JavaScript('menueditor.js'),
            new Stylesheet('menueditor.css'),
            new JavaScript('jquery.mjs.nestedSortable.js')
        ];

        foreach ($assets as $asset) {
            $asset->setZone(Zone::BACKEND);
            $file = $this->getWebDirectory()->getFile($asset->getPath());
            $asset->setPackageName('extensions')->setPath($file->getPath());
            $app['asset.queue.file']->add($asset);
        }

        // Block unauthorized access...
        if (!$app['users']->isAllowed($config['permission'])) {
            throw new AccessDeniedException(Trans::__(
                'menueditor.notallowed',
                ['DEFAULT' => 'Logged in user does not have the correct rights to use this route.']
            ));
        }

        // Handle posted menus
        if ($request->get('menus')) {
            try {
                $menus = json_decode($request->get('menus'), true);
                // Throw JSON error if we couldn't decode it
                if (json_last_error() !== 0) {
                    throw new \Exception('JSON Error');
                }
                $dumper = new Dumper();
                $dumper->setIndentation(4);
                $yaml = $dumper->dump($menus, 9999);
                $parser = new Parser();
                $parser->parse($yaml);
            } catch (\Exception $e) {
                // Don't save menufile if we got a json on yaml error
                $a