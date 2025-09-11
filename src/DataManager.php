<?php
// src/DataManager.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Gestor mínimo de entidades (clients, products, etc.) en XML plano.
 * - Directorio por entidad: data/<entityName>/
 * - Un fichero por item:    <id>.xml
 *
 * NOTAS:
 * - Compatibilidad con la API anterior (addItem/getAllItems/getItemById/updateItem/deleteItem).
 * - Escritura atómica y bloqueo para evitar corrupción bajo concurrencia.
 * - No se usan htmlspecialchars en claves/valores; se sanitiza el nombre de etiqueta.
 */
class DataManager {
    /** @var string */
    private $storagePath;
    /** @var string */
    private $entityName;

    /**
     * @param string $entityName  p.ej. 'clients' | 'products'
     * @param string|null $baseDir Base de datos local (por defecto data/)
     */
    public function __construct(string $entityName, ?string $baseDir = null) {
        $this->entityName = $entityName;
        $base = rtrim($baseDir ?? (__DIR__ . '/../data/'), '/');
        $this->storagePath = $base . '/' . $entityName . '/';
        $this->ensureDirectoryExists();
    }

    /** Asegura que existe el directorio de almacenamiento. */
    private function ensureDirectoryExists(): void {
        if (!is_dir($this->storagePath)) {
            if (!@mkdir($concurrentDirectory = $this->storagePath, 0775, true) && !is_dir($concurrentDirectory)) {
                throw new \RuntimeException("Error: No se pudo crear el directorio: {$this->storagePath}");
            }
        }
        @chmod($this->storagePath, 0775);
    }

    /**
     * Crea un item nuevo.
     * @param array<string, scalar|array|null> $data
     * @return array{success:bool, id?:string, message?:string}
     */
    public function addItem(array $data): array {
        $id = $this->generateId();
        $xml = new \SimpleXMLElement("<{$this->entityName}/>");
        $xml->addChild('id', $id);

        // No guardamos 'action'
        unset($data['action']);

        foreach ($data as $key => $value) {
            $this->upsertNode($xml, $key, $value);
        }

        $ok = $this->writeXmlAtomically($this->storagePath . $id . '.xml', $xml);
        return $ok
            ? ['success' => true, 'id' => $id]
            : ['success' => false, 'message' => 'No se pudo persistir el XML'];
    }

    /**
     * Devuelve todos los items como SimpleXMLElement[] (orden: más recientes primero).
     * @return array<int,\SimpleXMLElement>
     */
    public function getAllItems(): array {
        $files = glob($this->storagePath . '*.xml') ?: [];
        // Más recientes primero
        usort($files, static function(string $a, string $b): int {
            return filemtime($b) <=> filemtime($a);
        });

        $items = [];
        foreach ($files as $file) {
            $xmlObject = @simplexml_load_file($file);
            if ($xmlObject !== false) {
                $items[] = $xmlObject;
            }
        }
        return $items;
    }

    /**
     * Obtiene un item por id.
     * @param string $id
     * @return \SimpleXMLElement|null
     */
    public function getItemById(string $id): ?\SimpleXMLElement {
        $filePath = $this->storagePath . $id . '.xml';
        if (is_file($filePath)) {
            $xml = @simplexml_load_file($filePath);
            return ($xml !== false) ? $xml : null;
        }
        return null;
    }

    /**
     * Actualiza (o añade) campos de un item.
     * @param string $id
     * @param array<string, scalar|array|null> $data
     * @return array{success:bool, message?:string}
     */
    public function updateItem(string $id, array $data): array {
        $filePath = $this->storagePath . $id . '.xml';
        if (!is_file($filePath)) {
            return ['success' => false, 'message' => 'El item no existe.'];
        }

        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            return ['success' => false, 'message' => 'XML inválido o corrupto.'];
        }

        unset($data['action'], $data['id']);

        foreach ($data as $key => $value) {
            $this->upsertNode($xml, $key, $value);
        }

