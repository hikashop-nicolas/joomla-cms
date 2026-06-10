<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Extension
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Customize\Language\Extension;

use Joomla\Plugin\Customize\Language\Extension\LanguageEditor;
use Joomla\Tests\Unit\UnitTestCase;

/**
 * Test class for the Customize - Language plugin.
 *
 * @package     Joomla.UnitTest
 * @subpackage  Extension
 *
 * @testdox     The Customize Language plugin
 *
 * @since       __DEPLOY_VERSION__
 */
class LanguageTest extends UnitTestCase
{
    /**
     * Invoke a private static method on the plugin.
     *
     * @param   string  $method  The method name.
     * @param   string  $arg     The single string argument.
     *
     * @return  mixed
     *
     * @since   __DEPLOY_VERSION__
     */
    private function call(string $method, string $arg)
    {
        $reflection = new \ReflectionMethod(LanguageEditor::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, $arg);
    }

    /**
     * @testdox  normalises a valid override key to the upper-case identifier
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testSanitizeKeyNormalisesValidIdentifier()
    {
        $this->assertSame('COM_EXAMPLE_KEY', $this->call('sanitizeKey', '  com_example_key '));
    }

    /**
     * @testdox  rejects keys that are empty or contain INI-breaking or path characters
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testSanitizeKeyRejectsInvalidKeys()
    {
        $this->assertNull($this->call('sanitizeKey', ''));
        $this->assertNull($this->call('sanitizeKey', 'BAD KEY'));
        $this->assertNull($this->call('sanitizeKey', 'A=B'));
        $this->assertNull($this->call('sanitizeKey', '../ETC'));
        $this->assertNull($this->call('sanitizeKey', 'KEY"X'));
    }

    /**
     * @testdox  keeps a well-formed language tag
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testSafeLanguageTagKeepsWellFormedTags()
    {
        $this->assertSame('en-GB', $this->call('safeLanguageTag', 'en-GB'));
        $this->assertSame('fr', $this->call('safeLanguageTag', 'fr'));
        $this->assertSame('zh-Hans', $this->call('safeLanguageTag', 'zh-Hans'));
    }

    /**
     * @testdox  falls back to en-GB for a malformed tag so it cannot carry a path separator
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function testSafeLanguageTagFallsBackForMalformedTags()
    {
        $this->assertSame('en-GB', $this->call('safeLanguageTag', '../../etc'));
        $this->assertSame('en-GB', $this->call('safeLanguageTag', 'en-GB/../x'));
        $this->assertSame('en-GB', $this->call('safeLanguageTag', ''));
    }
}
