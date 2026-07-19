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

function hasHoverRevealDeclaration(style) {
  const display = style.getPropertyValue('display').trim();
  const visibility = style.getPropertyValue('visibility').trim();
  const opacity = style.getPropertyValue('opacity').trim();
  const pointerEvents = style.getPropertyValue('pointer-events').trim();
  const maxHeight = style.getPropertyValue('max-height').trim();
  const maxWidth = style.getPropertyValue('max-width').trim();
  return (display && display !== 'none')
    || visibility === 'visible'
    || (opacity !== '' && Number(opacity) > 0)
    || (pointerEvents && pointerEvents !== 'none')
    || (maxHeight && maxHeight !== '0' && maxHeight !== '0px')
    || (maxWidth && maxWidth !== '0' && maxWidth !== '0px');
}

function selectorHasHoverDependentTarget(selector) {
  const hoverIndex = selector.indexOf(':hover');
  if (hoverIndex < 0) return false;
  const afterHover = selector.slice(hoverIndex + ':hover'.length);
  return /^\s/.test(afterHover) || /^[>+~]/.test(afterHover);
}

function ruleMarksHoverInteraction(rule, element) {
  if (!rule?.style || typeof rule.selectorText !== 'string' || !hasHoverRevealDeclaration(rule.style)) return false;
  for (const selector of rule.selectorText.split(',')) {
    const trimmedSelector = selector.trim();
    if (!selectorHasHoverDependentTarget(trimmedSelector)) continue;
    for (let candidate = element; candidate; candidate = candidate.parentElement) {
      try {
        if (candidate.matches(trimmedSelector)) return true;
      } catch {
        break;
      }
    }
  }
  return false;
}

function rulesMarkHoverInteraction(rules, element) {
  for (const rule of rules || []) {
    if (ruleMarksHoverInteraction(rule, element)) return true;
    try {
      if (rule.cssRules && rulesMarkHoverInteraction(rule.cssRules, element)) return true;
    } catch {
      // Cross-origin and protected stylesheets are intentionally ignored.
    }
  }
  return false;
}

function detectInteractionState(element) {
  for (const stylesheet of document.styleSheets) {
    try {
      if (rulesMarkHoverInteraction(stylesheet.cssRules, element)) return 'hover';
    } catch {
      // A page may contain external stylesheets whose rules cannot be inspected.
    }
  }
  return '';
}

export function captureAnchor(element, clientX, clientY) {
  const rect = element.getBoundingClientRect();
  const documentElement = document.documentElement;
  const fallbackAncestors = [];
  let ancestor = element.parentElement;

  while (ancestor && fallbackAncestors.length < 8) {
    const ancestorRect = ancestor.getBoundingClientRect();
    if (ancestorRect.width > 0 && ancestorRect.height > 0) {
      fallbackAncestors.push({
        selector: createStableSelector(ancestor),
        offset_x: clientX - ancestorRect.left,
        offset_y: clientY - ancestorRect.top
      });
    }
    ancestor = ancestor.parentElement;
  }

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
    fallback_ancestors: fallbackAncestors,
    interaction_state: detectInteractionState(element) || undefined,
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
        return positionAnchor(cached.element, pin.anchor, false);
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
        return positionAnchor(bestElement, pin.anchor, bestScore < 6);
      }

      return {
        x: Number(pin.anchor?.document_x || 0) - window.scrollX,
        y: Number(pin.anchor?.document_y || 0) - window.scrollY,
        uncertain: true
      };
    }
  };
}

function renderedRect(element) {
  const rect = element.getBoundingClientRect();
  const style = window.getComputedStyle(element);
  if (rect.width <= 0 || rect.height <= 0 || style.display === 'none' || style.visibility === 'hidden') return null;
  return rect;
}

function clamp(value, minimum, maximum) {
  return Math.min(maximum, Math.max(minimum, value));
}

function positionHiddenAnchor(ancestorRect, exactX, exactY, uncertain) {
  const x = clamp(exactX, ancestorRect.left, ancestorRect.right);
  const y = clamp(exactY, ancestorRect.top, ancestorRect.bottom);
  const mentionDistance = Math.hypot(exactX - x, exactY - y);

  return {
    x,
    y,
    uncertain,
    mention: mentionDistance >= 12 ? { x: exactX, y: exactY } : null
  };
}

export function positionAnchor(element, anchor = {}, uncertain = false) {
  const targetRect = renderedRect(element);
  if (targetRect) {
    return {
      x: targetRect.left + targetRect.width * Number(anchor.relative_x ?? 0.5),
      y: targetRect.top + targetRect.height * Number(anchor.relative_y ?? 0.5),
      uncertain
    };
  }

  for (const fallback of anchor.fallback_ancestors || []) {
    if (!fallback || typeof fallback.selector !== 'string') continue;

    let ancestor = null;
    try {
      ancestor = document.querySelector(fallback.selector);
    } catch {
      ancestor = null;
    }

    if (!ancestor) continue;
    const ancestorRect = renderedRect(ancestor);
    const offsetX = Number(fallback.offset_x);
    const offsetY = Number(fallback.offset_y);
    if (!ancestorRect || !Number.isFinite(offsetX) || !Number.isFinite(offsetY)) continue;

    return positionHiddenAnchor(
      ancestorRect,
      ancestorRect.left + offsetX,
      ancestorRect.top + offsetY,
      uncertain
    );
  }

  const storedX = Number(anchor.document_x) - window.scrollX;
  const storedY = Number(anchor.document_y) - window.scrollY;
  let visibleAncestor = element.parentElement;
  let ancestorRect = null;
  while (visibleAncestor && !ancestorRect) {
    ancestorRect = renderedRect(visibleAncestor);
    if (!ancestorRect) visibleAncestor = visibleAncestor.parentElement;
  }

  if (ancestorRect && Number.isFinite(storedX) && Number.isFinite(storedY)) {
    return positionHiddenAnchor(ancestorRect, storedX, storedY, uncertain);
  }
  if (Number.isFinite(storedX) && Number.isFinite(storedY)) {
    return { x: storedX, y: storedY, uncertain };
  }

  return ancestorRect
    ? { x: ancestorRect.left + ancestorRect.width / 2, y: ancestorRect.top + ancestorRect.height / 2, uncertain }
    : { x: 0, y: 0, uncertain };
}
