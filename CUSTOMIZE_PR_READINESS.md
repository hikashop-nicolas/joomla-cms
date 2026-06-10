# Customize mode: PR-readiness checklist

Working notes for upstreaming the Customize feature (branch `feature/customize-mode`).
This file tracks what is left before opening a joomla-cms PR; remove it before submitting.

Audit date: 2026-06-10.

## Already in good shape (verified)
- ESLint passes clean on the customize engine + plugin JS (`build/eslint.config.mjs`).
- No debug leftovers (no console.log / var_dump / TODO / FIXME in customize source).
- Permissions: every ajax action checks the CSRF token and a per-item ACL
  (`core.edit`/`core.delete`/`core.create` on `com_modules.module.<id>` etc.; the file-writing
  view/language handlers gate on `core.admin`).
- View override writer is hardened: component/view/layout/block/template are sanitized
  (no path traversal), and it never overwrites an existing override.
- Keyboard + ARIA + live-region announcements implemented (DOM-verified).

## Hard blockers
- [~] **Tests.** 30 unit tests passing: `CustomizeModeTest` (runtime: detect/isActive, recordString
      + isRecordable, recordSprintf); render-time event-handler tests for the module, position, view
      and content plugins (module markup, drop zone, view-block markers, article-text wrapping with
      active/inactive/context/idempotency cases); and pure-logic security tests for the view override
      path sanitizer (`View::sanitizeOverrideRequest`) and the language override key/tag validators
      (`LanguageEditor::sanitizeKey` / `safeLanguageTag`). Plus a Cypress system test
      (`tests/System/integration/administrator/components/com_menus/Customize.cy.js`): it logs into
      the admin, opens the customize host for the home menu item, and asserts the host renders
      (toolbar title + preview iframe), the engine ES module loads (`window.JoomlaCustomize`), and the
      module plugin's buttons registered (proving the import-map wiring resolves at runtime). A second
      test makes a real inline content edit through the engine API (`callAction('content', 'save')`)
      and asserts the new text persisted in the article's introtext in the database. Both run-verified
      against the local clone (passing). More edit-action coverage (drag reorder, override creation)
      could follow the same pattern.
- [x] **Update SQL.** Done. Idempotent `INSERT ... WHERE NOT EXISTS` for the 5 plugins in
      `administrator/components/com_admin/sql/updates/{mysql,postgresql}/6.2.0-2026-06-10.sql`
      (targeting 6.2.0; validated on the test DB: applies clean, stays at 5 rows).
- [x] **Asset pipeline -> upstream conventions.** Done. The engine and the 5 plugins register by name
      from their WebAsset manifests (`$wa->getRegistry()->addExtensionRegistryFile(...)` +
      `useStyle`/`useScript`, names `customize.*` / `plg_customize_*.admin`); path-based registration
      removed. The blocker was the manifest URIs including `js/`/`css/`: `HTMLHelper mediaPath` inserts
      that folder itself, so they resolved to `media/customize/js/js/...` (missing) and the resolved
      URI was empty, so the document skipped them. Fixed by using `<extension>/<file>` URIs. Engine +
      all 5 plugins verified loading via the manifests. (Still drop the local-only
      `build/build-customize.mjs` for the PR; the standard build compiles `build/media_source`.)
- [~] **PHP code standards.** Installing composer dev deps to run `php-cs-fixer` + `phpunit`.

## Likely required by reviewers
- [~] Dedicated security review of the file-writing handlers. The view override path resolver is now
      extracted and unit-tested (no traversal possible); the language override writer and a broader
      pass still want review.
- [ ] Cross-origin / postMessage fallback, or document the same-origin-only limitation in the PR.
- [x] ES modules. Done. The engine + plugins are `.es6.js` ES modules: `customize.api` exports the API
      (default export, registered `importmap: true`) and the engine (`customize.engine`) + the 5
      plugins are `type="module"` that import it by bare specifier (`import JC from 'customize.api'`).
      Built through the standard rollup/babel pipeline (`handleESMFile`); `customize.api` was added to
      the build's `externalModules` so it stays one shared module rather than being bundled into each
      consumer. Engine + all plugins load, register, and a `callAction` round-trip works; eslint clean.
      (The engine source is `customize.engine.es6.js`, renamed off `*core.es6.js` which the build
      special-cases to an IIFE.)
- [~] Documentation: PR description + manual test steps drafted in `PR_DESCRIPTION.md`. User docs
      (manual.joomla.org) still to write.

## Polish
- [ ] Real screen-reader pass (VoiceOver / NVDA), only DOM-verified so far.
- [ ] Document/guard "move a module to a position the template doesn't render" (saves but vanishes
      from the preview; drag has the same behaviour).
- [ ] Self-review of the core-change footprint (HtmlView event, ModulesRenderer events,
      CustomizeMode, Language/Text recordString, SiteApplication, com_menus) to minimise/justify.
