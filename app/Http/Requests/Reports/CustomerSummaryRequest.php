<?php

namespace App\Http\Requests\Reports;

use App\Services\Reports\CustomerSummaryReport;
use Illuminate\Validation\Rule;

/** Optional date range (no default: all time) plus a whitelisted sort. */
class CustomerSummaryRequest extends DateRangeRequest
{
    protected bool $defaultsToCurrentMonth = false;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'sort' => ['nullable', Rule::in(array_keys(CustomerSummaryReport::SORTS))],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    public function sort(): string
    {
        return (string) ($this->validated('sort') ?? 'name');
    }

    public function direction(): string
    {
        return (string) ($this->validated('dir') ?? 'asc');
    }
}
