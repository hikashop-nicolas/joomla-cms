<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Customize
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Libraries\Cms\Customize;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Customize\CustomizeMode;
use Joomla\CMS\Factory;
use Joomla\Input\Input;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Test class for \Joomla\CMS\Customize\CustomizeMode.
 *
 * @package     Joomla.UnitTest
 * @subpackage  Customize
 * @since       __DEPLOY_VERSION__
 */
class CustomizeModeTest extends UnitTestCase
{
    /**
     * The Factory application present before a test, restored afterwards.
     *
     * @var    CMSApplicationInterface|null
     * @since  __DEPLOY_VERSION__
     */
    private $originalApp = null;

    /**
     * Reset the class's static state before each test.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalApp = Factory::$application;

        $reflection = new \ReflectionClass(CustomizeMode::class);

        foreach (['active' => false, 'showAllPositions' => false, 'strings' => [], 'sprintf' => []] as $property => $value) {
            $reflection->getProperty($property)->setValue(null, $value);
        }
    }

    /**
     * Restore the Factory application after each test (tests that validate a token set it).
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function tearDown(): void
    {
        Factory::$application = $this->originalApp;

        parent::tearDown();
    }

    /**
     * Build an application stub whose input carries the given "customize" value.
     *
     * @param   mixed  $value  The value of the "customize" request parameter (null for absent).
     *
     * @return  CMSApplicationInterface
     *
     * @since   __DEPLOY_VERSION__
     */
    private function applicationWithCustomize($value): CMSApplicationInterface
    {
        $data = $value === null ? [] : ['customize' => $value];

        $app = $this->createMock(CMSApplicationInterface::class);
        $app->method('getInput')->willReturn(new Input($data));

        return $app;
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testIsInactiveByDefault()
    {
        $this->assertFalse(CustomizeMode::isActive());
    }

    /**
     * Build a Factory application whose site secret signs/validates customize tokens.
     *
     * @return  CMSApplicationInterface
     *
     * @since   __DEPLOY_VERSION__
     */
    private function signerApp(): CMSApplicationInterface
    {
        $app = $this->createMock(CMSApplicationInterface::class);
        $app->method('get')->willReturnCallback(
            static fn ($key, $default = null) => $key === 'secret' ? 'unit-test-secret' : $default
        );

        return $app;
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDetectActivatesWithAValidToken()
    {
        // mintToken + validateToken both sign with the Factory application's secret.
        Factory::$application = $this->signerApp();
        $token                = CustomizeMode::mintToken(42);

        CustomizeMode::detect($this->applicationWithCustomize($token));

        $this->assertTrue(CustomizeMode::isActive());
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDetectRejectsAnExpiredToken()
    {
        Factory::$application = $this->signerApp();
        $token                = CustomizeMode::mintToken(42, -10);

        CustomizeMode::detect($this->applicationWithCustomize($token));

        $this->assertFalse(CustomizeMode::isActive());
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDetectRejectsATamperedToken()
    {
        Factory::$application = $this->signerApp();
        $token                = CustomizeMode::mintToken(42);
        $tampered             = substr($token, 0, -1) . (substr($token, -1) === 'a' ? 'b' : 'a');

        CustomizeMode::detect($this->applicationWithCustomize($tampered));

        $this->assertFalse(CustomizeMode::isActive());
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDetectStaysInactiveWithoutTheFlag()
    {
        CustomizeMode::detect($this->applicationWithCustomize(null));

        $this->assertFalse(CustomizeMode::isActive());
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testDetectStaysInactiveForOtherValues()
    {
        CustomizeMode::detect($this->applicationWithCustomize('2'));

        $this->assertFalse(CustomizeMode::isActive());
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRecordStringStoresPlainText()
    {
        CustomizeMode::recordString('COM_EXAMPLE_KEY', 'Hello world');

        $this->assertSame(['COM_EXAMPLE_KEY' => 'Hello world'], CustomizeMode::getStrings());
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRecordStringKeepsTheFirstValueForAKey()
    {
        CustomizeMode::recordString('COM_EXAMPLE_KEY', 'First');
        CustomizeMode::recordString('COM_EXAMPLE_KEY', 'Second');

        $this->assertSame(['COM_EXAMPLE_KEY' => 'First'], CustomizeMode::getStrings());
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRecordStringRejectsUnsuitableText()
    {
        CustomizeMode::recordString('EMPTY', '');
        CustomizeMode::recordString('HTML', 'name <b>bold</b>');
        CustomizeMode::recordString('TOO_LONG', str_repeat('x', 201));
        CustomizeMode::recordString('CONTROL', "two\nlines");

        $this->assertSame([], CustomizeMode::getStrings());
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRecordSprintfStoresTheRenderedResult()
    {
        CustomizeMode::recordSprintf('COM_WRITTEN_BY', 'Written by: %s', 'Written by: Admin');

        $this->assertSame(
            ['Written by: Admin' => ['key' => 'COM_WRITTEN_BY', 'format' => 'Written by: %s']],
            CustomizeMode::getSprintf()
        );
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testRecordSprintfSkipsWhenNothingWasSubstituted()
    {
        CustomizeMode::recordSprintf('COM_PLAIN', 'No placeholders here', 'No placeholders here');

        $this->assertSame([], CustomizeMode::getSprintf());
    }
}
