import { ApiClient, ApiError } from './api-client.js';
import { captureAnchor, createAnchorResolver, positionAnchor } from './anchor.js';
import {
  createPinNavigationUrl,
  createCanonicalUrl,
  createPageKey,
  getRequestedPinId,
  hasClearParameter,
  installNavigationObserver,
  removeReviewLayerParameters
} from './page-key.js';

const VERSION = '1.1.3';
const MATERIAL_ICON_FONT_FAMILY = 'ReviewLayer Material Symbols';
const FILTERS = ['all', 'mobile', 'tablet', 'desktop'];
const ATTRIBUTION_MANIFEST = Object.freeze({
  payload: 'MkIuRGVzaWduIFdlYiBTdHVkaW98aHR0cHM6Ly93d3cud2ViLjJiLmRlc2lnbi8=',
  sha256: 'd3bb07d0ef598083e1352e1455e1a6aa7bc4b38dc2ff7137ab7c59c4a499fb17'
});
const ERROR_TRANSLATIONS = {
  NETWORK_ERROR: 'networkError',
  INVALID_RESPONSE: 'invalidResponse',
  VALIDATION_ERROR: 'validationError',
  ACCESS_DENIED: 'accessDenied',
  CSRF_ERROR: 'accessDenied',
  RATE_LIMITED: 'rateLimited',
  USAGE_LIMIT_REACHED: 'usageLimitReached',
  STORAGE_ERROR: 'storageError',
  NOT_FOUND: 'notFound',
  BACKUP_FAILED: 'backupFailed',
  CONFIGURATION_ERROR: 'configurationError'
};

function safeGet(storage, key, fallback = '') {
  try {
    return storage.getItem(key) ?? fallback;
  } catch {
    return fallback;
  }
}

function safeSet(storage, key, value) {
  try {
    storage.setItem(key, value);
  } catch {
    // Storage can be disabled by browser privacy settings; runtime state still works.
  }
}

function readAttributionManifest() {
  const [brand, url] = window.atob(ATTRIBUTION_MANIFEST.payload).split('|');
  return { brand, url };
}

