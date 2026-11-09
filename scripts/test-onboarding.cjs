// Actual wizard code + real jQuery/jsdom; HTTP replies are explicit test doubles.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const deps = process.env.ASHBI_DOM_DEPS ? createRequire(path.resolve(process.env.ASHBI_DOM_DEPS, 'package.json')) : require;
const { JSDOM } = deps('jsdom');
const jquery = deps('jquery');
const source = fs.readFileSync(path.join(__dirname, '../plugin/assets/js/admin/onboarding-wizard.js'), 'utf8');
async function fixture() {
  const dom = new JSDOM(`<input id="subscrpt-plan-type" value="installments"><input id="subscrpt_plan_title" value="Split"><input id="subscrpt_new_product_name" value="Box"><input id="subscrpt-installment-count" value="3"><input id="subscrpt-wizard-page" value="3"><input type="checkbox" id="subscrpt-publish-choice"><input type="checkbox" id="subscrpt-review-confirm"><div id="subscrpt-review-summary"></div><div id="subscrpt-finalize-error-msg"></div><div id="subscrpt-finalize-error" hidden></div><div id="subscrpt-finalize-success" hidden></div><button id="subscrpt-btn-retry-finalize"></button><div id="subscrpt-durations"><div data-dur><input data-dur-name value="Monthly"><input data-dur-freq value="1"><div data-dur-interval><input type="hidden" value="month"></div></div></div><div id="subscrpt-connect-durations"><div data-connect-row data-connect-dur="0"><input data-connect-price value="10.00"><input type="checkbox" data-connect-enabled checked></div></div>`, { runScripts: 'outside-only', url: 'https://example.test/' });
  const $ = jquery(dom.window);
  dom.window.jQuery = $;
  dom.window.alert = (message) => { dom.alerts.push(message); };
  dom.window.confirm = () => true;
  dom.alerts = [];
  // Expose the lexical controller only in this fixture, without an added runtime API.
  dom.window.eval(source.replace('Wizard.init();', 'window.fixtureWizard = Wizard;'));
  await new Promise(resolve => $(resolve));
  const w = dom.window.fixtureWizard;
  w.cfg = { rest_url: '/plans', currency_symbol: '$', currency_decimals: 2 };
  w.hasProducts = false;
  w.bindEvents();
  return { dom, $, w, close: () => dom.window.close() };
}
const event = { preventDefault() {} };

test('duration pricing follows stable duration identity after removal, not ordinal position', async () => {
  const f = await fixture();
  try {
    const row = f.$('[data-connect-row]')[0].outerHTML;
    f.$('body').append('<template id="subscrpt-connect-dur-tpl">' + row + '</template>');
    f.$('#subscrpt-durations').append(f.$('[data-dur]').first().clone());
    f.w.renderConnectDurations();
    f.$('[data-connect-price]').eq(0).val('10');
    f.$('[data-connect-price]').eq(1).val('20');
    f.$('[data-dur]').first().remove();
    f.w.renderConnectDurations();
    assert.equal(f.$('[data-connect-price]').val(), '20');
  } finally { f.close(); }
});

test('invalid decimal prices cannot reach review or write requests', async () => {
  const f = await fixture();
  try {
    let calls = 0; f.w.api = async () => { calls++; throw refusal(); };
    f.$('[data-connect-price]').val('10junk');
    f.w.nextFromProduct(event);
    f.$('#subscrpt-review-confirm').prop('checked', true);
    await f.w.finalize(event);
    assert.equal(calls, 0);
    assert.equal(f.$('#subscrpt-wizard-page').val(), '3');
  } finally { f.close(); }
});

