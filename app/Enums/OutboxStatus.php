<?php

namespace App\Enums;

enum OutboxStatus: string
{
    /** Waiting to be (re)delivered once available_at has passed. */
    case Pending = 'pending';
    /** Claimed by a publisher under a lease (locked_until) and fencing token (lock_token). */
    case Processing = 'processing';
    /** Delivered. Terminal. */
    case Published = 'published';
    /** Gave up after max attempts. Terminal until an operator requeues it. */
    case DeadLetter = 'dead_letter';
}
