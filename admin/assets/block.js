(function (blocks, element, components, blockEditor, i18n) {
  if (!blocks || !element || !components || !blockEditor) {
    return;
  }

  var el = element.createElement;
  var registerBlockType = blocks.registerBlockType;
  var InspectorControls = blockEditor.InspectorControls;
  var PanelBody = components.PanelBody;
  var SelectControl = components.SelectControl;
  var Notice = components.Notice;

  function getOptions() {
    var forms = (window.RIBO_BLOCKS && window.RIBO_BLOCKS.forms) ? window.RIBO_BLOCKS.forms : [];
    var opts = [{ label: 'Select…', value: '' }];
    for (var i = 0; i < forms.length; i++) {
      var f = forms[i] || {};
      opts.push({ label: (f.name || f.id || ''), value: (f.id || '') });
    }
    return opts;
  }

  registerBlockType('ribo-crm/form', {
    title: 'RIBO Form',
    icon: 'feedback',
    category: 'widgets',
    attributes: {
      formId: { type: 'string', default: '' }
    },
    edit: function (props) {
      var attrs = props.attributes || {};
      var formId = attrs.formId || '';
      var forms = (window.RIBO_BLOCKS && window.RIBO_BLOCKS.forms) ? window.RIBO_BLOCKS.forms : [];
      var options = getOptions();

      var selectedName = '';
      for (var i = 0; i < forms.length; i++) {
        if ((forms[i] && forms[i].id) === formId) {
          selectedName = forms[i].name || forms[i].id;
          break;
        }
      }

      return el(
        'div',
        { className: props.className },
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            { title: (i18n && i18n.__) ? i18n.__('RIBO Form', 'ribo-wp-inbound') : 'RIBO Form', initialOpen: true },
            el(SelectControl, {
              label: (i18n && i18n.__) ? i18n.__('Select a form', 'ribo-wp-inbound') : 'Select a form',
              value: formId,
              options: options,
              onChange: function (val) {
                props.setAttributes({ formId: val });
              }
            }),
            (!forms || forms.length === 0)
              ? el(Notice, { status: 'warning', isDismissible: false }, (i18n && i18n.__) ? i18n.__('No published forms found. Publish a form first.', 'ribo-wp-inbound') : 'No published forms found. Publish a form first.')
              : null
          )
        ),
        el(
          'div',
          {
            style: {
              border: '1px solid #dcdcde',
              borderRadius: '10px',
              padding: '14px',
              background: '#fff'
            }
          },
          el('div', { style: { fontWeight: 600, marginBottom: '6px' } }, 'RIBO Form'),
          formId
            ? el('div', null, (i18n && i18n.__) ? i18n.__('Selected form', 'ribo-wp-inbound') : 'Selected form', ': ', selectedName || formId)
            : el('div', { style: { opacity: 0.8 } }, (i18n && i18n.__) ? i18n.__('Select a form in the block settings.', 'ribo-wp-inbound') : 'Select a form in the block settings.'),
          el('div', { style: { marginTop: '10px', opacity: 0.7, fontSize: '12px' } }, 'This block renders your form on the front-end using the existing RIBO shortcode renderer.')
        )
      );
    },
    save: function () {
      // Server-side render.
      return null;
    }
  });
})(window.wp && window.wp.blocks, window.wp && window.wp.element, window.wp && window.wp.components, window.wp && window.wp.blockEditor, window.wp && window.wp.i18n);
