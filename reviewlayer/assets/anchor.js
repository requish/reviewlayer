const RANDOM_CLASS_PATTERN = /(?:^|[-_])(?:[a-f0-9]{7,}|[a-z0-9]{10,})(?:$|[-_])/i;
const STABLE_DATA_NAMES = ['data-review-id', 'data-testid', 'data-test', 'data-cy', 'data-component'];

function cssEscape(value) {
  if (window.CSS?.escape) {
    return window.CSS.escape(value);
  }
  return String(value).replace(/[^a-zA-Z0-9_-]/g, (character) => `\\${character.codePointAt(0).toString(16)} `);
}

function isUnique(selector) {
  try {
    return document.querySelectorAll(selector).length === 1;
  } catch {
    return false;
  }
}

function stableClasses(element) {
  return [...element.classList]
    .filter((name) => name.length <= 64 && !RANDOM_CLASS_PATTERN.test(name))
    .slice(0, 3);
}

function attributeSelector(name, value) {
  return `[${name}="${cssEscape(value)}"]`;
}

function selectorSegment(element) {
  const tag = element.localName;
  for (const name of STABLE_DATA_NAMES) {
    const value = element.getAttribute(name);
    if (value) {
      return `${tag}${attributeSelector(name, value)}`;
    }
  }

  for (const name of ['name', 'aria-label']) {
    const value = element.getAttribute(name);
    if (value && value.length <= 100) {
      return `${tag}${attributeSelector(name, value)}`;
    }
  }

  const classes = stableClasses(element);
  if (classes.length) {
    return `${tag}.${classes.map(cssEscape).join('.')}`;
  }

  const siblings = element.parentElement
    ? [...element.parentElement.children].filter((node) => node.localName === tag)
    : [];
  const index = Math.max(0, siblings.indexOf(element)) + 1;
  return siblings.length > 1 ? `${tag}:nth-of-type(${index})` : tag;
}

export function createStableSelector(element) {
  const reviewId = element.getAttribute('data-review-id');
  if (reviewId) {
    const selector = attributeSelector('data-review-id', reviewId);
    if (isUnique(selector)) return selector;
  }

  if (element.id) {
    const selector = `#${cssEscape(element.id)}`;
    if (isUnique(selector)) return selector;
  }

  for (const name of STABLE_DATA_NAMES.slice(1)) {
    const value = element.getAttribute(name);
    if (value) {
      const selector = attributeSelector(name, value);
      if (isUnique(selector)) return selector;
    }
  }

  for (const name of ['name', 'aria-label']) {
    const value = element.getAttribute(name);
    if (value && value.length <= 100) {
      const selector = `${element.localName}${attributeSelector(name, value)}`;
      if (isUnique(selector)) return selector;
    }
  }

  const path = [];
  let current = element;
  while (current && current !== document.body && path.length < 6) {
    path.unshift(selectorSegment(current));
    const selector = path.join(' > ');
    if (isUnique(selector)) return selector;
    current = current.parentElement;
  }
  path.unshift('body');
  return path.join(' > ');
}

function textSample(element) {
  return (element.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 160);
}

export function createFingerprint(element) {
  const attributes = {};
  for (const name of [...STABLE_DATA_NAMES, 'name', 'aria-label', 'role', 'type']) {
    const value = element.getAttribute(name);
    if (value && value.length <= 160) {
      attributes[name] = value;
    }
  }

  const ancestors = [];
  let parent = element.parentElement;
  while (parent && parent !== document.body && ancestors.length < 3) {
    ancestors.push({
      tag: parent.localName,
      id: parent.id || '',
      classes: stableClasses(parent)
    });
    parent = parent.parentElement;
  }

  const siblings = element.parentElement ? [...element.parentElement.children] : [];
  return {
    tag: element.localName,
    id: element.id || '',
    classes: stableClasses(element),
    attributes,
    text: textSample(element),
    sibling_index: Math.max(0, siblings.indexOf(element)),
    ancestors
  };
}

