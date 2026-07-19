import test from 'node:test';
import assert from 'node:assert/strict';
import { createPageKey, createPinNavigationUrl, getRequestedPinId } from '../assets/page-key.js';

test('normalizes trailing slash and sorts ordinary query parameters', () => {
  assert.equal(
    createPageKey({ href: 'https://Example.com/oferta/?z=3&a=2&a=1' }),
    'https://example.com/oferta?a=1&a=2&z=3'
  );
});

test('removes ReviewLayer parameters without removing prototype parameters', () => {
  assert.equal(
    createPageKey({ href: 'https://prototype.example.com/oferta?variant=2&reviewlayer=clear&reviewlayer_debug=1' }),
    'https://prototype.example.com/oferta?variant=2'
  );
});

test('keeps ports and hash routes distinct', () => {
  assert.notEqual(
    createPageKey({ href: 'http://localhost:3000/oferta' }),
    createPageKey({ href: 'http://localhost:5173/oferta' })
  );
  assert.notEqual(
    createPageKey({ href: 'https://example.com/#/oferta' }),
    createPageKey({ href: 'https://example.com/#/kontakt' })
  );
});

test('ignores regular in-page anchor fragments', () => {
  assert.equal(
    createPageKey({ href: 'https://example.com/prototype#demo' }),
    createPageKey({ href: 'https://example.com/prototype' })
  );
  assert.equal(
    createPageKey({ href: 'https://example.com/prototype#pricing' }),
    createPageKey({ href: 'https://example.com/prototype#demo' })
  );
});

test('normalizes default ports but preserves non-default ports', () => {
  assert.equal(createPageKey({ href: 'https://example.com:443/' }), 'https://example.com/');
  assert.equal(createPageKey({ href: 'https://example.com:8443/' }), 'https://example.com:8443/');
});

test('creates a temporary pin navigation URL without changing the page key', () => {
  const pageUrl = 'https://example.com/oferta?variant=2#/details';
  const navigationUrl = createPinNavigationUrl(pageUrl, 'pin-id', 'https://example.com/');
  assert.equal(getRequestedPinId({ href: navigationUrl }), 'pin-id');
  assert.equal(createPageKey({ href: navigationUrl }), createPageKey({ href: pageUrl }));
  assert.equal(new URL(navigationUrl).hash, '#/details');
});
