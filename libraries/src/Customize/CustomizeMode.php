<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Customize;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Tracks whether the visual frontend "Customize" mode is active for the current request.
 *
 * The mode is active only when the request carries a valid "customize" token: a short-lived value the
 * admin Customize view mints (signed with the site secret) for an authorised editor. It is NOT made
 * sticky in the session, so normal browsing is never affected; the admin Customize view's engine
 * carries the token across in-iframe navigation (links and forms) and refreshes it before it expires
 * so a long editing session stays valid.
 *
 * Gating on a signed token (rather than a bare flag) keeps anonymous visitors from activating the
 * mode by appending the parameter. Even so, a frontend page in customize mode only gains harmless
 * data-customize-* wrappers; all editing UI lives in the login-protected admin Customize view, and
 * every save is independently authorised in its handler.
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
     * Whether to surface every template position (even empty ones) for this request. Requested by the
     * editor via the "customizepositions" flag, and only honoured while customize mode is active.
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    private static $showAllPositions = false;

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
     * Default token lifetime in seconds.
     *
     * @var    integer
     * @since  __DEPLOY_VERSION__
     */
    private const TOKEN_TTL = 7200;

    /**
     * Resolve the customize state for the current request from the signed "customize" token.
     *
     * @param   CMSApplicationInterface  $app  The current application.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function detect(CMSApplicationInterface $app): void
    {
        self::$active           = self::validateToken((string) $app->getInput()->getCmd('customize', ''), $app);
        self::$showAllPositions = self::$active && (bool) $app->getInput()->getInt('customizepositions', 0);
    }

    /**
     * Whether to surface every template position (even empty ones) for this request. Used by the
     * document's module counter and the modules renderer to reveal the full position map on demand,
     * without the template needing to change.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function showAllPositions(): bool
    {
        return self::$showAllPositions;
    }

    /**
     * Mint a signed, short-lived customize token for an authorised editor. Callers must check the
     * editing permission first; this only signs "user X may customize until time T" with the site
     * secret, so the frontend can trust it without sharing a session.
     *
     * @param   integer  $userId  The editor's user id.
     * @param   integer  $ttl     Lifetime in seconds.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function mintToken(int $userId, int $ttl = self::TOKEN_TTL): string
    {
        $payload = $userId . '.' . (time() + $ttl);

        return $payload . '.' . self::sign($payload);
    }

    /**
     * Validate a customize token: correct shape, authentic signature and not expired.
     *
     * @param   string                   $value  The token from the request.
     * @param   CMSApplicationInterface  $app    The current application.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function validateToken(string $value, CMSApplicationInterface $app): bool
    {
        if (!preg_match('/^(\d+\.\d+)\.([a-f0-9]{64})$/', $value, $m)) {
            return false;
        }

        $payload = $m[1];

        if (!hash_equals(self::sign($payload), $m[2])) {
            return false;
        }

        // payload is "<userId>.<expiry>"; reject once past the expiry.
        return (int) explode('.', $payload)[1] > time();
    }

    /**
     * HMAC a token payload with the site secret.
     *
     * @param   string  $payload  The "<userId>.<expiry>" payload.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) Factory::getApplication()->get('secret'));
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
     * Build the inline notice shown in place of a layout/override that threw while rendering. Because
     * customize mode is only active for a holder of a valid (admin-minted) token, the editor is shown
     * the actual error to help them fix it, along with how to recover.
     *
     * @param   string  $message  The thrown error's message.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function renderError(string $message): string
    {
        return '<div class="customize-render-error" role="alert"><strong>'
            . htmlspecialchars(Text::_('JLIB_CUSTOMIZE_RENDER_ERROR'), ENT_QUOTES) . '</strong>'
            . ($message !== '' ? ' <code>' . htmlspecialchars($message, ENT_QUOTES) . '</code>' : '')
            . '</div>';
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
