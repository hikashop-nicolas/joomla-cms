<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Customize.module
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Customize\Module\Extension;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\GenericEvent;
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
            'onCustomizeModule'    => 'onCustomizeModule',
            'onCustomizeAdminInit' => 'onCustomizeAdminInit',
        ];
    }

    /**
     * Reorder a menu item relative to a sibling, using the nested-set move.
     *
     * @param   array  $payload  The request payload (id, reference, position).
     *
     * @return  string  JSON result.
     *
     * @since   1.0.0
     */
    private function doMoveMenuItem(array $payload): string
    {
        $id        = (int) ($payload['id'] ?? 0);
        $reference = (int) ($payload['reference'] ?? 0);
        $position  = (($payload['position'] ?? 'after') === 'before') ? 'before' : 'after';

        if (
            $id <= 0 || $reference <= 0 || $id === $reference
            || !$this->getApplication()->getIdentity()->authorise('core.edit', 'com_menus')
        ) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_MODULE_ERROR_INVALID'));
        }

        $table = $this->getApplication()->bootComponent('com_menus')->getMVCFactory()
            ->createTable('Menu', 'Administrator');

        if (!$table || !$table->load($id) || !$table->moveByReference($reference, $position, $id)) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_MODULE_ERROR_SAVE'));
        }

        return json_encode(['success' => true]);
    }

    /**
     * Rename a menu item (its displayed link text).
     *
     * @param   array  $payload  The request payload (id, title).
     *
     * @return  string  JSON result.
     *
     * @since   1.0.0
     */
    private function doSaveMenuItem(array $payload): string
    {
        $id    = (int) ($payload['id'] ?? 0);
        $title = trim(strip_tags((string) ($payload['title'] ?? '')));

        if ($id <= 0 || $title === '' || !$this->getApplication()->getIdentity()->authorise('core.edit', 'com_menus')) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_MODULE_ERROR_INVALID'));
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->createQuery()
            ->update($db->quoteName('#__menu'))
            ->set($db->quoteName('title') . ' = :title')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':title', $title)
            ->bind(':id', $id, ParameterType::INTEGER);
        $db->setQuery($query)->execute();

        return json_encode(['success' => true]);
    }

    /**
     * Delete a menu item (and, by nested-set semantics, any children).
     *
     * @param   array  $payload  The request payload (id).
     *
     * @return  string  JSON result.
     *
     * @since   1.0.0
     */
    private function doDeleteMenuItem(array $payload): string
    {
        $id = (int) ($payload['id'] ?? 0);

        if ($id <= 0 || !$this->getApplication()->getIdentity()->authorise('core.delete', 'com_menus')) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_MODULE_ERROR_INVALID'));
        }

        $table = $this->getApplication()->bootComponent('com_menus')->getMVCFactory()
            ->createTable('Menu', 'Administrator');

        if (!$table || !$table->delete($id)) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_MODULE_ERROR_SAVE'));
        }

        return json_encode(['success' => true]);
    }

    /**
     * Instrument a module being rendered in customize mode: inject the data-customize-* attributes
     * into its first tag (so core carries no customize markup). Custom (mod_custom) modules also get
     * a "custom" flag that surfaces the in-place "Edit content" button.
     *
     * @param   GenericEvent  $event  The event (subject = module, position, output = module HTML).
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onCustomizeModule(GenericEvent $event): void
    {
        $html = $event->getArgument('output');

        if (!\is_string($html) || trim($html) === '') {
            return;
        }

        $module   = $event->getArgument('subject');
        $position = (string) $event->getArgument('position', '');

        $attrs = ' data-customize-type="module"'
            . ' data-customize-id="' . (int) ($module->id ?? 0) . '"'
            . ' data-customize-position="' . htmlspecialchars($position, ENT_QUOTES) . '"'
            . ' data-customize-module="' . htmlspecialchars((string) ($module->module ?? ''), ENT_QUOTES) . '"'
            . ' data-customize-name="' . htmlspecialchars((string) ($module->title ?? ''), ENT_QUOTES) . '"';

        if (isset($module->module) && $module->module === 'mod_custom') {
            $attrs .= ' data-customize-custom="1"';
        }

        // Inject into the module's first tag (no extra wrapper).
        $event->setArgument('output', preg_replace('/^(\s*<[a-zA-Z][^>]*?)(\s*\/?>)/', '$1' . $attrs . '$2', $html, 1));
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

        // Menu-item actions operate on com_menus items, not the module record.
        $menuActions = [
            'movemenuitem'   => 'doMoveMenuItem',
            'savemenuitem'   => 'doSaveMenuItem',
            'deletemenuitem' => 'doDeleteMenuItem',
        ];
        $action = $input->getCmd('action', '');

        if (isset($menuActions[$action])) {
            $event->addResult($this->{$menuActions[$action]}($payload));

            return;
        }

        $id = (int) ($payload['id'] ?? 0);

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
                    $event->addResult($this->fail(Text::_('PLG_CUSTOMIZE_MODULE_ERROR_SAVE')));

                    return;
                }

                $event->addResult(json_encode(['success' => true, 'id' => $id]));
                break;

            case 'savecontent':
                // mod_custom HTML body, filtered through the user's Text Filters config.
                $html = ComponentHelper::filterText((string) ($payload['html'] ?? ''));

                if (\strlen($html) > 65535) {
                    $event->addResult($this->fail(Text::_('PLG_CUSTOMIZE_MODULE_CONTENT_TOO_LARGE')));

                    return;
                }

                $db    = Factory::getContainer()->get(DatabaseInterface::class);
                $query = $db->createQuery()
                    ->update($db->quoteName('#__modules'))
                    ->set($db->quoteName('content') . ' = :content')
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':content', $html)
                    ->bind(':id', $id, ParameterType::INTEGER);
                $db->setQuery($query)->execute();

                $event->addResult(json_encode(['success' => true, 'id' => $id, 'html' => $html]));
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
            'PLG_CUSTOMIZE_MODULE_BTN_CONTENT',
            'PLG_CUSTOMIZE_MODULE_BTN_EDIT',
            'PLG_CUSTOMIZE_MODULE_CONTENT_SAVED',
            'PLG_CUSTOMIZE_MODULE_CONTENT_TOO_LARGE',
            'PLG_CUSTOMIZE_MODULE_LOAD_FAILED',
            'PLG_CUSTOMIZE_MODULE_MENUITEM',
            'PLG_CUSTOMIZE_MODULE_MENUITEM_SAVED',
            'PLG_CUSTOMIZE_MODULE_MENU_REORDERED',
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
