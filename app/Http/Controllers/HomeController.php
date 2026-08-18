<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Transaksi; // ✅ Pastikan ini ada
use App\Models\Jadwal;
use Carbon\Carbon;

class HomeController extends Controller
{
    public function index()
    {
        // Cek apakah jadwal tutup
        // $hariIni = Carbon::now()->isoFormat('dddd');
        // $jadwal = Jadwal::where('hari', $hariIni)->where('aktif', true)->first();
        // $jamSekarang = Carbon::now()->format('H:i');

        // $buka = $jadwal && $jamSekarang >= $jadwal->jam_buka && $jamSekarang < $jadwal->jam_tutup;

        // // Jika jadwal tutup, tampilkan halaman tutup
        // if (!$buka) {
        //     return response()->view('admin.jadwal.tutup');
        // }
        
        if (Auth::check()) {
            // Hitung jumlah transaksi yang belum selesai
            $unreadCount = Transaksi::where('id_user', Auth::user()->id)
                ->where('status', '!=', Transaksi::STATUS_SELESAI)
                ->count();

            // Simpan jumlah ke session
            session(['unread_count' => $unreadCount]);
        } else {
            session(['unread_count' => 0]);
        }

        // Pastikan tabel slides ada & ambil slide yang aktif
        \App\Http\Controllers\Admin\SlideController::ensureSlidesTableExists();
        $slides = \App\Models\Slide::where('aktif', 'Y')->orderBy('id', 'asc')->get();

        return view('home', compact('slides'));
    }
}