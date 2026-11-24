<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — ADMIN VIDEO MANAGEMENT
 * Administration command center for managing video gallery items,
 * YouTube integrations, direct video file uploads, and publication state.
 */

require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';

$user = require_login();
require_permission('videos.view');

$canManage = can('videos.manage');
$catFilter = isset($_GET['category']) ? trim((string) $_GET['category']) : null;
$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';

$videos = VideoService::all(false, $catFilter, $search);
$categories = VideoService::CATEGORIES;

ui_header(t('Video Management', 'Video Management'), 'videos');

$actionsHtml = '';
if ($canManage) {
    $actionsHtml .= '<a class="btn btn-primary btn-interactive btn-shimmer" href="' . e(url('videos/form.php')) . '">+ ' . e(t('Add Video', 'Add Video')) . '</a> ';
}
$actionsHtml .= '<a class="btn btn-ghost btn-interactive" href="' . e(url('videos.php')) . '" target="_blank">' . e(t('Public Video Gallery', 'Public Video Gallery')) . ' &nearr;</a>';

page_header(
    t('Video Management', 'Video Management'),
    t('Manage workshop showcase videos, technical walkthroughs, and streaming media assets', 'Manage workshop showcase videos, technical walkthroughs, and streaming media assets'),
    $actionsHtml
);
?>

<div class="card card-flat reveal-up mb-4">
  <form method="get" action="<?= e(url('videos/index.php')) ?>" class="filters" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;">
    <div class="field grow" style="flex:2;min-width:240px;">
      <label for="vid-search"><?= e(t('Search videos…', 'Search videos…')) ?></label>
      <input type="search" id="vid-search" name="search" class="input" placeholder="<?= e(t('Search by title or description…', 'Search by title or description…')) ?>" value="<?= e($search) ?>" autocomplete="off">
    </div>
    <div class="field" style="flex:1;min-width:180px;">
      <label for="vid-cat"><?= e(t('Filter by category', 'Filter by category')) ?></label>
      <select id="vid-cat" name="category" class="input">
        <option value=""><?= e(t('All Categories', 'All Categories')) ?></option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= e($cat) ?>" <?= $catFilter === $cat ? 'selected' : '' ?>><?= e(t($cat, $cat)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="align-self:flex-end;">
      <button type="submit" class="btn btn-primary btn-interactive"><?= e(t('Filter', 'Filter')) ?></button>
      <?php if ($catFilter || $search): ?>
        <a class="btn btn-ghost" href="<?= e(url('videos/index.php')) ?>"><?= e(t('Clear', 'Clear')) ?></a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="table-wrap reveal-up">
  <table class="table stack" id="videos-table">
    <thead>
      <tr>
        <th style="width:110px;"><?= e(t('Preview', 'Preview')) ?></th>
        <th><?= e(t('Video Title', 'Video Title')) ?></th>
        <th><?= e(t('Category', 'Category')) ?></th>
        <th><?= e(t('Source', 'Source')) ?></th>
        <th style="text-align:center;"><?= e(t('Duration', 'Duration')) ?></th>
        <th style="text-align:center;"><?= e(t('Views', 'Views')) ?></th>
        <th style="text-align:center;"><?= e(t('Status', 'Status')) ?></th>
        <?php if ($canManage): ?><th style="text-align:right;"><?= e(t('Actions', 'Actions')) ?></th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($videos)): ?>
        <tr>
          <td colspan="<?= $canManage ? 8 : 7 ?>" class="empty text-center py-5">
            <div style="font-size:2rem;color:var(--gold,#d4af37);margin-bottom:0.5rem;">&#127916;</div>
            <p><?= e(t('No videos found matching the criteria.', 'No videos found matching the criteria.')) ?></p>
            <?php if ($canManage): ?>
              <a class="btn btn-primary btn-sm mt-2" href="<?= e(url('videos/form.php')) ?>">+ <?= e(t('Add First Video', 'Add First Video')) ?></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php else: foreach ($videos as $v): ?>
        <tr>
          <td>
            <div style="width:100px;aspect-ratio:16/9;background:#06140d;border-radius:6px;overflow:hidden;position:relative;border:1px solid rgba(212,175,55,0.25);">
              <?php if (!empty($v['thumbnail_url'])): ?>
                <img src="<?= e($v['thumbnail_url']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
              <?php else: ?>
                <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#d4af37;font-size:1.2rem;">&#127916;</div>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <strong><?= e($v['title']) ?></strong>
            <?php if (!empty($v['description'])): ?>
              <br><small class="muted" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;max-width:400px;"><?= e($v['description']) ?></small>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge badge-info" style="background:rgba(212,175,55,0.15);color:#d4af37;border:1px solid rgba(212,175,55,0.3);">
              <?= e(t($v['category'], $v['category'])) ?>
            </span>
          </td>
          <td>
            <?php if ($v['source_type'] === 'youtube'): ?>
              <span class="badge" style="background:#cc0000;color:#fff;">YouTube</span>
              <?php if (!empty($v['youtube_id'])): ?>
                <br><small class="muted"><code><?= e($v['youtube_id']) ?></code></small>
              <?php endif; ?>
            <?php else: ?>
              <span class="badge badge-success"><?= e(t('Uploaded File', 'Uploaded File')) ?></span>
              <?php if (!empty($v['video_file'])): ?>
                <br><small class="muted"><code><?= e($v['video_file']) ?></code></small>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td style="text-align:center;">
            <code><?= !empty($v['duration']) ? e($v['duration']) : '—' ?></code>
          </td>
          <td style="text-align:center;">
            <strong><?= number_format((int) $v['views_count']) ?></strong>
          </td>
          <td style="text-align:center;">
            <?php if ($v['is_published']): ?>
              <span class="badge badge-success"><?= e(t('Published', 'Published')) ?></span>
            <?php else: ?>
              <span class="badge badge-warning"><?= e(t('Draft', 'Draft')) ?></span>
            <?php endif; ?>
          </td>
          <?php if ($canManage): ?>
            <td style="text-align:right;white-space:nowrap;">
              <!-- Admin In-Page Video Preview -->
              <button type="button" class="btn btn-ghost btn-sm btn-interactive"
                      data-admin-preview-trigger
                      data-title="<?= e($v['title']) ?>"
                      data-desc="<?= e($v['description'] ?? '') ?>"
                      data-source="<?= e($v['source_type']) ?>"
                      data-embed="<?= e($v['embed_url'] ?? '') ?>"
                      data-stream="<?= e($v['stream_url'] ?? '') ?>"
                      title="<?= e(t('Preview Video', 'Preview Video')) ?>">
                &#9658; <?= e(t('Preview', 'Preview')) ?>
              </button>

              <a class="btn btn-ghost btn-sm btn-interactive" href="<?= e(url('videos/form.php?id=' . (int) $v['id'])) ?>" title="<?= e(t('Edit Video', 'Edit Video')) ?>">
                <?= e(t('Edit', 'Edit')) ?>
              </a>

              <!-- Toggle Publish/Draft Button -->
              <?= post_button(
                  url('videos/toggle.php'),
                  ['id' => (string) $v['id']],
                  $v['is_published'] ? t('Unpublish', 'Unpublish') : t('Publish', 'Publish'),
                  $v['is_published'] ? 'btn btn-ghost btn-sm text-warning' : 'btn btn-ghost btn-sm text-success',
                  $v['is_published'] ? t('Unpublish this video from public gallery?', 'Unpublish this video from public gallery?') : ''
              ) ?>

              <!-- Delete Video Button with Confirmation -->
              <?= post_button(
                  url('videos/delete.php'),
                  ['id' => (string) $v['id']],
                  t('Delete', 'Delete'),
                  'btn btn-ghost btn-sm text-danger',
                  t('Are you sure you want to delete this video permanently?', 'Are you sure you want to delete this video permanently?')
              ) ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<!-- Admin Video Preview Modal -->
