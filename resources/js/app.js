import { initPreferences } from './preferences';
import { initUi } from './ui';
import { initDataTables } from './data-table';
import { initTours } from './tour';
import { Chart, chartTheme, themedChart, applyChartTheme, onThemeChange, seriesColor } from './chart-theme';

// Pages with their own inline <script> (ported tools) use these globals.
window.Chart = Chart;
window.chartTheme = chartTheme;
window.themedChart = themedChart;
window.applyChartTheme = applyChartTheme;
window.onThemeChange = onThemeChange;
window.seriesColor = seriesColor;

document.addEventListener('DOMContentLoaded', () => {
    initPreferences();
    initUi();
    initDataTables();
    initTours();
});
