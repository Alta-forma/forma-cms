<?php
class MediaRepo {
    public static function list(): array {
        if (!is_dir(UPLOADS_DIR)) {
            return [];
        }
        $files = [];
        foreach (scandir(UPLOADS_DIR) ?: [] as $f) {
            if ($f === '.' || $f === '..' || $f === '.gitkeep') {
                continue;
            }
            $path = UPLOADS_DIR . '/' . $f;
            if (!is_file($path)) {
                continue;
            }
            $files[] = [
                'filename' => $f,
                'size'     => filesize($path),
                'mtime'    => filemtime($path),
                'url'      => forma_uploads_web_url($f),
                'ext'      => strtolower(pathinfo($f, PATHINFO_EXTENSION)),
            ];
        }
        usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $files;
    }

    public static function saveUpload(array $file): array {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed');
        }
        $sec = Database::get()->getSetting('security');
        $max = (int)($sec['max_upload_size'] ?? 52428800);
        if (($file['size'] ?? 0) > $max) {
            throw new RuntimeException('File too large');
        }
        $name = basename($file['name'] ?? 'file');
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = $sec['allowed_upload_types'] ?? [];
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            throw new RuntimeException('File type not allowed: ' . $ext);
        }
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '-', pathinfo($name, PATHINFO_FILENAME)) ?? 'file';
        $destName = $safe . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
        if (!is_dir(UPLOADS_DIR)) {
            mkdir(UPLOADS_DIR, 0755, true);
        }
        if (class_exists('Htaccess')) {
            Htaccess::ensureUploadsHtaccess();
        }
        $dest = UPLOADS_DIR . '/' . $destName;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new RuntimeException('Could not store upload');
        }
        // Belt-and-suspenders: some SAPIs leave the moved file at a restrictive mode
        // (e.g. 0600) that Apache can't read when serving it directly. See self_agent_store_media().
        @chmod($dest, 0644);
        return [
            'filename' => $destName,
            'url'      => forma_uploads_web_url($destName),
            'size'     => filesize($dest),
        ];
    }

    public static function delete(string $filename): void {
        $filename = basename($filename);
        $path = UPLOADS_DIR . '/' . $filename;
        if (!is_file($path)) {
            throw new RuntimeException('File not found');
        }
        unlink($path);
    }

    public static function get(string $filename): ?array {
        $filename = basename($filename);
        $path = UPLOADS_DIR . '/' . $filename;
        if (!is_file($path)) {
            return null;
        }
        return [
            'filename' => $filename,
            'size'     => filesize($path),
            'mtime'    => filemtime($path),
            'url'      => forma_uploads_web_url($filename),
            'ext'      => strtolower(pathinfo($filename, PATHINFO_EXTENSION)),
            'path'     => $path,
        ];
    }

    public static function readText(string $filename): string {
        $file = self::get($filename);
        if (!$file) {
            throw new RuntimeException('File not found');
        }
        if (!self::isTextExt($file['ext'])) {
            throw new RuntimeException('Not a text file');
        }
        $raw = file_get_contents($file['path']);
        if ($raw === false) {
            throw new RuntimeException('Could not read file');
        }
        return $raw;
    }

    public static function writeText(string $filename, string $content): void {
        $file = self::get($filename);
        if (!$file) {
            throw new RuntimeException('File not found');
        }
        if (!self::isTextExt($file['ext'])) {
            throw new RuntimeException('Not a text file');
        }
        if (file_put_contents($file['path'], $content) === false) {
            throw new RuntimeException('Could not write file');
        }
    }

    public static function rename(string $oldFilename, string $newFilename): string {
        $oldFilename = basename($oldFilename);
        $newFilename = basename($newFilename);
        if ($oldFilename === '' || $newFilename === '') {
            throw new RuntimeException('Filename required');
        }
        $sec = Database::get()->getSetting('security');
        $allowed = $sec['allowed_upload_types'] ?? [];
        $ext = strtolower(pathinfo($newFilename, PATHINFO_EXTENSION));
        if (in_array($ext, ['php', 'phtml', 'phar', 'php5', 'php7'], true)) {
            throw new RuntimeException('Cannot rename to executable file type');
        }
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            throw new RuntimeException('File type not allowed: ' . $ext);
        }
        $oldPath = UPLOADS_DIR . '/' . $oldFilename;
        $newPath = UPLOADS_DIR . '/' . $newFilename;
        if (!is_file($oldPath)) {
            throw new RuntimeException('File not found');
        }
        if ($oldFilename !== $newFilename && is_file($newPath)) {
            throw new RuntimeException('A file with that name already exists');
        }
        if ($oldFilename !== $newFilename && !rename($oldPath, $newPath)) {
            throw new RuntimeException('Failed to rename file');
        }
        return $newFilename;
    }

    public static function isTextExt(string $ext): bool {
        return in_array(strtolower($ext), ['txt', 'md', 'html', 'htm', 'css', 'js', 'json', 'xml', 'csv', 'svg', 'rtf'], true);
    }

    public static function isImageExt(string $ext): bool {
        return in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'], true);
    }

    public static function isAudioExt(string $ext): bool {
        return in_array(strtolower($ext), ['mp3', 'm4a', 'wav', 'ogg'], true);
    }

    public static function isVideoExt(string $ext): bool {
        return in_array(strtolower($ext), ['mp4', 'webm', 'mov', 'avi'], true);
    }

    public static function iconFor(string $ext): string {
        $ext = strtolower($ext);
        if (self::isImageExt($ext)) {
            return 'fa-file-image';
        }
        if (self::isAudioExt($ext)) {
            return 'fa-file-audio';
        }
        if (self::isVideoExt($ext)) {
            return 'fa-file-video';
        }
        if (self::isTextExt($ext)) {
            return in_array($ext, ['js', 'css', 'json', 'xml', 'html', 'htm', 'svg'], true) ? 'fa-file-code' : 'fa-file-alt';
        }
        if ($ext === 'pdf') {
            return 'fa-file-pdf';
        }
        return 'fa-file';
    }
}