test('canonical PHP template exposes count, review confirmation and a draft-first action', () => {
  const result = require('node:child_process').spawnSync('php', [path.join(__dirname, '../tests/fixtures/run-onboarding.php'), 'template'], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const dom = new JSDOM(result.stdout);
  try {
    const d = dom.window.document;
    assert.ok(d.querySelector('#subscrpt-installment-count'));
    assert.equal(d.querySelector('#subscrpt-installment-count').min, '2');
    assert.equal(d.querySelector('#subscrpt-installment-count').value, '');
    assert.equal(d.querySelector('#subscrpt-publish-choice').checked, false);
    assert.ok(d.querySelector('#subscrpt-review-confirm'));
    assert.match(d.querySelector('#subscrpt-btn-next-3').textContent, /Review/);
    assert.match(d.querySelector('#subscrpt-btn-create-reviewed').textContent, /draft/);
    assert.ok(d.querySelector('#subscrpt-finalize-progress').hidden);
  } finally { dom.window.close(); }
});

test('AUX-10 Continue is review-only; draft is the explicit default action', async () => {
  const f = await fixture();
  try {
    let writes = 0; f.w.ensurePlan = async () => { writes++; };
    f.w.ensureProductAndConnect = async () => {};
    f.w.nextFromProduct(event);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(writes, 0);
    assert.match(f.$('#subscrpt-review-summary').text(), /3 payments/);
    assert.match(f.$('#subscrpt-review-summary').text(), /total.*10.00/i);
    assert.match(f.$('#subscrpt-review-summary').text(), /signup.*0/i);
    assert.match(f.$('#subscrpt-review-summary').text(), /3.33.*today|today.*3.33/i);
    assert.match(f.$('#subscrpt-review-summary').text(), /final.*3.34/i);
    assert.match(f.$('#subscrpt-review-summary').text(), /draft/i);
    f.w.finalize(event);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(writes, 0);
  } finally { f.close(); }
});

test('AUX-10 draft intent stores draft group and draft duration', async () => {
  const f = await fixture();
  try {
    const calls = [];
    f.w.api = async (method, url, body) => { calls.push({ method, url, body: plain(body) }); return url === '/groups' ? { id: 11, plans: [{ id: 21 }] } : { id: 21 }; };
    await f.w.ensurePlan();
    assert.equal(calls[0].body.status, 'draft');
    assert.equal(calls[1].body.status, 'draft');
  } finally { f.close(); }
});

test('AUX-03 changed fields after refusal cannot append stale records', async () => {
  const f = await fixture();
  try {
    let calls = 0; f.w.api = async () => { calls++; throw refusal(); };
    f.w.nextFromProduct(event);
    f.$('#subscrpt-review-confirm').prop('checked', true);
    await f.w.finalize(event);
    f.$('#subscrpt-installment-count').val('8');
    await f.w.finalize(event);
    assert.equal(calls, 1);
    assert.match(f.$('#subscrpt-finalize-error-msg').text(), /changed|original/i);
  } finally { f.close(); }
});
const plain = value => JSON.parse(JSON.stringify(value));

for (const mode of ['new', 'existing']) for (const publish of [false, true]) {
  test(`AUX-10 reviewed ${mode} ${publish ? 'publish' : 'draft'} creation uses staged writes and honest completion`, async () => {
    const f = await fixture();
    try {
      const records = new Map(); const calls = []; let rid = 40;
      if (mode === 'existing') {
        f.w.hasProducts = true; f.w.selectedProductName = 'Existing Box';
        f.$('body').append('<input id="subscrpt-existing-product-hidden" value="51">');
      }
      f.w.api = async (method, url, body) => {
        calls.push({ method, url, body: plain(body || {}) });
        if (method === 'GET') {
          if (url === '/groups/11') return { ...records.get(url), plans: Array.from(records.entries()).filter(([key]) => key.startsWith('/terms/')).map(([, term]) => ({ ...term, relations: Array.from(records.entries()).filter(([key, relation]) => key.startsWith('/relations/') && relation.plan_id === term.id).map(([, relation]) => relation) })) };
          return records.get(url);
        }
        const match = url.match(/\/(\d+)$/);
        const id = match ? Number(match[1]) : url === '/groups' ? 11 : ++rid;
        const record = { ...(records.get(url) || {}), ...plain(body), id };
        if (url === '/groups') record.plans = [{ id: 21 }];
        const key = url === '/groups' ? '/groups/11' : url === '/relations' ? `/relations/${id}` : url;
        records.set(key, record); return record;
      };
      f.$.post = (url, body, callback) => {
        calls.push({ ajax: body.action, body: plain(body) });
        queueMicrotask(() => callback({ success: true, data: { product_id: 51, product_status: body.action === 'subscrpt_publish_wizard_product' ? 'publish' : 'draft' } }));
        return { fail() { return this; } };
      };
      f.$('#subscrpt-publish-choice').prop('checked', publish);
      f.w.nextFromProduct(event);
      f.$('#subscrpt-review-confirm').prop('checked', true);
      await f.w.finalize(event);
      assert.equal(f.$('#subscrpt-finalize-success').attr('hidden'), undefined);
      assert.equal(calls.find(c => c.url === '/groups').body.status, 'draft');
      assert.equal(calls.find(c => c.url === '/terms/21').body.status, 'draft');
      assert.equal(calls.find(c => c.url === '/relations').body.status, 'draft');
      assert.equal(calls.filter(c => c.ajax === 'subscrpt_create_wizard_product').length, mode === 'new' ? 1 : 0);
      assert.equal(calls.filter(c => c.ajax === 'subscrpt_publish_wizard_product').length, publish && mode === 'new' ? 1 : 0);
      assert.equal(records.get('/groups/11').status, publish ? 'active' : 'draft');
      assert.equal(records.get('/terms/21').data.installment_count, 3);
    } finally { f.close(); }
  });
}

test('AUX-03 retained final handlers and navigation cannot release pending ownership', async () => {
  const f = await fixture();
  try {
    const p = deferred(); let writes = 0;
    f.w.api = async () => { writes++; return p.promise; };
    f.w.nextFromProduct(event); f.$('#subscrpt-review-confirm').prop('checked', true);
    const first = f.w.finalize(event); f.w.finalize(event); f.w.nextFromProduct(event); f.w.restart(event); f.w.goToPage(1, event);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(writes, 1);
    assert.equal(f.$('#subscrpt-wizard-page').val(), '4');
    p.reject(new Error('Network lost')); await first;
    await f.w.finalize(event);
    assert.equal(writes, 1);
    assert.match(f.$('#subscrpt-finalize-error-msg').text(), /Check existing/);
  } finally { f.close(); }
});

const refusal = () => Object.assign(new Error('Validation refused'), { safeRetry: true });

for (const badId of [1.5, 'Infinity']) {
  test(`malformed product success ID ${badId} is uncertain and cannot create another copy`, async () => {
    const f = await fixture();
    try {
      let posts = 0; f.w.intent = f.w.captureIntent();
      f.$.post = (url, body, callback) => {
        posts++; queueMicrotask(() => callback({ success: true, data: { product_id: badId, product_status: 'draft' } }));
        return { fail() { return this; } };
      };
      await assert.rejects(f.w.ensureProduct(), /Check existing/);
      await assert.rejects(f.w.ensureProduct(), /Check existing/);
      assert.equal(posts, 1);
    } finally { f.close(); }
  });
}

test('actual REST helper only treats recognized pre-write errors as safe refusals', async () => {
  const f = await fixture();
  try {
    for (const [status, code, safe] of [[400, 'subscrpt_installment_count_invalid', true], [400, 'unknown_proxy_error', false], [500, 'subscrpt_installment_count_invalid', false]]) {
      f.dom.window.fetch = async (url, options) => {
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.headers['Content-Type'], 'application/json');
        return { ok: false, status, json: async () => ({ code, message: 'Refused' }) };
      };
      await assert.rejects(f.w.api('POST', '/terms', {}), error => error.safeRetry === safe);
    }
  } finally { f.close(); }
});

