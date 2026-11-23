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

interface TagLayoutStudioModalProps {
  isOpen: boolean;
  onClose: () => void;
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
      layerId: string;
      startClientX: number;
      startClientY: number;
      origXMm: number;
      origYMm: number;
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
    };

function roundMm(val: number): number {
  return Math.round(val * 10) / 10;
}

export const TagLayoutStudioModal: React.FC<TagLayoutStudioModalProps> = ({
  isOpen,
  onClose,
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
  const [selectedLayerId, setSelectedLayerId] = useState<string | null>(
    initialTemplate.layers[0]?.id ?? null
  );
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

  const dragRef = useRef<DragMode | null>(null);
  const draftRef = useRef<TagLayoutTemplate>(draft);
  const dragSnapshotRef = useRef<TagLayoutTemplate | null>(null);
  const canvasViewportRef = useRef<HTMLElement | null>(null);
  const artboardRef = useRef<HTMLDivElement | null>(null);
  const artworkInputRef = useRef<HTMLInputElement | null>(null);

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
    if (isOpen) {
      const target =
        templates.find((t) => t.id === activeTemplateId) ||
        templates[0] ||
        getBuiltInTemplates()[0];
      const cloned = cloneTemplate(target);
      setDraft(cloned);
      setHistoryPast([]);
      setHistoryFuture([]);
      setSelectedLayerId(cloned.layers[cloned.layers.length - 1]?.id ?? null);
      setSavedFeedback('');
      requestAnimationFrame(() => {
        setZoom(computeFitZoom(cloned));
      });
    }
  }, [isOpen, activeTemplateId, templates]);

  const pxPerMm = BASE_PX_PER_MM * zoom;
  const gridMetrics = useMemo(() => computeSheetGrid(draft), [draft]);
  const selectedLayer = useMemo(
    () => draft.layers.find((l) => l.id === selectedLayerId) || null,
    [draft.layers, selectedLayerId]
  );
  const selectedLayerIndex = useMemo(
    () => draft.layers.findIndex((l) => l.id === selectedLayerId),
    [draft.layers, selectedLayerId]
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
    if (!selectedLayerId) return;
    updateDraft((prev) => ({
      ...prev,
      layers: prev.layers.map((l) =>
        l.id === selectedLayerId ? ({ ...l, ...patch } as TagLayer) : l
      ),
    }));
  };

  // Pointer Drag / Resize / Rotate Handlers
  useEffect(() => {
    if (!isOpen) return;

    const handlePointerMove = (e: PointerEvent) => {
      const mode = dragRef.current;
      if (!mode) return;

      if (mode.kind === 'move') {
        const dxMm = (e.clientX - mode.startClientX) / pxPerMm;
        const dyMm = (e.clientY - mode.startClientY) / pxPerMm;

        setDraft((prev) => {
          const target = prev.layers.find((l) => l.id === mode.layerId);
          if (!target) return prev;

          let nextX = mode.origXMm + dxMm;
          let nextY = mode.origYMm + dyMm;
          let guideX: number | null = null;
          let guideY: number | null = null;

          if (snapEnabled) {
            const threshold = 0.7;
            const artW = prev.sticker.widthMm;
            const artH = prev.sticker.heightMm;

            const xCandidates = [0, artW / 2, artW];
            const yCandidates = [0, artH / 2, artH];

            for (const other of prev.layers) {
              if (other.id === target.id || !other.visible) continue;
              xCandidates.push(other.xMm, other.xMm + other.widthMm / 2, other.xMm + other.widthMm);
              yCandidates.push(other.yMm, other.yMm + other.heightMm / 2, other.yMm + other.heightMm);
            }

            // Snap left / center / right edge of moving layer
            const layerXOffsets = [0, target.widthMm / 2, target.widthMm];
            let bestDx = threshold;
            for (const off of layerXOffsets) {
              for (const cand of xCandidates) {
                const diff = cand - (nextX + off);
                if (Math.abs(diff) < Math.abs(bestDx)) {
                  bestDx = diff;
                  guideX = cand;
                }
              }
            }
            if (guideX !== null) nextX += bestDx;

            const layerYOffsets = [0, target.heightMm / 2, target.heightMm];
            let bestDy = threshold;
            for (const off of layerYOffsets) {
              for (const cand of yCandidates) {
                const diff = cand - (nextY + off);
                if (Math.abs(diff) < Math.abs(bestDy)) {
                  bestDy = diff;
                  guideY = cand;
                }
              }
            }
            if (guideY !== null) nextY += bestDy;
          }

          setSnapGuides({ xMm: guideX, yMm: guideY });

          return {
            ...prev,
            layers: prev.layers.map((l) =>
              l.id === mode.layerId
                ? ({ ...l, xMm: roundMm(nextX), yMm: roundMm(nextY) } as TagLayer)
                : l
            ),
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
      }
    };

    const handlePointerUp = () => {
      if (dragRef.current) {
        dragRef.current = null;
        setSnapGuides({ xMm: null, yMm: null });
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

      if (mod) {
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
        // Duplicate Selected Layer: Ctrl/Cmd + D
        if (keyLower === 'd' && selectedLayer) {
          e.preventDefault();
          handleDuplicateLayer(selectedLayer);
          return;
        }
        // Arrange Forward/Backward: Ctrl/Cmd + ] / [ (with Shift = Front / Back)
        if ((e.key === ']' || e.code === 'BracketRight') && selectedLayerIndex !== -1) {
          e.preventDefault();
          if (e.shiftKey) {
            handleMoveLayerToEdge(selectedLayerIndex, 'front');
          } else {
            handleMoveLayerOrder(selectedLayerIndex, 1);
          }
          return;
        }
        if ((e.key === '[' || e.code === 'BracketLeft') && selectedLayerIndex !== -1) {
          e.preventDefault();
          if (e.shiftKey) {
            handleMoveLayerToEdge(selectedLayerIndex, 'back');
          } else {
            handleMoveLayerOrder(selectedLayerIndex, -1);
          }
          return;
        }
      }

      if (!selectedLayer || selectedLayer.locked) return;

      const step = e.shiftKey ? 2.0 : 0.5;
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        updateSelectedLayer({ xMm: roundMm(selectedLayer.xMm - step) });
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        updateSelectedLayer({ xMm: roundMm(selectedLayer.xMm + step) });
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        updateSelectedLayer({ yMm: roundMm(selectedLayer.yMm - step) });
      } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        updateSelectedLayer({ yMm: roundMm(selectedLayer.yMm + step) });
      } else if ((e.key === 'Delete' || e.key === 'Backspace') && draft.layers.length > 1) {
        e.preventDefault();
        handleDeleteLayer(selectedLayer.id);
      }
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, selectedLayer, selectedLayerIndex, draft.layers.length, historyPast, historyFuture]);

  if (!isOpen) return null;

  const handleAddLayer = (type: TagLayerType) => {
    const newLayer = createNewLayer(type, draft.sticker.widthMm, draft.sticker.heightMm);
    updateDraft((prev) => ({
      ...prev,
      layers: [...prev.layers, newLayer],
    }));
    setSelectedLayerId(newLayer.id);
    setInspectorTab('layer');
  };

  const handleDeleteLayer = (layerId: string) => {
    updateDraft((prev) => {
      const remaining = prev.layers.filter((l) => l.id !== layerId);
      if (selectedLayerId === layerId) {
        setSelectedLayerId(remaining[remaining.length - 1]?.id ?? null);
      }
      return { ...prev, layers: remaining };
    });
  };

  const handleDuplicateLayer = (layer: TagLayer) => {
    const copy: TagLayer = {
      ...cloneTemplate({ ...draft, layers: [layer] }).layers[0],
      id: `layer-${layer.type}-${Date.now().toString(36)}`,
      label: `${layer.label} (Copy)`,
      xMm: roundMm(layer.xMm + 1.5),
      yMm: roundMm(layer.yMm + 1.5),
    };
    updateDraft((prev) => ({ ...prev, layers: [...prev.layers, copy] }));
    setSelectedLayerId(copy.id);
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

  const handleAlignSelected = (align: 'left' | 'centerH' | 'right' | 'top' | 'centerV' | 'bottom') => {
    if (!selectedLayer) return;
    const sw = draft.sticker.widthMm;
    const sh = draft.sticker.heightMm;
    switch (align) {
      case 'left':
        updateSelectedLayer({ xMm: 0 });
        break;
      case 'centerH':
        updateSelectedLayer({ xMm: roundMm((sw - selectedLayer.widthMm) / 2) });
        break;
      case 'right':
        updateSelectedLayer({ xMm: roundMm(sw - selectedLayer.widthMm) });
        break;
      case 'top':
        updateSelectedLayer({ yMm: 0 });
        break;
      case 'centerV':
        updateSelectedLayer({ yMm: roundMm((sh - selectedLayer.heightMm) / 2) });
        break;
      case 'bottom':
        updateSelectedLayer({ yMm: roundMm(sh - selectedLayer.heightMm) });
        break;
    }
  };

  const handleResetBuiltIn = () => {
    const builtIn = getBuiltInTemplates().find((b) => b.id === draft.id) || getBuiltInTemplates()[0];
    const cloned = cloneTemplate(builtIn);
    setDraft(cloned);
    setSelectedLayerId(cloned.layers[cloned.layers.length - 1]?.id ?? null);
    setSavedFeedback('Direset ke bawaan pabrik');
    setTimeout(() => setSavedFeedback(''), 2500);
  };

  const handleSaveCurrent = () => {
    const saved = onSaveTemplate(draft, false);
    setDraft(cloneTemplate(saved));
    setSavedFeedback('Template disimpan');
    setTimeout(() => setSavedFeedback(''), 2500);
  };

  const handleConfirmSaveAs = () => {
    const cleanName = saveAsName.trim();
    if (!cleanName) return;
    const copy: TagLayoutTemplate = {
      ...cloneTemplate(draft),
      id: `custom-${Date.now().toString(36)}`,
      name: cleanName,
      description: `Custom ${draft.sticker.widthMm}×${draft.sticker.heightMm} mm · Kertas ${draft.sheet.preset}`,
      isBuiltIn: false,
    };
    const saved = onSaveTemplate(copy, true);
    setDraft(cloneTemplate(saved));
    setShowSaveAsDialog(false);
    setSaveAsName('');
    setSavedFeedback(`Disimpan sebagai "${saved.name}"`);
    setTimeout(() => setSavedFeedback(''), 2800);
  };

  const handleConfirmRename = () => {
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
                minDropdownWidth={270}
                className="w-[175px] sm:w-[205px]"
                buttonClassName="w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-semibold text-slate-700 dark:text-zinc-200 hover:border-[#c28e62] cursor-pointer flex items-center justify-between gap-1.5"
                options={templates.map((t) => ({
                  value: t.id,
                  label: t.name,
                  badge: `${t.sticker.widthMm}×${t.sticker.heightMm}mm`,
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
              <div className="flex items-center justify-between px-1 pb-1">
                <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-zinc-500 flex items-center gap-1">
                  <Layers size={11} /> Layers ({draft.layers.length})
                </span>
                <span className="text-[10px] text-slate-400 dark:text-zinc-500">Atas = Depan</span>
              </div>

              {[...draft.layers].reverse().map((layer, revIdx) => {
                const realIdx = draft.layers.length - 1 - revIdx;
                const isSelected = layer.id === selectedLayerId;
                return (
                  <div
                    key={layer.id}
                    onClick={() => {
                      setSelectedLayerId(layer.id);
                      setInspectorTab('layer');
                    }}
                    className={`group flex items-center gap-1.5 px-2 py-1.5 rounded-lg border text-xs cursor-pointer transition-colors ${
                      isSelected
                        ? 'border-[#c28e62] bg-[#fcf7f3] dark:bg-zinc-800 text-slate-900 dark:text-zinc-100 font-semibold'
                        : 'border-transparent hover:bg-slate-50 dark:hover:bg-zinc-800/50 text-slate-600 dark:text-zinc-300'
                    }`}
                  >
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
              if (e.target === e.currentTarget) setSelectedLayerId(null);
            }}
            className="flex-1 min-w-0 min-h-0 bg-slate-100 dark:bg-zinc-950 relative overflow-auto flex items-center justify-center p-8 sm:p-12"
            style={{
              backgroundImage:
                'radial-gradient(circle, rgba(148,163,184,0.28) 1px, transparent 1px)',
              backgroundSize: '18px 18px',
            }}
          >
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
                selectedLayerId={selectedLayerId}
                showGrid={showGrid}
                snapGuides={snapGuides}
                onArtboardClick={() => setSelectedLayerId(null)}
                onLayerPointerDown={(e, layer) => {
                  setSelectedLayerId(layer.id);
                  setInspectorTab('layer');
                  dragSnapshotRef.current = cloneTemplate(draftRef.current);
                  dragRef.current = {
                    kind: 'move',
                    layerId: layer.id,
                    startClientX: e.clientX,
                    startClientY: e.clientY,
                    origXMm: layer.xMm,
                    origYMm: layer.yMm,
                  };
                }}
                onResizePointerDown={(e, layer, handle) => {
                  setSelectedLayerId(layer.id);
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
                          <input
                            type="number"
                            step="0.5"
                            value={selectedLayer.xMm}
                            onChange={(e) => updateSelectedLayer({ xMm: Number(e.target.value) || 0 })}
                            className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono text-slate-800 dark:text-zinc-100 outline-none focus:border-[#c28e62]"
                          />
                        </label>
                        <label className="block">
                          <span className="text-[10px] text-slate-500 dark:text-zinc-400">Y (mm)</span>
                          <input
                            type="number"
                            step="0.5"
                            value={selectedLayer.yMm}
                            onChange={(e) => updateSelectedLayer({ yMm: Number(e.target.value) || 0 })}
                            className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono text-slate-800 dark:text-zinc-100 outline-none focus:border-[#c28e62]"
                          />
                        </label>
                        <label className="block">
                          <span className="text-[10px] text-slate-500 dark:text-zinc-400">Lebar W (mm)</span>
                          <input
                            type="number"
                            step="0.5"
                            min="1"
                            value={selectedLayer.widthMm}
                            onChange={(e) =>
                              updateSelectedLayer({ widthMm: Math.max(1, Number(e.target.value) || 1) })
                            }
                            className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono text-slate-800 dark:text-zinc-100 outline-none focus:border-[#c28e62]"
                          />
                        </label>
                        <label className="block">
                          <span className="text-[10px] text-slate-500 dark:text-zinc-400">Tinggi H (mm)</span>
                          <input
                            type="number"
                            step="0.5"
                            min="1"
                            value={selectedLayer.heightMm}
                            onChange={(e) =>
                              updateSelectedLayer({ heightMm: Math.max(1, Number(e.target.value) || 1) })
                            }
                            className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono text-slate-800 dark:text-zinc-100 outline-none focus:border-[#c28e62]"
                          />
                        </label>
                      </div>
                      <div className="mt-2 flex items-center gap-2">
                        <label className="flex-1 block">
                          <span className="text-[10px] text-slate-500 dark:text-zinc-400">Rotasi (°)</span>
                          <input
                            type="number"
                            step="15"
                            value={selectedLayer.rotation}
                            onChange={(e) =>
                              updateSelectedLayer({
                                rotation: (((Number(e.target.value) || 0) % 360) + 360) % 360,
                              })
                            }
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
                            <input
                              type="number"
                              step="0.2"
                              min="1.5"
                              max="15"
                              value={selectedLayer.lineGapMm}
                              onChange={(e) =>
                                updateSelectedLayer({ lineGapMm: Math.max(1.5, Number(e.target.value) || 4) })
                              }
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
                          size="sm"
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
                        <div className="grid grid-cols-2 gap-2">
                          <div className="block">
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400">Font</span>
                            <div className="mt-0.5">
                              <CustomSelect
                                value={selectedLayer.fontFamily}
                                onChange={(val) =>
                                  updateSelectedLayer({ fontFamily: val as TagFontFamily })
                                }
                                placeholder="Pilih Font"
                                searchable={false}
                                minDropdownWidth={180}
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
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400">Ukuran (pt)</span>
                            <input
                              type="number"
                              step="0.5"
                              min="2"
                              max="72"
                              value={selectedLayer.fontSizePt}
                              onChange={(e) =>
                                updateSelectedLayer({
                                  fontSizePt: Math.max(2, Number(e.target.value) || 6),
                                })
                              }
                              className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
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
                            <input
                              type="number"
                              min="0"
                              max="4"
                              value={selectedLayer.qrMargin}
                              onChange={(e) =>
                                updateSelectedLayer({ qrMargin: Math.max(0, Math.min(4, Number(e.target.value) || 0)) })
                              }
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
                            <input
                              type="number"
                              step="0.1"
                              min="0"
                              value={selectedLayer.strokeWidthMm}
                              onChange={(e) =>
                                updateSelectedLayer({ strokeWidthMm: Math.max(0, Number(e.target.value) || 0) })
                              }
                              className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                            />
                          </label>
                          <label className="block">
                            <span className="text-[10px] text-slate-500 dark:text-zinc-400">Sudut R (mm)</span>
                            <input
                              type="number"
                              step="0.5"
                              min="0"
                              value={selectedLayer.cornerRadiusMm}
                              onChange={(e) =>
                                updateSelectedLayer({ cornerRadiusMm: Math.max(0, Number(e.target.value) || 0) })
                              }
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
                ) : (
                  <div className="py-10 text-center text-slate-400 dark:text-zinc-500 space-y-2">
                    <Sliders size={22} className="mx-auto opacity-50" />
                    <p className="text-xs">Klik elemen di canvas atau di daftar Layers untuk mengedit posisinya.</p>
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
                        <input
                          type="number"
                          min="8"
                          max="300"
                          step="1"
                          value={draft.sticker.widthMm}
                          onChange={(e) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sticker: { ...prev.sticker, widthMm: Math.max(8, Number(e.target.value) || 25) },
                            }))
                          }
                          className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                        />
                      </label>
                      <label className="block">
                        <span className="text-[10px] text-slate-500 dark:text-zinc-400">Tinggi Tag (mm)</span>
                        <input
                          type="number"
                          min="8"
                          max="450"
                          step="1"
                          value={draft.sticker.heightMm}
                          onChange={(e) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sticker: { ...prev.sticker, heightMm: Math.max(8, Number(e.target.value) || 60) },
                            }))
                          }
                          className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                        />
                      </label>
                      <label className="block">
                        <span className="text-[10px] text-slate-500 dark:text-zinc-400">Sudut Rounded R (mm)</span>
                        <input
                          type="number"
                          min="0"
                          max="50"
                          step="0.5"
                          value={draft.sticker.cornerRadiusMm}
                          onChange={(e) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sticker: { ...prev.sticker, cornerRadiusMm: Math.max(0, Number(e.target.value) || 0) },
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
                          size="sm"
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
                        <input
                          type="number"
                          min="30"
                          max="1200"
                          value={draft.sheet.widthMm}
                          onChange={(e) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sheet: {
                                ...prev.sheet,
                                preset: 'Custom',
                                widthMm: Math.max(30, Number(e.target.value) || 310),
                              },
                            }))
                          }
                          className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                        />
                      </label>
                      <label className="block">
                        <span className="text-[10px] text-slate-500 dark:text-zinc-400">Kertas H (mm)</span>
                        <input
                          type="number"
                          min="30"
                          max="1200"
                          value={draft.sheet.heightMm}
                          onChange={(e) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sheet: {
                                ...prev.sheet,
                                preset: 'Custom',
                                heightMm: Math.max(30, Number(e.target.value) || 470),
                              },
                            }))
                          }
                          className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                        />
                      </label>
                      <label className="block">
                        <span className="text-[10px] text-slate-500 dark:text-zinc-400">Jarak X / Gap (mm)</span>
                        <input
                          type="number"
                          min="0"
                          max="30"
                          step="0.5"
                          value={draft.sheet.gapXMm}
                          onChange={(e) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sheet: { ...prev.sheet, gapXMm: Math.max(0, Number(e.target.value) || 0) },
                            }))
                          }
                          className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                        />
                      </label>
                      <label className="block">
                        <span className="text-[10px] text-slate-500 dark:text-zinc-400">Jarak Y / Gap (mm)</span>
                        <input
                          type="number"
                          min="0"
                          max="30"
                          step="0.5"
                          value={draft.sheet.gapYMm}
                          onChange={(e) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sheet: { ...prev.sheet, gapYMm: Math.max(0, Number(e.target.value) || 0) },
                            }))
                          }
                          className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                        />
                      </label>
                      <label className="block">
                        <span className="text-[10px] text-slate-500 dark:text-zinc-400">Margin Tepi X (mm)</span>
                        <input
                          type="number"
                          min="0"
                          max="60"
                          step="1"
                          value={draft.sheet.marginXMm}
                          onChange={(e) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sheet: { ...prev.sheet, marginXMm: Math.max(0, Number(e.target.value) || 0) },
                            }))
                          }
                          className="mt-0.5 w-full h-8 px-2.5 rounded-lg border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-xs font-mono outline-none focus:border-[#c28e62]"
                        />
                      </label>
                      <label className="block">
                        <span className="text-[10px] text-slate-500 dark:text-zinc-400">Margin Tepi Y (mm)</span>
                        <input
                          type="number"
                          min="0"
                          max="60"
                          step="1"
                          value={draft.sheet.marginYMm}
                          onChange={(e) =>
                            updateDraft((prev) => ({
                              ...prev,
                              sheet: { ...prev.sheet, marginYMm: Math.max(0, Number(e.target.value) || 0) },
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
                        size="sm"
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
