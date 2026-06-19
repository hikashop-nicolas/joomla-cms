# Customize mode: changes for Joomla maintainers

This document maps what the `feature/customize-mode` branch adds and changes, by area, for reviewers.
For the user-facing overview see [CUSTOMIZE_DEMO.md](CUSTOMIZE_DEMO.md); for the extension contracts
see [CUSTOMIZE_PLUGINS.md](CUSTOMIZE_PLUGINS.md) and [CUSTOMIZE_TEMPLATES.md](CUSTOMIZE_TEMPLATES.md).

## Design rule (why core stays thin)

In Customize mode, core only fires **generic, mutable events** and uses the strings they return; the
`customize` plugins own all the markup, the editing UI and the persistence. So the core diff is small
and, when the mode is inactive, has **no effect on output** (events fire with no listeners, or behind
an `isActive()` check). The mode is activated per request by a signed token, never made sticky in the
session, so normal browsing and anonymous visitors are unaffected.

## Core (`libraries/src`)

Two new classes plus a handful of one-line hooks:

- **`Customize/CustomizeMode.php`** (new) - the request-scoped state: validates the signed activation
  token (`isActive()`), the "show all positions" sub-flag, and a collector that records translated
  strings (`recordString` / `recordSprintf`) for the language plugin.
- **`Customize/LayoutHelper.php`** (new) - renders a template's editable grids and outer positions
  through the `data-customize-*` contract and applies the per-style arrangement (order, hidden, added
  positions, splits); used by templates, not core.
- **`Application/SiteApplication.php`** - calls `CustomizeMode::detect()` once per request and, when
  active, emits the marker the engine looks for.
- **`Document/Renderer/Html/ModulesRenderer.php`** - in customize mode, fires `onCustomizeModule` per
  module and `onCustomizeEmptyPosition` for an empty position, and uses the returned markup.
- **`Document/HtmlDocument.php`** - `countModules()` reports an empty position as present when "show all
  positions" is on, so the template renders the full position map.
- **`Helper/ModuleHelper.php`** - a module layout that throws in customize mode renders a recoverable
  inline notice instead of breaking the page.
- **`MVC/View/HtmlView.php`** - `loadTemplate()` fires `onCustomizeRenderView` per view sub-layout (of
  any component) and uses the returned string.
- **`Language/Language.php` + `Language/Text.php`** - `Text::_` / `Text::sprintf` record the
  key -> rendered-string map (output unchanged) so the language plugin can map on-page text to its key.

## `com_templates` (the host)

The Customize host lives here, launched per template style:

- **`src/View/Customize/` + `tmpl/customize/`** (new) - the admin host view: it loads the engine and
  its API, registers the engine's JS strings, dispatches `onCustomizeAdminInit` to the plugins, and
  renders the live-preview iframe (forced to the edited style, with the signed token).
- **`src/Controller/AjaxController.php`** - a `customizeToken` task (mints the signed token for an
  authorised editor) and a `customize` task carrying the template-scoped actions:
  `getCustomizeCss` / `writeCustomizeCss`, `listPositions`, `ensureChild`, `saveArrangement`,
  `addPosition`, `removePosition`, `splitPosition`, `reorderSplit`, `resizeSplit`, `unsplitPosition`.
  All gated on a CSRF token + `core.admin`; names sanitised; paths resolved template-relative.
- **`ensureChild`** - the first persisted layout change moves the style onto its own child template
  (`<element>_customize`) so edits are isolated per style; the manifest
  (`templates/<child>/customize-positions.json`) and the resize CSS live there.
- **`src/View/Style/HtmlView.php`** + language - the **Customize** toolbar button on a site style.

## `com_menus` (former host, removed)

The host originally lived in `com_menus`; it has been removed there now that it launches per template
style: the `customize` view + tmpl, the `customizeToken` controller task and the toolbar button on the
menu-item edit view are gone, along with their `COM_MENUS_CUSTOMIZE_*` language keys.

## Front-end engine + API (`media/customize`, built from `media_source`)

- **`js/customize.api.es6.js`** - the `JoomlaCustomize` (`JC`) API: `registerAreaType` / `registerButton`,
  `callAction` (com_ajax) and `templateAction` (com_templates), the `ui` helpers (popover, toast,
  inline editor), `panel`, external-drag, transient management, and expired-session detection.
