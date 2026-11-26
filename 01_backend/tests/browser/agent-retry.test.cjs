const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { randomUUID } = require('node:crypto');
const { resolve } = require('node:path');

test('interrupted response and in-progress replay retain the original teller operation', async () => {
    const blade = readFileSync(resolve(__dirname, '../../resources/views/agent-views/_post_helper.blade.php'), 'utf8');
    const script = blade.slice(blade.indexOf('window.__amialPost'), blade.indexOf('</script>'));
    const keys = [];
    const receipts = new Map();
    const fetch = async (_, options) => {
        const key = options.headers['Idempotency-Key'];
        keys.push(key);
        if (!receipts.has(key)) receipts.set(key, receipts.size + 1);
        if (keys.length === 1) return {status: 200, json: async () => { throw Error('body interrupted'); }};
        if (keys.length === 2) return {status: 409, json: async () => ({code: 'IDEMPOTENCY_IN_PROGRESS'})};
        return {status: 200, ok: true, json: async () => ({success: true, receipt: receipts.get(key)})};
    };
    const crypto = {randomUUID};
    const window = {crypto};
    vm.runInNewContext(script, {window, crypto, document: {activeElement: null}, fetch});
    const post = () => window.__amialPost('/agent', '/counter/deposit', {amount: 100}, 'test');
    assert.equal((await post()).pending, true);
    assert.equal((await post()).pending, true);
    assert.equal((await post()).receipt, 1);
    assert.equal(new Set(keys).size, 1);
    assert.equal(receipts.size, 1);
    assert.equal((await post()).receipt, 2);
});
