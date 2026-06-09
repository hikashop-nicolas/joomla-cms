<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Extension
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Customize\View\Extension;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Event\GenericEvent;
use Joomla\Event\Dispatcher;
use Joomla\Plugin\Customize\View\Extension\View;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Test class for the Customize - View plugin.
 *
 * @package     Joomla.UnitTest
 * @subpackage  Extension
 *
 * @testdox     The Customize View plugin
 *
 * @since       __DEPLOY_VERSION__
 */
class ViewTest extends UnitTestCase
{
    /**
     * Build the plugin with an application reporting the given template.
     *
     * @param   string  $template  The active template element.
     *
     * @return  View
     *
     * @since   __DEPLOY_VERSION__
     */
    private function plugin(string $template = 'cassiopeia'): View
    {
        // getTemplate() lives on the application (the concrete site app is final); mock the abstract base.
        $app = $this->getMockBuilder(CMSApplication::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTemplate'])
            ->getMockForAbstractClass();
        $app->method('getTemplate')->willReturn($template);

        $plugin = new View(new Dispatcher(), ['params' => []]);
        $plugin->setApplication($app);

        return $plugin;
    }

    /**
     * @testdox  wraps a sub-layout in comment markers carrying its identity
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testWrapsOutputWithBlockMarkers()
    {
        $event = new GenericEvent('onCustomizeRenderView', [
            'output'    => '<div>article</div>',
            'block'     => 'item',
            'component' => 'com_content',
            'view'      => 'featured',
            'layout'    => 'default',
        ]);

        $this->plugin()->onCustomizeRenderView($event);

        $html = (string) $event->getArgument('output');

        $this->assertStringContainsString('<!--customize-block-start:', $html);
        $this->assertStringContainsString('component=com_content', $html);
        $this->assertStringContainsString('view=featured', $html);
        $this->assertStringContainsString('layout=default', $html);
        $this->assertStringContainsString('block=item', $html);
        $this->assertStringContainsString('template=cassiopeia', $html);
        $this->assertStringContainsString('<div>article</div>', $html);
        $this->assertStringContainsString('<!--customize-block-end-->', $html);
    }

    /**
     * @testdox  does nothing for a top-level layout (no block name)
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testLeavesOutputUntouchedWithoutABlock()
    {
        $event = new GenericEvent('onCustomizeRenderView', [
            'output'    => '<div>article</div>',
            'block'     => '',
            'component' => 'com_content',
            'view'      => 'featured',
            'layout'    => 'default',
        ]);

        $this->plugin()->onCustomizeRenderView($event);

        $this->assertSame('<div>article</div>', $event->getArgument('output'));
    }
}
