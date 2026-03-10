/**
 * RIBO – Elementor Interactive Canvas
 *
 * Provides Canva-style drag-and-drop + resize for form fields rendered
 * inside the Elementor editor preview iframe.
 *
 * Works for both:
 *   • Existing / saved form mode (data-form-id from DB)
 *   • Manual form builder mode (form-id synced to DB by widget render)
 *
 * Positions are stored in the DB via the /update-layout REST endpoint.
 * On next Elementor preview refresh the updated positions are read back.
 */
(function ($) {
    'use strict';

    /* ── Config (injected by wp_localize_script) ─────────────────────────── */
    var CFG = window.RIBO_EL_CANVAS || {};
    var REST_BASE = CFG.rest_base || ''; // e.g. https://site.com/wp-json/ribo/v1/forms/
    var NONCE = CFG.nonce || '';

    /* ── Toast helper ────────────────────────────────────────────────────── */
    var $toast = null;
    var toastTimer = null;

    function showToast(msg, color) {
        if (!$toast) {
            $toast = $('<div id="ribo-el-save-toast"></div>').appendTo('body');
        }
        $toast.text(msg || 'Saved').css('background', color || '#2ecc71');
        clearTimeout(toastTimer);
        $toast.addClass('ribo-el-toast-show');
        toastTimer = setTimeout(function () { $toast.removeClass('ribo-el-toast-show'); }, 2000);
    }

    /* ── Pixel helper ────────────────────────────────────────────────────── */
    function px(v) { return parseFloat(v) || 0; }

    /* ── Get stage pixel width ───────────────────────────────────────────── */
    function stageW($stage) {
        return Math.max(200, $stage.outerWidth() || $stage.width() || 200);
    }

    /* ── Collect current layout of all fields inside a stage ─────────────── */
    function collectLayout($stage) {
        var sw = stageW($stage);
        var rows = [];

        $stage.find('.ribo-field[data-field-id]').each(function () {
            var $f = $(this);
            var fid = String($f.data('field-id') || '').trim();
            if (!fid) return;

            var leftPx = px($f.css('left'));
            var topPx = px($f.css('top'));
            var wPx = $f.outerWidth();
            var hPx = $f.outerHeight();

            /* Convert pixel width → 1-12 column span relative to stage width */
            var rawCols = (wPx / sw) * 12;
            var colSpan = Math.max(1, Math.min(12, Math.round(rawCols * 10) / 10));

            rows.push({
                id: fid,
                canvas_x: Math.round(leftPx * 10) / 10,
                canvas_y: Math.round(topPx * 10) / 10,
                width: colSpan,
                height: Math.round(hPx * 10) / 10
            });
        });

        return rows;
    }

    /* ── Persist layout to DB via REST ──────────────────────────────────── */
    function saveLayout($stage, formId) {
        if (!formId || !REST_BASE) return;
        var fields = collectLayout($stage);
        if (!fields.length) return;

        $.ajax({
            url: REST_BASE + encodeURIComponent(formId) + '/update-layout',
            method: 'POST',
            contentType: 'application/json',
            beforeSend: function (xhr) { if (NONCE) xhr.setRequestHeader('X-WP-Nonce', NONCE); },
            data: JSON.stringify({ fields: fields }),
            success: function () { showToast('Layout saved ✓'); },
            error: function () { showToast('Save failed', '#e74c3c'); }
        });
    }

    /* ── Refresh the stage min-height so fields don't overflow ──────────── */
    function refreshStageHeight($stage) {
        var max = 200;
        $stage.find('.ribo-field').each(function () {
            var $f = $(this);
            var bot = px($f.css('top')) + $f.outerHeight();
            if (bot > max) max = bot;
        });
        $stage.css('min-height', (max + 40) + 'px');
    }

    /* ── Initialise one form wrap ─────────────────────────────────────────── */
    function initForm($wrap) {
        /* Only run once per wrap element */
        if ($wrap.data('ribo-canvas-ready')) return;
        $wrap.data('ribo-canvas-ready', true);

        var formId = String($wrap.data('form-id') || '').trim();
        if (!formId) {
            $wrap.removeData('ribo-canvas-ready');
            return;
        }

        var $stage = $wrap.find('.ribo-form-stage');
        if (!$stage.length) {
            $wrap.removeData('ribo-canvas-ready');
            return;
        }

        /* Make sure the stage doesn't clip dragged fields */
        $stage.css({ position: 'relative', overflow: 'visible' });

        /* ── Loop over every field ─────────────────────────────────────────── */
        $stage.find('.ribo-field[data-field-id]').each(function () {
            var $f = $(this);
            var fid = $f.data('field-id');

            /* Ensure absolute positioning (set by shortcode but belt-and-suspenders) */
            $f.css({ position: 'absolute', 'box-sizing': 'border-box' });

            /* ── Drag-handle bar at top ───────────────────────────────────── */
            if (!$f.find('.ribo-el-drag-handle').length) {
                $f.prepend('<div class="ribo-el-drag-handle" title="Drag to move">⠿⠿⠿</div>');
            }

            /* ── Resize handles (SE corner, E edge, S edge) ──────────────── */
            if (!$f.find('.ribo-el-rh').length) {
                $f.append('<div class="ribo-el-rh ribo-el-rh-se" title="Resize"></div>');
                $f.append('<div class="ribo-el-rh ribo-el-rh-e"  title="Resize width"></div>');
                $f.append('<div class="ribo-el-rh ribo-el-rh-s"  title="Resize height"></div>');
            }

            /* ── jQuery UI Draggable ──────────────────────────────────────── */
            try {
                if ($f.data('ui-draggable')) { try { $f.draggable('destroy'); } catch (e) { } }

                $f.draggable({
                    handle: '.ribo-el-drag-handle',
                    containment: $stage,
                    cursor: 'move',
                    distance: 2,
                    scroll: true,
                    start: function (e) {
                        e.stopPropagation();
                        $stage.find('.ribo-field').removeClass('ribo-el-selected');
                        $f.addClass('ribo-el-dragging ribo-el-selected');
                    },
                    drag: function (e) {
                        e.stopPropagation();
                        refreshStageHeight($stage);
                    },
                    stop: function (e) {
                        e.stopPropagation();
                        $f.removeClass('ribo-el-dragging');
                        refreshStageHeight($stage);
                        saveLayout($stage, formId);
                    }
                });
            } catch (err) {
                /* jQuery UI not available – fail silently */
                console.warn('RIBO Canvas: draggable init failed for field', fid, err);
            }

            /* ── jQuery UI Resizable ─────────────────────────────────────── */
            try {
                if ($f.data('ui-resizable')) { try { $f.resizable('destroy'); } catch (e) { } }

                $f.resizable({
                    containment: $stage,
                    handles: {
                        se: '.ribo-el-rh-se',
                        e: '.ribo-el-rh-e',
                        s: '.ribo-el-rh-s'
                    },
                    minWidth: 80,
                    minHeight: 50,
                    start: function (e) {
                        e.stopPropagation();
                        $stage.find('.ribo-field').removeClass('ribo-el-selected');
                        $f.addClass('ribo-el-resizing ribo-el-selected');
                        /* Temporarily disable draggable so resize handles don't become drag handles */
                        try { $f.draggable('disable'); } catch (ex) { }
                    },
                    resize: function (e) {
                        e.stopPropagation();
                        refreshStageHeight($stage);
                    },
                    stop: function (e) {
                        e.stopPropagation();
                        $f.removeClass('ribo-el-resizing');
                        refreshStageHeight($stage);
                        saveLayout($stage, formId);
                        try { $f.draggable('enable'); } catch (ex) { }
                    }
                });
            } catch (err) {
                console.warn('RIBO Canvas: resizable init failed for field', fid, err);
            }

            /* ── Click-to-select (not drag) ──────────────────────────────── */
            $f.on('mousedown.riboCanvas', function (e) {
                /* Stop event so Elementor doesn't intercept and lose widget focus */
                e.stopPropagation();
                $stage.find('.ribo-field').removeClass('ribo-el-selected');
                $f.addClass('ribo-el-selected');
            });
        });

        /* Initial stage height */
        refreshStageHeight($stage);
    }

    /* ── Find and initialise all RIBO forms in the document ──────────────── */
    function initAll() {
        /* The interactive wrapper class is added by the widget render function */
        $('.ribo-el-interactive .ribo-form-wrap[data-form-id]').each(function () {
            initForm($(this));
        });
    }

    /* ── Boot ─────────────────────────────────────────────────────────────── */

    /*
     * Elementor fires `elementorFrontend.hooks` inside the preview iframe.
     * Hook into `frontend/element_ready/ribo_form_widget.default` so we init
     * as soon as each RIBO widget is rendered or refreshed by Elementor.
     */
    function hookElementorFrontend() {
        if (!window.elementorFrontend || !window.elementorFrontend.hooks) return false;
        window.elementorFrontend.hooks.addAction(
            'frontend/element_ready/ribo_form_widget.default',
            function ($scope) {
                /* Small delay to let Elementor finish its own DOM mutations */
                setTimeout(function () {
                    $scope.find('.ribo-form-wrap[data-form-id]').each(function () {
                        /* Reset init flag so a refreshed widget re-inits properly */
                        $(this).removeData('ribo-canvas-ready');
                        initForm($(this));
                    });
                }, 100);
            }
        );
        return true;
    }

    /* Try to hook immediately; fall back to the init event */
    if (!hookElementorFrontend()) {
        $(window).on('elementor/frontend/init', function () {
            hookElementorFrontend();
            setTimeout(initAll, 400);
        });
    }

    /* Regular DOMContentLoaded fallback (covers non-Elementor preview usage) */
    $(document).ready(function () {
        /* Give Elementor preview a moment to inject widgets */
        setTimeout(initAll, 500);
    });

}(jQuery));
