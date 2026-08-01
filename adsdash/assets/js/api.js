/* ============================================================
   AdsDash — api.js
   Centralized API Client for Backend Integration
   Backend developers can set API_BASE_URL to point to their API
   (e.g., Node.js / Laravel / Django / PHP API endpoints).
   ============================================================ */

const API_CONFIG = {
  // Change API_BASE_URL to your backend API route, e.g. 'https://api.yourdomain.com/v1'
  API_BASE_URL: '/api',
  USE_MOCK_FALLBACK: true // set to false when backend endpoints are connected
};

/**
  * Generic helper to perform API requests
  */
async function apiRequest(endpoint, method = 'GET', data = null) {
  const options = {
    method,
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    }
  };

  const token = localStorage.getItem('adsdash_token');
  if (token) {
    options.headers['Authorization'] = `Bearer ${token}`;
  }

  if (data && (method === 'POST' || method === 'PUT' || method === 'PATCH')) {
    options.body = JSON.stringify(data);
  }

  try {
    const response = await fetch(`${API_CONFIG.API_BASE_URL}${endpoint}`, options);
    if (!response.ok) {
      const errorData = await response.json().catch(() => ({}));
      throw new Error(errorData.message || `API Error: ${response.status} ${response.statusText}`);
    }
    return await response.json();
  } catch (error) {
    console.warn(`[AdsDash API] Call failed for ${endpoint}:`, error.message);
    if (API_CONFIG.USE_MOCK_FALLBACK) {
      return null; // Fallback to inline HTML/JS mock data
    }
    throw error;
  }
}

/**
  * Exported Backend API Methods
  */
const AdsDashAPI = {
  // Dashboard & Metrics
  getDashboardStats: () => apiRequest('/dashboard/stats'),

  // Quotations API
  getQuotations: (params) => apiRequest(`/quotations?${new URLSearchParams(params || {}).toString()}`),
  getQuotationById: (id) => apiRequest(`/quotations/${id}`),
  createQuotation: (data) => apiRequest('/quotations', 'POST', data),
  updateQuotationStatus: (id, status) => apiRequest(`/quotations/${id}/status`, 'PATCH', { status }),

  // Invoices API
  getInvoices: (params) => apiRequest(`/invoices?${new URLSearchParams(params || {}).toString()}`),
  getInvoiceById: (id) => apiRequest(`/invoices/${id}`),
  createInvoiceFromQuotation: (quotationId) => apiRequest('/invoices/from-quotation', 'POST', { quotationId }),
  updateInvoiceStatus: (id, status) => apiRequest(`/invoices/${id}/status`, 'PATCH', { status }),

  // Customers API
  getCustomers: () => apiRequest('/customers'),
  createCustomer: (data) => apiRequest('/customers', 'POST', data),

  // Payments API
  getPayments: () => apiRequest('/payments'),
  recordPayment: (data) => apiRequest('/payments', 'POST', data)
};

// Make available globally
window.AdsDashAPI = AdsDashAPI;
