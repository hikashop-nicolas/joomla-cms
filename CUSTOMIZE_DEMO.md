# Customize mode: demo and testing guide

> This is a demo and testing document for the `feature/customize-mode` fork. It is not part of the
> eventual joomla-cms pull request (remove `CUSTOMIZE_DEMO.md` and `docs/customize-demo/` before
> opening the PR). The PR text lives in `PR_DESCRIPTION.md`.

Customize mode is a visual frontend editor launched from the menu manager. A **Customize** button on a
menu item opens an admin view that hosts the item's live frontend page in an iframe and lets you edit
the page in place: article text, titles, images and category; module settings, custom-module HTML and
menu items; module placement; any translated string; and a layout's template override. Every edit is a
normal authorised save that persists through native Joomla mechanisms (the article model, the module
table, language overrides, template overrides).

## Try it

This is a fork of joomla-cms (base: Joomla 6.1.0). It is a core source tree, not an installable
package, so you test it by installing Joomla from this branch.

1. Clone and check out the branch:
   ```
   git clone https://github.com/hikashop-nicolas/joomla-cms.git
   cd joomla-cms
   git checkout feature/customize-mode
   ```
2. Build the assets (the customize engine and plugins compile through the standard pipeline):
   ```
   composer install
   npm ci
   npm run build
   ```
3. Install Joomla from this source with the normal web installer (point it at an empty database). The
   five `customize` plugins ship enabled on a fresh install.
4. In the administrator, go to Menus, edit any menu item, and click **Customize** in the toolbar.

If you already have a 6.1 development checkout, you can instead merge `feature/customize-mode` into it
and re-run `npm run build`. Existing sites get the five plugins enabled through the update SQL in
`administrator/components/com_admin/sql/updates`.

## The editing flow

- The host loads the menu item's live frontend page in a same-origin iframe. The right panel shows a
  status (green when customize mode is active, with the editable-area count) and, for users who can
  manage modules, an **Add module** button.
- Hover or Tab to an editable area: it outlines and shows a small toolbar with the actions available
  for that area.
- Click **Edit** (or press Enter) to edit in place. Article and custom-module bodies open a WYSIWYG
  editor with image support; titles and translated strings edit inline; module and menu-item settings
  open a small popover.

  ![Editing an article body in place with the inline editor](docs/customize-demo/01-content-edit.png)

- Drag a module to reorder it or move it to another position, or use Ctrl with the arrow keys; press
  Delete to remove it. Drag works for menu items in a menu module too.

  ![Dragging a module to reorder it, with the drop zone highlighted](docs/customize-demo/02-module-reorder.png)

- Edit any translated string on the page (a label, a button, the "Written by" line). It saves as a
  Joomla language override that applies site-wide.

  ![Editing a translated string in place, saved as a language override](docs/customize-demo/03-language-edit.png)

## What you can edit

| Plugin | What it edits | Persists as |
|---|---|---|
| content | Article text, title, image, category | Article model save |
| module | Module settings, custom-module HTML, menu-item rename/reorder/delete | Module table / menu items |
| position | Module placement: reorder, move position, add, remove | Module table |
| language | Any translated string on the page | Language override |
| view | A layout's template override, and reorder of safe blocks | Template override |

## Permissions

Each plugin loads its editing UI only when you hold the permission needed to use it, so you never see
an affordance you cannot use (the per-action save is also checked):

- content: `core.edit` or `core.edit.own` on `com_content`
- module: the module area on `core.edit com_modules`, the menu-item area on `core.edit com_menus`
- position: `core.edit` or `core.create` on `com_modules`
- language and view (overrides): `core.admin`

## How it works

A request only enters customize mode when it carries `customize=1` (`CustomizeMode::isActive()`); it is
never session-sticky, so normal browsing is unaffected. At each render point, core fires a generic,
mutable event when the mode is active and uses the returned string; the owning plugin adds all the
`data-customize-*` markup, so core carries no plugin-specific knowledge. Saves go through `com_ajax`
(`onAjax<Name>`), each with a CSRF token and a per-item permission check. The admin parent reads the
same-origin iframe DOM, draws the hover and selection chrome, and drives the edits.

## Known limitations

- **Same-origin by design.** If the site frontend is served from a different origin than the
  administrator (an uncommon setup), the browser blocks iframe access; customize mode detects this and
  shows a clear status rather than failing. Cross-origin support would need a postMessage agent in the
  frontend, which is out of scope.
- Block reorder is offered only where the rendered output maps reliably to the layout source;
  otherwise the view plugin offers **Edit layout** but not drag.

## Feedback

Please open an issue on this fork with your Joomla version, PHP version, database, and steps. UI,
wording, accessibility, and edge-case reports are all welcome.
