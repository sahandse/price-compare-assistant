document.addEventListener('DOMContentLoaded', () => {
  if (!window.PCAAdmin) return;

  const ajax = async (action, data = {}) => {
    const body = new URLSearchParams({ action, nonce: PCAAdmin.nonce, ...data });
    const res = await fetch(PCAAdmin.ajax, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body
    });
    return res.json();
  };

  const escapeHtml = value => String(value || '').replace(/[&<>"']/g, ch => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  }[ch]));

  const setBusy = (el, busy, text) => {
    if (!el) return;
    if (busy) {
      el.dataset.oldText = el.textContent;
      el.disabled = true;
      el.textContent = text || PCAAdmin.labels.loading;
    } else {
      el.disabled = false;
      el.textContent = el.dataset.oldText || el.textContent;
    }
  };

  const renderSuggestions = (sourceBox, payload) => {
    const box = sourceBox.querySelector('.pca-suggestions');
    const source = sourceBox.dataset.source;
    const productId = sourceBox.closest('.pca-product-row').dataset.product;

    if (!payload.results || !payload.results.length) {
      box.innerHTML =
        '<div class="pca-suggest-empty">' +
          escapeHtml(PCAAdmin.labels.notFound) +
          ' <a href="' + escapeHtml(payload.search_url) + '" target="_blank" rel="noopener">نمایش جستجو در سایت</a>' +
        '</div>';
      box.hidden = false;
      return;
    }

    box.innerHTML = payload.results.map(item => (
      '<button type="button" class="pca-suggest-item" ' +
        'data-product="' + escapeHtml(productId) + '" ' +
        'data-source="' + escapeHtml(source) + '" ' +
        'data-url="' + escapeHtml(item.url) + '" ' +
        'data-title="' + escapeHtml(item.title) + '">' +
          '<strong>' + escapeHtml(item.title) + '</strong>' +
          '<small>' + escapeHtml(item.url) + '</small>' +
          '<span>انتخاب این محصول</span>' +
      '</button>'
    )).join('');
    box.hidden = false;
  };

  const searchSource = async (sourceBox) => {
    const row = sourceBox.closest('.pca-product-row');
    const button = sourceBox.querySelector('.pca-search-source');
    setBusy(button, true, PCAAdmin.labels.searching);
    try {
      const result = await ajax('pca_search_product', {
        product_id: row.dataset.product,
        source: sourceBox.dataset.source
      });
      if (!result.success) throw new Error(result.data?.message || PCAAdmin.labels.error);
      renderSuggestions(sourceBox, result.data);
    } catch (err) {
      const box = sourceBox.querySelector('.pca-suggestions');
      box.innerHTML = '<div class="pca-suggest-error">' + escapeHtml(err.message) + '</div>';
      box.hidden = false;
    } finally {
      setBusy(button, false);
    }
  };

  document.addEventListener('click', async (e) => {
    const searchOne = e.target.closest('.pca-search-source');
    if (searchOne) {
      e.preventDefault();
      await searchSource(searchOne.closest('.pca-source'));
      return;
    }

    const searchAll = e.target.closest('.pca-search-all');
    if (searchAll) {
      e.preventDefault();
      const row = searchAll.closest('.pca-product-row');
      setBusy(searchAll, true, PCAAdmin.labels.searching);
      try {
        await Promise.all([...row.querySelectorAll('.pca-source')].map(searchSource));
      } finally {
        setBusy(searchAll, false);
      }
      return;
    }

    const suggestion = e.target.closest('.pca-suggest-item');
    if (suggestion) {
      e.preventDefault();
      const sourceBox = suggestion.closest('.pca-source');
      suggestion.disabled = true;
      try {
        const result = await ajax('pca_select_match', {
          product_id: suggestion.dataset.product,
          source: suggestion.dataset.source,
          url: suggestion.dataset.url,
          title: suggestion.dataset.title
        });
        if (!result.success) throw new Error(result.data?.message || PCAAdmin.labels.error);

        const match = sourceBox.querySelector('.pca-source-match');
        const price = sourceBox.querySelector('.pca-source-price');
        match.innerHTML =
          '<a href="' + escapeHtml(suggestion.dataset.url) + '" target="_blank" rel="noopener">' +
          escapeHtml(suggestion.dataset.title) + '</a>' +
          '<button type="button" class="button-link pca-refresh-source">بروزرسانی قیمت</button>';

        if (result.data.formatted) {
          price.innerHTML = '<b>' + escapeHtml(result.data.formatted) + '</b><small>' + escapeHtml(result.data.checked_at || '') + '</small>';
        } else {
          price.innerHTML = '<b>—</b><small>' + escapeHtml(result.data.message || 'محصول تطبیق داده شد؛ قیمت قابل استخراج نبود.') + '</small>';
        }
        sourceBox.querySelector('.pca-suggestions').hidden = true;
      } catch (err) {
        alert(err.message);
      } finally {
        suggestion.disabled = false;
      }
      return;
    }

    const refresh = e.target.closest('.pca-refresh-source');
    if (refresh) {
      e.preventDefault();
      const sourceBox = refresh.closest('.pca-source');
      const row = sourceBox.closest('.pca-product-row');
      setBusy(refresh, true, PCAAdmin.labels.loading);
      try {
        const result = await ajax('pca_refresh_price', {
          product_id: row.dataset.product,
          source: sourceBox.dataset.source
        });
        if (!result.success) throw new Error(result.data?.message || PCAAdmin.labels.error);
        sourceBox.querySelector('.pca-source-price').innerHTML =
          '<b>' + escapeHtml(result.data.formatted) + '</b><small>' + escapeHtml(result.data.checked_at || '') + '</small>';
      } catch (err) {
        alert(err.message);
      } finally {
        setBusy(refresh, false);
      }
      return;
    }

    const apply = e.target.closest('.pca-apply-price-btn');
    if (apply) {
      e.preventDefault();
      const row = apply.closest('.pca-product-row');
      const input = row.querySelector('.pca-apply-price input');
      const value = input.value.trim();
      if (!value) {
        alert('قیمت جدید را وارد کنید.');
        return;
      }
      if (!confirm('قیمت این محصول در ووکامرس تغییر کند؟')) return;

      setBusy(apply, true, 'در حال اعمال…');
      try {
        const result = await ajax('pca_apply_price', {
          product_id: row.dataset.product,
          price: value
        });
        if (!result.success) throw new Error(result.data?.message || PCAAdmin.labels.error);

        row.querySelector('.pca-own-price').innerHTML =
          '<b>' + escapeHtml(result.data.formatted) + '</b><small>قیمت فعلی فروشگاه</small>';
        input.value = '';
        alert(result.data.message || PCAAdmin.labels.saved);
      } catch (err) {
        alert(err.message);
      } finally {
        setBusy(apply, false);
      }
    }
  });
});
