import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { ApiClient } from '../assets/api-client.js';

test('a write request refreshes an expired CSRF session and retries exactly once', async () => {
  const originalFetch = globalThis.fetch;
  const calls = [];
  globalThis.fetch = async (url, options) => {
    const action = new URL(url).searchParams.get('action');
    calls.push({ action, options });
    if (calls.length === 1) {
      return {
        ok: false,
        status: 403,
        json: async () => ({ success: false, error: { code: 'CSRF_ERROR', message: 'Expired.' } })
      };
    }
    if (action === 'bootstrap') {
      return {
        ok: true,
        status: 200,
        json: async () => ({ success: true, data: { csrf_token: 'fresh-token' }, error: null })
      };
    }
    return {
      ok: true,
      status: 200,
      json: async () => ({ success: true, data: { updated: true }, error: null })
    };
  };

  try {
    const client = new ApiClient('https://example.com/reviewlayer/');
    client.csrfToken = 'expired-token';
    const result = await client.updatePinAudience('22222222-2222-4222-8222-222222222222', {
      project_key: 'default',
      author_id: '11111111-1111-4111-8111-111111111111',
      audience_role: 'designer'
    });
    assert.deepEqual(result, { updated: true });
    assert.deepEqual(calls.map((call) => call.action), ['update-pin-audience', 'bootstrap', 'update-pin-audience']);
    assert.equal(calls[0].options.headers['X-ReviewLayer-CSRF'], 'expired-token');
    assert.equal(calls[2].options.headers['X-ReviewLayer-CSRF'], 'fresh-token');
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('pin audience changes use project access without requiring a retained commenter row', async () => {
  const testDirectory = dirname(fileURLToPath(import.meta.url));
  const api = await readFile(resolve(testDirectory, '../api/index.php'), 'utf8');
  const action = api.match(/if \(\$action === 'update-pin-audience'\) \{([\s\S]*?)\n    \}/)?.[1] || '';
  assert.match(action, /updatePinAudience/);
  assert.doesNotMatch(action, /knownAuthor|Only a project commenter|resolveAuthorId/);
});
