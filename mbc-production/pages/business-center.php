<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login($pdo);
require_permission($user, 'manage_business_center');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        set_flash('error', 'Invalid form token.');
        redirect('business-center.php');
    }

    $sectionKey = trim((string) ($_POST['section_key'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($sectionKey === '' || $title === '') {
        set_flash('error', 'Section and title are required.');
        redirect('business-center.php');
    }

    $stmt = $pdo->prepare('INSERT INTO business_center_content (section_key, title, body, is_active, updated_by)
        VALUES (:section_key, :title, :body, :is_active, :updated_by)
        ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body), is_active = VALUES(is_active), updated_by = VALUES(updated_by)');
    $stmt->execute([
        'section_key' => substr($sectionKey, 0, 80),
        'title' => $title,
        'body' => $body !== '' ? $body : null,
        'is_active' => $isActive,
        'updated_by' => (int) $user['id'],
    ]);
    audit_log($pdo, $user, 'update_landing_content', 'business_center', 'section', $sectionKey);
    set_flash('success', 'Landing page content updated.');
    redirect('business-center.php?section=' . rawurlencode($sectionKey));
}

$sections = $pdo->query('SELECT section_key, title, body, is_active, updated_at FROM business_center_content ORDER BY FIELD(section_key, "hero", "mission_vision", "services", "features", "contact", "footer"), section_key')->fetchAll();
$byKey = [];
foreach ($sections as $section) {
    $byKey[(string) $section['section_key']] = $section;
}

$sectionConfig = [
    'hero' => [
        'label' => 'Hero',
        'title' => 'Hero Settings',
        'title_label' => 'Hero Title',
        'body_label' => 'Hero Description',
        'hint' => 'Displayed under the main title on the landing page.',
        'placeholder' => 'Short welcome message for the public landing page.',
        'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="M7 15l3-3 2 2 3-4 2 5"></path></svg>',
    ],
    'mission_vision' => [
        'label' => 'Mission & Vision',
        'title' => 'Mission & Vision Settings',
        'title_label' => 'Section Title',
        'body_label' => 'Mission and Vision Text',
        'hint' => 'Use the format Vision: ... then Mission: ... so the landing page can display separate panels.',
        'placeholder' => "Vision: Enter the official vision statement\n\nMission: Enter the official mission statement",
        'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M4 4.5A2.5 2.5 0 0 1 6.5 7H20v14H6.5A2.5 2.5 0 0 1 4 18.5z"></path></svg>',
    ],
    'services' => [
        'label' => 'Services',
        'title' => 'Services Settings',
        'title_label' => 'Section Title',
        'body_label' => 'Section Description',
        'hint' => 'Shown above the service cards on the landing page.',
        'placeholder' => 'Brief description of campus services.',
        'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3-3a5 5 0 0 1-6.6 6.6l-6.8 6.8a2 2 0 1 1-2.8-2.8l6.8-6.8a5 5 0 0 1 6.6-6.6z"></path></svg>',
    ],
    'features' => [
        'label' => 'Features',
        'title' => 'Features Settings',
        'title_label' => 'Section Title',
        'body_label' => 'Features List',
        'hint' => 'Enter one feature per line.',
        'placeholder' => "Sales records\nInventory tracking\nCash flow monitoring",
        'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l2.6 5.3 5.9.9-4.2 4.1 1 5.8L12 16.3 6.7 19.1l1-5.8-4.2-4.1 5.9-.9z"></path></svg>',
    ],
    'contact' => [
        'label' => 'Contact',
        'title' => 'Contact Settings',
        'title_label' => 'Section Title',
        'body_label' => 'Contact Information',
        'hint' => 'Campus location, office address, and contact details.',
        'placeholder' => 'Mindoro State University Bongabong Campus Production and Business Operation Office.',
        'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.3 1.7.6 2.5a2 2 0 0 1-.5 2.1L8 9.5a16 16 0 0 0 6.5 6.5l1.2-1.2a2 2 0 0 1 2.1-.5c.8.3 1.6.5 2.5.6a2 2 0 0 1 1.7 2z"></path></svg>',
    ],
    'footer' => [
        'label' => 'Footer',
        'title' => 'Footer Settings',
        'title_label' => 'Footer Title',
        'body_label' => 'Footer Description',
        'hint' => 'Displayed in the footer section of the public landing page.',
        'placeholder' => 'Additional footer text or copyright information.',
        'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M3 16h18"></path><path d="M8 20v-4"></path></svg>',
    ],
];

foreach ($sectionConfig as $key => $config) {
    if (!isset($byKey[$key])) {
        $byKey[$key] = [
            'section_key' => $key,
            'title' => $config['label'],
            'body' => '',
            'is_active' => 1,
            'updated_at' => null,
        ];
    }
}

$selectedSection = (string) ($_GET['section'] ?? 'hero');
if (!isset($byKey[$selectedSection])) {
    $selectedSection = 'hero';
}
$selected = $byKey[$selectedSection];
$selectedConfig = $sectionConfig[$selectedSection] ?? [
    'label' => ucwords(str_replace('_', ' ', $selectedSection)),
    'title' => 'Section Settings',
    'title_label' => 'Title',
    'body_label' => 'Body',
    'hint' => 'Displayed on the public landing page.',
    'placeholder' => '',
    'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2"></rect></svg>',
];

$bodyLines = array_values(array_filter(array_map('trim', preg_split('/\R+/', (string) ($selected['body'] ?? '')) ?: [])));
$updatedAt = $selected['updated_at'] ? date('M d, Y H:i', strtotime((string) $selected['updated_at'])) : 'Not saved yet';

render_header('Landing Page Content Management', $user);
?>

<style>
    .landing-cms-header {
        margin-bottom: 1rem;
    }
    .landing-cms-header h2 {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 800;
        color: #102018;
    }
    .landing-cms-header p {
        margin-top: .25rem;
        color: #64748b;
        font-size: .9rem;
    }
    .flash-message {
        margin-bottom: 1rem;
        border-radius: .5rem;
        border: 1px solid #dbe8dc;
        background: #f8fafc;
        padding: .75rem 1rem;
        font-size: .9rem;
        font-weight: 700;
    }
    .flash-message.success {
        border-color: #bbf7d0;
        background: #dcfce7;
        color: #166534;
    }
    .flash-message.error {
        border-color: #fecaca;
        background: #fee2e2;
        color: #991b1b;
    }
    .landing-cms-layout {
        display: grid;
        grid-template-columns: minmax(240px, 280px) minmax(0, 1fr);
        gap: 1rem;
        align-items: start;
    }
    .landing-section-nav {
        position: sticky;
        top: 1rem;
        overflow: hidden;
    }
    .landing-section-list {
        display: grid;
        gap: .35rem;
        padding: .75rem;
    }
    .landing-section-link {
        display: grid;
        grid-template-columns: 1.75rem minmax(0, 1fr) auto;
        align-items: center;
        gap: .55rem;
        border-radius: .45rem;
        padding: .55rem .65rem;
        color: #1f2937;
        text-decoration: none;
        transition: background .16s ease, color .16s ease;
    }
    .landing-section-link:hover,
    .landing-section-link.is-active {
        background: #f0fdf4;
        color: #14532d;
    }
    .landing-section-link.is-active {
        box-shadow: inset 3px 0 0 #f2bd22;
    }
    .section-hidden-label {
        color: #991b1b;
        font-size: .75rem;
        font-weight: 700;
    }
    .landing-section-icon {
        display: grid;
        place-items: center;
        width: 1.75rem;
        height: 1.75rem;
        border-radius: .45rem;
        background: #f8fafc;
        border: 1px solid #dbe8dc;
        color: #14532d;
    }
    .landing-section-icon svg {
        width: 1rem;
        height: 1rem;
        fill: none;
        stroke: currentColor;
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .landing-preview-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
    }
    .landing-device-controls {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem;
    }
    .landing-device-controls button {
        min-height: 2rem;
        padding: .35rem .7rem;
    }
    .landing-preview-shell {
        margin-top: .75rem;
        display: flex;
        justify-content: center;
        border-radius: .5rem;
        border: 1px solid #dbe8dc;
        background: #f8fafc;
        padding: .75rem;
        overflow: hidden;
    }
    .landing-preview-frame {
        width: 100%;
        max-width: 100%;
        min-height: 16rem;
        border-radius: .45rem;
        border: 1px solid #dbe8dc;
        background: #ffffff;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .08);
        overflow: hidden;
        transition: max-width .18s ease;
    }
    .landing-preview-frame.is-tablet {
        max-width: 760px;
    }
    .landing-preview-frame.is-mobile {
        max-width: 390px;
    }
    .landing-preview-hero {
        min-height: 7.5rem;
        padding: 1rem;
        color: #f8fafc;
        background: linear-gradient(135deg, #0f3d22, #1b5e20);
    }
    .landing-preview-hero span {
        display: inline-flex;
        margin-bottom: .55rem;
        border-radius: 999px;
        background: rgba(255,255,255,.14);
        padding: .25rem .6rem;
        font-size: .75rem;
        font-weight: 700;
    }
    .landing-preview-hero h3,
    .landing-preview-section h3 {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 800;
    }
    .landing-preview-hero p,
    .landing-preview-section p,
    .landing-preview-section li {
        color: #475569;
        font-size: .9rem;
        line-height: 1.5;
    }
    .landing-preview-hero p {
        color: #dcfce7;
        max-width: 42rem;
    }
    .landing-preview-section {
        padding: .85rem 1rem;
    }
    .landing-preview-section ul {
        margin: .75rem 0 0;
        padding-left: 1.1rem;
    }
    .landing-editor-grid {
        display: grid;
        gap: 1rem;
    }
    .landing-form-body {
        display: grid;
        gap: 1rem;
        padding: 1rem;
    }
    .form-field-hint {
        margin-top: .35rem;
        color: #64748b;
        font-size: .78rem;
    }
    .landing-field textarea {
        min-height: 11rem;
    }
    .landing-toggle-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        border-radius: .5rem;
        border: 1px solid #dbe8dc;
        background: #f8fafc;
        padding: .8rem;
    }
    .landing-toggle {
        position: relative;
        display: inline-flex;
        align-items: center;
        gap: .6rem;
        font-weight: 700;
        color: #1f2937;
    }
    .landing-toggle input {
        position: absolute;
        opacity: 0;
    }
    .landing-toggle span {
        width: 2.5rem;
        height: 1.35rem;
        border-radius: 999px;
        background: #cbd5e1;
        position: relative;
        transition: background .16s ease;
    }
    .landing-toggle span::after {
        content: "";
        position: absolute;
        top: .18rem;
        left: .18rem;
        width: .98rem;
        height: .98rem;
        border-radius: 999px;
        background: #fff;
        transition: transform .16s ease;
    }
    .landing-toggle input:checked + span {
        background: #166534;
    }
    .landing-toggle input:checked + span::after {
        transform: translateX(1.15rem);
    }
    .landing-sticky-actions {
        position: sticky;
        bottom: 0;
        z-index: 2;
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: .5rem;
        border-top: 1px solid #dbe8dc;
        background: rgba(255,255,255,.96);
        padding: .75rem 1rem;
    }
    @media (max-width: 1024px) {
        .landing-cms-layout {
            grid-template-columns: 1fr;
        }
        .landing-section-nav {
            position: static;
        }
        .landing-section-list {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 640px) {
        .landing-section-list {
            grid-template-columns: 1fr;
        }
    }
</style>

<?php if ($flash = get_flash()): ?>
    <div class="flash-message <?= h($flash['type']) ?>">
        <?= h($flash['message']) ?>
    </div>
<?php endif; ?>

<section class="landing-cms-header">
    <h2>Landing Page Content Management</h2>
    <p>Manage the public landing page sections, visibility, and content.</p>
</section>

<div class="landing-cms-layout">
    <aside class="table-card landing-section-nav">
        <div class="section-heading">
            <div>
                <h3 class="text-base font-bold text-slate-950">Sections</h3>
                <p class="text-sm text-slate-500">Landing page blocks</p>
            </div>
        </div>
        <nav class="landing-section-list" aria-label="Landing sections">
            <?php foreach ($byKey as $key => $section): ?>
                <?php
                $config = $sectionConfig[$key] ?? [
                    'label' => ucwords(str_replace('_', ' ', (string) $key)),
                    'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2"></rect></svg>',
                ];
                $active = ((int) $section['is_active']) === 1;
                ?>
                <a href="business-center.php?section=<?= h((string) $key) ?>" class="landing-section-link <?= $selectedSection === $key ? 'is-active' : '' ?>">
                    <span class="landing-section-icon"><?= $config['icon'] ?></span>
                    <span class="min-w-0">
                        <strong class="block truncate text-sm"><?= h((string) $config['label']) ?></strong>
                        <small class="text-xs text-slate-500"><?= h($section['updated_at'] ? date('M d, Y', strtotime((string) $section['updated_at'])) : 'Not saved') ?></small>
                    </span>
                    <?php if (!$active): ?>
                        <span class="section-hidden-label">Hidden</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <main class="landing-editor-grid">
        <section class="table-card data-panel">
            <div class="section-heading">
                <div>
                    <h3 class="text-base font-bold text-slate-950">Live Preview</h3>
                    <p class="text-sm text-slate-500">Preview updates while editing <?= h((string) $selectedConfig['label']) ?>.</p>
                </div>
                <div class="inline-actions">
                    <div class="landing-device-controls" role="group" aria-label="Device preview">
                        <button type="button" class="btn alt" data-preview-size="desktop">Desktop</button>
                        <button type="button" class="btn alt" data-preview-size="tablet">Tablet</button>
                        <button type="button" class="btn alt" data-preview-size="mobile">Mobile</button>
                    </div>
                </div>
            </div>
            <div class="landing-preview-shell">
                <article class="landing-preview-frame" data-preview-frame data-section="<?= h($selectedSection) ?>">
                    <div class="landing-preview-hero">
                        <span data-preview-status><?= ((int) $selected['is_active']) === 1 ? 'Active' : 'Hidden' ?></span>
                        <h3 data-preview-title><?= h((string) $selected['title']) ?></h3>
                        <p data-preview-body><?= h((string) ($selected['body'] ?: 'Section description will appear here.')) ?></p>
                    </div>
                    <div class="landing-preview-section">
                        <h3><?= h((string) $selectedConfig['label']) ?></h3>
                        <?php if ($bodyLines): ?>
                            <ul data-preview-list>
                                <?php foreach (array_slice($bodyLines, 0, 6) as $line): ?>
                                    <li><?= h($line) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p data-preview-empty>No body content yet. Add content in the editor below.</p>
                            <ul data-preview-list hidden></ul>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
        </section>

        <section class="table-card data-panel">
            <div class="section-heading">
                <div>
                    <h3 class="text-base font-bold text-slate-950"><?= h((string) $selectedConfig['title']) ?></h3>
                    <p class="text-sm text-slate-500">Last updated: <?= h($updatedAt) ?></p>
                </div>
            </div>

            <form method="post" id="editor-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="section_key" value="<?= h($selectedSection) ?>">

                <div class="landing-form-body">
                    <div class="landing-field">
                        <label for="title"><?= h((string) $selectedConfig['title_label']) ?></label>
                        <input id="title" type="text" name="title" value="<?= h((string) $selected['title']) ?>" required data-live-title>
                    </div>

                    <div class="landing-field">
                        <label for="body"><?= h((string) $selectedConfig['body_label']) ?></label>
                        <textarea id="body" name="body" placeholder="<?= h((string) $selectedConfig['placeholder']) ?>" data-live-body><?= h((string) ($selected['body'] ?? '')) ?></textarea>
                        <p class="form-field-hint"><?= h((string) $selectedConfig['hint']) ?></p>
                    </div>

                    <div class="landing-toggle-row">
                        <div>
                            <h4 class="text-sm font-bold text-slate-950">Visibility</h4>
                            <p class="text-sm text-slate-500">Control whether this section appears on the public landing page.</p>
                        </div>
                        <label class="landing-toggle" for="is_active">
                            <input type="checkbox" id="is_active" name="is_active" value="1" <?= ((int) $selected['is_active']) === 1 ? 'checked' : '' ?> data-live-active>
                            <span aria-hidden="true"></span>
                            Active
                        </label>
                    </div>
                </div>

                <div class="landing-sticky-actions">
                    <button type="button" class="btn alt" data-open-full-preview>Preview</button>
                    <button type="submit">Save / Publish</button>
                </div>
            </form>
        </section>
    </main>
</div>

<script>
    (function () {
        const titleInput = document.querySelector('[data-live-title]');
        const bodyInput = document.querySelector('[data-live-body]');
        const activeInput = document.querySelector('[data-live-active]');
        const previewFrame = document.querySelector('[data-preview-frame]');
        const previewTitle = document.querySelector('[data-preview-title]');
        const previewBody = document.querySelector('[data-preview-body]');
        const previewStatus = document.querySelector('[data-preview-status]');
        const previewList = document.querySelector('[data-preview-list]');
        const previewEmpty = document.querySelector('[data-preview-empty]');

        function updatePreview() {
            const title = (titleInput ? titleInput.value : '').trim() || 'Untitled section';
            const body = (bodyInput ? bodyInput.value : '').trim();
            if (previewTitle) previewTitle.textContent = title;
            if (previewBody) previewBody.textContent = body || 'Section description will appear here.';
            if (previewStatus && activeInput) previewStatus.textContent = activeInput.checked ? 'Active' : 'Hidden';
            if (previewList) {
                const lines = body.split(/\n+/).map(function (line) { return line.trim(); }).filter(Boolean).slice(0, 6);
                previewList.innerHTML = '';
                lines.forEach(function (line) {
                    const item = document.createElement('li');
                    item.textContent = line;
                    previewList.appendChild(item);
                });
                previewList.hidden = lines.length === 0;
                if (previewEmpty) previewEmpty.hidden = lines.length > 0;
            }
        }

        [titleInput, bodyInput, activeInput].forEach(function (field) {
            if (!field) return;
            field.addEventListener('input', updatePreview);
            field.addEventListener('change', updatePreview);
        });

        document.querySelectorAll('[data-preview-size]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (!previewFrame) return;
                previewFrame.classList.remove('is-tablet', 'is-mobile');
                const size = button.getAttribute('data-preview-size');
                if (size === 'tablet') previewFrame.classList.add('is-tablet');
                if (size === 'mobile') previewFrame.classList.add('is-mobile');
            });
        });

        document.querySelectorAll('[data-open-full-preview]').forEach(function (button) {
            button.addEventListener('click', function () {
                window.open('index.php', '_blank', 'width=1200,height=800');
            });
        });

        updatePreview();
    })();
</script>

<?php render_footer();
