/**
 * Contoh Helper API Client Frontend untuk Website Bimbingan Belajar
 * Menggunakan struktur slot dari `api_config.json` & Pembayaran Xendit Invoice.
 */

import apiConfig from './api_config.json' assert { type: 'json' };

const BASE_URL = apiConfig.environment.development.baseUrl;

// Helper dasar API Call
async function apiCall(endpointConfig, payload = null, token = null, urlParams = {}) {
  let url = `${BASE_URL}${endpointConfig.path}`;

  // Replace URL param seperti /payments/xendit/status/{external_id}
  Object.keys(urlParams).forEach(key => {
    url = url.replace(`{${key}}`, urlParams[key]);
  });

  const headers = { ...apiConfig.defaultHeaders };
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const options = {
    method: endpointConfig.method,
    headers: headers,
  };

  if (payload && (endpointConfig.method === 'POST' || endpointConfig.method === 'PUT')) {
    options.body = JSON.stringify(payload);
  }

  try {
    const response = await fetch(url, options);
    return await response.json();
  } catch (error) {
    console.error(`Gagal memanggil API (${url}):`, error);
    throw error;
  }
}

/* ==========================================================================
 * 1. CEK JADWAL & MONITORING BELAJAR
 * ========================================================================== */

// Mengambil Jadwal Les Siswa
export async function fetchMySchedule(token) {
  return await apiCall(apiConfig.endpoints.schedules.getStudentSchedule, null, token);
}

// Mengecek Riwayat Presensi / Kehadiran Siswa
export async function fetchAttendanceHistory(token) {
  return await apiCall(apiConfig.endpoints.monitoring.getAttendance, null, token);
}

// Mengecek Nilai Evaluasi & Catatan Tutor
export async function fetchStudentReports(token) {
  return await apiCall(apiConfig.endpoints.monitoring.getReports, null, token);
}

/* ==========================================================================
 * 2. PEMBAYARAN SPP / PAKET BIMBEL VIA XENDIT
 * ========================================================================== */

/**
 * Membuat Invoice Xendit & Redirect Siswa ke Halaman Pembayaran (VA/QRIS/E-Wallet)
 */
export async function payBimbelPackageWithXendit(packageId, payerEmail, description, userToken) {
  const payload = {
    package_id: packageId,
    payer_email: payerEmail,
    description: description || "Pembayaran SPP Bimbingan Belajar"
  };

  // 1. Panggil backend untuk buat invoice Xendit
  const res = await apiCall(apiConfig.endpoints.payments.createXenditInvoice, payload, userToken);

  if (res.success && res.data && res.data.invoice_url) {
    // 2. Redirect ke Halaman Pembayaran Xendit yang Aman (VA BCA/Mandiri/BRI, QRIS, OVO, Dana, DLL)
    window.location.href = res.data.invoice_url;
  } else {
    alert("Gagal membuat Invoice Pembayaran Xendit. Periksa koneksi backend.");
  }
}

/**
 * Mengecek Status Tagihan Pembayaran Xendit
 */
export async function checkXenditPaymentStatus(externalId, userToken) {
  return await apiCall(
    apiConfig.endpoints.payments.getInvoiceStatus,
    null,
    userToken,
    { external_id: externalId }
  );
}
