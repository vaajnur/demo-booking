<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_author_can_cancel_their_booking(): void
    {
        $author = User::factory()->create();
        $booking = Booking::factory()->for($author)->create();
        Sanctum::actingAs($author);

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    public function test_another_user_cannot_cancel_someone_elses_booking(): void
    {
        $author = User::factory()->create();
        $otherUser = User::factory()->create();
        $booking = Booking::factory()->for($author)->create();
        Sanctum::actingAs($otherUser);

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    public function test_cancelling_a_booking_requires_authentication(): void
    {
        $booking = Booking::factory()->create();

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertUnauthorized();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }
}
