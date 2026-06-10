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

            // In customize mode, let the customize "module" plugin instrument this module's output.
            // Core only fires the hook and uses the returned string; it holds no customize markup.
            if ($customize && trim($moduleHtml) !== '') {
                $customizeEvent = new GenericEvent('onCustomizeModule', ['subject' => $mod, 'position' => $position, 'output' => $moduleHtml]);
                Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class)->dispatch('onCustomizeModule', $customizeEvent);
                $customized = $customizeEvent->getArgument('output');

                if (\is_string($customized)) {
                    $moduleHtml = $customized;
                }
            }

            if ($frontediting && trim($moduleHtml) != '' && $user->authorise('module.edit.frontend', 'com_modules.module.' . $mod->id)) {
                $displayData = ['moduleHtml' => &$moduleHtml, 'module' => $mod, 'position' => $position, 'menusediting' => $menusEditing];
                LayoutHelper::render('joomla.edit.frontediting_modules', $displayData);
            }

            $buffer .= $moduleHtml;
        }

        // In customize mode, let a plugin add a drop zone for an empty position (no markup in core).
        if ($customize && trim($buffer) === '') {
            $emptyEvent = new GenericEvent('onCustomizeEmptyPosition', ['subject' => $position, 'content' => '']);
            Factory::getContainer()->get(\Joomla\Event\DispatcherInterface::class)->dispatch('onCustomizeEmptyPosition', $emptyEvent);
            $emptyContent = $emptyEvent->getArgument('content');

            if (\is_string($emptyContent)) {
                $buffer = $emptyContent;
            }
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
