<x-mail::message>
# Invoice {{ $number }} is overdue

Hello {{ $customerName }},

This is a reminder from {{ $company }}: the invoice below was due on {{ $dueDate }} and still has an outstanding balance.

<x-mail::table>
| | |
|:--|--:|
| Invoice number | {{ $number }} |
| Issue date | {{ $issueDate }} |
| Due date | {{ $dueDate }} |
| Total | {{ $total }} |
| Amount paid | {{ $amountPaid }} |
| **Balance due** | **{{ $balance }}** |
</x-mail::table>

Please settle the balance as soon as possible. If you have already paid, please ignore this message.

Thank you,<br>
{{ $company }}
</x-mail::message>
