<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Customize.language
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Customize\Language\Extension;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Customize\CustomizeMode;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Customize plugin: edit translated strings on the page and save them as Joomla language overrides.
 *
 * Core Language::_ wraps each translation with its key in customize mode; this plugin's JS turns
 * those into editable areas, and the save writes a standard language override that Joomla applies
 * natively for everyone.
 *
 * @since  1.0.0
 */
final class LanguageEditor extends CMSPlugin implements SubscriberInterface
{
    /**
     * Load the plugin language file on instantiation.
     *
     * @var    boolean
     * @since  1.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * Returns the events this plugin subscribes to.
     *
     * @return  array
     *
     * @since   1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onAjaxLanguage'       => 'onAjaxLanguage',
            'onAfterRender'        => 'onAfterRender',
            'onCustomizeAdminInit' => 'onCustomizeAdminInit',
        ];
    }

    /**
     * On a frontend page in customize mode, append the recorded key => text map as a JSON island so
     * the editor can map on-page text back to its language key. The rendered page is left untouched.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onAfterRender(): void
    {
        if (!CustomizeMode::isActive()) {
            return;
        }

        $strings = CustomizeMode::getStrings();
        $sprintf = CustomizeMode::getSprintf();

        if (!$strings && !$sprintf) {
            return;
        }

        $app  = $this->getApplication();
        $body = $app->getBody();

        if (stripos($body, '</body>') === false) {
            return;
        }

        $json = json_encode(['strings' => $strings, 'sprintf' => $sprintf], JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            return;
        }

        // Base64 so no recorded value (which may contain HTML or even a stray </script>) can break
        // the island tag; the editor decodes it.
        $script = '<script type="application/json" id="customize-lang-map">' . base64_encode($json) . '</script>';

        $app->setBody(preg_replace('/<\/body>/i', $script . '</body>', $body, 1));
    }

    /**
     * com_ajax entry point (plugin=language&group=customize): save a language override.
     *
     * @param   AjaxEvent  $event  The AJAX event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onAjaxLanguage(AjaxEvent $event): void
    {
        if (!Session::checkToken('post')) {
            $event->addResult($this->fail(Text::_('JINVALID_TOKEN')));

            return;
        }

        if ($this->getApplication()->getInput()->getCmd('action', '') !== 'save') {
            $event->addResult($this->fail(Text::_('PLG_CUSTOMIZE_LANGUAGE_ERROR_INVALID')));

            return;
        }

        // Language overrides inject text site-wide, so require a global administrator.
        if (!$this->getApplication()->getIdentity()->authorise('core.admin')) {
            $event->addResult($this->fail(Text::_('JERROR_ALERTNOAUTHOR')));

            return;
        }

        $payload = json_decode($this->getApplication()->getInput()->get('payload', '', 'raw'), true) ?: [];
        $key     = strtoupper(trim((string) ($payload['key'] ?? '')));
        $value   = (string) ($payload['value'] ?? '');

        if ($key === '' || !preg_match('/^[A-Z0-9_]+$/', $key)) {
            $event->addResult($this->fail(Text::_('PLG_CUSTOMIZE_LANGUAGE_ERROR_INVALID')));

            return;
        }

        // Target the default site language.
        $tag = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');

        if (!preg_match('/^[A-Za-z]{2,3}(-[A-Za-z]{2,4})?$/', $tag)) {
            $tag = 'en-GB';
        }

        $file    = JPATH_SITE . '/language/overrides/' . $tag . '.override.ini';
        $strings = is_file($file) ? LanguageHelper::parseIniFile($file) : [];

        $strings[$key] = $value;

        if (LanguageHelper::saveToIniFile($file, $strings) === false) {
            $event->addResult($this->fail(Text::_('PLG_CUSTOMIZE_LANGUAGE_SAVE_FAILED')));

            return;
        }

        $event->addResult(json_encode(['success' => true]));
    }

    /**
     * Register this plugin's JS strings for Joomla.Text on the customize admin page.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onCustomizeAdminInit(): void
    {
        $this->loadLanguage();

        foreach (
            [
                'PLG_CUSTOMIZE_LANGUAGE_AREA',
                'PLG_CUSTOMIZE_LANGUAGE_BTN_EDIT',
                'PLG_CUSTOMIZE_LANGUAGE_FORMAT_HINT',
                'PLG_CUSTOMIZE_LANGUAGE_LABEL',
                'PLG_CUSTOMIZE_LANGUAGE_SAVED',
                'PLG_CUSTOMIZE_LANGUAGE_SAVE_ERROR',
                'PLG_CUSTOMIZE_LANGUAGE_SAVE_FAILED',
                'PLG_CUSTOMIZE_LANGUAGE_UNKNOWN_ERROR',
            ] as $key
        ) {
            Text::script($key);
        }
    }

    /**
     * Build a JSON failure envelope.
     *
     * @param   string  $message  The message.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function fail(string $message): string
    {
        return json_encode(['success' => false, 'message' => $message]);
    }
}
