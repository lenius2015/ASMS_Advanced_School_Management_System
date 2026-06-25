/**
 * assets/js/app.js
 * Shared client-side behaviors: mobile sidebar toggle,
 * idle-session warning, generic table search filter,
 * Chart.js integration, interactive dashboard features,
 * and CRUD/search enhancements.
 */

document.addEventListener('DOMContentLoaded', function () {
  // ====== Mobile sidebar toggle ======
  var toggleBtn = document.getElementById('sidebarToggleMobile');
  var sidebar = document.getElementById('asmsSidebar');
  if (toggleBtn && sidebar) {
    toggleBtn.addEventListener('click', function () {
      sidebar.classList.toggle('show');
    });
    document.addEventListener('click', function (e) {
      if (window.innerWidth < 992 && sidebar.classList.contains('show')
          && !sidebar.contains(e.target) && e.target !== toggleBtn) {
        sidebar.classList.remove('show');
      }
    });
  }

  // ====== Generic live filter for table search ======
  document.querySelectorAll('[data-table-filter]').forEach(function (input) {
    var table = document.querySelector(input.getAttribute('data-table-filter'));
    if (!table) return;
    input.addEventListener('keyup', function () {
      filterTable(input, table);
    });
  });

  // ====== Enhanced search with input[data-search] ======
  document.querySelectorAll('input[data-search]').forEach(function (input) {
    var target = document.querySelector(input.getAttribute('data-search'));
    if (!target) return;

    input.addEventListener('keyup', function () {
      var term = input.value.toLowerCase().trim();
      var rows = target.querySelectorAll('tbody tr');

      rows.forEach(function (row) {
        var match = row.textContent.toLowerCase().includes(term);
        row.style.display = match ? '' : 'none';
      });

      // Show/hide empty state
      var visible = target.querySelectorAll('tbody tr:not([style*="display: none"])').length;
      var emptyRow = target.querySelector('.empty-search-result');
      if (emptyRow) {
        emptyRow.style.display = visible === 0 ? '' : 'none';
      }
    });

    // Clear search button
    var clearBtn = input.parentElement.querySelector('.search-clear');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        input.value = '';
        input.dispatchEvent(new Event('keyup'));
        input.focus();
      });
    }
  });

  // ====== Confirm before destructive actions (works with both forms and buttons) ======
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener(el.tagName === 'FORM' ? 'submit' : 'click', function (e) {
      if (!confirm(el.getAttribute('data-confirm') || 'Are you sure?')) {
        e.preventDefault();
      }
    });
  });

  // ====== Bulk select checkboxes ======
  var selectAll = document.getElementById('selectAll');
  if (selectAll) {
    selectAll.addEventListener('change', function () {
      var checked = this.checked;
      document.querySelectorAll('.bulk-select').forEach(function (cb) {
        cb.checked = checked;
      });
      updateBulkBar();
    });
    document.querySelectorAll('.bulk-select').forEach(function (cb) {
      cb.addEventListener('change', updateBulkBar);
    });
  }

  function updateBulkBar() {
    var bar = document.querySelector('.bulk-action-bar');
    var count = document.querySelector('.bulk-action-bar .count');
    if (!bar) return;
    var selected = document.querySelectorAll('.bulk-select:checked').length;
    if (selected > 0) {
      bar.classList.add('show');
      if (count) count.textContent = selected + ' selected';
    } else {
      bar.classList.remove('show');
    }
  }

  // ====== Auto-dismiss alerts after 5 seconds ======
  document.querySelectorAll('.alert-dismissible').forEach(function (alert) {
    setTimeout(function () {
      var bsAlert = new bootstrap.Alert(alert);
      bsAlert.close();
    }, 5000);
  });

  // ====== Session idle warning ======
  var idleMinutesLimit = 20;
  var warnAtMs = (idleMinutesLimit - 2) * 60 * 1000;
  var lastActivity = Date.now();
  ['mousemove', 'keydown', 'click', 'scroll'].forEach(function (evt) {
    document.addEventListener(evt, function () { lastActivity = Date.now(); });
  });
  setInterval(function () {
    if (Date.now() - lastActivity > warnAtMs) {
      console.warn('Your session will expire soon due to inactivity.');
    }
  }, 30000);

  // ====== Chart.js Global Defaults ======
  if (typeof Chart !== 'undefined') {
    Chart.defaults.font.family = "'Inter', -apple-system, BlinkMacSystemFont, sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = '#334E68';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.padding = 16;
    Chart.defaults.plugins.tooltip.backgroundColor = '#0A1C2E';
    Chart.defaults.plugins.tooltip.titleFont.family = "'Inter', sans-serif";
    Chart.defaults.plugins.tooltip.bodyFont.family = "'Inter', sans-serif";
    Chart.defaults.plugins.tooltip.cornerRadius = 8;
    Chart.defaults.plugins.tooltip.padding = 12;
    Chart.defaults.plugins.tooltip.boxPadding = 4;
    Chart.defaults.elements.line.tension = 0.35;
  }

  // ====== Initialize all charts ======
  initAllCharts();

  // ====== Animate progress bars ======
  animateProgressBars();

  // ====== Animate counters ======
  animateCounters();

  // ====== Export buttons ======
  document.querySelectorAll('[data-export]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var table = document.querySelector(btn.getAttribute('data-export'));
      if (!table) return;
      var format = btn.getAttribute('data-format') || 'csv';
      if (format === 'csv') {
        exportTableToCSV(table, btn.getAttribute('data-filename') || 'export.csv');
      }
    });
  });
});

