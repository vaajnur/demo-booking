<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $startsAt = CarbonImmutable::parse($validated['starts_at'])->utc();
        $endsAt = CarbonImmutable::parse($validated['ends_at'])->utc();

        $booking = DB::transaction(function () use ($request, $validated, $startsAt, $endsAt): Booking {
            $room = Room::query()
                ->whereKey($validated['room_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $hasConflict = $room->bookings()
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();

            if ($hasConflict) {
                throw ValidationException::withMessages([
                    'starts_at' => 'Выбранное время пересекается с другой бронью этой комнаты.',
                ]);
            }

            return $room->bookings()->create([
                'user_id' => $request->user()->id,
                'title' => $validated['title'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
        });

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return response()->json($booking, 201);
    }

    public function destroy(Request $request, Booking $booking): Response
    {
        abort_unless($booking->user_id === $request->user()->id, 403, 'Отменить бронь может только её автор.');

        $booking->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
