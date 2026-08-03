/* ============================================================
   AdsDash — api.js
   Centralized API Client for Backend PHP Integration
   ============================================================ */

const API_CONFIG = {
  // Base URL pointing to AdsDash PHP API directory
  API_BASE_URL: '/adsdash/api',
};

/**
 * Core API Request Helper
 */
async function apiRequest(endpoint, method = 'GET', data = null) {
  const url = endpoint.startsWith('http')
    ? endpoint
    : `${API_CONFIG.API_BASE_URL}${endpoint.startsWith('/') ? '' : '/'}${endpoint}`;

  const options = {
    method,
    headers: {
      'Accept': 'application/json',
    },
    // Crucial for PHP session cookie persistence
    credentials: 'include',
  };

  if (data && (method === 'POST' || method === 'PUT' || method === 'PATCH' || method === 'DELETE')) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(data);
  }

  try {
    const response = await fetch(url, options);

    // Global 401 Unauthorized Handling (Redirect to login)
    if (response.status === 401) {
      if (!window.location.pathname.endsWith('login.html')) {
        sessionStorage.removeItem('adsdash_user');
        window.location.href = 'login.html';
      }
      const errRes = await response.json().catch(() => ({}));
      throw new Error(errRes.message || 'Authentication required.');
    }

    // Global 403 Forbidden Handling
    if (response.status === 403) {
      const errRes = await response.json().catch(() => ({}));
      const msg = errRes.message || 'You do not have permission to perform this action.';
      alert(`[Access Denied] ${msg}`);
      throw new Error(msg);
    }

    const result = await response.json().catch(() => null);

    if (!response.ok) {
      const errorMsg = (result && result.message) ? result.message : `HTTP Error ${response.status}`;
      throw new Error(errorMsg);
    }

    return result;
  } catch (error) {
    console.error(`[AdsDash API Error] ${method} ${endpoint}:`, error.message);
    throw error;
  }
}

/**
 * Unified API Client
 */
const api = {
  get: (endpoint) => apiRequest(endpoint, 'GET'),
  post: (endpoint, data) => apiRequest(endpoint, 'POST', data),
  put: (endpoint, data) => apiRequest(endpoint, 'PUT', data),
  delete: (endpoint, data) => apiRequest(endpoint, 'DELETE', data),

  // Auth Methods
  login: (email, password) => apiRequest('/auth.php?action=login', 'POST', { email, password }),
  logout: async () => {
    try {
      await apiRequest('/auth.php?action=logout', 'POST');
    } catch (e) {
      // Ignore network logout errors
    } finally {
      sessionStorage.removeItem('adsdash_user');
      window.location.href = 'login.html';
    }
  },
  me: () => apiRequest('/auth.php?action=me', 'GET'),

  // Email API Methods
  sendQuotationEmail: (id, recipientEmail, recipientName) =>
    apiRequest('/email.php?action=quotation', 'POST', { id, recipient_email: recipientEmail, recipient_name: recipientName }),
  sendInvoiceEmail: (id, recipientEmail, recipientName) =>
    apiRequest('/email.php?action=invoice', 'POST', { id, recipient_email: recipientEmail, recipient_name: recipientName }),
  sendPaymentEmail: (id, recipientEmail, recipientName) =>
    apiRequest('/email.php?action=payment', 'POST', { id, recipient_email: recipientEmail, recipient_name: recipientName }),
  sendCampaignEmail: (id, recipientEmail, recipientName) =>
    apiRequest('/email.php?action=campaign', 'POST', { id, recipient_email: recipientEmail, recipient_name: recipientName }),
  sendSystemEmail: (data) =>
    apiRequest('/email.php?action=system', 'POST', data),

  getEmailLogs: (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(`/email.php?action=logs${query ? '&' + query : ''}`, 'GET');
  },
  getEmailLog: (id) =>
    apiRequest(`/email.php?action=log&id=${id}`, 'GET'),
  retryEmail: (id) =>
    apiRequest('/email.php?action=retry', 'POST', { id }),
  getEmailAnalytics: (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return apiRequest(`/email.php?action=analytics${query ? '&' + query : ''}`, 'GET');
  },
};

/**
 * Global Auth Guard & User State Initialization
 */
async function initAuthGuard() {
  // Skip auth guard on login page
  if (window.location.pathname.endsWith('login.html')) {
    return;
  }

  try {
    const res = await api.me();
    if (res && res.success && res.data && res.data.user) {
      const user = res.data.user;
      sessionStorage.setItem('adsdash_user', JSON.stringify(user));
      updateUIForUser(user);
    } else {
      window.location.href = 'login.html';
    }
  } catch (err) {
    if (!window.location.pathname.endsWith('login.html')) {
      window.location.href = 'login.html';
    }
  }
}

/**
 * Update Topbar User Chip & RBAC Visibility across Navigation
 */
function updateUIForUser(user) {
  if (!user) return;

  const userNameEl = document.getElementById('userName');
  const userRoleEl = document.getElementById('userRole');
  const userAvatarEl = document.getElementById('userAvatar');

  if (userNameEl) userNameEl.textContent = user.name || 'User';
  if (userRoleEl) userRoleEl.textContent = (user.role || 'staff').toUpperCase();
  if (userAvatarEl) {
    const initials = (user.name || 'AD')
      .split(' ')
      .map(n => n[0])
      .join('')
      .substring(0, 2)
      .toUpperCase();
    userAvatarEl.textContent = initials;
  }

  // Role-Based UI Element Hiding
  const role = user.role || 'staff';

  // User management nav link
  document.querySelectorAll('[data-nav="users"]').forEach(el => {
    if (role === 'staff') {
      el.style.display = 'none';
    } else {
      el.style.display = '';
    }
  });

  // Owner-only element hiding
  document.querySelectorAll('.owner-only').forEach(el => {
    if (role !== 'owner') {
      el.style.display = 'none';
    } else {
      el.style.display = '';
    }
  });

  // Manager/Owner element hiding
  document.querySelectorAll('.manager-only').forEach(el => {
    if (role === 'staff') {
      el.style.display = 'none';
    } else {
      el.style.display = '';
    }
  });
}

// Bind auth guard once layout/DOM is ready
document.addEventListener('adsdash:layoutReady', initAuthGuard);
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('sidebar-placeholder') === null) {
    initAuthGuard();
  }
});

// Expose globally
window.api = api;
window.AdsDashAPI = api;
