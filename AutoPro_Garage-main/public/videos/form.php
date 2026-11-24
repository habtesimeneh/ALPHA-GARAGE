<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — VIDEO CREATE & EDIT FORM
 * Allows administrators to add/edit YouTube links or upload video files,
 * assign categories, upload custom thumbnails, and configure publication state.
 */

require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';

$user = require_login();
require_permission('videos.manage');

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$video = $id ? VideoService::find($id) : null;

if ($id && !$video) {
    abort(404, 'Video not found.');
}

$isEdit = $video !== null;
$errors = [];

if (is_post()) {
    verify_csrf();

    $videoUpload = !empty($_FILES['video_file']['name']) ? $_FILES['video_file'] : null;
    $thumbUpload = !empty($_FILES['thumbnail_file']['name']) ? $_FILES['thumbnail_file'] : null;

    try {
        $saved = VideoService::save(
            $id,
            $_POST,
            $videoUpload,
            $thumbUpload,
            (int) $user['id']
        );

        flash('success', $isEdit
            ? t('Video updated successfully.', 'Video updated successfully.')
            : t('Video created successfully.', 'Video created successfully.'));
        redirect('videos/index.php');
    } catch (InvalidArgumentException|RuntimeException $e) {
        $errors['general'] = $e->getMessage();
    }
}

$currentSource = $_POST['source_type'] ?? ($video['source_type'] ?? 'youtube');
$categories = VideoService::CATEGORIES;

ui_header($isEdit ? t('Edit Video', 'Edit Video') : t('Add Video', 'Add Video'), 'videos');

page_header(
    $isEdit ? t('Edit Video', 'Edit Video') : t('Add Video', 'Add Video'),
    $isEdit
        ? t('Modify video metadata, replace media asset, or adjust publication status', 'Modify video metadata, replace media asset, or adjust publication status')
        : t('Integrate a new YouTube walkthrough or upload a high-definition workshop video', 'Integrate a new YouTube walkthrough or upload a high-definition workshop video'),
    '<a class="btn btn-ghost" href="' . e(url('videos/index.php')) . '">&larr; ' . e(t('Back to Videos', 'Back to Videos')) . '</a>'
);
?>