test('missing stored commitment blocks activation before any product can become subscribable', async () => {
  const f = await fixture();
  try {
    f.$('#subscrpt-publish-choice').prop('checked', true);
    f.w.intent = f.w.captureIntent(); f.w.groupId = 11; f.w.termIds = [21]; f.w.relationIds = { 0: 42 };
    let writes = 0;
    f.w.api = async (method, url) => { if (method !== 'GET') writes++; return { id: url === '/groups/11' ? 11 : 21, status: 'draft', data: {} }; };
    await assert.rejects(f.w.completePublication(), /confirmed/);
    assert.equal(writes, 0);
  } finally { f.close(); }
});

for (const malformed of [true, false]) {
  test(`unknown ${malformed ? 'malformed success' : 'transport failure'} term POST is never automatically repeated`, async () => {
    const f = await fixture();
    try {
      const ds = [0, 1].map(i => ({ name: `D${i}`, freq: 1, interval: 'month' }));
      f.w.collectDurations = () => ds; let posts = 0;
      f.w.api = async (method, url) => {
        if (url === '/groups') return { id: 11, plans: [{ id: 21 }] };
        if (method === 'PUT') return { id: 21 };
        posts++; if (malformed) return {}; throw new Error('Network lost');
      };
      await assert.rejects(f.w.ensurePlan(), /Check existing/);
      await assert.rejects(f.w.ensurePlan(), /Check existing/);
      assert.equal(posts, 1); assert.deepEqual(Array.from(f.w.termIds), [21]);
    } finally { f.close(); }
  });
}

