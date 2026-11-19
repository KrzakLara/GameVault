<?php

namespace App\Actions\Fulfillment;

enum FulfillmentOutcome: string
{
    case Delivered = 'delivered';
    case Failed = 'failed';
    /** The order was already delivered/failed/cancelled when this execution tried to claim it. */
    case AlreadyFinal = 'already_final';
    /** Another worker holds a live lease on this order; this execution did nothing. */
    case Busy = 'busy';
    /** This worker's claim was replaced by another claim before it could finalize; its work was discarded (fenced). */
    case Superseded = 'superseded';
}
