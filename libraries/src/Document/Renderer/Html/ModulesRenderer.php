<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2015 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Document\Renderer\Html;

use Joomla\CMS\Customize\CustomizeMode;
use Joomla\CMS\Document\DocumentRenderer;
use Joomla\CMS\Event\GenericEvent;
use Joomla\CMS\Event\Module;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Layout\LayoutHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * HTML document renderer for a module position
 *
 * @since  3.5
 */
class ModulesRenderer extends DocumentRenderer
{
    /**
     * Renders multiple modules script and returns the results as a string
     *
     * @param   string  $position  The position of the modules to render
     * @param   array   $params    Associative array of values
     * @param   string  $content   Module content
     *
     * @return  string  The output of the script
     *
     * @since   3.5
     */
    public function render($position, $params = [], $content = null)
    {
        $renderer = $this->_doc->loadRenderer('module');
        $buffer   = '';

        $app          = Factory::getApplication();
        $user         = Factory::getUser();
        $frontediting = ($app->isClient('site') && $app->get('frontediting', 1) && !$user->guest);
        $menusEditing = ($app->get('frontediting', 1) == 2) && $user->authorise('core.edit', 'com_menus');

        $customize = CustomizeMode::isActive();

        foreach (ModuleHelper::getModules($position) as $mod) {
            $moduleHtml = $renderer->render($mod, $params, $content);

            // In customize mode, mark each module's first tag so the editor can target it (no extra wrapper).
            if ($customize && trim($moduleHtml) !== '') {
                $attrs = ' data-customize-type="module"'
                    . ' data-customize-id="' . (int) $mod->id . '"'
                    . ' data-customize-position="' . htmlspecialchars($position, ENT_QUOTES) . '"'
                    . ' data-customize-module="' . htmlspecialchars($mod->module, ENT_QUOTES) . '"'
                    . ' data-customize-name="' . htmlspecialchars($mod->title, ENT_QUOTES) . '"';

                // Let customize-group plugins contribute extra data-customize-* attributes for this
                // module (e.g. a flag that enables a type-specific button), rather than hardcoding
                // module types here. Each plugin adds entries to the "attributes" array.
                $customizeEvent = new GenericEvent('onCustomizeModule', ['subject' => $mod, 'position' => $position, 'attributes' => []]);
                $app->getDispatcher()->dispatch('onCustomizeModule', $customizeEvent);

                foreach ((array) $customizeEvent->getArgument('attributes', []) as $name => $value) {
                    $name = preg_replace('/[^a-z0-9\-]/', '', (string) $name);

                    if ($name !== '') {
                        $attrs .= ' data-customize-' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"';
                    }
                }

                $moduleHtml = preg_replace('/^(\s*<[a-zA-Z][^>]*?)(\s*\/?>)/', '$1' . $attrs . '$2', $moduleHtml, 1);
            }

            if ($frontediting && trim($moduleHtml) != '' && $user->authorise('module.edit.frontend', 'com_modules.module.' . $mod->id)) {
                $displayData = ['moduleHtml' => &$moduleHtml, 'module' => $mod, 'position' => $position, 'menusediting' => $menusEditing];
                LayoutHelper::render('joomla.edit.frontediting_modules', $displayData);
            }

            $buffer .= $moduleHtml;
        }

        // In customize mode, a position with no modules still gets a (drag-revealed) drop zone.
        if ($customize && trim($buffer) === '') {
            $buffer = '<div class="customize-empty-position" data-customize-dropzone="module" data-customize-droppos="'
                . htmlspecialchars($position, ENT_QUOTES) . '">'
                . htmlspecialchars($position) . '</div>';
        }

        // Dispatch onAfterRenderModules event
        $event = new Module\AfterRenderModulesEvent('onAfterRenderModules', [
            'content'    => &$buffer, // @todo: Remove reference in Joomla 7, see AfterRenderModulesEvent::__constructor()
            'attributes' => $params,
        ]);
        $app->getDispatcher()->dispatch('onAfterRenderModules', $event);

        return $event->getArgument('content', $content);
    }
}
