const moneyFormatters = new Map<string, Intl.NumberFormat>();

/** Integer minor units (as stored by the backend) to a localised price, e.g. 2999 EUR -> "€29.99". */
export function formatMoney(cents: number, currency = 'EUR'): string {
    let formatter = moneyFormatters.get(currency);
    if (!formatter) {
        formatter = new Intl.NumberFormat('en-IE', { style: 'currency', currency });
        moneyFormatters.set(currency, formatter);
    }

    return formatter.format(cents / 100);
}

/** A major-unit amount from an external source (CheapShark reports USD floats). */
export function formatAmount(amount: number, currency: string): string {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(amount);
}

export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }

    return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
}

const relative = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
const steps: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 31_536_000],
    ['month', 2_592_000],
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
    ['second', 1],
];

export function formatRelative(iso: string | null | undefined, now: number = Date.now()): string {
    if (!iso) {
        return '—';
    }
    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);
    for (const [unit, size] of steps) {
        if (Math.abs(seconds) >= size || unit === 'second') {
            return relative.format(Math.round(seconds / size), unit);
        }
    }

    return '—';
}

export function pluralize(count: number, singular: string, plural = `${singular}s`): string {
    return `${count.toLocaleString('en')} ${count === 1 ? singular : plural}`;
}
