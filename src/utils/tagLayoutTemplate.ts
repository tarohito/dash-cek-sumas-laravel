export type PaperSheetPreset = 'A3+' | 'A4' | 'F4' | 'Custom';

export type TagLayerType =
  | 'qr'
  | 'pin'
  | 'tagId'
  | 'brand'
  | 'emblem'
  | 'text'
  | 'shape';

export type TagFontFamily = 'KodeMono' | 'helvetica' | 'times';

export interface TagLayerBase {
  id: string;
  type: TagLayerType;
  label: string;
  visible: boolean;
  locked: boolean;
  xMm: number;
  yMm: number;
  widthMm: number;
  heightMm: number;
  rotation: number; // clockwise degrees (0..360)
}

export interface TagQrLayer extends TagLayerBase {
  type: 'qr';
  qrDarkColor: string;
  qrLightColor: string;
  qrMargin: number;
  qrErrorCorrection: 'L' | 'M' | 'Q' | 'H';
}

export interface TagPinLayer extends TagLayerBase {
  type: 'pin';
  pinMode: '1-line' | '2-line';
  lineGapMm: number;
  fontFamily: TagFontFamily;
  fontSizePt: number;
  fontWeight: 'bold' | 'normal';
  color: string;
  align: 'left' | 'center' | 'right';
}

export interface TagIdLayer extends TagLayerBase {
  type: 'tagId';
  prefix: string;
  fontFamily: TagFontFamily;
  fontSizePt: number;
  fontWeight: 'bold' | 'normal';
  color: string;
  align: 'left' | 'center' | 'right';
}

export interface TagBrandLayer extends TagLayerBase {
  type: 'brand';
  fontFamily: TagFontFamily;
  fontSizePt: number;
  fontWeight: 'bold' | 'normal';
  color: string;
  align: 'left' | 'center' | 'right';
  uppercase: boolean;
}

export interface TagEmblemLayer extends TagLayerBase {
  type: 'emblem';
  color: string;
  letter: string;
}

export interface TagTextLayer extends TagLayerBase {
  type: 'text';
  content: string;
  fontFamily: TagFontFamily;
  fontSizePt: number;
  fontWeight: 'bold' | 'normal';
  color: string;
  align: 'left' | 'center' | 'right';
}

export interface TagShapeLayer extends TagLayerBase {
  type: 'shape';
  shapeType: 'rect' | 'line';
  fillColor: string;
  strokeColor: string;
  strokeWidthMm: number;
  cornerRadiusMm: number;
  transparentFill?: boolean;
}

export type TagLayer =
  | TagQrLayer
  | TagPinLayer
  | TagIdLayer
  | TagBrandLayer
  | TagEmblemLayer
  | TagTextLayer
  | TagShapeLayer;

export interface TagLayoutTemplate {
  id: string;
  name: string;
  description: string;
  isBuiltIn?: boolean;
  createdAt?: string;
  updatedAt?: string;
  sticker: {
    widthMm: number;
    heightMm: number;
    cornerRadiusMm: number;
    backgroundColor: string;
    showCutLine: boolean;
    cutLineColor: string;
    cutLineWidthMm: number;
    backgroundImageDataUrl?: string | null;
  };
  sheet: {
    preset: PaperSheetPreset;
    widthMm: number;
    heightMm: number;
    marginXMm: number;
    marginYMm: number;
    gapXMm: number;
    gapYMm: number;
    showRegistrationMarks: boolean;
  };
  layers: TagLayer[];
}

export const PAPER_SHEET_PRESETS: Record<
  Exclude<PaperSheetPreset, 'Custom'>,
  { widthMm: number; heightMm: number; label: string }
> = {
  'A3+': { widthMm: 310, heightMm: 470, label: 'A3+ (310 × 470 mm)' },
  A4: { widthMm: 210, heightMm: 297, label: 'A4 (210 × 297 mm)' },
  F4: { widthMm: 215, heightMm: 330, label: 'F4 (215 × 330 mm)' },
};

export interface SheetGridMetrics {
  cols: number;
  rows: number;
  perPage: number;
  totalGridWidthMm: number;
  totalGridHeightMm: number;
  startXMm: number;
  startYMm: number;
  fitsOnSheet: boolean;
}

