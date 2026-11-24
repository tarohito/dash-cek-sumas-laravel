import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  AlignCenterHorizontal,
  AlignCenterVertical,
  AlignEndHorizontal,
  AlignEndVertical,
  AlignStartHorizontal,
  AlignStartVertical,
  ArrowDown,
  ArrowUp,
  Award,
  BoxSelect,
  BringToFront,
  Check,
  Copy,
  Download,
  Edit3,
  Eye,
  EyeOff,
  Grid,
  Hash,
  Image as ImageIcon,
  KeyRound,
  Layers,
  Lock,
  Magnet,
  QrCode,
  Redo2,
  RotateCcw,
  Save,
  Scissors,
  SendToBack,
  Sliders,
  Sparkles,
  Trash2,
  Type,
  Undo2,
  Unlock,
  X,
  ZoomIn,
  ZoomOut,
} from 'lucide-react';
import {
  cloneTemplate,
  computeSheetGrid,
  createNewLayer,
  getBuiltInTemplates,
  PAPER_SHEET_PRESETS,
  type PaperSheetPreset,
  type TagFontFamily,
  type TagLayer,
  type TagLayerType,
  type TagLayoutTemplate,
} from '../utils/tagLayoutTemplate';
import { type BatchItem, type TagItem } from '../utils/pdfGenerator';
import { TagStickerPreview } from './TagStickerPreview';
import { CustomSelect } from './CustomSelect';
import { CustomCheckbox } from './CustomCheckbox';
import { NewBadge, isRecentlyCreated } from './NewBadge';

interface TagLayoutStudioModalProps {
  isOpen: boolean;
  onClose: () => void;
  canEdit?: boolean;
  templates: TagLayoutTemplate[];
  activeTemplateId: string;
  onSaveTemplate: (template: TagLayoutTemplate, saveAsNew?: boolean) => TagLayoutTemplate;
  onDeleteTemplate: (templateId: string) => void;
  onSelectTemplate: (templateId: string) => void;
  sampleBatch?: Partial<BatchItem> | null;
  sampleTag?: Partial<TagItem> | null;
  onDownloadPdfWithTemplate?: (template: TagLayoutTemplate) => void;
}

const BASE_PX_PER_MM = 5.5;

type DragMode =
  | {
      kind: 'move';
      primaryLayerId: string;
      startClientX: number;
      startClientY: number;
      items: Array<{
        id: string;
        origXMm: number;
        origYMm: number;
      }>;
      origBounds: {
        minX: number;
        minY: number;
        maxX: number;
        maxY: number;
      };
    }
  | {
      kind: 'resize';
      layerId: string;
      handle: string;
      startClientX: number;
      startClientY: number;
      origXMm: number;
      origYMm: number;
      origWMm: number;
      origHMm: number;
    }
  | {
      kind: 'rotate';
      layerId: string;
      centerX: number;
      centerY: number;
    }
  | {
      kind: 'marquee';
      startClientX: number;
      startClientY: number;
      currentClientX: number;
      currentClientY: number;
      additive: boolean;
      baseSelection: string[];
    };

function roundMm(val: number): number {
  return Math.round(val * 10) / 10;
}

interface MmNumberInputProps {
  value: number;
  onCommit: (nextVal: number) => void;
  min?: number;
  max?: number;
  step?: number;
  fallback?: number;
  wrap360?: boolean;
  className?: string;
}

const MmNumberInput: React.FC<MmNumberInputProps> = ({
  value,
  onCommit,
  min,
  max,
  step = 1,
  fallback,
  wrap360 = false,
  className = '',
}) => {
  const [text, setText] = useState<string>(String(value));
  const isFocusedRef = useRef(false);

  useEffect(() => {
    if (!isFocusedRef.current) {
      setText(String(value));
    }
  }, [value]);

  const clampAndNormalize = (raw: string): number => {
    const normalized = raw.replace(',', '.').trim();
    if (normalized === '' || normalized === '-' || normalized === '.') {
      return fallback ?? min ?? 0;
    }
    let num = Number(normalized);
    if (!Number.isFinite(num)) {
      return fallback ?? min ?? 0;
    }
    if (wrap360) {
      num = ((num % 360) + 360) % 360;
    }
    if (min !== undefined) num = Math.max(min, num);
    if (max !== undefined) num = Math.min(max, num);
    return num;
  };

  return (
    <input
      type="text"
      inputMode="decimal"
      value={text}
      onFocus={(e) => {
        isFocusedRef.current = true;
        e.currentTarget.select();
      }}
      onChange={(e) => {
        const nextText = e.target.value;
        if (/^-?[0-9]*[.,]?[0-9]*$/.test(nextText)) {
          setText(nextText);
          const cleaned = nextText.replace(',', '.').trim();
          if (cleaned !== '' && cleaned !== '-' && cleaned !== '.') {
            const parsed = Number(cleaned);
            if (Number.isFinite(parsed) && (min === undefined || parsed >= min)) {
              const bounded = max !== undefined ? Math.min(max, parsed) : parsed;
              onCommit(wrap360 ? ((bounded % 360) + 360) % 360 : bounded);
            }
          }
        }
      }}
      onBlur={() => {
        isFocusedRef.current = false;
        const finalVal = clampAndNormalize(text);
        onCommit(finalVal);
        setText(String(finalVal));
      }}
      onKeyDown={(e) => {
        if (e.key === 'Enter') {
          e.currentTarget.blur();
        } else if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
          e.preventDefault();
          const cur = Number(text.replace(',', '.')) || 0;
          const delta = e.key === 'ArrowUp' ? step : -step;
          let nextVal = Math.round((cur + delta) * 100) / 100;
          if (wrap360) nextVal = ((nextVal % 360) + 360) % 360;
          if (min !== undefined) nextVal = Math.max(min, nextVal);
          if (max !== undefined) nextVal = Math.min(max, nextVal);
          setText(String(nextVal));
          onCommit(nextVal);
        }
      }}
      className={className}
    />
  );
};

