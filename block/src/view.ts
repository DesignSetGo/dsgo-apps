/**
 * Block view-script — runs on the published post page (Layer 0).
 *
 * Listens for `dsgo:embed:resize` messages forwarded from Layer 1's
 * parent-bridge, validates source, and resizes the matching iframe element.
 */
(() => {
  if (typeof window === 'undefined') return;

  function findIframeBySource(source: MessageEventSource | null): HTMLIFrameElement | null {
    if (!source) return null;
    const iframes = document.querySelectorAll<HTMLIFrameElement>(
      'iframe[data-dsgo-app-id][data-dsgo-auto-resize="1"]',
    );
    for (const el of iframes) {
      if (el.contentWindow === source) return el;
    }
    return null;
  }

  window.addEventListener('message', (event: MessageEvent): void => {
    if (!event.data || typeof event.data !== 'object') return;
    if (event.data.type !== 'dsgo:embed:resize') return;

    const iframe = findIframeBySource(event.source);
    if (!iframe) return;

    const raw = Number(event.data.height);
    if (!Number.isFinite(raw)) return;
    const clamped = Math.max(100, Math.min(2000, Math.round(raw)));
    iframe.style.height = clamped + 'px';
  });
})();
