<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Customize.view
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Customize\View\Extension;

use Joomla\CMS\Customize\CustomizeMode;
use Joomla\CMS\Event\GenericEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;

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
        $error  = (string) $event->getArgument('error', '');

        // A layout/override threw while rendering. Replace its (empty) output with a recoverable inline
        // notice, wrapped below so the block's Edit layout / Delete override still work. Customize mode
        // is gated on a valid admin token, so the editor is shown the actual error to help fix it.
        if ($error !== '') {
            $output = CustomizeMode::renderError($error);
        }

        if ($output === '' || $block === '') {
            return;
        }

        $component = (string) $event->getArgument('component', '');
        $view      = (string) $event->getArgument('view', '');
        $layout    = (string) $event->getArgument('layout', '');
        $file      = (string) $event->getArgument('file', '');

        // Resolve the block to the actual sub-layout file core rendered. Core found it via the view's
        // own registered template paths (Path::find on _path['template']), so this works for any
        // component, not only the core tmpl/<view>/ convention (e.g. HikaShop, under views/<view>/tmpl/).
        // Only offer the block for overrides when that file is a real file under the site root, so an
        // override can be created from it.
        if ($component === '' || $view === '' || $file === '' || strpos($file, JPATH_SITE) !== 0 || !is_file($file)) {
            return;
        }

        $source = str_replace('\\', '/', ltrim(substr($file, \strlen(JPATH_SITE)), '/\\'));

        $key       = $component . '|' . $view . '|' . $layout . '|' . $block;
        $occ       = $this->occurrences[$key] = ($this->occurrences[$key] ?? 0) + 1;

        // Capture the template actually rendering THIS page (here on the frontend getTemplate() is
        // correct) so the editor writes the override to the right template, not just the default one.
        $template = (string) $this->getApplication()->getTemplate();

        // The override lives at the Joomla convention for this component/view, named after the source
        // file core rendered; flag whether it already exists for a cue in the editor toolbar.
        $overrideFile = JPATH_SITE . '/templates/' . $template . '/html/' . $component . '/' . $view . '/' . basename($source);
        $hasOverride  = is_file($overrideFile) ? '1' : '0';

        // Values are already sanitised by core (component = option cmd; view/layout/block cleaned in
        // HtmlView; template is a folder element); the source is a real file path under the site root,
        // so none of them can contain ';' or '-->'.
        $meta = 'component=' . $component . ';view=' . $view . ';layout=' . $layout
            . ';block=' . $block . ';occ=' . $occ . ';template=' . $template
            . ';source=' . $source . ';override=' . $hasOverride;

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

        // The override is NOT created here. The focused editor (com_templates, layout=modal) seeds
        // from the original file and only writes the override on save, so opening then cancelling, or
        // saving without changes, leaves no orphan override that silently shadows the core layout.
        $fileParam = base64_encode(str_replace('\\', '//', $parts['relPath']));
        $url       = 'index.php?option=com_templates&view=template&layout=modal&id=' . (int) $extId
            . '&file=' . $fileParam . '&isMedia=0';

        return json_encode(['success' => true, 'url' => $url]);
    }

    /**
     * Sanitise an override request and derive the (relative) component source + override paths. Every
     * segment is reduced to a safe character set, so the result can never contain a "/" or ".." and
     * thus cannot escape the components/ or templates/ trees.
     *
     * @param   array  $payload  The request payload (type, component, view, template, source).
     *
     * @return  array|null  Keys component, view, template, fileName, source, relPath, type; or null
     *                      when the request is not a valid view/module override target.
     *
     * @since   1.0.0
     */
    private static function sanitizeOverrideRequest(array $payload): ?array
    {
        $component = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($payload['component'] ?? ''));
        $view      = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($payload['view'] ?? ''));
        $template  = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($payload['template'] ?? ''));

        // The block states its type ('view' or 'module'), so the override location follows the right
        // Joomla convention without sniffing the source path. The source is the file core rendered (the
        // original, or an existing override); it is used only, as a real .php file with no traversal, to
        // take the layout's file name.
        $type   = (string) ($payload['type'] ?? '');
        $source = ltrim(str_replace('\\', '/', (string) ($payload['source'] ?? '')), '/');

        if (
            ($type !== 'view' && $type !== 'module')
            || $component === '' || $source === ''
            || strpos($source, '..') !== false
            || ($type === 'view' && $view === '')
            || substr($source, -4) !== '.php'
            || !is_file(JPATH_SITE . '/' . $source)
        ) {
            return null;
        }

        $fileName = preg_replace('/[^a-zA-Z0-9_.-]/', '', basename($source));

        // Component views nest the override under the view name; modules sit directly under the module
        // folder, matching Joomla's override conventions.
        $relPath = $type === 'module'
            ? '/html/' . $component . '/' . $fileName
            : '/html/' . $component . '/' . $view . '/' . $fileName;

        return [
            'component' => $component,
            'view'      => $view,
            'template'  => $template,
            'fileName'  => $fileName,
            'source'    => $source,
            'relPath'   => $relPath,
            'type'      => $type,
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
                'PLG_CUSTOMIZE_VIEW_CHILDREN',
                'PLG_CUSTOMIZE_VIEW_OVERRIDDEN',
                'PLG_CUSTOMIZE_VIEW_PARENT',
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
