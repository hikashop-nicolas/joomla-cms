<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_templates
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Templates\Administrator\Controller;

use Joomla\CMS\Customize\CustomizeMode;
use Joomla\CMS\Customize\LayoutHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;
use Joomla\Filesystem\Path;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Ajax controller for com_templates, used by the Customize host.
 *
 * @since  __DEPLOY_VERSION__
 */
class AjaxController extends BaseController
{
    /**
     * Markers delimiting the Customize-managed block in a template's customize.css. Only the content
     * between them is ever rewritten, so any hand-written CSS in the file is preserved.
     *
     * @since  __DEPLOY_VERSION__
     */
    private const CSS_START = '/* customize:managed:start - written by Customize mode, do not edit between these markers */';
    private const CSS_END   = '/* customize:managed:end */';

    /**
     * Mint a fresh signed customize token for the current editor, so the customize engine can keep a
     * long editing session valid. Authorised like the Customize view itself.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function customizeToken()
    {
        if (!Session::checkToken('get')) {
            echo new JsonResponse(null, Text::_('JINVALID_TOKEN'), true);

            return;
        }

        if (!$this->app->getIdentity()->authorise('core.edit', 'com_templates')) {
            echo new JsonResponse(null, Text::_('JERROR_ALERTNOAUTHOR'), true);

            return;
        }

        echo new JsonResponse(['token' => CustomizeMode::mintToken((int) $this->app->getIdentity()->id)]);
    }

    /**
     * Template-scoped customize actions a template's own JS can call without shipping a plugin. The
     * active style id arrives in the "id" field; the action in "action"; its data in "payload" (JSON).
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function customize()
    {
        if (!Session::checkToken('post')) {
            echo new JsonResponse(null, Text::_('JINVALID_TOKEN'), true);

            return;
        }

        // Template editing is the gate, matching who may edit template files.
        if (!$this->app->getIdentity()->authorise('core.admin', 'com_templates')) {
            echo new JsonResponse(null, Text::_('JERROR_ALERTNOAUTHOR'), true);

            return;
        }

        $action  = $this->input->getCmd('action', '');
        $styleId = $this->input->getInt('id', 0);
        $payload = json_decode($this->input->get('payload', '', 'raw'), true) ?: [];

        $element = $this->resolveElement($styleId);

        if ($element === '') {
            echo new JsonResponse(null, Text::_('COM_TEMPLATES_CUSTOMIZE_INVALID_STYLE'), true);

            return;
        }

        switch ($action) {
            // Reads operate on the style's current (child, if any) template.
            case 'getCustomizeCss':
                echo new JsonResponse(['success' => true, 'css' => $this->readManaged($element)]);
                break;

            case 'listPositions':
                echo new JsonResponse(['success' => true, 'grids' => $this->readGrids($element)]);
                break;

            case 'ensureChild':
                echo new JsonResponse($this->ensureChild($styleId, $element));
                break;

            // Persisted writes are per-style: a template's media/ + templates/ dirs are shared across
            // all its styles, so any change must land in this style's own child template. Create/switch
            // to it first (idempotent once created), then write to the child.
            case 'writeCustomizeCss':
            case 'saveArrangement':
            case 'addPosition':
            case 'removePosition':
            case 'splitPosition':
            case 'reorderSplit':
            case 'resizeSplit':
            case 'unsplitPosition':
                $child = $this->ensureChild($styleId, $element);

                if (empty($child['success'])) {
                    echo new JsonResponse($child);
                    break;
                }

                $el = $child['child'];

                if ($action === 'writeCustomizeCss') {
                    echo new JsonResponse($this->writeCustomizeCss($el, $payload));
                } elseif ($action === 'saveArrangement') {
                    echo new JsonResponse($this->saveArrangement($el, $payload));
                } elseif ($action === 'addPosition') {
                    echo new JsonResponse($this->addPosition($el, $payload));
                } elseif ($action === 'splitPosition') {
                    echo new JsonResponse($this->splitPosition($el, $payload));
                } elseif ($action === 'reorderSplit') {
                    echo new JsonResponse($this->reorderSplit($el, $payload));
                } elseif ($action === 'resizeSplit') {
                    echo new JsonResponse($this->resizeSplit($el, $payload));
                } elseif ($action === 'unsplitPosition') {
                    echo new JsonResponse($this->unsplitPosition($el, $payload));
                } else {
                    echo new JsonResponse($this->removePosition($el, $payload));
                }

                break;

            default:
                echo new JsonResponse(null, Text::_('COM_TEMPLATES_CUSTOMIZE_UNKNOWN_ACTION'), true);
        }
    }

    /**
     * Resolve the (site) template element a style belongs to. Returns '' for an unknown or admin style.
     *
     * @param   integer  $styleId  The template style id.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function resolveElement(int $styleId): string
    {
        if ($styleId <= 0) {
            return '';
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['template', 'client_id']))
            ->from($db->quoteName('#__template_styles'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $styleId, ParameterType::INTEGER);

        $row = $db->setQuery($query)->loadObject();

        if (!$row || (int) $row->client_id !== 0 || !preg_match('/^[a-zA-Z0-9_-]+$/', (string) $row->template)) {
            return '';
        }

        return (string) $row->template;
    }

    /**
     * Absolute path to a template's customize.css (validated to stay under the template media dir).
     *
     * @param   string  $element  The site template element.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function cssPath(string $element): string
    {
        $base = JPATH_ROOT . '/media/templates/site/' . $element . '/css';

        return Path::check($base . '/customize.css', JPATH_ROOT . '/media/templates/site');
    }

    /**
     * Write the supplied CSS into the managed block of the template's customize.css, preserving
     * anything outside the markers.
     *
     * @param   string  $element  The site template element.
     * @param   array   $payload  { css: string }.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function writeCustomizeCss(string $element, array $payload): array
    {
        $body = (string) ($payload['css'] ?? '');
        $body = str_replace("\0", '', $body);

        // Generous cap; the managed block holds a handful of grid rules, not a stylesheet.
        if (\strlen($body) > 20000) {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_CUSTOMIZE_CSS_TOO_LARGE')];
        }

        $path  = $this->cssPath($element);
        $block = self::CSS_START . "\n" . trim($body) . "\n" . self::CSS_END . "\n";

        $existing = is_file($path) ? (string) file_get_contents($path) : '';

        if (strpos($existing, self::CSS_START) !== false && strpos($existing, self::CSS_END) !== false) {
            $pattern  = '/' . preg_quote(self::CSS_START, '/') . '.*?' . preg_quote(self::CSS_END, '/') . "\n?/s";
            $contents = preg_replace($pattern, $block, $existing);
        } else {
            $contents = $existing === '' ? $block : rtrim($existing) . "\n\n" . $block;
        }

        $dir = \dirname($path);

        if (!is_dir($dir) && !Folder::create($dir)) {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_ERROR_SOURCE_FILE_NOT_WRITABLE')];
        }

        if (File::write($path, $contents) === false) {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_ERROR_SOURCE_FILE_NOT_WRITABLE')];
        }

        return ['success' => true];
    }

    /**
     * Read the current managed-block body from a template's customize.css.
     *
     * @param   string  $element  The site template element.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function readManaged(string $element): string
    {
        $path = $this->cssPath($element);

        if (!is_file($path)) {
            return '';
        }

        $contents = (string) file_get_contents($path);
        $pattern  = '/' . preg_quote(self::CSS_START, '/') . '\n?(.*?)\n?' . preg_quote(self::CSS_END, '/') . '/s';

        return preg_match($pattern, $contents, $m) ? trim($m[1]) : '';
    }

    /**
     * Path to a template's customize positions manifest (validated to stay under the templates dir).
     *
     * @param   string  $element  The site template element.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function manifestPath(string $element): string
    {
        return Path::check(JPATH_ROOT . '/templates/' . $element . '/customize-positions.json', JPATH_ROOT . '/templates');
    }

    /**
     * Read a template's per-grid arrangement (v2 manifest), normalised from a legacy v1 manifest if
     * needed: [ <grid> => [ 'order' => [], 'hidden' => [], 'added' => [] ] ].
     *
     * @param   string  $element  The site template element.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function readGrids(string $element): array
    {
        return LayoutHelper::readArrangement($element)['grids'];
    }

    /**
     * Read a template's flat split map ([ <position> => [ 'layout' => string, 'positions' => [] ] ]).
     *
     * @param   string  $element  The site template element.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function readSplits(string $element): array
    {
        return LayoutHelper::readArrangement($element)['splits'] ?? [];
    }

    /**
     * Write a template's per-grid arrangement, preserving its splits.
     *
     * @param   string  $element  The site template element.
     * @param   array   $grids    The grids map.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function writeGrids(string $element, array $grids): bool
    {
        return $this->writeManifest($element, $grids, $this->readSplits($element));
    }

    /**
     * Write a template's split map, preserving its grids.
     *
     * @param   string  $element  The site template element.
     * @param   array   $splits   The flat, position-keyed split map.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function writeSplits(string $element, array $splits): bool
    {
        return $this->writeManifest($element, $this->readGrids($element), $splits);
    }

    /**
     * Write the whole v2 manifest (grids + splits) and drop the layout helper's cached copy so a
     * subsequent read in this request, or the next page render, sees the change.
     *
     * @param   string  $element  The site template element.
     * @param   array   $grids    The grids map.
     * @param   array   $splits   The flat split map.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function writeManifest(string $element, array $grids, array $splits): bool
    {
        $data = ['version' => 2, 'grids' => (object) $grids];

        if (!empty($splits)) {
            $data['splits'] = (object) $splits;
        }

        $ok = File::write($this->manifestPath($element), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;

        LayoutHelper::clearArrangementCache($element);

        return $ok;
    }

    /**
     * Whether a site template extension with this element exists.
     *
     * @param   string  $element  The template element.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function templateExists(string $element): bool
    {
        return $this->templateExtensionId($element) > 0;
    }

    /**
     * The extension id of a site template, or 0.
     *
     * @param   string  $element  The template element.
     *
     * @return  integer
     *
     * @since   __DEPLOY_VERSION__
     */
    private function templateExtensionId(string $element): int
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('template'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('element') . ' = :el')
            ->bind(':el', $element);

