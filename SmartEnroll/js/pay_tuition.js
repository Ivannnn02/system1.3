const studentSearch = document.getElementById('studentSearch');
if (studentSearch) {
  const searchForm = studentSearch.closest('.search-form');
  if (!searchForm) {
    studentSearch.addEventListener('input', () => {
      const query = studentSearch.value.toLowerCase();
      document.querySelectorAll('#payStudentList .student-pick-card').forEach((card) => {
        const haystack = (card.getAttribute('data-search') || '').toLowerCase();
        card.style.display = haystack.includes(query) ? '' : 'none';
      });
    });
  }
}

const paymentCatalog = document.getElementById('paymentCatalog');
const selectedPaymentTable = document.getElementById('selectedPaymentTable');
const selectedPaymentEmpty = document.getElementById('selectedPaymentEmpty');
const selectedPaymentRowTemplate = document.getElementById('selectedPaymentRowTemplate');
const paymentItemsJson = document.getElementById('paymentItemsJson');
const paymentForm = document.getElementById('paymentBuilderForm');
const paymentPreview = document.getElementById('paymentPreview');
const balanceAfterPreview = document.getElementById('balanceAfterPreview');
const remainingBalanceDisplay = document.getElementById('remainingBalanceDisplay');

function formatPHP(value) {
  return 'PHP ' + Number(value || 0).toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
}

function parseAmount(value) {
  return parseFloat(String(value || '').replace(/[^0-9.\-]/g, '')) || 0;
}

if (paymentCatalog && selectedPaymentTable && selectedPaymentRowTemplate) {
  const fullTuition = parseAmount(selectedPaymentTable.dataset.fullTuition || '0');
  const getRows = () => Array.from(selectedPaymentTable.querySelectorAll('.selected-payment-row[data-option]'));

  const syncEmptyState = () => {
    const hasRows = getRows().length > 0;
    if (selectedPaymentEmpty) {
      selectedPaymentEmpty.style.display = hasRows ? 'none' : '';
    }
  };

  const syncTotals = () => {
    let total = 0;
    getRows().forEach((row) => {
      const amount = row.dataset.option === 'Tuition Fee'
        ? parseAmount(row.querySelector('.tuition-manual-input')?.value)
        : parseAmount(row.dataset.amount || '0');
      total += Math.max(amount, 0);
    });

    const remaining = Math.max(fullTuition - total, 0);

    if (paymentPreview) {
      paymentPreview.textContent = formatPHP(total);
    }
    if (remainingBalanceDisplay) {
      remainingBalanceDisplay.textContent = formatPHP(remaining);
    }
    if (balanceAfterPreview) {
      balanceAfterPreview.textContent = formatPHP(remaining);
    }
  };

  const addSelectedRow = (option, defaultAmount) => {
    const existingRow = getRows().find((row) => row.dataset.option === option);
    if (existingRow) {
      return;
    }

    const fragment = selectedPaymentRowTemplate.content.cloneNode(true);
    const row = fragment.querySelector('.selected-payment-row');
    const name = row.querySelector('.selected-item-name');
    const suggested = row.querySelector('.selected-suggested-amount');
    const removeBtn = row.querySelector('.remove-selected-btn');
    const tuitionWrap = row.querySelector('.tuition-manual-wrap');
    const tuitionInput = row.querySelector('.tuition-manual-input');
    const status = row.querySelector('.selected-row-status');

    row.dataset.option = option;
    row.dataset.amount = String(defaultAmount);
    name.textContent = option;
    suggested.textContent = formatPHP(defaultAmount);
    if (option === 'Tuition Fee') {
      tuitionWrap?.classList.remove('is-hidden');
      if (tuitionInput) {
        tuitionInput.value = defaultAmount > 0 ? defaultAmount.toFixed(2) : '';
        tuitionInput.addEventListener('input', syncTotals);
      }
      if (status) {
        status.textContent = 'Manual input';
      }
    }
    removeBtn.addEventListener('click', () => {
      row.remove();
      syncEmptyState();
      syncTotals();
    });

    selectedPaymentTable.appendChild(fragment);
    syncEmptyState();
    syncTotals();
  };

  paymentCatalog.querySelectorAll('.catalog-row[data-option]').forEach((catalogRow) => {
    const button = catalogRow.querySelector('.catalog-add-btn');
    button?.addEventListener('click', () => {
      const option = catalogRow.dataset.option || '';
      const defaultAmount = parseAmount(catalogRow.dataset.default || '0');
      if (!option) {
        return;
      }
      addSelectedRow(option, defaultAmount);
    });
  });

  paymentForm?.addEventListener('submit', (event) => {
    const rows = getRows().map((row) => {
      const option = row.dataset.option || '';
      const amount = option === 'Tuition Fee'
        ? parseAmount(row.querySelector('.tuition-manual-input')?.value)
        : parseAmount(row.dataset.amount || '0');
      return {
        option,
        amount
      };
    }).filter((row) => row.option && row.amount > 0);

    if (!rows.length) {
      event.preventDefault();
      window.alert('Please add at least one payment row.');
      return;
    }

    if (rows.some((row) => row.option === 'Tuition Fee' && row.amount <= 0)) {
      event.preventDefault();
      window.alert('Please enter the tuition fee amount.');
      return;
    }

    if (paymentItemsJson) {
      paymentItemsJson.value = JSON.stringify(rows);
    }
  });

  syncTotals();
}
