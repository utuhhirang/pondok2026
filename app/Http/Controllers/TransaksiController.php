<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Helpers\WhatsAppHelper;
use App\Models\Transaksi;
use App\Models\UserSyarat;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TransaksiController extends Controller
{
    /**
     * Tampilkan halaman tracking berdasarkan ID TRX.
     *
     * @param  string  $idTrx
     * @return \Illuminate\View\View
     */
    public function show($idTrx)
    {
        // Ambil data transaksi beserta relasi
        $transaksi = Transaksi::with([
            'user',           // relasi ke User
            'dokumen',        // relasi ke JenisPelayanan
            'pengambilan',    // relasi ke Pengambilan
            'files'           // relasi ke UserSyarat (foto)
        ])->findOrFail($idTrx);

        return view('tracking', compact('transaksi'));
    }

    /**
     * Tampilkan semua transaksi (opsional, untuk admin).
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $transaksi = Transaksi::with('user', 'dokumen', 'pengambilan')
                              ->latest()
                              ->paginate(10);

        return view('tracking.index', compact('transaksi'));
    }

    /**
     * Update status transaksi (opsional).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $idTrx
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateStatus(Request $request, $idTrx)
    {
        $request->validate([
            'status' => 'required|integer|in:1,2,3,4,5,6,7,8',
            'pesan_penolakan' => 'nullable|string|max:1000',
            'pesan_batal' => 'nullable|string|max:1000',
        ]);

        // ✅ Cari berdasarkan id_trx (bukan id)
        $transaksi = Transaksi::where('id_trx', $idTrx)->firstOrFail();

        $oldStatus = $transaksi->status;
        $newStatus = $request->status;

        $transaksi->status = $newStatus;

        // ✅ Sesuaikan logika dengan penjelasan Anda
        if ($newStatus == 2) { // Verifikasi
            $transaksi->tgl_respon = now();
        } 
        elseif ($newStatus == 3) { // Proses
            $transaksi->tgl_proses = now();
        } 
        elseif ($newStatus == 4) { // Selesai
            $transaksi->tgl_selesai = now();
        } 
        elseif ($newStatus == 5 || $newStatus == 6 || $newStatus == 7) { // Ditolak, Pengajuan Ulang, Komplain
            if ($newStatus == 5 && $request->filled('pesan_penolakan')) {
                $transaksi->pesan = $request->pesan_penolakan;
            }
        } 
        elseif ($newStatus == 8) { // Dibatalkan
            $transaksi->deleted_at = now(); // Soft delete
            if ($request->filled('pesan_batal')) {
                $transaksi->pesan = $request->pesan_batal;
            }
        }

        $transaksi->save(); // ✅ Ini akan memicu observer → simpan log

        // Kirim Notifikasi WA jika status adalah Proses (3), Selesai (4), Ditolak (5), atau Dibatalkan (8)
        if (in_array($newStatus, [3, 4, 5, 8])) {
            $reason = null;
            if ($newStatus == 5) {
                $reason = $request->pesan_penolakan ?: $transaksi->pesan;
            } elseif ($newStatus == 8) {
                $reason = $request->pesan_batal ?: $transaksi->pesan;
            }
            $this->sendStatusNotification($transaksi, $newStatus, $reason);
        }

        return redirect()->back()->with('success', 'Status berhasil diperbarui.');
    }

    /**
     * Mengirim notifikasi perubahan status transaksi via WhatsApp
     */
    protected function sendStatusNotification($transaksi, $status, $reason = null)
    {
        $transaksi->load('user', 'dokumen');
        $user = $transaksi->user;
        
        if (!$user || !$user->phone) {
            return;
        }

        $namaDokumen = $transaksi->dokumen ? $transaksi->dokumen->nama : 'Dokumen';
        $idTrx = $transaksi->id_trx;

        $message = '';
        if ($status == 3) {
            $message = "Halo *{$transaksi->nama}*,\n\nPermohonan layanan *{$namaDokumen}* Anda dengan ID Transaksi *{$idTrx}* saat ini sedang **DIPROSES** oleh petugas.\n\nSilakan pantau secara berkala status permohonan Anda melalui aplikasi.\nStatus **DIPROSES** dilakukan sesuai jam kerja Aktif, diluar jam kerja akan dikerjakan hari selanjutnya.\n\nTerima kasih.";
        } elseif ($status == 4) {
            $message = "Halo *{$transaksi->nama}*,\n\nPermohonan layanan *{$namaDokumen}* Anda dengan ID Transaksi *{$idTrx}* telah **SELESAI**.\n\nDokumen Anda sudah siap diambil/diterima.\nSilakan cek menu lacak, cek berkas di aplikasi.";
            if (!empty($reason)) {
                $message .= "\n\n*Pesan Petugas:*\n{$reason}";
            }
            $message .= "\n\nTerima kasih.";
        } elseif ($status == 5) {
            $message = "Halo *{$transaksi->nama}*,\n\nMohon maaf, permohonan layanan *{$namaDokumen}* Anda dengan ID Transaksi *{$idTrx}* statusnya **DITOLAK**.\n\n*Alasan Penolakan:*\n{$reason}\n\nSilakan lakukan perbaikan data dan lakukan pengajuan ulang dengan nomor transaksi *{$idTrx}* melalui aplikasi.\n\nTerima kasih.";
        } elseif ($status == 8) {
            $message = "Halo *{$transaksi->nama}*,\n\nMohon maaf, permohonan layanan *{$namaDokumen}* Anda dengan ID Transaksi *{$idTrx}* telah **DIBATALKAN**.\n\n*Alasan Pembatalan:*\n{$reason}\n\nTerima kasih.";
        }

        if (empty($message)) {
            return;
        }

        WhatsAppHelper::sendMessage($user->phone, $message);
    }

    public function konfirmasi(Request $request, $id)
    {
        // Cari transaksi berdasarkan id_trx (bukan ID primary key!)
        $transaksi = Transaksi::where('id_trx', $id)->first();

        if (!$transaksi) {
            return response()->json([
                'success' => false,
                'message' => 'Transaksi tidak ditemukan.'
            ], 404);
        }

        // Update data
        $updated = $transaksi->update([
            'konfirmasi' => 'Y',
            'tgl_konfirmasi' => now(),
        ]);

        if ($updated) {
            return response()->json([
                'success' => true,
                'message' => 'Konfirmasi berhasil disimpan.'
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan konfirmasi. Tidak ada perubahan data.'
            ], 500);
        }
    }

    public function submitRating(Request $request, $id_trx)
    {
        try {
            // Cari transaksi berdasarkan id_trx (bukan ID primary key!)
            $transaksi = Transaksi::where('id_trx', $id_trx)->first();

            if (!$transaksi) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaksi tidak ditemukan.'
                ], 404);
            }

            // Update data rating
            $transaksi->update([
                'rating' => $request->input('rating'), // 1,2,3,4
                'komentar_rating' => $request->input('comment') ?? null,
                'tgl_rating' => now(), // simpan tanggal penilaian
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Penilaian berhasil disimpan.'
            ]);

        } catch (\Exception $e) {
            \Log::error('Error submit rating: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan penilaian: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        // Validasi seperti biasa
        $request->validate([
            'nama' => 'required|string|max:100',
            'nik' => 'required|string|max:16',
            'id_dokumen' => 'required|integer|exists:jenis_pelayanan,id',
            'no_kk' => 'nullable|string|max:16',
            'pengambilan_id' => 'required|integer|exists:pengambilan,id',
            'isi_informasi' => 'required|string',
        ]);

        // ✅ Cek apakah mode edit
        if ($request->filled('mode') && $request->mode === 'edit' && $request->filled('trx_id')) {
            // Update transaksi lama
            $transaksi = Transaksi::where('id_trx', $request->trx_id)
                ->where('status', 5) // Pastikan masih status Ditolak
                ->firstOrFail();

            $transaksi->update([
                'nik' => $request->nik,
                'nama' => $request->nama,
                'no_kk' => $request->no_kk,
                'keterangan' => $request->isi_informasi,
                'id_dokumen' => $request->id_dokumen,
                'pengambilan_id' => $request->pengambilan_id,
                'status' => 6, // ✅ Ubah ke Pengajuan Ulang
            ]);

            // ✅ Hapus file lama yang ditandai
            if ($request->has('deleted_files')) {
                UserSyarat::whereIn('id', $request->deleted_files)->delete();
            }

            // Handle file upload
            if ($request->hasFile('file_pendukung')) {
                foreach ($request->file('file_pendukung') as $file) {
                    if ($file) {
                        $filename = time() . '_' . $file->getClientOriginalName();
                        $path = $file->storeAs('uploads', $filename, 'public');

                        UserSyarat::create([
                            'id_trx' => $transaksi->id_trx,
                            'file'   => $path,
                        ]);
                    }
                }
            }

            return redirect()->route('tracking.show', $transaksi->id_trx)
                ->with('success', 'Permohonan berhasil diajukan ulang!');
        }

        // Jika bukan mode edit → buat transaksi baru (logika lama)
        $transaksiBaru = new Transaksi();
        $transaksiBaru->id_dokumen = $request->id_dokumen;
        $transaksiBaru->nik = $request->nik;
        $transaksiBaru->nama = $request->nama;
        $transaksiBaru->no_kk = $request->no_kk;
        $transaksiBaru->tgl = now();
        $transaksiBaru->keterangan = $request->isi_informasi;
        $transaksiBaru->pengambilan_id = $request->pengambilan_id;
        $transaksiBaru->status = 1; // Baru

        // Handle file upload untuk transaksi baru
        if ($request->hasFile('file_pendukung')) {
            foreach ($request->file('file_pendukung') as $file) {
                if ($file) {
                    $filename = time() . '_' . $file->getClientOriginalName();
                    $path = $file->storeAs('uploads', $filename, 'public');
                    
                    UserSyarat::create([
                        'id_trx' => $transaksiBaru->id_trx,
                        'file'   => $path,
                    ]);
                }
            }
        }

        $transaksiBaru->save();

        return redirect()->route('tracking.show', $transaksiBaru->id_trx)
            ->with('success', 'Permohonan berhasil diajukan!');
    }

    public function cekStatus(Request $request)
    {
        // Validasi input
        $validator = Validator::make($request->all(), [
            'nik' => 'required|digits:16',
            'id_trx' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Format NIK atau ID Transaksi tidak valid.'
            ], 422);
        }

        // Cari data transaksi (bisa dicari berdasarkan NIK pemohon, KK, atau NIK akun pembuat transaksi)
        $transaksi = Transaksi::with('dokumen')
                          ->where('id_trx', $request->id_trx)
                          ->where(function($query) use ($request) {
                              $query->where('nik', $request->nik)
                                    ->orWhere('kk', $request->nik)
                                    ->orWhereHas('user', function($q) use ($request) {
                                        $q->where('nik', $request->nik);
                                    });
                          })
                          ->first();

        if (!$transaksi) {
            return response()->json([
                'success' => false,
                'message' => 'Data permohonan tidak ditemukan. Pastikan NIK dan Nomor Transaksi benar.'
            ]);
        }

        $rawLayanan = $transaksi->id_dokumen;
        $ids = is_array($rawLayanan) ? $rawLayanan : json_decode($rawLayanan, true);
        if (!is_array($ids)) {
            $ids = ($ids !== null && $ids !== '') ? [$ids] : [];
        }

        $layananMap = [
            '1' => 'Kartu Keluarga',
            '2' => 'KTP',
            '3' => 'KIA',
            '4' => 'Pindah Keluar',  
            '5' => 'Pindah Datang',
            '6' => 'Akta Kelahiran',                    
            '7' => 'Akta Kematian',
            '8' => 'Akta Perkawinan',   
            '9' => 'Akta Perceraian'                                    
        ];

        $names = [];
        foreach ($ids as $id) {
            $cleanId = trim((string)$id);
            if (isset($layananMap[$cleanId])) {
                $names[] = $layananMap[$cleanId];
            } else {
                $names[] = "Layanan ID: $cleanId";
            }
        }
        $namaLayanan = implode(', ', $names);

        $responseData = [
            'nik' => $transaksi->nik,
            'id_trx' => $transaksi->id_trx,
            'nama_layanan' => $namaLayanan,
            'status' => $transaksi->status,
            'created_at' => $transaksi->created_at,
            'tgl_selesai' => $transaksi->tgl_selesai,
            'tgl_proses' => $transaksi->tgl_proses,
            'pesan' => $transaksi->pesan,
        ];

        // Jika ditemukan, kembalikan data
        return response()->json([
            'success' => true,
            'transaksi' => $responseData
        ]);
    }
}