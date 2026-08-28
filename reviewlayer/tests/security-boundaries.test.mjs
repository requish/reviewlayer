import test from 'node:test';
import assert from 'node:assert/strict';
import { createAnchorResolver } from '../assets/anchor.js';

function installDom() {
  let selectorCalls = 0;
  let tagCalls = 0;
  const element = {
    localName: 'button',
    id: 'save',
    classList: { contains: () => false },
    parentElement: null,
    isConnected: true,
    textContent: 'Save',
    getAttribute: () => null,
    getBoundingClientRect: () => ({ x: 10, y: 20, left: 10, top: 20, right: 110, bottom: 60, width: 100, height: 40 })
  };
  globalThis.window = {
    scrollX: 0,
    scrollY: 0,
    getComputedStyle: () => ({ display: 'block', visibility: 'visible' })
  };
  globalThis.document = {
    querySelector(selector) {
      selectorCalls += 1;
      return selector === '#save' ? element : null;
    },
    getElementById: () => null,
    getElementsByTagName() {
      tagCalls += 1;
      return { length: 0, item: () => null };
    }
  };
  return { element, selectorCalls: () => selectorCalls, tagCalls: () => tagCalls };
}

function pin(selector, tag = 'button') {
  return {
    id: crypto.randomUUID(),
    target_selector: selector,
    target_fingerprint: {
      tag,
      id: 'save',
      classes: [],
      attributes: {},
      text: 'Save',
      sibling_index: 0,
      ancestors: []
    },
    anchor: {
      relative_x: 0.5,
      relative_y: 0.5,
      document_x: 60,
      document_y: 40,
      fallback_ancestors: []
    }
  };
}

test('resolver rejects universal selectors and invalid fallback tags without querying the host DOM', () => {
  const dom = installDom();
  const result = createAnchorResolver().resolve(pin('*', 'button['));
  assert.equal(dom.selectorCalls(), 0);
  assert.equal(dom.tagCalls(), 0);
  assert.equal(result.uncertain, true);
});

test('resolver keeps ordinary generated selectors functional', () => {
  const dom = installDom();
  const result = createAnchorResolver().resolve(pin('#save'));
  assert.equal(dom.selectorCalls(), 1);
  assert.equal(result.x, 60);
  assert.equal(result.y, 40);
  assert.equal(result.uncertain, false);
});

