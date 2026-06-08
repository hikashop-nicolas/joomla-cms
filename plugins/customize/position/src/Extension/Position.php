<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Customize.position
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Customize\Position\Extension;

use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Customize plugin: reorder modules within a position by drag and drop on the page.
 *
 * Modules are marked with data-customize-* by the core ModulesRenderer; this plugin's JS enables
 * drag reordering and saves the new ordering through com_ajax (group=customize).
 *
 * @since  1.0.0
 */
final class Position extends CMSPlugin implements SubscriberInterface
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
            'onAjaxPosition'       => 'onAjaxPosition',
            'onCustomizeAdminInit' => 'onCustomizeAdminInit',
        ];
    }

    /**
     * com_ajax entry point (plugin=position&group=customize): persist a module ordering.
     *
     * @param   AjaxEvent  $event  The AJAX event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onAjaxPosition(AjaxEvent $event): void
    {
        $input = $this->getApplication()->getInput();

        if (!Session::checkToken('post')) {
            $event->addResult($this->fail(Text::_('JINVALID_TOKEN')));

            return;
        }

        $payload  = json_decode($input->get('payload', '', 'raw'), true) ?: [];
        $position = trim((string) ($payload['position'] ?? ''));

        if ($input->getCmd('action', '') !== 'reorder' || $position === '' || empty($payload['order']) || !\is_array($payload['order'])) {
            $event->addResult($this->fail(Text::_('PLG_CUSTOMIZE_POSITION_ERROR_INVALID')));

            return;
        }

        $user = $this->getApplication()->getIdentity();
        $db   = Factory::getContainer()->get(DatabaseInterface::class);
        $i    = 1;

        foreach ($payload['order'] as $moduleId) {
            $moduleId = (int) $moduleId;

            if ($moduleId <= 0 || !$user->authorise('core.edit', 'com_modules.module.' . $moduleId)) {
                continue;
            }

            // Set ordering and position so a module dragged in from another position moves here.
            $query = $db->createQuery()
                ->update($db->quoteName('#__modules'))
                ->set($db->quoteName('ordering') . ' = :ord')
                ->set($db->quoteName('position') . ' = :position')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':ord', $i, ParameterType::INTEGER)
                ->bind(':position', $position)
                ->bind(':id', $moduleId, ParameterType::INTEGER);
            $db->setQuery($query)->execute();

            $i++;
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
                'PLG_CUSTOMIZE_POSITION_SAVED',
                'PLG_CUSTOMIZE_POSITION_SAVE_ERROR',
                'PLG_CUSTOMIZE_POSITION_SAVE_FAILED',
                'PLG_CUSTOMIZE_POSITION_UNKNOWN_ERROR',
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
