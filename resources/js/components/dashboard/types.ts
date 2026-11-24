export interface MonthlyChart {
    labels: string[];
    months: string[];
    invoiced_cents: number[];
    collected_cents: number[];
}

export interface StatusSlice {
    key: 'unpaid' | 'partial' | 'paid' | 'overdue';
    label: string;
    count: number;
    total_cents: number;
}

export interface TopCustomer {
    id: number;
    name: string;
    outstanding_cents: number;
    invoice_count: number;
}

export interface DashboardCharts {
    monthly: MonthlyChart;
    status: StatusSlice[];
    top_customers: TopCustomer[];
}