<div class="video-modal" id="admin-preview-modal" role="dialog" aria-modal="true" aria-hidden="true" style="display:none;position:fixed;inset:0;background:rgba(4,12,8,0.85);backdrop-filter:blur(8px);z-index:99999;align-items:center;justify-content:center;padding:1.5rem;">
  <div style="background:#091c13;border:1px solid #d4af37;border-radius:14px;max-width:850px;width:100%;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,0.8);">
    <div style="padding:1rem 1.25rem;background:#0c2318;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid rgba(212,175,55,0.25);">
      <h3 id="admin-preview-title" style="margin:0;color:#fff;font-size:1.1rem;"><?= e(t('Preview Video', 'Preview Video')) ?></h3>
      <button type="button" data-admin-preview-close class="btn btn-ghost btn-sm" style="font-size:1.4rem;line-height:1;padding:0.25rem 0.5rem;color:#b0c4b8;" aria-label="<?= e(t('Close', 'Close')) ?>">&times;</button>
    </div>
    <div id="admin-preview-container" style="position:relative;width:100%;aspect-ratio:16/9;background:#000;">
      <!-- Video Element Injected -->
    </div>
    <div style="padding:0.85rem 1.25rem;background:#0c2318;border-top:1px solid rgba(212,175,55,0.15);display:flex;justify-content:space-between;align-items:center;">
      <small id="admin-preview-desc" class="muted" style="max-width:80%;"></small>
      <button type="button" class="btn btn-ghost btn-sm" data-admin-preview-close><?= e(t('Close', 'Close')) ?></button>
    </div>
  </div>
</div>

<script src="<?= e(asset('js/video-admin.js')) ?>"></script>
<?php ui_footer(); ?>