- **`js/customize.engine.es6.js`** - the overlay that runs on the host and drives the same-origin
  iframe: hover/select, the floating toolbar, keyboard access (Tab, Enter, Ctrl+arrows, Delete),
  generic drag-to-reorder, the token refresh, and the session-expiry redirect.
- **`css/customize.css`** (host) and **`css/customize-frame.css`** (injected into the iframe).

## Plugins (`plugins/customize/`)

A new `customize` plugin group. Each plugin instruments its output on the front-end and provides the
editing UI + a server save handler; all are permission-gated.

### content

Edits article body (in-place WYSIWYG with image picker), title, intro/full image, and category. Marks
the article on `onContentPrepare`; saves through the article model. Permission: `core.edit(.own)` on
`com_content`.

### module

Edits module settings, custom-module HTML, and a menu module's menu items (rename / reorder / delete),
plus the module's own layout override ("Edit layout"). Injects `data-customize-*` on the module's first
tag via `onCustomizeModule`; saves through the module table, the menu table, and template overrides.
Permission: `core.edit` on `com_modules` (menu items on `com_menus`).

### position

Module placement: reorder within a position, move a module to another position (including empty ones
via a picker), add and delete modules. Emits the empty-position drop zone via `onCustomizeEmptyPosition`
and owns the "Show all positions" toggle. Saves through the module table. Permission: `core.edit` or
`core.create` on `com_modules`.

### layout

Position-block layout (the new piece): move / hide / add a block, **Split** a position into columns,
ratios, stacked rows or a 2x2 grid, reorder and resize the split cells, and **Unsplit**. Consumes the
`LayoutHelper` contract a template emits (`layout-block`, `layout-position`, `split-cell` area types).
Saves through the `com_templates` template-scoped actions into the style's child template. Permission:
`core.admin` on `com_templates`.

### language

Edits any translated string on the page, saved as a site-wide language override. Reads the
key -> string map core records (a JSON island emitted on `onAfterRender`); no markup in core. Permission:
`core.admin`.

### view

Makes any view sub-layout overridable: wraps each layout (at any nesting depth) via
`onCustomizeRenderView` into a block whose **Edit layout** creates the template override and opens
Joomla's native editor; parent/child navigation walks the block hierarchy. Permission: `core.admin`.

## Cassiopeia (reference adopter)

Cassiopeia shows how a template opts into layout editing:

- **`templates/cassiopeia/index.php`** - the main content column renders through `LayoutHelper::grid`,
  and the outer positions (sidebars, top/bottom rows, banner, footer) through
  `LayoutHelper::positionAttrs` + `LayoutHelper::position`, so they are reorderable and splittable. It
  injects the per-style `customize.css` (resize) inline, with a parent-template fallback.
- **`media/templates/site/cassiopeia/js/customize.js`** (built from `media_source`) - the only
  template-specific JS: the sidebar/component ratio resize (drag + keyboard window-splitter, persisted
  to `customize.css`) and the swap-sidebars control.
- **`language/en-GB/tpl_cassiopeia.ini`** - the ratio/swap strings.

## Install + build

- **`installation/sql/{mysql,postgresql}/base.sql`** - the `customize` plugins ship enabled on a fresh
  install.
- **`administrator/components/com_admin/sql/updates/{mysql,postgresql}/*.sql`** - idempotent inserts so
  existing sites get them on update.
- The engine + plugin assets live in `build/media_source` and compile to `media/` through the standard
  pipeline (`npm run build`), registered via `joomla.asset.json` import maps.

## Tests

- **Unit** (`tests/Unit/Libraries/Cms/Customize`, `tests/Unit/Plugin/Customize`) - `CustomizeMode`
  (token validation), `LayoutHelper` (grid render, manifest migration, split rendering), and each
  plugin's render-time handler + validators.
- **System** (`tests/System/integration/administrator/components/com_templates/Customize.cy.js`) - the
  host loads and the engine + layout plugin register; a read action round-trips; and a full
  split -> module-distribution -> unsplit round-trip is asserted against the database.
