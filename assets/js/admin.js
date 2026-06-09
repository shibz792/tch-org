(() => {
  const search = document.getElementById('tableSearch');
  if (search) search.addEventListener('input', () => {
    const value = search.value.toLowerCase().trim();
    document.querySelectorAll('tbody tr[data-search]').forEach(row => row.hidden = !row.dataset.search.includes(value));
  });
})();