test('AUX-02 preview follows zero-decimal store precision without assuming cents', async () => {
  const f = await fixture();
  try {
    f.w.cfg.currency_decimals = 0;
    f.w.nextFromProduct(event);
    assert.match(f.$('#subscrpt-review-summary').text(), /today.*\$3\b/i);
    assert.match(f.$('#subscrpt-review-summary').text(), /final.*\$4\b/i);
  } finally { f.close(); }
});
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };

for (const failedIndex of [0, 1, 2]) {
  test(`AUX-03 resumes after refused duration ${failedIndex} without another group or completed term`, async () => {
    const f = await fixture();
    try {
      const ds = [0, 1, 2].map(i => ({ name: `D${i}`, freq: i + 1, interval: 'month' }));
      f.w.collectDurations = () => ds;
      const calls = []; let failed = false; const gate = deferred();
      f.w.api = async (method, url, body) => {
        calls.push({ method, url, body: plain(body || {}) });
        if (url === '/groups') return { id: 11, plans: [{ id: 21 }] };
        const idx = Number(body.title.slice(1));
        if (idx === failedIndex && !failed) { failed = true; return gate.promise; }
        return { id: idx === 0 ? 21 : 30 + idx };
      };
      const pending = f.w.ensurePlan();
      await new Promise(resolve => setImmediate(resolve));
      assert.equal(f.w.termIds.filter(Boolean).length, failedIndex);
      gate.reject(refusal());
      await assert.rejects(pending, /refused/);
      const checkpoint = Array.from(f.w.termIds);
      await f.w.ensurePlan();
      assert.equal(calls.filter(c => c.url === '/groups').length, 1);
      for (let i = 0; i < failedIndex; i++) assert.equal(calls.filter(c => c.body.title === `D${i}`).length, 1);
      assert.deepEqual(Array.from(f.w.termIds), [21, 31, 32]);
      assert.equal(checkpoint.filter(Boolean).length, failedIndex);
    } finally { f.close(); }
  });
}

test('AUX-03 unknown group response cannot be blindly resubmitted', async () => {
  const f = await fixture();
  try {
    let count = 0;
    f.w.api = async () => { count++; throw new Error('Network lost'); };
    await assert.rejects(f.w.ensurePlan(), /Network lost|Check existing/);
    await assert.rejects(f.w.ensurePlan(), /Check existing/);
    assert.equal(count, 1);
  } finally { f.close(); }
});

test('AUX-03 concurrent owners share one deferred creation and retain original intent', async () => {
  const f = await fixture();
  try {
    const pending = deferred(); const calls = [];
    f.w.api = async (method, url, body) => {
      calls.push({ method, url, body: plain(body) });
      if (url === '/groups') return pending.promise;
      return { id: 21 };
    };
    const first = f.w.ensurePlan(); const second = f.w.ensurePlan();
    f.$('#subscrpt-installment-count').val('9');
    pending.resolve({ id: 11, plans: [{ id: 21 }] });
    await Promise.all([first, second]);
    assert.equal(calls.filter(c => c.url === '/groups').length, 1);
    assert.equal(calls.find(c => c.url === '/terms/21').body.data.installment_count, 3);
  } finally { f.close(); }
});

test('AUX-02 explicit count survives the seeded-term replacement', async () => {
  const f = await fixture();
  try {
    const body = f.w.termBody(f.w.collectDurations()[0], 11, 'installments');
    assert.equal(body.data.installment_count, 3);
    f.$('#subscrpt-installment-count').val('2.5');
    assert.throws(() => f.w.termBody(f.w.collectDurations()[0], 11, 'installments'), /whole number/);
    f.$('#subscrpt-installment-count').val('');
    assert.throws(() => f.w.termBody(f.w.collectDurations()[0], 11, 'installments'), /whole number/);
    assert.equal(f.w.termBody(f.w.collectDurations()[0], 11, 'recurring').data.installment_count, undefined);
  } finally { f.close(); }
});
