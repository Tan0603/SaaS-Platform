<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Your invoice is ready". */
class InvoiceIssuedMail extends InvoiceMail
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: "Invoice {$this->invoice->number} from ".tenant('name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invoices.issued', with: $this->viewData());
    }
}
