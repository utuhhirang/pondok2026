<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppHelper
{
    /**
     * Kirim pesan WhatsApp melalui API Gateway
     *
     * @param string $phone Nomor WhatsApp penerima
     * @param string $message Pesan yang akan dikirim
     * @return array Status pengiriman ['success' => bool, 'status' => int, 'response' => mixed, 'error' => string|null]
     */
    public static function sendMessage($phone, $message)
    {
        // 1. Format nomor telepon ke format internasional (62)
        $formattedPhone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($formattedPhone, '0')) {
            $formattedPhone = '62' . substr($formattedPhone, 1);
        }

        // 2. Normalisasi pesan agar mendukung newline (\n) dan format WhatsApp (*bold*, _italic_, ~strikethrough~, dll)
        $formattedMessage = str_replace(["\r\n", "\r", '\n'], "\n", $message);

        // 3. Tentukan mode pengiriman berdasarkan WHATSAPP_USE (local | fonnte)
        $use = env('WHATSAPP_USE', 'local');

        if ($use === 'fonnte') {
            // --- Mode Fonnte ---
            $url   = env('WHATSAPP_URL_FONNTE', 'https://api.fonnte.com/send');
            $token = env('WHATSAPP_FONNTE_TOKEN');

            Log::info("Mencoba mengirim WA (Fonnte) ke: {$formattedPhone} | URL: {$url}");

            try {
                $response = Http::withHeaders([
                    'Authorization' => $token,
                ])->timeout(15)->post($url, [
                    'target'  => $formattedPhone,
                    'message' => $formattedMessage,
                ]);
            } catch (\Exception $e) {
                Log::error("Gagal mengirim WhatsApp via Fonnte (Exception): " . $e->getMessage());
                return [
                    'success'  => false,
                    'status'   => 500,
                    'response' => null,
                    'error'    => $e->getMessage(),
                ];
            }
        } else {
            // --- Mode Local ---
            $url     = env('WHATSAPP_URL_LOCAL');
            $token   = config('services.whatsapp.token');
            $referal = config('services.whatsapp.referal');

            Log::info("Mencoba mengirim WA (Local) ke: {$formattedPhone} | URL: {$url}");

            try {
                $response = Http::withHeaders([
                    'x-api-key' => $token,
                ])->timeout(15)->post($url, [
                    'number'  => $formattedPhone,
                    'message' => $formattedMessage,
                    'referal' => $referal,
                ]);
            } catch (\Exception $e) {
                Log::error("Gagal mengirim WhatsApp via Local (Exception): " . $e->getMessage());
                return [
                    'success'  => false,
                    'status'   => 500,
                    'response' => null,
                    'error'    => $e->getMessage(),
                ];
            }
        }

        // 4. Proses hasil response
        $statusCode = $response->status();
        $decoded    = $response->json();

        if ($response->successful()) {
            Log::info("Respon WhatsApp Sukses ({$statusCode}): " . $response->body());
            return [
                'success'  => true,
                'status'   => $statusCode,
                'response' => $decoded ?: $response->body(),
                'error'    => null,
            ];
        } else {
            Log::error("Respon WhatsApp Gagal ({$statusCode}): " . $response->body());
            return [
                'success'  => false,
                'status'   => $statusCode,
                'response' => $decoded ?: $response->body(),
                'error'    => "HTTP Error Code: {$statusCode}",
            ];
        }
    }
}
