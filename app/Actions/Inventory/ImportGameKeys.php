<?php

namespace App\Actions\Inventory;

use App\Models\AuditLog;
use App\Models\Game;
use App\Models\User;
use App\Services\Inventory\InventoryAllocator;
use App\Support\Transactions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Admin stock import. All or nothing; audited with counts only (the codes themselves are never
 * logged, audited or echoed back).
 */
final class ImportGameKeys
{
    public function __construct(private readonly InventoryAllocator $inventory) {}

    /**
     * @param  list<string>  $codes
     * @return array{imported: int, duplicates: int}
     */
    public function handle(Game $game, array $codes, User $admin): array
    {
        try {
            return Transactions::retrying(function () use ($game, $codes, $admin): array {
                $result = $this->inventory->import($game, $codes, $admin->id);
                AuditLog::record('inventory.import', $admin, $game, $result + ['submitted' => count($codes)]);

                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent import committed one of these codes first. Nothing was imported.
            throw ValidationException::withMessages(['codes' => 'Some of these keys were imported at the same time by another request. Nothing was imported; please submit again.']);
        }
    }
}