        return (int) $db->setQuery($query)->loadResult();
    }

    /**
     * Point a template style at a child template, recording the parent so Joomla treats it as a child
     * (inheriting the parent's index.php and assets).
     *
     * @param   integer  $styleId  The style id.
     * @param   string   $element  The child template element to use.
     * @param   string   $parent   The parent template element.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function switchStyleTemplate(int $styleId, string $element, string $parent): void
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__template_styles'))
            ->set($db->quoteName('template') . ' = :el')
            ->set($db->quoteName('parent') . ' = :parent')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':el', $element)
            ->bind(':parent', $parent)
            ->bind(':id', $styleId, ParameterType::INTEGER);

        $db->setQuery($query)->execute();
    }

    /**
     * Ensure the customized style runs on its own Cassiopeia child template, so layout edits (new and
     * reordered positions) stay isolated from the parent. Creates the child the first time and points
     * this style at it.
     *
     * @param   integer  $styleId  The style id.
     * @param   string   $element  The style's current template element.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function ensureChild(int $styleId, string $element): array
    {
        // Already a customize child (it carries a manifest): nothing to do.
        if (is_file($this->manifestPath($element))) {
            return ['success' => true, 'child' => $element];
        }

        $childElement = $element . '_customize';

        if (!$this->templateExists($childElement) && !$this->installChild($element, 'customize')) {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_ERROR_COULD_NOT_COPY')];
        }

        $this->switchStyleTemplate($styleId, $childElement, $element);

        if (!is_file($this->manifestPath($childElement))) {
            $this->writeGrids($childElement, []);
        }

        // Per-style isolation: a CSS change saved before the child existed went to the parent's shared
        // media dir (affecting every style). Carry that managed block into the child so it stays with
        // this style, then later parent reads no longer apply to it.
        $parentManaged = $this->readManaged($element);

        if ($parentManaged !== '' && $this->readManaged($childElement) === '') {
            $this->writeCustomizeCss($childElement, ['css' => $parentManaged]);
        }

        return ['success' => true, 'child' => $childElement];
    }

    /**
     * Build and install a child of a site template, using the native com_templates child + folder
     * installer flow (so the extension is registered properly).
     *
     * @param   string  $sourceElement  The parent template element.
     * @param   string  $newName        The child suffix (child element becomes <source>_<newName>).
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function installChild(string $sourceElement, string $newName): bool
    {
        $sourceId = $this->templateExtensionId($sourceElement);

        if ($sourceId <= 0) {
            return false;
        }

        $tmpPrefix = uniqid('template_child_');
        $toPath    = $this->app->get('tmp_path') . '/' . $tmpPrefix;

        /** @var \Joomla\Component\Templates\Administrator\Model\TemplateModel $model */
        $model = $this->app->bootComponent('com_templates')->getMVCFactory()
            ->createModel('Template', 'Administrator', ['ignore_request' => true]);
        $model->setState('extension.id', $sourceId);
        $model->setState('new_name', $newName);
        $model->setState('tmp_prefix', $tmpPrefix);
        $model->setState('to_path', $toPath);

        if (!$model->child()) {
            return false;
        }

        $this->input->set('installtype', 'folder');
        $this->input->set('install_directory', $toPath);

        /** @var \Joomla\Component\Installer\Administrator\Model\InstallModel $installModel */
        $installModel = $this->app->bootComponent('com_installer')->getMVCFactory()
            ->createModel('Install', 'Administrator');
        $this->app->getLanguage()->load('com_installer');

        $ok = (bool) $installModel->install();
        $model->cleanup();

        return $ok;
    }

    /**
     * Add a new module position to the (child) template: declare it in templateDetails.xml so the
     * module manager offers it, and record it in the manifest so the layout renders it.
     *
     * @param   string  $element  The (child) template element.
     * @param   array   $payload  { name, region }.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function addPosition(string $element, array $payload): array
    {
        $name   = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($payload['name'] ?? '')));
        $region = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($payload['region'] ?? 'main'))) ?: 'main';

        if ($name === '') {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_CUSTOMIZE_BAD_POSITION_NAME')];
        }

        $grids = $this->readGrids($element);

        // A position name is unique across the whole template.
        foreach ($grids as $g) {
            if (\in_array($name, $g['added'] ?? [], true)) {
                return ['success' => false, 'message' => Text::_('COM_TEMPLATES_CUSTOMIZE_POSITION_EXISTS')];
            }
        }

        $grid          = $grids[$region] ?? ['order' => [], 'hidden' => [], 'added' => []];
        $grid['added'] = array_values(array_unique(array_merge($grid['added'] ?? [], [$name])));

        // If the grid already carries an explicit order, append the new block to it; otherwise leave
        // the order empty so the renderer appends added positions after the declared blocks.
        if (!empty($grid['order'])) {
            $grid['order'][] = $name;
        }

        $grids[$region] = $grid;

        if (!$this->writeGrids($element, $grids) || !$this->declarePosition($element, $name, true)) {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_ERROR_SOURCE_FILE_NOT_WRITABLE')];
        }

        return ['success' => true, 'name' => $name, 'region' => $region];
    }

    /**
     * Persist a grid's block order (and optionally which blocks are hidden), preserving the grid's
     * added positions. Block ids are the stable ids the layout helper emits.
     *
     * @param   string  $element  The (child) template element.
     * @param   array   $payload  { grid, order: string[], hidden?: string[] }.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function saveArrangement(string $element, array $payload): array
    {
        $grid = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($payload['grid'] ?? '')));

        if ($grid === '') {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_CUSTOMIZE_BAD_POSITION_NAME')];
        }

        $clean = static function ($list): array {
            $out = [];

            foreach ((array) $list as $id) {
                $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $id));

                if ($id !== '') {
                    $out[] = $id;
                }
            }

            return array_values(array_unique($out));
        };

        $grids      = $this->readGrids($element);
        $g          = $grids[$grid] ?? ['order' => [], 'hidden' => [], 'added' => []];
        $g['order'] = $clean($payload['order'] ?? []);

        if (isset($payload['hidden'])) {
            $g['hidden'] = $clean($payload['hidden']);
        }

        $grids[$grid] = $g;

        if (!$this->writeGrids($element, $grids)) {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_ERROR_SOURCE_FILE_NOT_WRITABLE')];
        }

        return ['success' => true];
    }

    /**
     * Remove a custom position from the manifest and the template's declared positions.
     *
     * @param   string  $element  The (child) template element.
     * @param   array   $payload  { name }.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function removePosition(string $element, array $payload): array
    {
        $name   = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($payload['name'] ?? '')));
        $grids  = $this->readGrids($element);
        $splits = $this->readSplits($element);

        foreach ($grids as $region => $g) {
            $g['order']     = array_values(array_diff($g['order'] ?? [], [$name]));
            $g['hidden']    = array_values(array_diff($g['hidden'] ?? [], [$name]));
            $g['added']     = array_values(array_diff($g['added'] ?? [], [$name]));
            $grids[$region] = $g;
        }

        $this->declarePosition($element, $name, false);

        // If the position was split, drop the split (and any nested ones) and undeclare its
        // sub-positions, so removing a position never leaves orphaned split data or positions behind.
        if (isset($splits[$name])) {
            $descendants = [];
            $keys        = [];
            $this->collectSplit($splits, $name, $descendants, $keys);

            foreach (array_values(array_unique($descendants)) as $pos) {
                $this->declarePosition($element, $pos, false);
            }

            foreach (array_unique($keys) as $key) {
                unset($splits[$key]);
            }
        }

        $this->writeManifest($element, $grids, $splits);

        return ['success' => true];
    }

    /**
     * Split a position into N sub-positions for a chosen layout: declare the new sub-positions, move
     * the original position's modules across them by order, and record the split in the manifest (the
     * layout helper renders the block as a flex/grid row of those positions).
     *
     * @param   string  $element  The (child) template element.
     * @param   array   $payload  { block, layout }.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function splitPosition(string $element, array $payload): array
    {
        $block    = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($payload['block'] ?? '')));
        $layoutId = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($payload['layout'] ?? '')));
        $layout   = LayoutHelper::splitLayout($layoutId);

        if ($block === '' || $layout === null) {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_CUSTOMIZE_BAD_POSITION_NAME')];
        }

        // The first sub-position keeps the original name; the rest get -2, -3, ... suffixes.
        $positions = [$block];

        for ($i = 2; $i <= (int) $layout['n']; $i++) {
            $positions[] = $block . '-' . $i;

            $this->declarePosition($element, $block . '-' . $i, true);
        }

        $this->distributeModules($block, $positions);

        // Splits are stored in a flat, position-keyed map (not under a grid), so any position is
        // splittable, whether it is a main-column block or an outer template region.
        $splits         = $this->readSplits($element);
        $splits[$block] = ['layout' => $layoutId, 'positions' => $positions];

        if (!$this->writeSplits($element, $splits)) {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_ERROR_SOURCE_FILE_NOT_WRITABLE')];
        }

        return ['success' => true, 'positions' => $positions];
    }

    /**
     * Reorder the sub-positions of an existing split (drag one cell among the others). The split's slot
     * widths are positional, so this just changes which sub-position sits in which slot; no module moves.
     *
     * @param   string  $element  The (child) template element.
     * @param   array   $payload  { owner, order: string[] }.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function reorderSplit(string $element, array $payload): array
    {
        $owner  = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($payload['owner'] ?? '')));
        $splits = $this->readSplits($element);

        if ($owner === '' || !isset($splits[$owner]['positions'])) {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_CUSTOMIZE_BAD_POSITION_NAME')];
        }

        $current = (array) $splits[$owner]['positions'];
        $clean   = [];

        foreach ((array) ($payload['order'] ?? []) as $name) {
            $name = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $name));

            if ($name !== '' && \in_array($name, $current, true) && !\in_array($name, $clean, true)) {
                $clean[] = $name;
            }
        }

        // Keep any sub-position the client did not report (defensive), so none is ever dropped.
        foreach ($current as $name) {
            if (!\in_array($name, $clean, true)) {
                $clean[] = $name;
            }
        }

        // Only a permutation is valid; a different set means the payload was off, so change nothing.
        if (\count($clean) !== \count($current)) {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_CUSTOMIZE_BAD_POSITION_NAME')];
        }

        $splits[$owner]['positions'] = $clean;

        if (!$this->writeSplits($element, $splits)) {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_ERROR_SOURCE_FILE_NOT_WRITABLE')];
        }

        return ['success' => true, 'positions' => $clean];
    }

    /**
     * Resize the cells of a split: store one relative weight (flex-grow value) per sub-position, by
     * slot, so the layout helper renders the cells in those proportions. A pure layout change, no module
     * moves; the weights are positional, so a later cell reorder keeps the slot widths.
     *
     * @param   string  $element  The (child) template element.
     * @param   array   $payload  { owner, weights: number[] }.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function resizeSplit(string $element, array $payload): array
    {
        $owner  = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($payload['owner'] ?? '')));
        $splits = $this->readSplits($element);

        if ($owner === '' || !isset($splits[$owner]['positions'])) {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_CUSTOMIZE_BAD_POSITION_NAME')];
        }

        $weights = [];

        foreach ((array) ($payload['weights'] ?? []) as $w) {
            $w         = (float) $w;
            $weights[] = $w > 0 ? round($w, 3) : 1;
        }

        // One weight per sub-position; a mismatch means the payload was off, so change nothing.
        if (\count($weights) !== \count((array) $splits[$owner]['positions'])) {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_CUSTOMIZE_BAD_POSITION_NAME')];
        }

        $splits[$owner]['weights'] = $weights;

        if (!$this->writeSplits($element, $splits)) {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_ERROR_SOURCE_FILE_NOT_WRITABLE')];
        }

        return ['success' => true, 'weights' => $weights];
    }

    /**
     * Undo a split: fold every sub-position's modules back into the owner, undeclare the extra
     * positions, and drop the split (and any nested splits) from the manifest. The owner renders as a
     * single position again, with no orphaned positions or stranded modules left behind.
     *
     * @param   string  $element  The (child) template element.
     * @param   array   $payload  { block } the split owner position.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function unsplitPosition(string $element, array $payload): array
    {
        $owner  = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($payload['block'] ?? '')));
        $splits = $this->readSplits($element);

        if ($owner === '' || !isset($splits[$owner])) {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_CUSTOMIZE_BAD_POSITION_NAME')];
        }

        // Gather the whole split subtree: the extra sub-positions to fold in + the split keys to drop.
        $descendants = [];
        $keys        = [];
        $this->collectSplit($splits, $owner, $descendants, $keys);
        $descendants = array_values(array_unique($descendants));

        // Move the sub-positions' modules back into the owner, then remove the extra positions.
        $this->gatherModules($owner, $descendants);

        foreach ($descendants as $pos) {
            $this->declarePosition($element, $pos, false);
        }

        foreach (array_unique($keys) as $key) {
            unset($splits[$key]);
        }

        if (!$this->writeSplits($element, $splits)) {
            return ['success' => false, 'message' => Text::_('COM_TEMPLATES_ERROR_SOURCE_FILE_NOT_WRITABLE')];
        }

        return ['success' => true];
    }

    /**
     * Walk a split subtree from $owner, collecting its sub-positions (excluding the owner) into
     * $positions and every split key it touches (including nested ones) into $keys.
     *
     * @param   array     $splits     The flat split map.
     * @param   string    $owner      The split owner to walk from.
     * @param   string[]  $positions  Out: sub-positions to fold/undeclare.
     * @param   string[]  $keys       Out: split keys to drop.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function collectSplit(array $splits, string $owner, array &$positions, array &$keys): void
    {
        if (!isset($splits[$owner]['positions']) || \in_array($owner, $keys, true)) {
            return;
        }

        $keys[] = $owner;

        foreach ((array) $splits[$owner]['positions'] as $pos) {
            $pos = (string) $pos;

            if ($pos === $owner) {
                continue;
            }

            $positions[] = $pos;

            // A sub-position can itself be split (nested); fold that in too.
            if (isset($splits[$pos])) {
                $this->collectSplit($splits, $pos, $positions, $keys);
            }
        }
    }

    /**
     * Move every module in the given positions into $target, appended after its existing modules,
     * preserving each source position's order.
     *
     * @param   string    $target         The position to gather into.
     * @param   string[]  $fromPositions  The positions to drain.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function gatherModules(string $target, array $fromPositions): void
    {
        $db       = Factory::getContainer()->get(DatabaseInterface::class);
        $ordering = (int) $db->setQuery(
            $db->createQuery()
                ->select('MAX(' . $db->quoteName('ordering') . ')')
                ->from($db->quoteName('#__modules'))
                ->where($db->quoteName('position') . ' = ' . $db->quote($target))
                ->where($db->quoteName('client_id') . ' = 0')
        )->loadResult();

        foreach ($fromPositions as $from) {
            if ($from === $target) {
                continue;
            }

            $ids = $db->setQuery(
                $db->createQuery()
                    ->select($db->quoteName('id'))
                    ->from($db->quoteName('#__modules'))
                    ->where($db->quoteName('position') . ' = ' . $db->quote($from))
                    ->where($db->quoteName('client_id') . ' = 0')
                    ->order($db->quoteName('ordering'))
            )->loadColumn() ?: [];

            foreach ($ids as $id) {
                $ordering++;
                $db->setQuery(
                    $db->createQuery()
                        ->update($db->quoteName('#__modules'))
                        ->set($db->quoteName('position') . ' = ' . $db->quote($target))
                        ->set($db->quoteName('ordering') . ' = ' . (int) $ordering)
                        ->where($db->quoteName('id') . ' = ' . (int) $id)
                )->execute();
            }
        }
    }

    /**
     * Distribute the modules of one position across a set of positions, round-robin by ordering, so a
     * split spreads its existing modules evenly (module 1 to the 1st sub-position, 2 to the 2nd, ...).
     *
     * @param   string    $fromPosition  The position whose modules are distributed.
     * @param   string[]  $positions     The target positions (the first is usually $fromPosition).
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function distributeModules(string $fromPosition, array $positions): void
    {
        $n = \count($positions);

        if ($n < 2) {
            return;
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->createQuery()
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('position') . ' = ' . $db->quote($fromPosition))
            ->where($db->quoteName('client_id') . ' = 0')
            ->order($db->quoteName('ordering'));
        $ids = $db->setQuery($query)->loadColumn() ?: [];

        foreach ($ids as $i => $id) {
            $target = $positions[$i % $n];

            if ($target === $fromPosition) {
                continue;
            }

            $update = $db->createQuery()
                ->update($db->quoteName('#__modules'))
                ->set($db->quoteName('position') . ' = ' . $db->quote($target))
                ->where($db->quoteName('id') . ' = ' . (int) $id);
            $db->setQuery($update)->execute();
        }
    }

    /**
     * Add or remove a <position> in the template's templateDetails.xml.
     *
     * @param   string   $element  The template element.
     * @param   string   $name     The position name.
     * @param   boolean  $add      True to add, false to remove.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function declarePosition(string $element, string $name, bool $add): bool
    {
        $xmlFile = Path::check(JPATH_ROOT . '/templates/' . $element . '/templateDetails.xml', JPATH_ROOT . '/templates');

        if (!is_file($xmlFile)) {
            return false;
        }

        $xml = simplexml_load_string((string) file_get_contents($xmlFile));

        if ($xml === false || !isset($xml->positions)) {
            return false;
        }

        $present = false;

        foreach ($xml->positions->position as $pos) {
            if ((string) $pos === $name) {
                $present = true;
                break;
            }
        }

        if ($add && !$present) {
            $xml->positions->addChild('position', $name);
        } elseif (!$add && $present) {
            // simplexml cannot drop a node directly; rebuild the positions list without it.
            $remaining = [];

            foreach ($xml->positions->position as $pos) {
                if ((string) $pos !== $name) {
                    $remaining[] = (string) $pos;
                }
            }

            unset($xml->positions);
            $positions = $xml->addChild('positions');

            foreach ($remaining as $r) {
                $positions->addChild('position', $r);
            }
        } else {
            return true;
        }

        return File::write($xmlFile, $xml->asXML()) !== false;
    }
}
