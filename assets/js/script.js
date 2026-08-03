/* ============================================================
   AdsDash — shared app JS
   ============================================================ */
document.addEventListener('DOMContentLoaded', function () {

  // Default DataTables styling used across list modules
  if (window.jQuery && jQuery.fn.DataTable) {
    jQuery('.datatable').each(function () {
      jQuery(this).DataTable({
        pageLength: 10,
        lengthChange: false,
        language: { search: '', searchPlaceholder: 'Filter table…' },
        dom: '<"d-flex justify-content-between align-items-center mb-3"f>rt<"d-flex justify-content-between align-items-center mt-3"ip>'
      });
    });
  }

  // Enable Bootstrap tooltips globally
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
});

/* Confirm helper for destructive actions (delete quotation, remove screen, etc.) */
function confirmAction(message) {
  return window.confirm(message || 'Are you sure? This action cannot be undone.');
}

/* Export current table to CSV (client-side quick export; Reports module has server export too) */
function exportTableToCSV(tableId, filename) {
  const table = document.getElementById(tableId);
  if (!table) return;
  let csv = [];
  table.querySelectorAll('tr').forEach(row => {
    const cols = row.querySelectorAll('td, th');
    let line = [];
    cols.forEach(col => line.push('"' + col.innerText.replace(/"/g, '""') + '"'));
    csv.push(line.join(','));
  });
  const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = (filename || 'export') + '.csv';
  link.click();
}