function similarity(candidate, fingerprint) {
  if (!candidate || candidate.localName !== fingerprint.tag) return -1;
  let score = 3;
  if (fingerprint.id && candidate.id === fingerprint.id) score += 8;

  for (const className of fingerprint.classes || []) {
    if (candidate.classList.contains(className)) score += 2;
  }
  for (const [name, value] of Object.entries(fingerprint.attributes || {})) {
    if (candidate.getAttribute(name) === value) score += 4;
  }

  const sourceText = fingerprint.text || '';
  const candidateText = textSample(candidate);
  if (sourceText && candidateText) {
    if (candidateText === sourceText) score += 5;
    else if (candidateText.includes(sourceText) || sourceText.includes(candidateText)) score += 2;
  }

  const siblings = candidate.parentElement ? [...candidate.parentElement.children] : [];
  if (siblings.indexOf(candidate) === fingerprint.sibling_index) score += 1;
  return score;
}

export function captureAnchor(element, clientX, clientY) {
  const rect = element.getBoundingClientRect();
  const documentElement = document.documentElement;
  return {
    target_selector: createStableSelector(element),
    target_fingerprint: createFingerprint(element),
    relative_x: rect.width ? Math.min(1, Math.max(0, (clientX - rect.left) / rect.width)) : 0.5,
    relative_y: rect.height ? Math.min(1, Math.max(0, (clientY - rect.top) / rect.height)) : 0.5,
    document_x: clientX + window.scrollX,
    document_y: clientY + window.scrollY,
    document_width: Math.max(documentElement.scrollWidth, documentElement.clientWidth),
    document_height: Math.max(documentElement.scrollHeight, documentElement.clientHeight),
    element_rect: {
      x: rect.x + window.scrollX,
      y: rect.y + window.scrollY,
      width: rect.width,
      height: rect.height
    },
    viewport_width: window.innerWidth,
    viewport_height: window.innerHeight,
    scroll_x: window.scrollX,
    scroll_y: window.scrollY,
    device_pixel_ratio: window.devicePixelRatio || 1
  };
}

export function createAnchorResolver() {
  let cache = new Map();
  let revision = 0;

  return {
    invalidate() {
      revision += 1;
      cache = new Map();
    },

    resolve(pin) {
      const cached = cache.get(pin.id);
      if (cached?.revision === revision && cached.element?.isConnected) {
        return positionFor(cached.element, pin, false);
      }

      const fingerprint = pin.target_fingerprint || {};
      let candidates = [];
      try {
        if (pin.target_selector) {
          candidates = [...document.querySelectorAll(pin.target_selector)];
        }
      } catch {
        candidates = [];
      }

      let bestElement = null;
      let bestScore = -1;
      for (const candidate of candidates) {
        const score = similarity(candidate, fingerprint);
        if (score > bestScore) {
          bestElement = candidate;
          bestScore = score;
        }
      }

      if (!bestElement && fingerprint.id) {
        bestElement = document.getElementById(fingerprint.id);
        bestScore = similarity(bestElement, fingerprint);
      }

      if (!bestElement && fingerprint.tag) {
        const limitedCandidates = [...document.querySelectorAll(fingerprint.tag)].slice(0, 250);
        for (const candidate of limitedCandidates) {
          const score = similarity(candidate, fingerprint);
          if (score > bestScore) {
            bestElement = candidate;
            bestScore = score;
          }
        }
      }

      if (bestElement && bestScore >= 3) {
        cache.set(pin.id, { element: bestElement, revision });
        return positionFor(bestElement, pin, bestScore < 6);
      }

      return {
        x: Number(pin.anchor?.document_x || 0) - window.scrollX,
        y: Number(pin.anchor?.document_y || 0) - window.scrollY,
        uncertain: true
      };
    }
  };
}

function positionFor(element, pin, uncertain) {
  const rect = element.getBoundingClientRect();
  return {
    x: rect.left + rect.width * Number(pin.anchor?.relative_x ?? 0.5),
    y: rect.top + rect.height * Number(pin.anchor?.relative_y ?? 0.5),
    uncertain
  };
}
