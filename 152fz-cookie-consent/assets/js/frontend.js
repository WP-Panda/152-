/* Consent is opt-in. The cacheable HTML is identical for all visitors; this script reads local preferences. */
(() => {
  'use strict';
  const cfg = window.rccConfig;
  if (!cfg) return;
  const read = () => {
    try {
      const value = document.cookie.split('; ').find(c => c.startsWith('rcc_consent='));
      const saved = value && JSON.parse(decodeURIComponent(value.substring(12)));
      return saved && saved.version === cfg.version && saved.time > Date.now() / 1000 - cfg.duration * 86400 ? saved : null;
    } catch (_) { return null; }
  };
  let consent = read();
  let approved = consent ? consent.categories : {necessary: true};
  const allowed = category => category === 'necessary' || approved[category] === true;
  const classify = value => {
    const lower = String(value || '').toLowerCase();
    if (lower.includes('/woocommerce/assets/')) return null;
    for (const [category, patterns] of Object.entries(cfg.rules)) {
      if (patterns.some(pattern => lower.includes(pattern.toLowerCase()))) return category;
    }
    for (const line of cfg.customRules.split('\n')) {
      const [category, ...parts] = line.trim().split('|');
      if (cfg.categories.includes(category) && parts.join('|') && lower.includes(parts.join('|').toLowerCase())) return category;
    }
    return null;
  };
  const updateGoogle = () => {
    if (!cfg.googleMode || typeof window.gtag !== 'function') return;
    window.gtag('consent', 'update', {
      analytics_storage: allowed('analytics') ? 'granted' : 'denied',
      ad_storage: allowed('marketing') ? 'granted' : 'denied',
      ad_user_data: allowed('marketing') ? 'granted' : 'denied',
      ad_personalization: allowed('marketing') ? 'granted' : 'denied',
      functionality_storage: allowed('functional') ? 'granted' : 'denied'
    });
  };
  updateGoogle();
  // Intercept the common dynamic insertion APIs before execution. Direct document.write and
  // scripts inserted by extensions cannot be reliably controlled without a restrictive CSP.
  const prepare = node => {
    if (!node || node.nodeType !== 1) return node;
    if (node.tagName === 'SCRIPT') {
      const category = classify((node.getAttribute('src') || '') + ' ' + node.id + ' ' + node.className);
      if (category && !allowed(category)) {
        if (node.hasAttribute('src')) { node.dataset.rccSrc = node.getAttribute('src'); node.removeAttribute('src'); }
        if (node.type && node.type !== 'text/plain') node.dataset.rccType = node.type;
        node.dataset.rccCategory = category;
        node.type = 'text/plain';
        if (cfg.debug) console.info('RCC blocked script', category, node.dataset.rccSrc);
      }
    }
    if (node.tagName === 'IFRAME' && node.getAttribute('src')) {
      const category = classify(node.getAttribute('src'));
      if (category && !allowed(category)) {
        node.dataset.rccSrc = node.getAttribute('src');
        node.removeAttribute('src');
        node.dataset.rccCategory = category;
      }
    }
    return node;
  };
  for (const method of ['appendChild', 'insertBefore', 'replaceChild']) {
    const original = Node.prototype[method];
    Node.prototype[method] = function (node, ...args) { return original.call(this, prepare(node), ...args); };
  }
  const activate = () => {
    document.querySelectorAll('script[data-rcc-category]').forEach(old => {
      if (!allowed(old.dataset.rccCategory) || old.dataset.rccLoaded) return;
      old.dataset.rccLoaded = '1';
      const script = document.createElement('script');
      // Dynamically inserted external scripts are async by default; preserve dependency order.
      if (!old.hasAttribute('async')) script.async = false;
      for (const attr of old.attributes) {
        if (!['type', 'data-rcc-category', 'data-rcc-src', 'data-rcc-type', 'data-rcc-loaded'].includes(attr.name)) script.setAttribute(attr.name, attr.value);
      }
      if (old.dataset.rccType) script.type = old.dataset.rccType;
      if (old.dataset.rccSrc) script.src = old.dataset.rccSrc;
      else script.textContent = old.textContent;
      old.parentNode.insertBefore(script, old.nextSibling);
    });
    document.querySelectorAll('iframe[data-rcc-src]').forEach(frame => {
      if (allowed(frame.dataset.rccCategory)) { frame.src = frame.dataset.rccSrc; frame.removeAttribute('data-rcc-src'); }
    });
    document.querySelectorAll('.rcc-frame[data-rcc-frame]').forEach(holder => {
      if (!allowed(holder.dataset.rccCategory)) return;
      try {
        const html = atob(holder.dataset.rccFrame);
        const template = document.createElement('template'); template.innerHTML = html.trim();
        const frame = template.content.firstElementChild;
        if (frame && frame.tagName === 'IFRAME') holder.replaceWith(frame);
      } catch (_) { /* malformed markup: retain placeholder */ }
    });
  };
  const ready = () => {
    const root = document.getElementById('rcc-root');
    const dialog = document.getElementById('rcc-dialog');
    const floating = document.getElementById('rcc-float');
    let previousFocus = null;
    const showBanner = () => { if (root && !consent) root.hidden = false; };
    if (consent) { if (floating) floating.hidden = false; } else setTimeout(showBanner, Math.max(0, cfg.delay) * 1000);
    const open = () => {
      if (!dialog) return;
      previousFocus = document.activeElement;
      dialog.hidden = false;
      dialog.querySelectorAll('[data-rcc-toggle]').forEach(input => { input.checked = allowed(input.dataset.rccToggle); });
      dialog.querySelector('.rcc-close').focus();
    };
    const close = () => { if (dialog) dialog.hidden = true; if (previousFocus && previousFocus.focus) previousFocus.focus(); };
    document.addEventListener('click', event => {
      if (event.target.closest('[data-rcc-open]')) { event.preventDefault(); open(); }
      if (event.target.closest('[data-rcc-close]')) { close(); }
      if (event.target.closest('[data-rcc-dismiss]')) { if (root) root.hidden = true; if (floating) floating.hidden = false; }
      const button = event.target.closest('[data-rcc-choice]');
      if (!button) return;
      event.preventDefault();
      const choice = button.dataset.rccChoice;
      if (!['accept', 'reject', 'save', 'revoke'].includes(choice)) return;
      const categories = {necessary: true};
      cfg.categories.forEach(key => { if (key !== 'necessary') categories[key] = choice === 'accept' || (choice === 'save' && !!dialog?.querySelector('[data-rcc-toggle="' + key + '"]')?.checked); });
      const body = new URLSearchParams({action: 'rcc_save', nonce: cfg.nonce, choice, url: location.href});
      for (const [key, value] of Object.entries(categories)) body.set('categories[' + key + ']', value ? '1' : '0');
      button.disabled = true;
      fetch(cfg.ajax, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body.toString()})
        .then(async response => {
          if (response.status === 403) {
            const fresh = await fetch(cfg.ajax + '?action=rcc_nonce', {credentials: 'same-origin', cache: 'no-store'}).then(r => r.json());
            if (!fresh.success) throw Error('Unable to refresh nonce');
            body.set('nonce', fresh.data.nonce);
            return fetch(cfg.ajax, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body.toString()}).then(r => r.json());
          }
          return response.json();
        }).then(result => {
          if (!result.success) throw Error('Consent was not saved');
          const previous = approved;
          consent = result.data; approved = consent.categories;
          close(); if (root) root.hidden = true; if (floating) floating.hidden = false;
          updateGoogle(); activate();
          if (Object.keys(previous).some(key => previous[key] && !approved[key])) location.reload();
        }).catch(() => { window.alert(cfg.saveError); })
        .finally(() => { button.disabled = false; });
    });
    document.addEventListener('keydown', event => {
      if (!dialog || dialog.hidden) return;
      if (event.key === 'Escape') close();
      if (event.key === 'Tab') {
        const items = [...dialog.querySelectorAll('button:not([disabled]),input:not([disabled])')].filter(el => el.offsetParent !== null);
        if (!items.length) return;
        if (event.shiftKey && document.activeElement === items[0]) { event.preventDefault(); items.at(-1).focus(); }
        else if (!event.shiftKey && document.activeElement === items.at(-1)) { event.preventDefault(); items[0].focus(); }
      }
    });
    document.getElementById('rcc-search')?.addEventListener('input', event => {
      dialog.querySelectorAll('.rcc-category,.rcc-cookie-row').forEach(row => { row.hidden = !row.dataset.rccSearch.toLowerCase().includes(event.target.value.toLowerCase()); });
    });
    const attach = form => {
      if (!cfg.formAuto || form.dataset.rccProcessed || !form.querySelector('input[type=email],input[type=tel],textarea,input[name*=name],input[name*=email]') || form.closest('.woocommerce, .wc-block-checkout, .wp-block-woocommerce-checkout, #rcc-root, #rcc-dialog') || form.matches('[data-rcc-skip],.cart,.checkout,.woocommerce-form,form[action*="wp-login.php"]')) return;
      if (!form.querySelector('input[name="rcc_personal_data_consent"]')) {
        const wrapper = document.createElement('div'); wrapper.innerHTML = cfg.checkboxHtml;
        const button = form.querySelector('[type=submit]');
        form.insertBefore(wrapper.firstElementChild, button || null);
      }
      form.dataset.rccProcessed = '1';
    };
    document.querySelectorAll('form').forEach(attach);
    document.addEventListener('submit', event => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || !cfg.formAuto) return;
      attach(form);
      if (cfg.formCookie && !consent) { event.preventDefault(); event.stopImmediatePropagation(); window.alert(cfg.cookieMessage); open(); return; }
      const checkbox = form.querySelector('input[name="rcc_personal_data_consent"]');
      if (checkbox && !checkbox.checked) { event.preventDefault(); event.stopImmediatePropagation(); checkbox.setCustomValidity(cfg.requiredMessage); checkbox.reportValidity(); }
    }, true);
    document.addEventListener('change', event => { if (event.target.matches('input[name="rcc_personal_data_consent"]')) event.target.setCustomValidity(''); });
    new MutationObserver(mutations => {
      for (const mutation of mutations) for (const node of mutation.addedNodes) {
        if (node.nodeType !== 1) continue;
        if (node.matches?.('form')) attach(node);
        node.querySelectorAll?.('form').forEach(attach);
      }
    }).observe(document.documentElement, {childList: true, subtree: true});
    activate();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
})();
