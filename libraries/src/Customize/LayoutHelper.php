<?php

/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Customize;

use Joomla\CMS\Factory;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Renders a template "grid" (a container whose direct children are reorderable position blocks) so it
 * participates in Customize mode without the template shipping any JS for moving/adding/removing blocks.
 *
 * A template calls self::grid() for each editable region, passing its blocks in default order. The
 * helper applies the saved per-grid arrangement (order, hidden, added positions) recorded in the
 * (child) template's manifest, so the choices show on the live site too. In Customize mode it also
 * wraps each interactive block in the generic data-customize-* contract the layout plugin reads; normal
 * visitors get the bare blocks in their arranged order with no extra markup.
 *
 * Persistence split: structural changes (move/hide/add/remove) live in the manifest and are applied
 * here (DOM order, so reading order matches visual order); purely visual changes (e.g. grid track
 * sizes) live in the template's customize.css, which the template loads itself.
 *
 * @since  __DEPLOY_VERSION__
 */
final class LayoutHelper
{
    /**
     * Whether the (static) split CSS has been emitted yet this request, so it is output only once.
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    private static $splitCssDone = false;

    /**
     * Per-request cache of parsed arrangements, keyed by template element, so a page that renders many
     * regions (a grid plus several outer positions) parses the manifest once. Cleared on write.
     *
     * @var    array<string, array>
     * @since  __DEPLOY_VERSION__
     */
    private static $arrangementCache = [];

    /**
     * Positions currently mid-render as a split, so a sub-position that points back at an ancestor
     * (only possible via a hand-edited manifest) renders as a plain include instead of looping.
     *
     * @var    array<string, bool>
     * @since  __DEPLOY_VERSION__
     */
    private static $splitting = [];

