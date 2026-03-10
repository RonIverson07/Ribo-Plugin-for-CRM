(function(){
  function qs(el, sel){ return el.querySelector(sel); }
  function qsa(el, sel){ return Array.prototype.slice.call(el.querySelectorAll(sel)); }

  document.addEventListener('submit', async function(e){
    var form = e.target;
    if (!form.classList.contains('ribo-form')) return;
    e.preventDefault();

    var wrap = form.closest('.ribo-form-wrap');
    var status = qs(form, '.ribo-status');
    status.textContent = 'Sending...';

    // clear errors
    qsa(form, '.ribo-error').forEach(function(x){ x.textContent=''; });

    // gather
    var data = {};
    qsa(form, 'input, textarea, select').forEach(function(inp){
      if (!inp.name) return;
      if (inp.type === 'checkbox') {
        if (!data[inp.name]) data[inp.name] = [];
        if (inp.checked) data[inp.name].push(inp.value);
      } else {
        data[inp.name] = inp.value;
      }
    });

    // normalize checkboxes array names -> field id
    Object.keys(data).forEach(function(k){
      if (k.endsWith('[]')) {
        data[k.slice(0, -2)] = data[k];
        delete data[k];
      }
    });

    try {
      var resp = await fetch(RIBO_FORM.restUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': RIBO_FORM.nonce
        },
        body: JSON.stringify(data)
      });
      var json = await resp.json().catch(function(){ return {}; });

      if (resp.status === 422 && json && json.errors) {
        Object.keys(json.errors).forEach(function(fid){
          var err = qs(form, '.ribo-error[data-error-for="'+fid+'"]');
          if (err) err.textContent = json.errors[fid];
        });
        status.textContent = 'Please fix the highlighted fields.';
        return;
      }

      if (resp.ok && json.ok) {
        status.textContent = json.queued ? 'Submitted (queued for sync). Thank you!' : 'Submitted. Thank you!';
        form.reset();
        return;
      }

      status.textContent = 'Submission failed. Please try again later.';
    } catch(err) {
      status.textContent = 'Network error. Please try again later.';
    }
  });
})();
