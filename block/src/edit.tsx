import { useEffect, useState } from '@wordpress/element';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, RangeControl, ToggleControl, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

interface BlockAttributes {
  appId: string;
  height: number;
  autoResize: boolean;
  align?: string;
}

interface EditProps {
  attributes: BlockAttributes;
  setAttributes: (attrs: Partial<BlockAttributes>) => void;
}

interface AppListEntry {
  id: string;
  name: string;
  version: string;
  modes: string[];
  isolation: 'iframe' | 'inline';
}

export default function Edit({ attributes, setAttributes }: EditProps): JSX.Element {
  const [apps, setApps] = useState<AppListEntry[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [showPreview, setShowPreview] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<AppListEntry[]>({ path: '/dsgo/v1/apps' })
      .then((list) => { if (!cancelled) setApps(list); })
      .catch((err: Error) => { if (!cancelled) setError(err.message); });
    return () => { cancelled = true; };
  }, []);

  const blockApps = (apps ?? []).filter((a) => Array.isArray(a.modes) && a.modes.includes('block'));
  const selected = blockApps.find((a) => a.id === attributes.appId) ?? null;
  const blockProps = useBlockProps();

  const onPickApp = (id: string) => setAttributes({ appId: id });
  const onChangeHeight = (h: number) => {
    const clamped = Math.max(100, Math.min(2000, h));
    setAttributes({ height: clamped });
  };
  const onToggleAutoResize = (v: boolean) => setAttributes({ autoResize: v });

  return (
    <div {...blockProps}>
      <InspectorControls>
        <PanelBody title="App">
          {error && <Notice status="error">Could not load apps: {error}</Notice>}
          {apps !== null && blockApps.length === 0 && (
            <Notice status="info">
              No block-mode apps installed. <a href="/wp-admin/edit.php?post_type=dsgo_app">Install one →</a>
            </Notice>
          )}
          {blockApps.length > 0 && (
            <SelectControl
              label="App"
              value={attributes.appId}
              options={[
                { value: '', label: 'Choose an app…' },
                ...blockApps.map((a) => ({
                  value: a.id,
                  label: `${a.name} (v${a.version})`,
                })),
              ]}
              onChange={onPickApp}
            />
          )}
        </PanelBody>
        <PanelBody title="Layout">
          <RangeControl
            label="Height (px)"
            value={attributes.height}
            min={100}
            max={2000}
            step={10}
            disabled={attributes.autoResize}
            onChange={onChangeHeight}
          />
          <ToggleControl
            label="Auto-resize to content"
            checked={attributes.autoResize}
            onChange={onToggleAutoResize}
            help="Lets the app set its own height. Requires the app to send a dsgo:resize message."
          />
          <ToggleControl
            label="Show live preview"
            checked={showPreview}
            onChange={setShowPreview}
            help="Renders the actual sandboxed iframe instead of a static card."
          />
        </PanelBody>
      </InspectorControls>

      {showPreview && attributes.appId ? (
        <iframe
          src={`/?dsgo_embed=${encodeURIComponent(attributes.appId)}&dsgo_h=${attributes.height}&dsgo_ar=${attributes.autoResize ? 1 : 0}`}
          sandbox="allow-scripts"
          style={{ width: '100%', height: attributes.height, border: 0, display: 'block' }}
        />
      ) : (
        <div className="dsgo-block-card">
          {selected ? (
            <>
              <div className="dsgo-block-card__title">{selected.name}</div>
              <div className="dsgo-block-card__meta">v{selected.version} · {selected.modes.join(', ')}</div>
            </>
          ) : (
            <div className="dsgo-block-card__placeholder">
              {apps === null ? 'Loading apps…' : 'Pick an app from the sidebar.'}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

export type { BlockAttributes, EditProps };
