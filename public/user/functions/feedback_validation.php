<?php
declare(strict_types=1);

/** Validate both ratings without assigning a default score on the server. */
function permohonan_feedback_validate(array $input): array
{
    $type = $input['jenis_feedback'] ?? '';
    $message = $input['pesan'] ?? '';
    if (!is_string($type) || !in_array($type, ['tanggapan', 'aduan'], true) || !is_string($message)) {
        throw new InvalidArgumentException('Jenis feedback atau pesan tidak valid.');
    }
    $message = preg_replace('/^\s+|\s+$/u', '', $message);
    if ($message === null || mb_strlen($message) > 1000) {
        throw new InvalidArgumentException('Pesan maksimal 1.000 karakter.');
    }
    $rating = null;
    $serviceRating = null;
    if ($type === 'tanggapan') {
        foreach (['rating', 'service_rating'] as $key) {
            $value = $input[$key] ?? null;
            if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-5]$/D', (string)$value)) {
                throw new InvalidArgumentException('Pilih rating aplikasi dan pelayanan masing-masing 1 sampai 5 bintang.');
            }
        }
        $rating = (int)$input['rating'];
        $serviceRating = (int)$input['service_rating'];
    }
    if ($message === '' && ($type === 'aduan' || $rating <= 3 || $serviceRating <= 3)) {
        throw new InvalidArgumentException($type === 'aduan'
            ? 'Pesan aduan wajib diisi.'
            : 'Mohon ceritakan kendala Anda untuk rating 3 bintang atau kurang.');
    }
    return ['type'=>$type, 'rating'=>$rating, 'service_rating'=>$serviceRating, 'message'=>$message];
}