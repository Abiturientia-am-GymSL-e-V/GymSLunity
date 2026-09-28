<?php

declare(strict_types=1);

namespace Tests\Feature\Members;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostalLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_lookup_accepts_country_codes_and_names_and_preserves_leading_zeroes(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['mv']]));
        foreach (['DE', 'de', 'Deutschland'] as $country) {
            $this->getJson(route('postal.lookup', ['country' => $country, 'postal_code' => '01067']))->assertOk()->assertExactJson(['supported' => true, 'cities' => ['Dresden', 'Dresden Friedrichstadt', 'Dresden Innere Altstadt']]);
        }
        $this->getJson(route('postal.lookup', ['country' => 'DE', 'postal_code' => '00000']))->assertOk()->assertJson(['supported' => true, 'cities' => []]);
        $this->getJson(route('postal.lookup', ['country' => 'AT', 'postal_code' => '1010']))->assertOk()->assertJson(['supported' => false, 'cities' => []]);
    }

    public function test_lookup_requires_member_access_and_bounds_request_values(): void
    {
        $url = route('postal.lookup', ['country' => 'DE', 'postal_code' => '01067']);
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['roles' => ['bh']]))->getJson($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['roles' => ['mv']]))->getJson(route('postal.lookup', ['country' => 'DE', 'postal_code' => ['invalid']]))->assertUnprocessable();
    }
}
