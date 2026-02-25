(function () {
    function toQuery(params) {
        var search = new URLSearchParams();
        Object.keys(params || {}).forEach(function (k) {
            var value = params[k];
            if (value !== undefined && value !== null && value !== '') {
                search.set(k, value);
            }
        });
        return search.toString();
    }

    var csrfToken = '';
    function setCsrfToken(token) {
        csrfToken = token || '';
    }

    async function get(action, params) {
        var query = toQuery(Object.assign({ action: action }, params || {}));
        var res = await fetch('api.php?' + query);
        return res.json();
    }

    async function post(action, body) {
        var headers = { 'Content-Type': 'application/json' };
        if (csrfToken) {
            headers['X-CSRF-Token'] = csrfToken;
        }
        var res = await fetch('api.php?action=' + encodeURIComponent(action), {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(body || {})
        });
        return res.json();
    }

    function formatMoney(value) {
        return 'R$ ' + Number(value || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatNumber(value) {
        return Number(value || 0).toLocaleString('pt-BR');
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    window.MyPharmApiClient = {
        setCsrfToken: setCsrfToken,
        get: get,
        post: post
    };

    window.MyPharmUtils = {
        formatMoney: formatMoney,
        formatNumber: formatNumber,
        escapeHtml: escapeHtml
    };
})();