export function computeSheetGrid(template: TagLayoutTemplate): SheetGridMetrics {
  const pageW = Math.max(20, Number(template.sheet.widthMm) || 310);
  const pageH = Math.max(20, Number(template.sheet.heightMm) || 470);
  const marginX = Math.max(0, Number(template.sheet.marginXMm) || 0);
  const marginY = Math.max(0, Number(template.sheet.marginYMm) || 0);
  const gapX = Math.max(0, Number(template.sheet.gapXMm) || 0);
  const gapY = Math.max(0, Number(template.sheet.gapYMm) || 0);
  const stickerW = Math.max(5, Number(template.sticker.widthMm) || 25);
  const stickerH = Math.max(5, Number(template.sticker.heightMm) || 60);

  const usableW = Math.max(0, pageW - marginX * 2);
  const usableH = Math.max(0, pageH - marginY * 2);

  const rawCols = Math.floor((usableW + gapX + 0.001) / (stickerW + gapX));
  const rawRows = Math.floor((usableH + gapY + 0.001) / (stickerH + gapY));

  const fitsOnSheet = rawCols >= 1 && rawRows >= 1;
  const cols = Math.max(1, rawCols);
  const rows = Math.max(1, rawRows);
  const perPage = cols * rows;

  const totalGridWidthMm = cols * stickerW + (cols - 1) * gapX;
  const totalGridHeightMm = rows * stickerH + (rows - 1) * gapY;
  const startXMm = Math.max(0, (pageW - totalGridWidthMm) / 2);
  const startYMm = Math.max(0, (pageH - totalGridHeightMm) / 2);

  return {
    cols,
    rows,
    perPage,
    totalGridWidthMm,
    totalGridHeightMm,
    startXMm,
    startYMm,
    fitsOnSheet,
  };
}

export function createOfficialDefaultTemplate(): TagLayoutTemplate {
  return {
    id: 'builtin-sumas-25x60',
    name: 'Sumas Resmi (25 × 60 mm)',
    description: 'Layout standar stiker gosok 25 × 60 mm (10 × 6 = 60 label di A3+)',
    isBuiltIn: true,
    sticker: {
      widthMm: 25,
      heightMm: 60,
      cornerRadiusMm: 2.0,
      backgroundColor: '#ffffff',
      showCutLine: true,
      cutLineColor: '#dc2626',
      cutLineWidthMm: 0.18,
      backgroundImageDataUrl: null,
    },
    sheet: {
      preset: 'A3+',
      widthMm: 310,
      heightMm: 470,
      marginXMm: 14,
      marginYMm: 25,
      gapXMm: 3.0,
      gapYMm: 4.0,
      showRegistrationMarks: true,
    },
    layers: [
      {
        id: 'layer-emblem',
        type: 'emblem',
        label: 'Emblem Logo',
        visible: true,
        locked: false,
        xMm: 10.0,
        yMm: 3.5,
        widthMm: 5.0,
        heightMm: 5.0,
        rotation: 0,
        color: '#242156',
        letter: 'S',
      },
      {
        id: 'layer-brand',
        type: 'brand',
        label: 'Nama Brand',
        visible: true,
        locked: false,
        xMm: 1.0,
        yMm: 9.2,
        widthMm: 23.0,
        heightMm: 4.0,
        rotation: 0,
        fontFamily: 'times',
        fontSizePt: 5.8,
        fontWeight: 'bold',
        color: '#18181b',
        align: 'center',
        uppercase: true,
      },
      {
        id: 'layer-sub1',
        type: 'text',
        label: 'Teks Original',
        visible: true,
        locked: false,
        xMm: 1.0,
        yMm: 13.6,
        widthMm: 23.0,
        heightMm: 2.8,
        rotation: 0,
        content: 'ORIGINAL PRODUCT',
        fontFamily: 'helvetica',
        fontSizePt: 5.0,
        fontWeight: 'bold',
        color: '#0f172a',
        align: 'center',
      },
      {
        id: 'layer-sub2',
        type: 'text',
        label: 'Teks Produk Asli',
        visible: true,
        locked: false,
        xMm: 1.0,
        yMm: 16.2,
        widthMm: 23.0,
        heightMm: 2.6,
        rotation: 0,
        content: 'PRODUK ASLI',
        fontFamily: 'helvetica',
        fontSizePt: 5.0,
        fontWeight: 'bold',
        color: '#475569',
        align: 'center',
      },
      {
        id: 'layer-qr',
        type: 'qr',
        label: 'QR Code',
        visible: true,
        locked: false,
        xMm: 0.0,
        yMm: 23.0,
        widthMm: 25.0,
        heightMm: 25.0,
        rotation: 0,
        qrDarkColor: '#000000',
        qrLightColor: '#ffffff',
        qrMargin: 2,
        qrErrorCorrection: 'M',
      },
      {
        id: 'layer-pin',
        type: 'pin',
        label: 'PIN Autentikasi',
        visible: true,
        locked: false,
        xMm: 1.0,
        yMm: 48.8,
        widthMm: 23.0,
        heightMm: 10.0,
        rotation: 0,
        pinMode: '2-line',
        lineGapMm: 4.3,
        fontFamily: 'KodeMono',
        fontSizePt: 14.0,
        fontWeight: 'bold',
        color: '#0f172a',
        align: 'center',
      },
    ],
  };
}

