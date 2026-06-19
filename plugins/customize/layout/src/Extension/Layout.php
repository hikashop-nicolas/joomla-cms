<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Customize.layout
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Customize\Layout\Extension;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Customize plugin: rearrange a template's layout blocks (the module positions themselves), generically.
 *
 * It reads the data-customize-grid / data-customize-block contract a template emits (via the core
 * LayoutHelper) and lets the editor drag, keyboard-move and hide those blocks. Persistence is handled
 * by com_templates (JoomlaCustomize.templateAction -> ajax.customize), which writes the arrangement
 * into the style's child template, so this plugin ships no server handler of its own; it only loads its
 * admin JS and strings on the Customize page, gated on the template-editing permission the actions need.
 *
 * @since  __DEPLOY_VERSION__
 */
final class Layout extends CMSPlugin implements SubscriberInterface
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
            'onCustomizeAdminInit' => 'onCustomizeAdminInit',
        ];
    }

    /**
     * Load this plugin's admin JS + strings on the Customize page, only for a user who may edit the
     * template (the permission the layout actions on com_templates enforce).
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onCustomizeAdminInit(): void
    {
        if (!$this->getApplication()->getIdentity()->authorise('core.admin', 'com_templates')) {
            return;
        }

        $this->loadLanguage();

        $wa = $this->getApplication()->getDocument()->getWebAssetManager();

        if (is_file(JPATH_ROOT . '/media/plg_customize_layout/joomla.asset.json')) {
            $wa->getRegistry()->addExtensionRegistryFile('plg_customize_layout');
            $wa->useScript('plg_customize_layout.admin');
        }

        foreach (
            [
                'PLG_CUSTOMIZE_LAYOUT_BLOCK',
                'PLG_CUSTOMIZE_LAYOUT_HIDDEN_HEADING',
                'PLG_CUSTOMIZE_LAYOUT_MOVE',
                'PLG_CUSTOMIZE_LAYOUT_MOVE_NONE',
                'PLG_CUSTOMIZE_LAYOUT_NOT_REMOVABLE',
                'PLG_CUSTOMIZE_LAYOUT_POSITION',
                'PLG_CUSTOMIZE_LAYOUT_REMOVED',
                'PLG_CUSTOMIZE_LAYOUT_RESIZE',
                'PLG_CUSTOMIZE_LAYOUT_RESTORE',
                'PLG_CUSTOMIZE_LAYOUT_SAVED',
                'PLG_CUSTOMIZE_LAYOUT_SAVE_FAILED',
                'PLG_CUSTOMIZE_LAYOUT_SPLIT',
                'PLG_CUSTOMIZE_LAYOUT_SPLIT_COLUMNS',
                'PLG_CUSTOMIZE_LAYOUT_SPLIT_FAILED',
                'PLG_CUSTOMIZE_LAYOUT_SPLIT_GRID',
                'PLG_CUSTOMIZE_LAYOUT_SPLIT_ROWS',
                'PLG_CUSTOMIZE_LAYOUT_SWAP_WITH',
                'PLG_CUSTOMIZE_LAYOUT_UNSPLIT',
            ] as $key
        ) {
            Text::script($key);
        }
    }
}
