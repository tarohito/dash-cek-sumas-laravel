import React from 'react';

const BULAN_MAP: Record<string, number> = {
  jan: 0,
  feb: 1,
  mar: 2,
  apr: 3,
  mei: 4,
  may: 4,
  jun: 5,
  jul: 6,
  agu: 7,
  agt: 7,
  aug: 7,
  sep: 8,
  okt: 9,
  oct: 9,
  nov: 10,
  des: 11,
  dec: 11,
};

export const parseTimestampFromId = (idVal?: string | number | null): Date | null => {
  if (idVal === undefined || idVal === null) return null;
  if (typeof idVal === 'number') {
    if (Number.isFinite(idVal) && idVal >= 1577836800000 && idVal <= 4102444800000) {
      const dt = new Date(idVal);
      return isNaN(dt.getTime()) ? null : dt;
    }
    return null;
  }
  const str = String(idVal).trim();
  if (!str) return null;
  // Supports e.g. b1732421100000, br-1732421100000, cat-1732421100000-a3f1, 1732421100000
  const match = str.match(/^(?:[a-zA-Z_-]+)?(\d{12,14})(?:-[a-zA-Z0-9]+)?$/);
  if (!match) return null;
  const ms = Number(match[1]);
  if (!Number.isFinite(ms) || ms < 1577836800000 || ms > 4102444800000) return null;
  const dt = new Date(ms);
  return isNaN(dt.getTime()) ? null : dt;
};

export const parseFlexibleDate = (
  dateVal?: string | number | null,
  idVal?: string | number | null,
  opts?: { requireTime?: boolean }
): Date | null => {
  const requireTime = Boolean(opts?.requireTime);

  if (typeof dateVal === 'number' && Number.isFinite(dateVal)) {
    const ms = dateVal < 1e11 ? dateVal * 1000 : dateVal;
    const d = new Date(ms);
    if (!isNaN(d.getTime())) return d;
  }

  const str = dateVal !== undefined && dateVal !== null ? String(dateVal).trim() : '';

  if (str) {
    // Numeric epoch ms in string form
    if (/^\d{12,14}$/.test(str)) {
      const d = new Date(Number(str));
      if (!isNaN(d.getTime())) return d;
    }

    // ISO datetime with explicit time component (e.g. 2026-11-24T03:45:00Z or 2026-11-24 03:45:00)
    const hasExplicitTime = /[T\s]\d{1,2}[:.]\d{2}/.test(str);
    if (hasExplicitTime && str.includes('-')) {
      const isoCandidate = str.includes('T') ? str : str.replace(' ', 'T');
      const direct = new Date(isoCandidate);
      if (!isNaN(direct.getTime())) return direct;
    }

    // Indonesian format: "28 Okt 2026, 09:15" / "24 Nov 2026, 11.05 WIB" / "4 Nov 2026"
    const parts = str.replace(/,/g, '').split(/\s+/);
    if (parts.length >= 3) {
      const day = parseInt(parts[0], 10);
      const monthKey = parts[1].substring(0, 3).toLowerCase();
      const year = parseInt(parts[2], 10);
      if (!isNaN(day) && !isNaN(year) && year >= 1970 && BULAN_MAP[monthKey] !== undefined) {
        const hasClock = Boolean(parts[3] && /^\d{1,2}[:.]\d{2}/.test(parts[3]));
        if (hasClock) {
          const timeParts = parts[3].split(/[:.]/);
          const hours = parseInt(timeParts[0], 10) || 0;
          const minutes = parseInt(timeParts[1], 10) || 0;
          const tzToken = (parts[4] || '').toUpperCase();
          if (tzToken === 'WIB') {
            return new Date(Date.UTC(year, BULAN_MAP[monthKey], day, hours - 7, minutes));
          }
          if (tzToken === 'WITA') {
            return new Date(Date.UTC(year, BULAN_MAP[monthKey], day, hours - 8, minutes));
          }
          if (tzToken === 'WIT') {
            return new Date(Date.UTC(year, BULAN_MAP[monthKey], day, hours - 9, minutes));
          }
          return new Date(year, BULAN_MAP[monthKey], day, hours, minutes);
        }

        // Date-only Indonesian string (no clock) -> check ID timestamp first
        const idDt = parseTimestampFromId(idVal);
        if (idDt) return idDt;
        if (!requireTime) {
          return new Date(year, BULAN_MAP[monthKey], day);
        }
        return null;
      }
    }

    // Date-only ISO string (YYYY-MM-DD) -> prefer timestamp ID if present
    if (/^\d{4}-\d{2}-\d{2}$/.test(str)) {
      const idDt = parseTimestampFromId(idVal);
      if (idDt) return idDt;
      if (!requireTime) {
        const direct = new Date(str);
        if (!isNaN(direct.getTime())) return direct;
      }
      return null;
    }

    if (hasExplicitTime) {
      const direct = new Date(str);
      if (!isNaN(direct.getTime())) return direct;
    }
  }

  // Fallback to timestamp embedded in ID
  const idDt = parseTimestampFromId(idVal);
  if (idDt) return idDt;

  if (!requireTime && str) {
    const direct = new Date(str);
    if (!isNaN(direct.getTime())) return direct;
  }

  return null;
};

