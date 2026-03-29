const requirementsSearch = document.getElementById('requirementsSearch');

if (requirementsSearch) {
  requirementsSearch.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      requirementsSearch.form?.submit();
    }
  });
}