<div class="card reveal-up" style="max-width:880px;margin:0 auto;">
  <?php if (!empty($errors['general'])): ?>
    <div class="alert alert-danger mb-4" role="alert">
      <?= e($errors['general']) ?>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" id="video-form" novalidate>
    <?= csrf_field() ?>

    <!-- Source Type Selection -->
    <div class="field mb-4">
      <label class="form-label" style="font-weight:700;display:block;margin-bottom:0.5rem;">
        <?= e(t('Video Source', 'Video Source')) ?> <span class="req">*</span>
      </label>
      <div style="display:flex;gap:1.5rem;align-items:center;">
        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
          <input type="radio" name="source_type" value="youtube" <?= $currentSource === 'youtube' ? 'checked' : '' ?>>
          <span><strong>YouTube Embed</strong> &mdash; <small class="muted"><?= e(t('Paste YouTube watch / shorts / embed URL', 'Paste YouTube watch / shorts / embed URL')) ?></small></span>
        </label>
        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
          <input type="radio" name="source_type" value="upload" <?= $currentSource === 'upload' ? 'checked' : '' ?>>
          <span><strong>Local Video Upload</strong> &mdash; <small class="muted"><?= e(t('MP4, WebM, OGG, MOV (Up to 50MB)', 'MP4, WebM, OGG, MOV (Up to 50MB)')) ?></small></span>
        </label>
      </div>
    </div>

    <!-- YouTube Input Box -->
    <div id="youtube-box" style="<?= $currentSource === 'youtube' ? '' : 'display:none;' ?>" class="mb-4">
      <div class="field">
        <label for="f_youtube_url"><?= e(t('YouTube URL or Video ID', 'YouTube URL or Video ID')) ?> <span class="req">*</span></label>
        <input class="input" id="f_youtube_url" name="youtube_url" type="url"
               placeholder="https://www.youtube.com/watch?v=..."
               value="<?= e($_POST['youtube_url'] ?? ($video['youtube_url'] ?? '')) ?>">
        <small class="help"><?= e(t('Supports youtube.com/watch?v=..., youtu.be/..., shorts/..., or direct 11-char ID.', 'Supports youtube.com/watch?v=..., youtu.be/..., shorts/..., or direct 11-char ID.')) ?></small>
      </div>

      <!-- Live YouTube Preview Container -->
      <div id="yt-preview-wrap" style="display:none;margin-top:0.75rem;padding:0.75rem;background:#06140d;border:1px solid rgba(212,175,55,0.3);border-radius:8px;align-items:center;gap:1rem;">
        <img id="yt-preview-thumb" src="" alt="Thumbnail preview" style="width:120px;aspect-ratio:16/9;object-fit:cover;border-radius:4px;">
        <div>
          <div style="color:#d4af37;font-weight:600;font-size:0.9rem;">&#10004; Valid YouTube Video Detected</div>
          <small class="muted">Video ID: <code id="yt-preview-id"></code></small>
        </div>
      </div>
    </div>

    <!-- Upload Input Box -->
    <div id="upload-box" style="<?= $currentSource === 'upload' ? '' : 'display:none;' ?>" class="mb-4">
      <div class="field">
        <label for="f_video_file">
          <?= e(t('Upload Video File', 'Upload Video File')) ?>
          <?= !$isEdit ? '<span class="req">*</span>' : '' ?>
        </label>
        <input class="input" id="f_video_file" name="video_file" type="file" accept="video/mp4,video/webm,video/ogg,video/quicktime">
        <small class="help"><?= e(t('Supported formats: MP4, WebM, OGG, MOV. Maximum file size: 50MB.', 'Supported formats: MP4, WebM, OGG, MOV. Maximum file size: 50MB.')) ?></small>
      </div>

      <?php if (!empty($video['video_file'])): ?>
        <div style="margin-top:0.75rem;padding:0.75rem;background:#06140d;border:1px solid rgba(212,175,55,0.3);border-radius:8px;">
          <div style="font-size:0.85rem;color:var(--gold,#d4af37);margin-bottom:0.35rem;">
            <?= e(t('Current stored video file:', 'Current stored video file:')) ?> <code><?= e($video['video_file']) ?></code>
          </div>
          <video controls style="max-width:320px;aspect-ratio:16/9;border-radius:4px;display:block;">
            <source src="<?= e($video['stream_url'] ?? '') ?>">
          </video>
        </div>
      <?php endif; ?>
    </div>

    <!-- Video Title -->
    <div class="field mb-4">
      <label for="f_title"><?= e(t('Video Title', 'Video Title')) ?> <span class="req">*</span></label>
      <input class="input" id="f_title" name="title" type="text" maxlength="255" required
             placeholder="<?= e(t('e.g. Advanced ECU Diagnostics & Calibration Workflow', 'e.g. Advanced ECU Diagnostics & Calibration Workflow')) ?>"
             value="<?= e($_POST['title'] ?? ($video['title'] ?? '')) ?>">
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;" class="mb-4">
      <!-- Category -->
      <div class="field">
        <label for="f_category"><?= e(t('Category', 'Category')) ?> <span class="req">*</span></label>
        <select class="input" id="f_category" name="category">
          <?php foreach ($categories as $cat): ?>
            <?php $sel = ($_POST['category'] ?? ($video['category'] ?? 'Diagnostics')) === $cat ? 'selected' : ''; ?>
            <option value="<?= e($cat) ?>" <?= $sel ?>><?= e(t($cat, $cat)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Duration -->
      <div class="field">
        <label for="f_duration"><?= e(t('Duration (e.g. 08:45)', 'Duration (e.g. 08:45)')) ?></label>
        <input class="input" id="f_duration" name="duration" type="text" maxlength="32"
               placeholder="08:45"
               value="<?= e($_POST['duration'] ?? ($video['duration'] ?? '')) ?>">
      </div>
    </div>

    <!-- Optional Custom Thumbnail File -->
    <div class="field mb-4">
      <label for="f_thumbnail_file"><?= e(t('Custom Thumbnail Image (Optional)', 'Custom Thumbnail Image (Optional)')) ?></label>
      <input class="input" id="f_thumbnail_file" name="thumbnail_file" type="file" accept="image/jpeg,image/png,image/webp">
      <small class="help"><?= e(t('Upload a custom cover image (JPG, PNG, WebP up to 5MB). If left empty for YouTube, YouTube thumbnail is used automatically.', 'Upload a custom cover image (JPG, PNG, WebP up to 5MB). If left empty for YouTube, YouTube thumbnail is used automatically.')) ?></small>

      <?php if (!empty($video['thumbnail_file'])): ?>
        <div style="margin-top:0.5rem;display:flex;align-items:center;gap:0.75rem;">
          <img src="<?= e($video['thumbnail_url'] ?? '') ?>" alt="" style="width:90px;aspect-ratio:16/9;object-fit:cover;border-radius:4px;border:1px solid #d4af37;">
          <small class="muted"><?= e(t('Current custom thumbnail:', 'Current custom thumbnail:')) ?> <code><?= e($video['thumbnail_file']) ?></code></small>
        </div>
      <?php endif; ?>
    </div>

    <!-- Description -->
    <div class="field mb-4">
      <label for="f_description"><?= e(t('Video Description', 'Video Description')) ?></label>
      <textarea class="input" id="f_description" name="description" rows="4"
                placeholder="<?= e(t('Provide technical context, steps performed, or tools showcased in this video…', 'Provide technical context, steps performed, or tools showcased in this video…')) ?>"><?= e($_POST['description'] ?? ($video['description'] ?? '')) ?></textarea>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;align-items:center;" class="mb-4">
      <!-- Sort Order -->
      <div class="field">
        <label for="f_sort_order"><?= e(t('Sort Order', 'Sort Order')) ?></label>
        <input class="input" id="f_sort_order" name="sort_order" type="number" min="0" step="1"
               value="<?= e((string) ($_POST['sort_order'] ?? ($video['sort_order'] ?? 0))) ?>">
        <small class="help"><?= e(t('Lower numbers display first.', 'Lower numbers display first.')) ?></small>
      </div>

      <!-- Published Checkbox -->
      <div class="field" style="padding-top:1.25rem;">
        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
          <input type="checkbox" name="is_published" value="1"
                 <?= (!isset($_POST['is_published']) && !$isEdit) || !empty($_POST['is_published']) || (!empty($video['is_published']) && !is_post()) ? 'checked' : '' ?>>
          <strong><?= e(t('Publish Video to Public Gallery', 'Publish Video to Public Gallery')) ?></strong>
        </label>
        <small class="help" style="display:block;margin-left:1.5rem;"><?= e(t('Unchecking keeps this video in draft state (visible only to admins).', 'Unchecking keeps this video in draft state (visible only to admins).')) ?></small>
      </div>
    </div>

    <div class="form-actions mt-4" style="display:flex;gap:1rem;justify-content:flex-end;">
      <a class="btn btn-ghost" href="<?= e(url('videos/index.php')) ?>"><?= e(t('Cancel', 'Cancel')) ?></a>
      <button type="submit" class="btn btn-primary btn-interactive btn-shimmer">
        <?= $isEdit ? e(t('Update Video', 'Update Video')) : e(t('Save Video', 'Save Video')) ?>
      </button>
    </div>
  </form>
</div>

<script src="<?= e(asset('js/video-admin.js')) ?>"></script>
<?php ui_footer(); ?>
