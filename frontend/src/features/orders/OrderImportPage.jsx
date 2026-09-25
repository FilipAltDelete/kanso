import { useCallback, useMemo } from 'react';
import { useImportOrders } from '../../api/orderImports.js';
import { useI18n } from '../../lib/i18n.jsx';
import { firstTranslation } from '../../lib/translate.js';
import { CsvImportPage } from '../import/CsvImportPage.jsx';
import { useCanOperate } from './shared.jsx';

/**
 * Importing orders from a CSV file, one row per order line (ADR-0008). Rows
 * with the same order reference are one order; an order the channel already
 * has under its reference is left alone, so the same file twice creates
 * nothing the second time. Optional paymentStatus, tags (separated by |)
 * and note columns are set on the order as it is created.
 */
export function OrderImportPage() {
  const { t } = useI18n();
  const canImport = useCanOperate();
  const preview = useImportOrders();
  const run = useImportOrders();

  const errorColumns = useMemo(() => [{ accessorKey: 'reference', header: t('orderImport.reference'), cell: ({ getValue }) => getValue() ?? '' }], [t]);
  const problem = useCallback(
    (error) =>
      firstTranslation(t, [`orderImport.error.${error.field}.${error.code}`, `orderImport.error.${error.code}`, `violation.${error.code}`, `import.error.${error.code}`], error.message),
    [t],
  );
  const counts = useCallback(
    (result) => [
      { key: 'rows', label: t('import.count.rows'), value: result.rows },
      { key: 'orders', label: t('orderImport.count.orders'), value: result.orders },
      { key: 'created', label: t(result.dryRun ? 'orderImport.count.toCreate' : 'orderImport.count.created'), value: result.created, tone: 'green' },
      { key: 'existing', label: t('orderImport.count.existing'), value: result.existing },
      { key: 'failed', label: t('orderImport.count.failed'), value: result.failed, tone: result.failed > 0 ? 'red' : 'slate' },
    ],
    [t],
  );

  return (
    <CsvImportPage
      title={t('orderImport.title')}
      subtitle={t('orderImport.subtitle')}
      back={{ to: '/orders', label: t('orders.back') }}
      done={{ to: '/orders', label: t('orderImport.toOrders') }}
      canImport={canImport}
      preview={preview}
      run={run}
      counts={counts}
      changes={(result) => result.created}
      runLabel={(count) => t('orderImport.run', { count })}
      format={[t('orderImport.formatRows'), t('orderImport.formatRequired'), t('orderImport.formatOptional'), t('orderImport.formatAnnotations'), t('orderImport.formatPrices'), t('orderImport.formatAgain')]}
      template={{
        filename: 'orders.csv',
        rows: [
          ['orderReference', 'customerName', 'customerEmail', 'shippingLine1', 'shippingPostalCode', 'shippingCity', 'shippingCountry', 'sku', 'quantity', 'unitPrice', 'paymentStatus', 'tags', 'note'],
          ['WEB-1001', 'Anna Andersson', 'anna@example.com', 'Storgatan 1', '111 22', 'Stockholm', 'SE', 'TEE-BLK-M', '2', '199.00', 'paid', 'VIP|gift wrap', 'Leave at the door'],
          ['WEB-1001', '', '', '', '', '', '', 'MUG-1', '1', '89.00', '', '', ''],
        ],
      }}
      errorColumns={errorColumns}
      problem={problem}
      history="orders"
    />
  );
}
