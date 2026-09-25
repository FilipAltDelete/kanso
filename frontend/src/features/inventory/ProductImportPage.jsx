import { useCallback, useMemo } from 'react';
import { useImportProducts } from '../../api/inventory.js';
import { useI18n } from '../../lib/i18n.jsx';
import { formatQuantity } from '../../lib/quantity.js';
import { firstTranslation } from '../../lib/translate.js';
import { CsvImportPage } from '../import/CsvImportPage.jsx';
import { MAX_WEIGHT_GRAMS, useCanEditCatalog } from './catalogForm.js';

/**
 * Importing products from a CSV file (ADR-0006). Products are matched by
 * SKU, so running the same file again changes nothing.
 */
export function ProductImportPage() {
  const { t, locale } = useI18n();
  const canImport = useCanEditCatalog();
  const preview = useImportProducts();
  const run = useImportProducts();

  const errorColumns = useMemo(() => [{ accessorKey: 'sku', header: t('product.sku'), cell: ({ getValue }) => getValue() ?? '' }], [t]);
  const problem = useCallback(
    (error) => firstTranslation(t, [`catalog.violation.${error.field}.${error.code}`, `import.error.${error.code}`], error.message, { max: formatQuantity(MAX_WEIGHT_GRAMS, locale) }),
    [t, locale],
  );

  return (
    <CsvImportPage
      title={t('import.title')}
      subtitle={t('import.subtitle')}
      back={{ to: '/products', label: t('products.back') }}
      done={{ to: '/products', label: t('import.toProducts') }}
      canImport={canImport}
      preview={preview}
      run={run}
      counts={(result) => {
        const prefix = result.dryRun ? 'import.will' : 'import.did';

        return [
          { key: 'rows', label: t('import.count.rows'), value: result.rows },
          { key: 'created', label: t(`${prefix}.created`), value: result.created, tone: 'green' },
          { key: 'updated', label: t(`${prefix}.updated`), value: result.updated, tone: 'green' },
          { key: 'unchanged', label: t(`${prefix}.unchanged`), value: result.unchanged },
          { key: 'failed', label: t('import.count.failed'), value: result.failed, tone: result.failed > 0 ? 'red' : 'slate' },
        ];
      }}
      changes={(result) => result.created + result.updated}
      runLabel={(count) => t('import.run', { count })}
      format={[t('import.formatColumns'), t('import.formatMatching'), t('import.formatBlank')]}
      template={{
        filename: 'products.csv',
        rows: [
          ['sku', 'name', 'barcode', 'weightGrams'],
          ['TEE-BLK-M', t('import.templateExample'), '7350000000001', '180'],
        ],
      }}
      errorColumns={errorColumns}
      problem={problem}
    />
  );
}
