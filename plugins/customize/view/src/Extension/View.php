<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Customize.view
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Customize\View\Extension;

use Joomla\CMS\Event\GenericEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Customize plugin: view layouts. Wraps each rendered sub-layout (the `onCustomizeRenderView` core
 * hook) so the editor can target it, opens the native Joomla template editor on a created override
 * ("Edit layout"), and reorders reliably-mappable blocks via a template override.
 *
 * @since  1.0.0
 */
final class View extends CMSPlugin implements SubscriberInterface
{
    /**
     * Load the plugin language file on instantiation.
     *
     * @var    boolean
     * @since  1.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * Per-request occurrence counter, keyed by component|view|layout|block.
     *
     * @var    array<string, int>
     * @since  1.0.0
     */
    private $occurrences = [];

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
            'onCustomizeRenderView' => 'onCustomizeRenderView',
            'onAjaxView'            => 'onAjaxView',
            'onCustomizeAdminInit'  => 'onCustomizeAdminInit',
        ];
    }

    /**
     * Core fires this for each sub-layout rendered in customize mode; wrap the output with comment
     * markers carrying its source identity so the editor's JS can turn it into an editable area.
     *
     * @param   GenericEvent  $event  The render event (output + component/view/layout/block).
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onCustomizeRenderView(GenericEvent $event): void
    {
        $output = (string) $event->getArgument('output', '');
        $block  = (string) $event->getArgument('block', '');

        if ($output === '' || $block === '') {
            return;
        }

        $component = (string) $event->getArgument('component', '');
        $view      = (string) $event->getArgument('view', '');
        $layout    = (string) $event->getArgument('layout', '');
        $key       = $component . '|' . $view . '|' . $layout . '|' . $block;
        $occ       = $this->occurrences[$key] = ($this->occurrences[$key] ?? 0) + 1;

        // Values are already sanitised by core (component = option cmd; view/layout/block cleaned in
        // HtmlView), so they cannot contain ';' or '-->'.
        $meta = 'component=' . $component . ';view=' . $view . ';layout=' . $layout
            . ';block=' . $block . ';occ=' . $occ;

        $event->setArgument('output', '<!--customize-block-start:' . $meta . '-->' . $output . '<!--customize-block-end-->');
    }

    /**
     * com_ajax entry point (plugin=view&group=customize).
     *
     * @param   AjaxEvent  $event  The AJAX event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onAjaxView(AjaxEvent $event): void
    {
        if (!Session::checkToken('post')) {
            $event->addResult($this->fail(Text::_('JINVALID_TOKEN')));

            return;
        }

        // View layout overrides are a site-wide, template-level change.
        if (!$this->getApplication()->getIdentity()->authorise('core.admin')) {
            $event->addResult($this->fail(Text::_('JERROR_ALERTNOAUTHOR')));

            return;
        }

        $payload = json_decode($this->getApplication()->getInput()->get('payload', '', 'raw'), true) ?: [];

        switch ($this->getApplication()->getInput()->getCmd('action', '')) {
            case 'override':
                $event->addResult($this->doOverride($payload));
                break;

            default:
                $event->addResult($this->fail(Text::_('PLG_CUSTOMIZE_VIEW_ERROR_INVALID')));
        }
    }

    /**
     * Create the template override for a block's sub-layout (if absent) and return the URL of the
     * native com_templates file editor for it.
     *
     * @param   array  $payload  The request payload (component, view, layout, block).
     *
     * @return  string  JSON result.
     *
     * @since   1.0.0
     */
    private function doOverride(array $payload): string
    {
        $component = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($payload['component'] ?? ''));
        $view      = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($payload['view'] ?? ''));
        $layout    = preg_replace('/[^a-zA-Z0-9_.\-]/', '', (string) ($payload['layout'] ?? ''));
        $block     = preg_replace('/[^a-zA-Z0-9_.\-]/', '', (string) ($payload['block'] ?? ''));

        if ($component === '' || $view === '' || $layout === '') {
            return $this->fail(Text::_('PLG_CUSTOMIZE_VIEW_ERROR_INVALID'));
        }

        $fileName = ($block !== '' ? $layout . '_' . $block : $layout) . '.php';
        $source   = JPATH_SITE . '/components/' . $component . '/tmpl/' . $view . '/' . $fileName;

        if (!is_file($source)) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_VIEW_NO_SOURCE'));
        }

        // This runs in the admin com_ajax context, so resolve the default SITE template explicitly
        // (not $app->getTemplate(), which would return the administrator template here).
        $template = $this->siteTemplate();
        $extId    = $this->templateExtensionId($template);

        if (!$extId) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_VIEW_OVERRIDE_FAILED'));
        }

        // Use JPATH_SITE explicitly: this handler runs in the admin app, where JPATH_THEMES would be
        // the administrator templates directory.
        $relPath      = '/html/' . $component . '/' . $view . '/' . $fileName;
        $overrideFile = JPATH_SITE . '/templates/' . $template . $relPath;

        // Create the override once; never overwrite an existing (possibly user-edited) one.
        if (!is_file($overrideFile)) {
            $dir = \dirname($overrideFile);

            if ((!is_dir($dir) && !Folder::create($dir)) || !File::copy($source, $overrideFile)) {
                return $this->fail(Text::_('PLG_CUSTOMIZE_VIEW_OVERRIDE_FAILED'));
            }
        }

        // Native template-editor URL (see com_templates TemplateModel::getFile / TemplateController).
        $fileParam = base64_encode(str_replace('\\', '//', $relPath));
        $url       = 'index.php?option=com_templates&view=template&id=' . (int) $extId
            . '&file=' . $fileParam . '&isMedia=0';

        return json_encode(['success' => true, 'url' => $url]);
    }

    /**
     * The element of the default site template.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function siteTemplate(): string
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->createQuery()
            ->select($db->quoteName('template'))
            ->from($db->quoteName('#__template_styles'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('home') . ' = ' . $db->quote('1'));
        $db->setQuery($query);

        return (string) $db->loadResult();
    }

    /**
     * The #__extensions id of a site template by element.
     *
     * @param   string  $template  The template element.
     *
     * @return  integer
     *
     * @since   1.0.0
     */
    private function templateExtensionId(string $template): int
    {
        if ($template === '') {
            return 0;
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->createQuery()
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('template'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('element') . ' = :element')
            ->bind(':element', $template);
        $db->setQuery($query);

        return (int) $db->loadResult();
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
                'PLG_CUSTOMIZE_VIEW_AREA',
                'PLG_CUSTOMIZE_VIEW_BTN_EDIT',
                'PLG_CUSTOMIZE_VIEW_SAVED',
                'PLG_CUSTOMIZE_VIEW_SAVE_ERROR',
                'PLG_CUSTOMIZE_VIEW_SAVE_FAILED',
                'PLG_CUSTOMIZE_VIEW_UNKNOWN_ERROR',
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
