<?php
declare(strict_types=1);

final class Normalizers {
    // Nombres canónicos
    public const DIR3_OC = 'face_dir3_oc';
    public const DIR3_OG = 'face_dir3_og';
    public const DIR3_UT = 'face_dir3_ut';

    /** Normaliza una ficha de cliente a array con claves canónicas */
    public static function client(object|array $c): array {
        $a = is_object($c) ? json_decode(json_encode($c, JSON_PARTIAL_OUTPUT_ON_ERROR), true) : (array)$c;

        // entityType
        $et = strtolower(trim((string)($a['entityType'] ?? 'company')));
        $map = [
            'empresa' => 'company', 'company' => 'company',
            'freelancer' => 'freelancer', 'pf' => 'freelancer', 'persona' => 'freelancer', 'individual' => 'freelancer',
            'adm_publica' => 'public_admin', 'public_admin' => 'public_admin', 'administracion' => 'public_admin'
        ];
        $a['entityType'] = $map[$et] ?? 'company';

        // countryCode → ISO-3
        $cc = strtoupper(trim((string)($a['countryCode'] ?? '')));
        if ($cc === '' || $cc === 'ES') $cc = 'ESP';
        $a['countryCode'] = $cc;

        // NIF sin espacios/guiones
        if (isset($a['nif'])) $a['nif'] = strtoupper(preg_replace('/[\s-]+/', '', (string)$a['nif']));

        // Unificar DIR3 a claves canónicas
        $a[self::DIR3_OC] = strtoupper(trim((string)(
            $a[self::DIR3_OC] ?? $a['dir3_accounting'] ?? $a['dir3OC'] ?? $a['dir3_oc'] ?? $a['dir3Oc'] ?? ''
        )));
        $a[self::DIR3_OG] = strtoupper(trim((string)(
            $a[self::DIR3_OG] ?? $a['dir3_managing']   ?? $a['dir3OG'] ?? $a['dir3_og'] ?? $a['dir3Og'] ?? ''
        )));
        $a[self::DIR3_UT] = strtoupper(trim((string)(
            $a[self::DIR3_UT] ?? $a['dir3_processing'] ?? $a['dir3UT'] ?? $a['dir3_ut'] ?? $a['dir3Ut'] ?? ''
        )));

        return $a;
    }

    /** Devuelve OC/OG/UT ya normalizados (si faltan, cadenas vacías) */
    public static function dir3(array $client): array {
        return [
            'OC' => (string)($client[self::DIR3_OC] ?? ''),
            'OG' => (string)($client[self::DIR3_OG] ?? ''),
            'UT' => (string)($client[self::DIR3_UT] ?? ''),
        ];
    }
}

