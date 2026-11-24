import React, { useEffect, useMemo, useState } from 'react';
import {
  AlertTriangle,
  Box,
  Building2,
  ChevronLeft,
  ChevronRight,
  FolderTree,
  Info,
  Layers,
  LayoutTemplate,
  Loader2,
  RotateCcw,
  Search,
  Trash2,
  User,
  X,
} from 'lucide-react';
import { apiFetch } from '../utils/authSession';
import { CustomCheckbox } from './CustomCheckbox';
import { CustomSelect } from './CustomSelect';
import { TableEmptyRow } from './TableEmptyState';
import { NewBadge } from './NewBadge';

export type TrashEntityType = 'brand' | 'product' | 'category' | 'batch' | 'user' | 'tag_layout';

export interface TrashItem {
  id: string;
  entityType: TrashEntityType;
  entityId: string;
  title: string;
  subtitle?: string | null;
  brandName?: string | null;
  brandId?: string | null;
  deletedById?: string | null;
  deletedByName?: string | null;
  deletedAt: string;
  deletedAtTs: number;
  expiresAtTs: number;
  daysRemaining: number;
  itemCount: number;
}

export interface TrashCounts {
  all: number;
  brand: number;
  product: number;
  category: number;
  batch: number;
  user: number;
  tag_layout: number;
}

interface TrashManagerProps {
  onAlert?: (title: string, message: string, type?: 'success' | 'warning' | 'error' | 'info') => void;
  onRestored?: (restoredTypes: TrashEntityType[]) => void;
  onCountChanged?: (count: number) => void;
}

const TYPE_META: Record<
  TrashEntityType,
  { label: string; shortLabel: string; icon: React.FC<{ size?: number; className?: string }>; pillClass: string }
> = {
  brand: {
    label: 'Brand / Merk',
    shortLabel: 'Brand',
    icon: Building2,
    pillClass: 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800/60',
  },
  category: {
    label: 'Kategori Produk',
    shortLabel: 'Kategori',
    icon: FolderTree,
    pillClass: 'bg-violet-50 dark:bg-violet-950/40 text-violet-700 dark:text-violet-300 border-violet-200 dark:border-violet-800/60',
  },
  product: {
    label: 'Produk SKU',
    shortLabel: 'SKU',
    icon: Box,
    pillClass: 'bg-sky-50 dark:bg-sky-950/40 text-sky-700 dark:text-sky-300 border-sky-200 dark:border-sky-800/60',
  },
  batch: {
    label: 'Batch & Tag QR',
    shortLabel: 'Batch',
    icon: Layers,
    pillClass: 'bg-[#c28e62]/10 text-[#a67346] dark:text-[#d4a379] border-[#c28e62]/30',
  },
  user: {
    label: 'Akun Pengguna',
    shortLabel: 'Pengguna',
    icon: User,
    pillClass: 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 border-indigo-200 dark:border-indigo-800/60',
  },
  tag_layout: {
    label: 'Layout Tag',
    shortLabel: 'Layout',
    icon: LayoutTemplate,
    pillClass: 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800/60',
  },
};

