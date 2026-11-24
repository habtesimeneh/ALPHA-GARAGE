<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — VIDEO SERVICE
 * Comprehensive service layer for Video Gallery & Media Management.
 * Manages YouTube parsing, file uploads, thumbnail processing,
 * database persistence, view counting, and tamper-evident audit logging.
 */
final class VideoService
{
    public const CATEGORIES = [
        'Diagnostics',
        'Engine & Transmission',
        'Electrical & Battery',
        'Detailing & Ceramic',
        'Maintenance Tips',
        'Garage Showcase',
    ];

    public const ALLOWED_VIDEO_EXTS = ['mp4', 'webm', 'ogg', 'mov'];
    public const ALLOWED_VIDEO_MIMES = [
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/quicktime',
    ];
    public const MAX_VIDEO_BYTES = 52428800; // 50MB

    public const ALLOWED_THUMB_EXTS = ['jpg', 'jpeg', 'png', 'webp'];
    public const ALLOWED_THUMB_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];
    public const MAX_THUMB_BYTES = 5242880; // 5MB

    /**
     * Extracts and validates an 11-character YouTube video ID.
     */
    public static function extractYoutubeId(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $trimmed = trim($url);

        // Direct 11-char ID
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $trimmed)) {
            return $trimmed;
        }

        // Standard YouTube URL formats
        $pattern = '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?|shorts)\/|.*[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i';
        if (preg_match($pattern, $trimmed, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Generate an SEO-friendly unique slug.
     */
    public static function generateSlug(string $title, ?int $ignoreId = null): string
    {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'video-' . bin2hex(random_bytes(4));
        }

        $baseSlug = $slug;
        $counter = 1;

        while (true) {
            $query = 'SELECT id FROM videos WHERE slug = ?';
            $params = [$slug];
            if ($ignoreId !== null) {
                $query .= ' AND id != ?';
                $params[] = $ignoreId;
            }
            $exists = Database::one($query, $params);
            if (!$exists) {
                break;
            }
            $counter++;
            $slug = $baseSlug . '-' . $counter;
        }

        return $slug;
    }

    /**
     * Retrieve videos with filtering.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(bool $onlyPublished = false, ?string $category = null, string $search = ''): array
    {
        $sql = 'SELECT * FROM videos WHERE 1=1';
        $params = [];

        if ($onlyPublished) {
            $sql .= ' AND is_published = 1';
        }

        if ($category !== null && $category !== '' && $category !== 'all') {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }

        if (trim($search) !== '') {
            $sql .= ' AND (title LIKE ? OR description LIKE ?)';
            $wildcard = '%' . trim($search) . '%';
            $params[] = $wildcard;
            $params[] = $wildcard;
        }

        $sql .= ' ORDER BY sort_order ASC, created_at DESC';

        $rows = Database::all($sql, $params);

        return array_map([self::class, 'formatVideo'], $rows);
    }

    /**
     * Find single video by ID.
     */
    public static function find(int $id): ?array
    {
        $row = Database::one('SELECT * FROM videos WHERE id = ?', [$id]);
        return $row ? self::formatVideo($row) : null;
    }

    /**
     * Find published video by slug.
     */
    public static function findBySlug(string $slug): ?array
    {
        $row = Database::one('SELECT * FROM videos WHERE slug = ? AND is_published = 1', [$slug]);
        return $row ? self::formatVideo($row) : null;
    }

    /**
     * Enrich a video record with streaming, embed, and thumbnail URLs.
     */
    public static function formatVideo(array $row): array
    {
        $thumbUrl = null;
        if (!empty($row['thumbnail_file'])) {
            $thumbUrl = url('media-stream.php?type=thumbnail&file=' . rawurlencode($row['thumbnail_file']));
        } elseif (!empty($row['youtube_id'])) {
            $thumbUrl = 'https://img.youtube.com/vi/' . rawurlencode($row['youtube_id']) . '/hqdefault.jpg';
        }

        $streamUrl = null;
        if ($row['source_type'] === 'upload' && !empty($row['video_file'])) {
            $streamUrl = url('media-stream.php?type=video&file=' . rawurlencode($row['video_file']));
        }

        $embedUrl = null;
        if ($row['source_type'] === 'youtube' && !empty($row['youtube_id'])) {
            $embedUrl = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($row['youtube_id']) . '?autoplay=1&rel=0';
        }

        $row['thumbnail_url'] = $thumbUrl;
        $row['stream_url'] = $streamUrl;
        $row['embed_url'] = $embedUrl;

        return $row;
    }

    /**
     * Save (insert or update) a video record.
     *
     * @throws InvalidArgumentException
     */
    public static function save(
        ?int $id,
        array $data,
        ?array $videoUpload = null,
        ?array $thumbUpload = null,
        ?int $userId = null
    ): array {
        $title = trim($data['title'] ?? '');
        if ($title === '') {
            throw new InvalidArgumentException(t('Video title is required.', 'Video title is required.'));
        }

        $category = trim($data['category'] ?? 'Diagnostics');
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'Diagnostics';
        }

        $sourceType = ($data['source_type'] ?? 'youtube') === 'upload' ? 'upload' : 'youtube';
        $description = trim($data['description'] ?? '');
        $duration = trim($data['duration'] ?? '');
        $isPublished = !empty($data['is_published']) ? 1 : 0;
        $sortOrder = (int) ($data['sort_order'] ?? 0);

        $youtubeId = null;
        $youtubeUrl = null;

        $existing = $id ? self::find($id) : null;
        $videoFile = $existing['video_file'] ?? null;
        $thumbFile = $existing['thumbnail_file'] ?? null;

        if ($sourceType === 'youtube') {
            $rawUrl = trim($data['youtube_url'] ?? '');
            if ($rawUrl === '') {
                throw new InvalidArgumentException(t('YouTube URL is required for YouTube video source.', 'YouTube URL is required for YouTube video source.'));
            }
            $extractedId = self::extractYoutubeId($rawUrl);
            if (!$extractedId) {
                throw new InvalidArgumentException(t('Invalid YouTube URL or ID provided.', 'Invalid YouTube URL or ID provided.'));
            }
            $youtubeId = $extractedId;
            $youtubeUrl = 'https://www.youtube.com/watch?v=' . $extractedId;
        } else {
            // Uploaded video source
            if (!empty($videoUpload['tmp_name']) && is_uploaded_file($videoUpload['tmp_name'])) {
                $savedFile = self::processVideoUpload($videoUpload);
                // Clean up previous video if replaced
                if ($videoFile && $videoFile !== $savedFile) {
                    @unlink(storage_path('uploads/videos/' . $videoFile));
                }
                $videoFile = $savedFile;
            } elseif (!$id && empty($videoFile)) {
                throw new InvalidArgumentException(t('Please select a video file to upload.', 'Please select a video file to upload.'));
            }
        }

        // Process custom thumbnail upload if provided
        if (!empty($thumbUpload['tmp_name']) && is_uploaded_file($thumbUpload['tmp_name'])) {
            $savedThumb = self::processThumbnailUpload($thumbUpload);
            if ($thumbFile && $thumbFile !== $savedThumb) {
                @unlink(storage_path('uploads/thumbnails/' . $thumbFile));
            }
            $thumbFile = $savedThumb;
        }

        $slug = self::generateSlug($title, $id);

        if ($id && $existing) {
            Database::run(
                'UPDATE videos SET title = ?, slug = ?, description = ?, category = ?, source_type = ?,
                 youtube_id = ?, youtube_url = ?, video_file = ?, thumbnail_file = ?, duration = ?,
                 is_published = ?, sort_order = ? WHERE id = ?',
                [
                    $title,
                    $slug,
                    $description,
                    $category,
                    $sourceType,
                    $youtubeId,
                    $youtubeUrl,
                    $videoFile,
                    $thumbFile,
                    $duration,
                    $isPublished,
                    $sortOrder,
                    $id,
                ]
            );

            AuditLogger::log('update', 'videos', $id, $existing, [
                'title' => $title,
                'category' => $category,
                'source_type' => $sourceType,
                'is_published' => $isPublished,
            ]);

            return self::find($id);
        }

        $newId = (int) Database::insert(
            'INSERT INTO videos (title, slug, description, category, source_type, youtube_id,
             youtube_url, video_file, thumbnail_file, duration, is_published, sort_order, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $title,
                $slug,
                $description,
                $category,
                $sourceType,
                $youtubeId,
                $youtubeUrl,
                $videoFile,
                $thumbFile,
                $duration,
                $isPublished,
                $sortOrder,
                $userId,
            ]
        );

        AuditLogger::log('create', 'videos', $newId, null, [
            'title' => $title,
            'category' => $category,
            'source_type' => $sourceType,
            'is_published' => $isPublished,
        ]);

        return self::find($newId);
    }

    /**
     * Process and validate an uploaded video file.
     */
    private static function processVideoUpload(array $file): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(t('File upload failed with error code: ', 'File upload failed with error code: ') . (int) $file['error']);
        }

        if ($file['size'] > self::MAX_VIDEO_BYTES) {
            throw new InvalidArgumentException(t('Video file exceeds maximum allowable size (50MB).', 'Video file exceeds maximum allowable size (50MB).'));
        }

        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_VIDEO_EXTS, true)) {
            throw new InvalidArgumentException(t('Invalid video format. Supported: MP4, WebM, OGG, MOV.', 'Invalid video format. Supported: MP4, WebM, OGG, MOV.'));
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, self::ALLOWED_VIDEO_MIMES, true)) {
            throw new InvalidArgumentException(t('Disallowed MIME type for video: ', 'Disallowed MIME type for video: ') . htmlspecialchars((string) $mime));
        }

        $destDir = storage_path('uploads/videos');
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0750, true);
        }

        $newName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = $destDir . DIRECTORY_SEPARATOR . $newName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new RuntimeException(t('Failed to securely store uploaded video file.', 'Failed to securely store uploaded video file.'));
        }

        @chmod($destPath, 0640);
        return $newName;
    }

    /**
     * Process and validate an uploaded thumbnail image.
     */
    private static function processThumbnailUpload(array $file): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(t('Thumbnail upload error: ', 'Thumbnail upload error: ') . (int) $file['error']);
        }

        if ($file['size'] > self::MAX_THUMB_BYTES) {
            throw new InvalidArgumentException(t('Thumbnail image exceeds maximum size (5MB).', 'Thumbnail image exceeds maximum size (5MB).'));
        }

        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_THUMB_EXTS, true)) {
            throw new InvalidArgumentException(t('Invalid thumbnail format. Supported: JPG, PNG, WebP.', 'Invalid thumbnail format. Supported: JPG, PNG, WebP.'));
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!in_array($mime, self::ALLOWED_THUMB_MIMES, true)) {
            throw new InvalidArgumentException(t('Disallowed MIME type for thumbnail: ', 'Disallowed MIME type for thumbnail: ') . htmlspecialchars((string) $mime));
        }

        $destDir = storage_path('uploads/thumbnails');
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0750, true);
        }

        $newName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = $destDir . DIRECTORY_SEPARATOR . $newName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new RuntimeException(t('Failed to securely store uploaded thumbnail.', 'Failed to securely store uploaded thumbnail.'));
        }

        @chmod($destPath, 0640);
        return $newName;
    }

    /**
     * Toggle video publication status.
     */
    public static function togglePublish(int $id, ?int $userId = null): bool
    {
        $video = self::find($id);
        if (!$video) {
            return false;
        }

        $newStatus = $video['is_published'] ? 0 : 1;
        Database::run('UPDATE videos SET is_published = ? WHERE id = ?', [$newStatus, $id]);

        AuditLogger::log('update', 'videos', $id, ['is_published' => $video['is_published']], ['is_published' => $newStatus]);
        return true;
    }

    /**
     * Permanently delete a video and unlink associated media files.
     */
    public static function delete(int $id, ?int $userId = null): bool
    {
        $video = self::find($id);
        if (!$video) {
            return false;
        }

        if (!empty($video['video_file'])) {
            @unlink(storage_path('uploads/videos/' . $video['video_file']));
        }
        if (!empty($video['thumbnail_file'])) {
            @unlink(storage_path('uploads/thumbnails/' . $video['thumbnail_file']));
        }

        Database::run('DELETE FROM videos WHERE id = ?', [$id]);
        AuditLogger::log('delete', 'videos', $id, $video, null);
        return true;
    }

    /**
     * Safely increment view count.
     */
    public static function incrementViews(int $id): void
    {
        Database::run('UPDATE videos SET views_count = views_count + 1 WHERE id = ?', [$id]);
    }
}
