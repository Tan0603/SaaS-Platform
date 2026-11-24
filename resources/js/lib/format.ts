/** Money is stored as integer cents; show it as RM with 2 decimals. */
export function formatMoney(cents: number | null | undefined): string {
    const value = (cents ?? 0) / 100;

    return 'RM ' + value.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** "125.50" -> 12550 using string maths only. Returns null when the text is not a valid amount. */
export function parseCents(text: string): number | null {
    const match = /^(\d{1,7})(?:\.(\d{1,2}))?$/.exec(text.trim());

    return match ? Number(match[1] + (match[2] ?? '').padEnd(2, '0')) : null;
}

/** Same rounding as the server (InvoiceCalculator): quantity in hundredths x price in cents, half up. Display only. */
export function lineTotalCents(quantity: string, unitPrice: string): number {
    const qty = /^(\d{1,6})(?:\.(\d{1,2}))?$/.exec(quantity.trim());
    const price = parseCents(unitPrice);

    if (!qty || price === null) {
        return 0;
    }

    const hundredths = Number(qty[1] + (qty[2] ?? '').padEnd(2, '0'));

    return Math.floor((hundredths * price + 50) / 100);
}

/** Short form for chart axis ticks: "RM 1.2K", "RM 3M". Full precision stays in formatMoney (tooltips). */
export function formatMoneyCompact(cents: number | null | undefined): string {
    const value = (cents ?? 0) / 100;

    return 'RM ' + value.toLocaleString('en-MY', { notation: 'compact', maximumFractionDigits: 1 });
}
