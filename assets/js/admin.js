(function () {
	if (typeof salcDashboardData === 'undefined') return;
	const canvas = document.getElementById('salcClicksChart');
	if (!canvas) return;

	const labels = salcDashboardData.series.map(item => item.day);
	const data = salcDashboardData.series.map(item => Number(item.clicks));

	new Chart(canvas, {
		type: 'line',
		data: {
			labels,
			datasets: [{
				label: 'Clicks',
				data,
				borderWidth: 2,
				tension: 0.25,
				fill: false
			}]
		},
		options: {
			responsive: true,
			plugins: {
				legend: { display: true }
			},
			scales: {
				y: { beginAtZero: true }
			}
		}
	});
})();
