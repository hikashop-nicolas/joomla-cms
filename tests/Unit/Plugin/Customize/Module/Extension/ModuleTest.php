<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Extension
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Customize\Module\Extension;

use Joomla\CMS\Event\GenericEvent;
use Joomla\Event\Dispatcher;
use Joomla\Plugin\Customize\Module\Extension\Module;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Test class for the Customize - Module plugin.
 *
 * @package     Joomla.UnitTest
 * @subpackage  Extension
 *
 * @testdox     The Customize Module plugin
 *
 * @since       __DEPLOY_VERSION__
 */
class ModuleTest extends UnitTestCase
{
    /**
     * Build the plugin instance.
     *
     * @return  Module
     *
     * @since   __DEPLOY_VERSION__
     */
    private function plugin(): Module
    {
        return new Module(new Dispatcher(), ['params' => []]);
    }

    /**
     * Run onCustomizeModule and return the resulting output.
     *
     * @param   string  $output    The module HTML.
     * @param   object  $module    The module record.
     * @param   string  $position  The position.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function instrument(string $output, object $module, string $position = 'sidebar'): string
    {
        $event = new GenericEvent('onCustomizeModule', [
            'subject'  => $module,
            'position' => $position,
            'output'   => $output,
        ]);

        $this->plugin()->onCustomizeModule($event);

        return (string) $event->getArgument('output');
    }

    /**
     * @testdox  injects the base data-customize attributes into the module's first tag
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testInjectsBaseAttributesIntoFirstTag()
    {
        $module = (object) ['id' => 7, 'module' => 'mod_menu', 'title' => 'Main Menu'];
        $html   = $this->instrument('<div class="moduletable">content</div>', $module);

        $this->assertStringContainsString('data-customize-type="module"', $html);
        $this->assertStringContainsString('data-customize-id="7"', $html);
        $this->assertStringContainsString('data-customize-position="sidebar"', $html);
        $this->assertStringContainsString('data-customize-module="mod_menu"', $html);
        $this->assertStringContainsString('data-customize-name="Main Menu"', $html);
        $this->assertStringContainsString('>content</div>', $html);
    }

    /**
     * @testdox  flags mod_custom modules so the in-place content editor is offered
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testCustomModulesGetTheCustomFlag()
    {
        $html = $this->instrument('<div>x</div>', (object) ['id' => 3, 'module' => 'mod_custom', 'title' => 'Promo']);

        $this->assertStringContainsString('data-customize-custom="1"', $html);
    }

    /**
     * @testdox  does not flag other module types
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testNonCustomModulesHaveNoCustomFlag()
    {
        $html = $this->instrument('<div>x</div>', (object) ['id' => 3, 'module' => 'mod_menu', 'title' => 'Menu']);

        $this->assertStringNotContainsString('data-customize-custom', $html);
    }

    /**
     * @testdox  escapes the module name
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testNameIsHtmlEscaped()
    {
        $html = $this->instrument('<div>x</div>', (object) ['id' => 1, 'module' => 'mod_menu', 'title' => 'A & "B"']);

        $this->assertStringContainsString('data-customize-name="A &amp; &quot;B&quot;"', $html);
    }

    /**
     * @testdox  leaves empty output untouched
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testEmptyOutputIsLeftUntouched()
    {
        $event = new GenericEvent('onCustomizeModule', [
            'subject'  => (object) ['id' => 1, 'module' => 'mod_menu', 'title' => 'T'],
            'position' => 'p',
            'output'   => '   ',
        ]);

        $this->plugin()->onCustomizeModule($event);

        $this->assertSame('   ', $event->getArgument('output'));
    }
}
