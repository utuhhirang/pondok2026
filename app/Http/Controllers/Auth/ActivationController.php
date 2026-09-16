<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use App\Mail\ActivationEmail;

class ActivationController extends Controller
{
    /**
     * Tampilkan form aktivasi OTP
     */
    public function showForm(Request $request)
    {
        $nik = $request->query('nik', session('activation_nik'));

        if ($nik) {
            $user = User::where('nik', $nik)->first();
            if ($user && $user->active == 1) {
                return redirect()->route('login')->with('swal', [
                    'title' => 'Akun Sudah Aktif',
                    'text' => 'Akun Anda sudah aktif sebelumnya. Silakan login.',
                    'icon' => 'info'
                ]);
            }
        }

        return view('auth.activate', compact('nik'));
    }

    /**
     * Proses aktivasi dengan kode OTP manual
     */
    public function activate(Request $request)
    {
        $request->validate([
            'nik' => 'required|numeric|digits:16|exists:users,nik',
            'otp' => 'required|numeric|digits:6',
        ], [
            'nik.required' => 'NIK wajib diisi.',
            'nik.digits' => 'NIK harus 16 digit.',
            'nik.exists' => 'NIK tidak terdaftar.',
            'otp.required' => 'Kode OTP wajib diisi.',
            'otp.digits' => 'Kode OTP harus 6 digit.',
        ]);

        $user = User::where('nik', $request->nik)->firstOrFail();

        if ($user->active == 1) {
            return redirect()->route('login')->with('swal', [
                'title' => 'Akun Sudah Aktif',
                'text' => 'Akun Anda sudah aktif sebelumnya. Silakan login.',
                'icon' => 'info'
            ]);
        }

        $expiresAt = $user->activation_code_expires_at ? Carbon::parse($user->activation_code_expires_at) : null;

        if ($user->activation_code !== $request->otp || !$expiresAt || $expiresAt->isPast()) {
            return back()->withInput()->with('swal', [
                'title' => 'Aktivasi Gagal',
                'text' => 'Kode OTP salah atau sudah kedaluwarsa. Silakan kirim ulang OTP ke email Anda.',
                'icon' => 'error'
            ]);
        }

        // Aktifkan user
        $user->update([
            'active' => 1,
            'activation_code' => null,
            'activation_code_expires_at' => null,
        ]);

        return redirect()->route('login')->with('swal', [
            'title' => 'Aktivasi Berhasil!',
            'text' => 'Akun Anda berhasil diaktifkan. Silakan login.',
            'icon' => 'success'
        ]);
    }

    /**
     * Kirim ulang kode OTP aktivasi ke email
     */
    public function resend(Request $request)
    {
        $request->validate([
            'nik' => 'required|numeric|digits:16|exists:users,nik',
        ], [
            'nik.required' => 'NIK wajib diisi untuk kirim ulang OTP.',
            'nik.exists' => 'NIK tidak terdaftar.',
        ]);

        $user = User::where('nik', $request->nik)->firstOrFail();

        if ($user->active == 1) {
            return redirect()->route('login')->with('swal', [
                'title' => 'Akun Sudah Aktif',
                'text' => 'Akun Anda sudah aktif. Silakan login.',
                'icon' => 'info'
            ]);
        }

        // Generate OTP Baru
        $otp = (string) rand(100000, 999999);
        $user->update([
            'activation_code' => $otp,
            'activation_code_expires_at' => now()->addMinutes(15),
        ]);

        // Kirim Email Aktivasi
        $activationUrl = route('activate.link', ['nik' => $user->nik, 'otp' => $otp]);
        try {
            Mail::to($user->email)->send(new ActivationEmail($user, $otp, $activationUrl));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Gagal mengirim ulang email aktivasi: ' . $e->getMessage());
        }

        return back()->with('swal', [
            'title' => 'OTP Terkirim!',
            'text' => 'Kode OTP dan tautan aktivasi baru telah dikirimkan ke email ' . $user->email . '.',
            'icon' => 'success'
        ])->with('activation_nik', $user->nik);
    }

    /**
     * Aktivasi akun langsung melalui link URL dari email
     */
    public function directActivate($nik, $otp)
    {
        $user = User::where('nik', $nik)->first();

        if (!$user) {
            return redirect()->route('login')->with('swal', [
                'title' => 'Error',
                'text' => 'Pengguna tidak ditemukan.',
                'icon' => 'error'
            ]);
        }

        if ($user->active == 1) {
            return redirect()->route('login')->with('swal', [
                'title' => 'Akun Sudah Aktif',
                'text' => 'Akun Anda sudah aktif. Silakan login.',
                'icon' => 'info'
            ]);
        }

        $expiresAt = $user->activation_code_expires_at ? Carbon::parse($user->activation_code_expires_at) : null;

        if ($user->activation_code !== $otp || !$expiresAt || $expiresAt->isPast()) {
            return redirect()->route('activate.form', ['nik' => $user->nik])->with('swal', [
                'title' => 'Tautan Kedaluwarsa',
                'text' => 'Tautan aktivasi salah atau sudah kedaluwarsa. Silakan minta kirim ulang OTP ke email Anda.',
                'icon' => 'error'
            ]);
        }

        // Aktifkan user
        $user->update([
            'active' => 1,
            'activation_code' => null,
            'activation_code_expires_at' => null,
        ]);

        return redirect()->route('login')->with('swal', [
            'title' => 'Aktivasi Berhasil!',
            'text' => 'Akun Anda telah diaktifkan secara otomatis. Silakan login.',
            'icon' => 'success'
        ]);
    }
}
