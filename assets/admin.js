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
    let payload;
    try { payload = await res.json(); }
    catch (e) { throw new Error(PCAAdmin.labels.error); }
    if (!payload.success) throw new Error(payload?.data?.message || PCAAdmin.labels.error);
    return payload.data;
  };

  const escapeHtml = value => String(value || '').replace(/[&<>"']/g, ch => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  }[ch]));

  const setBusy = (el, busy, text) => {
    if (!el) return;
    if (busy) {
      if (!el.dataset.oldHtml) el.dataset.oldHtml = el.innerHTML;
      el.disabled = true;
      el.classList.add('is-loading');
      el.innerHTML = '<span class="pca-spinner"></span>' + escapeHtml(text || PCAAdmin.labels.loading);
    } else {
      el.disabled = false;
      el.classList.remove('is-loading');
      el.innerHTML = el.dataset.oldHtml || el.innerHTML;
    }
  };

  const updateMarket = (market, data) => {
    const price = market.querySelector('.pca-source-price');
    const match = market.querySelector('.pca-source-match');
    const brandSmall = market.querySelector('.pca-market-brand small');

    if (brandSmall && data.score) brandSmall.textContent = data.score + '٪ تطبیق';

    if (data.formatted) {
      price.innerHTML =
        '<b>' + escapeHtml(data.formatted) + '</b>' +
        '<small>' + escapeHtml(data.checked_at ? 'بروزرسانی ' + data.checked_at : 'قیمت پیدا شد') + '</small>';
      market.classList.add('has-price');
      market.classList.remove('has-error');
    } else {
      price.innerHTML =
        '<b class="pca-price-empty">—</b>' +
        '<small>' + escapeHtml(data.message || 'قیمت از صفحه قابل استخراج نبود') + '</small>';
      market.classList.add('has-error');
    }

    if (data.url) {
      match.innerHTML =
        '<a href="' + escapeHtml(data.url) + '" target="_blank" rel="noopener">' +
          escapeHtml(data.title || 'محصول پیدا شده') +
        '</a>' +
        '<button type="button" class="pca-text-btn pca-refresh-source">بروزرسانی قیمت</button>';
    }
  };

  const renderSuggestions = (market, payload) => {
    const box = market.querySelector('.pca-suggestions');
    const source = market.dataset.source;
    const productId = market.closest('.pca-product-card').dataset.product;

    if (!payload.results || !payload.results.length) {
      box.innerHTML =
        '<div class="pca-suggest-empty">' +
          escapeHtml(PCAAdmin.labels.notFound) +
          ' <a href="' + escapeHtml(payload.search_url) + '" target="_blank" rel="noopener">جستجو در سایت منبع</a>' +
        '</div>';
      box.hidden = false;
      return;
    }

    box.innerHTML = payload.results.map((item, index) => (
      '<button type="button" class="pca-suggest-item" ' +
        'data-product="' + escapeHtml(productId) + '" ' +
        'data-source="' + escapeHtml(source) + '" ' +
        'data-url="' + escapeHtml(item.url) + '" ' +
        'data-title="' + escapeHtml(item.title) + '">' +
          '<span class="pca-suggest-rank">' + (index + 1) + '</span>' +
          '<span class="pca-suggest-copy">' +
            '<strong>' + escapeHtml(item.title) + '</strong>' +
            '<small>' + escapeHtml((item.score || 0) + '٪ شباهت') + '</small>' +
          '</span>' +
          '<span class="pca-suggest-select">انتخاب</span>' +
      '</button>'
    )).join('');
    box.hidden = false;
  };

  const searchMarket = async (market) => {
    const row = market.closest('.pca-product-card');
    const button = market.querySelector('.pca-search-source');

    market.classList.add('is-searching');
    setBusy(button, true, 'در حال جستجو');

    try {
      const data = await ajax('pca_search_product', {
        product_id: row.dataset.product,
        source: market.dataset.source
      });

      if (data.auto_match) {
        updateMarket(market, data.auto_match);
        const suggestions = market.querySelector('.pca-suggestions');
        suggestions.hidden = true;
      } else {
        renderSuggestions(market, data);
      }
    } catch (err) {
      market.classList.add('has-error');
      const box = market.querySelector('.pca-suggestions');
      box.innerHTML = '<div class="pca-suggest-error">' + escapeHtml(err.message) + '</div>';
      box.hidden = false;
    } finally {
      market.classList.remove('is-searching');
      setBusy(button, false);
    }
  };

  document.addEventListener('click', async e => {
    const searchOne = e.target.closest('.pca-search-source');
    if (searchOne) {
      e.preventDefault();
      await searchMarket(searchOne.closest('.pca-market'));
      return;
    }

    const searchAll = e.target.closest('.pca-search-all');
    if (searchAll) {
      e.preventDefault();
      const row = searchAll.closest('.pca-product-card');
      setBusy(searchAll, true, 'در حال مقایسه');
      row.classList.add('is-comparing');

      try {
        await Promise.all([...row.querySelectorAll('.pca-market')].map(searchMarket));
      } finally {
        row.classList.remove('is-comparing');
        setBusy(searchAll, false);
      }
      return;
    }

    const suggestion = e.target.closest('.pca-suggest-item');
    if (suggestion) {
      e.preventDefault();
      const market = suggestion.closest('.pca-market');
      suggestion.disabled = true;

      try {
        const data = await ajax('pca_select_match', {
          product_id: suggestion.dataset.product,
          source: suggestion.dataset.source,
          url: suggestion.dataset.url,
          title: suggestion.dataset.title
        });

        updateMarket(market, {
          ...data,
          url: suggestion.dataset.url,
          title: suggestion.dataset.title
        });
        market.querySelector('.pca-suggestions').hidden = true;
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
      const market = refresh.closest('.pca-market');
      const row = market.closest('.pca-product-card');

      setBusy(refresh, true, 'در حال بروزرسانی');
      try {
        const data = await ajax('pca_refresh_price', {
          product_id: row.dataset.product,
          source: market.dataset.source
        });

        const currentLink = market.querySelector('.pca-source-match a');
        updateMarket(market, {
          ...data,
          url: currentLink?.href || '',
          title: currentLink?.textContent || ''
        });
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
      const row = apply.closest('.pca-product-card');
      const input = row.querySelector('.pca-apply-price input');
      const value = input.value.trim();

      if (!value) {
        input.focus();
        return;
      }
      if (!confirm('قیمت این محصول در ووکامرس تغییر کند؟')) return;

      setBusy(apply, true, 'در حال ذخیره');
      try {
        const data = await ajax('pca_apply_price', {
          product_id: row.dataset.product,
          price: value
        });

        const price = row.querySelector('.pca-store-price strong');
        if (price) price.textContent = data.formatted;
        input.value = '';

        apply.dataset.oldHtml = '<span>✓</span> ذخیره شد';
        apply.innerHTML = apply.dataset.oldHtml;
        setTimeout(() => {
          apply.dataset.oldHtml = 'اعمال قیمت';
          apply.innerHTML = 'اعمال قیمت';
        }, 1800);
      } catch (err) {
        alert(err.message);
      } finally {
        setBusy(apply, false);
      }
    }
  });
});
