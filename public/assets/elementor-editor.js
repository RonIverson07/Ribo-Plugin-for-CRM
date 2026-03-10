/**
 * RIBO – Elementor Editor Panel Sync
 *
 * Runs in the main Elementor editor frame (not the preview iframe).
 * Synchronizes the widget controls (the repeater list) with the RIBO DB
 * when the form is edited elsewhere (e.g. RIBO CRM Form Builder).
 */
(function ($) {
    'use strict';

    if (!window.elementor) return;

    var CFG = window.RIBO_EL_EDITOR || {};
    var REST_BASE = CFG.rest_base || '';
    var NONCE = CFG.nonce || '';

    // Cache to prevent infinite sync loops
    var lastSyncedSchema = {};

    function syncWidgetFromDB(view) {
        var model = view.getEditModel();
        var settings = model.attributes.settings;
        var mode = settings.get('mode');
        var formId = settings.get('internal_ribo_form_id');

        if (mode !== 'manual' || !formId || !REST_BASE) return;

        // Fetch latest schema from DB
        $.ajax({
            url: REST_BASE + encodeURIComponent(formId),
            method: 'GET',
            beforeSend: function (xhr) { if (NONCE) xhr.setRequestHeader('X-WP-Nonce', NONCE); },
            success: function (resp) {
                if (!resp || !resp.schema_json) return;
                var dbSchema = JSON.parse(resp.schema_json);
                if (!dbSchema || !dbSchema.fields) return;

                // Compare and update if needed
                updateRepeater(model, dbSchema);
            }
        });
    }

    function updateRepeater(model, dbSchema) {
        var fields = dbSchema.fields || [];
        var mapping = dbSchema.mapping || {};
        var currentRepeater = model.getSetting('manual_fields').models || [];

        // Convert DB fields to Elementor repeater items
        var newItems = fields.map(function (f, index) {
            var payloadKey = mapping[f.id] || (f.settings && f.settings.payload_key) || '';

            return {
                _id: f.id, // Use stable ID
                field_label: f.label || '',
                field_type: f.type || 'text',
                field_placeholder: (f.settings && f.settings.placeholder) || '',
                field_required: f.required ? 'yes' : '',
                field_width: f.width || 12,
                field_options: (f.settings && f.settings.choices) ? f.settings.choices.join('\n') : '',
                payload_key: payloadKey
            };
        });

        // Simple check to avoid redundant updates
        var currentItemsJson = JSON.stringify(model.getSetting('manual_fields').toJSON());
        var newItemsJson = JSON.stringify(newItems);

        if (currentItemsJson !== newItemsJson) {
            console.log('RIBO: Syncing DB fields to Elementor panel');

            // Set values in Elementor model. 
            // This will automatically refresh the editor panel controls.
            model.setSetting('manual_fields', newItems);

            // Also sync other settings if needed
            if (dbSchema.name && model.getSetting('manual_form_name') !== dbSchema.name) {
                model.setSetting('manual_form_name', dbSchema.name);
            }
            if (dbSchema.ui && dbSchema.ui.submit_text && model.getSetting('manual_submit_text') !== dbSchema.ui.submit_text) {
                model.setSetting('manual_submit_text', dbSchema.ui.submit_text);
            }
        }
    }

    // Hook into the widget panel open event
    elementor.hooks.addAction('panel/open_editor/widget/ribo_form_widget', function (panel, model, view) {
        // Debounce or only sync once per panel open?
        // Let's do it on open.
        setTimeout(function () {
            syncWidgetFromDB(view);
        }, 500);
    });

    // Listen for custom "form updated" events from the iframe or other sources
    $(document).on('ribo:form:updated', function () {
        var activeView = elementor.panel.currentView.view;
        if (activeView && activeView.getEditModel) {
            syncWidgetFromDB(activeView);
        }
    });

})(jQuery);
