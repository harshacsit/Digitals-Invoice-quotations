/* ============================================================
   AdsDash — api.js
   Centralized API Client for Backend PHP Integration
   ============================================================ */

// Production PHP backend URL — update this when your PHP backend is deployed live
const PRODUCTION_API_URL = 'https://YOUR-BACKEND-DOMAIN/api';

const API_CONFIG = {
  PRODUCTION_API_URL,

  // Base URL pointing to AdsDash PHP API directory
  // Automatically detects local development (XAMPP / CLI server) vs production (e.g. Vercel)
  API_BASE_URL: (function() {
    if (window.ADSDASH_API_URL) {
      return window.ADSDASH_API_URL;
    }
    const hostname = window.location.hostname;
    const isLocal = hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '' || hostname.includes('ngrok') || PRODUCTION_API_URL.includes('YOUR-BACKEND-DOMAIN');
    if (isLocal) {
      const path = window.location.pathname;
      const dir = path.substring(0, path.lastIndexOf('/'));
      return dir ? `${dir}/api` : '/api';
    }
    return PRODUCTION_API_URL;
  })(),
};

/**
 * Helper to construct fully qualified API endpoints
 */
function getApiUrl(endpoint) {
  if (!endpoint) return API_CONFIG.API_BASE_URL;
  if (endpoint.startsWith('http://') || endpoint.startsWith('https://')) {
    return endpoint;
  }
  const cleanEndpoint = endpoint.startsWith('/') ? endpoint : `/${endpoint}`;
  return `${API_CONFIG.API_BASE_URL}${cleanEndpoint}`;
}

/**
 * Core API Request Helper
 */
async function apiRequest(endpoint, method = 'GET', data = null) {
  const url = getApiUrl(endpoint);

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
        window.location.replace('login.html');
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
 * Strict Fail-Closed Authentication Verification against PHP Backend
 * Returns Promise<Object|null> - User object if authenticated, null if unauthenticated or error.
 */
async function verifySession() {
  const apiUrl = getApiUrl('/auth.php?action=check');
  console.log('[AdsDash Auth] API URL:', apiUrl);
  console.log('[AdsDash Auth] Checking session...');

  // Fail-Closed Guard: If API URL is not configured or uses default placeholder
  if (!API_CONFIG.API_BASE_URL || API_CONFIG.API_BASE_URL.includes('YOUR-BACKEND-DOMAIN')) {
    console.warn('[AdsDash Auth] Backend API URL is unconfigured or uses placeholder. Fail-closed to unauthenticated.');
    console.log('[AdsDash Auth] Authenticated: false');
    return null;
  }

  try {
    const response = await fetch(apiUrl, {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'include'
    });

    console.log('[AdsDash Auth] Response status:', response.status);

    // Fail-Closed: Only HTTP 200 OK is treated as potential success
    if (response.status !== 200) {
      console.log('[AdsDash Auth] Non-200 status code received. Fail-closed.');
      console.log('[AdsDash Auth] Authenticated: false');
      return null;
    }

    // Fail-Closed: Response must be valid JSON
    const data = await response.json().catch(() => null);
    if (!data || typeof data !== 'object') {
      console.log('[AdsDash Auth] Response is not valid JSON. Fail-closed.');
      console.log('[AdsDash Auth] Authenticated: false');
      return null;
    }

    // Fail-Closed: Response structure must indicate success and contain user object
    if (data.success === true && data.data && data.data.user && typeof data.data.user === 'object') {
      console.log('[AdsDash Auth] Authenticated: true');
      return data.data.user;
    }

    console.log('[AdsDash Auth] Session check response indicates unauthenticated. Fail-closed.');
    console.log('[AdsDash Auth] Authenticated: false');
    return null;
  } catch (err) {
    console.error('[AdsDash Auth] Network error during auth check:', err.message);
    console.log('[AdsDash Auth] Authenticated: false');
    return null;
  }
}

/**
 * Unified API Client
 */
const api = {
  getUrl: getApiUrl,
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
      console.log('[AdsDash Auth] Redirecting to login...');
      window.location.replace('login.html');
    }
  },
  check: () => apiRequest('/auth.php?action=check', 'GET'),
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
let authCheckInProgress = null;

async function initAuthGuard() {
  const isLoginPage = window.location.pathname.endsWith('login.html');
  const isRootPage = window.location.pathname === '/' || window.location.pathname.endsWith('/index.html');

  if (isLoginPage) {
    const user = await verifySession();
    if (user) {
      console.log('[AdsDash Auth] Already authenticated. Redirecting to dashboard.html');
      sessionStorage.setItem('adsdash_user', JSON.stringify(user));
      window.location.replace('dashboard.html');
    }
    return;
  }

  if (isRootPage) {
    // index.html handles root routing via its inline head script
    return;
  }

  // Hide body until authentication is verified to prevent rendering sensitive content
  if (document.body) {
    document.body.style.visibility = 'hidden';
  }

  if (authCheckInProgress) {
    return authCheckInProgress;
  }

  authCheckInProgress = (async () => {
    try {
      const user = await verifySession();
      if (user) {
        window.__adsdash_authenticated = true;
        sessionStorage.setItem('adsdash_user', JSON.stringify(user));

        if (document.body) {
          document.body.style.setProperty('visibility', 'visible', 'important');
          document.body.style.visibility = 'visible';
        }
        updateUIForUser(user);
      } else {
        redirectToLogin();
      }
    } catch (err) {
      redirectToLogin();
    } finally {
      authCheckInProgress = null;
    }
  })();

  return authCheckInProgress;
}

function redirectToLogin() {
  sessionStorage.removeItem('adsdash_user');
  window.__adsdash_authenticated = false;
  if (!window.location.pathname.endsWith('login.html')) {
    console.log('[AdsDash Auth] Redirecting to login...');
    const current = window.location.pathname + window.location.search;
    const isRoot = window.location.pathname === '/' || window.location.pathname.endsWith('/index.html');
    const redirectParam = (!isRoot && current && !current.endsWith('login.html'))
      ? '?redirect=' + encodeURIComponent(current)
      : '';
    window.location.replace('login.html' + redirectParam);
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

// Run auth guard immediately on load
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAuthGuard);
} else {
  initAuthGuard();
}

// Also re-run layout ready listener for dynamic element updates
document.addEventListener('adsdash:layoutReady', () => {
  const cached = sessionStorage.getItem('adsdash_user');
  if (cached) {
    try {
      updateUIForUser(JSON.parse(cached));
    } catch (e) {}
  }
});

// BFCache (back button) security guard
window.addEventListener('pageshow', (event) => {
  if (event.persisted || (window.performance && window.performance.navigation && window.performance.navigation.type === 2)) {
    initAuthGuard();
  }
});

// Expose globally
window.API_CONFIG = API_CONFIG;
window.API_BASE_URL = API_CONFIG.API_BASE_URL;
window.api = api;
window.AdsDashAPI = api;
window.verifySession = verifySession;
window.initAuthGuard = initAuthGuard;
