import { ArcElement, BarElement, CategoryScale, Chart as ChartJS, Legend, LinearScale, Tooltip } from 'chart.js';

// Register only what the dashboard charts use (keeps the bundle small).
ChartJS.register(ArcElement, BarElement, CategoryScale, LinearScale, Legend, Tooltip);
