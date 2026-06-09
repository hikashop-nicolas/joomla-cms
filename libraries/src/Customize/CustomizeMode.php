<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Customize;

use Joomla\CMS\Application\CMSApplicationInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Tracks whether the visual frontend "Customize" mode is active for the current request.
 *
 * The mode is active only when the request carries the "customize=1" URL parameter. It is NOT made
 * sticky in the session, so normal browsing is never affected; the admin Customize view's engine
 * carries the flag across in-iframe navigation (links and forms) instead.
 *
 * Activation is intentionally not gated by user group: a frontend page in customize mode only gains
 * harmless, invisible data-customize-* wrappers. All editing UI lives in the login-protected admin
 * Customize view, and every save is independently authorised in its handler.
 *
 * @since  __DEPLOY_VERSION__
 */
final class CustomizeMode
{
    /**
     * Resolved state for the current request.
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    private static $active = false;

    /**
     * Map of language key => translated string used during this request (customize mode only).
     *
     * @var    array<string, string>
     * @since  __DEPLOY_VERSION__
     */
    private static $strings = [];

    /**
     * Map of rendered sprintf result => ['key' => key, 'format' => format], so a composed string
     * like "Written by: Admin" can be matched on the page and its format edited (customize mode).
     *
     * @var    array<string, array{key: string, format: string}>
     * @since  __DEPLOY_VERSION__
     */
    private static $sprintf = [];

    /**
     * Resolve the customize state for the current request from the URL flag.
     *
     * @param   CMSApplicationInterface  $app  The current application.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function detect(CMSApplicationInterface $app): void
    {
        self::$active = $app->getInput()->getInt('customize', 0) === 1;
    }

    /**
     * Whether customize mode is active for the current request.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * Record a key => translated string pair, so the customize editor can map on-page text back to
     * its language key without altering the rendered output.
     *
     * @param   string  $key   The (upper-cased) language key.
     * @param   string  $text  The translated string.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function recordString(string $key, string $text): void
    {
        if (!isset(self::$strings[$key]) && self::isRecordable($text)) {
            self::$strings[$key] = $text;
        }
    }

    /**
     * Whether a string is suitable to record: short, plain, single-line text. This keeps the map from
     * breaking the JSON island or carrying HTML/composed output that would not match a text node.
     *
     * @param   string  $text  The candidate string.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function isRecordable(string $text): bool
    {
        return $text !== '' && \strlen($text) <= 200 && !str_contains($text, '<') && !preg_match('/[\x00-\x1F]/', $text);
    }

    /**
     * Get the recorded key => translated string map.
     *
     * @return  array<string, string>
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function getStrings(): array
    {
        return self::$strings;
    }

    /**
     * Record a composed (sprintf) string: its rendered result mapped to its key and format.
     *
     * @param   string  $key       The (upper-cased) language key.
     * @param   string  $format    The translated format string (with placeholders).
     * @param   string  $rendered  The result after substitution.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function recordSprintf(string $key, string $format, string $rendered): void
    {
        // Nothing useful to match if no substitution happened, or if the result is not plain text
        // (HTML-bearing composed strings won't match a text node; the format prefix is used instead).
        if ($rendered === $format || !self::isRecordable($rendered)) {
            return;
        }

        if (!isset(self::$sprintf[$rendered])) {
            self::$sprintf[$rendered] = ['key' => $key, 'format' => $format];
        }
    }

    /**
     * Get the recorded rendered-result => {key, format} map for composed strings.
     *
     * @return  array<string, array{key: string, format: string}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function getSprintf(): array
    {
        return self::$sprintf;
    }
}
