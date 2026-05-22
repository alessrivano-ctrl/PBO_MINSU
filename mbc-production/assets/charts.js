(function () {
    'use strict';

    function money(value) {
        return 'PHP ' + Number(value || 0).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function getCanvas(id) {
        return document.getElementById(id);
    }

    function valueAt(values, index, fallback) {
        if (!Array.isArray(values)) {
            return fallback;
        }

        const value = values[index];
        return value === undefined || value === null || value === '' ? fallback : value;
    }

    function styledTooltip(callbacks) {
        return {
            backgroundColor: '#14532D',
            titleColor: '#FFFFFF',
            bodyColor: '#FFFFFF',
            borderColor: '#E5B83E',
            borderWidth: 1,
            cornerRadius: 8,
            displayColors: true,
            padding: 10,
            callbacks: callbacks || {
                label: function (context) {
                    return context.dataset.label + ': ' + money(context.parsed.y);
                }
            }
        };
    }

    function moneyTooltip(callbacks) {
        return styledTooltip(callbacks);
    }

    function moneyTicks() {
        return {
            color: '#475569',
            font: { weight: '600' },
            callback: function (value) {
                return money(value);
            }
        };
    }

    function renderLineChart(canvasId, labels, datasets, tooltipOptions) {
        const canvas = getCanvas(canvasId);
        if (!canvas) {
            return;
        }

        const styledDatasets = datasets.map(function (dataset) {
            return Object.assign({
                borderWidth: 3,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBorderWidth: 2,
                pointBackgroundColor: '#FFFFFF',
                pointBorderColor: dataset.borderColor || '#14532D'
            }, dataset);
        });

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: styledDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                layout: {
                    padding: { top: 8, right: 12, bottom: 0, left: 4 }
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxHeight: 8,
                            boxWidth: 28,
                            color: '#244236',
                            font: { weight: '700' },
                            padding: 18,
                            usePointStyle: true
                        }
                    },
                    tooltip: tooltipOptions || moneyTooltip()
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(20, 83, 45, 0.08)' },
                        ticks: {
                            color: '#475569',
                            font: { weight: '600' },
                            maxRotation: 45,
                            minRotation: 0
                        }
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: 'rgba(20, 83, 45, 0.12)' },
                        ticks: moneyTicks()
                    }
                }
            }
        });
    }

    function renderBarChart(canvasId, labels, datasets, showMoney, tooltipOptions) {
        const canvas = getCanvas(canvasId);
        if (!canvas) {
            return;
        }

        const styledDatasets = datasets.map(function (dataset) {
            return Object.assign({
                borderRadius: 5,
                borderSkipped: false,
                maxBarThickness: 48
            }, dataset);
        });

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: styledDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: { top: 8, right: 12, bottom: 0, left: 4 }
                },
                plugins: {
                    legend: {
                        display: styledDatasets.length > 1,
                        position: 'bottom',
                        labels: {
                            boxHeight: 8,
                            boxWidth: 28,
                            color: '#244236',
                            font: { weight: '700' },
                            padding: 18
                        }
                    },
                    tooltip: tooltipOptions || (showMoney ? moneyTooltip() : undefined)
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            color: '#475569',
                            font: { weight: '600' },
                            maxRotation: 45,
                            minRotation: 0
                        }
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: 'rgba(20, 83, 45, 0.12)' },
                        ticks: showMoney ? moneyTicks() : {
                            color: '#475569',
                            font: { weight: '600' }
                        }
                    }
                }
            }
        });
    }

    function renderDoughnutChart(canvasId, labels, data, options) {
        const canvas = getCanvas(canvasId);
        if (!canvas) {
            return;
        }

        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: options.label || 'Entries',
                        data: data,
                        backgroundColor: options.colors || [
                            '#2563eb',
                            '#16a34a',
                            '#dc2626',
                            '#ca8a04',
                            '#7c3aed',
                            '#0891b2',
                            '#64748b'
                        ],
                        borderColor: 'rgba(255, 255, 255, 0.9)',
                        borderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: options.tooltip || undefined
                }
            }
        });
    }

    function renderDashboardCharts(data) {
        const salesTooltip = moneyTooltip({
            title: function (items) {
                return 'Date: ' + (items[0] ? items[0].label : '');
            },
            label: function (context) {
                return context.dataset.label + ': ' + money(context.parsed.y);
            },
            afterBody: function (items) {
                const index = items[0] ? items[0].dataIndex : 0;
                return [
                    'Revenue: ' + money(valueAt(data.salesRevenue, index, 0)),
                    'Profit: ' + money(valueAt(data.salesProfit, index, 0)),
                    'Transactions: ' + valueAt(data.salesTransactions, index, 0)
                ];
            }
        });

        renderLineChart('dashboardSalesChart', data.salesLabels || [], [
            {
                label: 'Revenue',
                data: data.salesRevenue || [],
                borderColor: '#14532D',
                backgroundColor: 'rgba(20, 83, 45, 0.14)',
                tension: 0.28,
                fill: true
            },
            {
                label: 'Profit',
                data: data.salesProfit || [],
                borderColor: '#D6A51F',
                backgroundColor: 'rgba(214, 165, 31, 0.14)',
                tension: 0.28,
                fill: true
            }
        ], salesTooltip);

        const cashTooltip = moneyTooltip({
            title: function (items) {
                return 'Date: ' + (items[0] ? items[0].label : '');
            },
            label: function (context) {
                return context.dataset.label + ': ' + money(context.parsed.y);
            },
            afterBody: function (items) {
                const index = items[0] ? items[0].dataIndex : 0;
                return [
                    'Cash In: ' + money(valueAt(data.cashIn, index, 0)),
                    'Expenses: ' + money(valueAt(data.cashOut, index, 0)),
                    'Net Cash: ' + money(valueAt(data.cashNet, index, 0))
                ];
            }
        });

        renderBarChart('dashboardCashChart', data.cashLabels || [], [
            {
                label: 'Cash In',
                data: data.cashIn || [],
                backgroundColor: 'rgba(20, 83, 45, 0.78)',
                borderColor: '#14532D',
                borderWidth: 1
            },
            {
                label: 'Expenses',
                data: data.cashOut || [],
                backgroundColor: 'rgba(220, 38, 38, 0.58)',
                borderColor: '#991B1B',
                borderWidth: 1
            }
        ], true, cashTooltip);

        const productTooltip = moneyTooltip({
            title: function (items) {
                return 'Item: ' + (items[0] ? items[0].label : '');
            },
            label: function (context) {
                return 'Profit: ' + money(context.parsed.y);
            },
            afterBody: function (items) {
                const index = items[0] ? items[0].dataIndex : 0;
                return [
                    'Quantity Sold: ' + valueAt(data.topProductQty, index, 0),
                    'Revenue: ' + money(valueAt(data.topProductRevenue, index, 0)),
                    'Cost: ' + money(valueAt(data.topProductCost, index, 0)),
                    'Profit: ' + money(valueAt(data.topProductProfit, index, 0))
                ];
            }
        });

        renderBarChart('dashboardTopProductChart', data.topProductLabels || [], [
            {
                label: 'Profit',
                data: data.topProductProfit || [],
                backgroundColor: 'rgba(214, 165, 31, 0.78)',
                borderColor: '#D6A51F',
                borderWidth: 1
            }
        ], true, productTooltip);

        const inventoryTooltip = styledTooltip({
            title: function (items) {
                return 'Item or Category: ' + (items[0] ? items[0].label : '');
            },
            label: function (context) {
                return context.dataset.label + ': ' + Number(context.parsed.y || 0).toLocaleString();
            },
            afterBody: function (items) {
                const index = items[0] ? items[0].dataIndex : 0;
                return [
                    'Current Stock: ' + Number(valueAt(data.inventoryStockUnits, index, 0)).toLocaleString(),
                    'Low Stock Count: ' + Number(valueAt(data.inventoryLowStockCounts, index, 0)).toLocaleString(),
                    'Stock Value: ' + money(valueAt(data.inventoryStockValues, index, 0))
                ];
            }
        });

        renderBarChart('dashboardInventoryStatusChart', data.inventoryLabels || [], [
            {
                label: 'Current Stock',
                data: data.inventoryStockUnits || [],
                backgroundColor: 'rgba(20, 83, 45, 0.78)',
                borderColor: '#14532D',
                borderWidth: 1
            },
            {
                label: 'Low Stock Count',
                data: data.inventoryLowStockCounts || [],
                backgroundColor: 'rgba(214, 165, 31, 0.72)',
                borderColor: '#D6A51F',
                borderWidth: 1
            }
        ], false, inventoryTooltip);

        const topSellingTooltip = styledTooltip({
            title: function (items) {
                return 'Product: ' + (items[0] ? items[0].label : '');
            },
            label: function (context) {
                return 'Quantity Sold: ' + Number(context.parsed.y || 0).toLocaleString();
            },
            afterBody: function (items) {
                const index = items[0] ? items[0].dataIndex : 0;
                return [
                    'Revenue: ' + money(valueAt(data.topSellingProductRevenue, index, 0)),
                    'Cost: ' + money(valueAt(data.topSellingProductCost, index, 0)),
                    'Profit: ' + money(valueAt(data.topSellingProductProfit, index, 0))
                ];
            }
        });

        renderBarChart('dashboardProjectChart', data.topSellingProductLabels || [], [
            {
                label: 'Quantity Sold',
                data: data.topSellingProductQty || [],
                backgroundColor: 'rgba(20, 83, 45, 0.78)',
                borderColor: '#14532D',
                borderWidth: 1
            }
        ], false, topSellingTooltip);

        const rentalTooltip = moneyTooltip({
            title: function (items) {
                return 'Rental Account: ' + (items[0] ? items[0].label : '');
            },
            label: function (context) {
                return context.dataset.label + ': ' + money(context.parsed.y);
            },
            afterBody: function (items) {
                const index = items[0] ? items[0].dataIndex : 0;
                return [
                    'Expected Amount: ' + money(valueAt(data.rentalExpected, index, 0)),
                    'Paid Amount: ' + money(valueAt(data.rentalPaid, index, 0)),
                    'Balance: ' + money(valueAt(data.rentalBalance, index, 0)),
                    'Due Date: ' + (valueAt(data.rentalDueDates, index, '') || '-')
                ];
            }
        });

        renderBarChart('dashboardRentalCollectionChart', data.rentalCollectionLabels || [], [
            {
                label: 'Expected',
                data: data.rentalExpected || [],
                backgroundColor: 'rgba(20, 83, 45, 0.78)',
                borderColor: '#14532D',
                borderWidth: 1
            },
            {
                label: 'Paid',
                data: data.rentalPaid || [],
                backgroundColor: 'rgba(214, 165, 31, 0.72)',
                borderColor: '#D6A51F',
                borderWidth: 1
            }
        ], true, rentalTooltip);
    }

    function renderProjectCharts(data) {
        renderBarChart('categoryFinanceChart', data.categoryLabels || [], [
            {
                label: 'Income',
                data: data.income || [],
                backgroundColor: 'rgba(20, 83, 45, 0.78)',
                borderColor: '#14532D',
                borderWidth: 1
            },
            {
                label: 'Expense',
                data: data.expense || [],
                backgroundColor: 'rgba(220, 38, 38, 0.58)',
                borderColor: '#991B1B',
                borderWidth: 1
            },
            {
                label: 'Net',
                data: data.net || [],
                backgroundColor: 'rgba(214, 165, 31, 0.68)',
                borderColor: '#D6A51F',
                borderWidth: 1
            }
        ], true);

        renderDoughnutChart('entryTypeChart', data.entryTypeLabels || [], data.entryTypeCounts || [], {
            label: 'Entries',
            colors: [
                'rgba(20, 83, 45, 0.78)',
                'rgba(214, 165, 31, 0.76)',
                'rgba(31, 107, 58, 0.68)',
                'rgba(207, 230, 210, 0.95)',
                'rgba(220, 38, 38, 0.58)',
                'rgba(15, 63, 36, 0.72)',
                'rgba(138, 101, 11, 0.65)'
            ],
            tooltip: {
                callbacks: {
                    afterLabel: function (context) {
                        return 'Amount: ' + money((data.entryTypeAmounts || [])[context.dataIndex] || 0);
                    }
                }
            }
        });
    }

    function renderInventoryCharts(data) {
        renderDoughnutChart('inventoryCategoryChart', data.labels || [], data.counts || [], {
            label: 'Items',
            colors: ['#14532D', '#D6A51F', '#1F6B3A', '#CFE6D2', '#991B1B']
        });

        renderBarChart('inventoryValueChart', data.labels || [], [
            {
                label: 'Stock Value',
                data: data.values || [],
                backgroundColor: '#14532D',
                borderColor: '#0B2F1A',
                borderWidth: 1
            }
        ], true);
    }

    function initCharts() {
        if (!window.Chart || !window.BPO_CHARTS) {
            return;
        }

        if (window.BPO_CHARTS.dashboard) {
            renderDashboardCharts(window.BPO_CHARTS.dashboard);
        }

        if (window.BPO_CHARTS.projects) {
            renderProjectCharts(window.BPO_CHARTS.projects);
        }

        if (window.BPO_CHARTS.inventory) {
            renderInventoryCharts(window.BPO_CHARTS.inventory);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCharts);
    } else {
        initCharts();
    }
})();
