<?php

declare(strict_types=1);

namespace Kanso\Core\Internal\Domain\Document;

/** The documents Kanso generates for an order. Each is a template in templates/documents/. */
enum DocumentType: string
{
    /** For the warehouse: what to pick, with a box to tick per line. */
    case PickList = 'pick_list';

    /** Goes in the parcel: what the customer should find in it. */
    case PackingSlip = 'packing_slip';

    public function template(): string
    {
        return $this->value.'.html.twig';
    }

    /** Several orders' documents in one PDF, each order on its own pages. */
    public function batchTemplate(): string
    {
        return $this->value.'_batch.html.twig';
    }
}
