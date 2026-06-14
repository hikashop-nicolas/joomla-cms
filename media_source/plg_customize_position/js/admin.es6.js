/**
 * Customize - Position plugin (admin side).
 *
 * Loaded in the com_menus Customize host page. Modules are marked server-side (ModulesRenderer) and
 * dragged by their toolbar title (the engine provides the generic drag mechanism). Dropping a module
 * onto another module (or an empty-position drop zone) reorders/moves it in place. Dropping it onto
 * the sticky "move to another position" bar greys the module and shows a position picker, so any
 * template position (including ones not rendered on the page) is reachable.
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
import JC from 'customize.api';

  function t(key, fallback) {
    return JC.text(key, fallback);
  }

  function reloadFrame() {
    var f = window.document.getElementById('customize-frame');

    if (f) {
      try {
        f.contentWindow.location.reload();
      } catch (e) {
        f.src = f.src;
      }
    }
  }

  function moduleSelector(position) {
    return '[data-customize-type="module"][data-customize-position="' + (window.CSS ? window.CSS.escape(position) : position) + '"]';
  }

  // --- In-place reorder (drop onto a module / empty-position zone) -------------

  function saveOrder(doc, position) {
    var ids = Array.prototype.map.call(doc.querySelectorAll(moduleSelector(position)), function (m) {
      return m.getAttribute('data-customize-id');
    });

    JC.callAction('position', 'reorder', { position: position, order: ids }).then(function (res) {
      if (res && res.success) {
        JC.ui.toast(doc, t('PLG_CUSTOMIZE_POSITION_SAVED', 'Order saved.'));
      } else {
        var reason = (res && res.message) || t('PLG_CUSTOMIZE_POSITION_UNKNOWN_ERROR', 'unknown error');
        JC.ui.toast(doc, t('PLG_CUSTOMIZE_POSITION_SAVE_FAILED', 'Save failed: %s').replace('%s', reason));
      }
    }).catch(function () {
      JC.ui.toast(doc, t('PLG_CUSTOMIZE_POSITION_SAVE_ERROR', 'Save error.'));
    });
  }

  // --- Move a module to another position / order -----------------------------
  // The picker (position + order dropdowns) is a popover anchored to the module itself, opened by the
  // toolbar Move button, the keyboard Ctrl+arrows, or by dropping the module on the drag-time bottom
  // drop zone. (The red "remove" bar is provided generically by the engine via onDelete below.)

  var draggedModule = null;
  var moveBar = null;
  var pending = null;
  var movePop = null;       // the open move popover ({ close }), or null
  var offLoading = null;    // transient covering the positions fetch, before the popover exists

  function isModuleEl(el) {
    return el && el.getAttribute('data-customize-type') === 'module';
  }

  function removeMoveBar() {
    if (moveBar && moveBar.parentNode) {
      moveBar.parentNode.removeChild(moveBar);
    }
    moveBar = null;
  }

  function ungrey() {
    if (pending) {
      pending.classList.remove('customize-pending');
    }
    pending = null;
  }

  // Abort a move in progress: close the picker (its onClose un-greys) or just un-grey, and drop the
  // loading guard and drag bar. Used before starting a new move; the engine handles dismissal itself.
  function cancelMove() {
    if (offLoading) {
      offLoading();
      offLoading = null;
    }
    removeMoveBar();

    if (movePop) {
      movePop.close();
    } else {
      ungrey();
    }
  }

  function openSelector(doc, dirHint) {
    // Cover the async gap: if another action starts before the picker is built, drop the pending move
    // (the handler below then bails because pending is cleared).
    if (offLoading) {
      offLoading();
    }
    offLoading = JC.registerTransient(function () { offLoading = null; ungrey(); });

    JC.callAction('position', 'positions', {}).then(function (res) {
      if (offLoading) {
        offLoading();
        offLoading = null;
      }

      if (!pending) {
        return;
      }

      if (!res || !res.success) {
        JC.ui.toast(doc, t('PLG_CUSTOMIZE_POSITION_LOAD_FAILED', 'Could not load positions.'));
        ungrey();
        return;
      }

      var currentPos = pending.getAttribute('data-customize-position');
      var movedId = pending.getAttribute('data-customize-id');

      // Modules on the page in a position, in render order, excluding the one being moved.
      function modulesIn(pos) {
        return Array.prototype.filter.call(doc.querySelectorAll('[data-customize-type="module"]'), function (m) {
          return m !== pending && m.getAttribute('data-customize-position') === pos;
        });
      }

      // The module's current slot in its own position, so "no change" is the default order.
      var currentIndex = Array.prototype.filter.call(doc.querySelectorAll('[data-customize-type="module"]'), function (m) {
        return m.getAttribute('data-customize-position') === currentPos;
      }).indexOf(pending);

      var posSel = doc.createElement('select');
      (res.positions || []).forEach(function (p) {
        var o = doc.createElement('option');
        o.value = p;
        o.textContent = p;

        if (p === currentPos) {
          o.selected = true;
        }

        posSel.appendChild(o);
      });

      var ordSel = doc.createElement('select');

      // (Re)build the order options for the chosen position: "At the top", then "After <module>".
      function fillOrder() {
        ordSel.textContent = '';

        var mods = modulesIn(posSel.value);
        var top = doc.createElement('option');
        top.value = '0';
        top.textContent = t('PLG_CUSTOMIZE_POSITION_ORDER_TOP', 'At the top');
        ordSel.appendChild(top);

        mods.forEach(function (m, i) {
          var o = doc.createElement('option');
          o.value = String(i + 1);
          o.textContent = t('PLG_CUSTOMIZE_POSITION_ORDER_AFTER', 'After %s')
            .replace('%s', m.getAttribute('data-customize-name') || m.getAttribute('data-customize-id') || '');
          ordSel.appendChild(o);
        });

        ordSel.value = posSel.value === currentPos ? String(currentIndex) : String(mods.length);
      }

      fillOrder();
      posSel.addEventListener('change', fillOrder);

      // The picker is just a form popover anchored to the module: the shared helper handles placement,
      // the Save/Cancel bar, Enter/Escape and auto-dismissal; the move-specific bits go in the hooks.
      var pop = JC.ui.popover(doc, {
        anchor: pending,
        className: 'customize-move-pop',
        saveOnEnter: true,
        content: [
          JC.ui.label(doc, t('PLG_CUSTOMIZE_POSITION_LABEL', 'Position')),
          posSel,
          JC.ui.label(doc, t('PLG_CUSTOMIZE_POSITION_ORDER', 'Order')),
          ordSel
        ],
        onClose: function (reason) {
          movePop = null;
          var module = pending;
          ungrey();

          // Cancel/Escape returns the keyboard user to the module; an engine dismiss does not.
          if (reason === 'user' && module && module.focus) {
            module.focus();
          }
        },
        onSave: function (api) {
          JC.ui.saving(api.bar.save);

          // Insert the moved module into the target position's list at the chosen slot, then persist the
          // whole list (reorder sets each module's position + ordering, so this handles both at once).
          var pos = posSel.value;
          var ids = modulesIn(pos).map(function (m) { return m.getAttribute('data-customize-id'); });
          ids.splice(parseInt(ordSel.value, 10) || 0, 0, movedId);

          JC.callAction('position', 'reorder', { position: pos, order: ids }).then(function (r) {
            if (r && r.success) {
              // Keep the keyboard user on the module once the frame re-renders in its new spot.
              if (JC.selectAfterReload) {
                JC.selectAfterReload(pending, t('COM_MENUS_CUSTOMIZE_AREA_MOVED_TO', 'Moved to %s').replace('%s', pos));
              }

              pending = null;   // the frame reloads, so there is nothing to un-grey or refocus
              api.close();
              reloadFrame();
            } else {
              JC.ui.resetSave(api.bar.save);
              var reason = (r && r.message) || t('PLG_CUSTOMIZE_POSITION_UNKNOWN_ERROR', 'unknown error');
              JC.ui.toast(doc, t('PLG_CUSTOMIZE_POSITION_SAVE_FAILED', 'Save failed: %s').replace('%s', reason));
            }
          }).catch(function () {
            JC.ui.resetSave(api.bar.save);
            JC.ui.toast(doc, t('PLG_CUSTOMIZE_POSITION_SAVE_ERROR', 'Save error.'));
          });
        }
      });

      movePop = pop;

      // Ctrl+Left/Right opened this: pre-step the position, then focus the picker.
      if (dirHint) {
        var count = posSel.options.length;

        if (count) {
          posSel.selectedIndex = (posSel.selectedIndex + dirHint + count) % count;
          fillOrder();
        }
      }

      posSel.focus();
    });
  }

  function createMoveBar(doc) {
    if (moveBar) {
      return;
    }

    moveBar = doc.createElement('div');
    moveBar.className = 'customize-sticky-zone';
    moveBar.textContent = t('PLG_CUSTOMIZE_POSITION_DROP_HINT', 'Drop here to move to another position');

    moveBar.addEventListener('dragover', function (e) {
      if (draggedModule) {
        e.preventDefault();
        moveBar.classList.add('customize-sticky-over');
      }
    });

    moveBar.addEventListener('dragleave', function () {
      moveBar.classList.remove('customize-sticky-over');
    });

    moveBar.addEventListener('drop', function (e) {
      if (!draggedModule) {
        return;
      }

      e.preventDefault();
      pending = draggedModule;
      pending.classList.add('customize-pending');
      removeMoveBar();
      openSelector(doc);
    });

    doc.body.appendChild(moveBar);
  }

  JC.on('customize:drag-start', function (e) {
    draggedModule = (e.detail && e.detail.el) || null;

    if (draggedModule) {
      draggedModule.classList.remove('customize-new');
    }

    if (isModuleEl(draggedModule) && e.detail && e.detail.doc) {
      createMoveBar(e.detail.doc);
    }
  });

  JC.on('customize:drag-end', function () {
    draggedModule = null;

    // Keep the move bar only if the module was dropped on it (a position is being chosen).
    if (!pending) {
      removeMoveBar();
    }
  });

  // --- Add module (panel control, in the admin parent) ------------------------
  // Pick a type + title; the module is created in a default position and shown highlighted and
  // draggable, so the position is chosen by dragging it (like any other module).

  var newModuleId = null;

  function openAddForm(doc, panel, btn) {
    btn.classList.add('customize-hidden');

    var form = doc.createElement('div');
    form.className = 'customize-add-form';

    // Draggable bar at the top: grab it and drop it on the page to place (and create) the module.
    var bar = doc.createElement('div');
    bar.className = 'customize-add-bar';
    bar.setAttribute('draggable', 'true');

    var typeLabel = doc.createElement('label');
    typeLabel.textContent = t('PLG_CUSTOMIZE_POSITION_ADD_TYPE', 'Module type');
    var typeSel = doc.createElement('select');
    typeSel.className = 'form-select form-select-sm';

    var titleLabel = doc.createElement('label');
    titleLabel.textContent = t('PLG_CUSTOMIZE_POSITION_ADD_TITLE', 'Title');
    var titleInput = doc.createElement('input');
    titleInput.type = 'text';
    titleInput.className = 'form-control form-control-sm';

    var hint = doc.createElement('div');
    hint.className = 'customize-add-hint';
    hint.textContent = t('PLG_CUSTOMIZE_POSITION_ADD_HINT', 'Set a type and title, then drag the bar onto the page.');

    var msg = doc.createElement('div');
    msg.className = 'customize-status is-warn customize-hidden';

    var cancel = doc.createElement('button');
    cancel.type = 'button';
    cancel.className = 'btn btn-secondary btn-sm';
    cancel.textContent = t('COM_MENUS_CUSTOMIZE_CANCEL', 'Cancel');

    form.appendChild(bar);
    form.appendChild(typeLabel);
    form.appendChild(typeSel);
    form.appendChild(titleLabel);
    form.appendChild(titleInput);
    form.appendChild(hint);
    form.appendChild(msg);
    form.appendChild(cancel);
    panel.appendChild(form);

    function refreshBar() {
      var title = titleInput.value.trim();
      bar.textContent = '☰ ' + (title || t('PLG_CUSTOMIZE_POSITION_ADD_BAR', 'New module'));
    }
    refreshBar();
    titleInput.addEventListener('input', refreshBar);

    JC.callAction('position', 'moduletypes', {}).then(function (res) {
      (res && res.types || []).forEach(function (tp) {
        var o = doc.createElement('option');
        o.value = tp.element;
        o.textContent = tp.name;
        typeSel.appendChild(o);
      });
    });

    function close() {
      form.remove();
      btn.classList.remove('customize-hidden');
    }

    function fail() {
      msg.textContent = t('PLG_CUSTOMIZE_POSITION_ADD_FAILED', 'Could not add the module.');
      msg.classList.remove('customize-hidden');
    }

    cancel.addEventListener('click', close);

    bar.addEventListener('dragstart', function (e) {
      var title = titleInput.value.trim();

      if (!title || !typeSel.value) {
        e.preventDefault();
        msg.textContent = t('PLG_CUSTOMIZE_POSITION_ADD_NEEDINFO', 'Choose a type and enter a title first.');
        msg.classList.remove('customize-hidden');
        return;
      }

      msg.classList.add('customize-hidden');

      try {
        e.dataTransfer.effectAllowed = 'copy';
        e.dataTransfer.setData('text/plain', title);
      } catch (err) {
        // some browsers restrict dataTransfer
      }

      bar.classList.add('is-dragging');

      JC.beginExternalDrag('module', { module: typeSel.value, title: title }, function (info) {
        var position = info.target.getAttribute('data-customize-position') || info.target.getAttribute('data-customize-droppos');

        if (!position) {
          return;
        }

        JC.callAction('position', 'add', { title: info.payload.title, module: info.payload.module, position: position }).then(function (res) {
          if (res && res.success) {
            newModuleId = res.id;
            close();
            reloadFrame();
          } else {
            fail();
          }
        }).catch(fail);
      });
    });

    bar.addEventListener('dragend', function () {
      bar.classList.remove('is-dragging');
      JC.endExternalDrag();
    });

    titleInput.focus();
  }

  // After the iframe reloads, highlight the freshly created module and pop its toolbar so it can
  // be grabbed and dragged into place.
  JC.on('customize:frame-ready', function (e) {
    var doc = e.detail && e.detail.doc;

    if (!doc || !newModuleId) {
      return;
    }

    var m = doc.querySelector('[data-customize-type="module"][data-customize-id="' + newModuleId + '"]');
    newModuleId = null;

    if (!m) {
      return;
    }

    m.classList.add('customize-new');

    try {
      m.scrollIntoView({ block: 'center' });
      m.dispatchEvent(new doc.defaultView.MouseEvent('mouseover', { bubbles: true }));
    } catch (err) {
      // non-fatal
    }
  });

  function buildAddModule() {
    var panel = JC.panel && JC.panel();

    if (!panel) {
      return;
    }

    var doc = window.document;
    var btn = doc.createElement('button');
    btn.type = 'button';
    btn.className = 'customize-add-module btn btn-primary btn-sm';
    btn.textContent = t('PLG_CUSTOMIZE_POSITION_ADD_BTN', 'Add module');
    panel.appendChild(btn);

    btn.addEventListener('click', function () {
      openAddForm(doc, panel, btn);
    });
  }

  buildAddModule();

  // Open the "move to position" picker for a module. Shared by the keyboard Ctrl+arrows, the drag
  // move-bar, and the toolbar Move button. dir optionally pre-steps the position selection.
  function startMove(el, doc, dir) {
    if (!isModuleEl(el)) {
      return;
    }

    cancelMove();
    pending = el;
    pending.classList.add('customize-pending');
    openSelector(doc, dir);
  }

  // Declare modules sortable; the engine handles the drag mechanics and calls onReorder on drop
  // onto another module or an empty-position drop zone, and onDelete for the engine's remove bar.
  JC.registerAreaType('module', {
    draggable: true,
    onReorder: function (info) {
      var position = info.target.getAttribute('data-customize-position')
        || info.target.getAttribute('data-customize-droppos');

      if (!position) {
        return;
      }

      info.dragged.setAttribute('data-customize-position', position);
      saveOrder(info.doc, position);
    },
    // Keyboard "send to position" (Ctrl+Left/Right) opens the same picker as the Move toolbar button.
    onMove: function (info) {
      startMove(info.el, info.doc, info.dir);
    },
    onDelete: function (info) {
      return JC.callAction('position', 'delete', { id: info.el.getAttribute('data-customize-id') }).then(function (res) {
        if (res && res.success) {
          reloadFrame();
          return true;
        }

        JC.ui.toast(info.doc, t('PLG_CUSTOMIZE_POSITION_DELETE_FAILED', 'Could not remove the module.'));
        return false;
      }).catch(function () {
        JC.ui.toast(info.doc, t('PLG_CUSTOMIZE_POSITION_SAVE_ERROR', 'Save error.'));
        return false;
      });
    }
  });

  // A visible, Tab-reachable alternative to the (OS-conflicting) Ctrl+arrow move shortcut: open the
  // position picker from the module's toolbar.
  JC.registerButton('module', {
    id: 'move',
    label: t('PLG_CUSTOMIZE_POSITION_BTN_MOVE', 'Move'),
    order: 70,
    onClick: function (ctx) {
      startMove(ctx.el, ctx.doc, 0);
    }
  });
