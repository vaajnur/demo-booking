<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_booking_for_the_authenticated_user_and_stores_times_as_utc(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->validPayload($room, [
            'user_id' => User::factory()->create()->id,
            'starts_at' => '2026-11-10T07:00:00Z',
            'ends_at' => '2026-11-10T08:00:00Z',
        ]));

        $response->assertCreated()
            ->assertJsonPath('user_id', $user->id);

        $this->assertDatabaseHas('bookings', [
            'id' => $response->json('id'),
            'room_id' => $room->id,
            'user_id' => $user->id,
            'starts_at' => '2026-11-10 07:00:00',
            'ends_at' => '2026-11-10 08:00:00',
        ]);
    }

    public function test_rejects_a_booking_that_overlaps_an_existing_booking(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-10 07:00:00',
            'ends_at' => '2026-11-10 08:00:00',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-10T10:30:00+03:00',
            'ends_at' => '2026-11-10T11:30:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_allows_bookings_that_touch_at_the_boundary_and_in_different_rooms(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        $otherRoom = Room::factory()->create();
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-10 07:00:00',
            'ends_at' => '2026-11-10 08:00:00',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-10T11:00:00+03:00',
            'ends_at' => '2026-11-10T12:00:00+03:00',
        ]))->assertCreated();

        $this->postJson('/api/bookings', $this->validPayload($otherRoom, [
            'starts_at' => '2026-11-10T10:00:00+03:00',
            'ends_at' => '2026-11-10T11:00:00+03:00',
        ]))->assertCreated();

        $this->assertDatabaseCount('bookings', 3);
    }

    public function test_rejects_a_duration_shorter_than_fifteen_minutes(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'ends_at' => '2026-11-10T10:00:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_rejects_a_duration_longer_than_four_hours(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-10T09:00:00+03:00',
            'ends_at' => '2026-11-10T13:15:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_rejects_times_that_do_not_follow_the_fifteen_minute_step(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'ends_at' => '2026-11-10T10:20:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_accepts_the_minimum_and_maximum_durations_and_an_end_at_twenty_hundred(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();
        $otherRoom = Room::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-10T09:00:00+03:00',
            'ends_at' => '2026-11-10T09:15:00+03:00',
        ]))->assertCreated();

        $this->postJson('/api/bookings', $this->validPayload($otherRoom, [
            'starts_at' => '2026-11-10T09:00:00+03:00',
            'ends_at' => '2026-11-10T13:00:00+03:00',
        ]))->assertCreated();

        $this->postJson('/api/bookings', $this->validPayload($otherRoom, [
            'starts_at' => '2026-11-10T19:00:00+03:00',
            'ends_at' => '2026-11-10T20:00:00+03:00',
        ]))->assertCreated();

        $this->assertDatabaseCount('bookings', 3);
    }

    public function test_rejects_a_booking_when_the_end_is_not_after_the_start(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-10T11:00:00+03:00',
            'ends_at' => '2026-11-10T10:00:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_checks_business_hours_in_the_rooms_office_timezone(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-10T06:00:00Z',
            'ends_at' => '2026-11-10T07:00:00Z',
        ]))->assertCreated();

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-10T05:45:00Z',
            'ends_at' => '2026-11-10T06:45:00Z',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-10T16:15:00Z',
            'ends_at' => '2026-11-10T17:15:00Z',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_uses_the_selected_rooms_office_timezone(): void
    {
        $room = Room::factory()
            ->for(Office::factory()->novosibirsk())
            ->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-10T01:45:00Z',
            'ends_at' => '2026-11-10T02:45:00Z',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_rejects_a_booking_that_crosses_the_offices_local_date(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-10T19:45:00+03:00',
            'ends_at' => '2026-11-11T09:15:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_allows_a_booking_in_the_past_because_there_are_no_date_restrictions(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2020-01-02T10:00:00+03:00',
            'ends_at' => '2020-01-02T11:00:00+03:00',
        ]))->assertCreated();

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_returns_readable_validation_errors_for_invalid_or_missing_input(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', [
            'room_id' => '1 OR 1=1',
            'starts_at' => 'tomorrow morning',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id', 'title', 'starts_at', 'ends_at']);
    }

    public function test_rejects_invalid_calendar_dates_and_times_without_an_offset(): void
    {
        $room = Room::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-02-30T10:00:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->postJson('/api/bookings', $this->validPayload($room, [
            'starts_at' => '2026-11-10T10:00:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{room_id: int, title: string, starts_at: string, ends_at: string}
     */
    private function validPayload(Room $room, array $overrides = []): array
    {
        return array_merge([
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-10T10:00:00+03:00',
            'ends_at' => '2026-11-10T11:00:00+03:00',
        ], $overrides);
    }
}
