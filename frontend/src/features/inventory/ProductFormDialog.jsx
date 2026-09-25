import { useRef, useState } from 'react';
import { useCreateProduct, useUpdateProduct } from '../../api/inventory.js';
import { Button, Dialog, ErrorNotice, Field, Input } from '../../components/ui/primitives.jsx';
import { useI18n } from '../../lib/i18n.jsx';
import { formatQuantity } from '../../lib/quantity.js';
import { BARCODE_PATTERN, blankToNull, MAX_WEIGHT_GRAMS, parseGrams, serverFieldErrors, SKU_PATTERN } from './catalogForm.js';

const valuesOf = (product) => ({
  sku: product?.sku ?? '',
  name: product?.name ?? '',
  barcode: product?.barcode ?? '',
  weight: product?.weightGrams === null || product?.weightGrams === undefined ? '' : String(product.weightGrams),
});

/**
 * Creating a product, or editing one when `product` is given. The SKU is
 * fixed once created (order lines and stock refer to it), so an edit shows
 * it but does not send it.
 *
 * An edit sends the version it was based on. When someone else saved first
 * the API answers 409, the product is refetched, and the form reloads with
 * their values so nothing is overwritten unseen.
 */
export function ProductFormDialog({ product = null, onClose, onSaved }) {
  const { t, locale } = useI18n();
  const dialogRef = useRef(null);
  const editing = product !== null;
  const create = useCreateProduct();
  const update = useUpdateProduct(product?.id);
  const mutation = editing ? update : create;

  const [values, setValues] = useState(() => valuesOf(product));
  const [touched, setTouched] = useState(false);
  const [reloaded, setReloaded] = useState(false);
  const [seenVersion, setSeenVersion] = useState(product?.version);

  // After a 409 the product is refetched; show what is saved now.
  if (editing && product.version !== seenVersion) {
    setSeenVersion(product.version);
    if (update.error?.status === 409) {
      setValues(valuesOf(product));
      setReloaded(true);
    }
  }

  const weightGrams = parseGrams(values.weight);
  const limits = { max: formatQuantity(MAX_WEIGHT_GRAMS, locale) };
  const problems = {
    sku: editing || SKU_PATTERN.test(values.sku.trim()) ? null : t('catalog.violation.sku.format'),
    name: values.name.trim() === '' ? t('catalog.violation.name.required') : values.name.trim().length > 255 ? t('catalog.violation.name.too_long') : null,
    barcode: values.barcode.trim() === '' || BARCODE_PATTERN.test(values.barcode.trim()) ? null : t('catalog.violation.barcode.format'),
    weightGrams:
      Number.isNaN(weightGrams) || (weightGrams !== null && weightGrams > MAX_WEIGHT_GRAMS)
        ? t('catalog.violation.weightGrams.range', limits)
        : null,
  };
  const valid = Object.values(problems).every((problem) => problem === null);
  const errors = { ...serverFieldErrors(mutation.error, t, limits), ...(touched ? Object.fromEntries(Object.entries(problems).filter(([, problem]) => problem)) : {}) };
  const conflict = mutation.error?.status === 409;

  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }));

  function submit(event) {
    event.preventDefault();
    setTouched(true);
    setReloaded(false);
    if (!valid || mutation.isPending) return;

    const fields = { name: values.name.trim(), barcode: blankToNull(values.barcode), weightGrams };
    const body = editing ? { ...fields, version: product.version } : { sku: values.sku.trim(), ...fields };
    mutation.mutate(body, {
      onSuccess: (saved) => {
        dialogRef.current?.close();
        onSaved?.(saved);
      },
    });
  }

  return (
    <Dialog
      dialogRef={dialogRef}
      title={t(editing ? 'productForm.editTitle' : 'productForm.newTitle')}
      description={editing ? product.sku : t('productForm.newSubtitle')}
      onClose={onClose}
    >
      <form onSubmit={submit} noValidate className="space-y-4">
        {reloaded || conflict ? (
          <p role="alert" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            {t('productForm.conflict')}
          </p>
        ) : mutation.error && mutation.error.status !== 422 ? (
          <ErrorNotice error={mutation.error} />
        ) : null}

        {editing ? null : (
          <Field label={t('product.sku')} hint={t('productForm.skuHint')} error={errors.sku} required requiredLabel={t('catalog.required')}>
            {(props) => <Input {...props} autoComplete="off" maxLength={64} value={values.sku} onChange={set('sku')} />}
          </Field>
        )}
        <Field label={t('product.name')} error={errors.name} required requiredLabel={t('catalog.required')}>
          {(props) => <Input {...props} autoComplete="off" maxLength={255} value={values.name} onChange={set('name')} />}
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('product.barcode')} hint={t('productForm.barcodeHint')} error={errors.barcode}>
            {(props) => <Input {...props} autoComplete="off" maxLength={64} value={values.barcode} onChange={set('barcode')} />}
          </Field>
          <Field label={t('productForm.weightGrams')} error={errors.weightGrams}>
            {(props) => <Input {...props} inputMode="numeric" autoComplete="off" value={values.weight} onChange={set('weight')} />}
          </Field>
        </div>

        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={() => dialogRef.current?.close()}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" disabled={mutation.isPending}>
            {mutation.isPending ? t('catalog.saving') : t(editing ? 'catalog.save' : 'productForm.create')}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
