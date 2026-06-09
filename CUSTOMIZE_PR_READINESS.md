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
- [~] **Tests.** Started: `tests/Unit/Libraries/Cms/Customize/CustomizeModeTest.php` covers the
      runtime (detect/isActive, recordString + isRecordable rules, recordSprintf). Still need unit
      tests for each plugin's ajax actions (harder: they touch db/session/filesystem) and
      system/Cypress tests (`tests/System`) for the flow.
- [x] **Update SQL.** Done. Idempotent `INSERT ... WHERE NOT EXISTS` for the 5 plugins in
      `administrator/components/com_admin/sql/updates/{mysql,postgresql}/6.1.0-2026-06-10.sql`
      (validated on the test DB: applies clean, stays at 5 rows). Rename the file if the feature
      targets a different version.
- [ ] **Asset pipeline -> upstream conventions.** The `joomla.asset.json` manifests already live in
      `build/media_source` and the standard build copies them into `media/` (recreate-media.mjs copies
      media_source wholesale), so that part is fine. The remaining work is the registration switch:
      WAM auto-loads component/template manifests, not a `media/customize/` path or plugin manifests,
      so switching from path-based `registerAndUseScript` to by-name needs the canonical-path move
      (engine into `media/com_menus/...`, merged into com_menus's manifest) + explicit
      `addRegistryFile` for the plugin manifests. Also drop the local-only `build/build-customize.mjs`
      for the PR (the standard build compiles `build/media_source`).
- [~] **PHP code standards.** Installing composer dev deps to run `php-cs-fixer` + `phpunit`.

## Likely required by reviewers
- [ ] Dedicated security review of the file-writing handlers (template + language overrides).
- [ ] Cross-origin / postMessage fallback, or document the same-origin-only limitation in the PR.
- [ ] ES modules: maintainers may want `.es6.js` modules instead of the current `.es5.js` IIFEs.
- [ ] Documentation: PR description + manual test steps + user docs.

## Polish
- [ ] Real screen-reader pass (VoiceOver / NVDA), only DOM-verified so far.
- [ ] Document/guard "move a module to a position the template doesn't render" (saves but vanishes
      from the preview; drag has the same behaviour).
- [ ] Self-review of the core-change footprint (HtmlView event, ModulesRenderer events,
      CustomizeMode, Language/Text recordString, SiteApplication, com_menus) to minimise/justify.
