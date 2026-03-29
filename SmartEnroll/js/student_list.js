const studentSearch = document.getElementById('studentSearch');

if (studentSearch) {
  studentSearch.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    const query = studentSearch.value.toLowerCase();
    const rows = document.querySelectorAll('.student-table tbody tr');
    rows.forEach((row) => {
      const text = row.textContent.toLowerCase();
      row.style.display = text.includes(query) ? '' : 'none';
    });
  });
}

function getTableData(excludeLastColumn = false) {
  const rows = [];
  const table = document.querySelector('.student-table');
  if (!table) return rows;
  let headers = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
  if (excludeLastColumn && headers.length) {
    headers = headers.slice(0, -1);
  }
  rows.push(headers);
  table.querySelectorAll('tbody tr').forEach((tr) => {
    if (tr.style.display === 'none') return;
    let cols = Array.from(tr.querySelectorAll('td')).map((td) => td.textContent.trim());
    if (excludeLastColumn && cols.length) {
      cols = cols.slice(0, -1);
    }
    rows.push(cols);
  });
  return rows;
}

function toCSV(rows) {
  return rows.map((r) => r.map((c) => `"${c.replace(/"/g, '""')}"`).join(',')).join('\n');
}

document.querySelectorAll('.action-btn.delete').forEach((btn) => {
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    const href = btn.getAttribute('href');
    const modal = document.getElementById('deleteModal');
    const confirmBtn = document.getElementById('confirmDelete');
    if (modal && confirmBtn) {
      confirmBtn.setAttribute('href', href);
      modal.classList.add('active');
      const iconBox = document.getElementById('deleteIconBox');
      if (iconBox) {
        iconBox.classList.remove('show-alert');
        setTimeout(() => {
          iconBox.classList.add('show-alert');
        }, 400);
      }
    }
  });
});

const deleteModal = document.getElementById('deleteModal');
const cancelDelete = document.getElementById('cancelDelete');
if (deleteModal && cancelDelete) {
  cancelDelete.addEventListener('click', () => {
    deleteModal.classList.remove('active');
  });
}
