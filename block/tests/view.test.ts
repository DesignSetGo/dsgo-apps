/**
 * @jest-environment jsdom
 */
import '@testing-library/jest-dom';

describe('block view.ts (Layer-0 resize listener)', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <iframe id="i1" data-dsgo-app-id="app-a" data-dsgo-auto-resize="1"
              style="width:100%;height:480px;border:0;" src="about:blank"></iframe>
      <iframe id="i2" data-dsgo-app-id="app-b" data-dsgo-auto-resize="1"
              style="width:100%;height:480px;border:0;" src="about:blank"></iframe>
      <iframe id="i3" data-dsgo-app-id="app-c" data-dsgo-auto-resize="0"
              style="width:100%;height:480px;border:0;" src="about:blank"></iframe>
    `;
    jest.resetModules();
    require('../src/view.ts');
  });

  it('resizes the matching iframe when source matches', () => {
    const i1 = document.getElementById('i1') as HTMLIFrameElement;
    const event = new MessageEvent('message', {
      data: { type: 'dsgo:embed:resize', height: 720, appId: 'app-a' },
      source: i1.contentWindow as any,
    });
    window.dispatchEvent(event);
    expect(i1.style.height).toBe('720px');
  });

  it('ignores messages from non-iframe sources', () => {
    const i1 = document.getElementById('i1') as HTMLIFrameElement;
    const original = i1.style.height;
    const event = new MessageEvent('message', {
      data: { type: 'dsgo:embed:resize', height: 720, appId: 'app-a' },
      source: window as any,
    });
    window.dispatchEvent(event);
    expect(i1.style.height).toBe(original);
  });

  it('does not resize iframes with auto-resize disabled', () => {
    const i3 = document.getElementById('i3') as HTMLIFrameElement;
    const event = new MessageEvent('message', {
      data: { type: 'dsgo:embed:resize', height: 720, appId: 'app-c' },
      source: i3.contentWindow as any,
    });
    window.dispatchEvent(event);
    expect(i3.style.height).not.toBe('720px');
  });

  it('clamps height to [100, 2000]', () => {
    const i1 = document.getElementById('i1') as HTMLIFrameElement;

    window.dispatchEvent(new MessageEvent('message', {
      data: { type: 'dsgo:embed:resize', height: 50, appId: 'app-a' },
      source: i1.contentWindow as any,
    }));
    expect(i1.style.height).toBe('100px');

    window.dispatchEvent(new MessageEvent('message', {
      data: { type: 'dsgo:embed:resize', height: 99999, appId: 'app-a' },
      source: i1.contentWindow as any,
    }));
    expect(i1.style.height).toBe('2000px');
  });
});
