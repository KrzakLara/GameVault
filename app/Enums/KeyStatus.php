<?php

namespace App\Enums;

enum KeyStatus: string
{
    /** In stock, not linked to any order. */
    case Available = 'available';
    /** Held for a specific order that has not been delivered yet. */
    case Reserved = 'reserved';
    /** Delivered to an order. Never returns to stock. */
    case Sold = 'sold';
}