export function createCompactWithIdTemplate(): TagLayoutTemplate {
  return {
    id: 'builtin-compact-30x40',
    name: 'Compact + ID Tag (30 × 40 mm)',
    description: 'Label kotak 30 × 40 mm dengan QR, ID Tag, dan PIN di bawah',
    isBuiltIn: true,
    sticker: {
      widthMm: 30,
      heightMm: 40,
      cornerRadiusMm: 2.0,
      backgroundColor: '#ffffff',
      showCutLine: true,
      cutLineColor: '#dc2626',
      cutLineWidthMm: 0.18,
      backgroundImageDataUrl: null,
    },
    sheet: {
      preset: 'A3+',
      widthMm: 310,
      heightMm: 470,
      marginXMm: 10,
      marginYMm: 12,
      gapXMm: 3.0,
      gapYMm: 3.0,
      showRegistrationMarks: true,
    },
    layers: [
      {
        id: 'layer-brand',
        type: 'brand',
        label: 'Nama Brand',
        visible: true,
        locked: false,
        xMm: 1.5,
        yMm: 1.8,
        widthMm: 27.0,
        heightMm: 3.5,
        rotation: 0,
        fontFamily: 'helvetica',
        fontSizePt: 6.5,
        fontWeight: 'bold',
        color: '#242156',
        align: 'center',
        uppercase: true,
      },
      {
        id: 'layer-qr',
        type: 'qr',
        label: 'QR Code',
        visible: true,
        locked: false,
        xMm: 4.0,
        yMm: 5.5,
        widthMm: 22.0,
        heightMm: 22.0,
        rotation: 0,
        qrDarkColor: '#000000',
        qrLightColor: '#ffffff',
        qrMargin: 1,
        qrErrorCorrection: 'M',
      },
      {
        id: 'layer-tagid',
        type: 'tagId',
        label: 'ID Tag',
        visible: true,
        locked: false,
        xMm: 1.5,
        yMm: 27.8,
        widthMm: 27.0,
        heightMm: 3.2,
        rotation: 0,
        prefix: 'ID: ',
        fontFamily: 'KodeMono',
        fontSizePt: 5.5,
        fontWeight: 'bold',
        color: '#475569',
        align: 'center',
      },
      {
        id: 'layer-pin-box',
        type: 'shape',
        label: 'Kotak Area PIN',
        visible: true,
        locked: false,
        xMm: 2.5,
        yMm: 31.4,
        widthMm: 25.0,
        heightMm: 6.6,
        rotation: 0,
        shapeType: 'rect',
        fillColor: '#f8fafc',
        strokeColor: '#cbd5e1',
        strokeWidthMm: 0.2,
        cornerRadiusMm: 1.0,
        transparentFill: false,
      },
      {
        id: 'layer-pin',
        type: 'pin',
        label: 'PIN Autentikasi',
        visible: true,
        locked: false,
        xMm: 2.5,
        yMm: 31.6,
        widthMm: 25.0,
        heightMm: 6.2,
        rotation: 0,
        pinMode: '1-line',
        lineGapMm: 3.6,
        fontFamily: 'KodeMono',
        fontSizePt: 10.0,
        fontWeight: 'bold',
        color: '#0f172a',
        align: 'center',
      },
    ],
  };
}

