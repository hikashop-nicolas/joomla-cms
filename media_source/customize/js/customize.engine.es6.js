/**
 * Joomla Customize - overlay engine.
 *
 * Runs in the admin Customize host page and drives the same-origin frontend iframe directly:
 * on each iframe load it injects the overlay stylesheet, scans for [data-customize-type] areas,
 * and wires hover -> outline + toolbar (type/name on the left, registered buttons on the right).
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
import JC from 'customize.api';

document.addEventListener('DOMContentLoaded', function () {
    var opts = (window.Joomla && window.Joomla.getOptions) ? (window.Joomla.getOptions('customize') || {}) : {};
    var frame = document.getElementById(opts.frameId || 'customize-frame');

    if (!frame || !JC) {
      return;
    }

    // Size the stage to fill the viewport from its real top, and stop the admin page from scrolling, so
    // the iframe (and the drag-time "move to position" drop bar fixed at its bottom) is always fully
    // visible. Measuring the offset beats a fixed calc(): it adapts to a taller toolbar or message bar.
    var host = document.querySelector('.customize-host');

    if (host) {
      var fitHost = function () {
        host.style.height = Math.max(window.innerHeight - host.getBoundingClientRect().top - 12, 320) + 'px';
      };

      document.documentElement.style.overflow = 'hidden';
      document.body.style.overflow = 'hidden';
      fitHost();
      window.addEventListener('resize', fitHost);
    }

    // Keep the customize token fresh so a long-open editor keeps working past the token's lifetime.
    if (opts.tokenUrl && opts.tokenRefreshMs) {
      window.setInterval(function () {
        // Resolve against the current admin page so the relative URL keeps the subfolder + admin path.
        fetch(new URL(opts.tokenUrl, window.location.href).toString(), { credentials: 'same-origin' })
          .then(function (response) {
            return response.json();
          })
          .then(function (json) {
            if (json && json.data && json.data.token) {
              JC.setFrameToken(json.data.token);
            }
          })
          .catch(function () {});
      }, opts.tokenRefreshMs);
    }

    var toolbar = null;
    var srStatus = null;
    // After a reload (e.g. a cross-position move re-renders the frame), re-select this element so the
    // keyboard user keeps their place. {type, id, message}.
    var pendingSelect = null;
    var current = null;
    var editing = false;
    // The element kept selected (outline + toolbar) while the user interacts with it (after a button
    // click or double-click), so it stays put when the pointer moves away. Cleared by a click outside.
    var pinned = null;
    var dragEl = null;
    var dragType = null;
    var externalDrag = null;
    var removeBar = null;
    var pendingDelete = null;

    function frameDoc() {
      try {
        return frame.contentDocument || frame.contentWindow.document;
      } catch (e) {
        return null;
      }
    }

    function injectFrameCss(doc) {
      if (!opts.frameCss || doc.getElementById('customize-frame-css')) {
        return;
      }

      var link = doc.createElement('link');
      link.id = 'customize-frame-css';
      link.rel = 'stylesheet';
      link.href = opts.frameCss;
      (doc.head || doc.documentElement).appendChild(link);
    }

    function buildChrome(doc) {
      toolbar = doc.createElement('div');
      toolbar.className = 'customize-toolbar';
      toolbar.style.display = 'none';

      // A visually hidden live region so screen readers hear which area was entered.
      srStatus = doc.createElement('div');
      srStatus.className = 'customize-sr-status';
      srStatus.setAttribute('aria-live', 'polite');
      srStatus.setAttribute('aria-atomic', 'true');

      doc.body.appendChild(toolbar);
      doc.body.appendChild(srStatus);
    }

    function hide() {
      if (current) { current.classList.remove('customize-area-active'); }
      current = null;
      if (toolbar) { toolbar.style.display = 'none'; }
      // Leaving the selection closes any overlay that was open for it.
      JC.dismissTransients();
    }

    function dataset(el) {
      var data = {};
      for (var i = 0; i < el.attributes.length; i++) {
        var attr = el.attributes[i];
        if (attr.name.indexOf('data-customize-') === 0) {
          data[attr.name.slice('data-customize-'.length)] = attr.value;
        }
      }
      return data;
    }

    // --- Generic drag-to-reorder ------------------------------------------------
    // An area type marked { draggable: true } is dragged by its toolbar title. Other elements of
    // the same type (and any [data-customize-dropzone="<type>"] zones) become drop targets; the
    // engine repositions the element in the DOM on drop and calls the type's onReorder(info) so the
    // owning plugin can persist the change. This is shared so future draggable things reuse it.

    function highlightDroppables(doc, type, on) {
      Array.prototype.forEach.call(doc.querySelectorAll('[data-customize-type="' + type + '"]'), function (el) {
        if (el !== dragEl) {
          el.classList.toggle('customize-droppable', on);
        }

        if (!on) {
          el.classList.remove('customize-drop-target');
        }
      });

      // Reveal only the drop zones that accept the dragged type (e.g. module zones for a module drag,
      // not for a field drag).
      Array.prototype.forEach.call(doc.querySelectorAll('[data-customize-dropzone="' + type + '"]'), function (z) {
        z.classList.toggle('customize-dropzone-active', on);

        if (!on) {
          z.classList.remove('customize-drop-target');
        }
      });
    }

    // A red "remove" bar shown at the top while dragging an area type whose definition provides an
    // onDelete callback. Dropping the element on it greys the element and shows an inline confirm;
    // confirming calls onDelete({el, doc}) so the owning plugin can delete it. Shared by modules,
    // menu items and any future deletable type.
    function cancelRemove() {
      if (pendingDelete) {
        pendingDelete.classList.remove('customize-pending');
      }
      pendingDelete = null;

      if (removeBar && removeBar.parentNode) {
        removeBar.parentNode.removeChild(removeBar);
      }
      removeBar = null;
    }

    function showRemoveConfirm(doc, bar, areaType) {
      bar.textContent = '';
      bar.classList.add('customize-sticky-form');

      var name = pendingDelete.getAttribute('data-customize-name') || '';
      var label = doc.createElement('span');
      label.textContent = JC.text('COM_MENUS_CUSTOMIZE_REMOVE_CONFIRM', 'Remove %s?').replace('%s', name);

      var del = doc.createElement('button');
      del.type = 'button';
      del.className = 'customize-action customize-action-danger';
      del.textContent = JC.text('COM_MENUS_CUSTOMIZE_REMOVE', 'Remove');

      var cancel = doc.createElement('button');
      cancel.type = 'button';
      cancel.className = 'customize-action customize-action-cancel';
      cancel.textContent = JC.text('COM_MENUS_CUSTOMIZE_CANCEL', 'Cancel');

      bar.appendChild(label);
      bar.appendChild(del);
      bar.appendChild(cancel);

      cancel.addEventListener('click', cancelRemove);

      del.addEventListener('click', function () {
        var target = pendingDelete;
        del.disabled = true;
        del.textContent = JC.text('COM_MENUS_CUSTOMIZE_SAVING', 'Saving…');

        Promise.resolve(areaType.onDelete({ el: target, doc: doc })).then(function (ok) {
          if (ok === false) {
            del.disabled = false;
            del.textContent = JC.text('COM_MENUS_CUSTOMIZE_REMOVE', 'Remove');
          } else {
            // The plugin reloads the iframe on success; just drop our references.
            pendingDelete = null;
            removeBar = null;
          }
        });
      });
    }

    function makeRemoveBar(doc, areaType) {
      var bar = doc.createElement('div');
      bar.className = 'customize-sticky-zone customize-sticky-zone-top customize-sticky-danger';
      bar.textContent = JC.text('COM_MENUS_CUSTOMIZE_REMOVE_HINT', 'Drop here to remove');

      bar.addEventListener('dragover', function (e) {
        if (dragEl) {
          e.preventDefault();
          bar.classList.add('customize-sticky-over');
        }
      });

      bar.addEventListener('dragleave', function () {
        bar.classList.remove('customize-sticky-over');
      });

      bar.addEventListener('drop', function (e) {
        if (!dragEl) {
          return;
        }
        e.preventDefault();
        bar.classList.remove('customize-sticky-over');
        pendingDelete = dragEl;
        pendingDelete.classList.add('customize-pending');
        showRemoveConfirm(doc, bar, areaType);
      });

      doc.body.appendChild(bar);
      return bar;
    }

    // Keyboard equivalent of dragging an element onto the remove bar: show the same inline confirm,
    // move focus to the Remove button, and let Escape cancel (focus returns to the element).
    function keyboardDelete(el, doc) {
      var areaType = JC.getAreaType(el.getAttribute('data-customize-type'));

      if (!areaType || typeof areaType.onDelete !== 'function') {
        return;
      }

      cancelRemove();
      removeBar = makeRemoveBar(doc, areaType);
      pendingDelete = el;
      el.classList.add('customize-pending');
      showRemoveConfirm(doc, removeBar, areaType);

      removeBar.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          e.preventDefault();
          var target = pendingDelete;
          cancelRemove();

          if (target) {
            target.focus();
          }
        }
      });

      var del = removeBar.querySelector('.customize-action-danger');

      if (del) {
        del.focus();
      }
    }

    function makeDragHandle(handle, el, doc) {
      handle.setAttribute('draggable', 'true');
      handle.classList.add('customize-draggable');

      handle.addEventListener('dragstart', function (ev) {
        dragEl = el;
        dragType = el.getAttribute('data-customize-type');
        el.classList.add('customize-dragging');
        highlightDroppables(doc, dragType, true);

        var def = JC.getAreaType(dragType);
        if (def && typeof def.onDelete === 'function') {
          removeBar = makeRemoveBar(doc, def);
        }

        try {
          ev.dataTransfer.effectAllowed = 'move';
          ev.dataTransfer.setData('text/plain', el.getAttribute('data-customize-id') || '');
        } catch (e) {
          // some browsers restrict dataTransfer
        }
        JC.emit('customize:drag-start', { el: el, doc: doc });
      });

      handle.addEventListener('dragend', function () {
        el.classList.remove('customize-dragging');
        highlightDroppables(doc, dragType, false);
        JC.emit('customize:drag-end', { el: el });
        dragEl = null;
        dragType = null;

        // Keep the remove bar only if the element was dropped on it (a confirm is showing).
        if (!pendingDelete && removeBar) {
          if (removeBar.parentNode) {
            removeBar.parentNode.removeChild(removeBar);
          }
          removeBar = null;
        }
      });
    }

    function addDropTarget(doc, target, acceptType) {
      target.addEventListener('dragover', function (e) {
        var reorder = dragEl && dragType === acceptType && dragEl !== target;
        var external = externalDrag && externalDrag.type === acceptType;

        if (reorder || external) {
          e.preventDefault();
          target.classList.add('customize-drop-target');
        }
      });

      target.addEventListener('dragleave', function () {
        target.classList.remove('customize-drop-target');
      });

      target.addEventListener('drop', function (e) {
        target.classList.remove('customize-drop-target');

        // An external source (e.g. the Add-module bar) dropping a brand new element of this type.
        if (externalDrag && externalDrag.type === acceptType) {
          e.preventDefault();
          externalDrag.onDrop({ target: target, payload: externalDrag.payload, doc: doc });
          return;
        }

        if (!dragEl || dragType !== acceptType || dragEl === target) {
          return;
        }

        e.preventDefault();

        if (target.hasAttribute('data-customize-dropzone')) {
          target.parentNode.insertBefore(dragEl, target);
        } else {
          var rect = target.getBoundingClientRect();
          var after = (e.clientY - rect.top) > (rect.height / 2);
          target.parentNode.insertBefore(dragEl, after ? target.nextSibling : target);
        }

        var def = JC.getAreaType(dragType);

        if (def && typeof def.onReorder === 'function') {
          def.onReorder({ dragged: dragEl, target: target, doc: doc, type: dragType });
        }
      });
    }

    // Let a control outside the iframe (e.g. the Add-module bar in the panel) drag a brand new
    // element of `type` onto the page. Highlights the same drop targets as a reorder; onDrop is
    // called with the chosen target so the caller can create the element there.
    JC.beginExternalDrag = function (type, payload, onDrop) {
      externalDrag = { type: type, payload: payload, onDrop: onDrop };
      dragType = type;
      var doc = frameDoc();

      if (doc) {
        highlightDroppables(doc, type, true);
      }
    };

    JC.endExternalDrag = function () {
      var doc = frameDoc();

      if (doc && externalDrag) {
        highlightDroppables(doc, externalDrag.type, false);
      }

      externalDrag = null;
      dragType = null;
    };

    // Ask the engine to put keyboard focus back on this element (matched by type + customize id) once
    // the frame next reloads, optionally announcing a message. Used by plugins that reload after a
    // keyboard action, e.g. moving a module to another position.
    JC.selectAfterReload = function (el, message) {
      if (el && el.getAttribute) {
        pendingSelect = {
          type: el.getAttribute('data-customize-type'),
          id: el.getAttribute('data-customize-id'),
          message: message || ''
        };
      }
    };

    function setupSortable(doc) {
      Array.prototype.forEach.call(doc.querySelectorAll('[data-customize-type], [data-customize-dropzone]'), function (target) {
        var acceptType = target.getAttribute('data-customize-dropzone') || target.getAttribute('data-customize-type');

        if (target.hasAttribute('data-customize-dropzone') || (JC.getAreaType(acceptType) || {}).draggable) {
          addDropTarget(doc, target, acceptType);
        }
      });
    }

    // Position the toolbar at the element's top-left. The element's own outline (a CSS class on it)
    // tracks size changes by itself, so only the floating toolbar needs placing.
    function positionFor(el, doc) {
      var win = doc.defaultView;
      var rect = el.getBoundingClientRect();

      toolbar.style.top = (rect.top + win.scrollY) + 'px';
      toolbar.style.left = (rect.left + win.scrollX) + 'px';
    }

    function showFor(el, doc) {
      // Outline the element via a class on itself; the browser tracks its box, so the outline follows
      // size changes (e.g. an inline editor growing the element) with no scripting.
      if (current && current !== el) {
        current.classList.remove('customize-area-active');
        // Selecting a different element closes any overlay opened for the previous one.
        JC.dismissTransients();
      }

      current = el;
      el.classList.add('customize-area-active');

      var type = el.getAttribute('data-customize-type');
      var name = el.getAttribute('data-customize-name') || '';
      var areaType = JC.getAreaType(type);

      toolbar.innerHTML = '';

      var label = doc.createElement('span');
      label.className = 'customize-toolbar-label';
      label.textContent = (areaType.label || type) + (name ? ' · ' + name : '');

      // A plugin can flag an element (e.g. a view block that already has a template override) to show
      // a small cue after the title.
      var cue = el.getAttribute('data-customize-cue');

      if (cue) {
        var cueEl = doc.createElement('span');
        cueEl.className = 'customize-toolbar-cue';
        cueEl.textContent = cue;
        label.appendChild(cueEl);
      }

      // Draggable area types (e.g. modules) use the toolbar title as the drag handle, leaving the
      // element body free for editing its contents. The owning plugin handles the drop and save.
      if (areaType.draggable) {
        makeDragHandle(label, el, doc);
      }

      var actions = doc.createElement('span');
      actions.className = 'customize-toolbar-actions';

      JC.getButtons(type).forEach(function (button) {
        // A button may require a data flag on the element (e.g. requires:'props' -> data-customize-props).
        if (button.requires && !el.hasAttribute('data-customize-' + button.requires)) {
          return;
        }

        var btn = doc.createElement('button');
        btn.type = 'button';
        btn.className = 'customize-btn';
        btn.textContent = button.label || button.id;
        btn.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();

          // Pin before running the handler so a button can re-select another area (e.g. navigate to a
          // parent or child layout); cleared by a click elsewhere.
          pinned = el;

          // Close any open transient overlay (e.g. the module move picker) before a different action
          // runs, so two never stack. An action that opens its own overlay re-registers it right after.
          JC.dismissTransients();

          if (typeof button.onClick === 'function') {
            button.onClick({
              el: el,
              doc: doc,
              type: type,
              name: name,
              data: dataset(el),
              callAction: JC.callAction,
              emit: JC.emit
            });
          }
        });
        actions.appendChild(btn);
      });

      // Order: action buttons first (left), then the title/drag handle (right), so the buttons are
      // easy to reach even on narrow elements like a short translated string.
      toolbar.appendChild(actions);
      toolbar.appendChild(label);
      toolbar.style.display = 'flex';
      positionFor(el, doc);
    }

    // Programmatically select an area (outline + toolbar), pin it, and move keyboard focus to it, so a
    // toolbar button can navigate to another area, e.g. the view plugin's parent/child layout controls.
    // Without the focus(), Tab after such a jump would restart from the top of the page.
    JC.select = function (el) {
      if (el && el.getAttribute && el.getAttribute('data-customize-type')) {
        if (!el.hasAttribute('tabindex')) {
          el.setAttribute('tabindex', '0');
        }

        showFor(el, el.ownerDocument);
        pinned = el;
        el.focus();
      }
    };

    // Run an element's primary (first applicable) button action, and select it. Shared by
    // double-click and the keyboard Enter/Space activation.
    function triggerPrimary(el, doc) {
      var type = el.getAttribute('data-customize-type');
      var buttons = JC.getButtons(type);
      var primary = null;

      for (var i = 0; i < buttons.length; i++) {
        if (!buttons[i].requires || el.hasAttribute('data-customize-' + buttons[i].requires)) {
          primary = buttons[i];
          break;
        }
      }

      if (!primary || typeof primary.onClick !== 'function') {
        return;
      }

      if (current !== el) {
        showFor(el, doc);
      }

      pinned = el;
      primary.onClick({
        el: el,
        doc: doc,
        type: type,
        name: el.getAttribute('data-customize-name') || '',
        data: dataset(el),
        callAction: JC.callAction,
        emit: JC.emit
      });
    }

    // Carry the customize token across in-iframe navigation (same-origin links and forms), so the
    // mode persists without making it sticky in the session (which would leak into normal browsing).
    function carryCustomize(doc) {
      var host  = doc.location.host;
      var token = JC.getFrameToken();

      if (!token) {
        return;
      }

      Array.prototype.forEach.call(doc.querySelectorAll('a[href]'), function (a) {
        if (a.host !== host) {
          return;
        }

        var href = a.getAttribute('href');

        if (!href || href.charAt(0) === '#' || a.search.indexOf('customize=') !== -1) {
          return;
        }

        a.search = (a.search ? a.search + '&' : '?') + 'customize=' + encodeURIComponent(token);
      });

      Array.prototype.forEach.call(doc.querySelectorAll('form'), function (form) {
        if (form.querySelector('input[name="customize"]')) {
          return;
        }

        var input = doc.createElement('input');
        input.type = 'hidden';
        input.name = 'customize';
        input.value = token;
        form.appendChild(input);
      });
    }

    function wire(doc) {
      buildChrome(doc);
      carryCustomize(doc);

      var areas = doc.querySelectorAll('[data-customize-type]');

      var win = doc.defaultView;
      var hideTimer = null;

      function cancelHide() {
        if (hideTimer) {
          win.clearTimeout(hideTimer);
          hideTimer = null;
        }
      }

      // Hide on a short delay so moving from an element to its toolbar (the drag handle) across a
      // small gap doesn't dismiss it mid-grab.
      function scheduleHide() {
        cancelHide();
        hideTimer = win.setTimeout(hide, 250);
      }

      doc.addEventListener('mouseover', function (event) {
        // Keep the toolbar visible while the pointer is on it.
        if (toolbar && (event.target === toolbar || toolbar.contains(event.target))) {
          cancelHide();
          return;
        }

        // While editing, or with an element pinned/selected, don't follow the hover elsewhere.
        if (editing || pinned) {
          return;
        }

        var el = event.target.closest ? event.target.closest('[data-customize-type]') : null;

        if (el) {
          cancelHide();

          if (el !== current) {
            showFor(el, doc);
          }
        } else {
          scheduleHide();
        }
      });

      // A click outside the selected element (and its toolbar / editor UI) clears the selection.
      // Not while an inline editor is open: the user finishes that with Enter/Escape.
      doc.addEventListener('click', function (event) {
        if (!pinned || editing) {
          return;
        }

        var t = event.target;

        if (pinned.contains(t)
          || (toolbar && toolbar.contains(t))
          || (t.closest && t.closest('.customize-popover, .customize-inline-bar, .customize-sticky-zone'))) {
          return;
        }

        pinned = null;
        var el = t.closest ? t.closest('[data-customize-type]') : null;

        if (el) {
          showFor(el, doc);
        } else {
          hide();
        }
      });

      // Double-click an element to trigger its primary action (the first applicable button).
      doc.addEventListener('dblclick', function (event) {
        if (editing) {
          return;
        }

        var el = event.target.closest ? event.target.closest('[data-customize-type]') : null;

        if (el) {
          event.preventDefault();
          triggerPrimary(el, doc);
        }
      });

      JC.emit('customize:frame-ready', { doc: doc, areas: areas.length });

      // After plugins have tagged their areas/zones: wire keyboard access and drag-to-reorder.
      setupKeyboard(doc);
      setupSortable(doc);
    }

    // Keyboard access: make the areas reachable and operable without a mouse. Every area is a Tab stop,
    // so Tab walks through them in document order; focusing an area selects it (parity with hover);
    // Enter/Space runs its primary action; Tab moves into the toolbar and on to the next area; Escape
    // deselects; Ctrl+arrows move/reorder a block and Delete removes it. The area set is read live
    // (plugins tag some areas after load) and each area is made focusable lazily, so late-added areas
    // are reachable too.
    function setupKeyboard(doc) {
      // A human label for an area: its type label plus the item name, e.g. "Layout block item".
      function describe(el) {
        var type = el.getAttribute('data-customize-type');
        var name = el.getAttribute('data-customize-name') || '';
        var areaType = JC.getAreaType(type) || {};
        return (areaType.label || type) + (name ? ' ' + name : '');
      }

      function isDraggable(el) {
        return !!((JC.getAreaType(el.getAttribute('data-customize-type')) || {}).draggable);
      }

      function isMovable(el) {
        return typeof (JC.getAreaType(el.getAttribute('data-customize-type')) || {}).onMove === 'function';
      }

      function isDeletable(el) {
        return typeof (JC.getAreaType(el.getAttribute('data-customize-type')) || {}).onDelete === 'function';
      }

      function ensureArea(el) {
        // Every area is a Tab stop so a keyboard user can Tab through them in document order.
        if (!el.hasAttribute('tabindex')) {
          el.setAttribute('tabindex', '0');
        }

        // Announce each area as a named, activatable "Customize area" (role=group is valid when areas
        // nest, e.g. a layout block containing editable text).
        if (!el.getAttribute('role')) {
          el.setAttribute('role', 'group');
        }

        if (!el.getAttribute('aria-roledescription')) {
          el.setAttribute('aria-roledescription', JC.text('COM_MENUS_CUSTOMIZE_AREA_ROLEDESCRIPTION', 'Customize area'));
        }

        if (!el.getAttribute('aria-keyshortcuts')) {
          // Draggable areas can be reordered/moved and deletable ones removed from the keyboard, as the
          // alternative to drag-and-drop.
          var shortcuts = ['Enter'];

          if (isDraggable(el)) {
            shortcuts.push('Control+ArrowUp', 'Control+ArrowDown');
          }

          if (isMovable(el)) {
            shortcuts.push('Control+ArrowLeft', 'Control+ArrowRight');
          }

          if (isDeletable(el)) {
            shortcuts.push('Delete');
          }

          el.setAttribute('aria-keyshortcuts', shortcuts.join(' '));
        }

        if (!el.getAttribute('aria-label')) {
          el.setAttribute('aria-label', describe(el));
        }
      }

      // Tell screen readers which area was entered (and how to edit/move/remove it) via the live region.
      function announce(el) {
        if (!srStatus) {
          return;
        }

        var msg = describe(el) + '. ' + JC.text('COM_MENUS_CUSTOMIZE_AREA_HINT', 'Press Enter to edit');

        if (isDraggable(el)) {
          msg += '. ' + JC.text('COM_MENUS_CUSTOMIZE_AREA_MOVE_HINT', 'Use Ctrl with the arrow keys to move it');
        }

        if (isDeletable(el)) {
          msg += '. ' + JC.text('COM_MENUS_CUSTOMIZE_AREA_DELETE_HINT', 'Press Delete to remove it');
        }

        if (srStatus.textContent !== msg) {
          srStatus.textContent = msg;
        }
      }

      // Move a draggable block among its same-type peers in the same container (keyboard equivalent of
      // drag-and-drop), persist via the type's onReorder, keep focus on it, and announce the new spot.
      function moveBlock(el, dir) {
        var type = el.getAttribute('data-customize-type');
        var position = el.getAttribute('data-customize-position');
        var parent = el.parentNode;

        function peers() {
          return Array.prototype.filter.call(parent.children, function (c) {
            if (!c.getAttribute || c.getAttribute('data-customize-type') !== type) {
              return false;
            }

            return !position || c.getAttribute('data-customize-position') === position;
          });
        }

        var siblings = peers();
        var swap = siblings[siblings.indexOf(el) + dir];

        if (!swap) {
          return;
        }

        parent.insertBefore(el, dir < 0 ? swap : swap.nextSibling);

        var def = JC.getAreaType(type);

        if (def && typeof def.onReorder === 'function') {
          def.onReorder({ dragged: el, target: el, doc: doc, type: type });
        }

        el.focus();
        showFor(el, doc);

        if (srStatus) {
          var now = peers();
          var tmpl = JC.text('COM_MENUS_CUSTOMIZE_AREA_MOVED', 'Moved to position %1$s of %2$s');
          srStatus.textContent = describe(el) + '. ' + tmpl.replace('%1$s', now.indexOf(el) + 1).replace('%2$s', now.length);
        }
      }

      function areaList() {
        return Array.prototype.slice.call(doc.querySelectorAll('[data-customize-type]'));
      }

      // Move to and select an area (explicitly, not relying on focusin, which some hosts don't fire on
      // a programmatic focus()).
      function select(el) {
        ensureArea(el);
        el.focus();
        showFor(el, doc);
        pinned = el;
        announce(el);
      }

      areaList().forEach(ensureArea);

      // Focusing an area (Tab or click) selects it and keeps it selected so the toolbar is reachable.
      doc.addEventListener('focusin', function (event) {
        if (editing) {
          return;
        }

        var el = event.target.closest ? event.target.closest('[data-customize-type]') : null;

        if (el) {
          ensureArea(el);
          showFor(el, doc);
          pinned = el;
          announce(el);
        }
      });

      // Clear the selection when focus leaves the areas and the toolbar entirely (e.g. tabbing out).
      doc.addEventListener('focusout', function (event) {
        if (editing) {
          return;
        }

        var to = event.relatedTarget;
        var inArea = to && to.closest && to.closest('[data-customize-type]');
        var inToolbar = to && toolbar && toolbar.contains(to);
        // Focus moving into a customize popover (e.g. the Children menu, properties) is still "inside".
        var inPopover = to && to.closest && to.closest('.customize-popover, .customize-inline-bar, .customize-sticky-zone');

        if (!inArea && !inToolbar && !inPopover && pinned) {
          pinned = null;
          hide();
        }
      });

      doc.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          // An open editor cancels itself on Escape; only deselect when focus is on a bare area/toolbar.
          if (editing) {
            return;
          }

          if (toolbar && toolbar.contains(event.target) && current) {
            event.preventDefault();
            current.focus();
          } else {
            var area = event.target.closest && event.target.closest('[data-customize-type]');

            if (area && event.target === area) {
              pinned = null;
              hide();
              area.blur();
            }
          }

          return;
        }

        // Within the toolbar, Tab past the last action moves on to the next area and Shift+Tab before
        // the first returns to the area, so the keyboard flow is: area, its actions, next area.
        if (toolbar && toolbar.contains(event.target) && event.key === 'Tab') {
          var tbBtns = Array.prototype.slice.call(toolbar.querySelectorAll('.customize-btn'));
          var tbPos = tbBtns.indexOf(event.target);

          if (!event.shiftKey && tbPos === tbBtns.length - 1 && pinned) {
            var after = areaList();
            var nextArea = after[after.indexOf(pinned) + 1];

            if (nextArea) {
              event.preventDefault();
              select(nextArea);
            }
          } else if (event.shiftKey && tbPos === 0 && pinned) {
            event.preventDefault();
            pinned.focus();
          }

          return;
        }

        // Navigation/activation only when focus is on the area element itself (not an editor inside it).
        var el = event.target.closest ? event.target.closest('[data-customize-type]') : null;

        if (!el || event.target !== el || editing) {
          return;
        }

        // Ctrl+Up/Down reorders a draggable block; Ctrl+Left/Right sends it to another position (the
        // keyboard alternative to drag-and-drop).
        if (event.ctrlKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
          if (isDraggable(el)) {
            event.preventDefault();
            moveBlock(el, event.key === 'ArrowUp' ? -1 : 1);
          }

          return;
        }

        if (event.ctrlKey && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
          var areaDef = JC.getAreaType(el.getAttribute('data-customize-type'));

          if (areaDef && typeof areaDef.onMove === 'function') {
            event.preventDefault();
            areaDef.onMove({ el: el, doc: doc, dir: event.key === 'ArrowLeft' ? -1 : 1 });
          }

          return;
        }

        if (event.ctrlKey) {
          return;
        }

        // Delete removes a deletable block (after the same confirm as the drag remove bar).
        if (event.key === 'Delete' && isDeletable(el)) {
          event.preventDefault();
          keyboardDelete(el, doc);
          return;
        }

        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          triggerPrimary(el, doc);
        } else if (event.key === 'Tab' && !event.shiftKey) {
          // Tab from an area moves into its toolbar so the actions are reachable.
          var firstBtn = toolbar && toolbar.querySelector('.customize-btn');

          if (firstBtn) {
            event.preventDefault();
            firstBtn.focus();
          }
        }
      });

      // If a plugin asked to keep the user's place across this reload, re-select that element now.
      if (pendingSelect) {
        var want = pendingSelect;
        pendingSelect = null;
        var match = null;

        Array.prototype.forEach.call(doc.querySelectorAll('[data-customize-type]'), function (a) {
          if (!match && a.getAttribute('data-customize-type') === want.type && a.getAttribute('data-customize-id') === want.id) {
            match = a;
          }
        });

        if (match) {
          select(match);

          if (want.message && srStatus) {
            srStatus.textContent = describe(match) + '. ' + want.message;
          }
        }
      }
    }

    frame.addEventListener('load', function () {
      toolbar = null;
      srStatus = null;
      current = null;
      // Any in-progress edit/selection is gone with the old document; clear the flags so hover works
      // again (e.g. when a plugin reloads the frame to apply a save).
      editing = false;
      pinned = null;

      var doc = frameDoc();

      if (!doc) {
        // The browser blocks reading the iframe when the frontend is on a different origin from the
        // administrator (uncommon). Customize edits through direct same-origin DOM access, so such
        // setups are not editable; bail rather than instrument an unreadable frame.
        return;
      }

      var active = doc.querySelector('meta[name="customize-mode"]')
        || (frame.contentWindow && frame.contentWindow.JoomlaCustomizeFrame);

      if (!active) {
        return;
      }

      injectFrameCss(doc);
      wire(doc);
    });

    // While an inline editor is open, suppress hover-following but keep the element's outline + bar
    // (it stays selected). The selection is cleared by a click elsewhere.
    JC.on('customize:edit-start', function () {
      editing = true;
    });

    JC.on('customize:edit-end', function () {
      editing = false;

      // Return focus to the area that was being edited, so a keyboard user is not dropped to the top
      // of the page when the editor closes (focusin re-selects it and re-shows the toolbar).
      if (pinned && pinned.isConnected) {
        pinned.focus();
      }
    });
  });
