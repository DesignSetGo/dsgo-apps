/**
 * @jest-environment jsdom
 */
import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import Edit from '../src/edit';
import type { BlockAttributes } from '../src/edit';

jest.mock('@wordpress/api-fetch', () => ({
  __esModule: true,
  default: jest.fn(),
}));
import apiFetch from '@wordpress/api-fetch';

jest.mock('@wordpress/block-editor', () => ({
  InspectorControls: ({ children }: { children: React.ReactNode }) =>
    <div data-testid="inspector">{children}</div>,
  useBlockProps: () => ({ className: 'wp-block-dsgo-apps-embed-edit' }),
}));

jest.mock('@wordpress/components', () => ({
  PanelBody: ({ title, children }: any) =>
    <fieldset><legend>{title}</legend>{children}</fieldset>,
  SelectControl: ({ label, value, options, onChange }: any) => (
    <label>{label}
      <select data-testid="app-picker" value={value}
              onChange={(e) => onChange(e.target.value)}>
        {options.map((o: any) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
    </label>
  ),
  RangeControl: ({ label, value, onChange, disabled }: any) => (
    <label>{label}
      <input type="number" data-testid="height-slider" value={value} disabled={disabled}
             onChange={(e) => onChange(Number(e.target.value))} />
    </label>
  ),
  ToggleControl: ({ label, checked, onChange }: any) => (
    <label>
      <input type="checkbox"
             data-testid={`toggle-${label.toLowerCase().replace(/\s+/g, '-')}`}
             checked={checked} onChange={(e) => onChange(e.target.checked)} />
      {label}
    </label>
  ),
  Notice: ({ children }: any) => <div role="alert">{children}</div>,
}));

const baseAttrs: BlockAttributes = { appId: '', height: 480, autoResize: false };
const noopSet = jest.fn();

beforeEach(() => {
  (apiFetch as unknown as jest.Mock).mockReset();
  noopSet.mockReset();
});

describe('edit.tsx', () => {
  it('shows empty state when no apps with block mode are installed', async () => {
    (apiFetch as unknown as jest.Mock).mockResolvedValueOnce([
      { id: 'page-only', name: 'Page Only', version: '0.1.0', modes: ['page'], isolation: 'iframe' },
    ]);
    render(<Edit attributes={baseAttrs} setAttributes={noopSet} />);
    await waitFor(() => {
      expect(screen.getByText(/no.*block-mode.*apps installed/i)).toBeInTheDocument();
    });
  });

  it('lists only block-capable apps in the picker', async () => {
    (apiFetch as unknown as jest.Mock).mockResolvedValueOnce([
      { id: 'page-only', name: 'Page Only', version: '0.1.0', modes: ['page'], isolation: 'iframe' },
      { id: 'block-app', name: 'Block App', version: '0.2.0', modes: ['page', 'block'], isolation: 'iframe' },
    ]);
    render(<Edit attributes={baseAttrs} setAttributes={noopSet} />);
    await waitFor(() => {
      const picker = screen.getByTestId('app-picker') as HTMLSelectElement;
      const ids = Array.from(picker.options).map((o) => o.value);
      expect(ids).toContain('block-app');
      expect(ids).not.toContain('page-only');
    });
  });

  it('updates appId attribute when picker selection changes', async () => {
    (apiFetch as unknown as jest.Mock).mockResolvedValueOnce([
      { id: 'block-app', name: 'Block App', version: '0.2.0', modes: ['block'], isolation: 'iframe' },
    ]);
    render(<Edit attributes={baseAttrs} setAttributes={noopSet} />);
    await waitFor(() => screen.getByTestId('app-picker'));
    fireEvent.change(screen.getByTestId('app-picker'), { target: { value: 'block-app' } });
    expect(noopSet).toHaveBeenCalledWith({ appId: 'block-app' });
  });

  it('disables the height slider when autoResize is true', async () => {
    (apiFetch as unknown as jest.Mock).mockResolvedValueOnce([
      { id: 'block-app', name: 'Block App', version: '0.2.0', modes: ['block'], isolation: 'iframe' },
    ]);
    render(<Edit attributes={{ ...baseAttrs, appId: 'block-app', autoResize: true }} setAttributes={noopSet} />);
    await waitFor(() => screen.getByTestId('height-slider'));
    expect(screen.getByTestId('height-slider')).toBeDisabled();
  });

  it('toggle "Show live preview" replaces card with iframe', async () => {
    (apiFetch as unknown as jest.Mock).mockResolvedValueOnce([
      { id: 'block-app', name: 'Block App', version: '0.2.0', modes: ['block'], isolation: 'iframe' },
    ]);
    const { container } = render(
      <Edit attributes={{ ...baseAttrs, appId: 'block-app' }} setAttributes={noopSet} />,
    );
    expect(container.querySelector('iframe')).toBeNull();
    await waitFor(() => screen.getByTestId('toggle-show-live-preview'));
    fireEvent.click(screen.getByTestId('toggle-show-live-preview'));
    expect(container.querySelector('iframe')).not.toBeNull();
  });

  it('clamps height value when slider goes out of range', async () => {
    (apiFetch as unknown as jest.Mock).mockResolvedValueOnce([
      { id: 'block-app', name: 'Block App', version: '0.2.0', modes: ['block'], isolation: 'iframe' },
    ]);
    render(<Edit attributes={{ ...baseAttrs, appId: 'block-app' }} setAttributes={noopSet} />);
    await waitFor(() => screen.getByTestId('height-slider'));
    fireEvent.change(screen.getByTestId('height-slider'), { target: { value: '50' } });
    expect(noopSet).toHaveBeenCalledWith({ height: 100 });
    fireEvent.change(screen.getByTestId('height-slider'), { target: { value: '99999' } });
    expect(noopSet).toHaveBeenCalledWith({ height: 2000 });
  });
});
