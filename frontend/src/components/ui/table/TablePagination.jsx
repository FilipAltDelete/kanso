import { useId } from 'react';
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-react';
import { useI18n } from '../../../lib/i18n.jsx';
import { Button, Select } from '../primitives.jsx';

export function TablePagination({ table, total, pageSizes }) {
  const { t, locale } = useI18n();
  const id = useId();
  const number = new Intl.NumberFormat(locale);
  const { pageIndex, pageSize } = table.getState().pagination;
  const pages = Math.max(1, table.getPageCount());
  const from = total === 0 ? 0 : pageIndex * pageSize + 1;
  // The rows actually on the page: with server paging, the last page is often short.
  const to = total === 0 ? 0 : Math.min(total, from + table.getRowModel().rows.length - 1);

  const pageButtons = [
    { label: t('table.firstPage'), icon: ChevronsLeft, onClick: () => table.firstPage(), disabled: !table.getCanPreviousPage() },
    { label: t('table.previousPage'), icon: ChevronLeft, onClick: () => table.previousPage(), disabled: !table.getCanPreviousPage() },
    { label: t('table.nextPage'), icon: ChevronRight, onClick: () => table.nextPage(), disabled: !table.getCanNextPage() },
    { label: t('table.lastPage'), icon: ChevronsRight, onClick: () => table.lastPage(), disabled: !table.getCanNextPage() },
  ];

  return (
    <nav aria-label={t('table.pagination')} className="flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
      <p className="tabular-nums">
        {t('table.range', { from: number.format(from), to: number.format(to), total: number.format(total) })}
      </p>

      <div className="flex flex-wrap items-center gap-3">
        <div className="flex items-center gap-2">
          <label htmlFor={id}>{t('table.pageSize')}</label>
          <Select id={id} className="h-8" value={pageSize} onChange={(event) => table.setPageSize(Number(event.target.value))}>
            {pageSizes.map((size) => (
              <option key={size} value={size}>
                {number.format(size)}
              </option>
            ))}
          </Select>
        </div>

        <p className="tabular-nums">{t('table.page', { page: number.format(pageIndex + 1), pages: number.format(pages) })}</p>

        <div className="flex gap-1">
          {pageButtons.map(({ label, icon: Icon, onClick, disabled }) => (
            <Button key={label} variant="outline" size="icon" className="size-8" aria-label={label} title={label} onClick={onClick} disabled={disabled}>
              <Icon className="size-4" aria-hidden="true" />
            </Button>
          ))}
        </div>
      </div>
    </nav>
  );
}
