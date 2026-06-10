<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Extension
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Customize\Content\Extension;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Customize\CustomizeMode;
use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\Event\Dispatcher;
use Joomla\Input\Input;
use Joomla\Plugin\Customize\Content\Extension\Content;
use Joomla\Registry\Registry;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Test class for the Customize - Content plugin.
 *
 * @package     Joomla.UnitTest
 * @subpackage  Extension
 *
 * @testdox     The Customize Content plugin
 *
 * @since       __DEPLOY_VERSION__
 */
class ContentTest extends UnitTestCase
{
    /**
     * Reset the CustomizeMode static flag before each test.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function setUp(): void
    {
        parent::setUp();

        (new \ReflectionClass(CustomizeMode::class))->getProperty('active')->setValue(null, false);
    }

    /**
     * Put CustomizeMode into the active state.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function activate(): void
    {
        $app = $this->createMock(CMSApplicationInterface::class);
        $app->method('getInput')->willReturn(new Input(['customize' => '1']));

        CustomizeMode::detect($app);
    }

    /**
     * Build the plugin.
     *
     * @return  Content
     *
     * @since   __DEPLOY_VERSION__
     */
    private function plugin(): Content
    {
        return new Content(new Dispatcher(), ['params' => []]);
    }

    /**
     * Build an onContentPrepare event for the given context and item.
     *
     * @param   string  $context  The content context.
     * @param   object  $item     The content item (modified in place).
     *
     * @return  ContentPrepareEvent
     *
     * @since   __DEPLOY_VERSION__
     */
    private function event(string $context, object $item): ContentPrepareEvent
    {
        return new ContentPrepareEvent('onContentPrepare', [
            'context' => $context,
            'subject' => $item,
            'params'  => new Registry(),
            'page'    => 0,
        ]);
    }

    /**
     * @testdox  wraps an article's text in a content editing area when customize mode is active
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testWrapsTextWhenActive()
    {
        $this->activate();
        $item = (object) ['id' => 7, 'title' => 'My article', 'text' => '<p>Body</p>'];

        $this->plugin()->onContentPrepare($this->event('com_content.featured', $item));

        $this->assertStringContainsString('data-customize-type="content"', $item->text);
        $this->assertStringContainsString('data-customize-id="7"', $item->text);
        $this->assertStringContainsString('data-customize-field="introtext"', $item->text);
        $this->assertStringContainsString('<p>Body</p>', $item->text);
    }

    /**
     * @testdox  uses an empty field on the full article view so combined intro+full text is not edited
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testFullArticleWithFulltextGetsEmptyField()
    {
        $this->activate();
        $item = (object) ['id' => 9, 'title' => 'A', 'text' => 'x', 'fulltext' => 'more'];

        $this->plugin()->onContentPrepare($this->event('com_content.article', $item));

        $this->assertStringContainsString('data-customize-field=""', $item->text);
    }

    /**
     * @testdox  leaves the text untouched when customize mode is inactive
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testInactiveLeavesTextUntouched()
    {
        $item = (object) ['id' => 7, 'title' => 'A', 'text' => 'x'];

        $this->plugin()->onContentPrepare($this->event('com_content.featured', $item));

        $this->assertSame('x', $item->text);
    }

    /**
     * @testdox  ignores non-com_content contexts
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testNonContentContextIgnored()
    {
        $this->activate();
        $item = (object) ['id' => 7, 'title' => 'A', 'text' => 'x'];

        $this->plugin()->onContentPrepare($this->event('com_contact.contact', $item));

        $this->assertSame('x', $item->text);
    }

    /**
     * @testdox  wraps the text only once across repeated prepares
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testWrapsOnlyOnce()
    {
        $this->activate();
        $item   = (object) ['id' => 7, 'title' => 'A', 'text' => 'x'];
        $plugin = $this->plugin();

        $plugin->onContentPrepare($this->event('com_content.featured', $item));
        $wrapped = $item->text;
        $plugin->onContentPrepare($this->event('com_content.featured', $item));

        $this->assertSame($wrapped, $item->text);
    }
}
