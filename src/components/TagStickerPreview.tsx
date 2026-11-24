import React, { useEffect, useState } from 'react';
import {
  resolveDynamicText,
  type TagFontFamily,
  type TagLayer,
  type TagLayoutTemplate,
} from '../utils/tagLayoutTemplate';
import { createQrCodeDataUrl, getQrCodePayload, type BatchItem, type TagItem } from '../utils/pdfGenerator';

export const PT_TO_MM = 25.4 / 72; // 0.352778 mm per pt

export function getCssFontFamily(family: TagFontFamily): string {
  switch (family) {
    case 'KodeMono':
      return "'Kode Mono', ui-monospace, monospace";
    case 'times':
      return "Georgia, 'Times New Roman', serif";
    case 'helvetica':
    default:
      return "'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif";
  }
}

interface TagStickerPreviewProps {
  template: TagLayoutTemplate;
  pxPerMm: number;
  sampleBatch?: Partial<BatchItem>;
  sampleTag?: Partial<TagItem>;
  interactive?: boolean;
  selectedLayerId?: string | null;
  selectedLayerIds?: string[];
  onLayerPointerDown?: (e: React.PointerEvent, layer: TagLayer) => void;
  onResizePointerDown?: (e: React.PointerEvent, layer: TagLayer, handle: string) => void;
  onRotatePointerDown?: (e: React.PointerEvent, layer: TagLayer) => void;
  onArtboardClick?: () => void;
  onArtboardPointerDown?: (e: React.PointerEvent) => void;
  snapGuides?: { xMm: number | null; yMm: number | null };
  showGrid?: boolean;
}

