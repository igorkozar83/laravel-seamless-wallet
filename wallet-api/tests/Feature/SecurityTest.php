<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Topic: mass assignment protection, SQL injection, hidden attributes. */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_balance_cannot_be_mass_assigned_from_request_data(): void
    {
        $user = User::factory()->create();

        // imagine this array came straight from $request->all()
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => 'EUR', 'balance_cents' => 1_000_000]);

        $this->assertSame(0, $wallet->fresh()->balance_cents);      // ignored: not in #[Fillable]
    }

    public function test_query_bindings_stop_sql_injection_while_string_concatenation_does_not(): void
    {
        User::factory(3)->create();
        $malicious = "x' OR '1'='1";

        $unsafe = DB::select("select * from users where email = '{$malicious}'");      // ❌ never do this
        $safe = DB::select('select * from users where email = ?', [$malicious]);        // ✅ bound parameter
        $eloquent = User::where('email', $malicious)->get();                            // ✅ bound by Eloquent

        $this->assertCount(3, $unsafe);       // the injected OR '1'='1' returned EVERY user
        $this->assertCount(0, $safe);
        $this->assertCount(0, $eloquent);
    }

    public function test_hidden_attributes_never_reach_json(): void
    {
        $json = User::factory()->create()->toArray();

        $this->assertArrayNotHasKey('password', $json);              // #[Hidden(['password', ...])]
        $this->assertArrayNotHasKey('remember_token', $json);
    }
}
