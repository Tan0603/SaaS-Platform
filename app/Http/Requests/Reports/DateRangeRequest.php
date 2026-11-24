<?php

namespace App\Http\Requests\Reports;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * `from` / `to` (inclusive, Y-m-d). When absent they default to the current month; the range may not be
 * reversed or longer than two years.
 */
class DateRangeRequest extends FormRequest
{
    /** When false, a missing range stays missing (the report then covers all time). */
    protected bool $defaultsToCurrentMonth = true;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Invoice::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->defaultsToCurrentMonth) {
            return;
        }

        $this->merge([
            'from' => $this->filled('from') ? $this->input('from') : now()->startOfMonth()->toDateString(),
            'to' => $this->filled('to') ? $this->input('to') : now()->endOfMonth()->toDateString(),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateRange($validator)];
    }

    protected function validateRange(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['from', 'to']) || ! $this->filled('from') || ! $this->filled('to')) {
            return;
        }

        $from = Carbon::createFromFormat('Y-m-d', $this->input('from'))->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $this->input('to'))->startOfDay();

        if ($from->gt($to)) {
            $validator->errors()->add('to', 'The end date must be on or after the start date.');
        } elseif ($to->gt($from->copy()->addYears(2))) {
            $validator->errors()->add('to', 'The date range cannot be longer than 2 years.');
        }
    }

    /** @return array{from: ?string, to: ?string} */
    public function range(): array
    {
        return [
            'from' => $this->filled('from') ? (string) $this->input('from') : null,
            'to' => $this->filled('to') ? (string) $this->input('to') : null,
        ];
    }
}