const formatTrashDate = (iso?: string, ts?: number): string => {
  const dt = ts ? new Date(ts) : iso ? new Date(iso) : null;
  if (!dt || isNaN(dt.getTime())) return iso || '-';
  return dt.toLocaleString('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};

export const TrashManager: React.FC<TrashManagerProps> = ({
  onAlert,
  onRestored,
  onCountChanged,
}) => {
  const [items, setItems] = useState<TrashItem[]>([]);
  const [counts, setCounts] = useState<TrashCounts>({
    all: 0,
    brand: 0,
    product: 0,
    category: 0,
    batch: 0,
    user: 0,
    tag_layout: 0,
  });
  const [retentionDays, setRetentionDays] = useState<number>(30);
  const [typeFilter, setTypeFilter] = useState<'all' | TrashEntityType>('all');
  const [sortBy, setSortBy] = useState<'newest' | 'expiring' | 'title-asc'>('newest');
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [currentPage, setCurrentPage] = useState<number>(1);
  const [perPage] = useState<number>(15);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [isBulkBusy, setIsBulkBusy] = useState<boolean>(false);

  const [confirmModal, setConfirmModal] = useState<
    | { kind: 'single'; item: TrashItem }
    | { kind: 'bulk'; ids: string[] }
    | { kind: 'empty' }
    | null
  >(null);

  const [, setLiveTick] = useState<number>(() => Date.now());
  useEffect(() => {
    const timer = setInterval(() => setLiveTick(Date.now()), 15000);
    return () => clearInterval(timer);
  }, []);

  const fetchTrash = async (silent = false) => {
    try {
      if (!silent) setIsLoading(true);
      const res = await apiFetch('/api/trash');
      if (!res.ok) {
        throw new Error('Gagal memuat data Tempat Sampah.');
      }
      const data = await res.json();
      const loadedItems = Array.isArray(data?.items) ? (data.items as TrashItem[]) : [];
      const nextCounts: TrashCounts = data?.counts || {
        all: loadedItems.length,
        brand: 0,
        product: 0,
        category: 0,
        batch: 0,
        user: 0,
        tag_layout: 0,
      };
      setItems(loadedItems);
      setCounts(nextCounts);
      if (typeof data?.retentionDays === 'number') {
        setRetentionDays(data.retentionDays);
      }
      onCountChanged?.(nextCounts.all ?? loadedItems.length);
      setSelectedIds((prev) => prev.filter((id) => loadedItems.some((item) => item.id === id)));
    } catch (err: any) {
      onAlert?.('Gagal Memuat Tempat Sampah', err?.message || 'Terjadi kesalahan saat membaca Tempat Sampah.', 'error');
    } finally {
      if (!silent) setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchTrash();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const filteredItems = useMemo(() => {
    const q = searchQuery.trim().toLowerCase();
    const list = items.filter((item) => {
      if (typeFilter !== 'all' && item.entityType !== typeFilter) return false;
      if (!q) return true;
      const haystack = [
        item.title,
        item.subtitle,
        item.brandName,
        item.deletedByName,
        item.entityId,
        TYPE_META[item.entityType]?.label,
      ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();
      return haystack.includes(q);
    });

    return [...list].sort((a, b) => {
      if (sortBy === 'expiring') {
        return (a.expiresAtTs || 0) - (b.expiresAtTs || 0);
      }
      if (sortBy === 'title-asc') {
        return (a.title || '').localeCompare(b.title || '', 'id');
      }
      return (b.deletedAtTs || 0) - (a.deletedAtTs || 0);
    });
  }, [items, typeFilter, searchQuery, sortBy]);

  const totalPages = Math.max(1, Math.ceil(filteredItems.length / perPage));
  const safePage = Math.min(currentPage, totalPages);
  const paginatedItems = useMemo(() => {
    const start = (safePage - 1) * perPage;
    return filteredItems.slice(start, start + perPage);
  }, [filteredItems, safePage, perPage]);

  const allVisibleSelected =
    paginatedItems.length > 0 && paginatedItems.every((item) => selectedIds.includes(item.id));

  const toggleSelectAll = () => {
    if (allVisibleSelected) {
      const visibleSet = new Set(paginatedItems.map((i) => i.id));
      setSelectedIds((prev) => prev.filter((id) => !visibleSet.has(id)));
    } else {
      const merged = new Set([...selectedIds, ...paginatedItems.map((i) => i.id)]);
      setSelectedIds(Array.from(merged));
    }
  };

  const toggleSelectOne = (id: string) => {
    setSelectedIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  };

  const handleRestoreOne = async (item: TrashItem) => {
    try {
      setBusyId(item.id);
      const res = await apiFetch(`/api/trash/${encodeURIComponent(item.id)}/restore`, {
        method: 'POST',
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data?.error || 'Gagal memulihkan item dari Tempat Sampah.');
      }
      onAlert?.(
        'Item Dipulihkan',
        data?.message || `"${item.title}" berhasil dikembalikan ke daftar aktif.`,
        'success'
      );
      onRestored?.([item.entityType]);
      await fetchTrash(true);
    } catch (err: any) {
      onAlert?.('Gagal Memulihkan Item', err?.message || 'Terjadi kendala saat memulihkan item.', 'error');
    } finally {
      setBusyId(null);
    }
  };

  const handleBulkRestore = async () => {
    if (selectedIds.length === 0) return;
    try {
      setIsBulkBusy(true);
      const res = await apiFetch('/api/trash/bulk-restore', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids: selectedIds }),
      });
      const data = await res.json().catch(() => ({}));
      const restoredIds: string[] = Array.isArray(data?.restoredIds) ? data.restoredIds : [];
      const restoredTypes: TrashEntityType[] = Array.isArray(data?.restoredTypes) ? data.restoredTypes : [];
      const failed: Array<{ title?: string; error?: string }> = Array.isArray(data?.failed) ? data.failed : [];

      if (restoredIds.length > 0) {
        onRestored?.(restoredTypes);
      }

      if (failed.length > 0 && restoredIds.length === 0) {
        onAlert?.('Gagal Memulihkan', failed[0]?.error || 'Tidak ada item yang dapat dipulihkan.', 'error');
      } else if (failed.length > 0) {
        onAlert?.(
          'Sebagian Item Dipulihkan',
          `${restoredIds.length} item berhasil dipulihkan, ${failed.length} item gagal (${failed[0]?.error || 'konflik data'}).`,
          'warning'
        );
      } else {
        onAlert?.(
          'Berhasil Dipulihkan',
          `${restoredIds.length} item berhasil dikembalikan ke daftar aktif.`,
          'success'
        );
      }
      setSelectedIds([]);
      await fetchTrash(true);
    } catch (err: any) {
      onAlert?.('Gagal Memulihkan', err?.message || 'Terjadi kesalahan sistem.', 'error');
    } finally {
      setIsBulkBusy(false);
    }
  };

  const executeConfirmedDelete = async () => {
    if (!confirmModal) return;
    try {
      setIsBulkBusy(true);
      if (confirmModal.kind === 'single') {
        const target = confirmModal.item;
        const res = await apiFetch(`/api/trash/${encodeURIComponent(target.id)}`, {
          method: 'DELETE',
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
          throw new Error(data?.error || 'Gagal menghapus item secara permanen.');
        }
        onAlert?.(
          'Dihapus Permanen',
          `"${target.title}" telah dihapus permanen dan tidak dapat dikembalikan.`,
          'success'
        );
      } else if (confirmModal.kind === 'bulk') {
        const idsToDelete = confirmModal.ids;
        const res = await apiFetch('/api/trash/bulk-delete', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ids: idsToDelete }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
          throw new Error(data?.error || 'Gagal menghapus item terpilih.');
        }
        setSelectedIds([]);
        onAlert?.(
          'Dihapus Permanen',
          `${data?.deletedCount ?? idsToDelete.length} item berhasil dihapus permanen.`,
          'success'
        );
      } else if (confirmModal.kind === 'empty') {
        const query = typeFilter !== 'all' ? `?type=${encodeURIComponent(typeFilter)}` : '';
        const res = await apiFetch(`/api/trash${query}`, { method: 'DELETE' });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
          throw new Error(data?.error || 'Gagal mengosongkan Tempat Sampah.');
        }
        setSelectedIds([]);
        onAlert?.(
          'Tempat Sampah Dikosongkan',
          `${data?.deletedCount ?? 0} item dihapus secara permanen.`,
          'success'
        );
      }
      setConfirmModal(null);
      await fetchTrash(true);
    } catch (err: any) {
      onAlert?.('Gagal Menghapus Permanen', err?.message || 'Terjadi kesalahan saat menghapus permanen.', 'error');
    } finally {
      setIsBulkBusy(false);
    }
  };

  const typeOptions = [
    { value: 'all', label: 'Semua Jenis', badge: String(counts.all) },
    { value: 'brand', label: 'Brand / Merk', badge: String(counts.brand) },
    { value: 'category', label: 'Kategori Produk', badge: String(counts.category) },
    { value: 'product', label: 'Produk SKU', badge: String(counts.product) },
    { value: 'batch', label: 'Batch & Tag QR', badge: String(counts.batch) },
    { value: 'user', label: 'Akun Pengguna', badge: String(counts.user) },
    { value: 'tag_layout', label: 'Layout Tag', badge: String(counts.tag_layout) },
  ];

  const isFiltered = Boolean(searchQuery || typeFilter !== 'all' || sortBy !== 'newest');

  const getExpiryPill = (daysRemaining: number) => {
    if (daysRemaining <= 2) {
      return 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 border-rose-200 dark:border-rose-800/60';
    }
    if (daysRemaining <= 7) {
      return 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800/60';
    }
    return 'bg-slate-100 dark:bg-zinc-800 text-slate-600 dark:text-zinc-300 border-slate-200 dark:border-zinc-700';
  };

  return (
    <div className="flex-1 overflow-y-auto p-3.5 sm:p-5 md:p-6 lg:p-7 xl:p-8 pb-16 sm:pb-24 animate-in fade-in duration-300">
      <div className="w-full max-w-[1720px] mx-auto space-y-5 sm:space-y-6 pb-8 sm:pb-12">
        {/* TOP STATUS BAR (SERAGAM DENGAN HALAMAN LAIN) */}
        <div className="bg-white dark:bg-zinc-900 border border-slate-200 dark:border-zinc-700/80 rounded-2xl px-5 py-3.5 min-h-[62px] flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-xs">
          <div className="flex items-center gap-2.5">
            <div className="w-8 h-8 rounded-lg bg-[#c28e62]/10 text-[#c28e62] flex items-center justify-center shrink-0">
              <Trash2 size={16} />
            </div>
            <div>
              <h2 className="text-sm sm:text-base font-bold tracking-tight text-slate-800 dark:text-zinc-100 leading-snug">
                Tempat Sampah
              </h2>
            </div>
          </div>

          <div className="grid grid-cols-2 sm:flex items-center gap-2.5 w-full sm:w-auto">
            <button
              type="button"
              onClick={() => fetchTrash()}
              disabled={isLoading}
              className="w-full sm:w-auto h-10 px-4 sm:px-5 rounded-full border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-slate-700 dark:text-zinc-200 hover:bg-slate-50 dark:hover:bg-zinc-700 disabled:opacity-50 disabled:cursor-not-allowed text-xs sm:text-sm font-semibold inline-flex items-center justify-center transition-all cursor-pointer whitespace-nowrap active:scale-95"
            >
              <span>{isLoading ? 'Menyinkronkan...' : 'Segarkan'}</span>
            </button>
            <button
              type="button"
              disabled={(typeFilter === 'all' ? counts.all : (counts[typeFilter] ?? 0)) === 0 || isLoading || isBulkBusy}
              onClick={() => setConfirmModal({ kind: 'empty' })}
              className="w-full sm:w-auto h-10 px-4 sm:px-5 rounded-full bg-rose-600 hover:bg-rose-700 disabled:opacity-45 disabled:cursor-not-allowed text-white text-xs sm:text-sm font-semibold inline-flex items-center justify-center gap-1.5 transition-all cursor-pointer whitespace-nowrap active:scale-95"
            >
              <Trash2 size={14} />
              <span>Kosongkan</span>
            </button>
          </div>
        </div>

        {/* CARD UTAMA: RETENTION STRIP + SEARCH/FILTER + TABEL + PAGINATION */}
        <div className="bg-white dark:bg-zinc-900 border border-slate-200 dark:border-zinc-700 rounded-2xl overflow-hidden shadow-xs">
          {/* Strip Retensi 30 Hari ala Google Drive */}
          <div className="px-4 sm:px-5 py-2.5 bg-slate-50/80 dark:bg-zinc-800/50 border-b border-slate-200 dark:border-zinc-800 flex items-center justify-between gap-3 text-xs text-slate-600 dark:text-zinc-300">
            <div className="flex items-center gap-2 min-w-0">
              <Info size={14} className="text-[#c28e62] shrink-0" />
              <span className="truncate sm:whitespace-normal">
                Item di Tempat Sampah akan dihapus permanen secara otomatis setelah <strong>{retentionDays} hari</strong>.
              </span>
            </div>
            <span className="text-[11px] font-semibold text-slate-500 dark:text-zinc-400 tabular-nums shrink-0">
              {counts.all} item tersimpan
            </span>
          </div>

          {/* Search & Dropdown Filters (1 Baris Rapi di Desktop) */}
          <div className="p-4 sm:p-5 border-b border-slate-200 dark:border-zinc-800 flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3">
            <div className="relative flex-1 min-w-0">
              <Search size={15} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-zinc-500" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => {
                  setSearchQuery(e.target.value);
                  setCurrentPage(1);
                }}
                placeholder="Cari nama item, kode SKU, batch, brand, atau penghapus..."
                className="w-full bg-slate-50 dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 rounded-full pl-9 pr-9 py-2 text-xs sm:text-sm text-slate-800 dark:text-zinc-200 placeholder-slate-400 dark:placeholder-zinc-500 focus:outline-none focus:border-[#c28e62] transition-colors"
              />
              {searchQuery && (
                <button
                  type="button"
                  onClick={() => {
                    setSearchQuery('');
                    setCurrentPage(1);
                  }}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-zinc-200 cursor-pointer"
                >
                  <X size={14} />
                </button>
              )}
            </div>

            <div className="flex flex-wrap items-center gap-2.5">
              <div className="w-full sm:w-auto min-w-[165px]">
                <CustomSelect
                  value={typeFilter}
                  onChange={(val) => {
                    setTypeFilter(val as 'all' | TrashEntityType);
                    setCurrentPage(1);
                  }}
                  placeholder="Semua Jenis"
                  searchable={false}
                  buttonClassName="w-full bg-slate-50 dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 hover:border-[#c28e62] rounded-full px-3.5 py-2 text-xs text-slate-700 dark:text-zinc-200 cursor-pointer flex justify-between items-center gap-2 transition-colors select-none"
                  options={typeOptions}
                />
              </div>

              <div className="w-full sm:w-auto min-w-[165px]">
                <CustomSelect
                  value={sortBy}
                  onChange={(val) => {
                    setSortBy(val as 'newest' | 'expiring' | 'title-asc');
                    setCurrentPage(1);
                  }}
                  placeholder="Urutkan"
                  searchable={false}
                  buttonClassName="w-full bg-slate-50 dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 hover:border-[#c28e62] rounded-full px-3.5 py-2 text-xs text-slate-700 dark:text-zinc-200 cursor-pointer flex justify-between items-center gap-2 transition-colors select-none"
                  options={[
                    { value: 'newest', label: 'Baru Dihapus' },
                    { value: 'expiring', label: 'Segera Kedaluwarsa' },
                    { value: 'title-asc', label: 'Nama Item (A - Z)' },
                  ]}
                />
              </div>
            </div>
          </div>

          {/* Bulk Action Bar (Muncul hanya saat ada item tercentang) */}
          {selectedIds.length > 0 && (
            <div className="px-4 sm:px-5 py-2.5 bg-[#fcf7f3] dark:bg-zinc-800/80 border-b border-[#c28e62]/25 flex flex-wrap items-center justify-between gap-2.5">
              <span className="text-xs font-bold text-[#a67346] dark:text-[#d4a379]">
                {selectedIds.length} item dipilih
              </span>
              <div className="flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  disabled={isBulkBusy}
                  onClick={handleBulkRestore}
                  className="h-8 px-3.5 rounded-full bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold inline-flex items-center gap-1.5 cursor-pointer disabled:opacity-50 transition-colors"
                >
                  <RotateCcw size={12} />
                  <span>Pulihkan Terpilih</span>
                </button>
                <button
                  type="button"
                  disabled={isBulkBusy}
                  onClick={() => setConfirmModal({ kind: 'bulk', ids: selectedIds })}
                  className="h-8 px-3.5 rounded-full bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold inline-flex items-center gap-1.5 cursor-pointer disabled:opacity-50 transition-colors"
                >
                  <Trash2 size={12} />
                  <span>Hapus Permanen</span>
                </button>
                <button
                  type="button"
                  disabled={isBulkBusy}
                  onClick={() => setSelectedIds([])}
                  className="h-8 px-3 rounded-full text-xs font-semibold text-slate-500 dark:text-zinc-400 hover:text-slate-800 dark:hover:text-zinc-200 cursor-pointer"
                >
                  Batal
                </button>
              </div>
            </div>
          )}

          {/* Filter Reset Info Strip */}
          {isFiltered && (
            <div className="px-5 py-2.5 bg-slate-50/60 dark:bg-zinc-800/40 border-b border-slate-100 dark:border-zinc-800 flex items-center justify-between text-xs">
              <span className="text-slate-500 dark:text-zinc-400">
                Menampilkan hasil filter: <strong className="text-slate-800 dark:text-zinc-200">{filteredItems.length}</strong> item
                {searchQuery && <span> untuk "{searchQuery}"</span>}
              </span>
              <button
                type="button"
                onClick={() => {
                  setSearchQuery('');
                  setTypeFilter('all');
                  setSortBy('newest');
                  setCurrentPage(1);
                }}
                className="text-xs text-[#c28e62] hover:underline font-semibold cursor-pointer"
              >
                Reset Filter
              </button>
            </div>
          )}

          {/* CONTENT TABLE */}
          {isLoading ? (
            <div className="py-16 flex flex-col items-center justify-center gap-2 text-slate-400 dark:text-zinc-500">
              <Loader2 size={24} className="animate-spin text-[#c28e62]" />
              <span className="text-xs sm:text-sm font-medium">Memuat item Tempat Sampah...</span>
            </div>
          ) : (
            <div className="overflow-x-auto scrollbar-thin">
              <table className="w-full text-left text-xs sm:text-sm whitespace-nowrap min-w-[820px]">
                <thead className="bg-slate-50 dark:bg-zinc-800 border-b border-slate-200 dark:border-zinc-700 font-semibold text-slate-500 dark:text-zinc-400 uppercase tracking-wider text-[11px] select-none">
                  <tr>
                    <th className="pl-5 pr-2 py-3.5 w-10">
                      <CustomCheckbox
                        checked={allVisibleSelected}
                        indeterminate={!allVisibleSelected && paginatedItems.some((item) => selectedIds.includes(item.id))}
                        onChange={toggleSelectAll}
                        ariaLabel="Pilih semua item di halaman ini"
                      />
                    </th>
                    <th className="px-4 py-3.5">Nama Item</th>
                    <th className="px-4 py-3.5">Jenis</th>
                    <th className="px-4 py-3.5">Brand</th>
                    <th className="px-4 py-3.5">Dihapus</th>
                    <th className="px-4 py-3.5 text-center">Kedaluwarsa</th>
                    <th className="px-5 py-3.5 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-zinc-800 text-slate-700 dark:text-zinc-300">
                  {paginatedItems.length > 0 ? (
                    paginatedItems.map((item) => {
                      const meta = TYPE_META[item.entityType] || TYPE_META.product;
                      const IconComp = meta.icon;
                      const isChecked = selectedIds.includes(item.id);
                      const isBusy = busyId === item.id;

                      return (
                        <tr
                          key={item.id}
                          className={`transition-colors ${
                            isChecked
                              ? 'bg-[#c28e62]/[0.06] dark:bg-[#c28e62]/[0.08]'
                              : 'hover:bg-slate-50/80 dark:hover:bg-zinc-800/50'
                          }`}
                        >
                          <td className="pl-5 pr-2 py-3.5 align-middle">
                            <CustomCheckbox
                              checked={isChecked}
                              onChange={() => toggleSelectOne(item.id)}
                              ariaLabel={`Pilih ${item.title}`}
                            />
                          </td>

                          {/* KOLOM 1: ICON + NAMA ITEM + SUBTITLE */}
                          <td className="px-4 py-3.5">
                            <div className="flex items-center gap-3 min-w-0">
                              <div className="w-9 h-9 rounded-xl bg-slate-100 dark:bg-zinc-800 border border-slate-200 dark:border-zinc-700 flex items-center justify-center text-[#c28e62] shrink-0">
                                <IconComp size={16} />
                              </div>
                              <div className="min-w-0 max-w-[300px]">
                                <div className="flex items-center gap-2">
                                  <span
                                    className="font-bold text-slate-800 dark:text-zinc-100 text-xs sm:text-sm truncate"
                                    title={item.title}
                                  >
                                    {item.title}
                                  </span>
                                  <NewBadge date={item.deletedAt} size="xs" />
                                </div>
                                <div className="text-[11px] text-slate-400 dark:text-zinc-500 mt-0.5 truncate">
                                  {item.subtitle || `ID: ${item.entityId}`}
                                  {item.itemCount > 1 && ` · ${item.itemCount.toLocaleString('id-ID')} item`}
                                </div>
                              </div>
                            </div>
                          </td>

                          {/* KOLOM 2: JENIS ITEM */}
                          <td className="px-4 py-3.5">
                            <span
                              className={`inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold border ${meta.pillClass}`}
                            >
                              {meta.shortLabel}
                            </span>
                          </td>

                          {/* KOLOM 3: BRAND */}
                          <td className="px-4 py-3.5">
                            {item.brandName ? (
                              <span className="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-slate-100 dark:bg-zinc-800 text-slate-700 dark:text-zinc-300 border border-slate-200 dark:border-zinc-700">
                                {item.brandName}
                              </span>
                            ) : (
                              <span className="text-xs text-slate-400 dark:text-zinc-500">Global</span>
                            )}
                          </td>

                          {/* KOLOM 4: DIHAPUS OLEH & WAKTU */}
                          <td className="px-4 py-3.5">
                            <div className="font-semibold text-slate-700 dark:text-zinc-200 text-xs truncate max-w-[160px]">
                              {item.deletedByName || 'Sistem'}
                            </div>
                            <div className="text-[11px] text-slate-400 dark:text-zinc-500 mt-0.5">
                              {formatTrashDate(item.deletedAt, item.deletedAtTs)}
                            </div>
                          </td>

                          {/* KOLOM 5: SISA MASA SIMPAN */}
                          <td className="px-4 py-3.5 text-center">
                            <span
                              className={`inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold border tabular-nums ${getExpiryPill(
                                item.daysRemaining
                              )}`}
                            >
                              {item.daysRemaining <= 1 ? '< 1 hari lagi' : `${item.daysRemaining} hari lagi`}
                            </span>
                          </td>

                          {/* KOLOM 6: AKSI */}
                          <td className="px-5 py-3.5 text-right">
                            <div className="inline-flex items-center justify-end gap-1.5">
                              <button
                                type="button"
                                disabled={isBusy || isBulkBusy}
                                onClick={() => handleRestoreOne(item)}
                                title="Pulihkan ke lokasi asal"
                                className="h-8 px-3 rounded-full bg-emerald-50 dark:bg-emerald-950/40 hover:bg-emerald-600 text-emerald-700 dark:text-emerald-300 hover:text-white border border-emerald-200 dark:border-emerald-800/60 text-xs font-semibold inline-flex items-center gap-1.5 transition-colors cursor-pointer disabled:opacity-50"
                              >
                                {isBusy ? <Loader2 size={12} className="animate-spin" /> : <RotateCcw size={12} />}
                                <span>Pulihkan</span>
                              </button>
                              <button
                                type="button"
                                disabled={isBusy || isBulkBusy}
                                onClick={() => setConfirmModal({ kind: 'single', item })}
                                data-tooltip="Hapus Selamanya"
                                data-tooltip-pos="left"
                                aria-label={`Hapus permanen ${item.title}`}
                                className="p-2 rounded-full text-slate-400 dark:text-zinc-500 hover:text-rose-600 dark:hover:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/40 inline-flex items-center justify-center transition-colors cursor-pointer disabled:opacity-50"
                              >
                                <Trash2 size={15} />
                              </button>
                            </div>
                          </td>
                        </tr>
                      );
                    })
                  ) : (
                    <TableEmptyRow
                      colSpan={7}
                      icon={<Trash2 size={24} className="stroke-[1.75]" />}
                      title={isFiltered ? 'Tidak Ada Item yang Cocok' : 'Tempat Sampah Kosong'}
                      description={
                        isFiltered
                          ? 'Coba ubah kata kunci pencarian atau pilih jenis item lain.'
                          : `Data yang dihapus akan disimpan di sini selama ${retentionDays} hari sebelum dihapus permanen.`
                      }
                      isFiltered={isFiltered}
                      onResetFilter={() => {
                        setSearchQuery('');
                        setTypeFilter('all');
                        setSortBy('newest');
                        setCurrentPage(1);
                      }}
                    />
                  )}
                </tbody>
              </table>
            </div>
          )}

          {/* PAGINATION FOOTER (KONSISTEN DENGAN TABEL LAIN) */}
          {!isLoading && filteredItems.length > 0 && (
            <div className="px-5 py-3.5 border-t border-slate-100 dark:border-zinc-800 bg-slate-50/50 dark:bg-zinc-800/30 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-500 dark:text-zinc-400">
              <span>
                Menampilkan{' '}
                <strong className="text-slate-700 dark:text-zinc-200">
                  {(safePage - 1) * perPage + 1}–{Math.min(safePage * perPage, filteredItems.length)}
                </strong>{' '}
                dari <strong className="text-slate-700 dark:text-zinc-200">{filteredItems.length}</strong> item
              </span>
              {totalPages > 1 && (
                <div className="flex items-center gap-1.5">
                  <button
                    type="button"
                    disabled={safePage <= 1}
                    onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                    className="h-8 px-3 rounded-full border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 hover:bg-slate-50 dark:hover:bg-zinc-700 disabled:opacity-45 disabled:cursor-not-allowed text-slate-700 dark:text-zinc-200 inline-flex items-center gap-1 cursor-pointer transition-colors"
                  >
                    <ChevronLeft size={14} />
                    <span>Sebelumnya</span>
                  </button>
                  <span className="px-2.5 font-semibold text-slate-700 dark:text-zinc-200 tabular-nums">
                    {safePage} / {totalPages}
                  </span>
                  <button
                    type="button"
                    disabled={safePage >= totalPages}
                    onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                    className="h-8 px-3 rounded-full border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 hover:bg-slate-50 dark:hover:bg-zinc-700 disabled:opacity-45 disabled:cursor-not-allowed text-slate-700 dark:text-zinc-200 inline-flex items-center gap-1 cursor-pointer transition-colors"
                  >
                    <span>Berikutnya</span>
                    <ChevronRight size={14} />
                  </button>
                </div>
              )}
            </div>
          )}
        </div>
      </div>

      {/* MODAL KONFIRMASI HAPUS PERMANEN / KOSONGKAN TEMPAT SAMPAH */}
      {confirmModal && (
        <div
          onClick={() => {
            if (!isBulkBusy) setConfirmModal(null);
          }}
          className="fixed inset-0 bg-black/60 z-[10010] flex items-center justify-center p-4 backdrop-blur-sm animate-in fade-in duration-200"
        >
          <div
            onClick={(e) => e.stopPropagation()}
            className="bg-white dark:bg-zinc-900 rounded-2xl border border-slate-200/90 dark:border-zinc-700/80 w-full max-w-sm overflow-hidden p-6 mx-4 text-center shadow-2xl animate-in zoom-in-95 duration-200"
          >
            <div className="w-12 h-12 rounded-full bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center mx-auto mb-4">
              <AlertTriangle size={24} />
            </div>
            <h3 className="text-base font-bold tracking-tight text-slate-800 dark:text-zinc-100 leading-snug mb-2">
              {confirmModal.kind === 'empty'
                ? typeFilter === 'all'
                  ? 'Kosongkan Tempat Sampah?'
                  : `Kosongkan ${TYPE_META[typeFilter]?.label || 'Filter'} di Tempat Sampah?`
                : confirmModal.kind === 'bulk'
                  ? `Hapus Permanen ${confirmModal.ids.length} Item?`
                  : 'Hapus Permanen Item Ini?'}
            </h3>
            <p className="text-xs sm:text-sm text-slate-500 dark:text-zinc-400 mb-6 leading-relaxed">
              {confirmModal.kind === 'empty'
                ? typeFilter === 'all'
                  ? 'Seluruh item di Tempat Sampah akan dihapus selamanya dan tidak dapat dipulihkan.'
                  : `Seluruh item jenis "${TYPE_META[typeFilter]?.label || typeFilter}" di Tempat Sampah akan dihapus selamanya dan tidak dapat dipulihkan.`
                : confirmModal.kind === 'bulk'
                  ? `${confirmModal.ids.length} item terpilih akan dihapus selamanya dan tidak dapat dipulihkan.`
                  : `"${confirmModal.item.title}" akan dihapus selamanya dan tidak dapat dipulihkan.`}
            </p>
            <div className="flex items-center justify-center gap-3">
              <button
                type="button"
                disabled={isBulkBusy}
                onClick={() => setConfirmModal(null)}
                className="flex-1 h-10 px-5 rounded-full border border-slate-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 hover:bg-slate-50 dark:hover:bg-zinc-700 text-slate-700 dark:text-zinc-200 text-xs sm:text-sm font-semibold transition-colors cursor-pointer disabled:opacity-50"
              >
                Batal
              </button>
              <button
                type="button"
                disabled={isBulkBusy}
                onClick={executeConfirmedDelete}
                className="flex-1 h-10 px-5 rounded-full bg-rose-600 hover:bg-rose-700 text-white text-xs sm:text-sm font-semibold inline-flex items-center justify-center gap-1.5 transition-colors cursor-pointer disabled:opacity-50"
              >
                {isBulkBusy && <Loader2 size={14} className="animate-spin" />}
                <span>Hapus Selamanya</span>
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
