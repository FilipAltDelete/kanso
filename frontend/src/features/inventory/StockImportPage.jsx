import { useCallback, useMemo } from 'react';
import { useImportStock } from '../../api/inventory.js';
import { useI18n } from '../../lib/i18n.jsx';
import { firstTranslation } from '../../lib/translate.js';
import { CsvImportPage } from '../import/CsvImportPage.jsx';
import { useCanEditCatalog } from './catalogForm.js';

/**
 * Importing counted stock quantities from a CSV file, one row per SKU and
 * location (ADR-0016): the first stock load of a new installation, or a
 * stocktake. Each row is saved as a count adjustment; a row already equal to
 * on hand is left alone, so the same file twice changes nothing.
 */
export function StockImportPage() {
  const { t } = useI18n();
  const canImport = useCanEditCatalog();
  const preview = useImportStock();
  const run = useImportStock();

  const errorColumns = useMemo(
    () => [
      { accessorKey: 'sku', header: t('product.sku'), cell: ({ getValue }) => getValue() ?? '' },
      { accessorKey: 'location', header: t('stockImport.location'), cell: ({ getValue }) => getValue() ?? '' },
    ],
    [t],
  );
  const problem = useCallback(
    (error) => firstTranslation(t, [`stockImport.error.${error.field}.${error.code}`, `stockImport.error.${error.code}`, `import.error.${error.code}`], error.message),
    [t],
  );
  const counts = useCallback(
    (result) => [
      { key: 'rows', label: t('import.count.rows'), value: result.rows },
      { key: 'changed', label: t(result.dryRun ? 'stockImport.will.changed' : 'stockImport.did.changed'), value: result.changed, tone: 'green' },
      { key: 'unchanged', label: t(result.dryRun ? 'import.will.unchanged' : 'import.did.unchanged'), value: result.unchanged },
      { key: 'failed', label: t('import.count.failed'), value: result.failed, tone: result.failed > 0 ? 'red' : 'slate' },
    ],
    [t],
  );

  return (
    <CsvImportPage
      title={t('stockImport.title')}
      subtitle={t('stockImport.subtitle')}
      back={{ to: '/products', label: t('products.back') }}
      done={{ to: '/products', label: t('import.toProducts') }}
      canImport={canImport}
      notAllowed={t('stockImport.notAllowed')}
      preview={preview}
      run={run}
      counts={counts}
      changes={(result) => result.changed}
      runLabel={(count) => t('stockImport.run', { count })}
      format={[t('stockImport.formatColumns'), t('stockImport.formatCount'), t('stockImport.formatAgain')]}
      template={{
        filename: 'stock.csv',
        rows: [
          ['sku', 'location', 'quantity'],
          ['TEE-BLK-M', 'WH1', '120'],
        ],
      }}
      errorColumns={errorColumns}
      problem={problem}
      history="stock"
    />
  );
}
