import { jsPDF } from 'jspdf';
import QRCode from 'qrcode';
import { KODE_MONO_BOLD_BASE64 } from './kodeMonoFont';
import {
  computeSheetGrid,
  createOfficialDefaultTemplate,
  resolveDynamicText,
  type TagFontFamily,
  type TagLayer,
  type TagLayoutTemplate,
} from './tagLayoutTemplate';

export interface TagItem {
  id: string;
  batchId: string;
  tagNumber: string;
  pin: string;
  status?: string;
  project?: string;
}

export interface BatchItem {
  id: string;
  batchId: string;
  date: string;
  sku: string;
  brand: string;
  qty: number;
  qrType?: string;
  redirectUrl?: string;
}

/**
 * Menyimpan file menggunakan native file picker OS (showSaveFilePicker)
 * sehingga pengguna dapat memilih lokasi folder dan memberi nama file secara bebas.
 * Jika browser atau iframe tidak mendukung, otomatis fallback ke download biasa.
 */
export async function saveFileWithNativePicker(
  blob: Blob,
  suggestedName: string,
  mimeType: string,
  extension: string
): Promise<{ success: boolean; fileName: string; method: 'picker' | 'download'; aborted?: boolean }> {
  if (typeof window !== 'undefined' && 'showSaveFilePicker' in window) {
    try {
      const handle = await (window as any).showSaveFilePicker({
        suggestedName,
        types: [
          {
            description: `${extension.toUpperCase()} File (*.${extension})`,
            accept: {
              [mimeType]: [`.${extension}`],
            },
          },
        ],
      });
      const writable = await handle.createWritable();
      await writable.write(blob);
      await writable.close();
      return { success: true, fileName: handle.name, method: 'picker' };
    } catch (err: any) {
      if (err.name === 'AbortError') {
        return { success: false, fileName: suggestedName, method: 'picker', aborted: true };
      }
      console.warn('Native showSaveFilePicker tidak tersedia atau dibatasi, menggunakan fallback:', err);
    }
  }

  // Fallback: Standar tag <a> download
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = suggestedName;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  setTimeout(() => URL.revokeObjectURL(url), 2000);
  return { success: true, fileName: suggestedName, method: 'download' };
}

