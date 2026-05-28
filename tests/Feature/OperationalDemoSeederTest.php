<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyPointTransaction;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_is_idempotent_and_keeps_demo_ledgers_consistent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::where('email', 'admin@gusto.bo')->count());
        $this->assertGreaterThan(0, InventoryMovement::where('reference', 'like', 'DEMO-%')->count());
        $this->assertGreaterThan(0, LoyaltyPointTransaction::where('reference', 'like', 'DEMO-%')->count());
        $this->assertNull(
            InventoryMovement::where('stock_after', '<', 0)->first()
        );

        LoyaltyPoint::with(['transactions' => fn ($query) => $query->latest('id')])
            ->whereHas('transactions')
            ->get()
            ->each(function (LoyaltyPoint $account) {
                $latest = $account->transactions->first();
                $this->assertNotNull($latest);
                $this->assertSame($latest->balance_after, $account->points);
            });
    }
}