/**
 * Filter a table by input value
 */
function filterTable(input, table) {
  var term = input.value.toLowerCase();
  table.querySelectorAll('tbody tr').forEach(function (row) {
    row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
  });
}

/**
 * Initialize all charts defined in data-chart attributes
 */
function initAllCharts() {
  document.querySelectorAll('[data-chart]').forEach(function (canvas) {
    var configStr = canvas.getAttribute('data-chart');
    if (!configStr) return;
    try {
      var config = JSON.parse(configStr);
      if (typeof Chart !== 'undefined') {
        new Chart(canvas, config);
      }
    } catch (e) {
      console.warn('Invalid chart config:', e);
    }
  });
}

/**
 * Animate progress bars when they come into view
 */
function animateProgressBars() {
  var bars = document.querySelectorAll('.progress-bar[data-width]');
  if (!bars.length) return;

  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting) {
        var bar = entry.target;
        var width = bar.getAttribute('data-width');
        setTimeout(function () {
          bar.style.width = width + '%';
        }, 200);
        observer.unobserve(bar);
      }
    });
  }, { threshold: 0.3 });

  bars.forEach(function (bar) {
    observer.observe(bar);
  });
}

/**
 * Animate numeric counters in KPI cards
 */
function animateCounters() {
  document.querySelectorAll('[data-counter]').forEach(function (el) {
    var target = parseFloat(el.getAttribute('data-counter'));
    if (isNaN(target)) return;

    var suffix = el.getAttribute('data-counter-suffix') || '';
    var prefix = el.getAttribute('data-counter-prefix') || '';
    var duration = parseInt(el.getAttribute('data-counter-duration')) || 1000;
    var startTime = null;

    function step(timestamp) {
      if (!startTime) startTime = timestamp;
      var progress = Math.min((timestamp - startTime) / duration, 1);
      var eased = 1 - Math.pow(1 - progress, 3);
      var current = eased * target;

      if (target % 1 === 0) {
        el.textContent = prefix + Math.round(current) + suffix;
      } else {
        el.textContent = prefix + current.toFixed(1) + suffix;
      }

      if (progress < 1) {
        requestAnimationFrame(step);
      } else {
        el.textContent = prefix + target + suffix;
      }
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          requestAnimationFrame(step);
          observer.unobserve(el);
        }
      });
    }, { threshold: 0.3 });
    observer.observe(el);
  });
}

/**
 * Export table data to CSV
 */
function exportTableToCSV(table, filename) {
  var rows = [];
  // Header
  var headers = [];
  table.querySelectorAll('thead th').forEach(function (th) {
    // Skip action columns
    if (!th.querySelector('a, button, input[type="checkbox"]')) {
      headers.push('"' + th.textContent.trim().replace(/"/g, '""') + '"');
    }
  });
  if (headers.length) rows.push(headers.join(','));

  // Body
  table.querySelectorAll('tbody tr').forEach(function (tr) {
    if (tr.style.display === 'none') return;
    var row = [];
    tr.querySelectorAll('td').forEach(function (td) {
      // Skip action columns
      if (!td.querySelector('a.btn, button, input[type="checkbox"], .btn')) {
        row.push('"' + td.textContent.trim().replace(/"/g, '""') + '"');
      }
    });
    if (row.length) rows.push(row.join(','));
  });

  var csv = rows.join('\n');
  var blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
  var link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = filename;
  link.click();
  URL.revokeObjectURL(link.href);
}

/**
 * Helper to create a Chart.js config for a donut chart
 */
function createDonutChart(labels, data, colors) {
  return {
    type: 'doughnut',
    data: {
      labels: labels,
      datasets: [{
        data: data,
        backgroundColor: colors || ['#102A43', '#C8932A', '#1F8A55', '#C23B3B', '#2B6CB0', '#6B46C1'],
        borderWidth: 0,
        hoverOffset: 8
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '70%',
      plugins: {
        legend: {
          position: 'bottom',
          labels: {
            padding: 12,
            usePointStyle: true,
            font: { size: 11 }
          }
        }
      }
    }
  };
}

/**
 * Helper to create a Chart.js config for a line chart
 */
function createLineChart(labels, datasets, options) {
  return {
    type: 'line',
    data: {
      labels: labels,
      datasets: datasets
    },
    options: Object.assign({
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom',
          labels: {
            padding: 12,
            usePointStyle: true,
            font: { size: 11 }
          }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: {
            color: 'rgba(0,0,0,0.05)',
            drawBorder: false
          },
          ticks: {
            font: { size: 11 }
          }
        },
        x: {
          grid: {
            display: false
          },
          ticks: {
            font: { size: 11 }
          }
        }
      }
    }, options || {})
  };
}

/**
 * Helper to create a Chart.js config for a bar chart
 */
function createBarChart(labels, datasets, options) {
  return {
    type: 'bar',
    data: {
      labels: labels,
      datasets: datasets
    },
    options: Object.assign({
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'bottom',
          labels: {
            padding: 12,
            usePointStyle: true,
            font: { size: 11 }
          }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          grid: {
            color: 'rgba(0,0,0,0.05)',
            drawBorder: false
          },
          ticks: {
            font: { size: 11 }
          }
        },
        x: {
          grid: {
            display: false
          },
          ticks: {
            font: { size: 11 }
          }
        }
      }
    }, options || {})
  };
}