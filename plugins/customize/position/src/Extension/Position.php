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
use Joomla\Component\Templates\Administrator\Helper\TemplatesHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Customize plugin: rearrange modules. Reorder within/between positions by drag and drop, and move
 * a module to any template position (including empty ones) via a picker.
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
     * com_ajax entry point (plugin=position&group=customize).
     *
     * @param   AjaxEvent  $event  The AJAX event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onAjaxPosition(AjaxEvent $event): void
    {
        if (!Session::checkToken('post')) {
            $event->addResult($this->fail(Text::_('JINVALID_TOKEN')));

            return;
        }

        $payload = json_decode($this->getApplication()->getInput()->get('payload', '', 'raw'), true) ?: [];

        switch ($this->getApplication()->getInput()->getCmd('action', '')) {
            case 'reorder':
                $event->addResult($this->doReorder($payload));
                break;

            case 'positions':
                $event->addResult($this->doPositions());
                break;

            case 'move':
                $event->addResult($this->doMove($payload));
                break;

            default:
                $event->addResult($this->fail(Text::_('PLG_CUSTOMIZE_POSITION_ERROR_INVALID')));
        }
    }

    /**
     * Persist a module ordering (and position, for cross-position drops).
     *
     * @param   array  $payload  The request payload.
     *
     * @return  string  JSON result.
     *
     * @since   1.0.0
     */
    private function doReorder(array $payload): string
    {
        $position = trim((string) ($payload['position'] ?? ''));

        if ($position === '' || empty($payload['order']) || !\is_array($payload['order'])) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_POSITION_ERROR_INVALID'));
        }

        $user = $this->getApplication()->getIdentity();
        $db   = Factory::getContainer()->get(DatabaseInterface::class);
        $i    = 1;

        foreach ($payload['order'] as $moduleId) {
            $moduleId = (int) $moduleId;

            if ($moduleId <= 0 || !$user->authorise('core.edit', 'com_modules.module.' . $moduleId)) {
                continue;
            }

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

        return json_encode(['success' => true]);
    }

    /**
     * Move a single module to a position, appended after any existing modules there.
     *
     * @param   array  $payload  The request payload.
     *
     * @return  string  JSON result.
     *
     * @since   1.0.0
     */
    private function doMove(array $payload): string
    {
        $id       = (int) ($payload['id'] ?? 0);
        $position = trim((string) ($payload['position'] ?? ''));

        if ($id <= 0 || $position === '' || !$this->getApplication()->getIdentity()->authorise('core.edit', 'com_modules.module.' . $id)) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_POSITION_ERROR_INVALID'));
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $maxQuery = $db->createQuery()
            ->select('MAX(' . $db->quoteName('ordering') . ')')
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('position') . ' = :pos')
            ->bind(':pos', $position);
        $db->setQuery($maxQuery);
        $ordering = (int) $db->loadResult() + 1;

        $query = $db->createQuery()
            ->update($db->quoteName('#__modules'))
            ->set($db->quoteName('position') . ' = :position')
            ->set($db->quoteName('ordering') . ' = :ord')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':position', $position)
            ->bind(':ord', $ordering, ParameterType::INTEGER)
            ->bind(':id', $id, ParameterType::INTEGER);
        $db->setQuery($query)->execute();

        return json_encode(['success' => true]);
    }

    /**
     * Return the position list of the default site template.
     *
     * @return  string  JSON result.
     *
     * @since   1.0.0
     */
    private function doPositions(): string
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->createQuery()
            ->select($db->quoteName('template'))
            ->from($db->quoteName('#__template_styles'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('home') . ' = ' . $db->quote('1'));
        $db->setQuery($query);
        $template = (string) $db->loadResult();

        $positions = $template ? TemplatesHelper::getPositions(0, $template) : [];
        $positions = array_values(array_unique(array_map('strval', (array) $positions)));
        sort($positions);

        return json_encode(['success' => true, 'positions' => $positions]);
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
                'PLG_CUSTOMIZE_POSITION_DROP_HINT',
                'PLG_CUSTOMIZE_POSITION_LABEL',
                'PLG_CUSTOMIZE_POSITION_LOAD_FAILED',
                'PLG_CUSTOMIZE_POSITION_MOVED',
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
