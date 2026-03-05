(function () {
    const API_URL = 'api.php';

    function getThemeStorageKey() {
        const userName = (localStorage.getItem('userName') || '').trim().toLowerCase();
        return userName ? `mypharm_theme_${userName}` : 'mypharm_theme';
    }

    function getSavedThemeForCurrentUser() {
        const userKey = getThemeStorageKey();
        return localStorage.getItem(userKey) || localStorage.getItem('mypharm_theme');
    }

    function loadSavedTheme() {
        const saved = getSavedThemeForCurrentUser();
        if (saved) document.documentElement.setAttribute('data-theme', saved);
    }

    function toggleTheme() {
        const html = document.documentElement;
        const currentTheme = html.getAttribute('data-theme');
        const newTheme = currentTheme === 'light' ? 'dark' : 'light';
        html.setAttribute('data-theme', newTheme);
        localStorage.setItem(getThemeStorageKey(), newTheme);
        localStorage.setItem('mypharm_theme', newTheme);
    }

    async function fetchSession() {
        const r = await fetch(`${API_URL}?action=check_session`, { credentials: 'include' });
        return r.json();
    }

    function forceLogoutRedirect() {
        localStorage.clear();
        window.location.href = 'index.html';
    }

    function validateLocalAccess() {
        if (!localStorage.getItem('loggedIn')) return false;
        return (localStorage.getItem('userType') || '') === 'admin';
    }

    async function enforceAdminAccess() {
        if (!validateLocalAccess()) {
            forceLogoutRedirect();
            return null;
        }
        try {
            const session = await fetchSession();
            if (!session || !session.logged_in || session.tipo !== 'admin') {
                forceLogoutRedirect();
                return null;
            }
            return session;
        } catch (_) {
            forceLogoutRedirect();
            return null;
        }
    }

    function bindTabs() {
        const tabs = Array.from(document.querySelectorAll('.gc-tab'));
        const sections = Array.from(document.querySelectorAll('.gc-section'));
        if (!tabs.length || !sections.length) return;

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                const target = tab.getAttribute('data-section');
                tabs.forEach(t => t.classList.remove('active'));
                sections.forEach(s => s.classList.remove('active'));
                tab.classList.add('active');
                const section = document.getElementById(`gc-section-${target}`);
                if (section) section.classList.add('active');
            });
        });
    }

    function bindUi(session) {
        const nome = (session && session.nome) || localStorage.getItem('userName') || 'Administrador';
        const nomeEl = document.getElementById('gcUserName');
        if (nomeEl) nomeEl.textContent = nome;
        const avatarEl = document.getElementById('gcAvatar');
        if (avatarEl) avatarEl.textContent = (nome || 'A').charAt(0).toUpperCase();

        const logoutBtn = document.getElementById('gcLogoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function () {
                forceLogoutRedirect();
            });
        }

        const themeBtn = document.getElementById('gcThemeBtn');
        if (themeBtn) themeBtn.addEventListener('click', toggleTheme);

        const backBtn = document.getElementById('gcBackBtn');
        if (backBtn) {
            backBtn.addEventListener('click', function () {
                window.location.href = 'index.html';
            });
        }

        bindTabs();
    }

    document.addEventListener('DOMContentLoaded', async function () {
        loadSavedTheme();
        const session = await enforceAdminAccess();
        if (!session) return;
        const app = document.getElementById('gcApp');
        if (app) app.style.display = 'flex';
        bindUi(session);
    });
})();
