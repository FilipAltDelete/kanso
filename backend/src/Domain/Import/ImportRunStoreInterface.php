<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Import;

use Kanso\Core\Internal\Domain\Common\Page;
use Kanso\Core\Internal\Domain\Common\PageRequest;

interface ImportRunStoreInterface
{
    public function findById(string $id): ?ImportRun;

    /**
     * Newest first. The `type` filter (products, orders or stock) narrows the list.
     *
     * @return Page<ImportRun>
     */
    public function search(PageRequest $request): Page;

    /** Stages a run; it is written when the surrounding transaction commits. */
    public function add(ImportRun $run): void;
}
