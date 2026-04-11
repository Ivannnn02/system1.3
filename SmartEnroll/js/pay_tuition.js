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

function showToast(message) {
  let container = document.getElementById('smartenrollToastContainer');
  if (!container) {
    container = document.createElement('div');
    container.id = 'smartenrollToastContainer';
    container.className = 'sr-toast-container';
    document.body.appendChild(container);
  }

  const toast = document.createElement('div');
  toast.className = 'sr-toast';
  toast.textContent = message;
  container.appendChild(toast);

  window.setTimeout(() => {
    toast.classList.add('is-hiding');
    window.setTimeout(() => {
      toast.remove();
      if (!container.hasChildNodes()) {
        container.remove();
      }
    }, 240);
  }, 2400);
}

if (paymentCatalog && selectedPaymentTable && selectedPaymentRowTemplate) {
  const fullTuition = parseAmount(selectedPaymentTable.dataset.fullTuition || '0');
  const remainingBeforePayment = parseAmount(selectedPaymentTable.dataset.remaining || String(fullTuition));
  const getRows = () => Array.from(selectedPaymentTable.querySelectorAll('.selected-payment-row[data-option]'));

  const getRowAmount = (row) => {
    const option = row.dataset.option || '';
    if (option === 'Tuition Fee') {
      return parseAmount(row.querySelector('.tuition-manual-input')?.value);
    }
    return parseAmount(row.dataset.amount || '0');
  };

  const getMaxTuitionAllowed = (tuitionRow) => {
    const otherRowsTotal = getRows().reduce((sum, row) => {
      if (row === tuitionRow) {
        return sum;
      }
      return sum + Math.max(getRowAmount(row), 0);
    }, 0);

    return Math.max(remainingBeforePayment - otherRowsTotal, 0);
  };

  const syncEmptyState = () => {
    const hasRows = getRows().length > 0;
    if (selectedPaymentEmpty) {
      selectedPaymentEmpty.style.display = hasRows ? 'none' : '';
    }
  };

  const syncTotals = () => {
    let total = 0;
    getRows().forEach((row) => {
      const amount = getRowAmount(row);
      total += Math.max(amount, 0);

      if (row.dataset.option === 'Tuition Fee') {
        const tuitionInput = row.querySelector('.tuition-manual-input');
        const maxAllowed = getMaxTuitionAllowed(row);
        if (tuitionInput) {
          tuitionInput.max = maxAllowed.toFixed(2);
          const currentValue = parseAmount(tuitionInput.value);
          if (currentValue > maxAllowed) {
            tuitionInput.value = maxAllowed.toFixed(2);
            showToast('Tuition Fee cannot exceed the remaining balance.');
          }
        }
      }
    });

    const remaining = Math.max(remainingBeforePayment - total, 0);

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

    const hasTuitionFee = getRows().some((row) => row.dataset.option === 'Tuition Fee');
    const hasMonthlyPayment = getRows().some((row) => row.dataset.option === 'Monthly Payment');
    if ((option === 'Tuition Fee' && hasMonthlyPayment) || (option === 'Monthly Payment' && hasTuitionFee)) {
      showToast('Choose either Tuition Fee or Monthly Payment only, not both.');
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
      const maxAllowed = getMaxTuitionAllowed(row);
      if (maxAllowed <= 0) {
        showToast('No remaining balance available for Tuition Fee.');
        return;
      }

      tuitionWrap?.classList.remove('is-hidden');
      if (tuitionInput) {
        const initialValue = Math.min(defaultAmount, maxAllowed);
        tuitionInput.value = initialValue > 0 ? initialValue.toFixed(2) : '';
        tuitionInput.max = maxAllowed.toFixed(2);
        tuitionInput.addEventListener('input', () => {
          const currentValue = parseAmount(tuitionInput.value);
          const latestMax = getMaxTuitionAllowed(row);
          tuitionInput.max = latestMax.toFixed(2);
          if (currentValue > latestMax) {
            tuitionInput.value = latestMax.toFixed(2);
            showToast('Tuition Fee cannot exceed the remaining balance.');
          }
          syncTotals();
        });
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
      if (catalogRow.dataset.disabled === '1') {
        return;
      }

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

    const hasTuitionFee = rows.some((row) => row.option === 'Tuition Fee');
    const hasMonthlyPayment = rows.some((row) => row.option === 'Monthly Payment');
    if (hasTuitionFee && hasMonthlyPayment) {
      event.preventDefault();
      showToast('Choose either Tuition Fee or Monthly Payment only, not both.');
      return;
    }

    const totalAmount = rows.reduce((sum, row) => sum + row.amount, 0);
    if (totalAmount > remainingBeforePayment) {
      event.preventDefault();
      window.alert('The entered amount exceeds the remaining balance of ' + formatPHP(remainingBeforePayment) + '.');
      return;
    }

    if (paymentItemsJson) {
      paymentItemsJson.value = JSON.stringify(rows);
    }
  });

  syncTotals();
}
