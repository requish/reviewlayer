(() => {
  'use strict';

  document.documentElement.classList.add('has-js');

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const navToggle = document.querySelector('[data-nav-toggle]');
  const siteMenu = document.querySelector('[data-site-menu]');

  function setNavigation(open) {
    if (!navToggle || !siteMenu) return;
    navToggle.setAttribute('aria-expanded', String(open));
    navToggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    siteMenu.classList.toggle('is-open', open);
    const icon = navToggle.querySelector('.material-symbol');
    if (icon) icon.textContent = open ? 'close' : 'menu';
  }

  navToggle?.addEventListener('click', () => {
    setNavigation(navToggle.getAttribute('aria-expanded') !== 'true');
  });

  siteMenu?.addEventListener('click', (event) => {
    if (event.target.closest('a')) setNavigation(false);
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth > 860) setNavigation(false);
  }, { passive: true });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') setNavigation(false);
  });

  const copyButton = document.querySelector('[data-copy-snippet]');
  const snippet = document.querySelector('[data-install-snippet]');
  let copyResetTimer = 0;

  copyButton?.addEventListener('click', async () => {
    if (!snippet) return;
    try {
      await navigator.clipboard.writeText(snippet.textContent.trim());
      copyButton.textContent = 'Copied';
    } catch {
      const range = document.createRange();
      const selection = window.getSelection();
      range.selectNodeContents(snippet);
      selection?.removeAllRanges();
      selection?.addRange(range);
      copyButton.textContent = 'Select and copy';
    }
    window.clearTimeout(copyResetTimer);
    copyResetTimer = window.setTimeout(() => {
      copyButton.textContent = 'Copy snippet';
    }, 2200);
  });

  const revealElements = [...document.querySelectorAll('.reveal')];
  if (!reducedMotion.matches && 'IntersectionObserver' in window) {
    const revealObserver = new IntersectionObserver((entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        entry.target.classList.add('is-visible');
        revealObserver.unobserve(entry.target);
      }
    }, { rootMargin: '0px 0px -7% 0px', threshold: .08 });
    revealElements.forEach((element) => revealObserver.observe(element));
  } else {
    revealElements.forEach((element) => element.classList.add('is-visible'));
  }

  const palette = {
    background: '#0c0d14',
    panel: '#151724',
    panelSoft: '#11131d',
    line: 'rgba(255,255,255,.085)',
    lineStrong: 'rgba(255,255,255,.16)',
    text: 'rgba(247,247,251,.9)',
    muted: 'rgba(163,168,184,.52)',
    action: '#765cf6',
    actionBright: '#9b8aff',
    cyan: '#3dd9eb',
    magenta: '#cf63ff',
    success: '#79e39f'
  };

  function roundedRect(context, x, y, width, height, radius) {
    const r = Math.min(radius, width / 2, height / 2);
    context.beginPath();
    context.moveTo(x + r, y);
    context.lineTo(x + width - r, y);
    context.quadraticCurveTo(x + width, y, x + width, y + r);
    context.lineTo(x + width, y + height - r);
    context.quadraticCurveTo(x + width, y + height, x + width - r, y + height);
    context.lineTo(x + r, y + height);
    context.quadraticCurveTo(x, y + height, x, y + height - r);
    context.lineTo(x, y + r);
    context.quadraticCurveTo(x, y, x + r, y);
    context.closePath();
  }

  function fillRounded(context, x, y, width, height, radius, fillStyle) {
    roundedRect(context, x, y, width, height, radius);
    context.fillStyle = fillStyle;
    context.fill();
  }

  function strokeRounded(context, x, y, width, height, radius, strokeStyle, lineWidth = 1) {
    roundedRect(context, x, y, width, height, radius);
    context.strokeStyle = strokeStyle;
    context.lineWidth = lineWidth;
    context.stroke();
  }

  function drawGrid(context, width, height, gap = 36, alpha = .045) {
    context.save();
    context.strokeStyle = `rgba(255,255,255,${alpha})`;
    context.lineWidth = 1;
    for (let x = gap; x < width; x += gap) {
      context.beginPath();
      context.moveTo(x, 0);
      context.lineTo(x, height);
      context.stroke();
    }
    for (let y = gap; y < height; y += gap) {
      context.beginPath();
      context.moveTo(0, y);
      context.lineTo(width, y);
      context.stroke();
    }
    context.restore();
  }

  function drawPin(context, x, y, radius, color, phase, active = false) {
    context.save();
    context.translate(x, y);
    if (active) {
      context.beginPath();
      context.arc(0, 0, radius + 8 + Math.sin(phase) * 3, 0, Math.PI * 2);
      context.strokeStyle = 'rgba(118,92,246,.28)';
      context.lineWidth = 2;
      context.stroke();
    }
    context.shadowColor = color;
    context.shadowBlur = active ? 24 : 12;
    context.fillStyle = color;
    context.beginPath();
    context.arc(0, -2, radius, 0, Math.PI * 2);
    context.fill();
    context.beginPath();
    context.moveTo(-radius * .44, radius * .48);
    context.lineTo(radius * .82, radius * 1.25);
    context.lineTo(radius * .38, radius * .02);
    context.closePath();
    context.fill();
    context.shadowBlur = 0;
    context.beginPath();
    context.arc(-radius * .18, -radius * .22, radius * .24, 0, Math.PI * 2);
    context.fillStyle = '#fff';
    context.fill();
    context.restore();
  }

  function line(context, x1, y1, x2, y2, style, width = 1) {
    context.beginPath();
    context.moveTo(x1, y1);
    context.lineTo(x2, y2);
    context.strokeStyle = style;
    context.lineWidth = width;
    context.stroke();
  }

  function cubicBezierPoint(start, controlOne, controlTwo, end, progress) {
    const inverse = 1 - progress;
    const startWeight = inverse ** 3;
    const controlOneWeight = 3 * inverse ** 2 * progress;
    const controlTwoWeight = 3 * inverse * progress ** 2;
    const endWeight = progress ** 3;

    return {
      x: startWeight * start.x + controlOneWeight * controlOne.x + controlTwoWeight * controlTwo.x + endWeight * end.x,
      y: startWeight * start.y + controlOneWeight * controlOne.y + controlTwoWeight * controlTwo.y + endWeight * end.y
    };
  }


  class CanvasScene {
    constructor(canvas) {
      this.canvas = canvas;
      this.context = canvas.getContext('2d');
      this.type = canvas.dataset.canvas;
      this.width = 0;
      this.height = 0;
      this.visible = true;
      this.pointer = { x: .72, y: .28, active: false };
      this.resizeObserver = new ResizeObserver(() => this.resize());
      this.resizeObserver.observe(canvas);
      if (this.type === 'product') {
        canvas.addEventListener('pointermove', (event) => {
          const rect = canvas.getBoundingClientRect();
          this.pointer.x = (event.clientX - rect.left) / rect.width;
          this.pointer.y = (event.clientY - rect.top) / rect.height;
          this.pointer.active = true;
        });
        canvas.addEventListener('pointerleave', () => {
          this.pointer.active = false;
        });
      }
      this.resize();
    }

    resize() {
      const rect = this.canvas.getBoundingClientRect();
      if (!rect.width || !rect.height) return;
      const ratio = Math.min(window.devicePixelRatio || 1, 2);
      const pixelWidth = Math.round(rect.width * ratio);
      const pixelHeight = Math.round(rect.height * ratio);
      if (this.canvas.width !== pixelWidth || this.canvas.height !== pixelHeight) {
        this.canvas.width = pixelWidth;
        this.canvas.height = pixelHeight;
      }
      this.width = rect.width;
      this.height = rect.height;
      this.context.setTransform(ratio, 0, 0, ratio, 0, 0);
      this.draw(performance.now());
    }

    draw(time) {
      if (!this.width || !this.height) return;
      const seconds = reducedMotion.matches ? .75 : time / 1000;
      this.context.clearRect(0, 0, this.width, this.height);
      if (this.type === 'product') drawProduct(this.context, this.width, this.height, seconds, this.pointer);
      if (this.type === 'workflow') drawWorkflow(this.context, this.width, this.height, seconds);
      if (this.type === 'coverage') drawCoverage(this.context, this.width, this.height, seconds);
    }
  }

  function drawProduct(context, width, height, time, pointer) {
    const compact = width < 520;
    drawGrid(context, width, height, compact ? 28 : 36, .036);

    const glowX = (pointer.active ? pointer.x : .74 + Math.sin(time * .22) * .05) * width;
    const glowY = (pointer.active ? pointer.y : .27 + Math.cos(time * .2) * .04) * height;
    const glow = context.createRadialGradient(glowX, glowY, 0, glowX, glowY, Math.max(width, height) * .58);
    glow.addColorStop(0, 'rgba(118,92,246,.17)');
    glow.addColorStop(.42, 'rgba(61,217,235,.045)');
    glow.addColorStop(1, 'rgba(7,7,11,0)');
    context.fillStyle = glow;
    context.fillRect(0, 0, width, height);

    const inset = compact ? 16 : 22;
    const frameX = inset;
    const frameY = inset;
    const frameW = width - inset * 2;
    const frameH = height - inset * 2;
    fillRounded(context, frameX, frameY, frameW, frameH, compact ? 16 : 22, 'rgba(8,9,14,.8)');
    strokeRounded(context, frameX, frameY, frameW, frameH, compact ? 16 : 22, palette.lineStrong);

    const topH = compact ? 34 : 40;
    line(context, frameX, frameY + topH, frameX + frameW, frameY + topH, palette.line);
    for (let i = 0; i < 3; i += 1) {
      context.beginPath();
      context.arc(frameX + 18 + i * 12, frameY + topH / 2, 3, 0, Math.PI * 2);
      context.fillStyle = i === 0 ? 'rgba(255,125,143,.7)' : i === 1 ? 'rgba(255,209,102,.55)' : 'rgba(121,227,159,.58)';
      context.fill();
    }
    fillRounded(context, frameX + (compact ? 64 : 82), frameY + 11, frameW * .36, 16, 8, 'rgba(255,255,255,.055)');

    const contentX = frameX + (compact ? 14 : 22);
    const contentY = frameY + topH + (compact ? 14 : 22);
    const conversationW = compact ? frameW * .38 : frameW * .34;
    const gap = compact ? 10 : 16;
    const pageW = frameW - (contentX - frameX) * 2 - conversationW - gap;
    const pageH = frameH - topH - (compact ? 28 : 44);

    fillRounded(context, contentX, contentY, pageW, pageH, compact ? 12 : 16, 'rgba(255,255,255,.026)');
    strokeRounded(context, contentX, contentY, pageW, pageH, compact ? 12 : 16, palette.line);

    const heroH = pageH * .42;
    const heroGradient = context.createLinearGradient(contentX, contentY, contentX + pageW, contentY + heroH);
    heroGradient.addColorStop(0, 'rgba(118,92,246,.18)');
    heroGradient.addColorStop(1, 'rgba(61,217,235,.055)');
    fillRounded(context, contentX + 10, contentY + 10, pageW - 20, heroH - 15, compact ? 9 : 12, heroGradient);
    fillRounded(context, contentX + 22, contentY + 31, pageW * .55, compact ? 8 : 10, 5, 'rgba(247,247,251,.76)');
    fillRounded(context, contentX + 22, contentY + 49, pageW * .39, 5, 3, 'rgba(163,168,184,.46)');
    fillRounded(context, contentX + 22, contentY + 61, pageW * .47, 5, 3, 'rgba(163,168,184,.28)');
    fillRounded(context, contentX + 22, contentY + 79, compact ? 54 : 70, compact ? 18 : 22, 7, palette.action);

    const cardY = contentY + heroH + 7;
    const cardGap = compact ? 7 : 10;
    const cardW = (pageW - 20 - cardGap) / 2;
    const cardH = Math.max(52, pageH - heroH - 24);
    for (let i = 0; i < 2; i += 1) {
      const x = contentX + 10 + i * (cardW + cardGap);
      fillRounded(context, x, cardY, cardW, cardH, compact ? 8 : 11, 'rgba(255,255,255,.038)');
      strokeRounded(context, x, cardY, cardW, cardH, compact ? 8 : 11, palette.line);
      fillRounded(context, x + 10, cardY + 12, cardW * .48, 5, 3, 'rgba(247,247,251,.46)');
      fillRounded(context, x + 10, cardY + 24, cardW * .72, 4, 2, 'rgba(163,168,184,.25)');
    }

    const panelX = contentX + pageW + gap;
    fillRounded(context, panelX, contentY, conversationW, pageH, compact ? 12 : 16, 'rgba(16,17,26,.96)');
    strokeRounded(context, panelX, contentY, conversationW, pageH, compact ? 12 : 16, 'rgba(118,92,246,.3)');
    fillRounded(context, panelX + 14, contentY + 17, conversationW * .52, 7, 4, 'rgba(247,247,251,.68)');
    line(context, panelX, contentY + 40, panelX + conversationW, contentY + 40, palette.line);

    const messageWidths = [.72, .57, .78];
    messageWidths.forEach((factor, index) => {
      const y = contentY + 58 + index * (compact ? 48 : 56);
      const messageW = conversationW * factor;
      const x = index === 1 ? panelX + conversationW - messageW - 12 : panelX + 12;
      fillRounded(context, x, y, messageW, compact ? 30 : 35, 9, index === 1 ? 'rgba(118,92,246,.14)' : 'rgba(255,255,255,.05)');
      fillRounded(context, x + 9, y + 9, messageW * .65, 4, 2, 'rgba(247,247,251,.35)');
      fillRounded(context, x + 9, y + 18, messageW * .42, 4, 2, 'rgba(163,168,184,.22)');
    });
    fillRounded(context, panelX + 12, contentY + pageH - 42, conversationW - 24, 28, 9, 'rgba(255,255,255,.04)');

    const activePinX = contentX + pageW * .68;
    const activePinY = contentY + heroH * .5;
    drawPin(context, activePinX, activePinY, compact ? 7 : 9, palette.actionBright, time * 3.2, true);
    drawPin(context, contentX + pageW * .28, cardY + cardH * .32, compact ? 6 : 8, palette.cyan, time * 2, false);
    drawPin(context, contentX + pageW * .73, cardY + cardH * .68, compact ? 6 : 8, palette.magenta, time * 2.4, false);

    context.save();
    context.setLineDash([4, 5]);
    line(context, activePinX + 12, activePinY, panelX - 6, contentY + 76, 'rgba(155,138,255,.55)', 1.2);
    context.restore();
  }

  function drawWorkflow(context, width, height, time) {
    drawGrid(context, width, height, width < 520 ? 30 : 44, .034);
    const background = context.createRadialGradient(width * .52, height * .46, 0, width * .52, height * .46, width * .65);
    background.addColorStop(0, 'rgba(118,92,246,.13)');
    background.addColorStop(.45, 'rgba(61,217,235,.035)');
    background.addColorStop(1, 'rgba(7,7,11,0)');
    context.fillStyle = background;
    context.fillRect(0, 0, width, height);

    const vertical = width < 520;
    const nodes = vertical
      ? [
          { x: width * .28, y: height * .2 },
          { x: width * .7, y: height * .4 },
          { x: width * .3, y: height * .6 },
          { x: width * .68, y: height * .8 }
        ]
      : [
          { x: width * .18, y: height * .28 },
          { x: width * .68, y: height * .24 },
          { x: width * .32, y: height * .65 },
          { x: width * .78, y: height * .72 }
        ];

    const connectionBend = vertical ? height * .05 : width * .09;

    context.save();
    context.setLineDash([6, 8]);
    for (let index = 0; index < nodes.length - 1; index += 1) {
      const from = nodes[index];
      const to = nodes[index + 1];
      context.beginPath();
      context.moveTo(from.x, from.y);
      context.bezierCurveTo(from.x + connectionBend, from.y, to.x - connectionBend, to.y, to.x, to.y);
      context.strokeStyle = index === 1 ? 'rgba(61,217,235,.28)' : 'rgba(118,92,246,.3)';
      context.lineWidth = 1.3;
      context.stroke();
    }
    context.restore();

    nodes.forEach((node, index) => {
      const size = vertical ? 74 : 88;
      const active = index === Math.floor(time * .42) % nodes.length;
      context.save();
      context.shadowColor = active ? palette.action : 'transparent';
      context.shadowBlur = active ? 26 : 0;
      fillRounded(context, node.x - size / 2, node.y - size / 2, size, size, vertical ? 18 : 22, active ? 'rgba(118,92,246,.19)' : 'rgba(16,17,26,.96)');
      strokeRounded(context, node.x - size / 2, node.y - size / 2, size, size, vertical ? 18 : 22, active ? 'rgba(155,138,255,.65)' : palette.lineStrong);
      context.shadowBlur = 0;
      context.beginPath();
      context.arc(node.x, node.y, active ? 10 : 8, 0, Math.PI * 2);
      context.fillStyle = index === 3 ? palette.success : index === 2 ? palette.cyan : palette.actionBright;
      context.fill();
      context.beginPath();
      context.arc(node.x, node.y, active ? 18 : 15, 0, Math.PI * 2);
      context.strokeStyle = active ? 'rgba(255,255,255,.35)' : 'rgba(255,255,255,.1)';
      context.stroke();
      context.restore();
    });

    const progress = (time * .18) % (nodes.length - 1);
    const segment = Math.floor(progress);
    const local = progress - segment;
    const from = nodes[segment];
    const to = nodes[segment + 1];
    const particle = cubicBezierPoint(
      from,
      { x: from.x + connectionBend, y: from.y },
      { x: to.x - connectionBend, y: to.y },
      to,
      local
    );
    context.beginPath();
    context.arc(particle.x, particle.y, 4.5, 0, Math.PI * 2);
    context.fillStyle = '#fff';
    context.shadowColor = palette.cyan;
    context.shadowBlur = 18;
    context.fill();
    context.shadowBlur = 0;
  }

  function drawCoverage(context, width, height, time) {
    drawGrid(context, width, height, 28, .038);
    const padding = 18;
    const gap = 12;
    const columnW = (width - padding * 2 - gap * 2) / 3;
    const deviceWidths = [.56, .75, 1];
    const colors = [palette.magenta, palette.cyan, palette.actionBright];
    const phase = Math.sin(time * .8) * .04;

    deviceWidths.forEach((factor, index) => {
      const centerX = padding + columnW * index + columnW / 2 + gap * index;
      const deviceW = Math.max(30, columnW * factor);
      const deviceH = height * (.47 + index * .08);
      const y = height - deviceH - 35;
      fillRounded(context, centerX - deviceW / 2, y, deviceW, deviceH, 10, 'rgba(255,255,255,.035)');
      strokeRounded(context, centerX - deviceW / 2, y, deviceW, deviceH, 10, 'rgba(255,255,255,.13)');
      fillRounded(context, centerX - deviceW * .31, y + 16, deviceW * .62, 5, 3, 'rgba(247,247,251,.36)');
      fillRounded(context, centerX - deviceW * .31, y + 29, deviceW * (.42 + index * .08), 4, 2, 'rgba(163,168,184,.2)');
      const markerY = y + deviceH * (.56 + phase * (index + 1));
      context.beginPath();
      context.arc(centerX + deviceW * .19, markerY, 5.5, 0, Math.PI * 2);
      context.fillStyle = colors[index];
      context.shadowColor = colors[index];
      context.shadowBlur = 14;
      context.fill();
      context.shadowBlur = 0;
    });

    line(context, padding, height - 20, width - padding, height - 20, palette.lineStrong);
  }

  const scenes = [...document.querySelectorAll('canvas[data-canvas]')].map((canvas) => new CanvasScene(canvas));

  if ('IntersectionObserver' in window) {
    const canvasObserver = new IntersectionObserver((entries) => {
      for (const entry of entries) {
        const scene = scenes.find((candidate) => candidate.canvas === entry.target);
        if (scene) scene.visible = entry.isIntersecting;
      }
    }, { rootMargin: '160px 0px', threshold: 0 });
    scenes.forEach((scene) => canvasObserver.observe(scene.canvas));
  }

  let animationFrame = 0;
  function animate(time) {
    if (!document.hidden && !reducedMotion.matches) {
      for (const scene of scenes) {
        if (scene.visible) scene.draw(time);
      }
    }
    animationFrame = window.requestAnimationFrame(animate);
  }

  function resetMotion() {
    scenes.forEach((scene) => scene.draw(performance.now()));
    if (!animationFrame) animationFrame = window.requestAnimationFrame(animate);
  }

  reducedMotion.addEventListener?.('change', resetMotion);
  resetMotion();
})();
