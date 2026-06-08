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
}