    /**
     * Render a grid's blocks in their arranged order.
     *
     * @param   string  $grid    The grid name (matches a key in the manifest's "grids").
     * @param   array   $blocks  Ordered default blocks. Each: [
     *                              'id'        => string  stable key used in the arrangement (required),
     *                              'position'  => string  module position name, if this block is one,
     *                              'html'      => string  the block's rendered markup (may hold jdoc tags),
     *                              'movable'   => bool     default true,
     *                              'removable' => bool     default true,
     *                            ].
     * @param   array   $options  [ 'addedStyle' => string ] module chrome style for added positions.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function grid(string $grid, array $blocks, array $options = []): string
    {
        $active      = CustomizeMode::isActive();
        $addedStyle  = (string) ($options['addedStyle'] ?? 'card');
        $arrangement = self::readArrangement(self::activeElement());
        $g           = $arrangement['grids'][$grid] ?? [];
        $order       = \is_array($g['order'] ?? null) ? $g['order'] : [];
        $hidden      = array_flip(\is_array($g['hidden'] ?? null) ? $g['hidden'] : []);
        $added       = \is_array($g['added'] ?? null) ? $g['added'] : [];
        // Splits are keyed by position name in a flat map, so any block (or outer position) can carry one.
        $splits      = \is_array($arrangement['splits'] ?? null) ? $arrangement['splits'] : [];

        // Index the template's default blocks by id, in declared order.
        $declared = [];

        foreach ($blocks as $b) {
            if (!empty($b['id']) && isset($b['html'])) {
                $declared[(string) $b['id']] = $b;
            }
        }

        // Build the final ordered list: explicit order first, then any unplaced declared blocks (in
        // their default order), then any unplaced added positions. Hidden ids are dropped throughout.
        $final = [];

        foreach ($order as $id) {
            $id = (string) $id;

            if (isset($hidden[$id]) || isset($final[$id])) {
                continue;
            }

            if (isset($declared[$id])) {
                $final[$id] = $declared[$id];
            } elseif (\in_array($id, $added, true)) {
                $final[$id] = self::addedBlock($id, $addedStyle);
            }
        }

        foreach ($blocks as $b) {
            $id = (string) ($b['id'] ?? '');

            if ($id === '' || isset($final[$id]) || isset($hidden[$id])) {
                continue;
            }

            $final[$id] = $b;
        }

        foreach ($added as $name) {
            $name = (string) $name;

            if (isset($final[$name]) || isset($hidden[$name])) {
                continue;
            }

            $final[$name] = self::addedBlock($name, $addedStyle);
        }

        // Render. On the live site each block is emitted bare, in order. In Customize mode every block
        // is wrapped so its slot is captured in the order the plugin reads back; only an interactive
        // block (movable or removable) becomes a "layout-block" area the engine can drag/keyboard-move/
        // remove. (No data-customize-position is emitted: the engine groups keyboard-reorder peers by
        // that attribute, so a per-block position would isolate each block; peers are the same-type
        // siblings in the grid container instead.)
        $out = '';

        foreach ($final as $id => $b) {
            // A split block renders a flex/grid row of its sub-positions instead of a single include;
            // the CSS is emitted inline once. Splits render on the live site too, so this is outside the
            // customize-only branch below. The split is keyed by the block's module position.
            $pos     = (string) ($b['position'] ?? $id);
            $isSplit = $pos !== '' && isset($splits[$pos]);

            if ($isSplit) {
                self::$splitting[$pos] = true;
                $html                  = self::splitCss() . self::renderSplit($pos, $splits[$pos], $addedStyle);
                unset(self::$splitting[$pos]);
            } else {
                $html = (string) $b['html'];
            }

            if (!$active) {
                $out .= $html;
                continue;
            }

            $movable     = !isset($b['movable']) || $b['movable'];
            $removable   = !isset($b['removable']) || $b['removable'];
            $interactive = $movable || $removable;

            $attrs = ' data-customize-block="' . self::e((string) $id) . '"';

            if ($interactive) {
                // The engine shows "<area label> · <name>" in the toolbar; surface the position name
                // (or an explicit label, else the block id) so blocks are distinguishable.
                $label = (string) ($b['name'] ?? ($b['position'] ?? $id));

                $attrs .= ' data-customize-type="layout-block" data-customize-id="' . self::e((string) $id) . '"'
                    . ' data-customize-name="' . self::e($label) . '"';

                if ($movable) {
                    $attrs .= ' data-customize-movable';
                }

                if ($removable) {
                    $attrs .= ' data-customize-removable';
                }

                // A module-position block that is not already split can be split into sub-positions.
                if (!empty($b['position']) && !$isSplit) {
                    $attrs .= ' data-customize-splittable';
                }
            }

            $out .= '<div class="customize-block"' . $attrs . '>' . $html . '</div>';
        }

        return $out;
    }

    /**
     * Synthesise a block descriptor for a position added in Customize mode (no template-declared block
     * exists for it), rendered as a module position.
     *
     * @param   string  $position  The added position name.
     * @param   string  $style     The module chrome style.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function addedBlock(string $position, string $style): array
    {
        return [
            'id'        => $position,
            'position'  => $position,
            'html'      => '<jdoc:include type="modules" name="' . self::e($position) . '" style="' . self::e($style) . '" />',
            'movable'   => true,
            'removable' => true,
        ];
    }

    /**
     * Render a single module position that is not part of a grid (an outer template region, e.g. a
     * sidebar or a top row), applying a split if one is recorded for it. A template calls this in place
     * of its bare `<jdoc:include type="modules" ...>` so the position becomes splittable; the contract
     * markup that makes it selectable in Customize mode is emitted separately by self::positionAttrs(),
     * placed on the position's own container so no extra wrapper disturbs the template's layout.
     *
     * @param   string  $position     The module position name.
     * @param   string  $defaultHtml  The markup to render when the position is not split (normally the
     *                                 template's own jdoc include).
     * @param   array   $options      [ 'addedStyle' => string ] module chrome style for split cells.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function position(string $position, string $defaultHtml, array $options = []): string
    {
        $splits = self::readArrangement(self::activeElement())['splits'] ?? [];

        if (!isset($splits[$position]) || isset(self::$splitting[$position])) {
            return $defaultHtml;
        }

        self::$splitting[$position] = true;
        $html                       = self::splitCss() . self::renderSplit($position, $splits[$position], (string) ($options['addedStyle'] ?? 'card'));
        unset(self::$splitting[$position]);

        return $html;
    }

    /**
     * The data-customize contract attributes for an outer module position, so the layout plugin offers
     * a Split control on it. Empty outside Customize mode (normal visitors get untouched markup). The
     * position is selectable but not draggable: it is a "layout-position" area (no move/remove), since
     * reordering outer regions is template-grid specific, whereas a split stays inside the region.
     *
     * @param   string  $position  The module position name.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function positionAttrs(string $position): string
    {
        // A split position's container is just the wrapper for the split row; the sub-position cells
        // inside carry their own contract (see renderSplit), so the container itself stays plain.
        if (!CustomizeMode::isActive() || self::isSplit($position)) {
            return '';
        }

        return ' data-customize-type="layout-position"'
            . ' data-customize-block="' . self::e($position) . '"'
            . ' data-customize-name="' . self::e($position) . '"'
            . ' data-customize-splittable';
    }

    /**
     * The data-customize contract for one cell of a split. Each cell is a "split-cell" area: its own
     * toolbar and outline, fillable, splittable further, and draggable to reorder it among the split's
     * other cells (the owner attribute ties it to its split so a drag never crosses into another one).
     * The original position keeps its name and so reads as already split (no further Split control).
     *
     * @param   string  $position  The sub-position name.
     * @param   string  $owner      The position whose split this cell belongs to.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function splitCellAttrs(string $position, string $owner): string
    {
        if (!CustomizeMode::isActive()) {
            return '';
        }

        $attrs = ' data-customize-type="split-cell"'
            . ' data-customize-block="' . self::e($position) . '"'
            . ' data-customize-name="' . self::e($position) . '"'
            . ' data-customize-split-owner="' . self::e($owner) . '"';

        if (!self::isSplit($position)) {
            $attrs .= ' data-customize-splittable';
        }

        return $attrs;
    }

    /**
     * Whether a module position currently has a split recorded for it (so a template can keep rendering
     * the region even when the original position itself ended up empty after the modules were spread).
     *
     * @param   string  $position  The module position name.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function isSplit(string $position): bool
    {
        return isset(self::readArrangement(self::activeElement())['splits'][$position]);
    }

    /**
     * The available "split" layouts: ways to divide one position into N side-by-side sub-positions.
     * Each: [ 'n' => count, 'weights' => per-column flex grow, 'grid' => true for a 2x2 grid ]. Shared
     * by the split server action (to know how many positions to create) and the renderer.
     *
     * @return  array<string, array>
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function splitLayouts(): array
    {
        return [
            'cols-2'     => ['n' => 2, 'weights' => [1, 1], 'grid' => false],
            'cols-2-13'  => ['n' => 2, 'weights' => [1, 2], 'grid' => false],
            'cols-2-31'  => ['n' => 2, 'weights' => [2, 1], 'grid' => false],
            'cols-3'     => ['n' => 3, 'weights' => [1, 1, 1], 'grid' => false],
            'cols-3-121' => ['n' => 3, 'weights' => [1, 2, 1], 'grid' => false],
            'cols-4'     => ['n' => 4, 'weights' => [1, 1, 1, 1], 'grid' => false],
            'rows-2'     => ['n' => 2, 'weights' => [1, 1], 'grid' => false, 'rows' => true],
            'grid-2x2'   => ['n' => 4, 'weights' => [1, 1, 1, 1], 'grid' => true],
        ];
    }

    /**
     * A single split layout definition, or null if unknown.
     *
     * @param   string  $id  The layout id.
     *
     * @return  array|null
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function splitLayout(string $id): ?array
    {
        return self::splitLayouts()[$id] ?? null;
    }

    /**
     * Render a split block: a flex/grid/stacked row of its sub-positions. Each cell is a selectable
     * "layout-position" (own toolbar + outline) and may itself be split: the original (first) slot is
     * rendered as a plain include (it shares the split's name, so recursing would loop), the others
     * through self::position() so a nested split renders too.
     *
     * @param   string  $owner  The position being split (its first sub-position keeps this name).
     * @param   array   $split  { layout, positions: string[] }.
     * @param   string  $style  Module chrome style.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function renderSplit(string $owner, array $split, string $style): string
    {
        $positions = \is_array($split['positions'] ?? null) ? array_values($split['positions']) : [];
        $layout    = self::splitLayout((string) ($split['layout'] ?? ''));

        if (!$layout || !$positions) {
            // Fall back to the first position alone rather than dropping content.
            return $positions
                ? '<jdoc:include type="modules" name="' . self::e((string) $positions[0]) . '" style="' . self::e($style) . '" />'
                : '';
        }

        $isGrid  = !empty($layout['grid']);
        $isRows  = !empty($layout['rows']);
        $variant = $isGrid ? ' customize-split-grid' : ($isRows ? ' customize-split-rows' : '');

        // Effective per-cell weights: a saved custom set (from resizing) when its length matches the
        // cells, else the layout's defaults. With flex-basis:0 the cell sizes are proportional to these
        // grow values, so they apply on the live site too (the resize is not a customize-only effect).
        $weights = (isset($split['weights']) && \is_array($split['weights']) && \count($split['weights']) === \count($positions))
            ? array_values($split['weights'])
            : $layout['weights'];

        $active = CustomizeMode::isActive();
        $last   = \count($positions) - 1;
        $out    = '<div class="customize-split' . $variant . '">';

        foreach ($positions as $i => $pos) {
            $pos     = (string) $pos;
            $weight  = (float) ($weights[$i] ?? 1);
            $weight  = $weight > 0 ? $weight : 1;
            $include = '<jdoc:include type="modules" name="' . self::e($pos) . '" style="' . self::e($style) . '" />';
            $content = ($pos === $owner || isset(self::$splitting[$pos]))
                ? $include
                : self::position($pos, $include, ['addedStyle' => $style]);

            // A draggable separator after each cell but the last lets the editor resize adjacent cells.
            // Only for column splits: rows size to their content (no width to trade), and a 2x2 grid
            // would need two-axis handles. Never on the live site.
            $handle = ($active && !$isGrid && !$isRows && $i < $last)
                ? '<span class="customize-split-resize" data-customize-split-owner="' . self::e($owner)
                    . '" data-customize-split-index="' . $i . '" data-customize-split-dir="cols"></span>'
                : '';

            $out .= '<div class="customize-split-cell" style="flex-grow:' . self::num($weight) . '"'
                . self::splitCellAttrs($pos, $owner) . '>' . $content . $handle . '</div>';
        }

        return $out . '</div>';
    }

    /**
     * The (static) CSS for split blocks, emitted inline once per request. Columns wrap on small screens.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function splitCss(): string
    {
        if (self::$splitCssDone) {
            return '';
        }

        self::$splitCssDone = true;

        return '<style>'
            . '.customize-split{display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-start;}'
            . '.customize-split>.customize-split-cell{flex:1 1 0;min-width:0;}'
            . '.customize-split.customize-split-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));}'
            . '.customize-split.customize-split-rows{flex-direction:column;flex-wrap:nowrap;}'
            . '.customize-split.customize-split-rows>.customize-split-cell{flex:0 0 auto;width:100%;}'
            . '@media (max-width:575.98px){.customize-split{display:block;}.customize-split.customize-split-grid{grid-template-columns:minmax(0,1fr);}}'
            . '</style>';
    }

    /**
     * The active site template element (the child template, when the style runs on one).
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function activeElement(): string
    {
        return (string) Factory::getApplication()->getTemplate();
    }

    /**
     * Read and normalise a template's arrangement manifest to the v2 shape
     * ([ 'grids' => [ <grid> => [ 'order' => [], 'hidden' => [], 'added' => [] ] ], 'splits' => [
     * <position> => [ 'layout' => string, 'positions' => string[] ] ] ]). Cached per request.
     *
     * @param   string  $element  The (child) template element carrying the manifest.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function readArrangement(string $element): array
    {
        if (\array_key_exists($element, self::$arrangementCache)) {
            return self::$arrangementCache[$element];
        }

        $path = self::manifestPath($element);

        if ($element === '' || !is_file($path)) {
            return self::$arrangementCache[$element] = ['grids' => [], 'splits' => []];
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!\is_array($data)) {
            return self::$arrangementCache[$element] = ['grids' => [], 'splits' => []];
        }

        // v2: already keyed by grid.
        if (isset($data['grids']) && \is_array($data['grids'])) {
            $grids  = $data['grids'];
            $splits = (isset($data['splits']) && \is_array($data['splits'])) ? $data['splits'] : [];

            // Migrate any legacy per-grid splits into the flat, position-keyed map.
            foreach ($grids as $name => $g) {
                if (isset($g['splits']) && \is_array($g['splits'])) {
                    foreach ($g['splits'] as $pos => $split) {
                        $splits[$pos] = $splits[$pos] ?? $split;
                    }

                    unset($grids[$name]['splits']);
                }
            }

            return self::$arrangementCache[$element] = ['grids' => $grids, 'splits' => $splits];
        }

        // v1: a flat list of custom positions, each in a region. Map every one to that region's
        // "added" list so the helper appends it (no reordering or hiding existed in v1).
        $grids = [];

        foreach (($data['positions'] ?? []) as $p) {
            if (empty($p['name'])) {
                continue;
            }

            $region                    = (string) ($p['region'] ?? 'main');
            $grids[$region]['added'][] = (string) $p['name'];
        }

        return self::$arrangementCache[$element] = ['grids' => $grids, 'splits' => []];
    }

    /**
     * Drop the cached arrangement for a template (all templates when null), so a fresh read follows a
     * write. Called by the Customize host after it persists a manifest change.
     *
     * @param   string|null  $element  The template element, or null for all.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function clearArrangementCache(?string $element = null): void
    {
        if ($element === null) {
            self::$arrangementCache = [];

            return;
        }

        unset(self::$arrangementCache[$element]);
    }

    /**
     * Absolute path to a template's arrangement manifest.
     *
     * @param   string  $element  The template element.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function manifestPath(string $element): string
    {
        return JPATH_ROOT . '/templates/' . $element . '/customize-positions.json';
    }

    /**
     * Escape a value for an HTML attribute.
     *
     * @param   string  $value  The raw value.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Format a weight as a compact decimal (no trailing zeros) for an inline flex-grow value.
     *
     * @param   float  $n  The value.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function num(float $n): string
    {
        return rtrim(rtrim(\sprintf('%.3f', $n), '0'), '.');
    }
}
