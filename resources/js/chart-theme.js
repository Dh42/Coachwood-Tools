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
 * Every chart gets hover tooltips (anywhere along the axis) styled from the
 * theme; numbers show with thousands separators (Chart.js's own format).
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

function colorDatasets(datasets, chartType, theme) {
    datasets.forEach((dataset, i) => {
        if (dataset.fixedColor) return;
        const color = dataset.toneKey ? theme[dataset.toneKey] : theme.series[i % SERIES];
        dataset.borderColor = color;
        dataset.backgroundColor = dataset.fill || ['bar', 'pie', 'doughnut'].includes(dataset.type ?? chartType)
            ? color
            : dataset.backgroundColor ?? color;
    });
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

    colorDatasets(chart.data.datasets, chart.config.type, theme);
}

function applyDefaults() {
    const theme = chartTheme();
    Chart.defaults.color = theme.muted;
    Chart.defaults.borderColor = theme.grid;
    Chart.defaults.font.family = theme.fontFamily;

    // Tooltips on hover anywhere along the axis, not only on the exact point:
    // line charts draw no points, so Chart.js's default (pointer on the
    // point) almost never fired. A dot marks the hovered point.
    Chart.defaults.interaction.mode = 'index';
    Chart.defaults.interaction.intersect = false;
    Chart.defaults.elements.point.hoverRadius = 4;
    Chart.defaults.elements.point.hitRadius = 6;

    const tooltip = Chart.defaults.plugins.tooltip;
    tooltip.backgroundColor = theme.surface;
    tooltip.titleColor = theme.text;
    tooltip.bodyColor = theme.text;
    tooltip.footerColor = theme.muted;
    tooltip.borderColor = theme.grid;
    tooltip.borderWidth = 1;
    tooltip.padding = 10;
    tooltip.cornerRadius = 8;
    tooltip.boxPadding = 4;
    tooltip.usePointStyle = true;
    tooltip.titleFont = { family: theme.fontFamily, weight: '600' };
    tooltip.bodyFont = { family: theme.fontFamily };
}

export function themedChart(element, config) {
    applyDefaults();
    // Coloured before the chart is built: bars keep the colours they are
    // created with until a full update, so colouring afterwards with
    // update('none') left them Chart.js's default grey.
    colorDatasets(config.data?.datasets ?? [], config.type, chartTheme());
    // Horizontal bars: hovering anywhere along a row shows that row's tooltip.
    if (config.options?.indexAxis === 'y') {
        config.options.interaction = { axis: 'y', ...config.options.interaction };
    }
    const chart = new Chart(element, config);
    applyChartTheme(chart);
    onThemeChange((theme) => {
        applyDefaults();
        applyChartTheme(chart, theme);
        chart.update();
    });

    return chart;
}

export { Chart };
