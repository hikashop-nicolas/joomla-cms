<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Extension
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Customize\Position\Extension;

use Joomla\CMS\Event\GenericEvent;
use Joomla\Event\Dispatcher;
use Joomla\Plugin\Customize\Position\Extension\Position;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Test class for the Customize - Position plugin.
 *
 * @package     Joomla.UnitTest
 * @subpackage  Extension
 *
 * @testdox     The Customize Position plugin
 *
 * @since       __DEPLOY_VERSION__
 */
class PositionTest extends UnitTestCase
{
    /**
     * @testdox  emits a drop zone carrying the position for an empty position
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testEmitsDropZoneForThePosition()
    {
        $event = new GenericEvent('onCustomizeEmptyPosition', ['subject' => 'sidebar-a', 'content' => '']);

        (new Position(new Dispatcher(), ['params' => []]))->onCustomizeEmptyPosition($event);

        $html = (string) $event->getArgument('content');

        $this->assertStringContainsString('class="customize-empty-position"', $html);
        $this->assertStringContainsString('data-customize-dropzone="module"', $html);
        $this->assertStringContainsString('data-customize-droppos="sidebar-a"', $html);
        $this->assertStringContainsString('>sidebar-a</div>', $html);
    }

    /**
     * @testdox  escapes the position name in the drop zone
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPositionNameIsHtmlEscaped()
    {
        $event = new GenericEvent('onCustomizeEmptyPosition', ['subject' => 'a"b', 'content' => '']);

        (new Position(new Dispatcher(), ['params' => []]))->onCustomizeEmptyPosition($event);

        $this->assertStringContainsString('data-customize-droppos="a&quot;b"', (string) $event->getArgument('content'));
    }
}
