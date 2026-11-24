<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Your invoice is overdue" reminder. Same data as the issued mail, different wording. */
class InvoiceOverdueMail extends InvoiceMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: "Overdue: invoice {$this->invoice->number} from ".tenant('name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invoices.overdue', with: $this->viewData());
    }
}
