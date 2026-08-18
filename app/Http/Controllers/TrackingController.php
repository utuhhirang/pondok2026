<?php

namespace App\Http\Controllers;

use App\Models\Transaksi;
use Illuminate\Support\Facades\Auth;

class TrackingController extends Controller
{
    public function index()
    {
        $userId = Auth::id();

        // Tampilkan data transaksi milik user yang login dari tabel Transaksi
        $orders = Transaksi::where('id_user', $userId)
            ->with(['dokumen', 'pengambilan'])
            ->latest()
            ->get();
        $isPedes = false;

        // Fungsi asli: Cek Jadwal Buka
        if (!jadwal_buka()) {
            return response()->view('admin.jadwal.tutup');
        }
        
        // Fungsi asli: Reset notifikasi
        session(['unread_count' => 0]);

        return view('tracking.index', compact('orders', 'isPedes'));
    }

    public function show($id_trx)
    {
        // Fungsi asli untuk Transaksi Umum
        $transaksi = Transaksi::with(['dokumen', 'pengambilan', 'files', 'user', 'userDokumen'])
            ->where('id_trx', $id_trx)
            ->first();

        if (!$transaksi) {
            abort(404, 'Transaksi dengan ID ' . $id_trx . ' tidak ditemukan.');
        }

        return view('tracking.show', compact('transaksi'));
    }
}