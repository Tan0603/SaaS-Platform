import { onMounted, onUnmounted, ref } from 'vue';

export interface ChartTheme {
    dark: boolean;
    text: string;
    grid: string;
    surface: string;
    series: { invoiced: string; collected: string };
    status: Record<string, string>;
    accent: string;
}

/** shadcn stores colours as "H S% L%"; Chart.js needs a real colour string. Falls back when the variable is missing. */
function cssColor(name: string, fallback: string): string {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    return value ? `hsl(${value})` : fallback;
}

function readTheme(): ChartTheme {
    const dark = document.documentElement.classList.contains('dark');

    return {
        dark,
        text: cssColor('--muted-foreground', dark ? '#a3a3a3' : '#737373'),
        grid: cssColor('--border', dark ? '#262626' : '#e5e5e5'),
        surface: cssColor('--card', dark ? '#0a0a0a' : '#ffffff'),
        series: dark ? { invoiced: '#60a5fa', collected: '#34d399' } : { invoiced: '#2563eb', collected: '#059669' },
        status: dark
            ? { unpaid: '#94a3b8', partial: '#fbbf24', paid: '#34d399', overdue: '#f87171' }
            : { unpaid: '#64748b', partial: '#d97706', paid: '#059669', overdue: '#dc2626' },
        accent: dark ? '#fb923c' : '#ea580c',
    };
}

/** Chart colours that follow the light / dark class on <html> (set by the appearance switcher). */
export function useChartTheme() {
    const theme = ref<ChartTheme>(readTheme());
    let observer: MutationObserver | null = null;

    onMounted(() => {
        theme.value = readTheme();
        observer = new MutationObserver(() => (theme.value = readTheme()));
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    });

    onUnmounted(() => observer?.disconnect());

    return theme;
}
