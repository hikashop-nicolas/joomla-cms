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

        // Capture the template actually rendering THIS page (here on the frontend getTemplate() is
        // correct) so the editor writes the override to the right template, not just the default one.
        $template = (string) $this->getApplication()->getTemplate();

        // Flag whether this block already has a template override, for a cue in the editor toolbar.
        $fileName     = ($layout !== '' ? $layout . '_' . $block : $block) . '.php';
        $overrideFile = JPATH_SITE . '/templates/' . $template . '/html/' . $component . '/' . $view . '/' . $fileName;
        $hasOverride  = ($component !== '' && $view !== '' && is_file($overrideFile)) ? '1' : '0';

        // Values are already sanitised by core (component = option cmd; view/layout/block cleaned in
        // HtmlView; template is a folder element), so they cannot contain ';' or '-->'.
        $meta = 'component=' . $component . ';view=' . $view . ';layout=' . $layout
            . ';block=' . $block . ';occ=' . $occ . ';template=' . $template . ';override=' . $hasOverride;

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
        $parts = self::sanitizeOverrideRequest($payload);

        if ($parts === null) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_VIEW_ERROR_INVALID'));
        }

        $source = JPATH_SITE . '/' . $parts['source'];

        if (!is_file($source)) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_VIEW_NO_SOURCE'));
        }

        // Validate that the template (captured on the frontend, sent by the client) is a real site
        // template before writing into its tree.
        $extId = $this->templateExtensionId($parts['template']);

        if (!$extId) {
            return $this->fail(Text::_('PLG_CUSTOMIZE_VIEW_OVERRIDE_FAILED'));
        }

        // Use JPATH_SITE explicitly: this handler runs in the admin app, where JPATH_THEMES would be
        // the administrator templates directory.
        $overrideFile = JPATH_SITE . '/templates/' . $parts['template'] . $parts['relPath'];

        // Create the override once; never overwrite an existing (possibly user-edited) one.
        if (!is_file($overrideFile)) {
            $dir = \dirname($overrideFile);

            if ((!is_dir($dir) && !Folder::create($dir)) || !File::copy($source, $overrideFile)) {
                return $this->fail(Text::_('PLG_CUSTOMIZE_VIEW_OVERRIDE_FAILED'));
            }
        }

        // Native template-editor URL (see com_templates TemplateModel::getFile / TemplateController).
        $fileParam = base64_encode(str_replace('\\', '//', $parts['relPath']));
        $url       = 'index.php?option=com_templates&view=template&id=' . (int) $extId
            . '&file=' . $fileParam . '&isMedia=0';

        return json_encode(['success' => true, 'url' => $url]);
    }

    /**
     * Sanitise an override request and derive the (relative) component source + override paths. Every
     * segment is reduced to a safe character set, so the result can never contain a "/" or ".." and
     * thus cannot escape the components/ or templates/ trees.
     *
     * @param   array  $payload  The request payload (component, view, layout, block, template).
     *
     * @return  array|null  Keys component, view, layout, block, template, fileName, source, relPath;
     *                      or null when component, view or layout is missing.
     *
     * @since   1.0.0
     */
    private static function sanitizeOverrideRequest(array $payload): ?array
    {
        $component = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($payload['component'] ?? ''));
        $view      = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($payload['view'] ?? ''));
        $layout    = preg_replace('/[^a-zA-Z0-9_.\-]/', '', (string) ($payload['layout'] ?? ''));
        $block     = preg_replace('/[^a-zA-Z0-9_.\-]/', '', (string) ($payload['block'] ?? ''));
        $template  = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($payload['template'] ?? ''));

        if ($component === '' || $view === '' || $layout === '') {
            return null;
        }

        $fileName = ($block !== '' ? $layout . '_' . $block : $layout) . '.php';

        return [
            'component' => $component,
            'view'      => $view,
            'layout'    => $layout,
            'block'     => $block,
            'template'  => $template,
            'fileName'  => $fileName,
            'source'    => 'components/' . $component . '/tmpl/' . $view . '/' . $fileName,
            'relPath'   => '/html/' . $component . '/' . $view . '/' . $fileName,
        ];
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
        // Template overrides require core.admin; otherwise the view editing UI is not loaded at all.
        if (!$this->getApplication()->getIdentity()->authorise('core.admin')) {
            return;
        }

        $this->loadLanguage();

        $wa = $this->getApplication()->getDocument()->getWebAssetManager();

        if (is_file(JPATH_ROOT . '/media/plg_customize_view/joomla.asset.json')) {
            $wa->getRegistry()->addExtensionRegistryFile('plg_customize_view');
            $wa->useScript('plg_customize_view.admin');
        }

        foreach (
            [
                'PLG_CUSTOMIZE_VIEW_AREA',
                'PLG_CUSTOMIZE_VIEW_BTN_EDIT',
                'PLG_CUSTOMIZE_VIEW_OVERRIDDEN',
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
