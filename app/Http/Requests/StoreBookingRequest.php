<?php

namespace App\Http\Requests;

use App\Models\Room;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'room_id.required' => 'Выберите переговорную.',
            'room_id.integer' => 'Идентификатор комнаты должен быть целым числом.',
            'room_id.exists' => 'Выбранная переговорная не существует.',
            'title.required' => 'Укажите название встречи.',
            'title.string' => 'Название встречи должно быть текстом.',
            'title.max' => 'Название встречи не должно превышать 255 символов.',
            'starts_at.required' => 'Укажите время начала встречи.',
            'starts_at.date' => 'Время начала должно быть корректной датой.',
            'ends_at.required' => 'Укажите время окончания встречи.',
            'ends_at.date' => 'Время окончания должно быть корректной датой.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $startsAtInput = $this->input('starts_at');
            $endsAtInput = $this->input('ends_at');

            if (! $this->isIso8601WithOffset($startsAtInput)) {
                $validator->errors()->add('starts_at', 'Укажите время в формате ISO 8601 со смещением, например 2026-11-02T10:00:00+03:00.');
            }

            if (! $this->isIso8601WithOffset($endsAtInput)) {
                $validator->errors()->add('ends_at', 'Укажите время в формате ISO 8601 со смещением, например 2026-11-02T11:00:00+03:00.');
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $startsAt = CarbonImmutable::parse($startsAtInput);
            $endsAt = CarbonImmutable::parse($endsAtInput);

            foreach (['starts_at' => $startsAt, 'ends_at' => $endsAt] as $field => $dateTime) {
                if ((int) $dateTime->format('i') % 15 !== 0 || $dateTime->format('s.u') !== '00.000000') {
                    $validator->errors()->add($field, 'Время начала и окончания должно быть кратно 15 минутам.');
                }
            }

            $durationInSeconds = $endsAt->getTimestamp() - $startsAt->getTimestamp();

            if ($durationInSeconds < 900 || $durationInSeconds > 14400 || $durationInSeconds % 900 !== 0) {
                $validator->errors()->add('ends_at', 'Длительность встречи должна составлять от 15 минут до 4 часов с шагом 15 минут.');
            }

            $room = Room::query()->with('office')->find($this->integer('room_id'));

            if ($room === null) {
                return;
            }

            $localStartsAt = $startsAt->setTimezone($room->office->timezone);
            $localEndsAt = $endsAt->setTimezone($room->office->timezone);

            if ($localStartsAt->toDateString() !== $localEndsAt->toDateString()) {
                $validator->errors()->add('ends_at', 'Встреча должна целиком проходить в пределах одного рабочего дня офиса.');
            }

            if ($localStartsAt->format('H:i') < '09:00' || $localEndsAt->format('H:i') > '20:00') {
                $validator->errors()->add('starts_at', 'Встреча должна целиком проходить в рабочее время офиса: с 09:00 до 20:00.');
            }
        }];
    }

    private function isIso8601WithOffset(mixed $value): bool
    {
        if (! is_string($value)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))\z/', $value) !== 1) {
            return false;
        }

        $normalized = str_ends_with($value, 'Z')
            ? substr($value, 0, -1).'+00:00'
            : $value;

        if (preg_match('/T\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/', $value) === 1) {
            $normalized = preg_replace('/(Z|[+-]\d{2}:\d{2})\z/', ':00$1', $normalized);
        }

        if (preg_match('/T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/', $value) === 1) {
            $normalized = preg_replace('/(Z|[+-]\d{2}:\d{2})\z/', '.000000$1', $normalized);
        }

        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $normalized);
        $parseErrors = DateTimeImmutable::getLastErrors();

        return $dateTime !== false
            && ($parseErrors === false || ($parseErrors['warning_count'] === 0 && $parseErrors['error_count'] === 0));
    }
}