// Data baru dianggap "Baru" selama maxMinutes (default 5 menit), setelah itu badge otomatis hilang
export const isRecentlyCreated = (
  createdAt?: string | number | null,
  idVal?: string | number | null,
  maxMinutes = 5
): boolean => {
  if ((createdAt === undefined || createdAt === null || createdAt === '') && !idVal) {
    return false;
  }

  if (typeof createdAt === 'string') {
    const lower = createdAt.trim().toLowerCase();
    if (lower) {
      if (lower.includes('baru saja') || lower.includes('just now') || lower.includes('< 1 mnt')) {
        return true;
      }
      if (/\d+\s*(?:detik|dtk|s)\s*(?:yang\s*)?lalu/.test(lower)) {
        return true;
      }
      const minMatch = lower.match(/(\d+)\s*(?:menit|mnt|m)\s*(?:yang\s*)?lalu/);
      if (minMatch) {
        const mins = parseInt(minMatch[1], 10);
        return !isNaN(mins) && mins < maxMinutes;
      }
      if (/\d+\s*(?:jam|j|hari|d|minggu|bulan|tahun)\s*(?:yang\s*)?lalu/.test(lower)) {
        // Only fall back to ID if relative string says hours/days? No, definitely older than 5 min.
        return false;
      }
    }
  }

  const dt = parseFlexibleDate(createdAt, idVal, { requireTime: true });
  if (!dt) return false;

  const diffMs = Date.now() - dt.getTime();
  return diffMs >= -60000 && diffMs <= maxMinutes * 60 * 1000;
};

export const getRelativeCreatedTime = (
  createdAt?: string | number | null,
  idVal?: string | number | null
): string => {
  if (!createdAt && !idVal) return 'baru saja';
  const lower = String(createdAt || '').toLowerCase();
  if (lower.includes('baru saja')) return 'baru saja';
  if (lower.includes('menit yang lalu')) return lower;

  const dt = parseFlexibleDate(createdAt, idVal, { requireTime: true });
  if (!dt) return 'baru saja';

  const diffSeconds = Math.max(0, Math.floor((Date.now() - dt.getTime()) / 1000));
  if (diffSeconds < 60) return '< 1 mnt';
  const diffMinutes = Math.floor(diffSeconds / 60);
  return `${diffMinutes} mnt`;
};

export const formatDateTimeDisplay = (
  dateVal?: string | null,
  idVal?: string | null
): string => {
  if (!dateVal && !idVal) return 'Katalog Master';
  const dt = parseFlexibleDate(dateVal, idVal);
  if (!dt) return String(dateVal || 'Katalog Master');
  return dt.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
};

interface NewBadgeProps {
  date?: string | number | null;
  id?: string | number | null;
  label?: string;
  className?: string;
  maxMinutes?: number;
  size?: 'xs' | 'sm';
  force?: boolean;
}

export const NewBadge: React.FC<NewBadgeProps> = ({
  date,
  id,
  label = 'Baru',
  className = '',
  maxMinutes = 5,
  size = 'sm',
  force = false,
}) => {
  if (!force && !isRecentlyCreated(date, id, maxMinutes)) return null;

  const sizeClass =
    size === 'xs'
      ? 'px-1.5 py-0.5 text-[9px] gap-1'
      : 'px-2 py-0.5 text-[10px] gap-1.5';

  return (
    <span
      className={`inline-flex items-center rounded-full font-extrabold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400 border border-emerald-300 dark:border-emerald-700/80 tracking-wider uppercase select-none transition-all shrink-0 ${sizeClass} ${className}`}
    >
      <span className="relative flex h-1.5 w-1.5 shrink-0">
        <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
        <span className="relative inline-flex rounded-full h-1.5 w-1.5 bg-emerald-500"></span>
      </span>
      <span>{label}</span>
    </span>
  );
};
