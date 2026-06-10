# [6.2] Customize mode: a visual frontend editor launched from the menu manager

Draft PR description for the `feature/customize-mode` work. Move into the PR body when opening; remove
this file before submitting. See `CUSTOMIZE_PR_READINESS.md` for the remaining checklist.

## Summary

Adds a visual "Customize" mode: a **Customize** button on a menu item opens an admin view that hosts
the item's live frontend page in an iframe and lets you edit it in place: article text/title/image,
module settings and content, module placement (reorder, move between positions, add, remove), any
translated string, and a layout's template override, all from the rendered page.

Every edit is a normal authorised save and persists through **native Joomla mechanisms** (the article
model, the module table, language overrides, template overrides), so nothing new has to be understood
to maintain the data it produces.

## How it works

- A request only enters customize mode when it carries `customize=1` (`CustomizeMode::isActive()`); it
  is never made session-sticky, so normal browsing is unaffected. The frontend page then emits
  invisible `data-customize-*` wrappers only.
- The admin host view (`com_menus` view `customize`) loads that page in a same-origin iframe. The
  engine in the parent reads the iframe DOM, draws the hover/selection chrome and the toolbar, and
  drives edits.
- Each editing domain is a default-on plugin in a new **`customize`** plugin group: `content`,
  `module`, `position`, `language`, `view`. Saves go through `com_ajax` (`onAjax<Name>`), each with a
  CSRF token and a per-item permission check.
- **Core stays plugin-agnostic.** At each render point core only fires a generic, mutable event when
  customize mode is active and uses the returned string; the owning plugin adds all the markup:
  - `HtmlView::loadTemplate` fires `onCustomizeRenderView` (view blocks);
  - `ModulesRenderer` fires `onCustomizeModule` and `onCustomizeEmptyPosition` (module wrappers, drop
    zones);
  - `Language`/`Text` call a generic `CustomizeMode::recordString()` collector (translatable strings).

## Accessibility

Fully keyboard operable: a roving tabindex over the editable areas, arrow-key navigation, Enter/Space
to edit, Tab into the toolbar, Escape to leave, and `Ctrl`+arrows to reorder a block, move it to
another position, or `Delete` to remove it (the keyboard alternative to drag, WCAG 2.5.7). Areas carry
`role`, `aria-roledescription`, `aria-keyshortcuts` and an `aria-label`, and a polite live region
announces what you entered and how to edit it.

## Core changes (small, justified)

`libraries/src/Customize/CustomizeMode.php` (new runtime), and event hooks in
`libraries/src/MVC/View/HtmlView.php`, `libraries/src/Document/Renderer/Html/ModulesRenderer.php`,
`libraries/src/Language/{Language,Text}.php`, plus the `com_menus` customize view and the install/update
SQL. All hooks are gated by `CustomizeMode::isActive()`, so there is no cost on normal requests.

## Testing

1. Enable the five `customize` plugins (fresh install ships them on; existing installs get them via the
   update SQL).
2. Edit a menu item, click **Customize**.
3. Hover or Tab to an area and edit it; confirm the change persists on the live site.
4. Drag a module (or `Ctrl`+arrows) to reorder / move position; `Delete` to remove.
5. Edit a translated string; confirm a language override is written.
6. Use **Edit layout** on a view block; confirm a template override is created and the native template
   editor opens.

Unit tests: `tests/Unit/Libraries/Cms/Customize` and `tests/Unit/Plugin/Customize/**`.

## Known limitations

- **Same-origin only.** If the site frontend is on a different origin from the admin, the engine cannot
  read the iframe; a postMessage fallback is not yet implemented (a status message is shown).
- The engine/plugin assets are registered by path for now; by-name WebAsset manifest registration is
  prepared but pending (see the checklist).
