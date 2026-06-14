/**
 * Customize - Module plugin (admin side).
 *
 * Loaded in the com_menus Customize host page. Modules are marked server-side (ModulesRenderer);
 * this registers the "module" area type and a settings popover (title, show title, published).
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
import JC from 'customize.api';

  function t(key, fallback) {
    return JC.text(key, fallback);
  }

  function failMessage(res) {
    var reason = (res && res.message) || t('PLG_CUSTOMIZE_MODULE_UNKNOWN_ERROR', 'unknown error');
    return t('PLG_CUSTOMIZE_MODULE_SAVE_FAILED', 'Save failed: %s').replace('%s', reason);
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

  function editModule(ctx) {
    ctx.callAction('module', 'load', { id: ctx.data.id }).then(function (res) {
      if (!res || !res.success) {
        JC.ui.toast(ctx.doc, t('PLG_CUSTOMIZE_MODULE_LOAD_FAILED', 'Could not load module settings.'));
        return;
      }

      showPopover(ctx, res);
    });
  }

  function showPopover(ctx, data) {
    var doc = ctx.doc;

    var titleInput = doc.createElement('input');
    titleInput.type = 'text';
    titleInput.value = data.title || '';

    var showWrap = doc.createElement('label');
    showWrap.className = 'customize-check';
    var showTitle = doc.createElement('input');
    showTitle.type = 'checkbox';
    showTitle.checked = !!data.showtitle;
    showWrap.appendChild(showTitle);
    showWrap.appendChild(doc.createTextNode(t('PLG_CUSTOMIZE_MODULE_SHOW_TITLE', 'Show title')));

    var pubWrap = doc.createElement('label');
    pubWrap.className = 'customize-check';
    var published = doc.createElement('input');
    published.type = 'checkbox';
    published.checked = data.published === 1;
    pubWrap.appendChild(published);
    pubWrap.appendChild(doc.createTextNode(t('PLG_CUSTOMIZE_MODULE_PUBLISHED', 'Published')));

    JC.ui.popover(doc, {
      anchor: ctx.el,
      content: [
        JC.ui.label(doc, t('PLG_CUSTOMIZE_MODULE_TITLE', 'Title')),
        titleInput,
        showWrap,
        pubWrap
      ],
      onSave: function (api) {
        JC.ui.saving(api.bar.save);

        ctx.callAction('module', 'save', {
          id: ctx.data.id,
          title: titleInput.value,
          showtitle: showTitle.checked ? 1 : 0,
          published: published.checked ? 1 : 0
        }).then(function (res) {
          if (res && res.success) {
            api.close();
            JC.ui.toast(doc, t('PLG_CUSTOMIZE_MODULE_SAVED', 'Module saved.'));
            reloadFrame();
          } else {
            JC.ui.resetSave(api.bar.save);
            JC.ui.toast(doc, failMessage(res));
          }
        }).catch(function () {
          JC.ui.resetSave(api.bar.save);
          JC.ui.toast(doc, t('PLG_CUSTOMIZE_MODULE_SAVE_ERROR', 'Save error.'));
        });
      }
    });
  }

  // Edit a custom (mod_custom) module's HTML body in place with a WYSIWYG editor.
  function editContent(ctx) {
    var contentEl = ctx.el.querySelector('.mod-custom');

    if (!contentEl) {
      JC.ui.toast(ctx.doc, t('PLG_CUSTOMIZE_MODULE_NO_CONTENT', 'No editable content here.'));
      return;
    }

    JC.editInline({ doc: ctx.doc, el: contentEl }, {
      html: contentEl.innerHTML,
      save: function (content) {
        return ctx.callAction('module', 'savecontent', { id: ctx.data.id, html: content }).then(function (res) {
          if (res && res.success) {
            JC.ui.toast(ctx.doc, t('PLG_CUSTOMIZE_MODULE_CONTENT_SAVED', 'Content saved.'));
          } else {
            JC.ui.toast(ctx.doc, failMessage(res));
          }

          return { success: !!(res && res.success), html: res && res.html };
        });
      }
    });
  }

  function openModuleEditor(ctx) {
    JC.openEditModal({
      area: ctx.el,
      url: 'index.php?option=com_modules&view=module&layout=modal&id=' + encodeURIComponent(ctx.data.id),
      title: t('PLG_CUSTOMIZE_MODULE_BTN_ADVANCED', 'Advanced'),
      checkin: 'index.php?option=com_modules&task=modules.checkin&format=json&cid[]=' + encodeURIComponent(ctx.data.id),
      onClose: reloadFrame
    });
  }

  // Open the focused editor for this module's own layout file (the override is created lazily on
  // save), reusing the view plugin's shared override-resolve action.
  function openModuleLayoutEditor(ctx) {
    JC.callAction('view', 'override', {
      type: 'module',
      component: ctx.data.module,
      view: '',
      source: ctx.data.source,
      template: ctx.data.template
    }).then(function (res) {
      if (res && res.success && res.url) {
        JC.openEditModal({
          area: ctx.el,
          url: res.url,
          title: t('PLG_CUSTOMIZE_MODULE_BTN_LAYOUT', 'Edit layout'),
          onClose: reloadFrame
        });
      } else {
        JC.ui.toast(ctx.doc, (res && res.message) || t('PLG_CUSTOMIZE_MODULE_UNKNOWN_ERROR', 'unknown error'));
      }
    }).catch(function () {
      JC.ui.toast(ctx.doc, t('PLG_CUSTOMIZE_MODULE_SAVE_ERROR', 'Save error.'));
    });
  }

  // The PHP side reports which areas the user may edit: modules (com_modules) and/or menu items
  // (com_menus). Only register the area type the user is permitted to use.
  var perms = (window.Joomla && window.Joomla.getOptions) ? (window.Joomla.getOptions('customize.module', {}) || {}) : {};

  if (perms.modules) {
    JC.registerAreaType('module', { label: t('PLG_CUSTOMIZE_MODULE_AREA', 'Module') });
    JC.registerButton('module', { id: 'edit', label: t('PLG_CUSTOMIZE_MODULE_BTN_EDIT', 'Edit'), order: 10, onClick: editModule });
    // Only shown on custom modules (data-customize-custom emitted by the renderer).
    JC.registerButton('module', { id: 'content', label: t('PLG_CUSTOMIZE_MODULE_BTN_CONTENT', 'Edit content'), order: 20, requires: 'custom', onClick: editContent });

    // Layout override needs core.admin, and only shows when the module's layout file was resolved.
    if (perms.overrides) {
      JC.registerButton('module', { id: 'layout', label: t('PLG_CUSTOMIZE_MODULE_BTN_LAYOUT', 'Edit layout'), order: 80, requires: 'source', onClick: openModuleLayoutEditor });

      // Flag modules whose layout file already has an override (mirrors the view-block "Overridden" cue).
      JC.on('customize:frame-ready', function (e) {
        var doc = e.detail && e.detail.doc;

        if (!doc) {
          return;
        }

        Array.prototype.forEach.call(doc.querySelectorAll('[data-customize-type="module"][data-customize-override="1"]'), function (el) {
          if (!el.getAttribute('data-customize-cue')) {
            el.setAttribute('data-customize-cue', t('PLG_CUSTOMIZE_MODULE_OVERRIDDEN', 'Overridden'));
          }
        });
      });
    }

    JC.registerButton('module', { id: 'advanced', label: t('PLG_CUSTOMIZE_MODULE_BTN_ADVANCED', 'Advanced'), order: 90, onClick: openModuleEditor });
  }

  // --- Menu items (reorder by dragging) ---------------------------------------
  // Tag each <li class="item-<id>"> in a menu module as a draggable menu-item area.

  function siblingMenuItem(el, dir) {
    var n = el[dir];
    while (n && n.getAttribute('data-customize-type') !== 'menuitem') {
      n = n[dir];
    }
    return n;
  }

  function openMenuItemEditor(ctx) {
    JC.openEditModal({
      area: ctx.el,
      url: 'index.php?option=com_menus&view=item&layout=modal&id=' + encodeURIComponent(ctx.data.id),
      title: t('PLG_CUSTOMIZE_MODULE_BTN_ADVANCED', 'Advanced'),
      checkin: 'index.php?option=com_menus&task=items.checkin&format=json&cid[]=' + encodeURIComponent(ctx.data.id),
      onClose: reloadFrame
    });
  }

  // Rename a menu item in place. Uses a real text input (not contenteditable on the menu link), so
  // the caret and arrow keys behave normally, and stops keydown from reaching the menu's own
  // keyboard handling.
  function editMenuItemName(ctx) {
    var doc = ctx.doc;
    var li = ctx.el;

    if (li.getAttribute('data-customize-editing') === '1') {
      return;
    }

    var anchor = li.querySelector('a') || li;
    var original = (anchor.textContent || '').trim();

    li.setAttribute('data-customize-editing', '1');
    JC.emit('customize:edit-start');

    var input = doc.createElement('input');
    input.type = 'text';
    input.className = 'customize-name-input';
    input.value = original;

    anchor.classList.add('customize-edit-hidden');
    anchor.parentNode.insertBefore(input, anchor.nextSibling);

    var bar = JC.ui.makeBar(doc);
    input.parentNode.insertBefore(bar.el, input.nextSibling);

    input.focus();
    input.select();

    function teardown() {
      anchor.classList.remove('customize-edit-hidden');
      if (input.parentNode) {
        input.parentNode.removeChild(input);
      }
      if (bar.el.parentNode) {
        bar.el.parentNode.removeChild(bar.el);
      }
      li.removeAttribute('data-customize-editing');
      JC.emit('customize:edit-end');
    }

    function cancel() {
      teardown();
    }

    function save() {
      var value = input.value.trim();

      if (!value) {
        return;
      }

      JC.ui.saving(bar.save);

      ctx.callAction('module', 'savemenuitem', { id: ctx.data.id, title: value }).then(function (res) {
        if (res && res.success) {
          anchor.textContent = value;
          li.setAttribute('data-customize-name', value);
          teardown();
          JC.ui.toast(doc, t('PLG_CUSTOMIZE_MODULE_MENUITEM_SAVED', 'Menu item saved.'));
        } else {
          JC.ui.resetSave(bar.save);
          JC.ui.toast(doc, failMessage(res));
        }
      }).catch(function () {
        JC.ui.resetSave(bar.save);
        JC.ui.toast(doc, t('PLG_CUSTOMIZE_MODULE_SAVE_ERROR', 'Save error.'));
      });
    }

    input.addEventListener('keydown', function (e) {
      e.stopPropagation();

      if (e.key === 'Enter') {
        e.preventDefault();
        save();
      } else if (e.key === 'Escape') {
        e.preventDefault();
        cancel();
      }
    });

    bar.save.addEventListener('click', save);
    bar.cancel.addEventListener('click', cancel);
  }

  if (perms.menus) {
    JC.on('customize:frame-ready', function (e) {
      var doc = e.detail && e.detail.doc;

      if (!doc) {
        return;
      }

      Array.prototype.forEach.call(doc.querySelectorAll('[data-customize-module="mod_menu"] li'), function (li) {
        var match = /(?:^|\s)item-(\d+)(?:\s|$)/.exec(li.className);

        if (!match || li.hasAttribute('data-customize-type')) {
          return;
        }

        var link = li.querySelector('a, span');
        li.setAttribute('data-customize-type', 'menuitem');
        li.setAttribute('data-customize-id', match[1]);
        li.setAttribute('data-customize-name', (link ? link.textContent : '').trim());
      });
    });

    JC.registerAreaType('menuitem', {
      label: t('PLG_CUSTOMIZE_MODULE_MENUITEM', 'Menu item'),
      draggable: true,
      onReorder: function (info) {
        var el = info.dragged;
        var reference;
        var position;
        var prev = siblingMenuItem(el, 'previousElementSibling');

        if (prev) {
          reference = prev.getAttribute('data-customize-id');
          position = 'after';
        } else {
          var next = siblingMenuItem(el, 'nextElementSibling');
          if (!next) {
            return;
          }
          reference = next.getAttribute('data-customize-id');
          position = 'before';
        }

        JC.callAction('module', 'movemenuitem', {
          id: el.getAttribute('data-customize-id'),
          reference: reference,
          position: position
        }).then(function (res) {
          if (res && res.success) {
            JC.ui.toast(info.doc, t('PLG_CUSTOMIZE_MODULE_MENU_REORDERED', 'Menu reordered.'));
          } else {
            JC.ui.toast(info.doc, failMessage(res));
            reloadFrame();
          }
        }).catch(function () {
          JC.ui.toast(info.doc, t('PLG_CUSTOMIZE_MODULE_SAVE_ERROR', 'Save error.'));
          reloadFrame();
        });
      },
      onDelete: function (info) {
        return JC.callAction('module', 'deletemenuitem', { id: info.el.getAttribute('data-customize-id') }).then(function (res) {
          if (res && res.success) {
            reloadFrame();
            return true;
          }

          JC.ui.toast(info.doc, failMessage(res));
          return false;
        }).catch(function () {
          JC.ui.toast(info.doc, t('PLG_CUSTOMIZE_MODULE_SAVE_ERROR', 'Save error.'));
          return false;
        });
      }
    });

    JC.registerButton('menuitem', { id: 'edit', label: t('PLG_CUSTOMIZE_MODULE_BTN_EDIT', 'Edit'), order: 10, onClick: editMenuItemName });
    JC.registerButton('menuitem', { id: 'advanced', label: t('PLG_CUSTOMIZE_MODULE_BTN_ADVANCED', 'Advanced'), order: 90, onClick: openMenuItemEditor });
  }