export const TagStickerPreview: React.FC<TagStickerPreviewProps> = ({
  template,
  pxPerMm,
  sampleBatch,
  sampleTag,
  interactive = false,
  selectedLayerId = null,
  selectedLayerIds,
  onLayerPointerDown,
  onResizePointerDown,
  onRotatePointerDown,
  onArtboardClick,
  onArtboardPointerDown,
  snapGuides,
  showGrid = false,
}) => {
  const [qrUrls, setQrUrls] = useState<Record<string, string>>({});

  const effectiveBatch: BatchItem = {
    id: sampleBatch?.id || 'preview',
    batchId: sampleBatch?.batchId || 'SUM-2026-001',
    date: sampleBatch?.date || '2026-11-22',
    sku: sampleBatch?.sku || 'Parfum Signature 50ml',
    brand: sampleBatch?.brand || 'SUMAS',
    qty: sampleBatch?.qty || 100,
    qrType: sampleBatch?.qrType || 'dynamic',
    redirectUrl: sampleBatch?.redirectUrl || 'https://cek.sumas.id/',
  };

  const batchSampleTag = (sampleBatch as any)?.sampleTag as Partial<TagItem> | undefined;
  const fallbackPinLength = Math.min(12, Math.max(4, Number((sampleBatch as any)?.pinLength) || 8));
  const fallbackSamplePin =
    (sampleBatch as any)?.pinFormat === 'numeric'
      ? '482910573614'.slice(0, fallbackPinLength)
      : 'A7K9M2P4B6N8'.slice(0, fallbackPinLength);

  const effectiveTag: TagItem = {
    id: sampleTag?.id || 'tag-1',
    batchId: effectiveBatch.batchId,
    tagNumber: sampleTag?.tagNumber || batchSampleTag?.tagNumber || 'SUM-000128',
    pin: sampleTag?.pin || batchSampleTag?.pin || fallbackSamplePin,
  };

  const qrLayers = template.layers.filter((l) => l.type === 'qr' && l.visible);
  const qrSignature = JSON.stringify({
    payload: getQrCodePayload(effectiveBatch, effectiveTag),
    layers: qrLayers.map((l) =>
      l.type === 'qr'
        ? {
            id: l.id,
            d: l.qrDarkColor,
            l: l.qrLightColor,
            m: l.qrMargin,
            e: l.qrErrorCorrection,
          }
        : null
    ),
  });

  useEffect(() => {
    let active = true;
    const payload = getQrCodePayload(effectiveBatch, effectiveTag);
    Promise.all(
      qrLayers.map(async (layer) => {
        if (layer.type !== 'qr') return [layer.id, ''] as const;
        const url = await createQrCodeDataUrl(payload, {
          darkColor: layer.qrDarkColor,
          lightColor: layer.qrLightColor,
          margin: layer.qrMargin,
          errorCorrectionLevel: layer.qrErrorCorrection,
        });
        return [layer.id, url] as const;
      })
    ).then((entries) => {
      if (!active) return;
      const map: Record<string, string> = {};
      for (const [id, url] of entries) {
        if (url) map[id] = url;
      }
      setQrUrls(map);
    });
    return () => {
      active = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [qrSignature]);

  const widthPx = Math.max(20, template.sticker.widthMm * pxPerMm);
  const heightPx = Math.max(20, template.sticker.heightMm * pxPerMm);
  const radiusPx = Math.min(
    Math.max(0, template.sticker.cornerRadiusMm * pxPerMm),
    Math.min(widthPx, heightPx) / 2
  );

  const rawPin = String(effectiveTag.pin || fallbackSamplePin).replace(/\s+/g, '');
  const pinHalf = Math.ceil(rawPin.length / 2);

  return (
    <div
      onPointerDown={(e) => {
        if (!interactive || e.target !== e.currentTarget) return;
        if (onArtboardPointerDown) {
          onArtboardPointerDown(e);
        } else if (onArtboardClick) {
          onArtboardClick();
        }
      }}
      className="relative select-none overflow-visible"
      style={{
        width: `${widthPx}px`,
        height: `${heightPx}px`,
      }}
    >
      {/* Sticker Surface (clipped to corner radius) */}
      <div
        onPointerDown={(e) => {
          if (!interactive || e.target !== e.currentTarget) return;
          if (onArtboardPointerDown) {
            onArtboardPointerDown(e);
          } else if (onArtboardClick) {
            onArtboardClick();
          }
        }}
        className="absolute inset-0 overflow-hidden"
        style={{
          backgroundColor: template.sticker.backgroundColor || '#ffffff',
          borderRadius: `${radiusPx}px`,
        }}
      >
        {/* Optional Background Artwork */}
        {template.sticker.backgroundImageDataUrl && (
          <img
            src={template.sticker.backgroundImageDataUrl}
            alt="Sticker Background"
            draggable={false}
            className="absolute inset-0 w-full h-full object-fill pointer-events-none"
          />
        )}

        {/* Optional 1mm / 5mm Grid Overlay */}
        {showGrid && (
          <div
            aria-hidden="true"
            className="absolute inset-0 pointer-events-none opacity-45"
            style={{
              backgroundImage: `
                linear-gradient(to right, rgba(148,163,184,0.22) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(148,163,184,0.22) 1px, transparent 1px)
              `,
              backgroundSize: `${Math.max(4, pxPerMm)}px ${Math.max(4, pxPerMm)}px`,
            }}
          />
        )}

        {/* Layers */}
        {template.layers.map((layer) => {
          if (!layer.visible) return null;

          const left = layer.xMm * pxPerMm;
          const top = layer.yMm * pxPerMm;
          const width = Math.max(2, layer.widthMm * pxPerMm);
          const height = Math.max(2, layer.heightMm * pxPerMm);

          const justify =
            'align' in layer
              ? layer.align === 'left'
                ? 'flex-start'
                : layer.align === 'right'
                  ? 'flex-end'
                  : 'center'
              : 'center';

          return (
            <div
              key={layer.id}
              onPointerDown={(e) => {
                if (!interactive || layer.locked || !onLayerPointerDown) return;
                e.stopPropagation();
                onLayerPointerDown(e, layer);
              }}
              className={`absolute flex items-center ${
                interactive && !layer.locked ? 'cursor-move' : 'pointer-events-none'
              }`}
              style={{
                left: `${left}px`,
                top: `${top}px`,
                width: `${width}px`,
                height: `${height}px`,
                transform: layer.rotation ? `rotate(${layer.rotation}deg)` : undefined,
                transformOrigin: 'center center',
                justifyContent: justify,
              }}
            >
              {layer.type === 'shape' &&
                (layer.shapeType === 'line' ? (
                  <div
                    className="w-full"
                    style={{
                      height: `${Math.max(1, (layer.strokeWidthMm || 0.25) * pxPerMm)}px`,
                      backgroundColor: layer.strokeColor || '#cbd5e1',
                    }}
                  />
                ) : (
                  <div
                    className="w-full h-full"
                    style={{
                      backgroundColor: layer.transparentFill ? 'transparent' : layer.fillColor,
                      border:
                        layer.strokeWidthMm > 0
                          ? `${Math.max(1, layer.strokeWidthMm * pxPerMm)}px solid ${layer.strokeColor}`
                          : 'none',
                      borderRadius: `${Math.min(
                        Math.max(0, (layer.cornerRadiusMm || 0) * pxPerMm),
                        Math.min(width, height) / 2
                      )}px`,
                    }}
                  />
                ))}

              {layer.type === 'emblem' && (
                <div
                  className="rounded-full flex items-center justify-center font-serif font-bold shrink-0"
                  style={{
                    width: `${Math.min(width, height)}px`,
                    height: `${Math.min(width, height)}px`,
                    border: `${Math.max(1, (Math.min(width, height) / 2) * 0.1)}px solid ${layer.color}`,
                    color: layer.color,
                    fontSize: `${Math.max(6, (Math.min(layer.widthMm, layer.heightMm) / 2) * 1.75 * PT_TO_MM * pxPerMm)}px`,
                    lineHeight: 1,
                  }}
                >
                  {(layer.letter || 'S').slice(0, 2)}
                </div>
              )}

              {layer.type === 'brand' && (
                <span
                  className="whitespace-nowrap leading-none"
                  style={{
                    fontFamily: getCssFontFamily(layer.fontFamily),
                    fontWeight: layer.fontWeight === 'bold' ? 700 : 400,
                    fontSize: `${Math.max(5, layer.fontSizePt * PT_TO_MM * pxPerMm)}px`,
                    color: layer.color,
                    textAlign: layer.align,
                  }}
                >
                  {layer.uppercase
                    ? String(effectiveBatch.brand || 'SUMAS').slice(0, 24).toUpperCase()
                    : String(effectiveBatch.brand || 'SUMAS').slice(0, 24)}
                </span>
              )}

              {layer.type === 'tagId' && (
                <span
                  className="whitespace-nowrap leading-none"
                  style={{
                    fontFamily: getCssFontFamily(layer.fontFamily),
                    fontWeight: layer.fontWeight === 'bold' ? 700 : 400,
                    fontSize: `${Math.max(5, layer.fontSizePt * PT_TO_MM * pxPerMm)}px`,
                    color: layer.color,
                    textAlign: layer.align,
                  }}
                >
                  {`${layer.prefix ?? ''}${effectiveTag.tagNumber}`}
                </span>
              )}

              {layer.type === 'text' && (
                <span
                  className="whitespace-nowrap leading-none"
                  style={{
                    fontFamily: getCssFontFamily(layer.fontFamily),
                    fontWeight: layer.fontWeight === 'bold' ? 700 : 400,
                    fontSize: `${Math.max(5, layer.fontSizePt * PT_TO_MM * pxPerMm)}px`,
                    color: layer.color,
                    textAlign: layer.align,
                  }}
                >
                  {resolveDynamicText(layer.content, {
                    brand: effectiveBatch.brand,
                    sku: effectiveBatch.sku,
                    batchId: effectiveBatch.batchId,
                    tagNumber: effectiveTag.tagNumber,
                    pin: effectiveTag.pin,
                  })}
                </span>
              )}

              {layer.type === 'pin' && (
                <div
                  className="w-full flex flex-col justify-center leading-none"
                  style={{
                    alignItems:
                      layer.align === 'left'
                        ? 'flex-start'
                        : layer.align === 'right'
                          ? 'flex-end'
                          : 'center',
                    fontFamily: getCssFontFamily(layer.fontFamily),
                    fontWeight: layer.fontWeight === 'bold' ? 700 : 400,
                    fontSize: `${Math.max(6, layer.fontSizePt * PT_TO_MM * pxPerMm)}px`,
                    color: layer.color,
                  }}
                >
                  {layer.pinMode === '2-line' && rawPin.length > 4 ? (
                    <>
                      <span>{rawPin.slice(0, pinHalf)}</span>
                      <span
                        style={{
                          marginTop: `${Math.max(
                            0,
                            (layer.lineGapMm - layer.fontSizePt * PT_TO_MM * 0.72) * pxPerMm
                          )}px`,
                        }}
                      >
                        {rawPin.slice(pinHalf)}
                      </span>
                    </>
                  ) : (
                    <span>{rawPin}</span>
                  )}
                </div>
              )}

              {layer.type === 'qr' && (
                <div className="w-full h-full flex items-center justify-center overflow-hidden">
                  {qrUrls[layer.id] ? (
                    <img
                      src={qrUrls[layer.id]}
                      alt="QR Code"
                      draggable={false}
                      className="w-full h-full object-fill pointer-events-none"
                    />
                  ) : (
                    <div className="w-full h-full bg-slate-100 border border-slate-200 animate-pulse" />
                  )}
                </div>
              )}
            </div>
          );
        })}

        {/* Die-Cut Outline */}
        {template.sticker.showCutLine && (
          <div
            aria-hidden="true"
            className="absolute inset-0 pointer-events-none"
            style={{
              borderRadius: `${radiusPx}px`,
              border: `${Math.max(1, (template.sticker.cutLineWidthMm || 0.18) * pxPerMm)}px solid ${
                template.sticker.cutLineColor || '#dc2626'
              }`,
            }}
          />
        )}

        {/* Smart Snap Guides (Magenta like Adobe Illustrator) */}
        {interactive && snapGuides?.xMm !== null && snapGuides?.xMm !== undefined && (
          <div
            aria-hidden="true"
            className="absolute top-0 bottom-0 w-px bg-fuchsia-500 pointer-events-none z-30"
            style={{ left: `${snapGuides.xMm * pxPerMm}px` }}
          />
        )}
        {interactive && snapGuides?.yMm !== null && snapGuides?.yMm !== undefined && (
          <div
            aria-hidden="true"
            className="absolute left-0 right-0 h-px bg-fuchsia-500 pointer-events-none z-30"
            style={{ top: `${snapGuides.yMm * pxPerMm}px` }}
          />
        )}
      </div>

      {/* Interactive Selection Bounding Box + Resize Handles + Rotate Handle (outside clipped container!) */}
      {interactive &&
        (() => {
          const activeSelectedIds = selectedLayerIds ?? (selectedLayerId ? [selectedLayerId] : []);
          if (activeSelectedIds.length === 0) return null;
          const selectedLayers = template.layers.filter(
            (l) => l.visible && activeSelectedIds.includes(l.id)
          );
          if (selectedLayers.length === 0) return null;

          if (selectedLayers.length === 1) {
            const sel = selectedLayers[0];
            const left = sel.xMm * pxPerMm;
            const top = sel.yMm * pxPerMm;
            const width = Math.max(4, sel.widthMm * pxPerMm);
            const height = Math.max(4, sel.heightMm * pxPerMm);
            const handles = [
              { id: 'nw', cls: '-top-1 -left-1 cursor-nwse-resize' },
              { id: 'n', cls: '-top-1 left-1/2 -translate-x-1/2 cursor-ns-resize' },
              { id: 'ne', cls: '-top-1 -right-1 cursor-nesw-resize' },
              { id: 'e', cls: 'top-1/2 -right-1 -translate-y-1/2 cursor-ew-resize' },
              { id: 'se', cls: '-bottom-1 -right-1 cursor-nwse-resize' },
              { id: 's', cls: '-bottom-1 left-1/2 -translate-x-1/2 cursor-ns-resize' },
              { id: 'sw', cls: '-bottom-1 -left-1 cursor-nesw-resize' },
              { id: 'w', cls: 'top-1/2 -left-1 -translate-y-1/2 cursor-ew-resize' },
            ];

            return (
              <div
                onPointerDown={(e) => {
                  if (sel.locked || !onLayerPointerDown) return;
                  e.stopPropagation();
                  onLayerPointerDown(e, sel);
                }}
                className={`absolute z-40 border-[1.5px] border-[#0284c7] ${
                  sel.locked ? 'border-dashed opacity-70 cursor-not-allowed' : 'cursor-move'
                }`}
                style={{
                  left: `${left}px`,
                  top: `${top}px`,
                  width: `${width}px`,
                  height: `${height}px`,
                  transform: sel.rotation ? `rotate(${sel.rotation}deg)` : undefined,
                  transformOrigin: 'center center',
                }}
              >
                {!sel.locked && (
                  <>
                    {/* Rotation Handle */}
                    <div
                      onPointerDown={(e) => {
                        if (!onRotatePointerDown) return;
                        e.stopPropagation();
                        onRotatePointerDown(e, sel);
                      }}
                      title="Putar Elemen (Tahan Shift untuk snap 15°)"
                      className="absolute -top-6 left-1/2 -translate-x-1/2 flex flex-col items-center cursor-grab active:cursor-grabbing"
                    >
                      <div className="w-3 h-3 rounded-full bg-white border-2 border-[#0284c7] hover:bg-[#0284c7]" />
                      <div className="w-px h-3 bg-[#0284c7]" />
                    </div>

                    {/* 8 Resize Handles */}
                    {handles.map((h) => (
                      <div
                        key={h.id}
                        onPointerDown={(e) => {
                          if (!onResizePointerDown) return;
                          e.stopPropagation();
                          onResizePointerDown(e, sel, h.id);
                        }}
                        className={`absolute w-2.5 h-2.5 bg-white border-[1.5px] border-[#0284c7] rounded-[1px] hover:bg-[#0284c7] ${h.cls}`}
                      />
                    ))}
                  </>
                )}
              </div>
            );
          }

          // Multi-selection: render individual layer frames + combined bounding box
          const minX = Math.min(...selectedLayers.map((l) => l.xMm)) * pxPerMm;
          const minY = Math.min(...selectedLayers.map((l) => l.yMm)) * pxPerMm;
          const maxX = Math.max(...selectedLayers.map((l) => l.xMm + l.widthMm)) * pxPerMm;
          const maxY = Math.max(...selectedLayers.map((l) => l.yMm + l.heightMm)) * pxPerMm;

          return (
            <>
              <div
                aria-hidden="true"
                className="absolute z-30 border border-dashed border-[#0284c7]/75 pointer-events-none"
                style={{
                  left: `${minX}px`,
                  top: `${minY}px`,
                  width: `${Math.max(4, maxX - minX)}px`,
                  height: `${Math.max(4, maxY - minY)}px`,
                }}
              >
                <span className="absolute -top-5 left-0 px-1.5 py-0.5 rounded bg-[#0284c7] text-white text-[9px] font-mono font-bold whitespace-nowrap shadow-sm">
                  {selectedLayers.length} layer
                </span>
              </div>
              {selectedLayers.map((sel) => {
                const left = sel.xMm * pxPerMm;
                const top = sel.yMm * pxPerMm;
                const width = Math.max(4, sel.widthMm * pxPerMm);
                const height = Math.max(4, sel.heightMm * pxPerMm);
                return (
                  <div
                    key={sel.id}
                    onPointerDown={(e) => {
                      if (sel.locked || !onLayerPointerDown) return;
                      e.stopPropagation();
                      onLayerPointerDown(e, sel);
                    }}
                    className={`absolute z-40 border-[1.5px] border-[#0284c7] ${
                      sel.locked ? 'border-dashed opacity-70 cursor-not-allowed' : 'cursor-move'
                    }`}
                    style={{
                      left: `${left}px`,
                      top: `${top}px`,
                      width: `${width}px`,
                      height: `${height}px`,
                      transform: sel.rotation ? `rotate(${sel.rotation}deg)` : undefined,
                      transformOrigin: 'center center',
                    }}
                  />
                );
              })}
            </>
          );
        })()}
    </div>
  );
};
