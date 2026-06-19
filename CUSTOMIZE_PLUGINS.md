# Writing a Customize-mode plugin

This guide explains how to build a plugin in the `customize` plugin group, so a component (or a
third-party extension such as a shop) can make its own front-end output editable in Customize mode.

See also: [CUSTOMIZE_DEMO.md](CUSTOMIZE_DEMO.md) (overview + try it) and
[CUSTOMIZE_TEMPLATES.md](CUSTOMIZE_TEMPLATES.md) (making a *template* support layout editing).

## The one rule

In Customize mode, core only fires generic, mutable events and uses the strings they return. A
`customize` plugin owns everything else: the markup it injects, the editing UI, and the persistence. So
a plugin has three sides:

1. **Instrument the front-end** - add `data-customize-*` attributes to your output (only when the mode
   is active), so the engine can find and label editable areas.
2. **Provide the admin UI** - on the Customize host page, register your area types and toolbar buttons
   and build the editing affordances.
3. **Persist** - a save posts back to your server handler, which authorises it and writes through the
   native Joomla mechanism.

The six shipped plugins (`content`, `module`, `position`, `view`, `language`, `layout`) are working
references; mirror the one closest to your case.

## Anatomy

```
plugins/customize/<name>/
  <name>.xml                       extension manifest (group="customize")
  services/provider.php            DI registration
  src/Extension/<Name>.php         the PHP plugin (events + AJAX handler)
  language/en-GB/plg_customize_<name>.ini
media_source/plg_customize_<name>/
  js/admin.es6.js                  the admin-side editing JS (ES module)
  joomla.asset.json                registers the admin script (importmap consumer)
```

Build the JS with the standard pipeline (`npm run build` / `node build/build-customize.mjs`); locally,
`./deploy-customize.sh <site>` builds + syncs.

## 1. Instrument the front-end (PHP)

