<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Customize
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Customize;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Customize\CustomizeMode;
use Joomla\CMS\Customize\LayoutHelper;
use Joomla\CMS\Factory;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Test class for \Joomla\CMS\Customize\LayoutHelper.
 *
 * @package     Joomla.UnitTest
 * @subpackage  Customize
 *
 * @testdox     The Customize layout helper
 *
 * @since       __DEPLOY_VERSION__
 */
class LayoutHelperTest extends UnitTestCase
{
    /**
     * The template element whose manifest the tests read (a throwaway dir under templates/).
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    private const ELEMENT = 'customize_unit_test_tmpl';

    /**
     * The Factory application present before a test, restored afterwards.
     *
     * @var    \Joomla\CMS\Application\CMSApplicationInterface|null
     * @since  __DEPLOY_VERSION__
     */
    private $originalApp = null;

    /**
     * Reset the customize/helper static state and point the application at the test template.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalApp = Factory::$application;

        (new \ReflectionProperty(CustomizeMode::class, 'active'))->setValue(null, false);
        (new \ReflectionProperty(CustomizeMode::class, 'showAllPositions'))->setValue(null, false);
        (new \ReflectionProperty(LayoutHelper::class, 'splitCssDone'))->setValue(null, false);
        LayoutHelper::clearArrangementCache();

        $app = $this->getMockBuilder(CMSApplication::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTemplate'])
            ->getMockForAbstractClass();
        $app->method('getTemplate')->willReturn(self::ELEMENT);
        Factory::$application = $app;
    }

    /**
     * Restore the application and remove the test manifest.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function tearDown(): void
    {
        Factory::$application = $this->originalApp;
        LayoutHelper::clearArrangementCache();

        $file = JPATH_ROOT . '/templates/' . self::ELEMENT . '/customize-positions.json';
        $dir  = \dirname($file);

        if (is_file($file)) {
            unlink($file);
        }

        if (is_dir($dir)) {
            @rmdir($dir);
        }

        parent::tearDown();
    }

    /**
     * Set whether customize mode is active for the request.
     *
     * @param   boolean  $active  The state.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function setActive(bool $active): void
    {
        (new \ReflectionProperty(CustomizeMode::class, 'active'))->setValue(null, $active);
    }

    /**
     * Write the test template's manifest and invalidate the helper cache.
     *
     * @param   array  $data  The manifest payload.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function writeManifest(array $data): void
    {
        $dir = JPATH_ROOT . '/templates/' . self::ELEMENT;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir . '/customize-positions.json', json_encode($data));
        LayoutHelper::clearArrangementCache();
    }

    /**
     * @testdox  exposes the known split layouts (columns, ratios, rows, grid)
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testSplitLayoutsExposeTheKnownSet()
    {
        $layouts = LayoutHelper::splitLayouts();

        foreach (['cols-2', 'cols-2-13', 'cols-3-121', 'cols-4', 'rows-2', 'grid-2x2'] as $id) {
            $this->assertArrayHasKey($id, $layouts);
        }

        $this->assertSame([1, 2], LayoutHelper::splitLayout('cols-2-13')['weights']);
        $this->assertSame(3, LayoutHelper::splitLayout('cols-3-121')['n']);
        $this->assertTrue(LayoutHelper::splitLayout('rows-2')['rows']);
        $this->assertTrue(LayoutHelper::splitLayout('grid-2x2')['grid']);
        $this->assertNull(LayoutHelper::splitLayout('does-not-exist'));
    }

    /**
     * @testdox  renders bare blocks (no markup) on the live site
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testGridRendersBareBlocksWhenInactive()
    {
        $this->setActive(false);

        $html = LayoutHelper::grid('main', [
            ['id' => 'a', 'html' => '<p>A</p>'],
            ['id' => 'b', 'html' => '<p>B</p>'],
        ]);

        $this->assertSame('<p>A</p><p>B</p>', $html);
    }

    /**
     * @testdox  wraps blocks in the data-customize contract in customize mode
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testGridWrapsBlocksWhenActive()
    {
        $this->setActive(true);

        $html = LayoutHelper::grid('main', [
            ['id' => 'main-top', 'position' => 'main-top', 'html' => '<p>T</p>'],
            ['id' => 'component', 'html' => '<main>C</main>', 'removable' => false],
        ]);

        $this->assertStringContainsString('data-customize-type="layout-block"', $html);
        $this->assertStringContainsString('data-customize-block="main-top"', $html);
        // A module-position block can be split; the component block (no position) cannot.
        $this->assertStringContainsString('data-customize-splittable', $html);
        $this->assertStringContainsString('data-customize-block="component"', $html);
    }

    /**
     * @testdox  applies the saved order and drops hidden blocks
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testGridAppliesOrderAndHidden()
    {
        $this->setActive(false);
        $this->writeManifest([
            'version' => 2,
            'grids'   => ['main' => ['order' => ['b', 'a'], 'hidden' => ['c'], 'added' => []]],
        ]);

        $html = LayoutHelper::grid('main', [
            ['id' => 'a', 'html' => 'A'],
            ['id' => 'b', 'html' => 'B'],
            ['id' => 'c', 'html' => 'C'],
        ]);

        // Reordered to [b, a]; c is hidden.
        $this->assertSame('BA', $html);
    }

    /**
     * @testdox  migrates a v1 manifest to per-grid added positions
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testReadArrangementMigratesV1ToAddedPositions()
    {
        $this->writeManifest(['positions' => [['name' => 'foo', 'region' => 'main']]]);

        $arr = LayoutHelper::readArrangement(self::ELEMENT);

        $this->assertContains('foo', $arr['grids']['main']['added']);
        $this->assertSame([], $arr['splits']);
    }

    /**
     * @testdox  reads a v2 manifest's grids and flat splits
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testReadArrangementReadsV2GridsAndSplits()
    {
        $this->writeManifest([
            'version' => 2,
            'grids'   => ['main' => ['order' => ['breadcrumbs', 'main-top'], 'hidden' => [], 'added' => []]],
            'splits'  => ['top-a' => ['layout' => 'cols-2', 'positions' => ['top-a', 'top-a-2']]],
        ]);

        $arr = LayoutHelper::readArrangement(self::ELEMENT);

        $this->assertSame(['breadcrumbs', 'main-top'], $arr['grids']['main']['order']);
        $this->assertSame('cols-2', $arr['splits']['top-a']['layout']);
    }

    /**
     * @testdox  migrates legacy per-grid splits up to the flat split map
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testReadArrangementMigratesLegacyNestedSplits()
    {
        $this->writeManifest([
            'version' => 2,
            'grids'   => [
                'main' => [
                    'order'  => [],
                    'splits' => ['modulebanner' => ['layout' => 'cols-2', 'positions' => ['modulebanner', 'modulebanner-2']]],
                ],
            ],
        ]);

        $arr = LayoutHelper::readArrangement(self::ELEMENT);

        $this->assertArrayHasKey('modulebanner', $arr['splits']);
        $this->assertArrayNotHasKey('splits', $arr['grids']['main']);
    }

    /**
     * @testdox  treats a split position's container as a plain wrapper, an unsplit one as splittable
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPositionAttrsAndIsSplit()
    {
        $this->setActive(true);
        $this->writeManifest([
            'version' => 2,
            'grids'   => ['main' => ['order' => []]],
            'splits'  => ['top-a' => ['layout' => 'cols-2', 'positions' => ['top-a', 'top-a-2']]],
        ]);

        $this->assertTrue(LayoutHelper::isSplit('top-a'));
        $this->assertFalse(LayoutHelper::isSplit('banner'));

        // The split owner's container carries no contract (its cells do).
        $this->assertSame('', LayoutHelper::positionAttrs('top-a'));

        // An unsplit position is a splittable layout-position area.
        $attrs = LayoutHelper::positionAttrs('banner');
        $this->assertStringContainsString('data-customize-type="layout-position"', $attrs);
        $this->assertStringContainsString('data-customize-block="banner"', $attrs);
        $this->assertStringContainsString('data-customize-splittable', $attrs);
    }

    /**
     * @testdox  renders a split position as a flex row of selectable sub-position cells
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPositionRendersTheSplitRow()
    {
        $this->setActive(true);
        $this->writeManifest([
            'version' => 2,
            'grids'   => ['main' => ['order' => []]],
            'splits'  => ['top-a' => ['layout' => 'cols-2', 'positions' => ['top-a', 'top-a-2']]],
        ]);

        $html = LayoutHelper::position('top-a', '<jdoc:include type="modules" name="top-a" style="card" />');

        $this->assertStringContainsString('class="customize-split"', $html);
        $this->assertStringContainsString('name="top-a-2"', $html);
        $this->assertStringContainsString('data-customize-split-owner="top-a"', $html);
    }

    /**
     * @testdox  returns the bare include for a position that is not split
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testPositionReturnsDefaultWhenNotSplit()
    {
        $include = '<jdoc:include type="modules" name="banner" style="none" />';

        $this->assertSame($include, LayoutHelper::position('banner', $include));
    }
}
