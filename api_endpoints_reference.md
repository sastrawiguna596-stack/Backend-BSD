# Referensi Endpoint API (Backend BSD After School Club)

Semua endpoint di bawah ini menggunakan base URL: `http://localhost:8000/api/v1` (atau sesuai konfigurasi domain backend Anda).

## 1. Public Routes (Tanpa Autentikasi)
Endpoint ini dapat diakses tanpa perlu mengirimkan Bearer token.

| Method | Endpoint | Deskripsi |
|---|---|---|
| `POST` | `/auth/login` | Login user (mengembalikan token) |
| `POST` | `/auth/register` | Registrasi user baru |
| `GET`  | `/announcements` | Mengambil daftar pengumuman |
| `POST` | `/payments/webhook` | Webhook untuk Payment Gateway |

---

## 2. Protected Routes (Memerlukan Autentikasi)
Semua endpoint di bawah ini wajib menyertakan header:
`Authorization: Bearer <token_anda>`

### A. Auth (Semua User)
| Method | Endpoint | Deskripsi |
|---|---|---|
| `GET`  | `/auth/me` | Mendapatkan data user yang sedang login |
| `POST` | `/auth/logout` | Logout (revoke token) |

### B. Manajemen & Master Data (Khusus Role: `owner` & `admin`)
Endpoint yang menggunakan *apiResource* memiliki 5 aksi standar: `GET` (Index), `POST` (Store), `GET /{id}` (Show), `PUT/PATCH /{id}` (Update), `DELETE /{id}` (Destroy).

| Kategori | Method | Endpoint | Deskripsi |
|---|---|---|---|
| Guru | `GET, POST, PUT, DELETE` | `/teachers` | CRUD Data Guru |
| Siswa | `GET, POST, PUT, DELETE` | `/students` | CRUD Data Siswa |
| Orang Tua | `GET, POST, PUT, DELETE` | `/parents` | CRUD Data Orang Tua |
| | `POST` | `/parents/{parent}/students/{student}` | Mengaitkan siswa ke orang tua |
| | `DELETE` | `/parents/{parent}/students/{student}` | Melepas kaitan siswa dari orang tua |
| Pengguna | `GET, POST, PUT, DELETE` | `/users` | CRUD Data Pengguna (User) |
| Kelas | `GET, POST, PUT, DELETE` | `/classrooms` | CRUD Data Ruang Kelas |
| | `POST` | `/classrooms/{classroom}/teachers` | Assign guru ke kelas |
| | `DELETE` | `/classrooms/{classroom}/teachers/{teacher}` | Hapus guru dari kelas |
| Hari Libur | `GET, POST, PUT, DELETE` | `/holidays` | CRUD Hari Libur |
| Inventaris | `GET, POST, PUT, DELETE` | `/inventories` | CRUD Data Inventaris/Barang |
| Rencana Bayar | `POST` | `/payment-plans/generate` | Generate tagihan untuk pendaftaran |
| | `GET, POST, PUT, DELETE` | `/payment-plans` | CRUD Rencana Pembayaran |
| Transaksi Tunai | `POST` | `/cash-transactions/{id}/confirm` | Verifikasi (Terima) pembayaran tunai |
| | `POST` | `/cash-transactions/{id}/reject` | Tolak pembayaran tunai |
| Pengumuman | `POST, PUT, DELETE` | `/announcements` | Buat/Update/Hapus pengumuman |

### C. Laporan Keuangan (Khusus Role: `owner` & `admin`)
| Method | Endpoint | Deskripsi |
|---|---|---|
| `GET` | `/finance/dashboard` | Data ringkasan keuangan |
| `GET` | `/finance/reports/income` | Laporan pendapatan |
| `GET` | `/finance/reports/outstanding` | Laporan tunggakan (belum bayar) |
| `GET` | `/finance/reports/reconciliation` | Laporan rekonsiliasi kas |

### D. Penggajian Guru (Khusus Role: `owner`)
| Method | Endpoint | Deskripsi |
|---|---|---|
| `POST` | `/teacher-payrolls/generate` | Generate slip gaji guru |
| `GET, POST, PUT, DELETE` | `/teacher-payrolls` | CRUD Penggajian |

### E. Akademik & Kelas (Semua Role yang Login)
| Method | Endpoint | Deskripsi |
|---|---|---|
| `GET, POST, PUT, DELETE` | `/programs` | CRUD Program Studi |
| `POST` | `/programs/{program}/levels` | Tambah level ke program |
| `PUT` | `/programs/{program}/levels/{level}` | Update level program |
| `DELETE` | `/programs/{program}/levels/{level}` | Hapus level dari program |
| `GET, POST, PUT, DELETE` | `/enrollments` | Pendaftaran kelas |
| `GET, POST, PUT, DELETE` | `/class-schedules` | Jadwal Kelas Utama |
| `POST` | `/class-schedules/{id}/generate-sessions`| Generate sesi pertemuan (sesi kelas) |
| `GET, POST, PUT, DELETE` | `/class-sessions` | Sesi Kelas (Pertemuan) |
| `POST` | `/class-sessions/{id}/reschedule` | Reschedule jadwal pertemuan |
| `POST` | `/class-sessions/{id}/submit-attendance` | Submit absensi & logbook harian |

