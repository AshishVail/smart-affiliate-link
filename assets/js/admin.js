(function () {
	'use strict';

	/**
	 * Safely parse dashboard series from localized data.
	 * Expected format:
	 * salcDashboardData.series = [
	 *   { day: '2026-06-01', clicks: '12' },
	 *   ...
	 * ]
	 */
	function getSeries() {
		if (
			typeof window.salcDashboardData === 'undefined' ||
			!window.salcDashboardData ||
			!Array.isArray(window.salcDashboardData.series)
		) {
			return [];
		}

		return window.salcDashboardData.series
			.map(function (item) {
				return {
					day: item && item.day ? String(item.day) : '',
					clicks: item && typeof item.clicks !== 'undefined' ? Number(item.clicks) : 0
				};
			})
			.filter(function (item) {
				return item.day !== '' && Number.isFinite(item.clicks);
			});
	}

	/**
	 * Format YYYY-MM-DD to readable date for axis labels.
	 */
	function formatDateLabel(dateString) {
		var date = new Date(dateString + 'T00:00:00');
		if (Number.isNaN(date.getTime())) {
			return dateString;
		}
		return date.toLocaleDateString(undefined, {
			month: 'short',
			day: 'numeric'
		});
	}

	/**
	 * Build gradient fill for line chart.
	 * Enhanced for a premium, sleek YouTube-like analytics wave.
	 */
	function makeGradient(ctx, chartArea) {
		var gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
		gradient.addColorStop(0, 'rgba(34, 113, 177, 0.40)'); // Top: Rich brand blue with soft opacity
		gradient.addColorStop(0.5, 'rgba(34, 113, 177, 0.15)'); // Middle: Smooth transition
		gradient.addColorStop(1, 'rgba(34, 113, 177, 0.00)'); // Bottom: Fully transparent
		return gradient;
	}

	/**
	 * Create chart when canvas exists and Chart.js is loaded.
	 */
	function initClicksChart() {
		var canvas = document.getElementById('salcClicksChart');
		if (!canvas) {
			return;
		}

		if (typeof window.Chart === 'undefined') {
			// Chart.js not loaded for some reason.
			return;
		}

		var series = getSeries();
		var labels = series.map(function (row) { return formatDateLabel(row.day); });
		var values = series.map(function (row) { return row.clicks; });

		// If no data, still render an empty chart with helper label.
		if (labels.length === 0) {
			labels = ['No data'];
			values = [0];
		}

		var ctx = canvas.getContext('2d');
		if (!ctx) {
			return;
		}

		var chartInstance = null;

		chartInstance = new window.Chart(ctx, {
			type: 'line',
			data: {
				labels: labels,
				datasets: [
					{
						label: 'Clicks',
						data: values,
						borderColor: '#2271b1', // Pure WordPress / Nexovent Professional Blue
						borderWidth: 3, // Thicker stroke for a modern look
						pointRadius: 4, // Distinct interactive nodes
						pointHoverRadius: 6,
						pointBackgroundColor: '#2271b1',
						pointBorderColor: '#ffffff',
						pointBorderWidth: 2,
						tension: 0.4, // Smooth cubic interpolation curve just like YouTube Analytics
						fill: true,
						backgroundColor: function (context) {
							var chart = context.chart;
							var chartArea = chart.chartArea;

							if (!chartArea) {
								// Initial render before layout.
								return 'rgba(34, 113, 177, 0.15)';
							}

							return makeGradient(chart.ctx, chartArea);
						}
					}
				]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				interaction: {
					mode: 'index',
					intersect: false
				},
				plugins: {
					legend: {
						display: false, // Hidden legend to emulate the distraction-free YouTube dashboard card
						position: 'top',
						labels: {
							usePointStyle: true,
							pointStyle: 'circle',
							boxWidth: 8
						}
					},
					tooltip: {
						backgroundColor: '#1d2327',
						titleColor: '#ffffff',
						bodyColor: '#ffffff',
						bodyFont: {
							weight: 'bold'
						},
						padding: 12,
						displayColors: false,
						cornerRadius: 6,
						callbacks: {
							label: function (context) {
								var y = typeof context.parsed.y === 'number' ? context.parsed.y : 0;
								return 'Clicks: ' + y.toLocaleString();
							}
						}
					}
				},
				scales: {
					x: {
						grid: {
							display: false,
							drawBorder: false
						},
						ticks: {
							color: '#646970',
							font: {
								size: 11
							},
							maxRotation: 0,
							autoSkip: true,
							padding: 8
						}
					},
					y: {
						beginAtZero: true,
						grid: {
							color: 'rgba(0, 0, 0, 0.05)',
							drawBorder: false
						},
						ticks: {
							color: '#646970',
							font: {
								size: 11
							},
							precision: 0,
							padding: 8,
							callback: function (value) {
								return Number(value).toLocaleString();
							}
						}
					}
				}
			}
		});

		// Optional: expose for debug.
		window.salcClicksChartInstance = chartInstance;
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initClicksChart);
	} else {
		initClicksChart();
	}
})();
