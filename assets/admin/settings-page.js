(function () {
    var toggle  = document.querySelector('[data-dsgo-prefix-toggle]');
    var input   = document.getElementById('dsgo_apps_url_prefix');
    var preview = document.querySelector('[data-dsgo-prefix-preview]');
    var warn    = document.querySelector('[data-dsgo-prefix-warn]');
    var field   = document.querySelector('[data-dsgo-prefix-field]');
    if (!toggle || !input || !preview) {
        return;
    }
    var home  = preview.getAttribute('data-home') || '';
    var slug  = preview.getAttribute('data-slug') || 'my-app';
    // Last non-empty value the user typed; remembered so unchecking and
    // rechecking the toggle restores their prior prefix instead of leaving
    // it blank.
    var saved = input.value || 'apps';

    function applyToggle(initial) {
        var enabled = toggle.checked;
        if (enabled) {
            input.readOnly = false;
            if (!initial && (input.value === '' || input.value == null)) {
                input.value = saved;
            }
        } else {
            if (input.value !== '') saved = input.value;
            if (!initial) input.value = '';
            input.readOnly = true;
        }
        if (field) field.classList.toggle('dsgo-field--disabled', !enabled);
        if (warn) warn.hidden = enabled;
        renderPreview();
    }

    function renderPreview() {
        var raw = (input.value || '').trim().replace(/^\/+|\/+$/g, '');
        preview.textContent = raw === ''
            ? home + '/' + slug
            : home + '/' + raw + '/' + slug;
    }

    toggle.addEventListener('change', function () { applyToggle(false); });
    input.addEventListener('input', renderPreview);
    applyToggle(true);
})();