### F. Evaluasi & Laporan Akademik (Semua Role yang Login)
| Method | Endpoint | Deskripsi |
|---|---|---|
| `GET, POST, PUT, DELETE` | `/student-attendances` | Absensi Siswa |
| `GET, POST, PUT, DELETE` | `/teacher-logbooks` | Logbook/Jurnal Guru |
| `GET, POST, PUT, DELETE` | `/assessments` | Penilaian Siswa |
| `GET, POST, PUT, DELETE` | `/final-reports` | Rapor Akhir |
| `GET, POST, PUT, DELETE` | `/certificates` | Sertifikat Kelulusan |

### G. Keuangan & Transaksi (Semua Role yang Login)
| Method | Endpoint | Deskripsi |
|---|---|---|
| `GET, POST, PUT, DELETE` | `/payments` | Data Pembayaran Digital |
| `POST` | `/payments/charge` | Melakukan pembayaran (Payment Gateway) |
| `GET` | `/payments/{payment}/status` | Cek status pembayaran (midtrans/dsb) |
| `GET` | `/cash-transactions` | List semua transaksi tunai yang disubmit |
| `GET` | `/cash-transactions/{id}` | Detail transaksi tunai |
| `POST` | `/cash-transactions/submit` | Submit bukti bayar tunai/transfer manual |

---

## 🚀 Instruksi Integrasi ke Front-End (Menggunakan Axios)

Berdasarkan riwayat pengerjaan Anda, pendekatan terbaik adalah membuat sebuah **Instance Axios** khusus yang mengatur *Base URL* dan *Header Authorization* secara otomatis.

### 1. Buat File Konfigurasi Axios
Buat file baru di front-end Anda, misalnya `src/utils/axios.js` atau `src/lib/axios.js` (jika menggunakan React/Vue).

```javascript
import axios from 'axios';

// Konfigurasi URL utama ke Backend Laravel
const api = axios.create({
    baseURL: 'http://localhost:8000/api/v1', // Sesuaikan jika berbeda
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
    }
});

// Interceptor Request: Otomatis sisipkan Token di setiap request
api.interceptors.request.use(
    (config) => {
        // Ambil token dari localStorage
        const token = localStorage.getItem('token');
        if (token) {
            config.headers.Authorization = `Bearer ${token}`;
        }
        return config;
    },
    (error) => {
        return Promise.reject(error);
    }
);

// Interceptor Response: Handle Error 401 (Unauthorized) otomatis
api.interceptors.response.use(
    (response) => {
        return response;
    },
    (error) => {
        if (error.response && error.response.status === 401) {
            // Jika token kadaluarsa atau tidak valid, paksa logout
            localStorage.removeItem('token');
            localStorage.removeItem('user');
            // Redirect ke halaman login
            window.location.href = '/login'; 
        }
        return Promise.reject(error);
    }
);

export default api;
```

### 2. Cara Menggunakan Endpoint di Halaman/Komponen
Import instance `api` yang sudah dibuat, lalu panggil endpoint yang diinginkan. Anda tidak perlu repot mengetik ulang `http://localhost:8000/api/v1` atau menyelipkan token lagi.

**Contoh: Halaman Login (Public Route)**
```javascript
import api from '../utils/axios';

const handleLogin = async (email, password) => {
    try {
        const response = await api.post('/auth/login', {
            email: email,
            password: password
        });
        
        // Simpan token dan data user ke localStorage
        localStorage.setItem('token', response.data.token);
        localStorage.setItem('user', JSON.stringify(response.data.user));
        
        alert('Login Sukses!');
    } catch (error) {
        console.error('Login gagal:', error.response?.data?.message);
    }
};
```

**Contoh: Mengambil Data Siswa (Protected Route - Method GET)**
```javascript
import api from '../utils/axios';

const fetchStudents = async () => {
    try {
        // Token otomatis dikirim lewat interceptor!
        const response = await api.get('/students');
        console.log('Daftar siswa:', response.data);
    } catch (error) {
        console.error('Gagal mengambil data siswa:', error);
    }
};
```

**Contoh: Tambah Jadwal Kelas (Protected Route - Method POST)**
```javascript
import api from '../utils/axios';

const createSchedule = async (scheduleData) => {
    try {
        const response = await api.post('/class-schedules', scheduleData);
        console.log('Jadwal berhasil dibuat!', response.data);
    } catch (error) {
        console.error('Gagal membuat jadwal:', error.response?.data);
    }
};
```
