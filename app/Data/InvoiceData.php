<?php

namespace App\Data;

/** Validated input for creating or updating an invoice. Totals are never part of it: the server computes them. */
final readonly class InvoiceData
{
    /**
     * @param  list<InvoiceItemData>  $items
     */
    public function __construct(
        public int $customerId,
        public string $issueDate,
        public string $dueDate,
        public array $items,
    ) {}

    /**
     * @param  array{customer_id: int|string, issue_date: string, due_date: string, items: list<array<string, mixed>>}  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            customerId: (int) $validated['customer_id'],
            issueDate: $validated['issue_date'],
            dueDate: $validated['due_date'],
            items: array_map(
                fn (array $item) => InvoiceItemData::fromArray($item),
                array_values($validated['items']),
            ),
        );
    }
}
