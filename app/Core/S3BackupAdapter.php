<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Адаптер выгрузки резервных копий в облачные S3-хранилища (AWS / Yandex Object Storage / MinIO).
 */
final class S3BackupAdapter
{
    public static function upload(string $filePath): bool
    {
        $endpoint = Setting::get('s3_endpoint', '');
        $bucket = Setting::get('s3_bucket', '');
        $accessKey = Setting::get('s3_access_key', '');
        $secretKey = Setting::get('s3_secret_key', '');

        if ($endpoint === '' || $bucket === '' || $accessKey === '' || $secretKey === '' || !is_file($filePath)) {
            return false;
        }

        $fileName = basename($filePath);
        $url = rtrim($endpoint, '/') . '/' . $bucket . '/' . $fileName;
        $input = null;

        try {
            $date = gmdate('D, d M Y H:i:s T');
            $signature = base64_encode(hash_hmac('sha1', "PUT\n\napplication/zip\n{$date}\n/{$bucket}/{$fileName}", $secretKey, true));

            $headers = [
                'Date: ' . $date,
                'Content-Type: application/zip',
                'Authorization: AWS ' . $accessKey . ':' . $signature,
            ];

            $input = fopen($filePath, 'rb');
            if ($input === false) {
                return false;
            }
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_PUT => true,
                CURLOPT_INFILE => $input,
                CURLOPT_INFILESIZE => filesize($filePath),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            return $code >= 200 && $code < 300;
        } catch (\Throwable $e) {
            Logger::error('S3 Backup upload failed: ' . $e->getMessage());
            return false;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }
    }
}
