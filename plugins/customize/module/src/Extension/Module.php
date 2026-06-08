<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Customize.module
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Customize\Module\Extension;

use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Customize plugin: edit a module's common settings (title, show title, published) on the page.
 *
 * Modules are marked with data-customize-* by the core ModulesRenderer when customize mode is
 * active; this plugin handles the load/save AJAX through com_ajax (group=customize).
 *
 * @since  1.0.0
 */
final class Module extends CMSPlugin implements SubscriberInterface
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
            'onAjaxModule'         => 'onAjaxModule',
            'onCustomizeAdminInit' => 'onCustomizeAdminInit',
        ];
    }

    /**
     * com_ajax entry point (plugin=module&group=customize): load or save module settings.
     *
     * @param   AjaxEvent  $event  The AJAX event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onAjaxModule(AjaxEvent $event): void
    {
        $input = $this->getApplication()->getInput();

        if (!Session::checkToken('post')) {
            $event->addResult($this->fail(Text::_('JINVALID_TOKEN')));

            return;
        }

        $payload = json_decode($input->get('payload', '', 'raw'), true) ?: [];
        $id      = (int) ($payload['id'] ?? 0);

        if ($id <= 0 || !$this->getApplication()->getIdentity()->authorise('core.edit', 'com_modules.module.' . $id)) {
            $event->addResult($this->fail(Text::_('JERROR_ALERTNOAUTHOR')));

            return;
        }

        $table = $this->getApplication()->bootComponent('com_modules')->getMVCFactory()
            ->createModel('Module', 'Administrator', ['ignore_request' => true])->getTable();

        if (!$table->load($id)) {
            $event->addResult($this->fail(Text::_('PLG_CUSTOMIZE_MODULE_ERROR_INVALID')));

            return;
        }

        switch ($input->getCmd('action', '')) {
            case 'load':
                $event->addResult(json_encode([
                    'success'   => true,
                    'id'        => $id,
                    'title'     => $table->title,
                    'showtitle' => (int) $table->showtitle,
                    'published' => (int) $table->published,
                ]));
                break;

            case 'save':
                $table->title     = trim(strip_tags((string) ($payload['title'] ?? $table->title)));
                $table->showtitle = empty($payload['showtitle']) ? 0 : 1;
                $table->published = (int) ($payload['published'] ?? $table->published);

                if ($table->title === '' || !$table->store()) {
                    $event->addResult($this->fail($table->getError() ?: Text::_('PLG_CUSTOMIZE_MODULE_ERROR_SAVE')));

                    return;
                }

                $event->addResult(json_encode(['success' => true, 'id' => $id]));
                break;

            default:
                $event->addResult($this->fail(Text::_('PLG_CUSTOMIZE_MODULE_ERROR_UNKNOWN_ACTION')));
        }
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

        $keys = [
            'PLG_CUSTOMIZE_MODULE_AREA',
            'PLG_CUSTOMIZE_MODULE_BTN_ADVANCED',
            'PLG_CUSTOMIZE_MODULE_BTN_EDIT',
            'PLG_CUSTOMIZE_MODULE_LOAD_FAILED',
            'PLG_CUSTOMIZE_MODULE_PUBLISHED',
            'PLG_CUSTOMIZE_MODULE_SAVED',
            'PLG_CUSTOMIZE_MODULE_SAVE_ERROR',
            'PLG_CUSTOMIZE_MODULE_SAVE_FAILED',
            'PLG_CUSTOMIZE_MODULE_SHOW_TITLE',
            'PLG_CUSTOMIZE_MODULE_TITLE',
            'PLG_CUSTOMIZE_MODULE_UNKNOWN_ERROR',
        ];

        foreach ($keys as $key) {
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
