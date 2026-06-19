<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Customize.position
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Customize\Position\Extension;

use Joomla\CMS\Customize\CustomizeMode;
use Joomla\CMS\Event\GenericEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Table\Module;
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
 * @since  __DEPLOY_VERSION__
 */
final class Position extends CMSPlugin implements SubscriberInterface
{
    /**
     * Load the plugin language file on instantiation.
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    protected $autoloadLanguage = true;

    /**
     * Returns the events this plugin subscribes to.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onAjaxPosition'           => 'onAjaxPosition',
            'onCustomizeEmptyPosition' => 'onCustomizeEmptyPosition',
            'onCustomizeAdminInit'     => 'onCustomizeAdminInit',
        ];
    }

    /**
     * Emit a drop zone for an empty template position (so a module can be dragged into it), keeping
     * this markup in the plugin rather than in core's ModulesRenderer.
     *
     * @param   GenericEvent  $event  The event (subject = position name, content = markup to fill).
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onCustomizeEmptyPosition(GenericEvent $event): void
    {
        $position = (string) $event->getArgument('subject', '');

        // In "show all positions" mode the marker is shown at all times (a labeled drop slot), not just
        // while a module is being dragged, so the editor can see the full position map.
        $shown = CustomizeMode::showAllPositions() ? ' customize-empty-position-shown' : '';

        $event->setArgument(
            'content',
            '<div class="customize-empty-position' . $shown . '" data-customize-dropzone="module" data-customize-droppos="'
            . htmlspecialchars($position, ENT_QUOTES) . '">'
            . htmlspecialchars($position) . '</div>'
        );
    }

    /**
     * com_ajax entry point (plugin=position&group=customize).
     *
     * @param   AjaxEvent  $event  The AJAX event.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onAjaxPosition(AjaxEvent $event): void
    {
        if (!Session::checkToken('post')) {
            // Flag the auth failure so the customize host can tell the editor their session expired,
            // rather than the drag/drop just failing silently.
            $event->addResult(json_encode(['success' => false, 'authExpired' => true, 'message' => Text::_('JINVALID_TOKEN')]));

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

            case 'moduletypes':
                $event->addResult($this->doModuleTypes());
                break;

            case 'add':
                $event->addResult($this->doAdd($payload));
                break;

            case 'delete':
                $event->addResult($this->doDelete($payload));
                break;

            case 'swap':
                $event->addResult($this->doSwap($payload));
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
     * @since   __DEPLOY_VERSION__
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
     * @since   __DEPLOY_VERSION__
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
     * Delete a module (and its menu assignments).
     *
     * @param   array  $payload  The request payload.
     *
     * @return  string  JSON result.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function doDelete(array $payload): string
    {
        $id = (int) ($payload['id'] ?? 0);

        if ($id <= 0 || !$this->getApplication()->getIdentity()->authorise('core.delete', 'com_modules.module.' . $id)) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_POSITION_ERROR_INVALID'));
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $table = new Module($db);

        if (!$table->delete($id)) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_POSITION_DELETE_FAILED'));
        }
        $query = $db->createQuery()
            ->delete($db->quoteName('#__modules_menu'))
            ->where($db->quoteName('moduleid') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);
        $db->setQuery($query)->execute();

        return json_encode(['success' => true]);
    }

    /**
     * Swap the modules of two positions (e.g. the left and right sidebars). A content-level move only,
     * it does not touch the template's grid, so it cannot conflict with the width-ratio CSS.
     *
     * @param   array  $payload  { a, b } the two position names.
     *
     * @return  string  JSON result.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function doSwap(array $payload): string
    {
        $a = trim((string) ($payload['a'] ?? ''));
        $b = trim((string) ($payload['b'] ?? ''));

        if ($a === '' || $b === '' || $a === $b || !$this->getApplication()->getIdentity()->authorise('core.edit', 'com_modules')) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_POSITION_ERROR_INVALID'));
        }

        $db   = Factory::getContainer()->get(DatabaseInterface::class);
        $case = 'CASE WHEN ' . $db->quoteName('position') . ' = ' . $db->quote($a) . ' THEN ' . $db->quote($b)
            . ' ELSE ' . $db->quote($a) . ' END';

        $query = $db->createQuery()
            ->update($db->quoteName('#__modules'))
            ->set($db->quoteName('position') . ' = ' . $case)
            ->where($db->quoteName('position') . ' IN (' . $db->quote($a) . ', ' . $db->quote($b) . ')')
            ->where($db->quoteName('client_id') . ' = 0');
        $db->setQuery($query)->execute();

        return json_encode(['success' => true]);
    }

    /**
     * Return the installed, enabled site module types (for the "Add module" picker).
     *
     * @return  string  JSON result.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function doModuleTypes(): string
    {
        if (!$this->canManageModules()) {
            return $this->fail(Text::_('JERROR_ALERTNOAUTHOR'));
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->createQuery()
            ->select([$db->quoteName('element'), $db->quoteName('name')])
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('module'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('enabled') . ' = 1')
            ->order($db->quoteName('element'));
        $db->setQuery($query);
        $rows = $db->loadObjectList() ?: [];

        // Translate each module's display name the way the module manager does.
        $lang  = $this->getApplication()->getLanguage();
        $types = [];

        foreach ($rows as $row) {
            $lang->load($row->element . '.sys', JPATH_SITE)
                || $lang->load($row->element . '.sys', JPATH_SITE . '/modules/' . $row->element);
            $types[] = ['element' => $row->element, 'name' => Text::_($row->name)];
        }

        usort($types, static function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return json_encode(['success' => true, 'types' => $types]);
    }

    /**
     * Create a published module of the given type in a position, assigned to all pages.
     *
     * @param   array  $payload  The request payload.
     *
     * @return  string  JSON result.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function doAdd(array $payload): string
    {
        $app      = $this->getApplication();
        $title    = trim((string) ($payload['title'] ?? ''));
        $module   = trim((string) ($payload['module'] ?? ''));
        $position = trim((string) ($payload['position'] ?? ''));

        // The picker no longer chooses a position; place the module in the first template
        // position so it renders, then it is dragged where it belongs.
        if ($position === '') {
            $positions = $this->templatePositions();
            $position  = $positions[0] ?? '';
        }

        if ($title === '' || $module === '' || $position === '' || !$app->getIdentity()->authorise('core.create', 'com_modules')) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_POSITION_ERROR_INVALID'));
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        // Only allow an installed, enabled site module type.
        $check = $db->createQuery()
            ->select('COUNT(*)')
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('module'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('enabled') . ' = 1')
            ->where($db->quoteName('element') . ' = :element')
            ->bind(':element', $module);
        $db->setQuery($check);

        if (!(int) $db->loadResult()) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_POSITION_ERROR_INVALID'));
        }

        $maxQuery = $db->createQuery()
            ->select('MAX(' . $db->quoteName('ordering') . ')')
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('position') . ' = :pos')
            ->bind(':pos', $position);
        $db->setQuery($maxQuery);
        $ordering = (int) $db->loadResult() + 1;

        $table = new Module($db);

        $table->title     = $title;
        $table->module    = $module;
        $table->position  = $position;
        $table->ordering  = $ordering;
        $table->published = 1;
        $table->access    = 1;
        $table->showtitle = 1;
        $table->language  = '*';
        $table->client_id = 0;
        $table->params    = '{}';
        $table->note      = '';
        $table->content   = '';

        if (!$table->check() || !$table->store()) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_POSITION_ADD_FAILED'));
        }

        // Assign to all pages (menuid 0). insertObject takes the row by reference.
        $assignment = (object) ['moduleid' => (int) $table->id, 'menuid' => 0];
        $db->insertObject('#__modules_menu', $assignment);

        return json_encode(['success' => true, 'id' => (int) $table->id]);
    }

    /**
     * Return the position list of the default site template.
     *
     * @return  string  JSON result.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function doPositions(): string
    {
        if (!$this->canManageModules()) {
            return $this->fail(Text::_('JERROR_ALERTNOAUTHOR'));
        }

        return json_encode(['success' => true, 'positions' => $this->templatePositions()]);
    }

    /**
     * The position list declared by the default site template.
     *
     * @return  string[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private function templatePositions(): array
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

        return $positions;
    }

    /**
     * Register this plugin's JS strings for Joomla.Text on the customize admin page.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onCustomizeAdminInit(): void
    {
        // Module placement needs module-management rights; otherwise the position UI is not loaded.
        if (!$this->canManageModules()) {
            return;
        }

        $this->loadLanguage();

        $wa = $this->getApplication()->getDocument()->getWebAssetManager();

        if (is_file(JPATH_ROOT . '/media/plg_customize_position/joomla.asset.json')) {
            $wa->getRegistry()->addExtensionRegistryFile('plg_customize_position');
            $wa->useScript('plg_customize_position.admin');
        }

        foreach (
            [
                'PLG_CUSTOMIZE_POSITION_ADD_BAR',
                'PLG_CUSTOMIZE_POSITION_ADD_BTN',
                'PLG_CUSTOMIZE_POSITION_ADD_FAILED',
                'PLG_CUSTOMIZE_POSITION_ADD_HINT',
                'PLG_CUSTOMIZE_POSITION_ADD_NEEDINFO',
                'PLG_CUSTOMIZE_POSITION_ADD_NEEDPOS',
                'PLG_CUSTOMIZE_POSITION_ADD_SUBMIT',
                'PLG_CUSTOMIZE_POSITION_ADD_TITLE',
                'PLG_CUSTOMIZE_POSITION_ADD_TYPE',
                'PLG_CUSTOMIZE_POSITION_BTN_MOVE',
                'PLG_CUSTOMIZE_POSITION_DELETE_FAILED',
                'PLG_CUSTOMIZE_POSITION_DROP_HINT',
                'PLG_CUSTOMIZE_POSITION_LABEL',
                'PLG_CUSTOMIZE_POSITION_LOAD_FAILED',
                'PLG_CUSTOMIZE_POSITION_NEW_NAME',
                'PLG_CUSTOMIZE_POSITION_NEW_OPTION',
                'PLG_CUSTOMIZE_POSITION_ORDER',
                'PLG_CUSTOMIZE_POSITION_ORDER_AFTER',
                'PLG_CUSTOMIZE_POSITION_ORDER_TOP',
                'PLG_CUSTOMIZE_POSITION_SAVED',
                'PLG_CUSTOMIZE_POSITION_SAVE_ERROR',
                'PLG_CUSTOMIZE_POSITION_SAVE_FAILED',
                'PLG_CUSTOMIZE_POSITION_SHOW_ALL',
                'PLG_CUSTOMIZE_POSITION_SHOW_USED',
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
     * @since   __DEPLOY_VERSION__
     */
    private function fail(string $message): string
    {
        return json_encode(['success' => false, 'message' => $message]);
    }

    /**
     * Whether the current user may manage site modules. Gates the read-only pickers
     * (module types, template positions), which the mutating actions back with per-item checks.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function canManageModules(): bool
    {
        $identity = $this->getApplication()->getIdentity();

        return $identity->authorise('core.edit', 'com_modules') || $identity->authorise('core.create', 'com_modules');
    }
}
