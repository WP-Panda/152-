(() => {
  document.getElementById('rcc-select-logo')?.addEventListener('click', () => {
    if (!window.wp?.media) return;
    const frame = wp.media({title: 'Logo', multiple: false, library: {type: 'image'}});
    frame.on('select', () => {
      const url = frame.state().get('selection').first()?.get('url');
      const input = document.querySelector('input[name="rcc_options[logo]"]');
      if (url && input) input.value = url;
      const preview = document.getElementById('rcc-logo-preview');
      if (preview && url) preview.src = url;
    });
    frame.open();
  });
  document.querySelectorAll('.rcc-admin form[action$="options.php"]').forEach(form => {
    form.querySelectorAll('input:not(.rcc-preserve), textarea, select').forEach(input => {
      const match = input.name.match(/^rcc_options\[([^\]]+)\]/);
      if (match) {
        const hidden = form.querySelector('.rcc-preserve[data-key="' + match[1] + '"]');
        if (hidden) hidden.remove();
      }
    });
    form.querySelectorAll('input[name^="rcc_options[integrations]"]').forEach(input => {
      const hidden = form.querySelector('.rcc-preserve[data-key="integrations-' + input.name.match(/\[([^\]]+)\]$/)[1] + '"]');
      if (hidden) hidden.remove();
    });
    // Unchecked checkbox values must not revert to the preserved value.
    form.querySelectorAll('input[type=checkbox]').forEach(input => {
      const fallback = document.createElement('input');
      fallback.type = 'hidden'; fallback.name = input.name; fallback.value = '0';
      input.before(fallback);
    });
  });
})();
