/**
 * RIBO – Elementor Interactive Canvas
 *
 * Provides a "True Builder" experience with:
 *   • Draggable field library (Tray) inside the iframe
 *   • Canva-style drag-and-resize for form fields
 *   • Quick-Edit settings popover on the canvas
 *   • Visual deletions and duplications
 */
(function ($) {
    'use strict';

    /* ── Config ─────────────────────────────────────────────────────────── */
    var CFG = window.RIBO_EL_CANVAS || {};
    var REST_BASE = CFG.rest_base || '';
    var NONCE = CFG.nonce || '';

    /* ── State ──────────────────────────────────────────────────────────── */
    var activeFormId = null;
    var $activeStage = null;
    var $library = null;
    var $settingsPopover = null;

    /* ── Helpers ────────────────────────────────────────────────────────── */
    function px(v) { return parseFloat(v) || 0; }
    function stageW() { return $activeStage ? $activeStage.outerWidth() : 720; }

    function showToast(msg, color) {
        var $t = $('#ribo-el-save-toast');
        if (!$t.length) $t = $('<div id="ribo-el-save-toast"></div>').appendTo('body');
        $t.text(msg).css('background', color || '#2ecc71').addClass('ribo-el-toast-show');
        setTimeout(function () { $t.removeClass('ribo-el-toast-show'); }, 2000);
    }

    /* ── Field Library (Tray) ────────────────────────────────────────────── */
    var FIELD_TYPES = [
        { type: 'text', label: 'Short Text', icon: 'eicon-text-field' },
        { type: 'email', label: 'Email Address', icon: 'eicon-mail-field' },
        { type: 'phone', label: 'Phone Number', icon: 'eicon-tel-field' },
        { type: 'textarea', label: 'Long Text', icon: 'eicon-textarea' },
        { type: 'dropdown', label: 'Dropdown', icon: 'eicon-select' },
        { type: 'checkboxes', label: 'Checkboxes', icon: 'eicon-checkbox' },
        { type: 'radio', label: 'Radio Buttons', icon: 'eicon-radio' },
        { type: 'number', label: 'Number', icon: 'eicon-number-field' },
        { type: 'date', label: 'Date Picker', icon: 'eicon-calendar-o' },
        { type: 'hidden', label: 'Hidden Field', icon: 'eicon-hidden' }
    ];

    function createLibrary() {
        if ($library) return;
        $library = $('<div class="ribo-el-library-tray"><div class="ribo-el-lib-header">RIBO Fields</div><div class="ribo-el-lib-scroll"></div></div>');
        var $scroll = $library.find('.ribo-el-lib-scroll');

        FIELD_TYPES.forEach(function (ft) {
            var $item = $('<div class="ribo-el-lib-item" draggable="true" data-type="' + ft.type + '">' +
                '<i class="' + ft.icon + '"></i><span>' + ft.label + '</span>' +
                '</div>');

            $item.on('dragstart', function (e) {
                e.originalEvent.dataTransfer.setData('ribo-type', ft.type);
                $(this).addClass('is-dragging');
            }).on('dragend', function () {
                $(this).removeClass('is-dragging');
            }).on('click', function () {
                // Click to add at bottom
                addField(ft.type, 0, 9999);
            });

            $scroll.append($item);
        });

        $('body').append($library);
    }

    /* ── REST Sync ──────────────────────────────────────────────────────── */
    function syncToDB(payload, callback) {
        if (!activeFormId || !REST_BASE) return;
        $.ajax({
            url: REST_BASE + encodeURIComponent(activeFormId) + '/update-layout',
            method: 'POST',
            contentType: 'application/json',
            beforeSend: function (xhr) { if (NONCE) xhr.setRequestHeader('X-WP-Nonce', NONCE); },
            data: JSON.stringify(payload),
            success: function (resp) {
                if (resp && resp.ok) showToast('Saved ✓');
                if (callback) callback(resp);
                // Trigger Elementor panel sync
                if (window.parent && window.parent.elementor) {
                    // This triggers the elementor-editor.js sync
                    window.parent.jQuery(window.parent.document).trigger('ribo:form:updated');
                }
            },
            error: function () { showToast('Sync failed', '#e74c3c'); }
        });
    }

    function saveCurrentLayout() {
        var sw = stageW();
        var fields = [];
        $activeStage.find('.ribo-field[data-field-id]').each(function () {
            var $f = $(this);
            fields.push({
                id: String($f.data('field-id')),
                canvas_x: px($f.css('left')),
                canvas_y: px($f.css('top')),
                width: ($f.outerWidth() / sw) * 12,
                height: $f.outerHeight()
            });
        });
        syncToDB({ fields: fields });
    }

    /* ── Actions ─────────────────────────────────────────────────────────── */
    function addField(type, x, y) {
        var id = 'fld_new_' + Math.random().toString(36).substr(2, 9);
        var sw = stageW();
        var payload = {
            fields: [{
                id: id,
                type: type,
                label: 'New ' + type.charAt(0).toUpperCase() + type.slice(1),
                canvas_x: x,
                canvas_y: y,
                width: 12
            }]
        };
        syncToDB(payload, function () {
            // Hard refresh of the preview to show the new field
            if (window.parent && window.parent.elementor) {
                var model = window.parent.elementor.panel.currentView.model;
                if (model) {
                    model.setSetting('_ribo_refresh', Math.random());
                }
            }
        });
    }

    function deleteField(fieldId) {
        if (!confirm('Remove this field?')) return;
        syncToDB({ delete_ids: [fieldId] }, function () {
            $activeStage.find('[data-field-id="' + fieldId + '"]').fadeOut(200, function () {
                $(this).remove();
                hideSettings();
            });
        });
    }

    function updateFieldSettings(fieldId, settings) {
        var payload = {
            fields: [$.extend({ id: fieldId }, settings)]
        };
        syncToDB(payload);
    }

    /* ── Settings Popover ────────────────────────────────────────────────── */
    function showSettings($f) {
        var fid = $f.data('field-id');
        var label = $f.find('label').first().text().replace('*', '').trim();
        var ph = $f.find('input, textarea').attr('placeholder') || '';
        var isReq = $f.find('.ribo-req').length > 0;

        if (!$settingsPopover) {
            $settingsPopover = $('<div class="ribo-el-settings-bubble">' +
                '<div class="ribo-el-set-row"><label>Label</label><input type="text" class="set-label"></div>' +
                '<div class="ribo-el-set-row"><label>Placeholder</label><input type="text" class="set-ph"></div>' +
                '<div class="ribo-el-set-row is-flex"><label><input type="checkbox" class="set-req"> Required</label>' +
                '<button class="ribo-el-del-btn" title="Delete Field"><i class="eicon-trash"></i></button></div>' +
                '<div class="ribo-el-set-arrow"></div>' +
                '</div>').appendTo('body');
        }

        $settingsPopover.find('.set-label').val(label).off('change').on('change', function () {
            var val = $(this).val();
            $f.find('label').first().contents().first()[0].textContent = val;
            updateFieldSettings(fid, { label: val });
        });

        $settingsPopover.find('.set-ph').val(ph).off('change').on('change', function () {
            var val = $(this).val();
            $f.find('input, textarea').attr('placeholder', val);
            updateFieldSettings(fid, { placeholder: val });
        });

        $settingsPopover.find('.set-req').prop('checked', isReq).off('change').on('change', function () {
            var val = $(this).is(':checked');
            if (val) {
                if (!$f.find('.ribo-req').length) $f.find('label').append(' <span class="ribo-req">*</span>');
            } else {
                $f.find('.ribo-req').remove();
            }
            updateFieldSettings(fid, { required: val });
        });

        $settingsPopover.find('.ribo-el-del-btn').off('click').on('click', function () {
            deleteField(fid);
        });

        positionSettings($f);
        $settingsPopover.addClass('is-open');
    }

    function positionSettings($f) {
        if (!$settingsPopover || !$settingsPopover.hasClass('is-open')) return;
        var fOff = $f.offset();
        $settingsPopover.css({
            top: fOff.top - $settingsPopover.outerHeight() - 15,
            left: fOff.left + ($f.outerWidth() / 2) - ($settingsPopover.outerWidth() / 2)
        });
    }

    function hideSettings() {
        if ($settingsPopover) $settingsPopover.removeClass('is-open');
    }

    /* ── Canvas Initialisation ───────────────────────────────────────────── */
    function initField($f, $stage, formId) {
        if ($f.data('ribo-init')) return;
        $f.data('ribo-init', true);

        // Add handles if missing
        if (!$f.find('.ribo-el-drag-handle').length) $f.prepend('<div class="ribo-el-drag-handle" title="Drag to move">⠿⠿⠿</div>');
        if (!$f.find('.ribo-el-rh').length) {
            $f.append('<div class="ribo-el-rh ribo-el-rh-se"></div><div class="ribo-el-rh ribo-el-rh-e"></div><div class="ribo-el-rh ribo-el-rh-s"></div>');
        }

        // Draggable
        try {
            $f.draggable({
                handle: '.ribo-el-drag-handle',
                containment: $stage,
                start: function () {
                    $stage.find('.ribo-field').removeClass('ribo-el-selected');
                    $f.addClass('ribo-el-dragging ribo-el-selected');
                    hideSettings();
                },
                stop: function () {
                    $f.removeClass('ribo-el-dragging');
                    saveCurrentLayout();
                    showSettings($f);
                }
            });
        } catch (e) { }

        // Resizable
        try {
            $f.resizable({
                containment: $stage,
                handles: { se: '.ribo-el-rh-se', e: '.ribo-el-rh-e', s: '.ribo-el-rh-s' },
                minWidth: 80, minHeight: 40,
                start: function () {
                    $f.addClass('ribo-el-resizing ribo-el-selected');
                    $f.draggable('disable');
                    hideSettings();
                },
                stop: function () {
                    $f.removeClass('ribo-el-resizing');
                    $f.draggable('enable');
                    saveCurrentLayout();
                    showSettings($f);
                }
            });
        } catch (e) { }

        $f.on('mousedown', function (e) {
            e.stopPropagation();
            if (!$f.hasClass('ribo-el-selected')) {
                $stage.find('.ribo-field').removeClass('ribo-el-selected');
                $f.addClass('ribo-el-selected');
                showSettings($f);
            }
        });
    }

    function initForm($wrap) {
        if ($wrap.data('ribo-ready')) return;
        $wrap.data('ribo-ready', true);

        activeFormId = String($wrap.data('form-id'));
        $activeStage = $wrap.find('.ribo-form-stage');

        createLibrary();

        $activeStage.on('dragover', function (e) {
            e.preventDefault();
            $(this).addClass('drag-over');
        }).on('dragleave', function () {
            $(this).removeClass('drag-over');
        }).on('drop', function (e) {
            e.preventDefault();
            $(this).removeClass('drag-over');
            var type = e.originalEvent.dataTransfer.getData('ribo-type');
            if (type) {
                var offset = $(this).offset();
                var x = e.originalEvent.pageX - offset.left;
                var y = e.originalEvent.pageY - offset.top;
                addField(type, x, y);
            }
        });

        $activeStage.find('.ribo-field').each(function () {
            initField($(this), $activeStage, activeFormId);
        });

        // Global click to deselect
        $(document).on('mousedown', function (e) {
            if (!$(e.target).closest('.ribo-field, .ribo-el-settings-bubble, .ribo-el-library-tray').length) {
                $('.ribo-field').removeClass('ribo-el-selected');
                hideSettings();
            }
        });
    }

    /* ── Bootstrap ───────────────────────────────────────────────────────── */
    function hookElementor() {
        if (!window.elementorFrontend) return false;
        window.elementorFrontend.hooks.addAction('frontend/element_ready/ribo_form_widget.default', function ($scope) {
            var $wrap = $scope.find('.ribo-el-interactive .ribo-form-wrap');
            if ($wrap.length) initForm($wrap);
        });
        return true;
    }

    if (!hookElementor()) {
        $(window).on('elementor/frontend/init', hookElementor);
    }
    $(document).ready(function () {
        setTimeout(function () {
            $('.ribo-el-interactive .ribo-form-wrap').each(function () { initForm($(this)); });
        }, 500);
    });

})(jQuery);
