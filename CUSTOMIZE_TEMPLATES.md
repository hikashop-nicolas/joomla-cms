# Supporting Customize mode in a template

This guide explains how to make a site template participate in Customize mode's **layout editing**:
moving, adding, removing, hiding, splitting and resizing the module-position blocks of a page. Module,
content, language and view editing work in any template with no template changes; this guide is about
the position/layout layer.

See also: [CUSTOMIZE_DEMO.md](CUSTOMIZE_DEMO.md) (overview + try it) and
[CUSTOMIZE_PLUGINS.md](CUSTOMIZE_PLUGINS.md) (writing a customize *plugin*).

## How it works

A template renders its editable regions through the core helper
`Joomla\CMS\Customize\LayoutHelper`. The helper does two things:

- On the **live site** it renders the region in the editor's saved arrangement (order, hidden blocks,
  added positions, splits), bare, with no extra markup.
- In **Customize mode** it also wraps each block in the `data-customize-*` contract the `layout` plugin
  reads, so the editor can move/hide/split/resize it.

So you do not hand-write the contract or the persistence; you call the helper and pass your blocks.
Emit nothing special outside customize mode, the helper handles that.

```php
use Joomla\CMS\Customize\CustomizeMode;
use Joomla\CMS\Customize\LayoutHelper;
```

## 1. A reorderable region: `LayoutHelper::grid()`

Use this for a flow region whose direct children are reorderable position blocks (e.g. the main
content column). Mark the container with `data-customize-grid` (customize mode only) and pass the
blocks in default order:

```php
<div class="container-component"<?php echo CustomizeMode::isActive() ? ' data-customize-grid="main"' : ''; ?>>
    <?php echo LayoutHelper::grid('main', [
        ['id' => 'breadcrumbs', 'position' => 'breadcrumbs', 'html' => '<jdoc:include type="modules" name="breadcrumbs" style="none" />'],
        ['id' => 'main-top',    'position' => 'main-top',    'html' => '<jdoc:include type="modules" name="main-top" style="card" />'],
        ['id' => 'component',   'html' => '<jdoc:include type="message" /><main><jdoc:include type="component" /></main>', 'removable' => false],
        ['id' => 'main-bottom', 'position' => 'main-bottom', 'html' => '<jdoc:include type="modules" name="main-bottom" style="card" />'],
    ]); ?>
</div>
```

Block descriptor:

| Key | |
|---|---|
| `id` | **required** stable key used in the manifest |
| `position` | the module position name, if the block is one (enables Split) |
| `html` | the rendered markup (may contain `jdoc` tags) |
| `movable` | default `true`; `false` pins the block |
| `removable` | default `true`; `false` blocks hiding (e.g. the component) |

The grid name (`'main'`) is yours; use one per editable region.

## 2. An outer position: `positionAttrs()` + `position()`

Use this for a region that is **not** part of a reorderable grid (a sidebar, a top/bottom row, a
banner, a footer). Put the contract on the position's own container and render its content through the
helper, so it can be split (and column-resized) without disturbing your outer grid:

```php
<?php if ($this->countModules('top-a', true) || LayoutHelper::isSplit('top-a')) : ?>
    <div class="grid-child container-top-a"<?php echo LayoutHelper::positionAttrs('top-a'); ?>>
        <?php echo LayoutHelper::position('top-a', '<jdoc:include type="modules" name="top-a" style="card" />'); ?>
    </div>
<?php endif; ?>
```

- `positionAttrs($position)` returns the `data-customize-*` attributes in customize mode (empty
  otherwise, and empty when the position is split, since the split's cells then carry the contract).
- `position($position, $defaultHtml)` returns `$defaultHtml` normally, or the split layout (a flex row
  of sub-positions) once the position has been split. On the live site it renders the split too.
- Guard with `isSplit()` so a split whose original cell ended up empty still renders.

## 3. Resize (optional, template-specific)

Reordering and splitting are structural and generic, so the helper and the `layout` plugin own them.
Resizing *grid tracks* is template-specific (every grid's track math differs), so it stays a small
template-provided script. Cassiopeia is the reference:

1. Emit a boundary handle in customize mode (e.g. `<span class="customize-region-boundary"
   data-customize-edge="left">`).
2. Ship an ES module at `media/templates/site/<element>/js/customize.js` that imports `customize.api`,
   wires the handle (pointer drag + arrow keys), and persists the computed CSS:
   ```js
   import JC from 'customize.api';
   // ... compute the grid-template-columns rule ...
   JC.templateAction('writeCustomizeCss', { css });
   ```
   The host loads this module by resolved media path when your template is active.
3. Load the saved CSS in `index.php` so the choice applies on the live site (a child template has no
   media of its own, so fall back to the parent's file):
   ```php
   $css = JPATH_ROOT . '/media/templates/site/' . $app->getTemplate() . '/css/customize.css';
   if (is_file($css)) { $wa->addInlineStyle(file_get_contents($css)); }
   ```

Make the handle a keyboard window-splitter for accessibility: `role="separator"`, `tabindex="0"`,
`aria-valuenow/valuetext`, and Arrow/Home/End keys (the split separator does this; mirror it).

## Persistence (per style, isolated)

The first persisted change moves the style onto its own child template (`<element>_customize`) so edits
never leak across styles. The child carries:

- `templates/<child>/customize-positions.json` - the v2 arrangement manifest:
  ```json
  {
    "version": 2,
    "grids":  { "main": { "order": [], "hidden": [], "added": [] } },
    "splits": { "top-a": { "layout": "cols-2", "positions": ["top-a", "top-a-2"], "weights": [1, 1] } }
  }
  ```
- `media/templates/site/<child>/css/customize.css` - a managed block of resize CSS.

All writes go through `com_templates`' `ajax.customize` task (CSRF + `core.admin`), which validates the
grid/position/layout names and resolves every path template-relative. You do not write these yourself.

## The contract the helper emits (reference)

| Attribute | On | Meaning |
|---|---|---|
| `data-customize-grid="<name>"` | a container | its `data-customize-block` children are reorderable |
| `data-customize-type="layout-block"` | a grid block | movable / removable / splittable position block |
| `data-customize-type="layout-position"` | an outer position | splittable, not draggable |
| `data-customize-type="split-cell"` | a cell of a split | draggable among its split's cells, splittable |
| `data-customize-block` / `-name` | a block / cell | stable id / toolbar label |
| `data-customize-movable` / `-removable` / `-splittable` | a block | capability flags |
| `data-customize-split-owner` | a split cell | the split it belongs to |

Cassiopeia's `templates/cassiopeia/index.php` and `media/templates/site/cassiopeia/js/customize.js` are
the full working example.
