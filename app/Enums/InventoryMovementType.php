<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case Imported = 'imported';
    case Reserved = 'reserved';
    case Released = 'released';
    case Sold = 'sold';
}
