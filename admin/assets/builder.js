(function ($) {
  'use strict';

  // Unsaved-changes guard for builder.
  var LAST_SAVED_RAW = null;
  var IS_DIRTY = false;
  var IS_SAVING = false;


  function uid(prefix) {
    prefix = prefix || 'fld';
    return prefix + '_' + Math.random().toString(16).slice(2, 10);
  }

  function deepClone(obj) {
    return JSON.parse(JSON.stringify(obj));
  }

  function normalizeSchema(schema) {
    schema = schema && typeof schema === 'object' ? schema : {};
    schema.version = Math.max(2, parseInt(schema.version, 10) || 1);
    schema.fields = Array.isArray(schema.fields) ? schema.fields : [];
    schema.ui = schema.ui && typeof schema.ui === 'object' ? schema.ui : { submit_text: 'Send' };
    schema.mapping = schema.mapping && typeof schema.mapping === 'object' ? schema.mapping : {};

    // Ensure each field has a width (1–12). We allow decimals (e.g. 6.5)
    // so users can fine-tune widths beyond whole columns.
    schema.fields.forEach(function (f) {
      if (!f || typeof f !== 'object') return;
      var w = parseFloat(f.width);
      if (isNaN(w) || w < 1 || w > 12) w = 12;
      w = Math.round(w * 10) / 10;
      f.width = w;
      f.settings = f.settings && typeof f.settings === 'object' ? f.settings : {};

      var posX = parseFloat(f.settings.canvas_x);
      var posY = parseFloat(f.settings.canvas_y);
      if (isNaN(posX) || posX < 0) delete f.settings.canvas_x;
      else f.settings.canvas_x = Math.round(posX * 10) / 10;
      if (isNaN(posY) || posY < 0) delete f.settings.canvas_y;
      else f.settings.canvas_y = Math.round(posY * 10) / 10;

      // Normalize height settings.
      // - box_height: legacy/internal builder box height
      // - height: the intended control height used in preview + published form
      if (f.settings && (f.settings.height || f.settings.box_height)) {
        var h = parseFloat(f.settings.height || f.settings.box_height);
        if (!isNaN(h) && h > 0) {
          h = Math.round(h * 10) / 10;
          f.settings.height = h;
          f.settings.box_height = h;
        } else {
          delete f.settings.height;
          delete f.settings.box_height;
        }
      }
    });

    return schema;
  }

  function typeLabel(type) {
    var map = {
      name: 'Name',
      text: 'Single Line Text',
      textarea: 'Paragraph Text',
      email: 'Email',
      phone: 'Phone',
      dropdown: 'Dropdown',
      checkboxes: 'Checkboxes',
      number: 'Number',
      date: 'Date',
      file: 'File (URL)',
      hidden: 'Hidden',
      recaptcha: 'reCAPTCHA (placeholder)'
    };
    return map[type] || type;
  }


  var PAYLOAD_FIELD_PRESETS = {
    name: {
      key: 'name',
      type: 'text',
      paletteLabel: 'Full Name',
      fieldLabel: 'Full Name',
      badge: 'A',
      required: true,
      placeholder: 'Your name'
    },
    email: {
      key: 'email',
      type: 'email',
      paletteLabel: 'Email',
      fieldLabel: 'Email',
      badge: '@',
      required: false,
      placeholder: 'you@company.com'
    },
    phone: {
      key: 'phone',
      type: 'phone',
      paletteLabel: 'Phone',
      fieldLabel: 'Phone number',
      badge: '☎',
      required: false,
      placeholder: '+1 (555) 000-0000'
    },
    company: {
      key: 'company',
      type: 'text',
      paletteLabel: 'Company',
      fieldLabel: 'Company',
      badge: 'A',
      required: false,
      placeholder: 'Company name'
    },
    position: {
      key: 'position',
      type: 'text',
      paletteLabel: 'Position',
      fieldLabel: 'Position',
      badge: 'A',
      required: false,
      placeholder: 'Job title'
    },
    website: {
      key: 'website',
      type: 'text',
      paletteLabel: 'Website',
      fieldLabel: 'Website',
      badge: 'A',
      required: false,
      placeholder: 'company.com'
    },
    address: {
      key: 'address',
      type: 'textarea',
      paletteLabel: 'Address',
      fieldLabel: 'Address',
      badge: '¶',
      required: false,
      placeholder: 'Street, city, state, ZIP'
    },
    value: {
      key: 'value',
      type: 'number',
      paletteLabel: 'Value',
      fieldLabel: 'Estimated Value',
      badge: '#',
      required: false,
      placeholder: '2500000'
    },
    notes: {
      key: 'notes',
      type: 'textarea',
      paletteLabel: 'Notes',
      fieldLabel: 'Notes',
      badge: '¶',
      required: false,
      placeholder: 'Tell us a little about the project...'
    }
  };

  function getPayloadPreset(name) {
    name = String(name || '').trim();
    return Object.prototype.hasOwnProperty.call(PAYLOAD_FIELD_PRESETS, name) ? PAYLOAD_FIELD_PRESETS[name] : null;
  }

  function syncPayloadFieldMapping(field) {
    if (!field || typeof field !== 'object' || !field.id) return;
    field.settings = field.settings && typeof field.settings === 'object' ? field.settings : {};
    var payloadKey = field.settings.payload_key ? String(field.settings.payload_key).trim() : '';
    if (!payloadKey) return;
    SCHEMA.mapping = SCHEMA.mapping && typeof SCHEMA.mapping === 'object' ? SCHEMA.mapping : {};
    SCHEMA.mapping[field.id] = payloadKey;
  }

  function syncPayloadMappings() {
    SCHEMA.mapping = SCHEMA.mapping && typeof SCHEMA.mapping === 'object' ? SCHEMA.mapping : {};
    (SCHEMA.fields || []).forEach(function (field) {
      syncPayloadFieldMapping(field);
    });
  }

  function defaultFieldFor(type) {
    var preset = getPayloadPreset(type);
    var baseType = preset ? preset.type : type;
    var fieldIdPrefix = preset ? ('fld_' + preset.key) : 'fld';
    var f = {
      id: uid(fieldIdPrefix),
      type: baseType,
      label: preset ? preset.fieldLabel : typeLabel(baseType),
      required: preset ? !!preset.required : false,
      width: 12,
      settings: {
        placeholder: preset && preset.placeholder ? preset.placeholder : ''
      }
    };

    if (preset) {
      f.settings.payload_key = preset.key;
      if (preset.type === 'dropdown' || preset.type === 'checkboxes') {
        f.settings.choices = Array.isArray(preset.choices) ? preset.choices.slice() : ['Option 1', 'Option 2'];
      }
      return f;
    }

    if (type === 'name') {
      f.type = 'text';
      f.label = 'Name';
      f.required = true;
    }

    if (type === 'email') {
      f.required = true;
      f.settings.placeholder = 'you@company.com';
    }

    if (type === 'phone') {
      f.settings.placeholder = '+1 (555) 000-0000';
    }

    if (type === 'dropdown' || type === 'checkboxes') {
      f.settings.choices = ['Option 1', 'Option 2'];
    }

    if (type === 'hidden') {
      f.settings.default_value = '';
      f.label = 'Hidden field';
    }

    if (type === 'file') {
      f.settings.placeholder = 'https://…';
    }

    if (type === 'recaptcha') {
      f.label = 'reCAPTCHA';
      f.settings.site_key = '';
      f.settings.version = 'v2';
    }

    return f;
  }

  function getCanvasMetrics() {
    var $list = $('#riboCanvasList');
    var width = Math.max(320, $list.innerWidth() || $list.width() || 320);
    return { $list: $list, width: width };
  }

  function clampCanvasPosition(field, left, top, widthPx, heightPx, canvasWidth) {
    field.settings = field.settings || {};
    var maxLeft = Math.max(0, canvasWidth - Math.max(120, widthPx || 120));
    var nextLeft = Math.max(0, Math.min(maxLeft, Math.round((left || 0) * 10) / 10));
    var nextTop = Math.max(0, Math.round((top || 0) * 10) / 10);
    field.settings.canvas_x = nextLeft;
    field.settings.canvas_y = nextTop;
  }

  function getFieldBoxHeight(field) {
    if (field && field.settings && (field.settings.box_height || field.settings.height)) {
      var bh = parseFloat(field.settings.box_height || field.settings.height);
      if (!isNaN(bh) && bh > 0) return bh;
    }
    return 86;
  }

  function getFieldWidthPx(field, canvasWidth) {
    var span = (field && field.width !== undefined) ? parseFloat(field.width) : 12;
    if (isNaN(span) || span < 1 || span > 12) span = 12;
    span = Math.round(span * 10) / 10;
    return Math.max(120, (span / 12) * canvasWidth);
  }

  // Ensure each field has canvas_x/canvas_y. By default we use the CURRENT
  // builder canvas width. For preview mode, the builder canvas is hidden so
  // measuring it returns 0; in that case we pass an explicit width override.
  function ensureCanvasPositions(schema, forceReflow, widthOverride) {
    schema = normalizeSchema(schema || {});
    var canvasW = parseFloat(widthOverride);
    if (isNaN(canvasW) || canvasW < 320) {
      canvasW = getCanvasMetrics().width;
    }
    var cursorX = 0;
    var cursorY = 0;
    var rowHeight = 0;
    var gap = 12;

    schema.fields.forEach(function (f) {
      if (!f || typeof f !== 'object') return;
      f.settings = f.settings && typeof f.settings === 'object' ? f.settings : {};
      var widthPx = getFieldWidthPx(f, canvasW);
      var heightPx = getFieldBoxHeight(f);
      var hasPos = typeof f.settings.canvas_x === 'number' && typeof f.settings.canvas_y === 'number';

      if (forceReflow || !hasPos) {
        if (cursorX > 0 && cursorX + widthPx > canvasW) {
          cursorX = 0;
          cursorY += rowHeight + gap;
          rowHeight = 0;
        }
        clampCanvasPosition(f, cursorX, cursorY, widthPx, heightPx, canvasW);
        cursorX = (f.settings.canvas_x || 0) + widthPx + gap;
        rowHeight = Math.max(rowHeight, heightPx);
      } else {
        clampCanvasPosition(f, f.settings.canvas_x, f.settings.canvas_y, widthPx, heightPx, canvasW);
      }
    });
  }

  function refreshCanvasHeight(schema) {
    var metrics = getCanvasMetrics();
    var maxBottom = 0;
    (schema.fields || []).forEach(function (f) {
      if (!f || typeof f !== 'object') return;
      var top = (f.settings && typeof f.settings.canvas_y === 'number') ? f.settings.canvas_y : 0;
      var heightPx = getFieldBoxHeight(f);
      maxBottom = Math.max(maxBottom, top + heightPx);
    });
    metrics.$list.css('min-height', Math.max(360, maxBottom + 24) + 'px');
  }

  function renderCanvas(schema, selectedId) {
    var $list = $('#riboCanvasList');
    $list.empty();

    if (!schema.fields.length) {
      $('#riboCanvasEmpty').show();
      $list.css('min-height', '360px');
      return;
    }

    $('#riboCanvasEmpty').hide();
    ensureCanvasPositions(schema, false);

    schema.fields.forEach(function (f) {
      var $li = $('<li class="ribo-canvas-item" />').attr('data-id', f.id);
      var span = (f && f.width !== undefined) ? parseFloat(f.width) : 12;
      if (isNaN(span) || span < 1 || span > 12) span = 12;
      var pct = (span / 12) * 100;
      var left = (f.settings && typeof f.settings.canvas_x === 'number') ? f.settings.canvas_x : 0;
      var top = (f.settings && typeof f.settings.canvas_y === 'number') ? f.settings.canvas_y : 0;
      $li.css({ width: pct + '%', maxWidth: pct + '%', left: left + 'px', top: top + 'px' });

      if (f.settings && (f.settings.box_height || f.settings.height)) {
        var bh = parseFloat(f.settings.box_height || f.settings.height);
        if (!isNaN(bh) && bh > 0) $li.css('height', bh + 'px');
      }
      if (selectedId && selectedId === f.id) $li.addClass('is-selected');

      var $head = $('<div class="ribo-canvas-item-head" />');
      var $title = $('<div />');
      $title.append($('<div class="ribo-canvas-item-title" />').text(f.label || '(No label)'));
      $title.append($('<div class="ribo-canvas-item-meta" />').text(typeLabel(f.type) + (f.required ? ' • Required' : '')));

      var $actions = $('<div class="ribo-canvas-item-actions" />');
      var $dup = $('<button type="button" class="ribo-icon-btn" title="Duplicate">Duplicate</button>');
      var $del = $('<button type="button" class="ribo-icon-btn" title="Delete">Delete</button>');
      $actions.append($dup, $del);

      $head.append($title, $actions);
      $li.append($head);
      $list.append($li);

      $li.on('mousedown', function (e) {
        if ($(e.target).closest('.ribo-icon-btn, .ribo-resize-handle, .ui-resizable-handle, input, textarea, select, option, button, a').length) return;
        selectField(f.id, { skipCanvasRender: true });
      });

      $li.on('click', function (e) {
        if ($(e.target).closest('.ribo-icon-btn, .ribo-resize-handle, .ui-resizable-handle, input, textarea, select, option, button, a').length) return;
        selectField(f.id, { skipCanvasRender: true });
      });

      $del.on('click', function () { removeField(f.id); });
      $dup.on('click', function () { duplicateField(f.id); });
    });

    initCanvasDroppable($list);
    initCanvasDragging($list, schema, selectedId);
    initResizableCanvasItems($list, schema, selectedId);
    refreshCanvasHeight(schema);
  }

  function initCanvasDragging($list, schema, selectedId) {
    if (!$list || !$list.length) return;
    $list.find('.ribo-canvas-item').each(function () {
      var $item = $(this);
      var fieldId = $item.attr('data-id');
      try { if ($item.data('ui-draggable')) { $item.draggable('destroy'); } } catch (e) { }
      $item.draggable({
        containment: 'parent',
        cancel: '.ribo-icon-btn, .ui-resizable-handle, .ribo-resize-handle, input, textarea, select, option, button, a',
        distance: 0,
        start: function () {
          selectField(fieldId, { skipCanvasRender: true });
          $(this).addClass('ribo-is-dragging');
        },
        drag: function () { refreshCanvasHeight(schema); },
        stop: function (evt, ui) {
          $(this).removeClass('ribo-is-dragging');
          var f = (SCHEMA && SCHEMA.fields ? SCHEMA.fields : []).find(function (x) { return x.id === fieldId; });
          if (!f) return;
          var metrics = getCanvasMetrics();
          clampCanvasPosition(f, ui.position.left, ui.position.top, $item.outerWidth(), $item.outerHeight(), metrics.width);
          syncHiddenJSON();
          refreshCanvasHeight(schema);
          selectField(fieldId, { skipCanvasRender: true });
        }
      });
    });
  }

  function initResizableCanvasItems($list, schema, selectedId) {
    if (!$list || !$list.length) return;

    var handleList = ['n', 'e', 's', 'w', 'ne', 'nw', 'se', 'sw'];

    $list.find('.ribo-canvas-item').each(function () {
      var $it = $(this);
      // Clean up previous resizable if it exists
      try { if ($it.data('ui-resizable')) { $it.resizable('destroy'); } } catch (e) { }

      // Append handles if missing
      if ($it.find('.ribo-resize-handle').length === 0) {
        handleList.forEach(function (h) {
          $it.append('<div class="ui-resizable-handle ui-resizable-' + h + ' ribo-resize-handle ribo-resize-' + h + '" data-axis="' + h + '"></div>');
        });
      }
    });

    $list.find('.ribo-canvas-item').each(function () {
      var $item = $(this);
      var fieldId = $item.attr('data-id');
      var startBox = null;

      $item.resizable({
        containment: 'parent',
        handles: {
          n: '.ribo-resize-n', e: '.ribo-resize-e', s: '.ribo-resize-s', w: '.ribo-resize-w',
          ne: '.ribo-resize-ne', nw: '.ribo-resize-nw', se: '.ribo-resize-se', sw: '.ribo-resize-sw'
        },
        minWidth: 120,
        maxWidth: 1600,
        minHeight: 64,
        alsoResize: false,
        start: function (evt, ui) {
          // RELIABLE AXIS DETECTION: Use jQuery UI's internal axis property.
          var axis = '';
          try {
            var inst = $(this).data('ui-resizable') || $(this).data('resizable');
            if (inst && inst.axis) {
              axis = inst.axis;
            }
          } catch (e) { }

          if (!axis) {
            try {
              var tgt = (evt && evt.originalEvent && evt.originalEvent.target) ? evt.originalEvent.target : evt.target;
              axis = $(tgt).closest('.ribo-resize-handle').attr('data-axis') || '';
            } catch (e) { }
          }

          startBox = {
            left: parseFloat($item.css('left')) || 0,
            top: parseFloat($item.css('top')) || 0,
            width: $item.outerWidth(),
            height: $item.outerHeight()
          };

          $(this).attr('data-ribo-resize-axis', axis).addClass('ribo-is-resizing');
          try { if ($item.data('ui-draggable')) { $item.draggable('disable'); } } catch (e) { }
        },
        resize: function (evt, ui) {
          if (!startBox) return;
          var axis = (($item.attr('data-ribo-resize-axis') || '') + '').toLowerCase();

          var isW = axis.indexOf('w') !== -1;
          var isE = axis.indexOf('e') !== -1;
          var isN = axis.indexOf('n') !== -1;
          var isS = axis.indexOf('s') !== -1;

          var updateW = isW || isE || !axis;
          var updateH = isN || isS || !axis;

          var nextWidth = ui.size.width;
          var nextHeight = ui.size.height;
          var nextLeft = ui.position.left;
          var nextTop = ui.position.top;

          // Manual override for left-side/top-side movement to ensure absolute precision
          if (updateW) {
            if (isW) {
              // For 'w' handles, jQuery UI updates position.left. We calculate width from that delta.
              nextWidth = startBox.width + (startBox.left - ui.position.left);
              nextWidth = Math.max(120, nextWidth);
              nextLeft = startBox.left + (startBox.width - nextWidth);
            } else {
              nextWidth = Math.max(120, ui.size.width);
              nextLeft = startBox.left;
            }
            $item.css({ left: nextLeft + 'px', width: nextWidth + 'px', maxWidth: nextWidth + 'px' });
          }

          if (updateH) {
            if (isN) {
              nextHeight = startBox.height + (startBox.top - ui.position.top);
              nextHeight = Math.max(64, nextHeight);
              nextTop = startBox.top + (startBox.height - nextHeight);
            } else {
              nextHeight = Math.max(64, ui.size.height);
              nextTop = startBox.top;
            }
            $item.css({ top: nextTop + 'px', height: nextHeight + 'px' });
          }

          refreshCanvasHeight(schema);
        },
        stop: function (evt, ui) {
          $(this).removeClass('ribo-is-resizing');
          var f = (SCHEMA && SCHEMA.fields ? SCHEMA.fields : []).find(function (x) { return x.id === fieldId; });
          if (!f || !startBox) {
            try { if ($item.data('ui-draggable')) { $item.draggable('enable'); } } catch (e) { }
            return;
          }

          var metrics = getCanvasMetrics();
          var axis = (($item.attr('data-ribo-resize-axis') || '') + '').toLowerCase();

          var isW = axis.indexOf('w') !== -1;
          var isE = axis.indexOf('e') !== -1;
          var isN = axis.indexOf('n') !== -1;
          var isS = axis.indexOf('s') !== -1;

          var updateW = isW || isE || !axis;
          var updateH = isN || isS || !axis;

          var finalWidth = updateW ? $item.outerWidth() : startBox.width;
          var finalHeight = updateH ? $item.outerHeight() : startBox.height;

          // Use the actual CSS values as they are the most trusthworthy after the resize loop
          var finalLeft = updateW ? (parseFloat($item.css('left')) || 0) : startBox.left;
          var finalTop = updateH ? (parseFloat($item.css('top')) || 0) : startBox.top;

          if (updateW) {
            var pct = finalWidth / Math.max(1, metrics.width);
            if (isNaN(pct) || pct <= 0) pct = 1 / 12;
            if (pct > 1) pct = 1;
            var cols = Math.round(pct * 12 * 10) / 10;
            if (cols < 1) cols = 1;
            if (cols > 12) cols = 12;
            f.width = cols;
          }

          if (updateH) {
            f.settings = f.settings || {};
            var newH = Math.max(64, Math.round(finalHeight * 10) / 10);
            f.settings.box_height = newH;
            f.settings.height = newH;
          }

          clampCanvasPosition(f, finalLeft, finalTop, finalWidth, finalHeight, metrics.width);
          syncHiddenJSON();
          renderCanvas(SCHEMA, fieldId);
          selectField(fieldId, { skipCanvasRender: true });
          try { if ($item.data('ui-draggable')) { $item.draggable('enable'); } } catch (e) { }
        }
      });
    });
  }

  function ensurePaletteTemplate(type, templateHTML) {
    if (!type) return;
    // IMPORTANT: Only check the PALETTE, not the whole document.
    // When using connectToSortable, jQuery UI may temporarily move the original
    // palette element into the sortable list. If we query globally, we will
    // incorrectly think the template still exists and we won't restore it.
    if ($('#riboPalettePayload .ribo-field-item[data-type="' + type + '"]').length) return;
    if ($('#riboPaletteAdvanced .ribo-field-item[data-type="' + type + '"]').length) return;

    var advanced = ['hidden', 'file', 'recaptcha'];
    var $group = advanced.indexOf(type) !== -1 ? $('#riboPaletteAdvanced') : $('#riboPalettePayload');
    if (!$group.length) $group = $('.ribo-palette-group').first();

    var $item;
    if (templateHTML) {
      $item = $(templateHTML);
      $item.removeAttr('style');
      $item.removeClass('ui-draggable ui-draggable-handle ui-draggable-dragging ui-sortable-helper');
    } else {
      $item = $('<div class="ribo-field-item" />').attr('data-type', type)
        .append('<span class="ribo-field-badge">+</span>')
        .append('<span>' + (getPayloadPreset(type) ? getPayloadPreset(type).paletteLabel : typeLabel(type)) + '</span>');
    }

    $group.append($item);
    initPaletteDraggable($item);
  }

  function restorePaletteTemplateElement($movedEl, type) {
    if (!$movedEl || !$movedEl.length || !type) return;

    // If the palette already has the template, discard the moved element.
    if ($('#riboPalettePayload .ribo-field-item[data-type="' + type + '"]').length ||
      $('#riboPaletteAdvanced .ribo-field-item[data-type="' + type + '"]').length) {
      $movedEl.remove();
      return;
    }

    var advanced = ['hidden', 'file', 'recaptcha'];
    var $group = advanced.indexOf(type) !== -1 ? $('#riboPaletteAdvanced') : $('#riboPalettePayload');
    if (!$group.length) $group = $('.ribo-palette-group').first();

    // Clean + move back.
    $movedEl.detach();
    $movedEl.removeAttr('style');
    $movedEl.removeClass('ui-draggable ui-draggable-handle ui-draggable-dragging ui-sortable-helper');
    $group.append($movedEl);

    // Re-init draggable (in case the element lost handlers).
    $movedEl.removeData('ribo-dnd-init');
    initPaletteDraggable($movedEl);
  }

  function initPaletteDraggable($elements) {
    if (!$elements || !$elements.length) return;

    $elements.each(function () {
      var $el = $(this);
      if ($el.data('ribo-dnd-init')) return;
      $el.data('ribo-dnd-init', true);

      $el.draggable({
        // Important: palette items are TEMPLATES, so they must NEVER be removed from the left sidebar.
        // Some jQuery UI + connectToSortable combinations can end up moving the original element.
        // We therefore (1) drag a clone as helper AND (2) forcibly restore the original to its
        // original parent/position on stop if it was moved.
        helper: function () {
          var $h = $el.clone();
          $h.attr('data-type', $el.attr('data-type'));
          $h.attr('data-preset', $el.attr('data-preset'));
          // Make sure the helper keeps a sensible width like WPForms.
          $h.css('width', $el.outerWidth());
          return $h;
        },
        appendTo: 'body',
        cursor: 'move',
        revert: 'invalid',
        start: function (e, ui) {
          ui.helper.attr('data-type', $el.attr('data-type'));
          ui.helper.attr('data-preset', $el.attr('data-preset'));
          // Remember original location so we can put the template back if jQuery UI moves it.
          $el.data('riboOrigParent', $el.parent());
          $el.data('riboOrigNext', $el.next());
          $('#riboCanvasEmpty').addClass('is-dropping');
        },
        stop: function () {
          $('#riboCanvasEmpty').removeClass('is-dropping');

          // If the original template was moved, restore it.
          var $origParent = $el.data('riboOrigParent');
          var $origNext = $el.data('riboOrigNext');
          if ($origParent && $origParent.length && !$el.parent().is($origParent)) {
            if ($origNext && $origNext.length) {
              $el.insertBefore($origNext);
            } else {
              $origParent.append($el);
            }
          }

          // Clean up any draggable/sortable inline styles/classes that might stick.
          $el.removeAttr('style');
          $el.removeClass('ui-draggable ui-draggable-handle ui-draggable-dragging ui-sortable-helper');
        }
      });
    });
  }

  var SCHEMA = normalizeSchema(window.RIBO_BUILDER_SCHEMA || {});
  var SELECTED = null;
  var PREVIEW_MODE = false;

  function buildPreviewDOM(schema) {
    // IMPORTANT: Preview mode must NEVER mutate the builder working schema.
    schema = deepClone(schema || {});
    schema = normalizeSchema(schema);

    var fields = Array.isArray(schema.fields) ? schema.fields : [];
    var submitText = (schema.ui && schema.ui.submit_text) ? String(schema.ui.submit_text) : 'Send';

    // Original canvas width acts as the "ruler" for converting pixels to percentages.
    var rulerW = (schema.ui && schema.ui.canvas_width) ? parseFloat(schema.ui.canvas_width) : 0;
    if (isNaN(rulerW) || rulerW < 320) rulerW = 720;

    var stageHeight = 0;
    // Create a wrapper that matches the builder width "literally". 
    var $wrap = $('<div class="ribo-form-wrap ribo-form-wrap--layout" />');
    $wrap.css({
      'width': rulerW + 'px',
      'max-width': '100%',
      'margin': '0 auto',
      'background': '#fff',
      'border': '1px solid #dcdcde',
      'border-radius': '12px',
      'padding': '20px',
      'box-sizing': 'border-box'
    });

    var $form = $('<form class="ribo-form ribo-form--layout" novalidate></form>');
    var $stage = $('<div class="ribo-form-stage" />');
    $stage.css({ 'width': '100%', 'position': 'relative' });

    $form.on('submit', function (e) { e.preventDefault(); return false; });
    $form.append('<input type="hidden" name="_page_url" value="" />');

    fields.forEach(function (f) {
      if (!f || typeof f !== 'object') return;

      var fid = String(f.id || '');
      if (!fid) return;

      var type = String(f.type || 'text');
      var label = String(f.label || '');
      var required = !!f.required;
      var settings = (f.settings && typeof f.settings === 'object') ? f.settings : {};
      var ph = settings.placeholder ? String(settings.placeholder) : '';
      var defVal = (settings.default_value !== undefined) ? String(settings.default_value) : '';

      var leftPx = (typeof settings.canvas_x === 'number') ? settings.canvas_x : 0;
      var topPx = (typeof settings.canvas_y === 'number') ? settings.canvas_y : 0;
      var widthPx = getFieldWidthPx(f, rulerW);
      var fieldBoxHeight = getFieldBoxHeight(f);

      if (type === 'hidden') {
        $form.append($('<input type="hidden" />').attr({ id: fid, name: fid, value: defVal }));
        return;
      }

      stageHeight = Math.max(stageHeight, topPx + fieldBoxHeight);

      // BIT-FOR-BIT LITERAL MATCH: Use pixels.
      var $field = $('<div class="ribo-field" />').css({
        left: leftPx + 'px',
        top: topPx + 'px',
        width: widthPx + 'px',
        height: fieldBoxHeight + 'px'
      });

      if (label) {
        var $lab = $('<label />').attr('for', fid).text(label);
        if (required) $lab.append(' ').append('<span class="ribo-req">*</span>');
        $field.append($lab);
      }

      var ctrlH = settings.height || settings.box_height || null;

      if (type === 'textarea') {
        var $ta = $('<textarea />').attr({ id: fid, name: fid, placeholder: ph, rows: 3 }).prop('required', required);
        if (ctrlH) $ta.css({ height: 'calc(100% - 24px)', minHeight: '30px' });
        $field.append($ta);
      } else if (type === 'dropdown') {
        var $sel = $('<select />').attr({ id: fid, name: fid }).prop('required', required);
        $sel.append('<option value="">Select…</option>');
        var choices = Array.isArray(settings.choices) ? settings.choices : [];
        choices.forEach(function (c) {
          c = String(c || '').trim();
          if (!c) return;
          $sel.append($('<option />').attr('value', c).text(c));
        });
        $field.append($sel);
      } else if (type === 'checkboxes') {
        var $choicesWrap = $('<div class="ribo-choices" />');
        var c2 = Array.isArray(settings.choices) ? settings.choices : [];
        c2.forEach(function (c) {
          c = String(c || '').trim();
          if (!c) return;
          var $opt = $('<label class="ribo-choice" />');
          $opt.append($('<input type="checkbox" />').attr({ name: fid + '[]', value: c }));
          $opt.append($('<span />').text(c));
          $choicesWrap.append($opt);
        });
        $field.append($choicesWrap);
      } else if (type === 'recaptcha') {
        $field.append($('<div class="ribo-recaptcha" />').attr('style', 'padding:10px;border:1px dashed #dcdcde;border-radius:10px;background:#fbfbfb;color:#646970;').text('reCAPTCHA placeholder (configure validation later)'));
      } else {
        var htmlType = 'text';
        if (type === 'email') htmlType = 'email';
        else if (type === 'phone') htmlType = 'tel';
        else if (type === 'number') htmlType = 'number';
        else if (type === 'date') htmlType = 'date';
        else if (type === 'file') htmlType = 'url';

        var $in = $('<input />').attr({ id: fid, name: fid, type: htmlType, placeholder: ph }).prop('required', required);
        if (ctrlH) $in.css({ height: 'calc(100% - 24px)', minHeight: '20px' });
        $field.append($in);
      }

      $field.append($('<div class="ribo-error" />').attr('data-error-for', fid));
      $stage.append($field);
    });

    $stage.css('min-height', Math.max(300, stageHeight + 20) + 'px');
    $form.append($stage);
    $form.append($('<button type="submit" class="ribo-submit" />').text(submitText));
    $form.append($('<div class="ribo-status" aria-live="polite" />').text('Preview mode: submissions are disabled.'));

    $wrap.append($form);
    return $wrap;
  }


  function setPreviewMode(on) {
    PREVIEW_MODE = !!on;

    var $page = $('.ribo-builder-page');
    $page.toggleClass('ribo-preview-mode', PREVIEW_MODE);

    var $btn = $('#riboTogglePreview');
    $btn.text(PREVIEW_MODE ? 'Exit Preview' : 'Preview Mode');
    $btn.attr('aria-pressed', PREVIEW_MODE ? 'true' : 'false');

    if (PREVIEW_MODE) {
      // Ensure JSON is up to date before rendering preview.
      syncHiddenJSON();

      // Hide builder canvas list and show preview host
      $('#riboCanvasEmpty').hide();
      $('#riboCanvasList').hide();

      var $host = $('#riboPreviewHost');
      $host.empty().append(buildPreviewDOM(SCHEMA)).show();

      // Disable canvas interactions while previewing.
      try { $('#riboCanvasList').sortable('disable'); } catch (e) { }
    } else {
      $('#riboPreviewHost').hide().empty();
      $('#riboCanvasList').show();

      // Re-render to restore sortable/resizable UI.
      renderCanvas(SCHEMA, SELECTED);
      renderOptions();
      syncHiddenJSON();
    }
  }

  function syncHiddenJSON() {
    // keep schema stable
    SCHEMA.id = $('#riboFormId').val();
    SCHEMA.name = $('#riboFormName').val();
    SCHEMA.status = $('#riboFormStatus').val();
    SCHEMA.ui = SCHEMA.ui && typeof SCHEMA.ui === 'object' ? SCHEMA.ui : {};
    var metrics = getCanvasMetrics();
    SCHEMA.ui.canvas_width = Math.round(metrics.width * 10) / 10;
    var canvasMinHeight = parseFloat(metrics.$list.css('min-height')) || metrics.$list.outerHeight() || 0;
    if (!isNaN(canvasMinHeight) && canvasMinHeight > 0) {
      SCHEMA.ui.canvas_height = Math.round(canvasMinHeight * 10) / 10;
    }

    syncPayloadMappings();
    var raw = JSON.stringify(SCHEMA, null, 2);
    $('#riboSchemaJson').val(raw);
    $('#riboSchemaPreview').text(raw);

    // Track unsaved changes: if current raw differs from the last saved raw,
    // mark the builder as dirty so we can warn before navigating away.
    if (LAST_SAVED_RAW !== null) {
      IS_DIRTY = (raw !== LAST_SAVED_RAW);
    }
  }

  function selectField(fieldId, opts) {
    if (PREVIEW_MODE) return;
    opts = opts || {};
    SELECTED = fieldId;

    if (opts.skipCanvasRender) {
      $('#riboCanvasList .ribo-canvas-item').removeClass('is-selected');
      if (fieldId) {
        $('#riboCanvasList .ribo-canvas-item[data-id="' + fieldId + '"]').addClass('is-selected');
      }
    } else {
      renderCanvas(SCHEMA, SELECTED);
    }

    renderOptions();
  }

  function removeField(fieldId) {
    if (!confirm('Delete this field?')) return;
    SCHEMA.fields = SCHEMA.fields.filter(function (f) { return f.id !== fieldId; });
    if (SELECTED === fieldId) SELECTED = null;
    renderCanvas(SCHEMA, SELECTED);
    renderOptions();
    syncHiddenJSON();
  }

  function duplicateField(fieldId) {
    var idx = SCHEMA.fields.findIndex(function (f) { return f.id === fieldId; });
    if (idx === -1) return;
    var copy = deepClone(SCHEMA.fields[idx]);
    copy.id = uid('fld');
    copy.label = (copy.label || 'Field') + ' (copy)';
    copy.settings = copy.settings && typeof copy.settings === 'object' ? copy.settings : {};
    if (typeof copy.settings.canvas_x === 'number') copy.settings.canvas_x += 20;
    if (typeof copy.settings.canvas_y === 'number') copy.settings.canvas_y += 20;
    SCHEMA.fields.splice(idx + 1, 0, copy);
    syncPayloadFieldMapping(copy);
    SELECTED = copy.id;
    renderCanvas(SCHEMA, SELECTED);
    renderOptions();
    syncHiddenJSON();
  }

  function upsertMapping(fieldId, mappedKey) {
    if (!fieldId) return;
    if (!mappedKey) {
      delete SCHEMA.mapping[fieldId];
      return;
    }
    SCHEMA.mapping[fieldId] = mappedKey;
  }

  function renderOptions() {
    var $panel = $('#riboOptionsBody');
    $panel.empty();

    if (!SELECTED) {
      $panel.append('<p class="description">Select a field in the canvas to edit its options.</p>');
      return;
    }

    var f = SCHEMA.fields.find(function (x) { return x.id === SELECTED; });
    if (!f) {
      SELECTED = null;
      $panel.append('<p class="description">Select a field in the canvas to edit its options.</p>');
      return;
    }

    // Base
    syncPayloadFieldMapping(f);
    var mapped = (SCHEMA.mapping && SCHEMA.mapping[f.id]) ? SCHEMA.mapping[f.id] : '';
    var fixedPayloadKey = (f.settings && f.settings.payload_key) ? String(f.settings.payload_key) : '';

    var $label = $('<div class="ribo-form-row" />');
    $label.append('<label>Label</label>');
    $label.append($('<input type="text" />').val(f.label || '').on('input', function () {
      f.label = $(this).val();
      renderCanvas(SCHEMA, SELECTED);
      syncHiddenJSON();
    }));

    var $fid = $('<div class="ribo-form-row" />');
    $fid.append('<label>Field ID</label>');
    $fid.append($('<input type="text" readonly />').val(f.id));
    $fid.append('<p class="description ribo-muted">Used as the submission key before mapping.</p>');

    var $req = $('<div class="ribo-form-row" />');
    var $reqInp = $('<label><input type="checkbox" /> Required</label>');
    $reqInp.find('input').prop('checked', !!f.required).on('change', function () {
      f.required = $(this).is(':checked');
      renderCanvas(SCHEMA, SELECTED);
      syncHiddenJSON();
    });
    $req.append($reqInp);

    var $ph = $('<div class="ribo-form-row" />');
    $ph.append('<label>Placeholder</label>');
    $ph.append($('<input type="text" />').val((f.settings && f.settings.placeholder) ? f.settings.placeholder : '').on('input', function () {
      f.settings = f.settings || {};
      f.settings.placeholder = $(this).val();
      syncHiddenJSON();
    }));

    var $map = $('<div class="ribo-form-row" />');
    $map.append('<label>Payload Key</label>');
    if (fixedPayloadKey) {
      $map.append($('<input type="text" readonly />').val(fixedPayloadKey));
      $map.append('<p class="description ribo-muted">This field is always submitted as payload.' + escapeHtml(fixedPayloadKey) + '.</p>');
    } else {
      $map.append($('<input type="text" />').val(mapped).on('input', function () {
        upsertMapping(f.id, $(this).val());
        syncHiddenJSON();
      }));
      $map.append('<p class="description ribo-muted">Example: email, phone, company, notes</p>');
    }

    $panel.append($label, $fid, $req);

    // Layout / width
    var $w = $('<div class="ribo-form-row" />');
    $w.append('<label>Field width (1–12)</label>');

    var curW = (f && f.width) ? parseFloat(f.width) : 12;
    if (isNaN(curW) || curW < 1 || curW > 12) curW = 12;
    curW = Math.round(curW * 10) / 10;

    var $wInp = $('<input type="number" min="1" max="12" step="0.1" />').val(String(curW));
    $wInp.on('input', function () {
      var v = parseFloat($(this).val());
      if (isNaN(v)) v = 12;
      if (v < 1) v = 1;
      if (v > 12) v = 12;
      v = Math.round(v * 10) / 10;
      f.width = v;
      renderCanvas(SCHEMA, SELECTED);
      syncHiddenJSON();
    });

    $w.append($wInp);
    $w.append('<p class="description ribo-muted">Set the field span in a 12‑column grid (12 = full width). You can also resize by dragging any edge or corner of the field card.</p>');
    $panel.append($w);

    // Height
    if (f.type !== 'hidden' && f.type !== 'recaptcha') {
      var $h = $('<div class="ribo-form-row" />');
      $h.append('<label>Field height (px)</label>');
      var curH = '';
      if (f.settings && (f.settings.height || f.settings.box_height)) {
        var hh = parseFloat(f.settings.height || f.settings.box_height);
        if (!isNaN(hh) && hh > 0) curH = String(hh);
      }
      var $hInp = $('<input type="number" min="24" step="0.1" placeholder="Auto" />').val(curH);
      $hInp.on('input', function () {
        f.settings = f.settings || {};
        var v = String($(this).val() || '').trim();
        if (!v) {
          delete f.settings.height;
          delete f.settings.box_height;
        } else {
          var nv = parseFloat(v);
          if (!isNaN(nv) && nv > 0) {
            nv = Math.round(nv * 10) / 10;
            f.settings.height = nv;
            f.settings.box_height = nv;
          }
        }
        renderCanvas(SCHEMA, SELECTED);
        syncHiddenJSON();
      });
      $h.append($hInp);
      $h.append('<p class="description ribo-muted">Also applied on the published form. For Checkboxes, this becomes the choices list max-height (scrollable).</p>');
      $panel.append($h);
    }

    // Type-specific
    if (f.type === 'dropdown' || f.type === 'checkboxes') {
      var $choices = $('<div class="ribo-form-row ribo-choices-editor" />');
      $choices.append('<label>Choices (one per line)</label>');
      var current = (f.settings && Array.isArray(f.settings.choices)) ? f.settings.choices.join('\n') : '';
      $choices.append($('<textarea rows="6"></textarea>').val(current).on('input', function () {
        f.settings = f.settings || {};
        var lines = $(this).val().split(/\r?\n/).map(function (x) { return x.trim(); }).filter(Boolean);
        f.settings.choices = lines;
        syncHiddenJSON();
      }));
      $panel.append($choices);
    }

    if (f.type === 'hidden') {
      var $dv = $('<div class="ribo-form-row" />');
      $dv.append('<label>Default value</label>');
      $dv.append($('<input type="text" />').val((f.settings && f.settings.default_value) ? f.settings.default_value : '').on('input', function () {
        f.settings = f.settings || {};
        f.settings.default_value = $(this).val();
        syncHiddenJSON();
      }));
      $panel.append($dv);
    }

    if (f.type === 'recaptcha') {
      var $rk = $('<div class="ribo-form-row" />');
      $rk.append('<label>Site key</label>');
      $rk.append($('<input type="text" />').val((f.settings && f.settings.site_key) ? f.settings.site_key : '').on('input', function () {
        f.settings = f.settings || {};
        f.settings.site_key = $(this).val();
        syncHiddenJSON();
      }));

      var $rv = $('<div class="ribo-form-row" />');
      $rv.append('<label>Version</label>');
      var $sel = $('<select><option value="v2">v2</option><option value="v3">v3</option></select>');
      $sel.val((f.settings && f.settings.version) ? f.settings.version : 'v2');
      $sel.on('change', function () {
        f.settings = f.settings || {};
        f.settings.version = $(this).val();
        syncHiddenJSON();
      });
      $rv.append($sel);
      $rv.append('<p class="description ribo-muted">Placeholder in v1; validation can be added later.</p>');
      $panel.append($rk, $rv);
    }

    // Placeholder only for types that render inputs
    if (['text', 'textarea', 'email', 'phone', 'number', 'date', 'file'].indexOf(f.type) !== -1) {
      $panel.append($ph);
    }

    $panel.append($map);
  }

  function addField(type) {
    var f = defaultFieldFor(type);
    // ensure unique id
    while (SCHEMA.fields.some(function (x) { return x.id === f.id; })) {
      f.id = uid('fld');
    }
    SCHEMA.fields.push(f);
    syncPayloadFieldMapping(f);
    SELECTED = f.id;
    renderCanvas(SCHEMA, SELECTED);
    renderOptions();
    syncHiddenJSON();
  }

  function addFieldAt(type, index, canvasPos) {
    if (!type) return;
    var f = defaultFieldFor(type);
    while (SCHEMA.fields.some(function (x) { return x.id === f.id; })) {
      f.id = uid('fld');
    }
    f.settings = f.settings || {};
    if (canvasPos && typeof canvasPos.left === 'number' && typeof canvasPos.top === 'number') {
      f.settings.canvas_x = Math.max(0, Math.round(canvasPos.left * 10) / 10);
      f.settings.canvas_y = Math.max(0, Math.round(canvasPos.top * 10) / 10);
    }
    if (typeof index !== 'number' || index < 0) index = SCHEMA.fields.length;
    if (index > SCHEMA.fields.length) index = SCHEMA.fields.length;
    SCHEMA.fields.splice(index, 0, f);
    syncPayloadFieldMapping(f);
    SELECTED = f.id;
    renderCanvas(SCHEMA, SELECTED);
    renderOptions();
    syncHiddenJSON();
  }

  function initCanvasDroppable($target) {
    if (!$target || !$target.length) return;
    try { if ($target.data('ui-droppable')) { $target.droppable('destroy'); } } catch (e) { }
    $target.droppable({
      accept: '.ribo-field-item',
      hoverClass: 'ribo-drop-hover',
      tolerance: 'pointer',
      drop: function (event, ui) {
        var type = ui.draggable.data('preset') || ui.helper.attr('data-preset') || ui.draggable.attr('data-preset') || ui.draggable.data('type') || ui.helper.attr('data-type') || ui.draggable.attr('data-type');
        var $this = $(this);
        var off = $this.offset() || { left: 0, top: 0 };
        var helperW = ui.helper ? ui.helper.outerWidth() : 160;
        var helperH = ui.helper ? ui.helper.outerHeight() : 72;
        var left = (event.pageX - off.left) - (helperW / 2);
        var top = (event.pageY - off.top) - (helperH / 2);
        addFieldAt(type, SCHEMA.fields.length, { left: left, top: top });
        $('#riboCanvasEmpty').hide();
      }
    });
  }

  function bindPalette() {
    $(document).on('dblclick', '.ribo-field-item', function () {
      addField($(this).data('preset') || $(this).data('type'));
    });

    initPaletteDraggable($('.ribo-field-item'));
    initCanvasDroppable($('#riboCanvasList'));
    initCanvasDroppable($('#riboCanvasEmpty'));
  }


  // IMPORTANT: In the Form Builder, the live title/pill must stay single-line.
  // Long names may visually overflow/ellipsis via CSS, but we must NOT insert
  // manual line breaks (the Forms tab keeps its own wrapping behavior).
  function formatBuilderTitle(value) {
    value = String(value || '').trim();
    if (!value) {
      return 'Untitled Form';
    }
    return value;
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function bindTopFields() {
    $('#riboFormName').on('input', function () {
      var value = $(this).val() || '';
      if (value.length > 40) {
        value = value.substring(0, 40);
        $(this).val(value);
      }
      syncHiddenJSON();
      // Use .text() so the title cannot accidentally render HTML and stays
      // single-line; overflow handling is done via CSS.
      $('#riboFormTitle').text(formatBuilderTitle(value || 'Untitled Form'));
    });

    $('#riboFormStatus').on('change', function () {
      syncHiddenJSON();
      var st = $(this).val();
      $('#riboShortcodeHint').toggle(st === 'published');
    });

    $('#riboSubmitText').on('input', function () {
      SCHEMA.ui = SCHEMA.ui || {};
      SCHEMA.ui.submit_text = $(this).val();
      syncHiddenJSON();
    });
  }

  function initFromDOM() {
    // Ensure schema has correct identity
    SCHEMA.id = $('#riboFormId').val();
    SCHEMA.name = $('#riboFormName').val();
    SCHEMA.status = $('#riboFormStatus').val();

    // submit button
    if (!SCHEMA.ui) SCHEMA.ui = { submit_text: 'Send' };
    if (!SCHEMA.ui.submit_text) SCHEMA.ui.submit_text = 'Send';
    $('#riboSubmitText').val(SCHEMA.ui.submit_text);

    // mapping object safety
    if (!SCHEMA.mapping) SCHEMA.mapping = {};
    syncPayloadMappings();

    renderCanvas(SCHEMA, SELECTED);
    renderOptions();
    syncHiddenJSON();
    // Set the baseline 'saved' schema for dirty tracking.
    LAST_SAVED_RAW = $('#riboSchemaJson').val();
    IS_DIRTY = false;

    // Show shortcode hint if published
    $('#riboShortcodeHint').toggle($('#riboFormStatus').val() === 'published');
  }

  $(function () {
    bindPalette();
    bindTopFields();
    initFromDOM();

    // Warn before leaving the builder if there are unsaved changes.
    window.addEventListener('beforeunload', function (e) {
      if (IS_SAVING) return;
      if (!IS_DIRTY) return;
      e.preventDefault();
      e.returnValue = 'You have unsaved changes. If you leave, your changes will be lost.';
      return e.returnValue;
    });

    // Confirm when clicking top navigation tabs while the builder has unsaved changes.
    $(document).on('click', 'a.ribo-tab', function (e) {
      if (IS_SAVING) return;
      if (!IS_DIRTY) return;
      // Allow clicks on the currently active tab (no navigation).
      if ($(this).hasClass('is-active')) return;
      var ok = window.confirm('You have unsaved changes in the Form Builder. Leave without saving?');
      if (!ok) {
        e.preventDefault();
        e.stopPropagation();
        return false;
      }
    });

    // On form submit, always sync JSON
    $('#riboBuilderForm').on('submit', function () {
      var formName = ($.trim($('#riboFormName').val() || ''));
      if (formName.length > 40) {
        window.alert('Form creation failed. Form name must be 40 characters or less.');
        $('#riboFormName').focus();
        return false;
      }

      IS_SAVING = true;
      syncHiddenJSON();
      // Prevent the unsaved-changes prompt during a legitimate save navigation.
      LAST_SAVED_RAW = $('#riboSchemaJson').val();
      IS_DIRTY = false;
    });

    // Toggle raw JSON preview
    $('#riboToggleJson').on('click', function () {
      $('#riboRawJsonWrap').toggle();
    });

    // Preview mode toggle
    $('#riboTogglePreview').on('click', function () {
      setPreviewMode(!PREVIEW_MODE);
    });
  });

})(jQuery);
