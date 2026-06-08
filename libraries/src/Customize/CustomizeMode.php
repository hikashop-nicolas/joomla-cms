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
 * Admin and site use separate sessions, so the mode is carried into the frontend iframe by the
 * "customize=1" URL parameter on first load and then kept sticky in the frontend session, so that
 * subsequent in-iframe navigation keeps emitting the editable-area markup without re-passing the flag.
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
     * Session key holding the sticky customize flag for the current (frontend) session.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    public const SESSION_KEY = 'customize.active';

    /**
     * Resolved state for the current request.
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    private static $active = false;

    /**
     * Resolve the customize state for the current request from the URL flag and the session.
     *
     * @param   CMSApplicationInterface  $app  The current application.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function detect(CMSApplicationInterface $app): void
    {
        $session = $app->getSession();

        if ($app->getInput()->getInt('customize', 0) === 1) {
            $session->set(self::SESSION_KEY, true);
        }

        self::$active = (bool) $session->get(self::SESSION_KEY, false);
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
     * Turn customize mode off for the current session.
     *
     * @param   CMSApplicationInterface  $app  The current application.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function deactivate(CMSApplicationInterface $app): void
    {
        $app->getSession()->set(self::SESSION_KEY, false);
        self::$active = false;
    }
}