        $ok = $this->writeXmlAtomically($filePath, $xml);
        return $ok ? ['success' => true] : ['success' => false, 'message' => 'No se pudo persistir el XML'];
    }

    /**
     * Elimina un item.
     * @param string $id
     * @return array{success:bool, message?:string}
     */
    public function deleteItem(string $id): array {
        $filePath = $this->storagePath . $id . '.xml';
        if (!is_file($filePath)) {
            return ['success' => false, 'message' => 'El item no existe o no se pudo borrar.'];
        }
        if (@unlink($filePath)) {
            return ['success' => true];
        }
        return ['success' => false, 'message' => 'No se pudo borrar el fichero.'];
    }

    // ==================== Privados / utilidades ====================

    /** Genera un id estable y legible, similar al anterior. */
    private function generateId(): string {
        // uniqid ya incluye entropía; añadimos timestamp corto para ordenación humana (opcional)
        return uniqid($this->entityName . '_', true);
    }

    /**
     * Inserta/actualiza una clave en el XML, aceptando escalares o arrays.
     * - Sanitiza el nombre de etiqueta para ser válido en XML.
     * - Para arrays, crea nodos repetidos o árboles según sea asociativo o lista.
     *
     * @param \SimpleXMLElement $xml
     * @param string $key
     * @param mixed $value
     * @return void
     */
    private function upsertNode(\SimpleXMLElement $xml, string $key, $value): void {
        $tag = $this->sanitizeTagName($key);
        if ($tag === '' || $tag === 'id') {
            // No sobrescribir ID ni crear etiquetas vacías
            return;
        }

        if (is_array($value)) {
            // Si es array asociativo -> subnodos; si es lista -> nodos repetidos <tag>val</tag>
            if ($this->isAssoc($value)) {
                $parent = $xml->{$tag} ?? $xml->addChild($tag);
                foreach ($value as $k => $v) {
                    $this->upsertNode($parent, (string)$k, $v);
                }
            } else {
                // lista: <tag>item1</tag><tag>item2</tag>...
                // Para no duplicar si ya existe, borramos el nodo actual y reescribimos
                unset($xml->{$tag});
                foreach ($value as $v) {
                    $xml->addChild($tag, $this->scalarToString($v));
                }
            }
            return;
        }

        // Escalares / null
        $val = $this->scalarToString($value);

        // Si existe, se reemplaza; si no, se crea
        if (isset($xml->{$tag})) {
            // SimpleXMLElement maneja el escape internamente
            $xml->{$tag} = $val;
        } else {
            $xml->addChild($tag, $val);
        }
    }

    /** Convierte un escalar/null a string seguro. */
    private function scalarToString($v): string {
        if ($v === null) return '';
        if (is_bool($v)) return $v ? 'true' : 'false';
        // Convertimos a string plano; SimpleXML escapará lo necesario.
        return (string)$v;
    }

    /** ¿Es array asociativo? */
    private function isAssoc(array $arr): bool {
        if ([] === $arr) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Sanitiza el nombre de etiqueta a un nombre XML válido:
     * - Primera letra: A-Z | a-z | _
     * - Resto: A-Z | a-z | 0-9 | _ | -
     * - Reemplaza caracteres no válidos por '_'
     */
    private function sanitizeTagName(string $name): string {
        $name = trim($name);
        if ($name === '') return '';
        // Sustituimos espacios por _
        $name = preg_replace('/\s+/', '_', $name);
        // Si empieza por dígito o caracter no válido, prefijamos con _
        if (!preg_match('/^[A-Za-z_]/', $name)) {
            $name = '_' . $name;
        }
        // Resto de caracteres válidos
        $name = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name);
        return (string)$name;
    }

    /**
     * Escribe XML de forma atómica:
     * - Escribe a fichero temporal con LOCK_EX
     * - rename() sobre el destino
     * - Ajusta permisos
     */
    private function writeXmlAtomically(string $destPath, \SimpleXMLElement $xml): bool {
        $tmpPath = $destPath . '.tmp.' . bin2hex(random_bytes(4));
        $bytes   = $xml->asXML($tmpPath);
        if ($bytes === false) {
            @unlink($tmpPath);
            return false;
        }

        // Opcional: LOCK_EX en temp (no estrictamente necesario con rename atómico, pero ayuda a lectores que abran el .tmp)
        $ok = true;
        $fh = @fopen($tmpPath, 'rb');
        if ($fh) {
            @flock($fh, LOCK_EX);
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }

        if (!@rename($tmpPath, $destPath)) {
            // Si rename falla (ntfs/smb), intentamos copia + unlink
            $data = @file_get_contents($tmpPath);
            $ok = ($data !== false) && (@file_put_contents($destPath, $data, LOCK_EX) !== false);
            @unlink($tmpPath);
        }

        if ($ok) {
            @chmod($destPath, 0640);
        }
        return $ok;
    }
}