function hexToRgb(hex: string | undefined, fallback: [number, number, number] = [0, 0, 0]): [number, number, number] {
  if (!hex || typeof hex !== 'string') return fallback;
  const cleaned = hex.trim().replace(/^#/, '');
  if (cleaned.length === 3) {
    const r = parseInt(cleaned[0] + cleaned[0], 16);
    const g = parseInt(cleaned[1] + cleaned[1], 16);
    const b = parseInt(cleaned[2] + cleaned[2], 16);
    if (!Number.isNaN(r) && !Number.isNaN(g) && !Number.isNaN(b)) return [r, g, b];
  } else if (cleaned.length === 6) {
    const r = parseInt(cleaned.slice(0, 2), 16);
    const g = parseInt(cleaned.slice(2, 4), 16);
    const b = parseInt(cleaned.slice(4, 6), 16);
    if (!Number.isNaN(r) && !Number.isNaN(g) && !Number.isNaN(b)) return [r, g, b];
  }
  return fallback;
}

function rotatePointCW(px: number, py: number, cx: number, cy: number, deg: number): { x: number; y: number } {
  if (!deg) return { x: px, y: py };
  const rad = (deg * Math.PI) / 180;
  const cos = Math.cos(rad);
  const sin = Math.sin(rad);
  const dx = px - cx;
  const dy = py - cy;
  return {
    x: cx + dx * cos - dy * sin,
    y: cy + dx * sin + dy * cos,
  };
}

function setDocFont(doc: jsPDF, family: TagFontFamily, weight: 'bold' | 'normal'): void {
  if (family === 'KodeMono') {
    try {
      doc.setFont('KodeMono', 'bold');
      return;
    } catch {
      doc.setFont('courier', weight);
      return;
    }
  }
  doc.setFont(family, weight);
}

/**
 * Menghasilkan image data URL untuk QR Code menggunakan qrcode library
 */
export async function createQrCodeDataUrl(
  payload: string,
  options?: {
    darkColor?: string;
    lightColor?: string;
    margin?: number;
    errorCorrectionLevel?: 'L' | 'M' | 'Q' | 'H';
  }
): Promise<string> {
  try {
    return await QRCode.toDataURL(payload, {
      width: 400,
      margin: options?.margin ?? 2,
      errorCorrectionLevel: options?.errorCorrectionLevel ?? 'M',
      color: {
        dark: options?.darkColor || '#000000',
        light: options?.lightColor || '#ffffff',
      },
    });
  } catch (err) {
    console.error('Gagal generate QR Code:', err);
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
  }
}

/**
 * Menentukan isi payload QR Code berdasarkan tipe batch (Statis / Dinamis)
 */
export function getQrCodePayload(batch: BatchItem, tag: TagItem): string {
  const base = (batch.redirectUrl || 'https://cek.sumas.id/').trim();
  if (batch.qrType === 'static') {
    return base;
  }
  const cleanBase = base.replace(/\/+$/, '');
  const separator = cleanBase.includes('?') ? '&' : '/?';
  return `${cleanBase}${separator}id=${encodeURIComponent(tag.tagNumber)}&pin=${encodeURIComponent(tag.pin)}`;
}

function detectImageFormat(dataUrl: string): 'PNG' | 'JPEG' | 'WEBP' {
  const lower = dataUrl.slice(0, 40).toLowerCase();
  if (lower.includes('image/jpeg') || lower.includes('image/jpg')) return 'JPEG';
  if (lower.includes('image/webp')) return 'WEBP';
  return 'PNG';
}

function drawTextLayerInPdf(
  doc: jsPDF,
  text: string,
  layerX: number,
  layerY: number,
  layerW: number,
  layerH: number,
  rotation: number,
  align: 'left' | 'center' | 'right',
  fontFamily: TagFontFamily,
  fontWeight: 'bold' | 'normal',
  fontSizePt: number,
  colorHex: string,
  yOffsetMm = 0
): void {
  if (!text) return;
  setDocFont(doc, fontFamily, fontWeight);
  doc.setFontSize(Math.max(2, fontSizePt));
  const [r, g, b] = hexToRgb(colorHex, [15, 23, 42]);
  doc.setTextColor(r, g, b);

  const cx = layerX + layerW / 2;
  const cy = layerY + layerH / 2;
  const anchorX =
    align === 'left' ? layerX : align === 'right' ? layerX + layerW : cx;
  const anchorY = cy + yOffsetMm;
  const pos = rotatePointCW(anchorX, anchorY, cx, cy, rotation);

  doc.text(text, pos.x, pos.y, {
    align,
    baseline: 'middle',
    ...(rotation ? { angle: -rotation } : {}),
  });
}

function renderLayerInPdf(
  doc: jsPDF,
  layer: TagLayer,
  stickerX: number,
  stickerY: number,
  batch: BatchItem,
  tag: TagItem,
  qrDataUrl?: string
): void {
  if (!layer.visible) return;

  const lx = stickerX + layer.xMm;
  const ly = stickerY + layer.yMm;
  const lw = Math.max(0.5, layer.widthMm);
  const lh = Math.max(0.5, layer.heightMm);
  const rot = layer.rotation || 0;

  switch (layer.type) {
    case 'shape': {
      const [fr, fg, fb] = hexToRgb(layer.fillColor, [248, 250, 252]);
      const [sr, sg, sb] = hexToRgb(layer.strokeColor, [203, 213, 225]);
      const strokeW = Math.max(0, layer.strokeWidthMm || 0);

      if (layer.shapeType === 'line') {
        doc.setDrawColor(sr, sg, sb);
        doc.setLineWidth(Math.max(0.1, strokeW || 0.25));
        const cx = lx + lw / 2;
        const cy = ly + lh / 2;
        const p1 = rotatePointCW(lx, cy, cx, cy, rot);
        const p2 = rotatePointCW(lx + lw, cy, cx, cy, rot);
        doc.line(p1.x, p1.y, p2.x, p2.y);
      } else {
        const hasFill = !layer.transparentFill;
        const hasStroke = strokeW > 0;
        const style = hasFill && hasStroke ? 'FD' : hasFill ? 'F' : hasStroke ? 'S' : null;
        if (!style) break;
        if (hasFill) doc.setFillColor(fr, fg, fb);
        if (hasStroke) {
          doc.setDrawColor(sr, sg, sb);
          doc.setLineWidth(strokeW);
        }
        const radius = Math.min(layer.cornerRadiusMm || 0, Math.min(lw, lh) / 2);
        if (radius > 0) {
          doc.roundedRect(lx, ly, lw, lh, radius, radius, style);
        } else {
          doc.rect(lx, ly, lw, lh, style);
        }
      }
      break;
    }

    case 'emblem': {
      const [er, eg, eb] = hexToRgb(layer.color, [36, 33, 86]);
      const cx = lx + lw / 2;
      const cy = ly + lh / 2;
      const r = Math.min(lw, lh) / 2;
      doc.setDrawColor(er, eg, eb);
      doc.setLineWidth(Math.max(0.15, r * 0.1));
      doc.circle(cx, cy, r);

      const letter = (layer.letter || 'S').slice(0, 2);
      const fontPt = Math.max(3, r * 1.75);
      doc.setFont('times', 'bold');
      doc.setFontSize(fontPt);
      doc.setTextColor(er, eg, eb);
      doc.text(letter, cx, cy, {
        align: 'center',
        baseline: 'middle',
        ...(rot ? { angle: -rot } : {}),
      });
      break;
    }

    case 'brand': {
      const rawBrand = String(batch.brand || 'SUMAS').slice(0, 24);
      const brandText = layer.uppercase ? rawBrand.toUpperCase() : rawBrand;
      drawTextLayerInPdf(
        doc,
        brandText,
        lx,
        ly,
        lw,
        lh,
        rot,
        layer.align,
        layer.fontFamily,
        layer.fontWeight,
        layer.fontSizePt,
        layer.color
      );
      break;
    }

    case 'tagId': {
      const text = `${layer.prefix ?? ''}${tag.tagNumber || ''}`;
      drawTextLayerInPdf(
        doc,
        text,
        lx,
        ly,
        lw,
        lh,
        rot,
        layer.align,
        layer.fontFamily,
        layer.fontWeight,
        layer.fontSizePt,
        layer.color
      );
      break;
    }

    case 'text': {
      const resolved = resolveDynamicText(layer.content, {
        brand: batch.brand,
        sku: batch.sku,
        batchId: batch.batchId,
        tagNumber: tag.tagNumber,
        pin: tag.pin,
      });
      drawTextLayerInPdf(
        doc,
        resolved,
        lx,
        ly,
        lw,
        lh,
        rot,
        layer.align,
        layer.fontFamily,
        layer.fontWeight,
        layer.fontSizePt,
        layer.color
      );
      break;
    }

    case 'pin': {
      const rawPin = String(tag.pin || '00000000').replace(/\s+/g, '');
      const half = Math.ceil(rawPin.length / 2);
      const isTwoLines = layer.pinMode === '2-line' && rawPin.length > 4;

      if (isTwoLines) {
        const part1 = rawPin.slice(0, half);
        const part2 = rawPin.slice(half);
        const gap = Math.max(1.5, layer.lineGapMm || 4.0);
        drawTextLayerInPdf(
          doc,
          part1,
          lx,
          ly,
          lw,
          lh,
          rot,
          layer.align,
          layer.fontFamily,
          layer.fontWeight,
          layer.fontSizePt,
          layer.color,
          -gap / 2
        );
        drawTextLayerInPdf(
          doc,
          part2,
          lx,
          ly,
          lw,
          lh,
          rot,
          layer.align,
          layer.fontFamily,
          layer.fontWeight,
          layer.fontSizePt,
          layer.color,
          gap / 2
        );
      } else {
        drawTextLayerInPdf(
          doc,
          rawPin,
          lx,
          ly,
          lw,
          lh,
          rot,
          layer.align,
          layer.fontFamily,
          layer.fontWeight,
          layer.fontSizePt,
          layer.color
        );
      }
      break;
    }

    case 'qr': {
      if (!qrDataUrl) break;
      doc.addImage(qrDataUrl, 'PNG', lx, ly, lw, lh, undefined, 'FAST');
      break;
    }
  }
}

/**
 * Render PDF Layout Cetak Stiker berdasarkan Template Custom (ukuran kertas, ukuran stiker, & posisi elemen bebas dalam mm)
 */
export async function generateCustomStickersPdf(
  batch: BatchItem,
  tags: TagItem[],
  template: TagLayoutTemplate,
  onProgress?: (current: number, total: number) => void
): Promise<Blob> {
  const PAGE_WIDTH = Math.max(20, Number(template.sheet.widthMm) || 310);
  const PAGE_HEIGHT = Math.max(20, Number(template.sheet.heightMm) || 470);
  const STICKER_WIDTH = Math.max(5, Number(template.sticker.widthMm) || 25);
  const STICKER_HEIGHT = Math.max(5, Number(template.sticker.heightMm) || 60);
  const CORNER_RADIUS = Math.min(
    Math.max(0, Number(template.sticker.cornerRadiusMm) || 0),
    Math.min(STICKER_WIDTH, STICKER_HEIGHT) / 2
  );

  const doc = new jsPDF({
    orientation: PAGE_WIDTH > PAGE_HEIGHT ? 'landscape' : 'portrait',
    unit: 'mm',
    format: [PAGE_WIDTH, PAGE_HEIGHT],
  });

  // Register Kode Mono Bold TrueType Font
  try {
    doc.addFileToVFS('KodeMono-Bold.ttf', KODE_MONO_BOLD_BASE64);
    doc.addFont('KodeMono-Bold.ttf', 'KodeMono', 'bold');
  } catch (err) {
    console.warn('Could not register KodeMono font in jsPDF:', err);
  }

  const grid = computeSheetGrid(template);
  const STICKERS_PER_PAGE = grid.perPage;
  const totalPages = Math.max(1, Math.ceil(tags.length / STICKERS_PER_PAGE));

  const qrLayers = template.layers.filter((l) => l.type === 'qr' && l.visible);
  const qrCacheByLayer = new Map<string, Map<string, string>>();

  for (const qrLayer of qrLayers) {
    if (qrLayer.type !== 'qr') continue;
    const layerMap = new Map<string, string>();
    for (let idx = 0; idx < tags.length; idx++) {
      const t = tags[idx];
      const payload = getQrCodePayload(batch, t);
      const dataUrl = await createQrCodeDataUrl(payload, {
        darkColor: qrLayer.qrDarkColor,
        lightColor: qrLayer.qrLightColor,
        margin: qrLayer.qrMargin,
        errorCorrectionLevel: qrLayer.qrErrorCorrection,
      });
      layerMap.set(t.tagNumber, dataUrl);
      if (onProgress && idx % 20 === 0) {
        onProgress(idx + 1, tags.length);
      }
    }
    qrCacheByLayer.set(qrLayer.id, layerMap);
  }

  const bgImageDataUrl = template.sticker.backgroundImageDataUrl || null;
  const bgImageFormat = bgImageDataUrl ? detectImageFormat(bgImageDataUrl) : 'PNG';
  const [bgR, bgG, bgB] = hexToRgb(template.sticker.backgroundColor, [255, 255, 255]);
  const [cutR, cutG, cutB] = hexToRgb(template.sticker.cutLineColor, [220, 38, 38]);

  for (let pageIdx = 0; pageIdx < totalPages; pageIdx++) {
    if (pageIdx > 0) {
      doc.addPage([PAGE_WIDTH, PAGE_HEIGHT], PAGE_WIDTH > PAGE_HEIGHT ? 'landscape' : 'portrait');
    }

    const pageTags = tags.slice(pageIdx * STICKERS_PER_PAGE, (pageIdx + 1) * STICKERS_PER_PAGE);

    // 1. Tanda Registrasi Percetakan di 4 sudut lembar
    if (template.sheet.showRegistrationMarks) {
      doc.setDrawColor(160, 165, 175);
      doc.setLineWidth(0.25);
      const inset = Math.min(8, Math.max(4, Math.min(PAGE_WIDTH, PAGE_HEIGHT) * 0.04));
      const markLen = Math.min(7, inset - 1);
      const corners = [
        { x: inset, y: inset },
        { x: PAGE_WIDTH - inset, y: inset },
        { x: inset, y: PAGE_HEIGHT - inset },
        { x: PAGE_WIDTH - inset, y: PAGE_HEIGHT - inset },
      ];
      corners.forEach((c) => {
        doc.line(c.x - markLen, c.y, c.x + markLen, c.y);
        doc.line(c.x, c.y - markLen, c.x, c.y + markLen);
        doc.circle(c.x, c.y, 2.2);
      });
    }

    // 2. Render tiap stiker sesuai grid
    for (let i = 0; i < pageTags.length; i++) {
      const tag = pageTags[i];
      const col = i % grid.cols;
      const row = Math.floor(i / grid.cols);

      const x = grid.startXMm + col * (STICKER_WIDTH + template.sheet.gapXMm);
      const y = grid.startYMm + row * (STICKER_HEIGHT + template.sheet.gapYMm);

      // Background warna stiker
      doc.setFillColor(bgR, bgG, bgB);
      if (CORNER_RADIUS > 0) {
        doc.roundedRect(x, y, STICKER_WIDTH, STICKER_HEIGHT, CORNER_RADIUS, CORNER_RADIUS, 'F');
      } else {
        doc.rect(x, y, STICKER_WIDTH, STICKER_HEIGHT, 'F');
      }

      // Optional uploaded artwork background
      if (bgImageDataUrl) {
        try {
          doc.addImage(bgImageDataUrl, bgImageFormat, x, y, STICKER_WIDTH, STICKER_HEIGHT, 'sticker-bg', 'FAST');
        } catch {
          // Abaikan jika image artwork gagal disisipkan
        }
      }

      // Render semua layer dari bawah ke atas
      for (const layer of template.layers) {
        const qrDataUrl =
          layer.type === 'qr' ? qrCacheByLayer.get(layer.id)?.get(tag.tagNumber) : undefined;
        renderLayerInPdf(doc, layer, x, y, batch, tag, qrDataUrl);
      }

      // Garis potong die-cut
      if (template.sticker.showCutLine) {
        doc.setDrawColor(cutR, cutG, cutB);
        doc.setLineWidth(Math.max(0.08, template.sticker.cutLineWidthMm || 0.18));
        if (CORNER_RADIUS > 0) {
          doc.roundedRect(x, y, STICKER_WIDTH, STICKER_HEIGHT, CORNER_RADIUS, CORNER_RADIUS, 'S');
        } else {
          doc.rect(x, y, STICKER_WIDTH, STICKER_HEIGHT, 'S');
        }
      }

      if (onProgress) {
        const overallIdx = pageIdx * STICKERS_PER_PAGE + i + 1;
        onProgress(overallIdx, tags.length);
      }
    }
  }

  return doc.output('blob');
}

/**
 * Render PDF Layout Cetak Stiker Gosok pada format default A3+ (310 x 470 mm)
 */
export async function generateA3PlusStickersPdf(
  batch: BatchItem,
  tags: TagItem[],
  onProgress?: (current: number, total: number) => void
): Promise<Blob> {
  return generateCustomStickersPdf(batch, tags, createOfficialDefaultTemplate(), onProgress);
}

/**
 * Sanitasi sel CSV untuk mencegah CSV / Excel Formula Injection (DDE / =, +, -, @, TAB, CR)
 * serta meng-escape tanda kutip ganda sesuai RFC 4180.
 */
function sanitizeCsvCell(value: unknown): string {
  const raw = String(value ?? '').replace(/\r?\n/g, ' ');
  const trimmed = raw.trim();
  const needsFormulaGuard =
    trimmed.length > 0 &&
    trimmed !== '-' &&
    /^[=+\-@\t\r]/.test(raw);
  const safe = needsFormulaGuard ? `'${raw}` : raw;
  return `"${safe.replace(/"/g, '""')}"`;
}

/**
 * Generate File CSV Tabular untuk ekspor Excel
 * Menyertakan PIN utuh, Baris 1 (digit atas), dan Baris 2 (digit bawah jika ada)
 */
export function generateTagsCsv(batch: BatchItem, tags: TagItem[]): Blob {
  const headers = [
    'No',
    'Batch ID',
    'Nomor Tag',
    'PIN',
    'PIN Baris 1 (Atas)',
    'PIN Baris 2 (Bawah)',
    'Status Tag',
    'Proyek',
    'Brand',
    'Produk SKU',
    'Tipe QR',
    'URL Verifikasi',
  ];

  const rows = tags.map((t, idx) => {
    const rawPin = String(t.pin || '').replace(/\s+/g, '');
    const half = Math.ceil(rawPin.length / 2);
    const p1 = rawPin.length > 4 ? rawPin.slice(0, half) : rawPin;
    const p2 = rawPin.length > 4 ? rawPin.slice(half) : '';
    return [
      idx + 1,
      sanitizeCsvCell(batch.batchId),
      sanitizeCsvCell(t.tagNumber),
      sanitizeCsvCell(rawPin),
      sanitizeCsvCell(p1),
      sanitizeCsvCell(p2),
      sanitizeCsvCell(t.status || 'Tersedia'),
      sanitizeCsvCell(t.project || '-'),
      sanitizeCsvCell(batch.brand),
      sanitizeCsvCell(batch.sku),
      sanitizeCsvCell(batch.qrType === 'static' ? 'QR Statis' : 'QR Dinamis'),
      sanitizeCsvCell(batch.redirectUrl || 'https://cek.sumas.id/'),
    ];
  });

  const csvContent = '\uFEFF' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\r\n');
  return new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
}
