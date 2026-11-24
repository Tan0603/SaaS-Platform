<x-mail::message>
# Invoice {{ $number }}

Hello {{ $customerName }},

{{ $company }} has issued an invoice to you.

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

Please arrange payment by {{ $dueDate }}.

Thank you,<br>
{{ $company }}
</x-mail::message>