export function createHorizontalSealTemplate(): TagLayoutTemplate {
  return {
    id: 'builtin-seal-50x22',
    name: 'Segel Horizontal (50 × 22 mm)',
    description: 'Segel kemasan memanjang dengan QR di kiri, Brand, ID Tag & PIN di kanan',
    isBuiltIn: true,
    sticker: {
      widthMm: 50,
      heightMm: 22,
      cornerRadiusMm: 2.0,
      backgroundColor: '#ffffff',
      showCutLine: true,
      cutLineColor: '#dc2626',
      cutLineWidthMm: 0.18,
      backgroundImageDataUrl: null,
    },
    sheet: {
      preset: 'A3+',
      widthMm: 310,
      heightMm: 470,
      marginXMm: 10,
      marginYMm: 12,
      gapXMm: 3.0,
      gapYMm: 3.0,
      showRegistrationMarks: true,
    },
    layers: [
      {
        id: 'layer-qr',
        type: 'qr',
        label: 'QR Code',
        visible: true,
        locked: false,
        xMm: 1.5,
        yMm: 1.5,
        widthMm: 19.0,
        heightMm: 19.0,
        rotation: 0,
        qrDarkColor: '#000000',
        qrLightColor: '#ffffff',
        qrMargin: 1,
        qrErrorCorrection: 'M',
      },
      {
        id: 'layer-brand',
        type: 'brand',
        label: 'Nama Brand',
        visible: true,
        locked: false,
        xMm: 22.0,
        yMm: 2.0,
        widthMm: 26.0,
        heightMm: 4.0,
        rotation: 0,
        fontFamily: 'helvetica',
        fontSizePt: 7.0,
        fontWeight: 'bold',
        color: '#242156',
        align: 'left',
        uppercase: true,
      },
      {
        id: 'layer-tagid',
        type: 'tagId',
        label: 'ID Tag',
        visible: true,
        locked: false,
        xMm: 22.0,
        yMm: 6.2,
        widthMm: 26.0,
        heightMm: 3.5,
        rotation: 0,
        prefix: '#',
        fontFamily: 'KodeMono',
        fontSizePt: 5.5,
        fontWeight: 'bold',
        color: '#64748b',
        align: 'left',
      },
      {
        id: 'layer-pin',
        type: 'pin',
        label: 'PIN Autentikasi',
        visible: true,
        locked: false,
        xMm: 22.0,
        yMm: 10.5,
        widthMm: 26.0,
        heightMm: 9.5,
        rotation: 0,
        pinMode: '2-line',
        lineGapMm: 4.0,
        fontFamily: 'KodeMono',
        fontSizePt: 11.5,
        fontWeight: 'bold',
        color: '#0f172a',
        align: 'left',
      },
    ],
  };
}

export function getBuiltInTemplates(): TagLayoutTemplate[] {
  return [
    createOfficialDefaultTemplate(),
    createCompactWithIdTemplate(),
    createHorizontalSealTemplate(),
  ];
}

const TEMPLATES_STORAGE_KEY = 'sumas_tag_layout_templates_v1';
const SELECTED_TEMPLATE_STORAGE_KEY = 'sumas_selected_tag_template_v1';

export function cloneTemplate(tpl: TagLayoutTemplate): TagLayoutTemplate {
  return JSON.parse(JSON.stringify(tpl));
}

export function mergeServerTagTemplates(serverTemplates: TagLayoutTemplate[] | null | undefined): TagLayoutTemplate[] {
  const builtIns = getBuiltInTemplates();
  if (!Array.isArray(serverTemplates) || serverTemplates.length === 0) {
    return builtIns;
  }

  const byId = new Map<string, TagLayoutTemplate>();
  for (const item of serverTemplates) {
    if (item && typeof item.id === 'string' && item.sticker && item.sheet && Array.isArray(item.layers)) {
      byId.set(item.id, item);
    }
  }

  const merged: TagLayoutTemplate[] = builtIns.map((b) => byId.get(b.id) ?? b);
  for (const [id, item] of byId.entries()) {
    if (!builtIns.some((b) => b.id === id)) {
      merged.push(item);
    }
  }
  return merged;
}

export function loadSavedTagTemplates(): TagLayoutTemplate[] {
  const builtIns = getBuiltInTemplates();
  if (typeof window === 'undefined') return builtIns;

  try {
    const raw = window.localStorage.getItem(TEMPLATES_STORAGE_KEY);
    if (!raw) return builtIns;
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return builtIns;
    return mergeServerTagTemplates(parsed);
  } catch {
    return builtIns;
  }
}

export function saveTagTemplates(templates: TagLayoutTemplate[]): void {
  if (typeof window === 'undefined') return;
  try {
    window.localStorage.setItem(TEMPLATES_STORAGE_KEY, JSON.stringify(templates));
  } catch (err) {
    console.warn('Gagal menyimpan template tag ke localStorage:', err);
  }
}

export function getSelectedTagTemplateId(): string {
  if (typeof window === 'undefined') return 'builtin-sumas-25x60';
  try {
    return window.localStorage.getItem(SELECTED_TEMPLATE_STORAGE_KEY) || 'builtin-sumas-25x60';
  } catch {
    return 'builtin-sumas-25x60';
  }
}

export function setSelectedTagTemplateId(templateId: string): void {
  if (typeof window === 'undefined') return;
  try {
    window.localStorage.setItem(SELECTED_TEMPLATE_STORAGE_KEY, templateId);
  } catch {}
}

