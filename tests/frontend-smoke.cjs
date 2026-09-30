'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM} = require('jsdom');
const code = fs.readFileSync(path.join(__dirname, '../152fz-cookie-consent/assets/js/frontend.js'), 'utf8');
const wait = () => new Promise(resolve => setTimeout(resolve, 30));

async function scenario(choice) {
  const html = `<!doctype html><html><head>
    <script type="text/plain" data-rcc-category="marketing">window.trackerRan=true</script>
    </head><body>
    <div id="rcc-root" hidden><button data-rcc-choice="${choice}">${choice}</button></div>
    <div id="rcc-dialog" hidden><button data-rcc-close>Close</button></div>
    <button id="rcc-float" hidden data-rcc-open>Settings</button>
    <form id="contact"><input type="email"><button type="submit">Send</button></form>
    <form id="search"><input type="search"></form>
    <div class="woocommerce"><form id="checkout"><input type="email"></form></div>
    </body></html>`;
  const dom = new JSDOM(html, {url: 'https://example.org/', runScripts: 'dangerously', pretendToBeVisual: true});
  const {window: w} = dom;
  w.rccConfig = {
    ajax: '/wp-admin/admin-ajax.php', nonce: 'test', version: 'v1', duration: 180, delay: 0,
    categories: ['necessary', 'analytics', 'marketing'], rules: {analytics: [], marketing: []},
    customRules: '', formAuto: true, formCookie: false, googleMode: false, debug: false,
    checkboxHtml: '<label><input name="rcc_personal_data_consent" type="checkbox" required></label>',
    saveError: 'Failed'
  };
  w.fetch = async () => ({
    status: 200,
    json: async () => ({success: true, data: {
      id: 'test', time: Date.now() / 1000, version: 'v1',
      categories: {necessary: true, analytics: choice === 'accept', marketing: choice === 'accept'}
    }})
  });
  w.eval(code);
  await wait();
  assert.equal(w.trackerRan, undefined, 'tracker ran before consent');
  assert.ok(w.document.querySelector('#contact [name=rcc_personal_data_consent]'));
  assert.equal(w.document.querySelector('#search [name=rcc_personal_data_consent]'), null);
  assert.equal(w.document.querySelector('#checkout [name=rcc_personal_data_consent]'), null);
  w.document.querySelector('[data-rcc-choice]').click();
  await wait();
  assert.equal(!!w.trackerRan, choice === 'accept', 'tracker state after choice');
  assert.equal(w.document.getElementById('rcc-root').hidden, true);
  dom.window.close();
}

(async () => {
  await scenario('accept');
  await scenario('reject');
  console.log('Frontend consent smoke tests passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
