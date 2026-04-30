
// LifeLink - Shared JavaScript Utilities

const API_BASE = './api';

// API Client 
const api = {
    async request(endpoint, options = {}) {
        try {
            const res = await fetch(`${API_BASE}/${endpoint}`, {
                credentials: 'include',
                headers: { 'Content-Type': 'application/json', ...options.headers },
                ...options,
                body: options.body ? JSON.stringify(options.body) : undefined,
            });
            const data = await res.json();
            return data;
        } catch (err) {
            console.error('API Error:', err);
            return { success: false, message: 'Network error. Please try again.' };
        }
    },
    get: (ep) => api.request(ep),
    post: (ep, body) => api.request(ep, { method: 'POST', body }),
    put: (ep, body) => api.request(ep, { method: 'PUT', body }),
    delete: (ep) => api.request(ep, { method: 'DELETE' }),
};

// ---- Auth ----
const auth = {
    async login(email, password) {
        return api.post('auth.php?action=login', { email, password });
    },
    async register(data) {
        return api.post('auth.php?action=register', data);
    },
    async logout() {
        await api.post('auth.php?action=logout');
        localStorage.removeItem('ll_user');
        window.location.href = 'index.html';
    },
    async me() {
        const res = await api.get('auth.php?action=me');
        if (res.success) {
            localStorage.setItem('ll_user', JSON.stringify(res.user));
            return res.user;
        }
        return null;
    },
    getLocal() {
        try { return JSON.parse(localStorage.getItem('ll_user')); } catch { return null; }
    },
    async requireAuth(allowedRoles = []) {
        const user = await auth.me();
        if (!user) { window.location.href = 'login.html'; return null; }
        if (allowedRoles.length && !allowedRoles.includes(user.role)) {
            window.location.href = getDashboardUrl(user.role);
            return null;
        }
        return user;
    },
};

function getDashboardUrl(role) {
    const map = { donor: 'donor-dashboard.html', hospital: 'hospital-dashboard.html', admin: 'admin-panel.html', health_worker: 'health-worker.html' };
    return map[role] || 'index.html';
}

// ---- Toast Notifications ----
function toast(message, type = 'info', duration = 4000) {
    const colors = { success: '#2e7d32', error: '#B71C1C', info: '#1975d2', warning: '#f57c00' };
    const icons = { success: 'check_circle', error: 'error', info: 'info', warning: 'warning' };

    const el = document.createElement('div');
    el.style.cssText = `
        position:fixed;bottom:24px;right:24px;z-index:9999;
        background:${colors[type]};color:#fff;
        padding:14px 20px;border-radius:12px;
        font-family:'Inter',sans-serif;font-size:14px;font-weight:600;
        display:flex;align-items:center;gap:10px;
        box-shadow:0 8px 32px rgba(0,0,0,0.2);
        animation:slideUp 0.3s ease;max-width:360px;
    `;
    el.innerHTML = `<span class="material-symbols-outlined" style="font-size:20px">${icons[type]}</span><span>${message}</span>`;

    if (!document.querySelector('#toast-style')) {
        const style = document.createElement('style');
        style.id = 'toast-style';
        style.textContent = '@keyframes slideUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}';
        document.head.appendChild(style);
    }

    document.body.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity 0.3s'; setTimeout(() => el.remove(), 300); }, duration);
}

// ---- Loading Button ----
function setButtonLoading(btn, loading) {
    if (loading) {
        btn.dataset.originalText = btn.innerHTML;
        btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:18px;animation:spin 1s linear infinite">refresh</span> Loading...';
        btn.disabled = true;
        if (!document.querySelector('#spin-style')) {
            const s = document.createElement('style');
            s.id = 'spin-style';
            s.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
            document.head.appendChild(s);
        }
    } else {
        btn.innerHTML = btn.dataset.originalText || btn.innerHTML;
        btn.disabled = false;
    }
}

// ---- Format Helpers ----
const fmt = {
    date(str) {
        if (!str) return '—';
        return new Date(str).toLocaleDateString('en-GH', { year: 'numeric', month: 'short', day: 'numeric' });
    },
    time(str) {
        if (!str) return '—';
        const d = new Date(str);
        const diff = Date.now() - d;
        if (diff < 60000) return 'just now';
        if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`;
        if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`;
        return `${Math.floor(diff / 86400000)}d ago`;
    },
    mysqlDate(date) {
        const d = date instanceof Date ? date : new Date(date);
        if (Number.isNaN(d.getTime())) return null;
        return d.toISOString().slice(0, 19).replace('T', ' ');
    },
    urgencyBadge(urgency) {
        const classes = { critical: 'bg-red-600', urgent: 'bg-orange-500', standard: 'bg-blue-500' };
        return `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold text-white ${classes[urgency] || 'bg-gray-500'} uppercase tracking-wide">${urgency}</span>`;
    },
    statusBadge(status) {
        const map = {
            open: ['bg-red-100 text-red-700', 'Open'],
            matched: ['bg-yellow-100 text-yellow-700', 'Matched'],
            in_progress: ['bg-blue-100 text-blue-700', 'In Progress'],
            fulfilled: ['bg-green-100 text-green-700', 'Fulfilled'],
            cancelled: ['bg-gray-100 text-gray-600', 'Cancelled'],
        };
        const [cls, label] = map[status] || ['bg-gray-100 text-gray-600', status];
        return `<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold ${cls}">${label}</span>`;
    },
    bloodTypeBadge(type) {
        return `<span class="inline-flex items-center justify-center w-10 h-10 rounded-full bg-red-100 text-red-700 font-black text-sm border-2 border-red-200">${type}</span>`;
    },
};

