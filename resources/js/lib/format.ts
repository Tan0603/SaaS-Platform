/** Money is stored as integer cents; show it as RM with 2 decimals. */
export function formatMoney(cents: number | null | undefined): string {
    const value = (cents ?? 0) / 100;

    return 'RM ' + value.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
