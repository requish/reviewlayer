export const REVIEWLAYER_PARAMS = new Set([
  'reviewlayer',
  'reviewlayer_action',
  'reviewlayer_lang',
  'reviewlayer_debug',
  'reviewlayer_pin',
  'reviewlayer_token'
]);

export function createCanonicalUrl(locationLike) {
  const baseHref = typeof window === 'undefined' ? 'http://localhost/' : window.location.href;
  const url = new URL(locationLike.href || String(locationLike), baseHref);
  url.username = '';
  url.password = '';

  const entries = [...url.searchParams.entries()]
    .filter(([name]) => !REVIEWLAYER_PARAMS.has(name.toLowerCase()))
    .sort(([nameA, valueA], [nameB, valueB]) => {
      const nameOrder = nameA.localeCompare(nameB);
      return nameOrder || valueA.localeCompare(valueB);
    });

  url.search = '';
  for (const [name, value] of entries) {
    url.searchParams.append(name, value);
  }

  if (url.pathname.length > 1) {
    url.pathname = url.pathname.replace(/\/+$/, '');
  }

  if (url.hash && !url.hash.startsWith('#/') && !url.hash.startsWith('#!')) {
    url.hash = '';
  }

  return url.href;
}

export function createPageKey(locationLike) {
  return createCanonicalUrl(locationLike);
}

export function hasClearParameter(locationLike = window.location) {
  const url = new URL(locationLike.href);
  return url.searchParams.get('reviewlayer') === 'clear';
}

export function getRequestedPinId(locationLike) {
  const baseHref = typeof window === 'undefined' ? 'http://localhost/' : window.location.href;
  const source = locationLike || baseHref;
  const url = new URL(source.href || String(source), baseHref);
  return url.searchParams.get('reviewlayer_pin') || '';
}

export function createPinNavigationUrl(pageUrl, pinId, baseHref) {
  const fallbackHref = baseHref || (typeof window === 'undefined' ? 'http://localhost/' : window.location.href);
  const url = new URL(pageUrl, fallbackHref);
  url.searchParams.set('reviewlayer_pin', String(pinId));
  return url.href;
}

export function removeReviewLayerParameters() {
  const url = new URL(window.location.href);
  for (const name of [...url.searchParams.keys()]) {
    if (REVIEWLAYER_PARAMS.has(name.toLowerCase())) {
      url.searchParams.delete(name);
    }
  }
  window.history.replaceState(window.history.state, '', url.href);
}

export function installNavigationObserver() {
  if (window.__reviewLayerNavigationInstalled) {
    return;
  }

  window.__reviewLayerNavigationInstalled = true;
  for (const methodName of ['pushState', 'replaceState']) {
    const original = window.history[methodName];
    window.history[methodName] = function reviewLayerHistoryWrapper(...args) {
      const previousUrl = window.location.href;
      const result = original.apply(this, args);
      if (window.location.href !== previousUrl) {
        window.dispatchEvent(new CustomEvent('reviewlayer:navigation'));
      }
      return result;
    };
  }
}
