(() => {
  const table = document.getElementById('ae-jobs-table');
  if (!table || !window.AEAdmin) return;
  const summary = document.getElementById('ae-job-summary');
  const labels = { queued: 'Na fila', running: 'Processando', retry: 'Nova tentativa', completed: 'Concluídos', failed: 'Falhos' };
  const refresh = async () => {
    const body = new URLSearchParams({ action: 'ae_admin_status', nonce: AEAdmin.nonce, state: table.dataset.state || '', days: table.dataset.days || '0' });
    try {
      const response = await fetch(AEAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
      const payload = await response.json();
      if (!payload.success) return;
      table.innerHTML = payload.data.html;
      summary.innerHTML = Object.entries(labels).map(([key, label]) => `<span><strong>${Number(payload.data.summary[key] || 0)}</strong> ${label}</span>`).join('');
    } catch (_) { /* Keep the last rendered state on transient admin/network failures. */ }
  };
  refresh();
  window.setInterval(refresh, 5000);
})();