Most extensions render their output through `HtmlView`, so the event you are most likely to hook is
`onCustomizeRenderView`. Core fires it for every view sub-layout, of **any** component (it resolves the
layout through the view's own registered template paths, so it works for the `tmpl/<view>/` convention
*and* for layouts under `views/<view>/tmpl/`), and uses the string you return. Subscribe to the event
your content flows through (your plugin's `getSubscribedEvents()` maps it to a handler):

| Event | Fired by | Carries |
|---|---|---|
| `onCustomizeRenderView` | `HtmlView::loadTemplate`, per view sub-layout, any component | `output`, `block`, `component`, `view`, `layout`, `file` |
| `onCustomizeModule` | `ModulesRenderer` | `module`, `position`, `output` |
| `onCustomizeEmptyPosition` | `ModulesRenderer` | `position` (subject), `content` |
| `onContentPrepare` | content rendering (fires always) | the item |
| (string collector) | `Text::_` / `Text::sprintf` via `CustomizeMode::recordString()` | key + text |

The dedicated customize events (the first three) fire **only in customize mode**, so their handlers
need no guard. A general event you reuse, like `onContentPrepare`, fires always, so guard it with
`if (!CustomizeMode::isActive()) { return; }`.

Your handler mutates the event's output string to inject the contract. The shipped `view` plugin
already handles `onCustomizeRenderView` for **every** component's views, so any view layout is
overridable in customize mode with no work from you; subscribe to it from your own plugin only to add
component-specific affordances, and gate on your component:

```php
public function onCustomizeRenderView(GenericEvent $event): void
{
    if ($event->getArgument('component') !== 'com_example') {
        return; // only our own views
    }

    $output = (string) $event->getArgument('output', '');
    $block  = (string) $event->getArgument('block', '');

    if ($output === '' || $block === '') {
        return;
    }

    // Comment markers carry the block's identity; your admin JS turns the pair into an editable area.
    // (A sub-layout's output is not always a single element, so markers are safer than a wrapper div.)
    $meta = 'component=com_example;view=' . $event->getArgument('view') . ';block=' . $block;
    $event->setArgument('output', '<!--customize-block-start:' . $meta . '-->' . $output . '<!--customize-block-end-->');
}
```

When the markup *is* a single element you can inject `data-customize-*` attributes directly instead, as
the module plugin does on a module's first tag and the position plugin does on an empty-position slot.
If your render point has no event yet, that is a small core change (fire a `GenericEvent` and use the
returned string); keep the markup in the plugin, not in core.

### Instrumenting a view you own

When you own the view, the simplest path is to emit the contract straight from your layout, gated on
`CustomizeMode::isActive()` so visitors get untouched markup. This gives finer control than wrapping a
whole sub-layout, e.g. to make a list of items drag-reorderable: mark the container as a grid and each
item as a draggable area.

```php
<?php // components/com_example/tmpl/items/default.php
use Joomla\CMS\Customize\CustomizeMode;
\defined('_JEXEC') or die;

$customize = CustomizeMode::isActive();
?>
<div class="example-items"<?php echo $customize ? ' data-customize-grid="example-items"' : ''; ?>>
<?php foreach ($this->items as $item) : ?>
    <div class="example-item"<?php echo $customize
        ? ' data-customize-type="example-item"'
          . ' data-customize-id="' . (int) $item->id . '"'
          . ' data-customize-name="' . htmlspecialchars($item->title, ENT_QUOTES) . '"'
        : ''; ?>>
        <?php echo $item->body; ?>
    </div>
<?php endforeach; ?>
</div>
```

In customize mode each `.example-item` is now a selectable area, and because the container is a grid,
swapping two items is drag-and-drop. You supply the behaviour in your plugin's admin JS (step 3): a
`registerAreaType('example-item', { draggable: true, onReorder })` whose `onReorder` reads the new
order from the grid and posts it to your save handler (step 4). The engine handles the drag, drop and
keyboard reorder; you only persist.

### The data-customize-* contract

| Attribute | Meaning |
|---|---|
| `data-customize-type="<type>"` | marks an editable area; the type drives the toolbar + keyboard |
| `data-customize-id="<id>"` | the record id (re-selection after a reload) |
| `data-customize-name="<label>"` | label shown in the toolbar |
| `data-customize-<flag>` | a capability flag a button can require (e.g. `requires: 'props'`) |
| `data-customize-dropzone="<type>"` | a drop target for that draggable type |
| `data-customize-cue="<text>"` | a small badge after the toolbar title |

## 2. Provide the admin UI

Subscribe to `onCustomizeAdminInit` (fired on the Customize host) and load your admin script + strings,
gated on the permission your saves need:

```php
public function onCustomizeAdminInit(): void
{
    if (!$this->getApplication()->getIdentity()->authorise('core.edit', 'com_content')) {
        return;
    }

    $this->loadLanguage();
    $wa = $this->getApplication()->getDocument()->getWebAssetManager();
    $wa->getRegistry()->addExtensionRegistryFile('plg_customize_<name>');
    $wa->useScript('plg_customize_<name>.admin');

    foreach (['PLG_CUSTOMIZE_<NAME>_EDIT', ...] as $key) {
        Text::script($key);
    }
}
```

## 3. The admin JS (engine API)

The admin script is an ES module that imports the shared API and registers types/buttons. The engine
then wires hover/select, a floating toolbar, keyboard access (Tab, Enter, Ctrl+arrows, Delete) and
drag-to-reorder for every `[data-customize-type]` you declared.

```js
import JC from 'customize.api';

const t = (key, fallback) => JC.text(key, fallback);

// An area type. draggable -> the engine drags it by its toolbar handle and calls onReorder; onMove
// handles Ctrl+Left/Right; onDelete adds the remove bar. sameParentOnly keeps a drag within one group.
JC.registerAreaType('myarea', {
  label: t('PLG_CUSTOMIZE_MYPLUGIN_AREA', 'My area'),
  draggable: true,
  onReorder(info) { /* persist the new order */ },
  onDelete(info) { return JC.callAction('myplugin', 'delete', { id: info.el.getAttribute('data-customize-id') }); },
});

// A toolbar button. requires:'foo' shows it only on elements with data-customize-foo; primary:true
// makes it the Enter/double-click action.
JC.registerButton('myarea', {
  id: 'edit', label: t('PLG_CUSTOMIZE_MYPLUGIN_EDIT', 'Edit'), order: 10, primary: true,
  onClick(ctx) { /* ctx = { el, doc, type, name, data, callAction, emit } */ },
});
```

Useful API (all on the `JC` import, also `window.JoomlaCustomize`):

- `callAction(plugin, action, payload)` -> Promise of the parsed result; posts to your `onAjax<Name>`.
- `templateAction(action, payload)` -> the same for `com_templates` (template-scoped actions).
- `ui.popover(doc, { anchor, placement, ariaLabel, content, onSave })`, `ui.toast(doc, msg)`,
  `ui.makeBar/saving/resetSave`, `ui.label`.
- `editInline(ctx, { html, save })` - in-place TinyMCE; `openEditModal({ url })` - a native edit dialog.
- `panel()` - the host side-panel, for controls not tied to an on-page element (e.g. "Add module").
- `beginExternalDrag(type, payload, onDrop)` / `endExternalDrag` - drag a brand-new element onto the page.
- `reloadFrame()`, `selectAfterReload(el, msg)`, `select(el)`, `makeDragHandle(handle, el, doc)`.
- `on(name, cb)` / `emit(name, detail)` - e.g. `customize:frame-ready` (the engine finished a load).

Both `callAction` and `templateAction` detect an expired admin session and send the editor to log in.

## 4. The server handler

Register `onAjax<Name>` (com_ajax dispatches `group=customize&plugin=<name>` to it). Verify the token
and the per-item permission, persist natively, return JSON:

```php
public function onAjaxMyplugin(AjaxEvent $event): void
{
    if (!Session::checkToken('post')) {
        // authExpired lets the host tell the editor to log in again instead of failing silently.
        $event->addResult(json_encode(['success' => false, 'authExpired' => true, 'message' => Text::_('JINVALID_TOKEN')]));
        return;
    }

    $payload = json_decode($this->getApplication()->getInput()->get('payload', '', 'raw'), true) ?: [];

    switch ($this->getApplication()->getInput()->getCmd('action', '')) {
        case 'save':
            // ... authorise the specific record, save through the model/table ...
            $event->addResult(json_encode(['success' => true, 'id' => $id]));
            break;
        default:
            $event->addResult(json_encode(['success' => false, 'message' => Text::_('...')]));
    }
}
```

Return `success: true` (+ any data) on success; the JS updates the page in place or calls
`reloadFrame()`. Always re-authorise server-side: the token only gates that an editor is present, never
*what* they may change.

## Checklist

- Markup injected only when `CustomizeMode::isActive()`.
- Admin JS + strings loaded on `onCustomizeAdminInit`, gated on permission.
- Every save re-checks `Session::checkToken('post')` + the per-item permission.
- All JS strings via `JC.text(key, fallback)` (registered with `Text::script`); styling via CSS classes.
- `@since __DEPLOY_VERSION__` docblocks; `_JEXEC` guard; bound DB queries.
