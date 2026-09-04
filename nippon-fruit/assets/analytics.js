(function () {
    'use strict';
    if (!window.NF_ANALYTICS || !Number(NF_ANALYTICS.enabled) || !NF_ANALYTICS.ajaxUrl) return;

    function randomId() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
    }
    function storedId(storage, key) {
        try {
            let value = storage.getItem(key);
            if (!value) { value = randomId(); storage.setItem(key, value); }
            return value;
        } catch (e) { return randomId(); }
    }
    const visitorId = storedId(window.localStorage, 'nf_analytics_visitor');
    const sessionId = storedId(window.sessionStorage, 'nf_analytics_session');
    const sent = new Set();
    function device() {
        const width = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
        if (width < 768) return 'mobile';
        if (width < 1100) return 'tablet';
        return 'desktop';
    }
    function send(event, extra, key) {
        if (key && sent.has(key)) return;
        if (key) sent.add(key);
        const body = new URLSearchParams(Object.assign({
            action: NF_ANALYTICS.action || 'nf_track_analytics',
            event: event,
            visitor_id: visitorId,
            session_id: sessionId,
            device: device()
        }, extra || {}));
        fetch(NF_ANALYTICS.ajaxUrl, {
            method: 'POST', credentials: 'same-origin', keepalive: true,
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: body.toString()
        }).catch(function () {});
    }

    if (NF_ANALYTICS.pageType === 'product' && Number(NF_ANALYTICS.postId)) {
        send('detail_view', {post_id: String(NF_ANALYTICS.postId), referrer: document.referrer || ''}, 'detail:' + NF_ANALYTICS.postId);
        document.addEventListener('click', function (event) {
            const link = event.target.closest('.nf-single-portals a[href],.nf-single-portal-choice-grid a[href],.nf-mobile-portal-buttons a[href]');
            if (!link) return;
            const href = String(link.href || '').toLowerCase();
            const text = String(link.textContent || '');
            let portal = 'other';
            if (href.includes('rakuten') || text.includes('楽天')) portal = 'rakuten';
            else if (href.includes('yahoo') || text.includes('Yahoo')) portal = 'yahoo';
            send('outbound_click', {post_id: String(NF_ANALYTICS.postId), portal: portal});
        });
        return;
    }

    send('catalog_view', {referrer: document.referrer || ''}, 'catalog');

    let observer;
    function observeCards() {
        const cards = document.querySelectorAll('.nf-catalog-card[data-nf-product-id]:not([data-nf-analytics-bound])');
        cards.forEach(function (card) {
            card.setAttribute('data-nf-analytics-bound', '1');
            observer.observe(card);
        });
    }
    observer = new IntersectionObserver(function (entries) {
        const ids = [];
        entries.forEach(function (entry) {
            if (!entry.isIntersecting || entry.intersectionRatio < 0.25) return;
            observer.unobserve(entry.target);
            const id = Number(entry.target.getAttribute('data-nf-product-id'));
            if (id && !sent.has('impression:' + id)) { sent.add('impression:' + id); ids.push(id); }
        });
        if (ids.length) send('product_impression', {product_ids: ids.join(',')});
    }, {threshold: [0.25]});
    observeCards();
    new MutationObserver(observeCards).observe(document.body, {childList: true, subtree: true});

    let lastFilter = '';
    function recordFilters() {
        const params = new URLSearchParams(location.search);
        const payload = {
            keyword: params.get('q') || params.get('s') || '',
            category: params.get('category') || params.get('subcategory') || params.get('type') || '',
            municipality: params.get('municipality') || ''
        };
        const signature = JSON.stringify(payload);
        if (signature === lastFilter || (!payload.keyword && !payload.category && !payload.municipality)) return;
        lastFilter = signature;
        send('filter_use', payload, 'filter:' + signature);
    }
    recordFilters();
    document.addEventListener('change', function () { window.setTimeout(recordFilters, 700); });
    document.addEventListener('click', function () { window.setTimeout(recordFilters, 900); });
    window.addEventListener('popstate', recordFilters);
}());
