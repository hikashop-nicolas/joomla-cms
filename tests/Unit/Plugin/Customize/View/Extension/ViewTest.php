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
     * A real core sub-layout file (relative to JPATH_SITE) used as a resolvable source.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    private const SOURCE = 'components/com_content/tmpl/featured/default_item.php';

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

        $plugin = new View(['params' => []]);
        $plugin->setApplication($app);

        return $plugin;
    }

    /**
     * Build an onCustomizeRenderView event.
     *
     * @param   string  $block  The sub-layout (block) name.
     * @param   string  $file   The absolute path of the file core rendered.
     *
     * @return  GenericEvent
     *
     * @since   __DEPLOY_VERSION__
     */
    private function renderEvent(string $block, string $file): GenericEvent
    {
        return new GenericEvent('onCustomizeRenderView', [
            'output'    => '<div>article</div>',
            'block'     => $block,
            'component' => 'com_content',
            'view'      => 'featured',
            'layout'    => 'default',
            'file'      => $file,
        ]);
    }

    /**
     * @testdox  wraps a sub-layout in comment markers carrying its identity and resolved source
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testWrapsOutputWithBlockMarkers()
    {
        $event = $this->renderEvent('item', JPATH_SITE . '/' . self::SOURCE);

        $this->plugin()->onCustomizeRenderView($event);

        $html = (string) $event->getArgument('output');

        $this->assertStringContainsString('<!--customize-block-start:', $html);
        $this->assertStringContainsString('component=com_content', $html);
        $this->assertStringContainsString('view=featured', $html);
        $this->assertStringContainsString('block=item', $html);
        $this->assertStringContainsString('template=cassiopeia', $html);
        $this->assertStringContainsString('source=' . self::SOURCE, $html);
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
        $event = $this->renderEvent('', JPATH_SITE . '/' . self::SOURCE);

        $this->plugin()->onCustomizeRenderView($event);

        $this->assertSame('<div>article</div>', $event->getArgument('output'));
    }

    /**
     * @testdox  does not mark a block whose rendered source cannot be resolved under the site
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testSkipsBlockWithoutResolvableSource()
    {
        $event = $this->renderEvent('item', JPATH_SITE . '/components/com_content/tmpl/featured/does_not_exist.php');

        $this->plugin()->onCustomizeRenderView($event);

        $this->assertSame('<div>article</div>', $event->getArgument('output'));
    }

    /**
     * Invoke the private static sanitizeOverrideRequest().
     *
     * @param   array  $payload  The request payload.
     *
     * @return  array|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private function sanitize(array $payload)
    {
        $method = new \ReflectionMethod(View::class, 'sanitizeOverrideRequest');

        return $method->invoke(null, $payload);
    }

    /**
     * @testdox  derives the override file name and relative path from the resolved source
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testOverrideRequestBuildsExpectedPaths()
    {
        $parts = $this->sanitize([
            'type'      => 'view',
            'component' => 'com_content',
            'view'      => 'featured',
            'template'  => 'cassiopeia',
            'source'    => self::SOURCE,
        ]);

        $this->assertSame(self::SOURCE, $parts['source']);
        $this->assertSame('default_item.php', $parts['fileName']);
        $this->assertSame('/html/com_content/featured/default_item.php', $parts['relPath']);
        $this->assertSame('cassiopeia', $parts['template']);
        $this->assertSame('view', $parts['type']);
    }

    /**
     * @testdox  rejects a source that traverses, escapes components/, is not PHP, or does not exist
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testOverrideRequestRejectsUnsafeSource()
    {
        $base = ['type' => 'view', 'component' => 'com_content', 'view' => 'featured'];

        // Path traversal.
        $this->assertNull($this->sanitize($base + ['source' => 'components/com_content/../../configuration.php']));
        // Outside the components/ tree.
        $this->assertNull($this->sanitize($base + ['source' => 'configuration.php']));
        // Not a PHP file.
        $this->assertNull($this->sanitize($base + ['source' => 'components/com_content/tmpl/featured/default_item.html']));
        // Does not exist.
        $this->assertNull($this->sanitize($base + ['source' => 'components/com_content/tmpl/featured/does_not_exist.php']));
    }

    /**
     * @testdox  rejects a request missing the component, view or source
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testOverrideRequestRejectsMissingSegments()
    {
        $this->assertNull($this->sanitize(['type' => 'view', 'component' => '', 'view' => 'featured', 'source' => self::SOURCE]));
        $this->assertNull($this->sanitize(['type' => 'view', 'component' => 'com_content', 'view' => '', 'source' => self::SOURCE]));
        $this->assertNull($this->sanitize(['type' => 'view', 'component' => 'com_content', 'view' => 'featured', 'source' => '']));
        // A missing/invalid type is itself rejected.
        $this->assertNull($this->sanitize(['component' => 'com_content', 'view' => 'featured', 'source' => self::SOURCE]));
    }
}
