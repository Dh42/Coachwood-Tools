/*
 * The shared chart theme. Every chart reads its colours from the design
 * tokens through here, and recolours when the theme changes, instead of
 * each page keeping its own palette (the old app had one per page).
 *
 *   const chart = new Chart(el, { ... colors from chartTheme() ... });
 *   onThemeChange(() => { applyChartTheme(chart); chart.update(); });
 *
 * Or let the helper do both: themedChart(el, config) returns the chart and
 * keeps its axes, legend and dataset colours in step with the theme.
 * Datasets are coloured by position from the six-colour series palette,
 * unless a dataset sets its own colour or `toneKey` ('good', 'bad', ...).
 */
import { Chart } from 'chart.js/auto';

const SERIES = 6;

function token(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(`--${name}`).trim();
}

export function chartTheme() {
    return {
        text: token('text'),
        muted: token('muted'),
        faint: token('faint'),
        grid: token('border'),
        surface: token('surface'),
        accent: token('accent'),
        good: token('good'),
        bad: token('bad'),
        warn: token('warn'),
        near: token('near'),
        info: token('info'),
        series: Array.from({ length: SERIES }, (_, i) => token(`series-${i + 1}`)),
        fontFamily: token('font-sans'),
    };
}

export function seriesColor(index) {
    return chartTheme().series[index % SERIES];
}

export function onThemeChange(callback) {
    document.addEventListener('theme-changed', (event) => callback(chartTheme(), event.detail));
}

export function applyChartTheme(chart, theme = chartTheme()) {
    const options = chart.options;
    options.color = theme.muted;
    if (options.plugins?.legend?.labels) options.plugins.legend.labels.color = theme.muted;
    Object.values(options.scales ?? {}).forEach((scale) => {
        scale.ticks = { ...scale.ticks, color: theme.muted };
        scale.grid = { ...scale.grid, color: theme.grid };
        if (scale.title) scale.title.color = theme.muted;
    });

    chart.data.datasets.forEach((dataset, i) => {
        if (dataset.fixedColor) return;
        const color = dataset.toneKey ? theme[dataset.toneKey] : theme.series[i % SERIES];
        dataset.borderColor = color;
        dataset.backgroundColor = dataset.fill || ['bar', 'pie', 'doughnut'].includes(dataset.type ?? chart.config.type)
            ? color
            : dataset.backgroundColor ?? color;
    });
}

function applyDefaults() {
    const theme = chartTheme();
    Chart.defaults.color = theme.muted;
    Chart.defaults.borderColor = theme.grid;
    Chart.defaults.font.family = theme.fontFamily;
}

export function themedChart(element, config) {
    applyDefaults();
    const chart = new Chart(element, config);
    applyChartTheme(chart);
    chart.update('none');
    onThemeChange((theme) => {
        applyDefaults();
        applyChartTheme(chart, theme);
        chart.update('none');
    });

    return chart;
}

export { Chart };