export const TagLayoutStudioModal: React.FC<TagLayoutStudioModalProps> = ({
  isOpen,
  onClose,
  canEdit = true,
  templates,
  activeTemplateId,
  onSaveTemplate,
  onDeleteTemplate,
  onSelectTemplate,
  sampleBatch,
  sampleTag,
  onDownloadPdfWithTemplate,
}) => {
  const initialTemplate = useMemo(
    () =>
      cloneTemplate(
        templates.find((t) => t.id === activeTemplateId) ||
          templates[0] ||
          getBuiltInTemplates()[0]
      ),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [isOpen, activeTemplateId]
  );

  const [draft, setDraft] = useState<TagLayoutTemplate>(initialTemplate);
  const [historyPast, setHistoryPast] = useState<TagLayoutTemplate[]>([]);
  const [historyFuture, setHistoryFuture] = useState<TagLayoutTemplate[]>([]);
  const [selectedLayerIds, setSelectedLayerIds] = useState<string[]>(() =>
    initialTemplate.layers[0]?.id ? [initialTemplate.layers[0].id] : []
  );
  const [marqueeRect, setMarqueeRect] = useState<{
    left: number;
    top: number;
    width: number;
    height: number;
  } | null>(null);
  const [inspectorTab, setInspectorTab] = useState<'layer' | 'artboard'>('layer');
  const [zoom, setZoom] = useState<number>(1.35);
  const [showGrid, setShowGrid] = useState<boolean>(true);
  const [snapEnabled, setSnapEnabled] = useState<boolean>(true);
  const [snapGuides, setSnapGuides] = useState<{ xMm: number | null; yMm: number | null }>({
    xMm: null,
    yMm: null,
  });
  const [saveAsName, setSaveAsName] = useState<string>('');
  const [showSaveAsDialog, setShowSaveAsDialog] = useState<boolean>(false);
  const [renameValue, setRenameValue] = useState<string>('');
  const [showRenameDialog, setShowRenameDialog] = useState<boolean>(false);
  const [savedFeedback, setSavedFeedback] = useState<string>('');
  const [, setLiveTick] = useState<number>(() => Date.now());

  useEffect(() => {
    if (!isOpen) return;
    const timer = setInterval(() => {
      setLiveTick(Date.now());
    }, 15000);
    return () => clearInterval(timer);
  }, [isOpen]);

  const dragRef = useRef<DragMode | null>(null);
  const draftRef = useRef<TagLayoutTemplate>(draft);
  const dragSnapshotRef = useRef<TagLayoutTemplate | null>(null);
  const canvasViewportRef = useRef<HTMLElement | null>(null);
  const artboardRef = useRef<HTMLDivElement | null>(null);
  const artworkInputRef = useRef<HTMLInputElement | null>(null);
  const lastClickedLayerIdRef = useRef<string | null>(initialTemplate.layers[0]?.id ?? null);
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;
  const templatesRef = useRef(templates);
  templatesRef.current = templates;
  const wasOpenRef = useRef<boolean>(false);
  const prevActiveTemplateIdRef = useRef<string | null>(null);

  draftRef.current = draft;

  const computeFitZoom = (tpl: TagLayoutTemplate): number => {
    const vp = canvasViewportRef.current;
    if (!vp) return 1.25;
    const availW = Math.max(180, vp.clientWidth - 96);
    const availH = Math.max(180, vp.clientHeight - 96);
    const baseW = Math.max(10, tpl.sticker.widthMm) * BASE_PX_PER_MM;
    const baseH = Math.max(10, tpl.sticker.heightMm) * BASE_PX_PER_MM;
    const rawFit = Math.min(availW / baseW, availH / baseH);
    return Math.min(3.0, Math.max(0.4, Math.floor(rawFit * 20) / 20));
  };

  const handleZoomFit = (targetTemplate?: TagLayoutTemplate) => {
    setZoom(computeFitZoom(targetTemplate || draftRef.current));
  };

  useEffect(() => {
    if (isOpen && !canEdit) {
      onCloseRef.current();
      return;
    }

    const justOpened = isOpen && !wasOpenRef.current;
    const switchedTemplate =
      isOpen &&
      wasOpenRef.current &&
      prevActiveTemplateIdRef.current !== null &&
      prevActiveTemplateIdRef.current !== activeTemplateId;

    wasOpenRef.current = isOpen;
    if (!isOpen) {
      prevActiveTemplateIdRef.current = null;
      return;
    }
    prevActiveTemplateIdRef.current = activeTemplateId;

    if (justOpened || switchedTemplate) {
      const list = templatesRef.current;
      const target =
        list.find((t) => t.id === activeTemplateId) ||
        list[0] ||
        getBuiltInTemplates()[0];
      const cloned = cloneTemplate(target);
      setDraft(cloned);
      setHistoryPast([]);
      setHistoryFuture([]);
      const defaultId = cloned.layers[cloned.layers.length - 1]?.id ?? null;
      setSelectedLayerIds(defaultId ? [defaultId] : []);
      lastClickedLayerIdRef.current = defaultId;
      setSavedFeedback('');
      requestAnimationFrame(() => {
        setZoom(computeFitZoom(cloned));
      });
    }
  }, [isOpen, canEdit, activeTemplateId]);

  const pxPerMm = BASE_PX_PER_MM * zoom;
  const gridMetrics = useMemo(() => computeSheetGrid(draft), [draft]);
  const selectedLayers = useMemo(
    () => draft.layers.filter((l) => selectedLayerIds.includes(l.id)),
    [draft.layers, selectedLayerIds]
  );
  const selectedLayer = useMemo(
    () => (selectedLayers.length === 1 ? selectedLayers[0] : null),
    [selectedLayers]
  );
  const selectedLayerIndex = useMemo(
    () => (selectedLayer ? draft.layers.findIndex((l) => l.id === selectedLayer.id) : -1),
    [draft.layers, selectedLayer]
  );

  const pushPastSnapshot = (snapshot: TagLayoutTemplate) => {
    setHistoryPast((past) => [...past.slice(-49), snapshot]);
    setHistoryFuture([]);
  };

  const updateDraft = (updater: (prev: TagLayoutTemplate) => TagLayoutTemplate) => {
    const prev = draftRef.current;
    const next = updater(prev);
    if (JSON.stringify(prev) !== JSON.stringify(next)) {
      pushPastSnapshot(cloneTemplate(prev));
      draftRef.current = next;
      setDraft(next);
    }
  };

  const handleUndo = () => {
    if (historyPast.length === 0) return;
    const previous = historyPast[historyPast.length - 1];
    setHistoryPast((past) => past.slice(0, -1));
    setHistoryFuture((future) => [cloneTemplate(draftRef.current), ...future.slice(0, 49)]);
    setDraft(cloneTemplate(previous));
  };

  const handleRedo = () => {
    if (historyFuture.length === 0) return;
    const next = historyFuture[0];
    setHistoryFuture((future) => future.slice(1));
    setHistoryPast((past) => [...past.slice(-49), cloneTemplate(draftRef.current)]);
    setDraft(cloneTemplate(next));
  };

  const updateSelectedLayer = (patch: Partial<TagLayer>) => {
    if (!selectedLayer) return;
    updateDraft((prev) => ({
      ...prev,
      layers: prev.layers.map((l) =>
        l.id === selectedLayer.id ? ({ ...l, ...patch } as TagLayer) : l
      ),
    }));
  };

  // Pointer Drag / Resize / Rotate / Marquee Handlers
  useEffect(() => {
    if (!isOpen) return;

    const handlePointerMove = (e: PointerEvent) => {
      const mode = dragRef.current;
      if (!mode) return;

      if (mode.kind === 'move') {
        const dxMm = (e.clientX - mode.startClientX) / pxPerMm;
        const dyMm = (e.clientY - mode.startClientY) / pxPerMm;
        const movingIds = new Set(mode.items.map((item) => item.id));
        const boundsW = mode.origBounds.maxX - mode.origBounds.minX;
        const boundsH = mode.origBounds.maxY - mode.origBounds.minY;

        setDraft((prev) => {
          let nextBoundsX = mode.origBounds.minX + dxMm;
          let nextBoundsY = mode.origBounds.minY + dyMm;
          let guideX: number | null = null;
          let guideY: number | null = null;

          if (snapEnabled) {
            const threshold = 0.7;
            const artW = prev.sticker.widthMm;
            const artH = prev.sticker.heightMm;

            const xCandidates = [0, artW / 2, artW];
            const yCandidates = [0, artH / 2, artH];

            for (const other of prev.layers) {
              if (movingIds.has(other.id) || !other.visible) continue;
              xCandidates.push(other.xMm, other.xMm + other.widthMm / 2, other.xMm + other.widthMm);
              yCandidates.push(other.yMm, other.yMm + other.heightMm / 2, other.yMm + other.heightMm);
            }

            // Snap left / center / right edge of moving layer (or group bounding box)
            const layerXOffsets = [0, boundsW / 2, boundsW];
            let bestDx = threshold;
            for (const off of layerXOffsets) {
              for (const cand of xCandidates) {
                const diff = cand - (nextBoundsX + off);
                if (Math.abs(diff) < Math.abs(bestDx)) {
                  bestDx = diff;
                  guideX = cand;
                }
              }
            }
            if (guideX !== null) nextBoundsX += bestDx;

            const layerYOffsets = [0, boundsH / 2, boundsH];
            let bestDy = threshold;
            for (const off of layerYOffsets) {
              for (const cand of yCandidates) {
                const diff = cand - (nextBoundsY + off);
                if (Math.abs(diff) < Math.abs(bestDy)) {
                  bestDy = diff;
                  guideY = cand;
                }
              }
            }
            if (guideY !== null) nextBoundsY += bestDy;
          }

          setSnapGuides({ xMm: guideX, yMm: guideY });

          const snappedDx = nextBoundsX - mode.origBounds.minX;
          const snappedDy = nextBoundsY - mode.origBounds.minY;
          const itemMap = new Map(mode.items.map((it) => [it.id, it]));

          return {
            ...prev,
            layers: prev.layers.map((l) => {
              const item = itemMap.get(l.id);
              if (!item) return l;
              return {
                ...l,
                xMm: roundMm(item.origXMm + snappedDx),
                yMm: roundMm(item.origYMm + snappedDy),
              } as TagLayer;
            }),
          };
        });
      } else if (mode.kind === 'resize') {
        const dxMm = (e.clientX - mode.startClientX) / pxPerMm;
        const dyMm = (e.clientY - mode.startClientY) / pxPerMm;

        setDraft((prev) => {
          const target = prev.layers.find((l) => l.id === mode.layerId);
          if (!target) return prev;

          let nextX = mode.origXMm;
          let nextY = mode.origYMm;
          let nextW = mode.origWMm;
          let nextH = mode.origHMm;

          if (mode.handle.includes('e')) {
            nextW = Math.max(2, mode.origWMm + dxMm);
          }
          if (mode.handle.includes('s')) {
            nextH = Math.max(1.5, mode.origHMm + dyMm);
          }
          if (mode.handle.includes('w')) {
            const candidateW = Math.max(2, mode.origWMm - dxMm);
            nextX = mode.origXMm + (mode.origWMm - candidateW);
            nextW = candidateW;
          }
          if (mode.handle.includes('n')) {
            const candidateH = Math.max(1.5, mode.origHMm - dyMm);
            nextY = mode.origYMm + (mode.origHMm - candidateH);
            nextH = candidateH;
          }

          // Keep QR square when dragging corner handles or holding Shift
          if (target.type === 'qr' && (mode.handle.length === 2 || e.shiftKey)) {
            const side = Math.max(4, Math.max(nextW, nextH));
            if (mode.handle.includes('w')) nextX = mode.origXMm + (mode.origWMm - side);
            if (mode.handle.includes('n')) nextY = mode.origYMm + (mode.origHMm - side);
            nextW = side;
            nextH = side;
          }

          return {
            ...prev,
            layers: prev.layers.map((l) =>
              l.id === mode.layerId
                ? ({
                    ...l,
                    xMm: roundMm(nextX),
                    yMm: roundMm(nextY),
                    widthMm: roundMm(nextW),
                    heightMm: roundMm(nextH),
                  } as TagLayer)
                : l
            ),
          };
        });
      } else if (mode.kind === 'rotate') {
        const angleRad = Math.atan2(e.clientY - mode.centerY, e.clientX - mode.centerX);
        let deg = (angleRad * 180) / Math.PI + 90;
        deg = ((deg % 360) + 360) % 360;
        if (e.shiftKey) {
          deg = Math.round(deg / 15) * 15;
        } else {
          deg = Math.round(deg);
        }
        setDraft((prev) => ({
          ...prev,
          layers: prev.layers.map((l) =>
            l.id === mode.layerId ? ({ ...l, rotation: deg % 360 } as TagLayer) : l
          ),
        }));
      } else if (mode.kind === 'marquee') {
        mode.currentClientX = e.clientX;
        mode.currentClientY = e.clientY;
        const left = Math.min(mode.startClientX, e.clientX);
        const top = Math.min(mode.startClientY, e.clientY);
        const width = Math.abs(e.clientX - mode.startClientX);
        const height = Math.abs(e.clientY - mode.startClientY);
        if (width >= 3 || height >= 3) {
          setMarqueeRect({ left, top, width, height });
          const artboardBox = artboardRef.current?.getBoundingClientRect();
          if (artboardBox) {
            const right = left + width;
            const bottom = top + height;
            const hitIds = draftRef.current.layers
              .filter((layer) => {
                if (!layer.visible || layer.locked) return false;
                const lLeft = artboardBox.left + layer.xMm * pxPerMm;
                const lTop = artboardBox.top + layer.yMm * pxPerMm;
                const lRight = lLeft + layer.widthMm * pxPerMm;
                const lBottom = lTop + layer.heightMm * pxPerMm;
                return !(lRight < left || lLeft > right || lBottom < top || lTop > bottom);
              })
              .map((l) => l.id);
            if (mode.additive) {
              const merged = Array.from(new Set([...mode.baseSelection, ...hitIds]));
              setSelectedLayerIds(merged);
            } else {
              setSelectedLayerIds(hitIds);
            }
          }
        }
      }
    };

    const handlePointerUp = () => {
      const mode = dragRef.current;
      if (mode) {
        dragRef.current = null;
        setSnapGuides({ xMm: null, yMm: null });
        setMarqueeRect(null);
        if (mode.kind === 'marquee') {
          const movedPx = Math.hypot(
            mode.currentClientX - mode.startClientX,
            mode.currentClientY - mode.startClientY
          );
          if (movedPx < 3 && !mode.additive) {
            setSelectedLayerIds([]);
          }
          dragSnapshotRef.current = null;
          return;
        }
        if (
          dragSnapshotRef.current &&
          JSON.stringify(dragSnapshotRef.current) !== JSON.stringify(draftRef.current)
        ) {
          pushPastSnapshot(dragSnapshotRef.current);
        }
        dragSnapshotRef.current = null;
      }
    };

    window.addEventListener('pointermove', handlePointerMove);
    window.addEventListener('pointerup', handlePointerUp);
    return () => {
      window.removeEventListener('pointermove', handlePointerMove);
      window.removeEventListener('pointerup', handlePointerUp);
    };
  }, [isOpen, pxPerMm, snapEnabled]);

  // Keyboard Adobe Illustrator Shortcuts & Nudge (Arrow keys = 0.5mm, Shift+Arrow = 2mm)
  useEffect(() => {
    if (!isOpen) return;
    const onKeyDown = (e: KeyboardEvent) => {
      const activeTag = (document.activeElement?.tagName || '').toLowerCase();
      if (activeTag === 'input' || activeTag === 'textarea' || activeTag === 'select') return;

      const mod = e.metaKey || e.ctrlKey;
      const keyLower = e.key.toLowerCase();

      if (e.key === 'Escape' && !showSaveAsDialog && !showRenameDialog && selectedLayerIds.length > 0) {
        e.preventDefault();
        setSelectedLayerIds([]);
        return;
      }

      if (mod) {
        // Select All Layers: Ctrl/Cmd + A
        if (keyLower === 'a') {
          e.preventDefault();
          setSelectedLayerIds(draft.layers.map((l) => l.id));
          setInspectorTab('layer');
          return;
        }
        // Undo: Ctrl/Cmd + Z | Redo: Ctrl/Cmd + Shift + Z (atau Ctrl + Y)
        if (keyLower === 'z') {
          e.preventDefault();
          if (e.shiftKey) {
            handleRedo();
          } else {
            handleUndo();
          }
          return;
        }
        if (keyLower === 'y') {
          e.preventDefault();
          handleRedo();
          return;
        }
        // Fit on Screen: Ctrl/Cmd + 0 | Actual Size 100%: Ctrl/Cmd + 1
        if (e.key === '0') {
          e.preventDefault();
          handleZoomFit();
          return;
        }
        if (e.key === '1') {
          e.preventDefault();
          setZoom(1);
          return;
        }
        // Zoom In: Ctrl/Cmd + = / + | Zoom Out: Ctrl/Cmd + -
        if (e.key === '=' || e.key === '+') {
          e.preventDefault();
          setZoom((z) => Math.min(3.0, roundMm(z + 0.15)));
          return;
        }
        if (e.key === '-') {
          e.preventDefault();
          setZoom((z) => Math.max(0.4, roundMm(z - 0.15)));
          return;
        }
        // Show/Hide Grid: Ctrl/Cmd + '
        if (e.key === "'") {
          e.preventDefault();
          setShowGrid((v) => !v);
          return;
        }
        // Duplicate Selected Layer(s): Ctrl/Cmd + D
        if (keyLower === 'd' && selectedLayers.length > 0) {
          e.preventDefault();
          handleDuplicateLayers(selectedLayers);
          return;
        }
        // Arrange Forward/Backward: Ctrl/Cmd + ] / [ (with Shift = Front / Back)
        if ((e.key === ']' || e.code === 'BracketRight') && selectedLayerIds.length > 0) {
          e.preventDefault();
          if (e.shiftKey) {
            handleMoveSelectedToEdge('front');
          } else {
            handleMoveSelectedOrder(1);
          }
          return;
        }
        if ((e.key === '[' || e.code === 'BracketLeft') && selectedLayerIds.length > 0) {
          e.preventDefault();
          if (e.shiftKey) {
            handleMoveSelectedToEdge('back');
          } else {
            handleMoveSelectedOrder(-1);
          }
          return;
        }
      }

      const unlockedSelected = selectedLayers.filter((l) => !l.locked);
      if (unlockedSelected.length === 0) return;

      const step = e.shiftKey ? 2.0 : 0.5;
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        nudgeSelectedLayers(-step, 0);
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        nudgeSelectedLayers(step, 0);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        nudgeSelectedLayers(0, -step);
      } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        nudgeSelectedLayers(0, step);
      } else if ((e.key === 'Delete' || e.key === 'Backspace') && draft.layers.length > 1) {
        e.preventDefault();
        handleDeleteLayers(unlockedSelected.map((l) => l.id));
      }
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, selectedLayers, selectedLayerIds, draft.layers, historyPast, historyFuture, showSaveAsDialog, showRenameDialog]);

  if (!isOpen || !canEdit) return null;

  const nudgeSelectedLayers = (dxMm: number, dyMm: number) => {
    const idSet = new Set(selectedLayers.filter((l) => !l.locked).map((l) => l.id));
    if (idSet.size === 0) return;
    updateDraft((prev) => ({
      ...prev,
      layers: prev.layers.map((l) =>
        idSet.has(l.id)
          ? ({ ...l, xMm: roundMm(l.xMm + dxMm), yMm: roundMm(l.yMm + dyMm) } as TagLayer)
          : l
      ),
    }));
  };

  const handleAddLayer = (type: TagLayerType) => {
    const newLayer = createNewLayer(type, draft.sticker.widthMm, draft.sticker.heightMm);
    updateDraft((prev) => ({
      ...prev,
      layers: [...prev.layers, newLayer],
    }));
    setSelectedLayerIds([newLayer.id]);
    lastClickedLayerIdRef.current = newLayer.id;
    setInspectorTab('layer');
  };

  const handleDeleteLayers = (layerIds: string[]) => {
    const idSet = new Set(layerIds);
    if (idSet.size === 0) return;
    updateDraft((prev) => {
      let remaining = prev.layers.filter((l) => !idSet.has(l.id));
      if (remaining.length === 0) {
        if (prev.layers.length <= 1) return prev;
        // Keep the bottom-most layer so at least 1 layer always remains
        remaining = [prev.layers[0]];
      }
      const nextSelected = selectedLayerIds.filter(
        (id) => !idSet.has(id) && remaining.some((r) => r.id === id)
      );
      if (nextSelected.length > 0) {
        setSelectedLayerIds(nextSelected);
      } else {
        const fallbackId = remaining[remaining.length - 1]?.id ?? null;
        setSelectedLayerIds(fallbackId ? [fallbackId] : []);
        lastClickedLayerIdRef.current = fallbackId;
      }
      return { ...prev, layers: remaining };
    });
  };

  const handleDeleteLayer = (layerId: string) => {
    handleDeleteLayers([layerId]);
  };

  const handleDuplicateLayers = (layersToCopy: TagLayer[]) => {
    if (layersToCopy.length === 0) return;
    const stamp = Date.now().toString(36);
    const copies: TagLayer[] = layersToCopy.map((layer, idx) => ({
      ...cloneTemplate({ ...draft, layers: [layer] }).layers[0],
      id: `layer-${layer.type}-${stamp}-${idx}`,
      label: `${layer.label} (Copy)`,
      xMm: roundMm(layer.xMm + 1.5),
      yMm: roundMm(layer.yMm + 1.5),
    }));
    updateDraft((prev) => ({ ...prev, layers: [...prev.layers, ...copies] }));
    const newIds = copies.map((c) => c.id);
    setSelectedLayerIds(newIds);
    lastClickedLayerIdRef.current = newIds[newIds.length - 1] ?? null;
  };

  const handleDuplicateLayer = (layer: TagLayer) => {
    handleDuplicateLayers([layer]);
  };

  const handleMoveLayerOrder = (indexInLayers: number, delta: -1 | 1) => {
    const nextIdx = indexInLayers + delta;
    if (nextIdx < 0 || nextIdx >= draft.layers.length) return;
    updateDraft((prev) => {
      const arr = [...prev.layers];
      const [item] = arr.splice(indexInLayers, 1);
      arr.splice(nextIdx, 0, item);
      return { ...prev, layers: arr };
    });
  };

  const handleMoveLayerToEdge = (indexInLayers: number, edge: 'front' | 'back') => {
    if (indexInLayers < 0 || indexInLayers >= draft.layers.length) return;
    if (edge === 'front' && indexInLayers === draft.layers.length - 1) return;
    if (edge === 'back' && indexInLayers === 0) return;
    updateDraft((prev) => {
      const arr = [...prev.layers];
      const [item] = arr.splice(indexInLayers, 1);
      if (edge === 'front') {
        arr.push(item);
      } else {
        arr.unshift(item);
      }
      return { ...prev, layers: arr };
    });
  };

  const handleMoveSelectedOrder = (delta: -1 | 1) => {
    if (selectedLayerIds.length === 0) return;
    const idSet = new Set(selectedLayerIds);
    updateDraft((prev) => {
      const arr = [...prev.layers];
      if (delta > 0) {
        for (let i = arr.length - 2; i >= 0; i--) {
          if (idSet.has(arr[i].id) && !idSet.has(arr[i + 1].id)) {
            [arr[i], arr[i + 1]] = [arr[i + 1], arr[i]];
          }
        }
      } else {
        for (let i = 1; i < arr.length; i++) {
          if (idSet.has(arr[i].id) && !idSet.has(arr[i - 1].id)) {
            [arr[i], arr[i - 1]] = [arr[i - 1], arr[i]];
          }
        }
      }
      return { ...prev, layers: arr };
    });
  };

  const handleMoveSelectedToEdge = (edge: 'front' | 'back') => {
    if (selectedLayerIds.length === 0) return;
    const idSet = new Set(selectedLayerIds);
    updateDraft((prev) => {
      const selected = prev.layers.filter((l) => idSet.has(l.id));
      const others = prev.layers.filter((l) => !idSet.has(l.id));
      return {
        ...prev,
        layers: edge === 'front' ? [...others, ...selected] : [...selected, ...others],
      };
    });
  };

  const handleAlignSelected = (align: 'left' | 'centerH' | 'right' | 'top' | 'centerV' | 'bottom') => {
    const unlocked = selectedLayers.filter((l) => !l.locked);
    if (unlocked.length === 0) return;

    if (unlocked.length === 1) {
      const target = unlocked[0];
      const sw = draft.sticker.widthMm;
      const sh = draft.sticker.heightMm;
      let nextX = target.xMm;
      let nextY = target.yMm;
      switch (align) {
        case 'left':
          nextX = 0;
          break;
        case 'centerH':
          nextX = roundMm((sw - target.widthMm) / 2);
          break;
        case 'right':
          nextX = roundMm(sw - target.widthMm);
          break;
        case 'top':
          nextY = 0;
          break;
        case 'centerV':
          nextY = roundMm((sh - target.heightMm) / 2);
          break;
        case 'bottom':
          nextY = roundMm(sh - target.heightMm);
          break;
      }
      updateDraft((prev) => ({
        ...prev,
        layers: prev.layers.map((l) =>
          l.id === target.id ? ({ ...l, xMm: nextX, yMm: nextY } as TagLayer) : l
        ),
      }));
      return;
    }

    // Multi-selection: align unlocked selected layers relative to the selection's bounding box
    const minX = Math.min(...unlocked.map((l) => l.xMm));
    const maxX = Math.max(...unlocked.map((l) => l.xMm + l.widthMm));
    const minY = Math.min(...unlocked.map((l) => l.yMm));
    const maxY = Math.max(...unlocked.map((l) => l.yMm + l.heightMm));
    const midX = (minX + maxX) / 2;
    const midY = (minY + maxY) / 2;
    const idSet = new Set(unlocked.map((l) => l.id));

    updateDraft((prev) => ({
      ...prev,
      layers: prev.layers.map((l) => {
        if (!idSet.has(l.id)) return l;
        switch (align) {
          case 'left':
            return { ...l, xMm: roundMm(minX) } as TagLayer;
          case 'centerH':
            return { ...l, xMm: roundMm(midX - l.widthMm / 2) } as TagLayer;
          case 'right':
            return { ...l, xMm: roundMm(maxX - l.widthMm) } as TagLayer;
          case 'top':
            return { ...l, yMm: roundMm(minY) } as TagLayer;
          case 'centerV':
            return { ...l, yMm: roundMm(midY - l.heightMm / 2) } as TagLayer;
          case 'bottom':
            return { ...l, yMm: roundMm(maxY - l.heightMm) } as TagLayer;
        }
      }),
    }));
  };

  const handleCenterGroupOnArtboard = (axis: 'x' | 'y') => {
    const unlocked = selectedLayers.filter((l) => !l.locked);
    if (unlocked.length === 0) return;
    const minX = Math.min(...unlocked.map((l) => l.xMm));
    const maxX = Math.max(...unlocked.map((l) => l.xMm + l.widthMm));
    const minY = Math.min(...unlocked.map((l) => l.yMm));
    const maxY = Math.max(...unlocked.map((l) => l.yMm + l.heightMm));
    const dx = axis === 'x' ? (draft.sticker.widthMm - (maxX - minX)) / 2 - minX : 0;
    const dy = axis === 'y' ? (draft.sticker.heightMm - (maxY - minY)) / 2 - minY : 0;
    const idSet = new Set(unlocked.map((l) => l.id));
    updateDraft((prev) => ({
      ...prev,
      layers: prev.layers.map((l) =>
        idSet.has(l.id)
          ? ({ ...l, xMm: roundMm(l.xMm + dx), yMm: roundMm(l.yMm + dy) } as TagLayer)
          : l
      ),
    }));
  };

  const handleToggleLockSelected = () => {
    if (selectedLayers.length === 0) return;
    const allLocked = selectedLayers.every((l) => l.locked);
    const idSet = new Set(selectedLayers.map((l) => l.id));
    updateDraft((prev) => ({
      ...prev,
      layers: prev.layers.map((l) =>
        idSet.has(l.id) ? ({ ...l, locked: !allLocked } as TagLayer) : l
      ),
    }));
  };

  const handleToggleVisibleSelected = () => {
    if (selectedLayers.length === 0) return;
    const anyHidden = selectedLayers.some((l) => !l.visible);
    const idSet = new Set(selectedLayers.map((l) => l.id));
    updateDraft((prev) => ({
      ...prev,
      layers: prev.layers.map((l) =>
        idSet.has(l.id) ? ({ ...l, visible: anyHidden } as TagLayer) : l
      ),
    }));
  };

  const handleResetBuiltIn = () => {
    const builtIn = getBuiltInTemplates().find((b) => b.id === draft.id) || getBuiltInTemplates()[0];
    const cloned = cloneTemplate(builtIn);
    setDraft(cloned);
    const defaultId = cloned.layers[cloned.layers.length - 1]?.id ?? null;
    setSelectedLayerIds(defaultId ? [defaultId] : []);
    lastClickedLayerIdRef.current = defaultId;
    setSavedFeedback('Direset ke bawaan pabrik');
    setTimeout(() => setSavedFeedback(''), 2500);
  };

  const handleSaveCurrent = () => {
    if (!canEdit) return;
    const saved = onSaveTemplate(draft, false);
    setDraft(cloneTemplate(saved));
    setSavedFeedback('Template disimpan');
    setTimeout(() => setSavedFeedback(''), 2500);
  };

  const handleConfirmSaveAs = () => {
    if (!canEdit) return;
    const cleanName = saveAsName.trim();
    if (!cleanName) return;
    const nowIso = new Date().toISOString();
    const copy: TagLayoutTemplate = {
      ...cloneTemplate(draft),
      id: `custom-${Date.now().toString(36)}`,
      name: cleanName,
      description: `Custom ${draft.sticker.widthMm}×${draft.sticker.heightMm} mm · Kertas ${draft.sheet.preset}`,
      isBuiltIn: false,
      createdAt: nowIso,
      updatedAt: nowIso,
    };
    const saved = onSaveTemplate(copy, true);
    prevActiveTemplateIdRef.current = saved.id;
    setDraft(cloneTemplate(saved));
    setShowSaveAsDialog(false);
    setSaveAsName('');
    setSavedFeedback(`Disimpan sebagai "${saved.name}"`);
    setTimeout(() => setSavedFeedback(''), 2800);
  };

  const handleConfirmRename = () => {
    if (!canEdit) return;
    const cleanName = renameValue.trim();
    if (!cleanName) return;
    const renamed: TagLayoutTemplate = {
      ...cloneTemplate(draft),
      name: cleanName,
    };
    const saved = onSaveTemplate(renamed, false);
    setDraft(cloneTemplate(saved));
    setShowRenameDialog(false);
    setRenameValue('');
    setSavedFeedback(`Nama template diubah menjadi "${saved.name}"`);
    setTimeout(() => setSavedFeedback(''), 2800);
  };

  const handleArtworkUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      if (typeof reader.result === 'string') {
        updateDraft((prev) => ({
          ...prev,
          sticker: { ...prev.sticker, backgroundImageDataUrl: reader.result as string },
        }));
      }
    };
    reader.readAsDataURL(file);
    e.target.value = '';
  };

  const artboardWidthPx = draft.sticker.widthMm * pxPerMm;
  const artboardHeightPx = draft.sticker.heightMm * pxPerMm;
  const rulerTicksX = Array.from({ length: Math.floor(draft.sticker.widthMm / 5) + 1 }, (_, i) => i * 5);
  const rulerTicksY = Array.from({ length: Math.floor(draft.sticker.heightMm / 5) + 1 }, (_, i) => i * 5);

  const renderLayerIcon = (type: TagLayerType) => {
    switch (type) {
      case 'qr':
        return <QrCode size={13} className="text-[#c28e62]" />;
      case 'pin':
        return <KeyRound size={13} className="text-emerald-500" />;
      case 'tagId':
        return <Hash size={13} className="text-sky-500" />;
      case 'brand':
        return <Sparkles size={13} className="text-indigo-500" />;
      case 'emblem':
        return <Award size={13} className="text-amber-500" />;
      case 'shape':
        return <BoxSelect size={13} className="text-slate-500" />;
      case 'text':
      default:
        return <Type size={13} className="text-violet-500" />;
    }
  };

  return (
    <div className="fixed inset-0 z-[10020] bg-black/75 backdrop-blur-sm flex items-center justify-center p-1.5 sm:p-3 animate-in fade-in duration-150">
      <div className="bg-white dark:bg-zinc-950 border border-slate-200 dark:border-zinc-800 rounded-2xl w-full max-w-[1440px] h-[95dvh] flex flex-col overflow-hidden">
        {/* TOP STUDIO TOOLBAR */}
        <div className="px-3 sm:px-4 py-2.5 border-b border-slate-200 dark:border-zinc-800 bg-slate-50 dark:bg-zinc-900 flex flex-wrap items-center justify-between gap-2.5 shrink-0">
          <div className="flex items-center gap-2.5 min-w-0">
            <div className="w-8 h-8 rounded-lg bg-[#c28e62]/15 text-[#c28e62] flex items-center justify-center shrink-0">
              <Sliders size={16} />
            </div>
            <div className="min-w-0">
              <div className="flex items-center gap-2">
                <h3 className="text-xs sm:text-sm font-bold text-slate-800 dark:text-zinc-100 truncate">
                  Editor Layout Tag
                </h3>
                <span className="hidden md:inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-200/70 dark:bg-zinc-800 text-slate-600 dark:text-zinc-300">
                  {draft.sticker.widthMm} × {draft.sticker.heightMm} mm · {draft.sheet.preset} ({gridMetrics.cols}×{gridMetrics.rows} = {gridMetrics.perPage}/hlm)
                </span>
              </div>
              {savedFeedback && (
                <p className="text-[10px] font-semibold text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
                  <Check size={11} /> {savedFeedback}
                </p>
              )}
            </div>
          </div>

          {/* Preset Picker & Canvas Tools */}
          <div className="flex flex-wrap items-center gap-1.5 sm:gap-2">
            {/* Undo & Redo (Icon Only + Illustrator Shortcut Tooltip) */}
            <div className="flex items-center border border-slate-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-800 overflow-hidden">
              <button
                type="button"
                onClick={handleUndo}
                disabled={historyPast.length === 0}
                title="Undo (Ctrl+Z)"
                aria-label="Undo (Ctrl+Z)"
                className="h-8 w-8 text-slate-600 dark:text-zinc-300 hover:bg-slate-100 dark:hover:bg-zinc-700 disabled:opacity-30 disabled:cursor-not-allowed cursor-pointer inline-flex items-center justify-center"
              >
                <Undo2 size={14} />
              </button>
              <div className="w-px h-4 bg-slate-200 dark:bg-zinc-700" />
              <button
                type="button"
                onClick={handleRedo}
                disabled={historyFuture.length === 0}
                title="Redo (Ctrl+Shift+Z)"
                aria-label="Redo (Ctrl+Shift+Z)"
                className="h-8 w-8 text-slate-600 dark:text-zinc-300 hover:bg-slate-100 dark:hover:bg-zinc-700 disabled:opacity-30 disabled:cursor-not-allowed cursor-pointer inline-flex items-center justify-center"
              >
                <Redo2 size={14} />
              </button>
            </div>

            <div className="flex items-center gap-1">
              <CustomSelect
                value={draft.id}
                onChange={(val) => onSelectTemplate(val)}
                placeholder="Pilih Preset Template"
                searchable={templates.length > 5}
                minDropdownWidth={380}
                className="w-[225px] sm:w-[300px]"
                buttonClassName="w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-semibold text-slate-700 dark:text-zinc-200 hover:border-[#c28e62] cursor-pointer flex items-center justify-between gap-1.5"
                options={templates.map((t) => ({
                  value: t.id,
                  label: t.name,
                  badge: `${t.sticker.widthMm}×${t.sticker.heightMm}mm`,
                  isNew: !t.isBuiltIn && isRecentlyCreated(t.createdAt, t.id),
                }))}
              />
              <button
                type="button"
                onClick={() => {
                  setShowSaveAsDialog(false);
                  setRenameValue(draft.name);
                  setShowRenameDialog(true);
                }}
                title="Ubah Nama Template (Rename)"
                aria-label="Ubah Nama Template"
                className="h-8 w-8 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 hover:border-[#c28e62] text-slate-500 hover:text-[#c28e62] inline-flex items-center justify-center cursor-pointer transition-colors shrink-0"
              >
                <Edit3 size={13} />
              </button>
            </div>

            <div className="flex items-center border border-slate-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-800 overflow-hidden">
              <button
                type="button"
                onClick={() => setZoom((z) => Math.max(0.4, roundMm(z - 0.15)))}
                title="Zoom Out (Ctrl+-)"
                className="h-8 px-2 text-slate-600 dark:text-zinc-300 hover:bg-slate-100 dark:hover:bg-zinc-700 cursor-pointer flex items-center justify-center"
              >
                <ZoomOut size={14} />
              </button>
              <button
                type="button"
                onClick={() => setZoom(1)}
                title="Actual Size 100% (Ctrl+1)"
                className="h-8 px-2 text-[11px] font-mono font-semibold text-slate-700 dark:text-zinc-200 hover:bg-slate-100 dark:hover:bg-zinc-700 min-w-[44px] text-center cursor-pointer"
              >
                {Math.round(zoom * 100)}%
              </button>
              <button
                type="button"
                onClick={() => setZoom((z) => Math.min(3.0, roundMm(z + 0.15)))}
                title="Zoom In (Ctrl++)"
                className="h-8 px-2 text-slate-600 dark:text-zinc-300 hover:bg-slate-100 dark:hover:bg-zinc-700 cursor-pointer flex items-center justify-center"
              >
                <ZoomIn size={14} />
              </button>
              <div className="w-px h-4 bg-slate-200 dark:bg-zinc-700" />
              <button
                type="button"
                onClick={() => handleZoomFit()}
                title="Fit Artboard in Window (Ctrl+0)"
                className="h-8 px-2 text-[11px] font-semibold text-slate-600 dark:text-zinc-300 hover:bg-slate-100 dark:hover:bg-zinc-700 hover:text-[#c28e62] cursor-pointer"
              >
                Fit
              </button>
            </div>

            <button
              type="button"
              onClick={() => setShowGrid((v) => !v)}
              title="Toggle Grid 1mm (Ctrl+')"
              className={`h-8 px-2.5 rounded-lg border text-xs font-semibold inline-flex items-center gap-1 cursor-pointer transition-colors ${
                showGrid
                  ? 'border-[#c28e62] bg-[#c28e62]/10 text-[#c28e62]'
                  : 'border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-500 dark:text-zinc-400'
              }`}
            >
              <Grid size={13} />
              <span className="hidden sm:inline">Grid</span>
            </button>

            <button
              type="button"
              onClick={() => setSnapEnabled((v) => !v)}
              title="Toggle Smart Snap Guides"
              className={`h-8 px-2.5 rounded-lg border text-xs font-semibold inline-flex items-center gap-1 cursor-pointer transition-colors ${
                snapEnabled
                  ? 'border-fuchsia-500/60 bg-fuchsia-500/10 text-fuchsia-600 dark:text-fuchsia-400'
                  : 'border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-500 dark:text-zinc-400'
              }`}
            >
              <Magnet size={13} />
              <span className="hidden sm:inline">Snap</span>
            </button>

            <button
              type="button"
              onClick={handleSaveCurrent}
              className="h-8 px-3 rounded-lg bg-slate-800 dark:bg-zinc-700 hover:bg-slate-900 dark:hover:bg-zinc-600 text-white text-xs font-semibold inline-flex items-center gap-1.5 cursor-pointer"
            >
              <Save size={13} />
              <span>Simpan</span>
            </button>

            <button
              type="button"
              onClick={() => {
                setSaveAsName(`${draft.name.replace(/ \(Custom\)$/i, '')} (Custom)`);
                setShowSaveAsDialog(true);
              }}
              className="h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 hover:bg-slate-100 dark:hover:bg-zinc-700 text-slate-700 dark:text-zinc-200 text-xs font-semibold inline-flex items-center gap-1 cursor-pointer"
            >
              <Copy size={13} />
              <span className="hidden lg:inline">Save As</span>
            </button>

            {draft.isBuiltIn ? (
              <button
                type="button"
                onClick={handleResetBuiltIn}
                title="Reset Standar"
                className="h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 hover:bg-slate-100 dark:hover:bg-zinc-700 text-slate-500 dark:text-zinc-400 text-xs font-semibold inline-flex items-center gap-1 cursor-pointer"
              >
                <RotateCcw size={13} />
              </button>
            ) : (
              <button
                type="button"
                onClick={() => onDeleteTemplate(draft.id)}
                title="Hapus Template"
                className="h-8 px-2.5 rounded-lg border border-rose-200 dark:border-rose-900/50 bg-rose-50 dark:bg-rose-950/30 hover:bg-rose-100 text-rose-600 dark:text-rose-400 text-xs font-semibold inline-flex items-center gap-1 cursor-pointer"
              >
                <Trash2 size={13} />
              </button>
            )}

            {onDownloadPdfWithTemplate && (
              <button
                type="button"
                onClick={() => {
                  const saved = onSaveTemplate(draft, false);
                  onDownloadPdfWithTemplate(saved);
                }}
                className="h-8 px-3.5 rounded-lg bg-[#c28e62] hover:bg-[#b07d53] text-white text-xs font-semibold inline-flex items-center gap-1.5 cursor-pointer"
              >
                <Download size={13} />
                <span>Export PDF</span>
              </button>
            )}

            <button
              type="button"
              onClick={onClose}
              aria-label="Tutup Editor"
              className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-zinc-200 hover:bg-slate-200/70 dark:hover:bg-zinc-800 cursor-pointer"
            >
              <X size={18} />
            </button>
          </div>
        </div>

        {/* SAVE AS INLINE POPOVER BAR */}
        {showSaveAsDialog && (
          <div className="px-4 py-2.5 bg-amber-50/90 dark:bg-zinc-900 border-b border-amber-200 dark:border-zinc-800 flex flex-wrap items-center gap-2 shrink-0">
            <span className="text-xs font-semibold text-slate-700 dark:text-zinc-200">
              Nama Baru:
            </span>
            <input
              type="text"
              value={saveAsName}
              onChange={(e) => setSaveAsName(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') handleConfirmSaveAs();
                if (e.key === 'Escape') setShowSaveAsDialog(false);
              }}
              placeholder="Contoh: Parfum 30x45 mm"
              className="h-8 px-3 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs text-slate-800 dark:text-zinc-100 w-64 outline-none focus:border-[#c28e62]"
              autoFocus
            />
            <button
              type="button"
              onClick={handleConfirmSaveAs}
              className="h-8 px-3 rounded-lg bg-[#c28e62] text-white text-xs font-semibold cursor-pointer"
            >
              Simpan
            </button>
            <button
              type="button"
              onClick={() => setShowSaveAsDialog(false)}
              className="h-8 px-2.5 rounded-lg text-xs font-semibold text-slate-500 hover:text-slate-800 dark:hover:text-zinc-200 cursor-pointer"
            >
              Batal
            </button>
          </div>
        )}

        {/* RENAME TEMPLATE INLINE POPOVER BAR */}
        {showRenameDialog && (
          <div className="px-4 py-2.5 bg-slate-50 dark:bg-zinc-900 border-b border-slate-200 dark:border-zinc-800 flex flex-wrap items-center gap-2 shrink-0">
            <span className="text-xs font-semibold text-slate-700 dark:text-zinc-200">
              Nama Template:
            </span>
            <input
              type="text"
              value={renameValue}
              onChange={(e) => setRenameValue(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') handleConfirmRename();
                if (e.key === 'Escape') setShowRenameDialog(false);
              }}
              placeholder="Masukkan nama template..."
              className="h-8 px-3 rounded-lg border border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs text-slate-800 dark:text-zinc-100 w-64 outline-none focus:border-[#c28e62]"
              autoFocus
            />
            <button
              type="button"
              onClick={handleConfirmRename}
              className="h-8 px-3 rounded-lg bg-[#c28e62] text-white text-xs font-semibold cursor-pointer"
            >
              Simpan
            </button>
            <button
              type="button"
              onClick={() => setShowRenameDialog(false)}
              className="h-8 px-2.5 rounded-lg text-xs font-semibold text-slate-500 hover:text-slate-800 dark:hover:text-zinc-200 cursor-pointer"
            >
              Batal
            </button>
          </div>
        )}

        {/* STUDIO 3-COLUMN WORKSPACE */}
        <div className="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">
          {/* LEFT PANEL: ADD ELEMENTS & LAYER STACK */}
          <aside className="w-full lg:w-64 border-b lg:border-b-0 lg:border-r border-slate-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 flex flex-col max-h-[28vh] lg:max-h-none shrink-0 overflow-hidden">
            <div className="p-3 border-b border-slate-100 dark:border-zinc-800">
              <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500 mb-2">
                Tambah Elemen
              </div>
              <div className="grid grid-cols-4 lg:grid-cols-2 gap-1.5">
                {(
                  [
                    { type: 'qr', label: '+ QR Code', icon: QrCode },
                    { type: 'pin', label: '+ PIN', icon: KeyRound },
                    { type: 'tagId', label: '+ ID Tag', icon: Hash },
                    { type: 'brand', label: '+ Brand', icon: Sparkles },
                    { type: 'text', label: '+ Teks', icon: Type },
                    { type: 'emblem', label: '+ Emblem', icon: Award },
                    { type: 'shape', label: '+ Kotak', icon: BoxSelect },
                  ] as const
                ).map((item) => {
                  const Icon = item.icon;
                  return (
                    <button
                      key={item.type}
                      type="button"
                      onClick={() => handleAddLayer(item.type)}
                      className="h-7 px-2 rounded-lg border border-slate-200 dark:border-zinc-700/80 bg-slate-50 dark:bg-zinc-800/70 hover:border-[#c28e62] hover:text-[#c28e62] text-[11px] font-semibold text-slate-700 dark:text-zinc-200 flex items-center gap-1.5 cursor-pointer transition-colors truncate"
                    >
                      <Icon size={12} className="shrink-0" />
                      <span className="truncate">{item.label}</span>
                    </button>
                  );
                })}
                <button
                  type="button"
                  onClick={() => artworkInputRef.current?.click()}
                  className="h-7 px-2 rounded-lg border border-dashed border-slate-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 hover:border-[#c28e62] hover:text-[#c28e62] text-[11px] font-semibold text-slate-600 dark:text-zinc-300 flex items-center gap-1.5 cursor-pointer transition-colors truncate"
                >
                  <ImageIcon size={12} className="shrink-0" />
                  <span className="truncate">+ Artwork</span>
                </button>
                <input
                  ref={artworkInputRef}
                  type="file"
                  accept="image/png,image/jpeg,image/webp,image/svg+xml"
                  onChange={handleArtworkUpload}
                  className="hidden"
                />
              </div>
            </div>

            {/* LAYERS LIST (top-most layer shown first, like Illustrator) */}
            <div className="flex-1 overflow-y-auto p-2.5 space-y-1">
              <div className="flex items-center justify-between px-1 pb-1 gap-1.5">
                <div className="flex items-center gap-1.5 min-w-0">
                  <CustomCheckbox
                    checked={draft.layers.length > 0 && selectedLayerIds.length === draft.layers.length}
                    indeterminate={selectedLayerIds.length > 0 && selectedLayerIds.length < draft.layers.length}
                    onChange={(checked) => {
                      if (checked) {
                        setSelectedLayerIds(draft.layers.map((l) => l.id));
                        setInspectorTab('layer');
                      } else {
                        setSelectedLayerIds([]);
                      }
                    }}
                    ariaLabel="Pilih semua layer"
                    className="scale-90 origin-left"
                  />
                  <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500 flex items-center gap-1 truncate">
                    <Layers size={11} />
                    {selectedLayerIds.length > 1
                      ? `${selectedLayerIds.length}/${draft.layers.length} Dipilih`
                      : `Layers (${draft.layers.length})`}
                  </span>
                </div>
                <span className="text-[10px] text-slate-400 dark:text-zinc-500 shrink-0">Atas = Depan</span>
              </div>

              {[...draft.layers].reverse().map((layer, revIdx, reversedArr) => {
                const realIdx = draft.layers.length - 1 - revIdx;
                const isSelected = selectedLayerIds.includes(layer.id);
                return (
                  <div
                    key={layer.id}
                    onClick={(e) => {
                      if (e.shiftKey && lastClickedLayerIdRef.current) {
                        const anchorIdx = reversedArr.findIndex((l) => l.id === lastClickedLayerIdRef.current);
                        if (anchorIdx !== -1) {
                          const start = Math.min(anchorIdx, revIdx);
                          const end = Math.max(anchorIdx, revIdx);
                          const rangeIds = reversedArr.slice(start, end + 1).map((l) => l.id);
                          setSelectedLayerIds((prev) =>
                            e.metaKey || e.ctrlKey
                              ? Array.from(new Set([...prev, ...rangeIds]))
                              : rangeIds
                          );
                          setInspectorTab('layer');
                          return;
                        }
                      }
                      if (e.metaKey || e.ctrlKey) {
                        setSelectedLayerIds((prev) =>
                          prev.includes(layer.id)
                            ? prev.filter((id) => id !== layer.id)
                            : [...prev, layer.id]
                        );
                        lastClickedLayerIdRef.current = layer.id;
                        setInspectorTab('layer');
                        return;
                      }
                      setSelectedLayerIds([layer.id]);
                      lastClickedLayerIdRef.current = layer.id;
                      setInspectorTab('layer');
                    }}
                    className={`group flex items-center gap-1.5 px-2 py-1.5 rounded-lg border text-xs cursor-pointer transition-colors ${
                      isSelected
                        ? 'border-[#c28e62] bg-[#fcf7f3] dark:bg-zinc-800 text-slate-900 dark:text-zinc-100 font-semibold'
                        : 'border-transparent hover:bg-slate-50 dark:hover:bg-zinc-800/50 text-slate-600 dark:text-zinc-300'
                    }`}
                  >
                    <div
                      onClick={(e) => e.stopPropagation()}
                      className={`shrink-0 transition-opacity ${
                        isSelected || selectedLayerIds.length > 1
                          ? 'opacity-100'
                          : 'opacity-0 group-hover:opacity-100 focus-within:opacity-100'
                      }`}
                    >
                      <CustomCheckbox
                        checked={isSelected}
                        onChange={(checked) => {
                          setSelectedLayerIds((prev) =>
                            checked
                              ? Array.from(new Set([...prev, layer.id]))
                              : prev.filter((id) => id !== layer.id)
                          );
                          lastClickedLayerIdRef.current = layer.id;
                          setInspectorTab('layer');
                        }}
                        ariaLabel={`Pilih layer ${layer.label}`}
                        className="scale-75 origin-left"
                      />
                    </div>
                    {renderLayerIcon(layer.type)}
                    <span className="flex-1 truncate text-[11px]">{layer.label}</span>

                    <div className="flex items-center gap-0.5 shrink-0">
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          handleMoveLayerOrder(realIdx, 1);
                        }}
                        disabled={realIdx === draft.layers.length - 1}
                        title="Naikkan Layer"
                        className="p-0.5 rounded text-slate-400 hover:text-slate-700 dark:hover:text-zinc-100 disabled:opacity-25 cursor-pointer"
                      >
                        <ArrowUp size={11} />
                      </button>
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          handleMoveLayerOrder(realIdx, -1);
                        }}
                        disabled={realIdx === 0}
                        title="Turunkan Layer"
                        className="p-0.5 rounded text-slate-400 hover:text-slate-700 dark:hover:text-zinc-100 disabled:opacity-25 cursor-pointer"
                      >
                        <ArrowDown size={11} />
                      </button>
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          updateDraft((prev) => ({
                            ...prev,
                            layers: prev.layers.map((l) =>
                              l.id === layer.id ? ({ ...l, locked: !l.locked } as TagLayer) : l
                            ),
                          }));
                        }}
                        title={layer.locked ? 'Buka Kunci' : 'Kunci Layer'}
                        className={`p-0.5 rounded cursor-pointer ${
                          layer.locked ? 'text-amber-500' : 'text-slate-400 hover:text-slate-700 dark:hover:text-zinc-100'
                        }`}
                      >
                        {layer.locked ? <Lock size={11} /> : <Unlock size={11} />}
                      </button>
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          updateDraft((prev) => ({
                            ...prev,
                            layers: prev.layers.map((l) =>
                              l.id === layer.id ? ({ ...l, visible: !l.visible } as TagLayer) : l
                            ),
                          }));
                        }}
                        title={layer.visible ? 'Sembunyikan' : 'Tampilkan'}
                        className={`p-0.5 rounded cursor-pointer ${
                          layer.visible ? 'text-slate-400 hover:text-slate-700 dark:hover:text-zinc-100' : 'text-slate-300 dark:text-zinc-600'
                        }`}
                      >
                        {layer.visible ? <Eye size={11} /> : <EyeOff size={11} />}
                      </button>
                    </div>
                  </div>
                );
              })}
            </div>
          </aside>

          {/* CENTER CANVAS VIEWPORT WITH RULERS (mm) */}
          <main
            ref={canvasViewportRef}
            onPointerDown={(e) => {
              if (e.button !== 0 || e.target !== e.currentTarget) return;
              dragRef.current = {
                kind: 'marquee',
                startClientX: e.clientX,
                startClientY: e.clientY,
                currentClientX: e.clientX,
                currentClientY: e.clientY,
                additive: e.shiftKey || e.metaKey || e.ctrlKey,
                baseSelection: selectedLayerIds,
              };
            }}
            className="flex-1 min-w-0 min-h-0 bg-slate-100 dark:bg-zinc-950 relative overflow-auto flex items-center justify-center p-8 sm:p-12 select-none"
            style={{
              backgroundImage:
                'radial-gradient(circle, rgba(148,163,184,0.28) 1px, transparent 1px)',
              backgroundSize: '18px 18px',
            }}
          >
            {marqueeRect && (
              <div
                aria-hidden="true"
                className="fixed z-[10030] border border-[#0284c7] bg-[#0284c7]/10 pointer-events-none rounded-[2px]"
                style={{
                  left: `${marqueeRect.left}px`,
                  top: `${marqueeRect.top}px`,
                  width: `${marqueeRect.width}px`,
                  height: `${marqueeRect.height}px`,
                }}
              />
            )}
            <div className="relative inline-block" ref={artboardRef}>
              {/* Top Ruler (mm) */}
              <div
                aria-hidden="true"
                className="absolute -top-6 left-0 h-5 bg-white/90 dark:bg-zinc-900/90 border border-slate-200 dark:border-zinc-800 rounded-t text-[9px] font-mono text-slate-400 dark:text-zinc-500 overflow-hidden pointer-events-none"
                style={{ width: `${artboardWidthPx}px` }}
              >
                {rulerTicksX.map((mm) => (
                  <div
                    key={mm}
                    className="absolute bottom-0 border-l border-slate-300 dark:border-zinc-700 h-2.5 pl-0.5 leading-none"
                    style={{ left: `${mm * pxPerMm}px` }}
                  >
                    {mm}
                  </div>
                ))}
              </div>

              {/* Left Ruler (mm) */}
              <div
                aria-hidden="true"
                className="absolute top-0 -left-6 w-5 bg-white/90 dark:bg-zinc-900/90 border border-slate-200 dark:border-zinc-800 rounded-l text-[9px] font-mono text-slate-400 dark:text-zinc-500 overflow-hidden pointer-events-none"
                style={{ height: `${artboardHeightPx}px` }}
              >
                {rulerTicksY.map((mm) => (
                  <div
                    key={mm}
                    className="absolute left-0 right-0 border-t border-slate-300 dark:border-zinc-700 pt-0.5 text-center leading-none"
                    style={{ top: `${mm * pxPerMm}px` }}
                  >
                    {mm}
                  </div>
                ))}
              </div>

              {/* Artboard Dimensions Pill */}
              <div className="absolute -bottom-7 left-1/2 -translate-x-1/2 whitespace-nowrap px-2.5 py-0.5 rounded-full bg-white/90 dark:bg-zinc-900/90 border border-slate-200 dark:border-zinc-800 text-[10px] font-mono font-semibold text-slate-500 dark:text-zinc-400 pointer-events-none">
                {draft.sticker.widthMm} × {draft.sticker.heightMm} mm · R{draft.sticker.cornerRadiusMm}
              </div>

              <TagStickerPreview
                template={draft}
                pxPerMm={pxPerMm}
                sampleBatch={sampleBatch ?? undefined}
                sampleTag={sampleTag ?? undefined}
                interactive
                selectedLayerIds={selectedLayerIds}
                showGrid={showGrid}
                snapGuides={snapGuides}
                onArtboardPointerDown={(e) => {
                  if (e.button !== 0) return;
                  dragRef.current = {
                    kind: 'marquee',
                    startClientX: e.clientX,
                    startClientY: e.clientY,
                    currentClientX: e.clientX,
                    currentClientY: e.clientY,
                    additive: e.shiftKey || e.metaKey || e.ctrlKey,
                    baseSelection: selectedLayerIds,
                  };
                }}
                onLayerPointerDown={(e, layer) => {
                  const isModifier = e.shiftKey || e.metaKey || e.ctrlKey;
                  let nextIds: string[];
                  if (isModifier) {
                    if (selectedLayerIds.includes(layer.id)) {
                      nextIds = selectedLayerIds.filter((id) => id !== layer.id);
                      setSelectedLayerIds(nextIds);
                      return;
                    }
                    nextIds = [...selectedLayerIds, layer.id];
                    setSelectedLayerIds(nextIds);
                  } else if (selectedLayerIds.includes(layer.id)) {
                    nextIds = selectedLayerIds;
                  } else {
                    nextIds = [layer.id];
                    setSelectedLayerIds(nextIds);
                  }
                  lastClickedLayerIdRef.current = layer.id;
                  setInspectorTab('layer');

                  const movingLayers = draftRef.current.layers.filter(
                    (l) => nextIds.includes(l.id) && !l.locked
                  );
                  if (movingLayers.length === 0) return;

                  dragSnapshotRef.current = cloneTemplate(draftRef.current);
                  dragRef.current = {
                    kind: 'move',
                    primaryLayerId: layer.id,
                    startClientX: e.clientX,
                    startClientY: e.clientY,
                    items: movingLayers.map((ml) => ({
                      id: ml.id,
                      origXMm: ml.xMm,
                      origYMm: ml.yMm,
                    })),
                    origBounds: {
                      minX: Math.min(...movingLayers.map((ml) => ml.xMm)),
                      minY: Math.min(...movingLayers.map((ml) => ml.yMm)),
                      maxX: Math.max(...movingLayers.map((ml) => ml.xMm + ml.widthMm)),
                      maxY: Math.max(...movingLayers.map((ml) => ml.yMm + ml.heightMm)),
                    },
                  };
                }}
                onResizePointerDown={(e, layer, handle) => {
                  setSelectedLayerIds([layer.id]);
                  lastClickedLayerIdRef.current = layer.id;
                  dragSnapshotRef.current = cloneTemplate(draftRef.current);
                  dragRef.current = {
                    kind: 'resize',
                    layerId: layer.id,
                    handle,
                    startClientX: e.clientX,
                    startClientY: e.clientY,
                    origXMm: layer.xMm,
                    origYMm: layer.yMm,
                    origWMm: layer.widthMm,
                    origHMm: layer.heightMm,
                  };
                }}
                onRotatePointerDown={(_e, layer) => {
                  const box = artboardRef.current?.getBoundingClientRect();
                  if (!box) return;
                  dragSnapshotRef.current = cloneTemplate(draftRef.current);
                  const centerX = box.left + (layer.xMm + layer.widthMm / 2) * pxPerMm;
                  const centerY = box.top + (layer.yMm + layer.heightMm / 2) * pxPerMm;
                  dragRef.current = {
                    kind: 'rotate',
                    layerId: layer.id,
                    centerX,
                    centerY,
                  };
                }}
              />
            </div>
          </main>

          {/* RIGHT PANEL: PROPERTIES INSPECTOR */}
          <aside className="w-full lg:w-80 border-t lg:border-t-0 lg:border-l border-slate-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 flex flex-col max-h-[34vh] lg:max-h-none shrink-0 overflow-hidden">
            {/* Inspector Tabs */}
            <div className="grid grid-cols-2 border-b border-slate-200 dark:border-zinc-800 p-1.5 gap-1 bg-slate-50 dark:bg-zinc-900/80 shrink-0">
              <button
                type="button"
                onClick={() => setInspectorTab('layer')}
                className={`h-8 rounded-lg text-xs font-semibold cursor-pointer transition-colors ${
                  inspectorTab === 'layer'
                    ? 'bg-white dark:bg-zinc-800 text-[#c28e62] border border-slate-200 dark:border-zinc-700'
                    : 'text-slate-500 dark:text-zinc-400 hover:text-slate-800 dark:hover:text-zinc-200'
                }`}
              >
                Elemen
              </button>
              <button
                type="button"
                onClick={() => setInspectorTab('artboard')}
                className={`h-8 rounded-lg text-xs font-semibold cursor-pointer transition-colors ${
                  inspectorTab === 'artboard'
                    ? 'bg-white dark:bg-zinc-800 text-[#c28e62] border border-slate-200 dark:border-zinc-700'
                    : 'text-slate-500 dark:text-zinc-400 hover:text-slate-800 dark:hover:text-zinc-200'
                }`}
              >
                Kanvas &amp; Kertas
              </button>
            </div>

            <div className="flex-1 overflow-y-auto p-3.5 space-y-4 text-xs">
              {inspectorTab === 'layer' ? (
                selectedLayer ? (
                  <>
                    {/* Layer Title + Actions */}
                    <div className="flex items-center justify-between gap-2">
                      <input
                        type="text"
                        value={selectedLayer.label}
                        onChange={(e) => updateSelectedLayer({ label: e.target.value })}
                        className="flex-1 h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 text-xs font-bold text-slate-800 dark:text-zinc-100 outline-none focus:border-[#c28e62]"
                      />
                      <button
                        type="button"
                        onClick={() => handleDuplicateLayer(selectedLayer)}
                        title="Duplikat Elemen (Ctrl+D)"
                        className="h-8 w-8 rounded-lg border border-slate-200 dark:border-zinc-700 flex items-center justify-center text-slate-500 hover:text-[#c28e62] cursor-pointer"
                      >
                        <Copy size={13} />
                      </button>
                      <button
                        type="button"
                        onClick={() => handleDeleteLayer(selectedLayer.id)}
                        disabled={draft.layers.length <= 1}
                        title="Hapus Elemen (Del)"
                        className="h-8 w-8 rounded-lg border border-rose-200 dark:border-rose-900/50 flex items-center justify-center text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/30 disabled:opacity-30 cursor-pointer"
                      >
                        <Trash2 size={13} />
                      </button>
                    </div>

                    {/* Quick Align to Artboard */}
                    <div>
                      <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500 mb-1.5">
                        Perataan
                      </div>
                      <div className="grid grid-cols-6 gap-1">
                        {[
                          { id: 'left', icon: AlignStartVertical, title: 'Rata Kiri' },
                          { id: 'centerH', icon: AlignCenterVertical, title: 'Tengah Horizontal' },
                          { id: 'right', icon: AlignEndVertical, title: 'Rata Kanan' },
                          { id: 'top', icon: AlignStartHorizontal, title: 'Rata Atas' },
                          { id: 'centerV', icon: AlignCenterHorizontal, title: 'Tengah Vertikal' },
                          { id: 'bottom', icon: AlignEndHorizontal, title: 'Rata Bawah' },
                        ].map((btn) => {
                          const Icon = btn.icon;
                          return (
                            <button
                              key={btn.id}
                              type="button"
                              onClick={() => handleAlignSelected(btn.id as any)}
                              title={btn.title}
                              className="h-7 rounded-lg border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 hover:border-[#c28e62] hover:text-[#c28e62] flex items-center justify-center text-slate-600 dark:text-zinc-300 cursor-pointer"
                            >
                              <Icon size={13} />
                            </button>
                          );
                        })}
                      </div>
                    </div>

                    {/* Layer Ordering (Z-Index) */}
                    <div>
                      <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500 mb-1.5">
                        Urutan Layer
                      </div>
                      <div className="grid grid-cols-4 gap-1">
                        <button
                          type="button"
                          onClick={() => handleMoveLayerToEdge(selectedLayerIndex, 'back')}
                          disabled={selectedLayerIndex <= 0}
                          title="Send to Back (Ctrl+Shift+[)"
                          className="h-7 rounded-lg border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 hover:border-[#c28e62] hover:text-[#c28e62] disabled:opacity-35 disabled:cursor-not-allowed flex items-center justify-center gap-1 text-[10px] font-semibold text-slate-600 dark:text-zinc-300 cursor-pointer"
                        >
                          <SendToBack size={12} />
                          <span>Back</span>
                        </button>
                        <button
                          type="button"
                          onClick={() => handleMoveLayerOrder(selectedLayerIndex, -1)}
                          disabled={selectedLayerIndex <= 0}
                          title="Send Backward (Ctrl+[)"
                          className="h-7 rounded-lg border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 hover:border-[#c28e62] hover:text-[#c28e62] disabled:opacity-35 disabled:cursor-not-allowed flex items-center justify-center gap-1 text-[10px] font-semibold text-slate-600 dark:text-zinc-300 cursor-pointer"
                        >
                          <ArrowDown size={12} />
                          <span>Backward</span>
                        </button>
                        <button
                          type="button"
                          onClick={() => handleMoveLayerOrder(selectedLayerIndex, 1)}
                          disabled={selectedLayerIndex === -1 || selectedLayerIndex >= draft.layers.length - 1}
                          title="Bring Forward (Ctrl+])"
                          className="h-7 rounded-lg border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 hover:border-[#c28e62] hover:text-[#c28e62] disabled:opacity-35 disabled:cursor-not-allowed flex items-center justify-center gap-1 text-[10px] font-semibold text-slate-600 dark:text-zinc-300 cursor-pointer"
                        >
                          <ArrowUp size={12} />
                          <span>Forward</span>
                        </button>
                        <button
                          type="button"
                          onClick={() => handleMoveLayerToEdge(selectedLayerIndex, 'front')}
                          disabled={selectedLayerIndex === -1 || selectedLayerIndex >= draft.layers.length - 1}
                          title="Bring to Front (Ctrl+Shift+])"
                          className="h-7 rounded-lg border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 hover:border-[#c28e62] hover:text-[#c28e62] disabled:opacity-35 disabled:cursor-not-allowed flex items-center justify-center gap-1 text-[10px] font-semibold text-slate-600 dark:text-zinc-300 cursor-pointer"
                        >
                          <BringToFront size={12} />
                          <span>Front</span>
                        </button>
                      </div>
                    </div>

                    {/* Transform Coordinates (mm & deg) */}
                    <div>
                      <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500 mb-1.5">
                        Transform (mm & °)
                      </div>
                      <div className="grid grid-cols-2 gap-2">
                        <label className="block">
                          <span className="text-[10px] text-slate-500 dark:text-zinc-400">X (mm)</span>
                          <MmNumberInput
                            step={0.5}
                            value={selectedLayer.xMm}
                            fallback={0}
                            onCommit={(val) => updateSelectedLayer({ xMm: val })}
                            className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono text-slate-800 dark:text-zinc-100 outline-none focus:border-[#c28e62]"
                          />
                        </label>
                        <label className="block">
                          <span className="text-[10px] text-slate-500 dark:text-zinc-400">Y (mm)</span>
                          <MmNumberInput
                            step={0.5}
                            value={selectedLayer.yMm}
                            fallback={0}
                            onCommit={(val) => updateSelectedLayer({ yMm: val })}
                            className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono text-slate-800 dark:text-zinc-100 outline-none focus:border-[#c28e62]"
                          />
                        </label>
                        <label className="block">
                          <span className="text-[10px] text-slate-500 dark:text-zinc-400">Lebar W (mm)</span>
                          <MmNumberInput
                            step={0.5}
                            min={1}
                            fallback={10}
                            value={selectedLayer.widthMm}
                            onCommit={(val) => updateSelectedLayer({ widthMm: val })}
                            className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono text-slate-800 dark:text-zinc-100 outline-none focus:border-[#c28e62]"
                          />
                        </label>
                        <label className="block">
                          <span className="text-[10px] text-slate-500 dark:text-zinc-400">Tinggi H (mm)</span>
                          <MmNumberInput
                            step={0.5}
                            min={1}
                            fallback={10}
                            value={selectedLayer.heightMm}
                            onCommit={(val) => updateSelectedLayer({ heightMm: val })}
                            className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono text-slate-800 dark:text-zinc-100 outline-none focus:border-[#c28e62]"
                          />
                        </label>
                      </div>
                      <div className="mt-2 flex items-center gap-2">
                        <label className="flex-1 block">
                          <span className="text-[10px] text-slate-500 dark:text-zinc-400">Rotasi (°)</span>
                          <MmNumberInput
                            step={15}
                            wrap360
                            fallback={0}
                            value={selectedLayer.rotation}
                            onCommit={(val) => updateSelectedLayer({ rotation: val })}
                            className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono text-slate-800 dark:text-zinc-100 outline-none focus:border-[#c28e62]"
                          />
                        </label>
                        <button
                          type="button"
                          onClick={() => updateSelectedLayer({ rotation: 0 })}
                          className="mt-4 h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 text-[11px] font-semibold text-slate-600 dark:text-zinc-300 hover:border-[#c28e62] cursor-pointer"
                        >
                          0°
                        </button>
                        <button
                          type="button"
                          onClick={() =>
                            updateSelectedLayer({ rotation: (selectedLayer.rotation + 90) % 360 })
                          }
                          className="mt-4 h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 text-[11px] font-semibold text-slate-600 dark:text-zinc-300 hover:border-[#c28e62] cursor-pointer"
                        >
                          +90°
                        </button>
                      </div>
                    </div>

                    {/* CONTEXTUAL CONTROLS PER LAYER TYPE */}
                    {selectedLayer.type === 'pin' && (
                      <div className="space-y-2.5 pt-2 border-t border-slate-100 dark:border-zinc-800">
                        <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500">
                          Format PIN
                        </div>
                        <div className="grid grid-cols-2 gap-1.5">
                          <button
                            type="button"
                            onClick={() => updateSelectedLayer({ pinMode: '2-line' })}
                            className={`h-8 rounded-lg border text-[11px] font-semibold cursor-pointer ${
                              selectedLayer.pinMode === '2-line'
                                ? 'border-[#c28e62] bg-[#c28e62]/10 text-[#c28e62]'
                                : 'border-slate-200 dark:border-zinc-700 text-slate-600 dark:text-zinc-300'
                            }`}
                          >
                            2 Baris (4+4)
                          </button>
                          <button
                            type="button"
                            onClick={() => updateSelectedLayer({ pinMode: '1-line' })}
                            className={`h-8 rounded-lg border text-[11px] font-semibold cursor-pointer ${
                              selectedLayer.pinMode === '1-line'
                                ? 'border-[#c28e62] bg-[#c28e62]/10 text-[#c28e62]'
                                : 'border-slate-200 dark:border-zinc-700 text-slate-600 dark:text-zinc-300'
                            }`}
                          >
                            1 Baris
                          </button>
                        </div>
                        {selectedLayer.pinMode === '2-line' && (
                          <label className="block">
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400">
                              Jarak Baris (mm)
                            </span>
                            <MmNumberInput
                              step={0.2}
                              min={1.5}
                              max={15}
                              fallback={4}
                              value={selectedLayer.lineGapMm}
                              onCommit={(val) => updateSelectedLayer({ lineGapMm: val })}
                              className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                            />
                          </label>
                        )}
                      </div>
                    )}

                    {selectedLayer.type === 'tagId' && (
                      <div className="space-y-2 pt-2 border-t border-slate-100 dark:border-zinc-800">
                        <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500">
                          ID Tag
                        </div>
                        <label className="block">
                          <span className="text-[10px] text-slate-500 dark:text-zinc-400">
                            Prefix
                          </span>
                          <input
                            type="text"
                            value={selectedLayer.prefix}
                            onChange={(e) => updateSelectedLayer({ prefix: e.target.value })}
                            placeholder="Contoh: ID: atau #"
                            className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs outline-none focus:border-[#c28e62]"
                          />
                        </label>
                      </div>
                    )}

                    {selectedLayer.type === 'text' && (() => {
                      const updateTextContent = (nextContent: string) => {
                        const prevAuto = selectedLayer.content.trim().slice(0, 32);
                        const shouldSyncLabel =
                          !selectedLayer.label.trim() ||
                          selectedLayer.label === 'Teks Custom' ||
                          selectedLayer.label === 'Teks Original' ||
                          selectedLayer.label === 'Teks Produk Asli' ||
                          selectedLayer.label === prevAuto;
                        updateSelectedLayer({
                          content: nextContent,
                          ...(shouldSyncLabel
                            ? { label: nextContent.trim() ? nextContent.trim().slice(0, 32) : 'Teks Custom' }
                            : {}),
                        });
                      };

                      return (
                        <div className="space-y-2 pt-2 border-t border-slate-100 dark:border-zinc-800">
                          <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500">
                            Teks &amp; Token
                          </div>
                          <input
                            type="text"
                            value={selectedLayer.content}
                            onChange={(e) => updateTextContent(e.target.value)}
                            className="w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs outline-none focus:border-[#c28e62]"
                          />
                          <div className="flex flex-wrap gap-1">
                            {['{brand}', '{sku}', '{batchId}', '{tagNumber}'].map((tok) => (
                              <button
                                key={tok}
                                type="button"
                                onClick={() =>
                                  updateTextContent(`${selectedLayer.content} ${tok}`.trim())
                                }
                                className="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-zinc-800 text-[10px] font-mono text-slate-600 dark:text-zinc-300 hover:text-[#c28e62] cursor-pointer"
                              >
                                +{tok}
                              </button>
                            ))}
                          </div>
                        </div>
                      );
                    })()}

                    {selectedLayer.type === 'brand' && (
                      <div className="pt-2 border-t border-slate-100 dark:border-zinc-800 flex items-center justify-between">
                        <span className="text-xs font-medium text-slate-700 dark:text-zinc-300">
                          Huruf Kapital (UPPERCASE)
                        </span>
                        <CustomCheckbox
                          checked={selectedLayer.uppercase}
                          onChange={(checked) => updateSelectedLayer({ uppercase: checked })}
                          ariaLabel="Huruf Kapital (UPPERCASE)"
                        />
                      </div>
                    )}

                    {/* Shared Typography Inspector */}
                    {(selectedLayer.type === 'pin' ||
                      selectedLayer.type === 'tagId' ||
                      selectedLayer.type === 'brand' ||
                      selectedLayer.type === 'text') && (
                      <div className="space-y-2.5 pt-2 border-t border-slate-100 dark:border-zinc-800">
                        <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500">
                          Tipografi
                        </div>
                        <div className="grid grid-cols-[minmax(0,1fr)_74px] gap-2">
                          <div className="block min-w-0">
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400">Font</span>
                            <div className="mt-0.5">
                              <CustomSelect
                                value={selectedLayer.fontFamily}
                                onChange={(val) =>
                                  updateSelectedLayer({ fontFamily: val as TagFontFamily })
                                }
                                placeholder="Pilih Font"
                                searchable={false}
                                minDropdownWidth={260}
                                buttonClassName="w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs text-slate-700 dark:text-zinc-200 hover:border-[#c28e62] cursor-pointer flex items-center justify-between gap-1"
                                options={[
                                  { value: 'KodeMono', label: 'Kode Mono', badge: 'Mono' },
                                  { value: 'helvetica', label: 'Sans (Helvetica)', badge: 'Sans' },
                                  { value: 'times', label: 'Serif (Times)', badge: 'Serif' },
                                ]}
                              />
                            </div>
                          </div>
                          <label className="block">
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400 whitespace-nowrap">Ukuran (pt)</span>
                            <MmNumberInput
                              step={0.5}
                              min={2}
                              max={2000}
                              fallback={6}
                              value={selectedLayer.fontSizePt}
                              onCommit={(val) => updateSelectedLayer({ fontSizePt: val })}
                              className="mt-0.5 w-full h-8 px-2 text-center rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                            />
                          </label>
                        </div>

                        <div className="grid grid-cols-2 gap-2 items-end">
                          <div>
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400">Perataan Teks</span>
                            <div className="mt-0.5 grid grid-cols-3 gap-1">
                              {(['left', 'center', 'right'] as const).map((al) => (
                                <button
                                  key={al}
                                  type="button"
                                  onClick={() => updateSelectedLayer({ align: al })}
                                  className={`h-8 rounded-lg border text-[10px] font-bold uppercase cursor-pointer ${
                                    selectedLayer.align === al
                                      ? 'border-[#c28e62] bg-[#c28e62]/10 text-[#c28e62]'
                                      : 'border-slate-200 dark:border-zinc-700 text-slate-500 dark:text-zinc-400'
                                  }`}
                                >
                                  {al === 'left' ? 'Kiri' : al === 'center' ? 'Tgh' : 'Knn'}
                                </button>
                              ))}
                            </div>
                          </div>

                          <label className="block">
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400">Warna Teks</span>
                            <div className="mt-0.5 flex items-center gap-1.5 h-8 px-2 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
                              <input
                                type="color"
                                value={selectedLayer.color}
                                onChange={(e) => updateSelectedLayer({ color: e.target.value })}
                                className="w-5 h-5 rounded cursor-pointer border-0 bg-transparent p-0"
                              />
                              <span className="text-[11px] font-mono text-slate-600 dark:text-zinc-300">
                                {selectedLayer.color}
                              </span>
                            </div>
                          </label>
                        </div>
                      </div>
                    )}

                    {selectedLayer.type === 'qr' && (
                      <div className="space-y-2.5 pt-2 border-t border-slate-100 dark:border-zinc-800">
                        <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500">
                          QR Code
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                          <label className="block">
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400">Warna QR</span>
                            <div className="mt-0.5 flex items-center gap-1.5 h-8 px-2 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
                              <input
                                type="color"
                                value={selectedLayer.qrDarkColor}
                                onChange={(e) => updateSelectedLayer({ qrDarkColor: e.target.value })}
                                className="w-5 h-5 rounded cursor-pointer border-0 bg-transparent p-0"
                              />
                              <span className="text-[11px] font-mono">{selectedLayer.qrDarkColor}</span>
                            </div>
                          </label>
                          <label className="block">
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400">BG QR</span>
                            <div className="mt-0.5 flex items-center gap-1.5 h-8 px-2 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
                              <input
                                type="color"
                                value={selectedLayer.qrLightColor}
                                onChange={(e) => updateSelectedLayer({ qrLightColor: e.target.value })}
                                className="w-5 h-5 rounded cursor-pointer border-0 bg-transparent p-0"
                              />
                              <span className="text-[11px] font-mono">{selectedLayer.qrLightColor}</span>
                            </div>
                          </label>
                          <label className="block">
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400">Quiet Zone</span>
                            <MmNumberInput
                              min={0}
                              max={4}
                              fallback={1}
                              value={selectedLayer.qrMargin}
                              onCommit={(val) => updateSelectedLayer({ qrMargin: Math.round(val) })}
                              className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                            />
                          </label>
                          <div className="block">
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400">Koreksi Error</span>
                            <div className="mt-0.5">
                              <CustomSelect
                                value={selectedLayer.qrErrorCorrection}
                                onChange={(val) =>
                                  updateSelectedLayer({
                                    qrErrorCorrection: val as 'L' | 'M' | 'Q' | 'H',
                                  })
                                }
                                placeholder="Level ECC"
                                searchable={false}
                                minDropdownWidth={165}
                                buttonClassName="w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs text-slate-700 dark:text-zinc-200 hover:border-[#c28e62] cursor-pointer flex items-center justify-between gap-1"
                                options={[
                                  { value: 'L', label: 'Low', badge: '7%' },
                                  { value: 'M', label: 'Medium', badge: '15%' },
                                  { value: 'Q', label: 'Quartile', badge: '25%' },
                                  { value: 'H', label: 'High', badge: '30%' },
                                ]}
                              />
                            </div>
                          </div>
                        </div>
                      </div>
                    )}

                    {selectedLayer.type === 'shape' && (
                      <div className="space-y-2.5 pt-2 border-t border-slate-100 dark:border-zinc-800">
                        <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500">
                          Bentuk
                        </div>
                        <div className="grid grid-cols-2 gap-1.5">
                          <button
                            type="button"
                            onClick={() => updateSelectedLayer({ shapeType: 'rect' })}
                            className={`h-8 rounded-lg border text-[11px] font-semibold cursor-pointer ${
                              selectedLayer.shapeType === 'rect'
                                ? 'border-[#c28e62] bg-[#c28e62]/10 text-[#c28e62]'
                                : 'border-slate-200 dark:border-zinc-700 text-slate-600 dark:text-zinc-300'
                            }`}
                          >
                            Kotak
                          </button>
                          <button
                            type="button"
                            onClick={() => updateSelectedLayer({ shapeType: 'line', heightMm: 1 })}
                            className={`h-8 rounded-lg border text-[11px] font-semibold cursor-pointer ${
                              selectedLayer.shapeType === 'line'
                                ? 'border-[#c28e62] bg-[#c28e62]/10 text-[#c28e62]'
                                : 'border-slate-200 dark:border-zinc-700 text-slate-600 dark:text-zinc-300'
                            }`}
                          >
                            Garis
                          </button>
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                          <label className="block">
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400">Warna Isi</span>
                            <div className="mt-0.5 flex items-center gap-1.5 h-8 px-2 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
                              <input
                                type="color"
                                value={selectedLayer.fillColor}
                                onChange={(e) => updateSelectedLayer({ fillColor: e.target.value })}
                                className="w-5 h-5 rounded cursor-pointer border-0 bg-transparent p-0"
                              />
                              <span className="text-[11px] font-mono">{selectedLayer.fillColor}</span>
                            </div>
                          </label>
                          <label className="block">
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400">Warna Garis</span>
                            <div className="mt-0.5 flex items-center gap-1.5 h-8 px-2 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
                              <input
                                type="color"
                                value={selectedLayer.strokeColor}
                                onChange={(e) => updateSelectedLayer({ strokeColor: e.target.value })}
                                className="w-5 h-5 rounded cursor-pointer border-0 bg-transparent p-0"
                              />
                              <span className="text-[11px] font-mono">{selectedLayer.strokeColor}</span>
                            </div>
                          </label>
                          <label className="block">
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400">Tebal Garis (mm)</span>
                            <MmNumberInput
                              step={0.1}
                              min={0}
                              fallback={0}
                              value={selectedLayer.strokeWidthMm}
                              onCommit={(val) => updateSelectedLayer({ strokeWidthMm: val })}
                              className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                            />
                          </label>
                          <label className="block">
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400">Sudut R (mm)</span>
                            <MmNumberInput
                              step={0.5}
                              min={0}
                              fallback={0}
                              value={selectedLayer.cornerRadiusMm}
                              onCommit={(val) => updateSelectedLayer({ cornerRadiusMm: val })}
                              className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                            />
                          </label>
                        </div>
                      </div>
                    )}

                    {selectedLayer.type === 'emblem' && (
                      <div className="space-y-2 pt-2 border-t border-slate-100 dark:border-zinc-800">
                        <div className="grid grid-cols-2 gap-2">
                          <label className="block">
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400">Inisial</span>
                            <input
                              type="text"
                              maxLength={2}
                              value={selectedLayer.letter}
                              onChange={(e) => updateSelectedLayer({ letter: e.target.value.toUpperCase() })}
                              className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-bold outline-none focus:border-[#c28e62]"
                            />
                          </label>
                          <label className="block">
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400">Warna</span>
                            <div className="mt-0.5 flex items-center gap-1.5 h-8 px-2 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
                              <input
                                type="color"
                                value={selectedLayer.color}
                                onChange={(e) => updateSelectedLayer({ color: e.target.value })}
                                className="w-5 h-5 rounded cursor-pointer border-0 bg-transparent p-0"
                              />
                              <span className="text-[11px] font-mono">{selectedLayer.color}</span>
                            </div>
                          </label>
                        </div>
                      </div>
                    )}
                  </>
                ) : selectedLayers.length > 1 ? (
                  <div className="space-y-4">
                    {/* Multi-Selection Header + Bulk Actions */}
                    <div className="p-2.5 rounded-xl border border-[#c28e62]/40 bg-[#fcf7f3] dark:bg-zinc-800/80 space-y-2.5">
                      <div className="flex items-center justify-between gap-2">
                        <span className="text-xs font-bold text-slate-800 dark:text-zinc-100">
                          {selectedLayers.length} Layer Terpilih
                        </span>
                        <button
                          type="button"
                          onClick={() => setSelectedLayerIds([])}
                          className="text-[11px] font-semibold text-slate-500 hover:text-slate-800 dark:hover:text-zinc-200 cursor-pointer"
                        >
                          Batal Pilih
                        </button>
                      </div>
                      <div className="grid grid-cols-4 gap-1">
                        <button
                          type="button"
                          onClick={() => handleDuplicateLayers(selectedLayers)}
                          title="Duplikat Semua Terpilih (Ctrl+D)"
                          className="h-8 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 flex items-center justify-center gap-1 text-[10px] font-semibold text-slate-600 dark:text-zinc-300 hover:border-[#c28e62] hover:text-[#c28e62] cursor-pointer"
                        >
                          <Copy size={12} />
                          <span>Duplikat</span>
                        </button>
                        <button
                          type="button"
                          onClick={handleToggleLockSelected}
                          title="Kunci / Buka Kunci Terpilih"
                          className="h-8 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 flex items-center justify-center gap-1 text-[10px] font-semibold text-slate-600 dark:text-zinc-300 hover:border-[#c28e62] hover:text-[#c28e62] cursor-pointer"
                        >
                          {selectedLayers.every((l) => l.locked) ? <Unlock size={12} /> : <Lock size={12} />}
                          <span>{selectedLayers.every((l) => l.locked) ? 'Buka' : 'Kunci'}</span>
                        </button>
                        <button
                          type="button"
                          onClick={handleToggleVisibleSelected}
                          title="Tampilkan / Sembunyikan Terpilih"
                          className="h-8 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 flex items-center justify-center gap-1 text-[10px] font-semibold text-slate-600 dark:text-zinc-300 hover:border-[#c28e62] hover:text-[#c28e62] cursor-pointer"
                        >
                          {selectedLayers.some((l) => !l.visible) ? <Eye size={12} /> : <EyeOff size={12} />}
                          <span>{selectedLayers.some((l) => !l.visible) ? 'Tampil' : 'Semb.'}</span>
                        </button>
                        <button
                          type="button"
                          onClick={() => handleDeleteLayers(selectedLayers.map((l) => l.id))}
                          disabled={draft.layers.length - selectedLayers.length < 1}
                          title={
                            draft.layers.length - selectedLayers.length < 1
                              ? 'Minimal 1 layer harus tersisa'
                              : 'Hapus Layer Terpilih (Del)'
                          }
                          className="h-8 rounded-lg border border-rose-200 dark:border-rose-900/50 bg-white dark:bg-zinc-900 flex items-center justify-center gap-1 text-[10px] font-semibold text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/30 disabled:opacity-35 disabled:cursor-not-allowed cursor-pointer"
                        >
                          <Trash2 size={12} />
                          <span>Hapus</span>
                        </button>
                      </div>
                    </div>

                    {/* Align Selection Relative to Bounds */}
                    <div>
                      <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500 mb-1.5">
                        Ratakan Antar Elemen
                      </div>
                      <div className="grid grid-cols-6 gap-1">
                        {[
                          { id: 'left', icon: AlignStartVertical, title: 'Rata Kiri' },
                          { id: 'centerH', icon: AlignCenterVertical, title: 'Tengah Horizontal' },
                          { id: 'right', icon: AlignEndVertical, title: 'Rata Kanan' },
                          { id: 'top', icon: AlignStartHorizontal, title: 'Rata Atas' },
                          { id: 'centerV', icon: AlignCenterHorizontal, title: 'Tengah Vertikal' },
                          { id: 'bottom', icon: AlignEndHorizontal, title: 'Rata Bawah' },
                        ].map((btn) => {
                          const Icon = btn.icon;
                          return (
                            <button
                              key={btn.id}
                              type="button"
                              onClick={() => handleAlignSelected(btn.id as any)}
                              title={btn.title}
                              className="h-7 rounded-lg border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 hover:border-[#c28e62] hover:text-[#c28e62] flex items-center justify-center text-slate-600 dark:text-zinc-300 cursor-pointer"
                            >
                              <Icon size={13} />
                            </button>
                          );
                        })}
                      </div>
                    </div>

                    {/* Center Group on Artboard */}
                    <div>
                      <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500 mb-1.5">
                        Posisikan Grup di Kanvas
                      </div>
                      <div className="grid grid-cols-2 gap-1.5">
                        <button
                          type="button"
                          onClick={() => handleCenterGroupOnArtboard('x')}
                          className="h-8 rounded-lg border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 hover:border-[#c28e62] hover:text-[#c28e62] flex items-center justify-center gap-1.5 text-[11px] font-semibold text-slate-600 dark:text-zinc-300 cursor-pointer"
                        >
                          <AlignCenterVertical size={13} />
                          <span>Tengah H</span>
                        </button>
                        <button
                          type="button"
                          onClick={() => handleCenterGroupOnArtboard('y')}
                          className="h-8 rounded-lg border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 hover:border-[#c28e62] hover:text-[#c28e62] flex items-center justify-center gap-1.5 text-[11px] font-semibold text-slate-600 dark:text-zinc-300 cursor-pointer"
                        >
                          <AlignCenterHorizontal size={13} />
                          <span>Tengah V</span>
                        </button>
                      </div>
                    </div>

                    {/* Bulk Layer Ordering */}
                    <div>
                      <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500 mb-1.5">
                        Urutan Layer Terpilih
                      </div>
                      <div className="grid grid-cols-4 gap-1">
                        <button
                          type="button"
                          onClick={() => handleMoveSelectedToEdge('back')}
                          title="Send to Back (Ctrl+Shift+[)"
                          className="h-7 rounded-lg border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 hover:border-[#c28e62] hover:text-[#c28e62] flex items-center justify-center gap-1 text-[10px] font-semibold text-slate-600 dark:text-zinc-300 cursor-pointer"
                        >
                          <SendToBack size={12} />
                          <span>Back</span>
                        </button>
                        <button
                          type="button"
                          onClick={() => handleMoveSelectedOrder(-1)}
                          title="Send Backward (Ctrl+[)"
                          className="h-7 rounded-lg border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 hover:border-[#c28e62] hover:text-[#c28e62] flex items-center justify-center gap-1 text-[10px] font-semibold text-slate-600 dark:text-zinc-300 cursor-pointer"
                        >
                          <ArrowDown size={12} />
                          <span>Backward</span>
                        </button>
                        <button
                          type="button"
                          onClick={() => handleMoveSelectedOrder(1)}
                          title="Bring Forward (Ctrl+])"
                          className="h-7 rounded-lg border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 hover:border-[#c28e62] hover:text-[#c28e62] flex items-center justify-center gap-1 text-[10px] font-semibold text-slate-600 dark:text-zinc-300 cursor-pointer"
                        >
                          <ArrowUp size={12} />
                          <span>Forward</span>
                        </button>
                        <button
                          type="button"
                          onClick={() => handleMoveSelectedToEdge('front')}
                          title="Bring to Front (Ctrl+Shift+])"
                          className="h-7 rounded-lg border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 hover:border-[#c28e62] hover:text-[#c28e62] flex items-center justify-center gap-1 text-[10px] font-semibold text-slate-600 dark:text-zinc-300 cursor-pointer"
                        >
                          <BringToFront size={12} />
                          <span>Front</span>
                        </button>
                      </div>
                    </div>

                    {/* Selected Layers List */}
                    <div>
                      <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500 mb-1.5">
                        Daftar Terpilih
                      </div>
                      <div className="space-y-1 max-h-48 overflow-y-auto pr-0.5">
                        {[...selectedLayers].reverse().map((layer) => (
                          <div
                            key={layer.id}
                            className="flex items-center justify-between gap-2 px-2 py-1.5 rounded-lg border border-slate-200 dark:border-zinc-700/80 bg-slate-50 dark:bg-zinc-800/70"
                          >
                            <button
                              type="button"
                              onClick={() => {
                                setSelectedLayerIds([layer.id]);
                                lastClickedLayerIdRef.current = layer.id;
                              }}
                              className="flex items-center gap-1.5 min-w-0 text-left flex-1 text-[11px] font-semibold text-slate-700 dark:text-zinc-200 hover:text-[#c28e62] cursor-pointer"
                              title="Fokus edit layer ini saja"
                            >
                              {renderLayerIcon(layer.type)}
                              <span className="truncate">{layer.label}</span>
                            </button>
                            <button
                              type="button"
                              onClick={() =>
                                setSelectedLayerIds((prev) => prev.filter((id) => id !== layer.id))
                              }
                              title="Keluarkan dari pilihan"
                              className="p-0.5 rounded text-slate-400 hover:text-rose-500 cursor-pointer"
                            >
                              <X size={11} />
                            </button>
                          </div>
                        ))}
                      </div>
                    </div>
                  </div>
                ) : (
                  <div className="py-10 text-center text-slate-400 dark:text-zinc-500 space-y-2 px-2">
                    <Sliders size={22} className="mx-auto opacity-50" />
                    <p className="text-xs leading-relaxed">
                      Klik elemen di kanvas atau daftar Layers untuk mengedit. Tahan <span className="font-mono font-semibold">Shift/Ctrl+Klik</span>, tekan <span className="font-mono font-semibold">Ctrl+A</span>, atau <span className="font-mono font-semibold">drag</span> di area kosong kanvas untuk memilih banyak layer sekaligus.
                    </p>
                  </div>
                )
              ) : (
                /* TAB 2: STICKER ARTBOARD & SHEET IMPOSITION */
                <div className="space-y-4">
                  <div className="space-y-2.5">
                    <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500">
                      Ukuran Tag (mm)
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                      <label className="block">
                        <span className="text-[10px] text-slate-500 dark:text-zinc-400">Lebar Tag (mm)</span>
                        <MmNumberInput
                          min={5}
                          max={300}
                          step={1}
                          fallback={25}
                          value={draft.sticker.widthMm}
                          onCommit={(val) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sticker: { ...prev.sticker, widthMm: val },
                            }))
                          }
                          className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                        />
                      </label>
                      <label className="block">
                        <span className="text-[10px] text-slate-500 dark:text-zinc-400">Tinggi Tag (mm)</span>
                        <MmNumberInput
                          min={5}
                          max={450}
                          step={1}
                          fallback={60}
                          value={draft.sticker.heightMm}
                          onCommit={(val) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sticker: { ...prev.sticker, heightMm: val },
                            }))
                          }
                          className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                        />
                      </label>
                      <label className="block">
                        <span className="text-[10px] text-slate-500 dark:text-zinc-400">Sudut Rounded R (mm)</span>
                        <MmNumberInput
                          min={0}
                          max={50}
                          step={0.5}
                          fallback={0}
                          value={draft.sticker.cornerRadiusMm}
                          onCommit={(val) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sticker: { ...prev.sticker, cornerRadiusMm: val },
                            }))
                          }
                          className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                        />
                      </label>
                      <label className="block">
                        <span className="text-[10px] text-slate-500 dark:text-zinc-400">Warna Dasar</span>
                        <div className="mt-0.5 flex items-center gap-1.5 h-8 px-2 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800">
                          <input
                            type="color"
                            value={draft.sticker.backgroundColor}
                            onChange={(e) =>
                              updateDraft((prev) => ({
                                ...prev,
                                sticker: { ...prev.sticker, backgroundColor: e.target.value },
                              }))
                            }
                            className="w-5 h-5 rounded cursor-pointer border-0 bg-transparent p-0"
                          />
                          <span className="text-[11px] font-mono">{draft.sticker.backgroundColor}</span>
                        </div>
                      </label>
                    </div>

                    {/* Background Artwork Upload */}
                    <div className="flex items-center gap-2 pt-1">
                      <button
                        type="button"
                        onClick={() => artworkInputRef.current?.click()}
                        className="flex-1 h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-slate-50 dark:bg-zinc-800 hover:border-[#c28e62] text-[11px] font-semibold text-slate-700 dark:text-zinc-200 flex items-center justify-center gap-1.5 cursor-pointer"
                      >
                        <ImageIcon size={13} />
                        <span>
                          {draft.sticker.backgroundImageDataUrl ? 'Ganti Artwork BG' : 'Upload Artwork BG'}
                        </span>
                      </button>
                      {draft.sticker.backgroundImageDataUrl && (
                        <button
                          type="button"
                          onClick={() =>
                            updateDraft((prev) => ({
                              ...prev,
                              sticker: { ...prev.sticker, backgroundImageDataUrl: null },
                            }))
                          }
                          className="h-8 px-2.5 rounded-lg border border-rose-200 dark:border-rose-900/50 text-rose-500 text-[11px] font-semibold cursor-pointer"
                        >
                          Hapus
                        </button>
                      )}
                    </div>

                    {/* Die-Cut Line Options */}
                    <div className="flex items-center justify-between pt-1">
                      <span className="text-xs font-medium text-slate-700 dark:text-zinc-300 flex items-center gap-1.5">
                        <Scissors size={13} className="text-rose-500" /> Garis Potong (Die-Cut)
                      </span>
                      <div className="flex items-center gap-2.5">
                        <input
                          type="color"
                          value={draft.sticker.cutLineColor}
                          onChange={(e) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sticker: { ...prev.sticker, cutLineColor: e.target.value },
                            }))
                          }
                          className="w-5 h-5 rounded cursor-pointer border-0 bg-transparent p-0"
                        />
                        <CustomCheckbox
                          checked={draft.sticker.showCutLine}
                          onChange={(checked) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sticker: { ...prev.sticker, showCutLine: checked },
                            }))
                          }
                          ariaLabel="Aktifkan Garis Potong Die-Cut"
                        />
                      </div>
                    </div>
                  </div>

                  {/* PAPER SHEET SETUP */}
                  <div className="space-y-2.5 pt-3 border-t border-slate-100 dark:border-zinc-800">
                    <div className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500">
                      Kertas &amp; Grid
                    </div>

                    <div className="grid grid-cols-4 gap-1">
                      {(['A3+', 'A4', 'F4', 'Custom'] as PaperSheetPreset[]).map((preset) => (
                        <button
                          key={preset}
                          type="button"
                          onClick={() => {
                            if (preset === 'Custom') {
                              updateDraft((prev) => ({
                                ...prev,
                                sheet: { ...prev.sheet, preset: 'Custom' },
                              }));
                            } else {
                              const dims = PAPER_SHEET_PRESETS[preset];
                              updateDraft((prev) => ({
                                ...prev,
                                sheet: {
                                  ...prev.sheet,
                                  preset,
                                  widthMm: dims.widthMm,
                                  heightMm: dims.heightMm,
                                },
                              }));
                            }
                          }}
                          className={`h-7 rounded-lg border text-[11px] font-semibold cursor-pointer ${
                            draft.sheet.preset === preset
                              ? 'border-[#c28e62] bg-[#c28e62]/10 text-[#c28e62]'
                              : 'border-slate-200 dark:border-zinc-700 text-slate-600 dark:text-zinc-300'
                          }`}
                        >
                          {preset}
                        </button>
                      ))}
                    </div>

                    <div className="grid grid-cols-2 gap-2">
                      <label className="block">
                        <span className="text-[10px] text-slate-500 dark:text-zinc-400">Kertas W (mm)</span>
                        <MmNumberInput
                          min={30}
                          max={1200}
                          fallback={310}
                          value={draft.sheet.widthMm}
                          onCommit={(val) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sheet: { ...prev.sheet, preset: 'Custom', widthMm: val },
                            }))
                          }
                          className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                        />
                      </label>
                      <label className="block">
                        <span className="text-[10px] text-slate-500 dark:text-zinc-400">Kertas H (mm)</span>
                        <MmNumberInput
                          min={30}
                          max={1200}
                          fallback={470}
                          value={draft.sheet.heightMm}
                          onCommit={(val) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sheet: { ...prev.sheet, preset: 'Custom', heightMm: val },
                            }))
                          }
                          className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                        />
                      </label>
                      <label className="block">
                        <span className="text-[10px] text-slate-500 dark:text-zinc-400">Jarak X / Gap (mm)</span>
                        <MmNumberInput
                          min={0}
                          max={30}
                          step={0.5}
                          fallback={0}
                          value={draft.sheet.gapXMm}
                          onCommit={(val) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sheet: { ...prev.sheet, gapXMm: val },
                            }))
                          }
                          className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                        />
                      </label>
                      <label className="block">
                        <span className="text-[10px] text-slate-500 dark:text-zinc-400">Jarak Y / Gap (mm)</span>
                        <MmNumberInput
                          min={0}
                          max={30}
                          step={0.5}
                          fallback={0}
                          value={draft.sheet.gapYMm}
                          onCommit={(val) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sheet: { ...prev.sheet, gapYMm: val },
                            }))
                          }
                          className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                        />
                      </label>
                      <label className="block">
                        <span className="text-[10px] text-slate-500 dark:text-zinc-400">Margin Tepi X (mm)</span>
                        <MmNumberInput
                          min={0}
                          max={60}
                          step={1}
                          fallback={0}
                          value={draft.sheet.marginXMm}
                          onCommit={(val) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sheet: { ...prev.sheet, marginXMm: val },
                            }))
                          }
                          className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                        />
                      </label>
                      <label className="block">
                        <span className="text-[10px] text-slate-500 dark:text-zinc-400">Margin Tepi Y (mm)</span>
                        <MmNumberInput
                          min={0}
                          max={60}
                          step={1}
                          fallback={0}
                          value={draft.sheet.marginYMm}
                          onCommit={(val) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sheet: { ...prev.sheet, marginYMm: val },
                            }))
                          }
                          className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                        />
                      </label>
                    </div>

                    <div className="flex items-center justify-between pt-1">
                      <span className="text-xs font-medium text-slate-700 dark:text-zinc-300">
                        Sensor Marks Sudut Kertas
                      </span>
                      <CustomCheckbox
                        checked={draft.sheet.showRegistrationMarks}
                        onChange={(checked) =>
                          updateDraft((prev) => ({
                            ...prev,
                            sheet: { ...prev.sheet, showRegistrationMarks: checked },
                          }))
                        }
                        ariaLabel="Sensor Marks Sudut Kertas"
                      />
                    </div>

                    {/* Live Sheet Imposition Mini Diagram */}
                    <div className="p-2.5 rounded-xl bg-slate-50 dark:bg-zinc-950 border border-slate-200 dark:border-zinc-800 space-y-2">
                      <div className="flex items-center justify-between text-[11px] font-semibold">
                        <span className="text-slate-600 dark:text-zinc-300">Simulasi 1 Lembar:</span>
                        <span className="text-[#c28e62]">
                          {gridMetrics.cols} × {gridMetrics.rows} = {gridMetrics.perPage} stiker
                        </span>
                      </div>
                      {!gridMetrics.fitsOnSheet && (
                        <p className="text-[10px] font-semibold text-rose-500">
                          Ukuran tag lebih besar dari area cetak kertas! Perkecil tag atau perbesar kertas.
                        </p>
                      )}
                      <div className="flex justify-center py-1">
                        <div
                          className="bg-white border border-slate-300 relative overflow-hidden"
                          style={{
                            width: `${Math.min(130, (draft.sheet.widthMm / draft.sheet.heightMm) * 150)}px`,
                            height: '150px',
                          }}
                        >
                          <div
                            className="w-full h-full grid gap-[1px] p-[4px]"
                            style={{
                              gridTemplateColumns: `repeat(${Math.min(20, gridMetrics.cols)}, minmax(0, 1fr))`,
                              gridTemplateRows: `repeat(${Math.min(20, gridMetrics.rows)}, minmax(0, 1fr))`,
                            }}
                          >
                            {Array.from({
                              length: Math.min(20, gridMetrics.cols) * Math.min(20, gridMetrics.rows),
                            }).map((_, idx) => (
                              <div
                                key={idx}
                                className="border border-red-400/80 bg-[#fcf7f3] rounded-[1px]"
                              />
                            ))}
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              )}
            </div>
          </aside>
        </div>
      </div>
    </div>
  );
};