async function verifyAttributionManifest() {
  if (!window.crypto?.subtle) return true;
  const payload = new TextEncoder().encode(window.atob(ATTRIBUTION_MANIFEST.payload));
  const digest = await window.crypto.subtle.digest('SHA-256', payload);
  const checksum = [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
  return checksum === ATTRIBUTION_MANIFEST.sha256;
}

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function createUuid() {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID();
  const bytes = new Uint8Array(16);
  window.crypto.getRandomValues(bytes);
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0'));
  return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
}

function materialIcon(name) {
  return `<span class="rl-material-icon" aria-hidden="true">${escapeHtml(name)}</span>`;
}

async function loadMaterialIconFont(baseUrl) {
  if (typeof FontFace !== 'function' || !document.fonts) return;
  const fontUrl = new URL(`assets/fonts/material-symbols-outlined.woff2?v=${VERSION}`, baseUrl).href;
  const font = new FontFace(
    MATERIAL_ICON_FONT_FAMILY,
    `url("${fontUrl}") format("woff2")`,
    { style: 'normal', weight: '400' }
  );
  document.fonts.add(font);
  await font.load();
}

function eyeIcon(hidden = false) {
  return materialIcon(hidden ? 'visibility_off' : 'visibility');
}

function settingsIcon() {
  return materialIcon('settings');
}

function menuIcon() {
  return materialIcon('menu');
}

function addModeIcon(active = false) {
  return materialIcon(active ? 'close' : 'add');
}

function positionIcon(direction) {
  return materialIcon(direction === 'up' ? 'arrow_upward' : 'arrow_downward');
}

function detectLanguage(mode) {
  if (mode === 'pl' || mode === 'en') return mode;
  const saved = safeGet(localStorage, 'reviewlayer:language');
  const candidates = [
    saved,
    document.documentElement.lang,
    ...(navigator.languages || []),
    navigator.language
  ].filter(Boolean);
  return candidates.some((candidate) => String(candidate).toLowerCase().startsWith('pl')) ? 'pl' : 'en';
}

function detectBrowser() {
  const ua = navigator.userAgent;
  let browser = 'Unknown';
  let engine = 'Unknown';
  let os = 'Unknown';

  if (/Firefox\//i.test(ua)) browser = 'Firefox';
  else if (/Edg\//i.test(ua)) browser = 'Edge';
  else if (/Chrome\//i.test(ua)) browser = 'Chrome';
  else if (/Safari\//i.test(ua)) browser = 'Safari';

  if (/Gecko\//i.test(ua) && /Firefox\//i.test(ua)) engine = 'Gecko';
  else if (/AppleWebKit\//i.test(ua) && !/(Chrome|Chromium|Edg)\//i.test(ua)) engine = 'WebKit';
  else if (/(Chrome|Chromium|Edg)\//i.test(ua)) engine = 'Blink';

  if (/Android/i.test(ua)) os = 'Android';
  else if (/iPhone|iPad|iPod/i.test(ua)) os = 'iOS';
  else if (/Windows/i.test(ua)) os = 'Windows';
  else if (/Mac OS X/i.test(ua)) os = 'macOS';
  else if (/Linux/i.test(ua)) os = 'Linux';

  return {
    user_agent: ua,
    user_agent_data: navigator.userAgentData?.toJSON?.() || null,
    browser,
    engine,
    os
  };
}

class ReviewLayerApp {
  constructor(options, translations, language) {
    this.baseUrl = options.baseUrl;
    this.projectKey = options.projectKey;
    this.languageMode = options.language;
    this.language = language;
    this.translations = translations;
    this.api = new ApiClient(this.baseUrl);
    this.authorId = safeGet(localStorage, 'reviewlayer:author-id') || createUuid();
    this.authorName = safeGet(localStorage, 'reviewlayer:author-name');
    this.accessCode = safeGet(sessionStorage, `reviewlayer:${this.projectKey}:access-code`);
    this.visibilityKey = `reviewlayer:${this.projectKey}:pins-visible`;
    this.pinsVisible = safeGet(localStorage, this.visibilityKey, 'true') !== 'false';
    this.toolbarPositionKey = `reviewlayer:${this.projectKey}:toolbar-position`;
    this.toolbarPosition = safeGet(localStorage, this.toolbarPositionKey, 'bottom') === 'top' ? 'top' : 'bottom';
    this.filter = 'all';
    this.pins = [];
    this.projectPins = [];
    this.currentPageKey = '';
    this.currentPageUrl = '';
    this.currentPin = null;
    this.panelType = '';
    this.adding = false;
    this.tempAnchor = null;
    this.tempTarget = null;
    this.routeController = null;
    this.panelController = null;
    this.positionFrame = 0;
    this.mutationTimer = 0;
    this.returnFocus = null;
    this.attributionObserver = null;
    this.anchorResolver = createAnchorResolver();
    this.api.setAccessCode(this.accessCode);
    safeSet(localStorage, 'reviewlayer:author-id', this.authorId);
  }

  t(key, replacements = {}) {
    let text = this.translations[key] ?? key;
    for (const [name, value] of Object.entries(replacements)) {
      text = text.replaceAll(`{${name}}`, String(value));
    }
    return text;
  }

  async init() {
    installNavigationObserver();
    this.createHost();
    this.bindEvents();
    this.observePage();
    try {
      this.bootstrapData = await this.api.bootstrap();
      await this.navigate(true);
    } catch (error) {
      this.handleError(error);
      if (hasClearParameter()) this.openAdminPanel();
    }
  }

  createHost() {
    this.host = document.createElement('div');
    this.host.id = 'reviewlayer-host';
    this.host.style.cssText = 'all:initial;position:fixed;inset:0;z-index:2147483647;pointer-events:none;contain:layout style;';
    document.body.append(this.host);
    this.shadow = this.host.attachShadow({ mode: 'open' });

    const stylesheet = document.createElement('link');
    stylesheet.rel = 'stylesheet';
    stylesheet.href = new URL(`assets/reviewlayer.css?v=${VERSION}`, this.baseUrl).href;
    this.shadow.append(stylesheet);

    this.root = document.createElement('div');
    this.root.className = 'rl-layer';
    this.shadow.append(this.root);
    this.renderShell();
  }

  renderShell() {
    this.attributionObserver?.disconnect();
    this.root.dataset.toolbarPosition = this.toolbarPosition;
    this.root.innerHTML = `
      <div class="rl-pins-layer${this.pinsVisible ? '' : ' is-hidden'}" data-role="pins"></div>
      <div class="rl-capture" data-role="capture" hidden></div>
      <div class="rl-highlight" data-role="highlight" hidden></div>
      <button class="rl-temp-pin" data-role="temp-pin" type="button" hidden aria-label="${escapeHtml(this.t('newPin'))}">${materialIcon('add')}</button>
      <div class="rl-instruction" data-role="instruction" hidden>${escapeHtml(this.t('addModeInstruction'))}</div>
      <div class="rl-toolbar is-${this.toolbarPosition}" role="toolbar" aria-label="${escapeHtml(this.t('appName'))}">
        <button class="rl-button rl-button-muted rl-visibility" type="button" data-action="toggle-pins" aria-pressed="${this.pinsVisible}" aria-label="${escapeHtml(this.t(this.pinsVisible ? 'hidePins' : 'showPins'))}" title="${escapeHtml(this.t(this.pinsVisible ? 'hidePins' : 'showPins'))}">
          ${eyeIcon(!this.pinsVisible)}<span class="rl-label-full">${escapeHtml(this.t(this.pinsVisible ? 'hidePins' : 'showPins'))}</span><span class="rl-label-short">${escapeHtml(this.t('pinsShort'))}</span>
        </button>
        <div class="rl-filters" role="group" aria-label="${escapeHtml(this.t('filterPins'))}">
          ${FILTERS.map((filter) => `<button type="button" data-filter="${filter}" class="${this.filter === filter ? 'is-active' : ''}" aria-pressed="${this.filter === filter}">${escapeHtml(this.t(filter))}</button>`).join('')}
        </div>
        <button class="rl-button rl-button-primary" type="button" data-action="toggle-add" aria-label="${escapeHtml(this.t(this.adding ? 'cancelAdd' : 'addPin'))}"><span class="rl-add-icon" aria-hidden="true">${addModeIcon(this.adding)}</span><span class="rl-label-full">${escapeHtml(this.t(this.adding ? 'cancel' : 'addPin'))}</span><span class="rl-label-short">${escapeHtml(this.t(this.adding ? 'cancel' : 'addPinShort'))}</span></button>
        <div class="rl-toolbar-position" role="group" aria-label="${escapeHtml(this.t('toolbarPosition'))}">
          <span class="rl-position-icon${this.toolbarPosition === 'bottom' ? ' is-active' : ''}" data-position="bottom" aria-hidden="true">${positionIcon('down')}</span>
          <button class="rl-position-switch" type="button" role="switch" aria-checked="${this.toolbarPosition === 'top'}" data-action="toggle-toolbar-position" aria-label="${escapeHtml(this.t(this.toolbarPosition === 'bottom' ? 'moveToolbarTop' : 'moveToolbarBottom'))}" title="${escapeHtml(this.t(this.toolbarPosition === 'bottom' ? 'moveToolbarTop' : 'moveToolbarBottom'))}"><span aria-hidden="true"></span></button>
          <span class="rl-position-icon${this.toolbarPosition === 'top' ? ' is-active' : ''}" data-position="top" aria-hidden="true">${positionIcon('up')}</span>
        </div>
        <button class="rl-icon-button" type="button" data-action="project-pins" aria-label="${escapeHtml(this.t('allProjectPins'))}" title="${escapeHtml(this.t('allProjectPins'))}">${menuIcon()}</button>
        <button class="rl-icon-button" type="button" data-action="settings" aria-label="${escapeHtml(this.t('settings'))}" title="${escapeHtml(this.t('settings'))}">${settingsIcon()}</button>
      </div>
      <section class="rl-panel" data-role="panel" aria-live="polite" hidden></section>
      <div class="rl-toast" data-role="toast" role="status" aria-live="polite" aria-atomic="true"></div>`;
    this.pinLayer = this.root.querySelector('[data-role="pins"]');
    this.captureLayer = this.root.querySelector('[data-role="capture"]');
    this.highlight = this.root.querySelector('[data-role="highlight"]');
    this.tempPin = this.root.querySelector('[data-role="temp-pin"]');
    this.instruction = this.root.querySelector('[data-role="instruction"]');
    this.panel = this.root.querySelector('[data-role="panel"]');
    this.toast = this.root.querySelector('[data-role="toast"]');
    this.renderPins();
    if (this.adding) this.setAddModeUi(true);
  }

  bindEvents() {
    this.shadow.addEventListener('click', (event) => this.handleClick(event));
    this.shadow.addEventListener('change', (event) => this.handleChange(event));
    this.shadow.addEventListener('submit', (event) => this.handleSubmit(event));
    this.shadow.addEventListener('keydown', (event) => this.handleShadowKeydown(event));
    window.addEventListener('keydown', (event) => this.handleGlobalKeydown(event), true);
    window.addEventListener('scroll', () => this.schedulePositions(), { passive: true });
    window.addEventListener('resize', () => this.schedulePositions(), { passive: true });
    window.visualViewport?.addEventListener('resize', () => this.schedulePositions(), { passive: true });
    window.visualViewport?.addEventListener('scroll', () => this.schedulePositions(), { passive: true });
    window.addEventListener('popstate', () => this.navigate());
    window.addEventListener('hashchange', () => this.navigate());
    window.addEventListener('reviewlayer:navigation', () => this.navigate());
  }

  observePage() {
    this.resizeObserver = new ResizeObserver(() => this.schedulePositions());
    this.resizeObserver.observe(document.documentElement);
    this.mutationObserver = new MutationObserver(() => {
      window.clearTimeout(this.mutationTimer);
      this.mutationTimer = window.setTimeout(() => {
        this.anchorResolver.invalidate();
        this.schedulePositions();
      }, 180);
    });
    this.mutationObserver.observe(document.body, { childList: true, subtree: true, attributes: true });
  }

  async navigate(force = false) {
    const nextKey = createPageKey(window.location);
    const nextUrl = createCanonicalUrl(window.location);
    if (!force && nextKey === this.currentPageKey) {
      if (hasClearParameter()) this.openAdminPanel();
      else await this.openRequestedPinFromUrl();
      return;
    }

    this.routeController?.abort();
    this.panelController?.abort();
    this.routeController = new AbortController();
    this.cancelAdd();
    this.closePanel(false);
    this.currentPageKey = nextKey;
    this.currentPageUrl = nextUrl;
    this.pins = [];
    this.renderPins();

    try {
      const data = await this.api.listPins({
        project_key: this.projectKey,
        page_key: this.currentPageKey,
        page_url: this.currentPageUrl
      }, this.routeController.signal);
      this.pins = data.pins || [];
      this.renderPins();
      if (hasClearParameter()) {
        this.openAdminPanel();
      } else await this.openRequestedPinFromUrl();
    } catch (error) {
      if (error.name !== 'AbortError') this.handleError(error);
    }
  }

  async openRequestedPinFromUrl() {
    const requestedPinId = getRequestedPinId();
    if (!requestedPinId) return false;
    removeReviewLayerParameters();
    if (this.pins.some((pin) => pin.id === requestedPinId)) {
      await this.openConversation(requestedPinId);
    } else {
      this.showToast(this.t('pinNavigationFailed'), true);
    }
    return true;
  }

  renderPins() {
    if (!this.pinLayer) return;
    this.pinLayer.classList.toggle('is-hidden', !this.pinsVisible);
    const eligiblePins = this.pins.filter((pin) => this.filter === 'all' || pin.viewport?.device_type === this.filter);
    this.pinLayer.innerHTML = eligiblePins.map((pin) => {
      const firstMessage = (pin.first_message || '').slice(0, 100);
      const tooltipKey = pin.status === 'resolved' ? 'pinTooltipResolved' : 'pinTooltipOpen';
      const tooltip = this.t(tooltipKey, { message: firstMessage });
      const interactionState = pin.interaction_state || pin.anchor?.interaction_state || '';
      const stateClasses = `${pin.status === 'resolved' ? ' is-resolved' : ''}${this.currentPin?.id === pin.id ? ' is-active' : ''}${interactionState === 'hover' ? ' is-hover' : ''}`;
      const pinLabel = escapeHtml(this.t('pinNumber', { number: pin.pin_number }));
      const hoverHint = interactionState === 'hover' ? `<span class="rl-pin-tooltip-hint">${escapeHtml(this.t('pinTooltipHoverHint'))}</span>` : '';
      const tooltipMarkup = `<span class="rl-pin-tooltip" aria-hidden="true"><span>${escapeHtml(tooltip)}</span>${hoverHint}</span>`;
      return `<button class="rl-pin${stateClasses}" type="button" data-pin-id="${escapeHtml(pin.id)}" aria-label="${pinLabel}"><span>${escapeHtml(pin.pin_number)}</span><i aria-hidden="true"></i>${tooltipMarkup}</button><button class="rl-pin-mention${stateClasses}" type="button" data-pin-mention-id="${escapeHtml(pin.id)}" aria-label="${pinLabel}" hidden>${materialIcon('arrow_upward')}<span>${escapeHtml(pin.pin_number)}</span></button>`;
    }).join('');
    this.schedulePositions();
  }

  schedulePositions() {
    if (this.positionFrame) return;
    this.positionFrame = window.requestAnimationFrame(() => {
      this.positionFrame = 0;
      this.updatePositions();
    });
  }

  updatePositions() {
    for (const element of this.pinLayer?.querySelectorAll('[data-pin-id]') || []) {
      const pin = this.pins.find((candidate) => candidate.id === element.dataset.pinId);
      if (!pin) continue;
      const position = this.anchorResolver.resolve(pin);
      pin.anchor_uncertain = position.uncertain;
      element.style.setProperty('--rl-x', `${Math.round(position.x)}px`);
      element.style.setProperty('--rl-y', `${Math.round(position.y)}px`);
      element.classList.toggle('is-uncertain', position.uncertain);

      const mention = element.nextElementSibling;
      if (mention?.dataset.pinMentionId === pin.id) {
        const showMention = Boolean(position.mention && this.currentPin?.id === pin.id);
        mention.hidden = !showMention;
        if (showMention) {
          const arrowAngle = Math.atan2(position.y - position.mention.y, position.x - position.mention.x) * 180 / Math.PI + 90;
          mention.style.setProperty('--rl-x', `${Math.round(position.mention.x)}px`);
          mention.style.setProperty('--rl-y', `${Math.round(position.mention.y)}px`);
          mention.style.setProperty('--rl-mention-angle', `${Math.round(arrowAngle)}deg`);
        }
      }
    }

    if (this.tempAnchor && this.tempTarget?.isConnected) {
      const position = positionAnchor(this.tempTarget, this.tempAnchor);
      this.tempPin.style.setProperty('--rl-x', `${Math.round(position.x)}px`);
      this.tempPin.style.setProperty('--rl-y', `${Math.round(position.y)}px`);
    }
  }

  handleClick(event) {
    const actionElement = event.target.closest('[data-action]');
    if (actionElement) {
      const action = actionElement.dataset.action;
      if (action === 'toggle-pins') this.togglePins();
      else if (action === 'toggle-add') this.adding ? this.cancelAdd() : this.startAdd();
      else if (action === 'toggle-toolbar-position') this.toggleToolbarPosition();
      else if (action === 'project-pins') this.openProjectPins();
      else if (action === 'open-project-pin') this.navigateToProjectPin(actionElement.dataset.pinId);
      else if (action === 'settings') this.openSettings();
      else if (action === 'close-panel') this.closePanel();
      else if (action === 'refresh-pin') this.openConversation(this.currentPin?.id, true);
      else if (action === 'toggle-status') this.toggleStatus();
      else if (action === 'delete-pin') this.deleteCurrentPin();
      else if (action === 'delete-message') this.deleteMessage(actionElement.dataset.messageId);
      else if (action === 'show-pins') this.setPinsVisible(true);
      else if (action === 'create-backup') this.createManualBackup(actionElement);
      return;
    }

    const filterButton = event.target.closest('[data-filter]');
    if (filterButton) {
      this.filter = filterButton.dataset.filter;
      for (const button of this.root.querySelectorAll('[data-filter]')) {
        const active = button.dataset.filter === this.filter;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', String(active));
      }
      this.renderPins();
      return;
    }

    const pinButton = event.target.closest('[data-pin-id], [data-pin-mention-id]');
    if (pinButton) this.openConversation(pinButton.dataset.pinId || pinButton.dataset.pinMentionId);
  }

  handleChange(event) {
    if (event.target.matches('[data-clear-scope]')) {
      const mode = this.panel.querySelector('[data-clear-mode]');
      const allProjects = event.target.value === 'all_projects';
      if (allProjects) mode.value = 'purge';
      mode.disabled = allProjects;
    }
  }

  async handleSubmit(event) {
    event.preventDefault();
    const form = event.target;
    if (form.dataset.form === 'create-pin') await this.submitPin(form);
    else if (form.dataset.form === 'reply') await this.submitReply(form);
    else if (form.dataset.form === 'settings') await this.saveSettings(form);
    else if (form.dataset.form === 'clear-data') await this.submitClear(form);
  }

  handleGlobalKeydown(event) {
    const target = event.target;
    const isEditing = target instanceof HTMLInputElement
      || target instanceof HTMLTextAreaElement
      || target instanceof HTMLSelectElement
      || target?.isContentEditable;
    if (event.altKey && event.key.toLowerCase() === 'p' && !isEditing) {
      event.preventDefault();
      this.togglePins();
    }
    if (event.key === 'Escape') {
      if (this.adding || this.tempAnchor) this.cancelAdd();
      else if (this.panelType) this.closePanel();
    }
  }

  handleShadowKeydown(event) {
    const submitShortcut = (event.ctrlKey || event.metaKey)
      && !event.altKey
      && !event.shiftKey
      && !event.repeat
      && !event.isComposing
      && event.key === 'Enter';
    if (submitShortcut && event.target instanceof HTMLTextAreaElement) {
      const form = event.target.closest('form[data-form="create-pin"], form[data-form="reply"]');
      if (form) {
        event.preventDefault();
        form.requestSubmit();
        return;
      }
    }

    if (event.key !== 'Tab' || !this.panelType || this.panel.hidden) return;
    const focusable = [...this.panel.querySelectorAll('button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), a[href]')];
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable.at(-1);
    if (event.shiftKey && this.shadow.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && this.shadow.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  startAdd() {
    this.closePanel(false);
    this.adding = true;
    this.tempAnchor = null;
    this.tempTarget = null;
    this.setAddModeUi(true);
    this.captureMove = (event) => this.updateHighlight(event);
    this.captureClick = (event) => {
      if (event.composedPath().includes(this.host)) return;
      this.capturePoint(event);
    };
    document.addEventListener('pointermove', this.captureMove, true);
    document.addEventListener('click', this.captureClick, true);
  }

  setAddModeUi(active) {
    // Keep the page as the pointer target so native :hover states and
    // JavaScript-driven flyouts can still open while a pin is being placed.
    this.captureLayer.hidden = true;
    this.instruction.hidden = true;
    const addButton = this.root.querySelector('[data-action="toggle-add"]');
    if (addButton) {
      addButton.querySelector('.rl-add-icon').innerHTML = addModeIcon(active);
      addButton.querySelector('.rl-label-full').textContent = this.t(active ? 'cancel' : 'addPin');
      addButton.querySelector('.rl-label-short').textContent = this.t(active ? 'cancel' : 'addPinShort');
      addButton.setAttribute('aria-label', this.t(active ? 'cancelAdd' : 'addPin'));
    }
  }

  elementAtPoint(clientX, clientY) {
    const element = document.elementFromPoint(clientX, clientY);
    return element && element !== this.host ? element : document.body;
  }

  updateHighlight(event) {
    if (!this.adding || event.composedPath().includes(this.host)) return;
    const target = this.elementAtPoint(event.clientX, event.clientY);
    const rect = target.getBoundingClientRect();
    this.highlight.hidden = false;
    this.highlight.style.setProperty('--rl-left', `${rect.left}px`);
    this.highlight.style.setProperty('--rl-top', `${rect.top}px`);
    this.highlight.style.setProperty('--rl-width', `${rect.width}px`);
    this.highlight.style.setProperty('--rl-height', `${rect.height}px`);
  }

  capturePoint(event) {
    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
    const target = this.elementAtPoint(event.clientX, event.clientY);
    this.tempTarget = target;
    this.tempAnchor = captureAnchor(target, event.clientX, event.clientY);
    this.removeAddListeners();
    this.adding = false;
    this.captureLayer.hidden = true;
    this.instruction.hidden = true;
    this.highlight.hidden = true;
    this.tempPin.hidden = false;
    this.setAddModeUi(false);
    this.updatePositions();
    this.openCreatePanel();
  }

  removeAddListeners() {
    if (this.captureMove) document.removeEventListener('pointermove', this.captureMove, true);
    if (this.captureClick) document.removeEventListener('click', this.captureClick, true);
    this.captureMove = null;
    this.captureClick = null;
  }

  cancelAdd() {
    this.removeAddListeners();
    this.adding = false;
    this.tempAnchor = null;
    this.tempTarget = null;
    if (this.captureLayer) {
      this.captureLayer.hidden = true;
      this.highlight.hidden = true;
      this.instruction.hidden = true;
      this.tempPin.hidden = true;
      this.setAddModeUi(false);
    }
    if (this.panelType === 'create') this.closePanel(false);
  }

  openCreatePanel() {
    this.openPanel('create');
    this.panel.innerHTML = this.panelFrame(this.t('newPin'), `
      <form data-form="create-pin" class="rl-form">
        <label>${escapeHtml(this.t('yourName'))}<input name="author_name" maxlength="80" required autocomplete="name" value="${escapeHtml(this.authorName)}"></label>
        <label>${escapeHtml(this.t('firstComment'))}<textarea name="message" maxlength="5000" required rows="6" placeholder="${escapeHtml(this.t('commentPlaceholder'))}"></textarea></label>
        <div class="rl-form-actions"><button class="rl-button rl-button-muted" type="button" data-action="close-panel">${escapeHtml(this.t('cancel'))}</button><button class="rl-button rl-button-primary" type="submit">${escapeHtml(this.t('save'))}</button></div>
      </form>`);
    this.focusPanel('textarea');
  }

  async submitPin(form) {
    if (!this.tempAnchor || !this.tempTarget) return;
    const formData = new FormData(form);
    const authorName = String(formData.get('author_name') || '').trim();
    const message = String(formData.get('message') || '').trim();
    if (!authorName) return this.showToast(this.t('nameRequired'), true);
    if (!message) return this.showToast(this.t('commentRequired'), true);
    const pageKeyAtStart = this.currentPageKey;
    this.setFormBusy(form, true);
    try {
      const viewport = this.collectViewport();
      if (positionAnchor(this.tempTarget, this.tempAnchor).mention) {
        this.tempAnchor.interaction_state = 'hover';
      }
      const data = await this.api.createPin({
        project_key: this.projectKey,
        page_key: this.currentPageKey,
        page_url: this.currentPageUrl,
        author_id: this.authorId,
        author_name: authorName,
        message,
        target_selector: this.tempAnchor.target_selector,
        target_fingerprint: this.tempAnchor.target_fingerprint,
        anchor: this.tempAnchor,
        viewport,
        browser: detectBrowser()
      });
      if (pageKeyAtStart !== this.currentPageKey) {
        this.showToast(this.t('pageChanged'), true);
        return;
      }
      this.authorName = authorName;
      safeSet(localStorage, 'reviewlayer:author-name', authorName);
      this.pins.push(data.pin);
      this.tempAnchor = null;
      this.tempTarget = null;
      this.tempPin.hidden = true;
      this.closePanel(false);
      this.renderPins();
      this.showToast(this.t(this.pinsVisible ? 'pinSaved' : 'pinSavedWhileHidden'));
    } catch (error) {
      this.handleError(error);
    } finally {
      this.setFormBusy(form, false);
    }
  }

  collectViewport() {
    const documentElement = document.documentElement;
    const width = window.innerWidth;
    const breakpoints = this.bootstrapData?.breakpoints || { mobile: 600, desktop: 1024 };
    return {
      width,
      height: window.innerHeight,
      document_width: Math.max(documentElement.scrollWidth, documentElement.clientWidth),
      document_height: Math.max(documentElement.scrollHeight, documentElement.clientHeight),
      scroll_x: window.scrollX,
      scroll_y: window.scrollY,
      device_pixel_ratio: window.devicePixelRatio || 1,
      orientation: screen.orientation?.type || '',
      device_type: width < breakpoints.mobile ? 'mobile' : (width < breakpoints.desktop ? 'tablet' : 'desktop')
    };
  }

  async openConversation(id, preserveFocus = false) {
    if (!id) return;
    if (!preserveFocus) this.returnFocus = this.shadow.activeElement;
    this.currentPin = this.pins.find((pin) => pin.id === id) || { id };
    this.openPanel('conversation', preserveFocus);
    this.panel.innerHTML = this.panelFrame(this.t('conversation'), `<div class="rl-loading">${escapeHtml(this.t('loading'))}</div>`);
    this.renderPins();
    this.panelController?.abort();
    this.panelController = new AbortController();
    try {
      const data = await this.api.getPin(id, this.projectKey, this.panelController.signal);
      this.currentPin = data.pin;
      const resolvedAnchor = this.anchorResolver.resolve(this.currentPin);
      this.currentPin.anchor_uncertain = resolvedAnchor.uncertain;
      this.currentPin.interaction_state = this.currentPin.anchor?.interaction_state
        || (resolvedAnchor.mention ? 'hover' : '');
      this.renderConversation();
      this.renderPins();
      if (!preserveFocus) this.focusPanel('[data-action="close-panel"]');
    } catch (error) {
      if (error.name !== 'AbortError') this.handleError(error);
    }
  }

  renderConversation() {
    const pin = this.currentPin;
    if (!pin) return;
    const viewport = pin.viewport || {};
    const browser = pin.browser || {};
    const messages = (pin.messages || []).map((message) => `
      <article class="rl-message">
        <div class="rl-message-head"><strong>${escapeHtml(message.author_name)}</strong><time datetime="${escapeHtml(message.created_at)}">${escapeHtml(this.formatDate(message.created_at))}</time></div>
        <p>${escapeHtml(message.message)}</p>
        <button class="rl-text-button rl-danger-text" type="button" data-action="delete-message" data-message-id="${escapeHtml(message.id)}">${escapeHtml(this.t('deleteMessage'))}</button>
      </article>`).join('');
    const hiddenNotice = this.pinsVisible ? '' : `<div class="rl-notice">${escapeHtml(this.t('pinsAreCurrentlyHidden'))}<button class="rl-text-button" type="button" data-action="show-pins">${escapeHtml(this.t('showPinsInPanel'))}</button></div>`;
    const interactionState = pin.interaction_state || pin.anchor?.interaction_state || '';
    const title = `${this.t('pinNumber', { number: pin.pin_number })}${interactionState === 'hover' ? ' (:hover)' : ''}`;
    this.panel.innerHTML = this.panelFrame(title, `
      ${hiddenNotice}
      <div class="rl-status-row"><span class="rl-status is-${escapeHtml(pin.status)}">${escapeHtml(this.t(pin.status === 'resolved' ? 'statusResolved' : 'statusOpen'))}</span><button class="rl-text-button" type="button" data-action="toggle-status">${escapeHtml(this.t(pin.status === 'resolved' ? 'reopen' : 'resolve'))}</button><button class="rl-icon-button rl-small" type="button" data-action="refresh-pin" aria-label="${escapeHtml(this.t('refresh'))}" title="${escapeHtml(this.t('refresh'))}">${materialIcon('refresh')}</button></div>
      <div class="rl-messages">${messages || `<p class="rl-empty">${escapeHtml(this.t('noMessages'))}</p>`}</div>
      <form data-form="reply" class="rl-form rl-reply-form">
        ${this.authorName ? '' : `<label>${escapeHtml(this.t('yourName'))}<input name="author_name" maxlength="80" required autocomplete="name"></label>`}
        <label>${escapeHtml(this.t('reply'))}<textarea name="message" maxlength="5000" required rows="3" placeholder="${escapeHtml(this.t('replyPlaceholder'))}"></textarea></label>
        <button class="rl-button rl-button-primary" type="submit">${escapeHtml(this.t('send'))}</button>
      </form>
      <details class="rl-details"><summary>${escapeHtml(this.t('technicalDetails'))}</summary>
        <dl>
          <div><dt>${escapeHtml(this.t('pageAddress'))}</dt><dd>${escapeHtml(pin.page_url)}</dd></div>
          <div><dt>${escapeHtml(this.t('anchor'))}</dt><dd>${escapeHtml(pin.anchor_uncertain ? this.t('anchorUncertain') : this.t('anchorCertain'))}</dd></div>
          <div><dt>${escapeHtml(this.t('createdBy'))}</dt><dd>${escapeHtml(pin.author_name)}</dd></div>
          <div><dt>${escapeHtml(this.t('createdAt'))}</dt><dd>${escapeHtml(this.formatDate(pin.created_at))}</dd></div>
          <div><dt>${escapeHtml(this.t('addedViewport', { width: viewport.width || '?', height: viewport.height || '?' }))}</dt><dd>${escapeHtml(viewport.device_type || this.t('unknown'))}</dd></div>
          <div><dt>${escapeHtml(this.t('browser'))}</dt><dd>${escapeHtml(browser.browser || this.t('unknown'))}</dd></div>
          <div><dt>${escapeHtml(this.t('engine'))}</dt><dd>${escapeHtml(browser.engine || this.t('unknown'))}</dd></div>
          <div><dt>${escapeHtml(this.t('operatingSystem'))}</dt><dd>${escapeHtml(browser.os || this.t('unknown'))}</dd></div>
        </dl>
      </details>
      <button class="rl-button rl-button-danger" type="button" data-action="delete-pin">${escapeHtml(this.t('deletePin'))}</button>`);
  }

  async submitReply(form) {
    if (!this.currentPin?.id) return;
    const formData = new FormData(form);
    const authorName = String(formData.get('author_name') || this.authorName).trim();
    const message = String(formData.get('message') || '').trim();
    if (!authorName) return this.showToast(this.t('nameRequired'), true);
    if (!message) return this.showToast(this.t('commentRequired'), true);
    this.setFormBusy(form, true);
    try {
      await this.api.addMessage(this.currentPin.id, {
        project_key: this.projectKey,
        author_id: this.authorId,
        author_name: authorName,
        message
      });
      this.authorName = authorName;
      safeSet(localStorage, 'reviewlayer:author-name', authorName);
      this.showToast(this.t('replySent'));
      await this.openConversation(this.currentPin.id, true);
    } catch (error) {
      this.handleError(error);
    } finally {
      this.setFormBusy(form, false);
    }
  }

  async toggleStatus() {
    if (!this.currentPin?.id) return;
    try {
      const nextStatus = this.currentPin.status === 'resolved' ? 'open' : 'resolved';
      await this.api.updateStatus(this.currentPin.id, {
        project_key: this.projectKey,
        status: nextStatus,
        author_id: this.authorId
      });
      this.currentPin.status = nextStatus;
      const summary = this.pins.find((pin) => pin.id === this.currentPin.id);
      if (summary) summary.status = nextStatus;
      this.renderConversation();
      this.renderPins();
      this.showToast(this.t('statusChanged'));
    } catch (error) {
      this.handleError(error);
    }
  }

  async deleteCurrentPin() {
    if (!this.currentPin?.id) return;
    const confirmation = window.prompt(this.t('deletePinConfirm'));
    if (confirmation === null) return;
    if (confirmation.trim().toUpperCase() !== 'DEL') {
      this.showToast(this.t('deletePinConfirmationInvalid'), true);
      return;
    }
    let adminCode = '';
    try {
      await this.api.deletePin(this.currentPin.id, { project_key: this.projectKey, author_id: this.authorId, admin_code: adminCode });
    } catch (error) {
      if (error instanceof ApiError && error.code === 'ACCESS_DENIED') {
        adminCode = window.prompt(this.t('adminCodePrompt')) || '';
        if (!adminCode) return;
        try {
          await this.api.deletePin(this.currentPin.id, { project_key: this.projectKey, author_id: this.authorId, admin_code: adminCode });
        } catch (retryError) {
          return this.handleError(retryError);
        }
      } else {
        return this.handleError(error);
      }
    }
    this.pins = this.pins.filter((pin) => pin.id !== this.currentPin.id);
    this.closePanel(false);
    this.renderPins();
    this.showToast(this.t('pinDeleted'));
  }

  async deleteMessage(id) {
    if (!id || !window.confirm(this.t('deleteMessageConfirm'))) return;
    const body = { project_key: this.projectKey, author_id: this.authorId, admin_code: '' };
    try {
      await this.api.deleteMessage(id, body);
    } catch (error) {
      if (error instanceof ApiError && error.code === 'ACCESS_DENIED') {
        body.admin_code = window.prompt(this.t('adminCodePrompt')) || '';
        if (!body.admin_code) return;
        try {
          await this.api.deleteMessage(id, body);
        } catch (retryError) {
          return this.handleError(retryError);
        }
      } else {
        return this.handleError(error);
      }
    }
    this.showToast(this.t('messageDeleted'));
    await this.openConversation(this.currentPin.id, true);
  }

  togglePins() {
    this.setPinsVisible(!this.pinsVisible);
  }

  toggleToolbarPosition() {
    this.toolbarPosition = this.toolbarPosition === 'bottom' ? 'top' : 'bottom';
    safeSet(localStorage, this.toolbarPositionKey, this.toolbarPosition);
    this.root.dataset.toolbarPosition = this.toolbarPosition;

    const toolbar = this.root.querySelector('.rl-toolbar');
    toolbar?.classList.toggle('is-top', this.toolbarPosition === 'top');
    toolbar?.classList.toggle('is-bottom', this.toolbarPosition === 'bottom');

    const positionSwitch = this.root.querySelector('[data-action="toggle-toolbar-position"]');
    if (positionSwitch) {
      const label = this.t(this.toolbarPosition === 'bottom' ? 'moveToolbarTop' : 'moveToolbarBottom');
      positionSwitch.setAttribute('aria-checked', String(this.toolbarPosition === 'top'));
      positionSwitch.setAttribute('aria-label', label);
      positionSwitch.title = label;
    }

    for (const icon of this.root.querySelectorAll('.rl-position-icon')) {
      icon.classList.toggle('is-active', icon.dataset.position === this.toolbarPosition);
    }

    this.showToast(this.t(this.toolbarPosition === 'top' ? 'toolbarMovedTop' : 'toolbarMovedBottom'));
  }

  setPinsVisible(visible) {
    this.pinsVisible = visible;
    safeSet(localStorage, this.visibilityKey, String(visible));
    this.pinLayer.classList.toggle('is-hidden', !visible);
    const button = this.root.querySelector('[data-action="toggle-pins"]');
    if (button) {
      button.setAttribute('aria-pressed', String(visible));
      button.setAttribute('aria-label', this.t(visible ? 'hidePins' : 'showPins'));
      button.title = this.t(visible ? 'hidePins' : 'showPins');
      button.querySelector('.rl-material-icon').outerHTML = eyeIcon(!visible);
      button.querySelector('.rl-label-full').textContent = this.t(visible ? 'hidePins' : 'showPins');
    }
    if (visible) this.schedulePositions();
    if (this.panelType === 'conversation') this.renderConversation();
    this.showToast(this.t(visible ? 'pinsVisible' : 'pinsHidden'));
  }

  async openProjectPins() {
    this.openPanel('project-pins');
    this.panel.innerHTML = this.panelFrame(this.t('allProjectPins'), `<div class="rl-loading">${escapeHtml(this.t('loading'))}</div>`);
    this.panelController?.abort();
    this.panelController = new AbortController();
    this.focusPanel('[data-action="close-panel"]');

    try {
      const data = await this.api.listProjectPins(this.projectKey, this.panelController.signal);
      this.projectPins = data.pins || [];
      const items = this.projectPins.map((pin) => {
        const page = this.formatPageLabel(pin.page_url);
        const statusKey = pin.status === 'resolved' ? 'statusResolved' : 'statusOpen';
        return `<button class="rl-project-pin" type="button" data-action="open-project-pin" data-pin-id="${escapeHtml(pin.id)}" aria-label="${escapeHtml(this.t('openProjectPin', { number: pin.pin_number, page }))}">
          <span class="rl-project-pin-head"><strong>#${escapeHtml(pin.pin_number)}</strong><span class="rl-status is-${escapeHtml(pin.status)}">${escapeHtml(this.t(statusKey))}</span><span class="rl-project-pin-arrow" aria-hidden="true">${materialIcon('arrow_forward')}</span></span>
          <span class="rl-project-pin-page" title="${escapeHtml(pin.page_url)}">${escapeHtml(page)}</span>
          <span class="rl-project-pin-message">${escapeHtml(pin.first_message || this.t('noMessages'))}</span>
        </button>`;
      }).join('');
      this.panel.innerHTML = this.panelFrame(this.t('allProjectPins'), `
        <p class="rl-panel-intro">${escapeHtml(this.t('projectPinsIntro', { project: this.projectKey, count: this.projectPins.length }))}</p>
        <div class="rl-project-pins">${items || `<p class="rl-empty">${escapeHtml(this.t('noProjectPins'))}</p>`}</div>`);
    } catch (error) {
      if (error.name !== 'AbortError') this.handleError(error);
    }
  }

  navigateToProjectPin(id) {
    const pin = this.projectPins.find((candidate) => candidate.id === id);
    if (!pin) return this.showToast(this.t('pinNavigationFailed'), true);

    try {
      if (createPageKey(pin.page_url) === this.currentPageKey) {
        this.openConversation(pin.id);
        return;
      }
      window.location.assign(createPinNavigationUrl(pin.page_url, pin.id));
    } catch {
      this.showToast(this.t('pinNavigationFailed'), true);
    }
  }

  formatPageLabel(value) {
    try {
      const url = new URL(value, window.location.href);
      return `${url.pathname}${url.search}${url.hash}` || '/';
    } catch {
      return String(value || '/');
    }
  }

  openSettings() {
    const attribution = readAttributionManifest();
    const backupSection = this.bootstrapData?.admin_actions_enabled === false
      ? ''
      : `<section class="rl-settings-section" aria-labelledby="rl-backup-title">
          <h3 id="rl-backup-title">${escapeHtml(this.t('backupSettingsTitle'))}</h3>
          <p>${escapeHtml(this.t('backupSettingsDescription'))}</p>
          <button class="rl-button rl-button-muted" type="button" data-action="create-backup">${escapeHtml(this.t('createBackupNow'))}</button>
          <span class="rl-settings-result" data-role="backup-result" role="status" aria-live="polite"></span>
        </section>`;
    this.openPanel('settings');
    this.panel.innerHTML = this.panelFrame(this.t('settings'), `
      <form data-form="settings" class="rl-form">
        <label>${escapeHtml(this.t('yourName'))}<input name="author_name" maxlength="80" autocomplete="name" value="${escapeHtml(this.authorName)}"></label>
        <label>${escapeHtml(this.t('language'))}<select name="language"><option value="pl"${this.language === 'pl' ? ' selected' : ''}>${escapeHtml(this.t('languagePolish'))}</option><option value="en"${this.language === 'en' ? ' selected' : ''}>${escapeHtml(this.t('languageEnglish'))}</option></select></label>
        <label>${escapeHtml(this.t('accessCode'))}<input name="access_code" type="password" maxlength="200" autocomplete="off" value="${escapeHtml(this.accessCode)}"><small>${escapeHtml(this.t('accessCodeHint'))}</small></label>
        <div class="rl-help"><strong>${escapeHtml(this.t('shortcutHelp'))}</strong><span>${escapeHtml(this.t('togglePinsShortcut'))}</span><span>${escapeHtml(this.t('closeWithEscape'))}</span></div>
        <button class="rl-button rl-button-primary" type="submit">${escapeHtml(this.t('save'))}</button>
      </form>
      ${backupSection}
      ${this.attributionMarkup(attribution)}`, 'rl-settings-body');
    this.protectSettingsAttribution(attribution);
    this.focusPanel('input');
  }

  attributionMarkup(attribution = readAttributionManifest()) {
    return `<footer class="rl-attribution" data-reviewlayer-attribution data-attribution-hash="${ATTRIBUTION_MANIFEST.sha256}"><span>${escapeHtml(this.t('applicationCreatedBy'))}</span> <a href="${escapeHtml(attribution.url)}" target="_blank" rel="noopener noreferrer external">${escapeHtml(attribution.brand)}</a></footer>`;
  }

  protectSettingsAttribution(attribution) {
    this.attributionObserver?.disconnect();

    const ensureAttribution = () => {
      if (this.panelType !== 'settings') return;
      const body = this.panel.querySelector('.rl-settings-body');
      if (!body) return;

      const footer = body.querySelector('[data-reviewlayer-attribution]');
      const link = footer?.querySelector('a');
      const valid = footer?.className === 'rl-attribution'
        && footer.dataset.attributionHash === ATTRIBUTION_MANIFEST.sha256
        && !footer.hidden
        && !footer.getAttribute('style')
        && footer.lastElementChild === link
        && body.lastElementChild === footer
        && footer.querySelector('span')?.textContent === this.t('applicationCreatedBy')
        && link?.textContent === attribution.brand
        && link.getAttribute('href') === attribution.url
        && link.getAttribute('target') === '_blank'
        && link.getAttribute('rel') === 'noopener noreferrer external';

      if (valid) return;
      for (const element of body.querySelectorAll('.rl-attribution, [data-reviewlayer-attribution]')) element.remove();
      body.insertAdjacentHTML('beforeend', this.attributionMarkup(attribution));
    };

    ensureAttribution();
    this.attributionObserver = new MutationObserver(ensureAttribution);
    this.attributionObserver.observe(this.panel, {
      attributes: true,
      characterData: true,
      childList: true,
      subtree: true
    });

    verifyAttributionManifest().then((valid) => {
      if (!valid) console.error('ReviewLayer attribution integrity check failed.');
    }).catch(() => {
      console.error('ReviewLayer attribution integrity check could not be completed.');
    });
  }

  async createManualBackup(button) {
    if (this.bootstrapData?.admin_actions_enabled === false) {
      this.showToast(this.t('adminActionsDisabled'), true);
      return;
    }
    let adminCode = '';
    if (this.bootstrapData?.admin_code_configured !== false) {
      adminCode = window.prompt(this.t('adminCodePrompt')) || '';
      if (!adminCode) return;
    }

    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = this.t('loading');
    try {
      const result = await this.api.createBackup({
        project_key: this.projectKey,
        admin_code: adminCode
      });
      const message = this.t('manualBackupCreated', { file: result.backup_file });
      const status = this.panel.querySelector('[data-role="backup-result"]');
      if (status) status.textContent = message;
      this.showToast(message);
    } catch (error) {
      this.handleError(error);
    } finally {
      if (button.isConnected) {
        button.disabled = false;
        button.textContent = originalText;
      }
    }
  }

  async saveSettings(form) {
    const data = new FormData(form);
    this.authorName = String(data.get('author_name') || '').trim();
    this.accessCode = String(data.get('access_code') || '');
    const nextLanguage = String(data.get('language') || 'en');
    safeSet(localStorage, 'reviewlayer:author-name', this.authorName);
    safeSet(localStorage, 'reviewlayer:language', nextLanguage);
    safeSet(sessionStorage, `reviewlayer:${this.projectKey}:access-code`, this.accessCode);
    this.api.setAccessCode(this.accessCode);
    if (nextLanguage !== this.language) {
      try {
        this.translations = await loadTranslations(this.baseUrl, nextLanguage);
        this.language = nextLanguage;
        this.renderShell();
      } catch (error) {
        return this.handleError(error);
      }
    } else {
      this.closePanel(false);
    }
    this.showToast(this.t('settingsSaved'));
    await this.navigate(true);
  }

  openAdminPanel() {
    if (this.panelType === 'admin') return;
    if (this.bootstrapData?.admin_actions_enabled === false) {
      if (hasClearParameter()) removeReviewLayerParameters();
      this.showToast(this.t('adminActionsDisabled'), true);
      return;
    }
    const adminCodeField = this.bootstrapData?.admin_code_configured === false
      ? ''
      : `<label>${escapeHtml(this.t('adminCode'))}<input name="admin_code" type="password" maxlength="200" required autocomplete="off"></label>`;
    this.openPanel('admin');
    this.panel.innerHTML = this.panelFrame(this.t('clearData'), `
      <p class="rl-panel-intro">${escapeHtml(this.t('clearDataDescription'))}</p>
      <form data-form="clear-data" class="rl-form">
        <label>${escapeHtml(this.t('clearScope'))}<select name="scope" data-clear-scope><option value="current_page">${escapeHtml(this.t('currentPage'))}</option><option value="current_project">${escapeHtml(this.t('currentProject'))}</option><option value="resolved_in_project">${escapeHtml(this.t('resolvedInProject'))}</option><option value="all_projects">${escapeHtml(this.t('allProjects'))}</option></select></label>
        <label>${escapeHtml(this.t('deletionMode'))}<select name="mode" data-clear-mode><option value="soft">${escapeHtml(this.t('softDelete'))}</option><option value="purge">${escapeHtml(this.t('permanentPurge'))}</option></select></label>
        ${adminCodeField}
        <label>${escapeHtml(this.t('confirmation'))}<input name="confirmation" maxlength="64" required placeholder="${escapeHtml(this.t('confirmationDelete'))}"><small>${escapeHtml(this.t('confirmationDeleteAll'))}</small></label>
        <label class="rl-checkbox"><input name="continue_without_backup" type="checkbox" value="1"><span>${escapeHtml(this.t('continueWithoutBackup'))}</span></label>
        <p class="rl-warning">${escapeHtml(this.t('clearWarning'))}</p>
        <button class="rl-button rl-button-danger" type="submit">${escapeHtml(this.t('executeClear'))}</button>
      </form>`);
    this.focusPanel('select');
  }

  async submitClear(form) {
    const data = new FormData(form);
    const scope = String(data.get('scope'));
    const mode = String(data.get('mode'));
    this.setFormBusy(form, true);
    try {
      const result = await this.api.clearData({
        scope,
        mode: scope === 'all_projects' ? 'purge' : mode,
        project_key: this.projectKey,
        page_key: this.currentPageKey,
        page_url: this.currentPageUrl,
        confirmation: String(data.get('confirmation') || ''),
        admin_code: String(data.get('admin_code') || ''),
        continue_without_backup: data.get('continue_without_backup') === '1'
      });
      const message = this.t('clearComplete', { pins: result.deleted_pins, messages: result.deleted_messages });
      removeReviewLayerParameters();
      this.closePanel(false);
      this.showToast(result.backup_file ? `${message} ${this.t('backupCreated', { file: result.backup_file })}` : message);
      await this.navigate(true);
    } catch (error) {
      this.handleError(error);
    } finally {
      this.setFormBusy(form, false);
    }
  }

  openPanel(type, preserveFocus = false) {
    if (!preserveFocus) this.returnFocus = this.shadow.activeElement || document.activeElement;
    this.panelType = type;
    this.panel.hidden = false;
    this.panel.setAttribute('role', type === 'admin' ? 'dialog' : 'region');
    if (type === 'admin') this.panel.setAttribute('aria-modal', 'true');
    else this.panel.removeAttribute('aria-modal');
  }

  panelFrame(title, content, bodyClass = '') {
    return `<header class="rl-panel-header"><div><span class="rl-eyebrow">${escapeHtml(this.t('appName'))}</span><h2>${escapeHtml(title)}</h2></div><button class="rl-icon-button" type="button" data-action="close-panel" aria-label="${escapeHtml(this.t('close'))}" title="${escapeHtml(this.t('close'))}">${materialIcon('close')}</button></header><div class="rl-panel-body${bodyClass ? ` ${escapeHtml(bodyClass)}` : ''}">${content}</div>`;
  }

  closePanel(restoreFocus = true) {
    const wasAdmin = this.panelType === 'admin';
    const wasCreate = this.panelType === 'create';
    this.panelController?.abort();
    this.attributionObserver?.disconnect();
    this.panelType = '';
    this.currentPin = null;
    if (this.panel) {
      this.panel.hidden = true;
      this.panel.innerHTML = '';
    }
    if (wasCreate && this.tempAnchor) this.cancelAdd();
    this.renderPins();
    if (wasAdmin && hasClearParameter()) {
      removeReviewLayerParameters();
      this.showToast(this.t('clearCancelled'));
    }
    if (restoreFocus && this.returnFocus?.focus) this.returnFocus.focus();
    this.returnFocus = null;
  }

  focusPanel(selector = 'button, input, textarea, select') {
    window.requestAnimationFrame(() => this.panel.querySelector(selector)?.focus());
  }

  setFormBusy(form, busy) {
    for (const control of form.elements) {
      if (busy) {
        control.dataset.reviewLayerWasDisabled = String(control.disabled);
        control.disabled = true;
      } else {
        control.disabled = control.dataset.reviewLayerWasDisabled === 'true';
        delete control.dataset.reviewLayerWasDisabled;
      }
    }
    form.setAttribute('aria-busy', String(busy));
  }

  formatDate(value) {
    try {
      return new Intl.DateTimeFormat(this.language === 'pl' ? 'pl-PL' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
    } catch {
      return value;
    }
  }

  showToast(message, isError = false) {
    if (!this.toast) return;
    this.toast.textContent = message;
    this.toast.classList.toggle('is-error', isError);
    this.toast.classList.add('is-visible');
    window.clearTimeout(this.toastTimer);
    this.toastTimer = window.setTimeout(() => this.toast?.classList.remove('is-visible'), 4500);
  }

  handleError(error) {
    if (error?.name === 'AbortError') return;
    console.error('[ReviewLayer]', error);
    const translationKey = ERROR_TRANSLATIONS[error?.code] || 'requestFailed';
    this.showToast(this.t(translationKey), true);
  }
}

async function loadTranslations(baseUrl, language) {
  const response = await fetch(new URL(`assets/i18n/${language}.json?v=${VERSION}`, baseUrl), { cache: 'no-store' });
  if (!response.ok) throw new ApiError('NETWORK_ERROR', 'Translation file unavailable.', response.status);
  try {
    return await response.json();
  } catch (error) {
    throw new ApiError('INVALID_RESPONSE', error.message, response.status);
  }
}

export async function startReviewLayer(options) {
  if (!/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/.test(options.projectKey)) {
    console.error('[ReviewLayer] Invalid data-project value.');
    window.__reviewLayerLoaded = false;
    return;
  }
  const language = detectLanguage(options.language);
  await loadMaterialIconFont(options.baseUrl);
  const translations = await loadTranslations(options.baseUrl, language);
  const app = new ReviewLayerApp(options, translations, language);
  window.__reviewLayer = app;
  await app.init();
}

export { createPageKey };