// ---- Navbar Injection ----
function renderNavbar(user) {
    const nav = document.getElementById('app-navbar');
    if (!nav) return;
    nav.innerHTML = `
    <header class="sticky top-0 z-50 w-full bg-white/90 backdrop-blur-md border-b border-gray-100 shadow-sm">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16 items-center">
          <a href="index.html" class="flex items-center gap-2">
            <div class="bg-red-700 p-1.5 rounded-lg flex items-center justify-center">
              <span class="material-symbols-outlined text-white text-xl">bloodtype</span>
            </div>
            <span class="text-xl font-black tracking-tight text-gray-900">LifeLink</span>
          </a>
          <nav class="hidden md:flex items-center gap-6">
            ${user ? `
              <a href="${getDashboardUrl(user.role)}" class="text-sm font-semibold text-gray-600 hover:text-red-700 transition-colors">Dashboard</a>
              <a href="requests.html" class="text-sm font-semibold text-gray-600 hover:text-red-700 transition-colors">Blood Requests</a>
              <a href="notifications.html" class="text-sm font-semibold text-gray-600 hover:text-red-700 transition-colors flex items-center gap-1">
                <span class="material-symbols-outlined text-sm">notifications</span>Alerts
              </a>
              ${user.role === 'donor' ? '<a href="education.html" class="text-sm font-semibold text-gray-600 hover:text-red-700">Learn</a>' : ''}
            ` : `
              <a href="#how-it-works" class="text-sm font-semibold text-gray-600 hover:text-red-700">How it Works</a>
              <a href="requests.html" class="text-sm font-semibold text-gray-600 hover:text-red-700">Urgent Requests</a>
            `}
          </nav>
          <div class="flex items-center gap-3">
            ${user ? `
              <a href="notifications.html" class="relative p-2 text-gray-600 hover:text-red-700">
                <span class="material-symbols-outlined">notifications</span>
                <span id="notif-badge" class="absolute top-1 right-1 hidden h-4 w-4 rounded-full bg-red-600 text-white text-[10px] font-bold flex items-center justify-center"></span>
              </a>
              <div class="relative" id="user-menu-container">
                <button onclick="toggleUserMenu()" class="flex items-center gap-2 text-sm font-semibold text-gray-700 hover:text-red-700 px-3 py-2 rounded-lg hover:bg-gray-50">
                  <span class="material-symbols-outlined text-xl">account_circle</span>
                  <span class="hidden sm:block">${user.full_name.split(' ')[0]}</span>
                  <span class="material-symbols-outlined text-sm">expand_more</span>
                </button>
                <div id="user-menu" class="hidden absolute right-0 mt-2 w-52 bg-white rounded-xl shadow-xl border border-gray-100 py-2 z-50">
                  <div class="px-4 py-2 border-b border-gray-100">
                    <p class="font-bold text-sm text-gray-900">${user.full_name}</p>
                    <p class="text-xs text-gray-500 capitalize">${user.role.replace('_',' ')}</p>
                  </div>
                  <a href="profile.html" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                    <span class="material-symbols-outlined text-sm">manage_accounts</span>Profile & Settings
                  </a>
                  <button onclick="auth.logout()" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                    <span class="material-symbols-outlined text-sm">logout</span>Logout
                  </button>
                </div>
              </div>
            ` : `
              <a href="login.html" class="text-sm font-bold text-gray-700 hover:text-red-700 px-4 py-2">Login</a>
              <a href="register.html" class="bg-red-700 text-white px-5 py-2.5 rounded-lg font-bold text-sm shadow-lg shadow-red-700/20 hover:bg-red-800 transition-all">Register</a>
            `}
          </div>
        </div>
      </div>
    </header>`;
    loadNotifCount(user);
}

function toggleUserMenu() {
    document.getElementById('user-menu')?.classList.toggle('hidden');
}
document.addEventListener('click', (e) => {
    if (!e.target.closest('#user-menu-container')) {
        document.getElementById('user-menu')?.classList.add('hidden');
    }
});

async function loadNotifCount(user) {
    if (!user) return;
    const res = await api.get('admin.php?action=notifications&unread=1&limit=1');
    if (res.success && res.unread_count > 0) {
        const badge = document.getElementById('notif-badge');
        if (badge) { badge.textContent = res.unread_count > 9 ? '9+' : res.unread_count; badge.classList.remove('hidden'); }
    }
}

// ---- Page Init ----
async function initPage(requiredRoles = [], publicPage = false) {
    const user = await auth.me();
    if (!user && !publicPage) {
        window.location.href = 'login.html';
        return null;
    }
    if (user && requiredRoles.length && !requiredRoles.includes(user.role)) {
        window.location.href = getDashboardUrl(user.role);
        return null;
    }
    renderNavbar(user);
    return user;
}