export function resolveDynamicText(
  rawText: string,
  context: {
    brand?: string;
    sku?: string;
    batchId?: string;
    tagNumber?: string;
    pin?: string;
  }
): string {
  return String(rawText ?? '')
    .replace(/\{brand\}/gi, context.brand || 'SUMAS')
    .replace(/\{sku\}/gi, context.sku || 'SKU-001')
    .replace(/\{batchId\}/gi, context.batchId || 'BATCH-001')
    .replace(/\{tagNumber\}/gi, context.tagNumber || 'SUM-000001')
    .replace(/\{pin\}/gi, context.pin || '01234567');
}

export function createNewLayer(
  type: TagLayerType,
  stickerW: number,
  stickerH: number
): TagLayer {
  const uid = `layer-${type}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`;
  const cx = stickerW / 2;
  const cy = stickerH / 2;

  switch (type) {
    case 'qr': {
      const size = Math.min(20, Math.max(8, Math.min(stickerW, stickerH) * 0.65));
      return {
        id: uid,
        type: 'qr',
        label: 'QR Code',
        visible: true,
        locked: false,
        xMm: Number((cx - size / 2).toFixed(1)),
        yMm: Number((cy - size / 2).toFixed(1)),
        widthMm: Number(size.toFixed(1)),
        heightMm: Number(size.toFixed(1)),
        rotation: 0,
        qrDarkColor: '#000000',
        qrLightColor: '#ffffff',
        qrMargin: 1,
        qrErrorCorrection: 'M',
      };
    }
    case 'pin':
      return {
        id: uid,
        type: 'pin',
        label: 'PIN Autentikasi',
        visible: true,
        locked: false,
        xMm: Number(Math.max(1, cx - 10).toFixed(1)),
        yMm: Number(Math.max(1, stickerH - 11).toFixed(1)),
        widthMm: Number(Math.min(stickerW - 2, 20).toFixed(1)),
        heightMm: 9,
        rotation: 0,
        pinMode: '2-line',
        lineGapMm: 4.0,
        fontFamily: 'KodeMono',
        fontSizePt: 12.0,
        fontWeight: 'bold',
        color: '#0f172a',
        align: 'center',
      };
    case 'tagId':
      return {
        id: uid,
        type: 'tagId',
        label: 'ID Tag',
        visible: true,
        locked: false,
        xMm: 1,
        yMm: Number(Math.max(1, cy - 2).toFixed(1)),
        widthMm: Number(Math.max(8, stickerW - 2).toFixed(1)),
        heightMm: 3.5,
        rotation: 0,
        prefix: 'ID: ',
        fontFamily: 'KodeMono',
        fontSizePt: 5.5,
        fontWeight: 'bold',
        color: '#475569',
        align: 'center',
      };
    case 'brand':
      return {
        id: uid,
        type: 'brand',
        label: 'Nama Brand',
        visible: true,
        locked: false,
        xMm: 1,
        yMm: 2,
        widthMm: Number(Math.max(8, stickerW - 2).toFixed(1)),
        heightMm: 4,
        rotation: 0,
        fontFamily: 'times',
        fontSizePt: 6,
        fontWeight: 'bold',
        color: '#18181b',
        align: 'center',
        uppercase: true,
      };
    case 'emblem':
      return {
        id: uid,
        type: 'emblem',
        label: 'Emblem Logo',
        visible: true,
        locked: false,
        xMm: Number(Math.max(1, cx - 2.5).toFixed(1)),
        yMm: 2,
        widthMm: 5,
        heightMm: 5,
        rotation: 0,
        color: '#242156',
        letter: 'S',
      };
    case 'shape':
      return {
        id: uid,
        type: 'shape',
        label: 'Kotak / Area',
        visible: true,
        locked: false,
        xMm: 2,
        yMm: 2,
        widthMm: Number(Math.max(6, stickerW - 4).toFixed(1)),
        heightMm: 8,
        rotation: 0,
        shapeType: 'rect',
        fillColor: '#f8fafc',
        strokeColor: '#cbd5e1',
        strokeWidthMm: 0.25,
        cornerRadiusMm: 1.0,
        transparentFill: false,
      };
    case 'text':
    default:
      return {
        id: uid,
        type: 'text',
        label: 'Teks Custom',
        visible: true,
        locked: false,
        xMm: 1,
        yMm: Number(Math.max(1, cy - 1.5).toFixed(1)),
        widthMm: Number(Math.max(8, stickerW - 2).toFixed(1)),
        heightMm: 3.5,
        rotation: 0,
        content: 'ORIGINAL PRODUCT',
        fontFamily: 'helvetica',
        fontSizePt: 5.5,
        fontWeight: 'bold',
        color: '#0f172a',
        align: 'center',
      };
  }
}
