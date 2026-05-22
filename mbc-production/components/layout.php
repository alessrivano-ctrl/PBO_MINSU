<?php
declare(strict_types=1);

function render_status_badge(string $status): void
{
    $normalized = strtolower(trim(str_replace('_', ' ', $status)));
    $label = match ($normalized) {
        'submitted', 'under review' => 'Pending',
        'needs revision' => 'Needs Revision',
        default => ucwords($normalized),
    };
    $class = preg_replace('/[^a-z0-9_-]+/', '-', $normalized) ?: 'status';
    echo '<span class="status-pill ' . h($class) . '">' . h($label) . '</span>';
}
function render_empty_state(string $title, string $message, ?string $buttonLabel = null, ?string $modalId = null): void
{
    echo '<div class="empty-state text-center">';
    echo '<p class="font-semibold text-slate-900">' . h($title) . '</p>';
    echo '<p class="mt-1 text-sm text-slate-500">' . h($message) . '</p>';
    if ($buttonLabel !== null && $modalId !== null) {
        echo '<button type="button" class="mt-4" data-open-modal="' . h($modalId) . '">' . h($buttonLabel) . '</button>';
    }
    echo '</div>';
}

function render_page_header(string $subtitle = '', ?string $primaryLabel = null, ?string $modalId = null, ?string $href = null): void
{
    if ($primaryLabel === null) {
        return;
    }

    echo '<div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">';
    echo '<span></span>';
    if ($modalId !== null) {
        echo '<button type="button" data-open-modal="' . h($modalId) . '">' . h($primaryLabel) . '</button>';
    } elseif ($href !== null) {
        echo '<a class="btn" href="' . h($href) . '">' . h($primaryLabel) . '</a>';
    }
    echo '</div>';
}

function render_record_count_badge(int $count, string $singular = 'record', ?string $plural = null): void
{
    return;
}

function render_table_header(string $title, ?int $count = null, string $singular = 'record', ?string $plural = null): void
{
    echo '<div class="table-shell-header">';
    echo '<h3>' . h($title) . '</h3>';
    if ($count !== null) {
        render_record_count_badge($count, $singular, $plural);
    }
    echo '</div>';
}

function nav_icon(string $name): string
{
    $paths = [
        'overview' => '<rect x="3" y="3" width="7" height="8" rx="1.5"></rect><rect x="14" y="3" width="7" height="5" rx="1.5"></rect><rect x="14" y="12" width="7" height="9" rx="1.5"></rect><rect x="3" y="15" width="7" height="6" rx="1.5"></rect>',
        'sales' => '<circle cx="8" cy="21" r="1"></circle><circle cx="19" cy="21" r="1"></circle><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h8.95a2 2 0 0 0 1.96-1.6L21 7H5.12"></path>',
        'inventory' => '<path d="m21 8-9-5-9 5 9 5 9-5Z"></path><path d="M3 8v8l9 5 9-5V8"></path><path d="M12 13v8"></path>',
        'projects' => '<path d="M3 3v18h18"></path><path d="m19 9-5 5-4-4-3 3"></path>',
        'records' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"></path><path d="M14 2v4a2 2 0 0 0 2 2h4"></path><path d="M8 13h8"></path><path d="M8 17h5"></path>',
        'admin' => '<path d="M12 20a8 8 0 0 0 8-8V6l-8-3-8 3v6a8 8 0 0 0 8 8Z"></path><path d="m9 12 2 2 4-4"></path>',
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="14" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect>',
        'pos' => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path><path d="M3 6h18"></path><path d="M16 10a4 4 0 0 1-8 0"></path>',
        'receipt' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"></path><path d="M8 7h8"></path><path d="M8 11h8"></path><path d="M8 15h5"></path>',
        'wallet' => '<path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5"></path><path d="M18 12h.01"></path>',
        'fishpond' => '<path d="M6.5 12c2-3 5.5-4 9-2l4.5 2-4.5 2c-3.5 2-7 1-9-2Z"></path><path d="m4 10 2.5 2L4 14"></path><circle cx="14" cy="12" r=".5" fill="currentColor"></circle>',
        'rental' => '<path d="M3 21h18"></path><path d="M5 21V7l8-4 6 4v14"></path><path d="M9 21v-6h6v6"></path><path d="M9 9h.01"></path><path d="M15 9h.01"></path>',
        'proposal' => '<path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"></path>',
        'logbook' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"></path>',
        'report' => '<path d="M3 3v18h18"></path><path d="M8 17V9"></path><path d="M13 17V5"></path><path d="M18 17v-4"></path>',
        'landing' => '<rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M3 9h18"></path><path d="M8 15h4"></path>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'security' => '<rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>',
        'backup' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><path d="M7 10l5 5 5-5"></path><path d="M12 15V3"></path>',
    ];
    $body = $paths[$name] ?? $paths['dashboard'];

    return '<svg class="nav-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' . $body . '</svg>';
}

function render_header(string $title, ?array $user = null): void
{
    $current = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
    $pageName = strtolower(pathinfo($current, PATHINFO_FILENAME));
    $pageSlug = (string) preg_replace('/[^a-z0-9_-]+/', '-', $pageName);
    $bodyPageClass = 'page-' . ($pageSlug !== '' ? $pageSlug : 'index');
    $navItems = [
        ['href' => 'dashboard.php'],
        ['href' => 'sales.php'],
        ['href' => 'sales-reports.php'],
        ['href' => 'cashflow.php'],
        ['href' => 'products.php?section=products'],
        ['href' => 'products.php?section=services'],
        ['href' => 'products.php?section=stock'],
        ['href' => 'projects.php'],
        ['href' => 'projects.php?category=fishpond'],
        ['href' => 'projects.php?category=rental&rental_type=stall'],
        ['href' => 'proposals.php'],
        ['href' => 'reports.php'],
        ['href' => 'business-center.php', 'permission' => 'manage_business_center'],
        ['href' => 'users.php', 'permission' => 'manage_users'],
        ['href' => 'settings.php', 'permission' => 'manage_settings'],
        ['href' => 'security.php', 'permission' => 'view_security_logs'],
        ['href' => 'security.php?type=audit', 'permission' => 'view_security_logs'],
        ['href' => 'security.php?type=sessions', 'permission' => 'view_security_logs'],
        ['href' => 'security.php?type=login', 'permission' => 'view_security_logs'],
        ['href' => 'security.php?type=errors', 'permission' => 'view_security_logs'],
        ['href' => 'backup.php', 'permission' => 'manage_backups'],
    ];
    $visibleItems = [];

    foreach ($navItems as $item) {
        if ($user && isset($item['permission']) && !user_can($user, (string) $item['permission'])) {
            continue;
        }

        $visibleItems[] = $item;
    }

    $visibleHrefMap = [];
    foreach ($visibleItems as $item) {
        $visibleHrefMap[(string) $item['href']] = true;
    }

    $hrefMatchesCurrent = static function (string $href) use ($current): bool {
        $hrefPath = parse_url($href, PHP_URL_PATH) ?: $href;
        if ($current !== $hrefPath) {
            return false;
        }

        $hrefQueryString = (string) (parse_url($href, PHP_URL_QUERY) ?: '');
        parse_str($hrefQueryString, $hrefQuery);
        $currentQuery = $_GET;
        if ($hrefPath === 'security.php' && !isset($currentQuery['type'])) {
            $currentQuery['type'] = 'audit';
        }
        if ($hrefPath === 'products.php' && !isset($currentQuery['section'])) {
            $currentQuery['section'] = 'products';
        }
        if ($hrefQuery === []) {
            return $hrefPath !== 'projects.php' || (!isset($currentQuery['category']) && !isset($currentQuery['rental_type']));
        }
        $relevantCurrentQuery = array_intersect_key($currentQuery, $hrefQuery);

        return $relevantCurrentQuery == $hrefQuery && count($relevantCurrentQuery) === count($hrefQuery);
    };

    $navGroups = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'collapsible' => false, 'items' => [
            ['href' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => 'dashboard'],
        ]],
        ['key' => 'sales', 'label' => 'Sales', 'icon' => 'sales', 'collapsible' => true, 'items' => [
            ['href' => 'sales.php', 'label' => 'POS', 'icon' => 'pos'],
            ['href' => 'sales-reports.php', 'label' => 'Transactions', 'icon' => 'receipt'],
            ['href' => 'cashflow.php', 'label' => 'Cash Flow', 'icon' => 'wallet'],
        ]],
        ['key' => 'inventory', 'label' => 'Inventory', 'icon' => 'inventory', 'collapsible' => true, 'items' => [
            ['href' => 'products.php?section=products', 'label' => 'Products', 'icon' => 'inventory'],
            ['href' => 'products.php?section=services', 'label' => 'Services', 'icon' => 'receipt'],
            ['href' => 'products.php?section=stock', 'label' => 'Stock', 'icon' => 'records'],
        ]],
        ['key' => 'projects', 'label' => 'Business Operation', 'icon' => 'projects', 'collapsible' => true, 'items' => [
            ['href' => 'projects.php?view=add-project', 'label' => 'Add Projects', 'icon' => 'projects'],
            ['href' => 'projects.php?category=fishpond', 'label' => 'Fishpond', 'icon' => 'fishpond'],
            ['href' => 'projects.php?category=rental&rental_type=stall', 'label' => 'Rentals', 'icon' => 'rental'],
            ['href' => 'proposals.php', 'label' => 'Proposals', 'icon' => 'proposal'],
        ]],
        ['key' => 'records', 'label' => 'REPORTS', 'icon' => 'report', 'collapsible' => false, 'items' => [
            ['href' => 'reports.php', 'label' => 'REPORTS', 'icon' => 'report'],
        ]],
        ['key' => 'admin', 'label' => 'Admin', 'icon' => 'admin', 'collapsible' => true, 'items' => [
            ['href' => 'business-center.php', 'label' => 'Landing Content', 'icon' => 'landing'],
            ['href' => 'users.php', 'label' => 'Users', 'icon' => 'users'],
            ['href' => 'settings.php', 'label' => 'System Settings', 'icon' => 'admin'],
            ['href' => 'security.php', 'label' => 'Security Logs', 'icon' => 'security'],
            ['href' => 'backup.php', 'label' => 'Backup', 'icon' => 'backup'],
        ]],
    ];

    $visibleGroups = [];
    $activeGroup = '';
    foreach ($navGroups as $group) {
        $groupItems = [];
        foreach ($group['items'] as $item) {
            if (empty($visibleHrefMap[(string) $item['href']])) {
                continue;
            }
            $item['active'] = $hrefMatchesCurrent((string) $item['href']);
            if ($item['active']) {
                $activeGroup = (string) $group['key'];
            }
            $groupItems[] = $item;
        }

        if ($groupItems === []) {
            continue;
        }

        $group['items'] = $groupItems;
        $group['active'] = $activeGroup === (string) $group['key'];
        $visibleGroups[] = $group;
    }

    $flash = get_flash();

    echo '<!doctype html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' | ' . h(APP_NAME) . '</title>';
    echo '<link rel="stylesheet" href="' . h(asset_url('assets/tailwind.css')) . '">';
    echo '<link rel="stylesheet" href="' . h(asset_url('assets/pos-receipt.css')) . '">';
    echo '<link rel="stylesheet" href="' . h(asset_url('assets/ui-overrides.css')) . '">';
    echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
    echo '</head>';
    echo '<body class="min-h-screen bg-brand-50 text-[#102018] antialiased ' . h($bodyPageClass) . '">';
    echo '<div id="page-loading-overlay" class="page-loading-overlay" aria-hidden="true">';
    echo '<div class="page-loading-box" role="status" aria-live="polite">';
    echo '<span class="page-loading-spinner" aria-hidden="true"></span>';
    echo '<span>Loading page...</span>';
    echo '</div>';
    echo '</div>';

    echo '<div class="min-h-screen">';
    $org = organization_profile($GLOBALS['pdo'] ?? null);
    echo '<header class="sticky top-0 z-40 flex h-16 items-center justify-between gap-4 border-b border-brand-100 bg-white/95 px-4 shadow-sm backdrop-blur lg:px-6">';
    echo '<div class="flex min-w-0 items-center gap-3">';
    echo '<img class="h-11 w-11 shrink-0 rounded-full bg-white object-contain ring-1 ring-slate-200" src="' . h($org['logo_path']) . '" data-logo-path="' . h($org['logo_path']) . '" alt="' . h($org['campus_display_name']) . ' logo">';
    echo '<span class="min-w-0">';
    echo '<span class="block truncate text-sm font-bold leading-5 text-slate-950">' . h($org['campus_display_name']) . '</span>';
    echo '<span class="block truncate text-xs leading-4 text-slate-500">' . h($org['system_name']) . '</span>';
    echo '</span>';
    echo '</div>';

    if ($user) {
        $fullName = trim((string) ($user['full_name'] ?? ''));
        $username = trim((string) ($user['username'] ?? ''));
        $role = trim((string) ($user['role'] ?? ''));
        $initials = '';
        foreach (preg_split('/\s+/', $fullName) ?: [] as $part) {
            if ($part !== '') {
                $initials .= strtoupper(substr($part, 0, 1));
            }
            if (strlen($initials) >= 2) {
                break;
            }
        }
        if ($initials === '') {
            $initials = strtoupper(substr($username !== '' ? $username : 'U', 0, 1));
        }

        // Sidebar toggle button for mobile
        echo '<button class="flex h-10 w-10 items-center justify-center rounded-lg text-brand-700 hover:bg-brand-50 focus:outline-none focus:ring-2 focus:ring-brand-400 lg:hidden" id="sidebar-toggle" aria-label="Open sidebar" type="button" title="Toggle sidebar">';
        echo '<svg class="h-5 w-5" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"></path></svg>';
        echo '</button>';

        echo '<details class="account-menu relative shrink-0">';
        echo '<summary class="account-menu-trigger" aria-label="Open account menu" title="' . h($fullName !== '' ? $fullName : $username) . '">' . h($initials) . '</summary>';
        echo '<div class="account-menu-panel">';
        echo '<div class="account-menu-name">' . h($fullName !== '' ? $fullName : $username) . '</div>';
        echo '<div class="account-menu-username">@' . h($username !== '' ? $username : 'user') . '</div>';
        if ($role !== '') {
            echo '<div class="account-menu-role">' . h(ucfirst($role)) . '</div>';
        }
        echo '<div class="account-menu-section">';
        echo '<a class="account-menu-link" href="profile.php">Account Settings</a>';
        echo '<div class="account-menu-note">Theme: System default</div>';
        echo '</div>';
        echo '<a class="logout-menu-button" href="' . h(app_base_path() . 'logout.php') . '">Log out</a>';
        echo '</div>';
        echo '</details>';
    }

    echo '</header>';

    if ($user) {
        echo '<div class="lg:flex lg:items-start">';
        $sidebarDefaultCollapsed = app_setting($GLOBALS['pdo'] ?? null, 'display.sidebar_default_state', 'expanded') === 'collapsed' ? '1' : '0';
        echo '<nav class="app-sidebar fixed bottom-0 left-0 right-0 top-16 z-30 border-b border-brand-900 bg-brand-900 px-4 py-3 text-white transition-all duration-300 ease-in-out lg:sticky lg:bottom-auto lg:right-auto lg:top-16 lg:h-[calc(100vh-4rem)] lg:shrink-0 lg:overflow-y-auto lg:border-b-0 lg:border-r lg:border-brand-800 lg:px-4 lg:py-3 -translate-x-full lg:translate-x-0" id="sidebar-nav" aria-label="Primary navigation" data-active-group="' . h($activeGroup) . '" data-default-collapsed="' . h($sidebarDefaultCollapsed) . '">';
        
        // Collapse button (mobile only)
        echo '<button class="absolute right-4 top-4 z-50 flex h-8 w-8 items-center justify-center rounded-lg bg-brand-800 text-white hover:bg-brand-700 lg:hidden" id="sidebar-close" aria-label="Close sidebar" type="button" onclick="document.getElementById(\'sidebar-nav\').classList.add(\'-translate-x-full\'); document.getElementById(\'sidebar-toggle\').focus();">';
        echo '<svg class="h-4 w-4" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"></path></svg>';
        echo '</button>';
        
        echo '<div class="sidebar-tools mb-1 hidden items-center justify-end px-1 lg:flex">';
        echo '<button class="sidebar-collapse-button has-sidebar-tooltip relative inline-flex shrink-0 items-center justify-center rounded-md border border-white/10 text-white/85 transition hover:bg-white/10 hover:text-white focus:outline-none focus:ring-2 focus:ring-gold-400" id="sidebar-collapse-toggle" aria-label="Collapse sidebar" type="button">';
        echo '<svg class="h-4 w-4" aria-hidden="true" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.78 4.22a.75.75 0 0 1 0 1.06L8.06 10l4.72 4.72a.75.75 0 1 1-1.06 1.06l-5.25-5.25a.75.75 0 0 1 0-1.06l5.25-5.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd"></path></svg>';
        echo '<span class="sidebar-tooltip pointer-events-none absolute left-full top-1/2 z-50 ml-3 -translate-y-1/2 whitespace-nowrap rounded-md bg-brand-800 px-2 py-1 text-xs font-semibold text-white shadow-lg">Collapse sidebar</span>';
        echo '</button>';
        echo '</div>';

        echo '<div class="sidebar-nav-list flex gap-2 overflow-x-auto pb-1 lg:block lg:space-y-1.5 lg:overflow-visible lg:pb-0">';
        foreach ($visibleGroups as $group) {
            $groupKey = (string) $group['key'];
            $groupLabel = (string) $group['label'];
            $submenuId = 'sidebar-group-' . preg_replace('/[^a-z0-9_-]+/', '-', $groupKey);
            $isOpen = !empty($group['active']);

            if (empty($group['collapsible'])) {
                $item = $group['items'][0];
                $active = !empty($item['active']);
                $baseClasses = 'sidebar-direct-link has-sidebar-tooltip relative flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-gold-400';
                $stateClasses = $active
                    ? 'sidebar-item-active bg-gold-400 text-[#102018] shadow-sm'
                    : 'text-white/90 hover:bg-white/10 hover:text-white focus:bg-white/10 focus:text-white';

                echo '<a class="' . h($baseClasses . ' ' . $stateClasses) . '" href="' . h((string) $item['href']) . '" aria-label="' . h((string) $item['label']) . '">';
                echo '<span class="inline-flex h-5 w-5 items-center justify-center">' . nav_icon((string) $item['icon']) . '</span>';
                echo '<span class="sidebar-label min-w-0 truncate">' . h((string) $item['label']) . '</span>';
                echo '<span class="sidebar-tooltip pointer-events-none absolute left-full top-1/2 z-50 ml-3 -translate-y-1/2 whitespace-nowrap rounded-md bg-brand-800 px-2 py-1 text-xs font-semibold text-white shadow-lg">' . h((string) $item['label']) . '</span>';
                echo '</a>';
                continue;
            }

            echo '<section class="sidebar-group min-w-max lg:min-w-0" data-sidebar-group="' . h($groupKey) . '">';
            echo '<button class="sidebar-group-button has-sidebar-tooltip relative flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-brand-100/85 transition hover:bg-white/10 hover:text-white focus:outline-none focus:ring-2 focus:ring-brand-400" type="button" aria-expanded="' . ($isOpen ? 'true' : 'false') . '" aria-controls="' . h($submenuId) . '" data-group-key="' . h($groupKey) . '" data-active-parent="' . ($isOpen ? 'true' : 'false') . '" aria-label="' . h($groupLabel) . '">';
            echo '<span class="inline-flex h-5 w-5 items-center justify-center">' . nav_icon((string) $group['icon']) . '</span>';
            echo '<span class="sidebar-label min-w-0 flex-1 truncate">' . h($groupLabel) . '</span>';
            echo '<svg class="sidebar-chevron" aria-hidden="true" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.22 4.22a.75.75 0 0 1 1.06 0l5.25 5.25a.75.75 0 0 1 0 1.06l-5.25 5.25a.75.75 0 0 1-1.06-1.06L11.94 10 7.22 5.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"></path></svg>';
            echo '<span class="sidebar-tooltip pointer-events-none absolute left-full top-1/2 z-50 ml-3 -translate-y-1/2 whitespace-nowrap rounded-md bg-brand-800 px-2 py-1 text-xs font-semibold normal-case tracking-normal text-white shadow-lg">' . h($groupLabel) . '</span>';
            echo '</button>';
            echo '<div class="sidebar-submenu mt-1 space-y-1 pl-3 ' . ($isOpen ? '' : 'is-closed') . '" id="' . h($submenuId) . '">';
            foreach ($group['items'] as $item) {
                $active = !empty($item['active']);
                $baseClasses = 'sidebar-link has-sidebar-tooltip relative flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-gold-400';
                $stateClasses = $active
                    ? 'sidebar-item-active bg-gold-400 text-[#102018] shadow-sm'
                    : 'text-white/90 hover:bg-white/10 hover:text-white focus:bg-white/10 focus:text-white';

                echo '<a class="' . h($baseClasses . ' ' . $stateClasses) . '" href="' . h((string) $item['href']) . '" aria-label="' . h((string) $item['label']) . '">';
                echo '<span class="inline-flex h-5 w-5 items-center justify-center">' . nav_icon((string) $item['icon']) . '</span>';
                echo '<span class="sidebar-label min-w-0 truncate">' . h((string) $item['label']) . '</span>';
                echo '<span class="sidebar-tooltip pointer-events-none absolute left-full top-1/2 z-50 ml-3 -translate-y-1/2 whitespace-nowrap rounded-md bg-brand-800 px-2 py-1 text-xs font-semibold text-white shadow-lg">' . h((string) $item['label']) . '</span>';
                echo '</a>';
            }
            echo '</div>';
            echo '</section>';
        }
        echo '</div>';
        echo '</nav>';
        
        // Overlay for mobile
        echo '<div class="fixed inset-0 top-16 z-20 hidden bg-black/50 lg:hidden" id="sidebar-overlay" onclick="document.getElementById(\'sidebar-nav\').classList.add(\'-translate-x-full\'); document.getElementById(\'sidebar-overlay\').classList.add(\'hidden\');"></div>';
        
        // Toggle button for mobile and collapsible sidebar groups
        echo '<script>
            const sidebarNav = document.getElementById("sidebar-nav");
            const sidebarToggle = document.getElementById("sidebar-toggle");
            const sidebarOverlay = document.getElementById("sidebar-overlay");
            const sidebarCollapseToggle = document.getElementById("sidebar-collapse-toggle");
            const sidebarStateKey = "bpoSidebarCollapsed";
            const groupStateKey = "bpoSidebarOpenGroups";
            const activeGroup = sidebarNav ? sidebarNav.getAttribute("data-active-group") : "";

            function readOpenGroups() {
                try {
                    return JSON.parse(localStorage.getItem(groupStateKey) || "{}") || {};
                } catch (error) {
                    return {};
                }
            }

            function saveOpenGroups(openGroups) {
                localStorage.setItem(groupStateKey, JSON.stringify(openGroups));
            }

            function setGroupOpen(button, open) {
                const submenu = document.getElementById(button.getAttribute("aria-controls"));
                button.setAttribute("aria-expanded", open ? "true" : "false");
                button.setAttribute("data-active-parent", open ? "true" : "false");
                if (submenu) {
                    submenu.classList.toggle("is-closed", !open);
                }
            }

            function syncCollapseLabel(collapsed) {
                if (!sidebarCollapseToggle) {
                    return;
                }
                sidebarCollapseToggle.setAttribute("aria-label", collapsed ? "Expand sidebar" : "Collapse sidebar");
                const tooltip = sidebarCollapseToggle.querySelector(".sidebar-tooltip");
                if (tooltip) {
                    tooltip.textContent = collapsed ? "Expand sidebar" : "Collapse sidebar";
                }
            }
            
            if (sidebarToggle) {
                sidebarToggle.addEventListener("click", () => {
                    sidebarNav.classList.remove("-translate-x-full");
                    sidebarOverlay.classList.remove("hidden");
                });
            }

            if (sidebarNav) {
                const storedSidebarState = localStorage.getItem(sidebarStateKey);
                const collapsed = storedSidebarState === null
                    ? sidebarNav.getAttribute("data-default-collapsed") === "1"
                    : storedSidebarState === "1";
                sidebarNav.classList.toggle("sidebar-collapsed", collapsed);
                syncCollapseLabel(collapsed);

                const savedOpenGroups = readOpenGroups();
                const groupButtons = Array.from(document.querySelectorAll("[data-group-key]"));
                const activeGroupButton = groupButtons.find((button) => button.getAttribute("data-group-key") === activeGroup);
                const savedOpenKey = Object.keys(savedOpenGroups).find((key) => savedOpenGroups[key] === true);
                const initialOpenKey = activeGroupButton ? activeGroup : (activeGroup === "" ? savedOpenKey : "");

                groupButtons.forEach((button) => {
                    const key = button.getAttribute("data-group-key");
                    setGroupOpen(button, key === initialOpenKey || (activeGroup !== "" && key === activeGroup));
                });

                groupButtons.forEach((button) => {
                    button.addEventListener("click", () => {
                        const key = button.getAttribute("data-group-key");
                        const isOpen = button.getAttribute("aria-expanded") === "true";
                        const nextOpen = !isOpen;
                        const openGroups = {};
                        groupButtons.forEach((otherButton) => {
                            const otherKey = otherButton.getAttribute("data-group-key");
                            const shouldOpen = otherKey === activeGroup || (otherButton === button && nextOpen);
                            openGroups[otherKey] = shouldOpen;
                            setGroupOpen(otherButton, shouldOpen);
                        });
                        saveOpenGroups(openGroups);
                    });
                });
            }

            if (sidebarCollapseToggle && sidebarNav) {
                sidebarCollapseToggle.addEventListener("click", () => {
                    const collapsed = !sidebarNav.classList.contains("sidebar-collapsed");
                    sidebarNav.classList.toggle("sidebar-collapsed", collapsed);
                    localStorage.setItem(sidebarStateKey, collapsed ? "1" : "0");
                    syncCollapseLabel(collapsed);
                });
            }
        </script>';
        
        echo '<main class="min-w-0 flex-1 px-3 py-5 sm:px-4 lg:px-5 xl:px-6 overflow-y-auto">';
        echo '<div class="w-full">';
    } else {
        echo '<div>';
        echo '<main class="min-w-0 px-4 py-5 sm:px-5 lg:px-6">';
        echo '<div>';
    }

    if ($flash) {
        $toastType = $flash['type'] === 'error' ? 'error' : 'success';
        echo '<div class="app-toast-data hidden" data-toast-type="' . h($toastType) . '" data-toast-message="' . h($flash['message']) . '"></div>';
    }

}

function render_footer(): void
{
    echo '</div>';
    echo '</main>';
    echo '</div>';
    echo '</div>';
    echo '<dialog id="logout-confirm-modal" class="modal">';
    echo '<div class="modal-header">';
    echo '<h3>Confirm Logout</h3>';
    echo '</div>';
    echo '<div class="modal-content">';
    echo '<p class="text-sm text-slate-700">Are you sure to logout?</p>';
    echo '<div class="modal-actions">';
    echo '<button type="button" class="btn alt" data-close-modal>Cancel</button>';
    echo '<a class="btn" href="' . h(app_base_path() . 'logout.php') . '">CONFIRM</a>';
    echo '</div>';
    echo '</div>';
    echo '</dialog>';
    echo '<script>
        const uiClassMap = {
            ".card": "rounded-lg border border-brand-100 bg-white p-5 shadow-sm",
            ".stack": "space-y-6",
            ".page-intro": "-mt-3 mb-5 max-w-3xl text-sm font-semibold leading-6 text-slate-700",
            ".metric-grid": "grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4",
            ".dashboard-layout": "space-y-0",
            ".dashboard-card-grid": "grid gap-3 items-stretch",
            ".dashboard-card": "flex h-full min-h-28 min-w-0 flex-col justify-start !p-4",
            ".dashboard-link": "cursor-pointer no-underline transition hover:-translate-y-0.5 hover:border-gold-400 hover:shadow-soft focus:outline-none focus:ring-2 focus:ring-gold-400",
            ".dashboard-card-cta": "mt-auto pt-3 text-xs font-bold text-brand-700",
            ".dashboard-section": "space-y-4",
            ".summary-grid": "grid gap-3 xl:grid-cols-3",
            ".two-col": "grid gap-3 md:grid-cols-2",
            ".stat": "mt-1 text-2xl font-bold text-[#102018]",
            ".muted": "text-sm text-slate-600",
            ".section-heading": "mb-3 flex flex-col gap-3 md:flex-row md:items-center md:justify-between",
            ".actions-row": "flex flex-wrap items-center gap-2",
            ".form-grid": "grid gap-3 md:grid-cols-2 xl:grid-cols-4",
            ".toolbar-form": "mt-3",
            ".field-wide": "md:col-span-2 xl:col-span-4",
            ".form-submit": "flex items-end",
            ".table-wrap": "w-full rounded-b-lg border border-brand-100",
            ".table-card": "rounded-lg border border-brand-100 bg-white shadow-sm",
            ".table-card > .section-heading": "flex flex-col gap-3 border-b border-brand-100 bg-white p-4 md:flex-row md:items-center md:justify-between",
            ".table-shell": "rounded-lg border border-brand-100 bg-white shadow-sm",
            ".table-shell-header": "flex flex-col gap-3 border-b border-brand-100 bg-white p-4 md:flex-row md:items-center md:justify-between",
            ".table-shell-header h3": "text-base font-bold text-[#102018]",
            ".table-toolbar": "grid gap-3 border border-brand-100 bg-white p-3 lg:items-end",
            ".table-toolbar-left": "min-w-0 flex-1",
            ".table-toolbar-right": "grid gap-2 sm:flex sm:items-end",
            ".table-footer": "flex flex-col gap-2 border-t border-brand-100 bg-white p-4 text-sm text-slate-700 md:flex-row md:items-center md:justify-between",
            ".data-panel": "space-y-4",
            ".data-panel-filters": "rounded-lg border border-brand-100 bg-white p-3",
            ".table-card > .data-panel-filters": "rounded-lg border",
            ".data-panel-footer": "mt-3 flex flex-wrap items-center gap-2 border-t border-brand-100 pt-3 text-sm font-semibold text-slate-700",
            ".table-card > .data-panel-footer": "m-0 bg-brand-50 p-4",
            ".chart-card": "overflow-hidden",
            ".chart-grid": "grid gap-4",
            ".dashboard-chart-grid": "items-stretch",
            ".chart-panel": "min-w-0 rounded-lg border border-brand-100 bg-brand-50 p-3 sm:p-4",
            ".chart-panel h4": "mb-2 text-sm font-bold text-[#102018]",
            ".chart-frame": "h-64 sm:h-72",
            ".dashboard-activity-grid": "grid gap-4 items-stretch",
            ".dashboard-activity-card": "min-w-0 overflow-hidden",
            ".modal-actions": "mt-4 flex justify-end gap-2",
            ".modal-content": "p-5",
            ".checkbox-field": "flex items-center pt-6",
            ".inline-actions": "flex flex-wrap items-center gap-2",
            ".inline-form": "flex flex-wrap items-center gap-2",
            ".action-menu": "relative inline-block text-left",
            ".action-menu summary": "inline-flex min-h-9 cursor-pointer list-none items-center justify-center rounded-md border border-brand-100 bg-white px-3 text-sm font-semibold text-brand-800 shadow-sm hover:bg-brand-50 focus:outline-none focus:ring-2 focus:ring-gold-400",
            ".action-menu-panel": "absolute right-0 z-20 mt-2 min-w-40 rounded-lg border border-brand-100 bg-white p-1 shadow-xl",
            ".action-menu-item": "w-full justify-start",
            ".form-validation-errors": "mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-800",
            ".field-error": "mt-1 text-xs font-semibold text-red-700",
            ".field-invalid": "border-red-400 focus:border-red-500 focus:ring-red-200",
            ".logout-menu-button": "shadow-none transition focus:outline-none focus:ring-2 focus:ring-gold-400",
            ".pagination": "mt-3 flex flex-wrap items-center gap-2",
            ".page-link": "inline-flex min-h-9 items-center rounded-md border border-brand-100 bg-white px-3 text-sm font-bold text-brand-700 hover:bg-brand-50",
            ".page-link.active": "bg-brand-700 text-white",
            ".page-link.disabled": "pointer-events-none opacity-45",
            ".low-stock": "bg-red-50",
            ".empty-state": "rounded-lg border border-dashed border-brand-200 bg-brand-50 p-8",
            ".content-grid": "grid gap-4 xl:grid-cols-2",
            ".collapse-card": "overflow-hidden",
            ".collapse-card summary": "flex cursor-pointer list-none items-center justify-between gap-3 border-b border-brand-100 bg-white p-4",
            ".collapse-card summary > span:first-child": "flex flex-col",
            ".collapse-card summary strong": "text-sm font-bold text-slate-950",
            ".collapse-card summary small": "mt-0.5 text-xs text-slate-500",
            ".collapse-card .table-wrap": "mt-0",
            ".badge": "inline-flex min-w-7 items-center justify-center rounded-full bg-brand-100 px-2 py-1 text-xs font-bold text-brand-800",
            ".summary-strip": "flex flex-wrap gap-2",
            ".summary-strip span": "inline-flex items-center gap-2 rounded-md border border-brand-100 bg-brand-50 px-3 py-2 text-sm font-semibold text-slate-800",
            ".summary-strip strong": "text-xs font-bold uppercase text-brand-800",
            ".footer-metric": "inline-flex items-center gap-2 rounded-md border border-brand-100 bg-white px-3 py-1.5 text-sm text-slate-800 shadow-sm",
            ".footer-metric strong": "text-xs font-bold uppercase text-brand-800",
            ".status-pill": "inline-flex rounded-full bg-slate-100 px-2 py-1 text-xs font-bold capitalize text-slate-700",
            ".status-pill.active": "bg-brand-100 text-brand-800",
            ".status-pill.success": "bg-brand-100 text-brand-800",

            ".status-pill.cash-in": "bg-brand-100 text-brand-800",
            ".status-pill.released": "bg-brand-100 text-brand-800",
            ".status-pill.returned": "bg-slate-100 text-slate-700",
            ".status-pill.neutral": "bg-slate-100 text-slate-700",
            ".status-pill.warning": "bg-gold-100 text-[#102018]",
            ".status-pill.pending": "bg-gold-100 text-[#102018]",
            ".status-pill.low-stock": "bg-gold-100 text-[#102018]",
            ".status-pill.overdue": "bg-red-50 text-red-800",
            ".status-pill.forfeited": "bg-red-50 text-red-800",
            ".status-pill.submitted": "bg-gold-100 text-[#102018]",
            ".status-pill.under-review": "bg-gold-100 text-[#102018]",
            ".status-pill.needs-revision": "bg-gold-100 text-[#102018]",
            ".status-pill.approved": "bg-brand-100 text-brand-800",
            ".status-pill.rejected": "bg-red-50 text-red-800",
            ".status-pill.danger": "bg-red-50 text-red-800",
            ".status-pill.cash-out": "bg-red-50 text-red-800",
            ".status-pill.implemented": "bg-brand-100 text-brand-800",
            ".tabs": "mb-4 flex gap-1 overflow-x-auto border-b border-brand-100",
            ".tab-link": "whitespace-nowrap border-b-2 border-transparent px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-gold-400 hover:text-brand-800",
            ".tab-link.active": "border-gold-500 bg-gold-50 text-brand-900"
        };

        function addClasses(selector, classes) {
            document.querySelectorAll(selector).forEach(function (element) {
                element.classList.add.apply(element.classList, classes.split(" "));
            });
        }

        document.querySelectorAll(".table-wrap").forEach(function (wrap) {
            if (wrap.querySelector(".table-scroll")) {
                return;
            }
            const table = wrap.querySelector("table");
            if (!table) {
                return;
            }
            const scroll = document.createElement("div");
            scroll.className = "table-scroll";
            wrap.appendChild(scroll);
            scroll.appendChild(table);
        });

        Object.entries(uiClassMap).forEach(function ([selector, classes]) {
            addClasses(selector, classes);
        });

        document.querySelectorAll("button, .btn, input[type=submit]").forEach(function (element) {
            if (element.closest(".app-sidebar")) {
                return;
            }
            if (element.classList.contains("logout-menu-button")) {
                return;
            }
            element.classList.add("inline-flex", "items-center", "justify-center", "rounded-md", "bg-brand-700", "px-3", "py-2", "text-sm", "font-semibold", "text-white", "shadow-sm", "transition", "hover:bg-brand-900", "focus:outline-none", "focus:ring-2", "focus:ring-gold-400", "disabled:opacity-50");
            if (element.classList.contains("alt") || element.classList.contains("btn-secondary")) {
                element.classList.remove("bg-brand-700", "text-white", "hover:bg-brand-900");
                element.classList.add("border", "border-brand-700", "bg-white", "text-brand-800", "hover:bg-brand-50");
            }
            if (element.classList.contains("btn-danger")) {
                element.classList.remove("bg-brand-700", "hover:bg-brand-900");
                element.classList.add("bg-red-700", "hover:bg-red-800");
            }
        });

        document.querySelectorAll("label").forEach(function (element) {
            element.classList.add("mb-1", "block", "text-sm", "font-medium", "text-[#102018]");
        });

        document.querySelectorAll("input:not([type=hidden]):not([type=checkbox]), select, textarea").forEach(function (element) {
            element.classList.add("w-full", "rounded-md", "border", "border-slate-300", "bg-white", "px-3", "py-2", "text-sm", "text-slate-900", "shadow-sm", "outline-none", "focus:border-brand-700", "focus:ring-2", "focus:ring-gold-100");
        });

        const filterApplyIcon = `<path d="M20 6 9 17l-5-5"></path>`;
        const filterResetIcon = `<path d="M3 12a9 9 0 1 0 3-6.7"></path><path d="M3 4v6h6"></path>`;
        const searchIcon = `<path d="m21 21-4.35-4.35"></path><circle cx="11" cy="11" r="7"></circle>`;

        function installTooltip(element, label) {
            if (!element || !label) {
                return;
            }
            element.setAttribute("aria-label", label);
            element.setAttribute("data-ui-tooltip", label);
            element.removeAttribute("title");
        }

        function enhanceFilterAction(control, label, icon) {
            if (!control || control.dataset.filterIcon === "1") {
                return;
            }
            control.dataset.filterIcon = "1";
            control.classList.add("filter-icon-button");
            installTooltip(control, label);
            control.innerHTML = `<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${icon}</svg>`;
        }

        function enhanceFilterSearch(input) {
            if (!input || input.closest(".filter-search-field")) {
                return;
            }
            const wrapper = document.createElement("div");
            wrapper.className = "filter-search-field";
            input.parentNode.insertBefore(wrapper, input);
            wrapper.appendChild(input);
            const icon = document.createElement("span");
            icon.className = "filter-search-icon";
            icon.innerHTML = `<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${searchIcon}</svg>`;
            wrapper.prepend(icon);
            input.classList.add("filter-search-input");
            input.dataset.searchIconInput = "1";
        }

        function classifyFilterContainer(container) {
            if (!container) {
                return;
            }
            if (container.querySelector("input[type=date]")) {
                container.classList.add("filter-field-date");
            }
            if (container.querySelector("select")) {
                container.classList.add("filter-field-select");
            }
            if (container.querySelector("input[name=q], input[name=item_q], input[type=search], input[id*=search], input[id=item_q]")) {
                container.classList.add("filter-field-search");
            }
        }

        document.querySelectorAll(".data-panel-filters, .dashboard-filter-panel, .report-filter-form").forEach(function (form) {
            form.classList.add("standard-filter-bar");
            form.querySelectorAll(":scope > div, :scope > .grid > div, :scope > .form-grid > div, .dashboard-filter-grid > div").forEach(classifyFilterContainer);
            form.querySelectorAll("input[name=q], input[name=item_q], input[type=search], input[id*=search], input[id=item_q]").forEach(function (input) {
                if (!input.placeholder || /^(item name|name, code, contact|pond name, code, fish type)$/i.test(input.placeholder.trim())) {
                    input.placeholder = input.id === "item_q" ? "Search item" : "Search records";
                }
                if (input.id === "q" && input.closest("form") && window.location.pathname.includes("cashflow")) {
                    input.placeholder = "Search reference or description";
                }
                if (input.id === "q" && window.location.pathname.includes("sales-reports")) {
                    input.placeholder = "Search transaction";
                }
                if ((input.id === "q" || input.id === "filter_q") && window.location.pathname.includes("products")) {
                    input.placeholder = "Search item";
                }
                enhanceFilterSearch(input);
            });
            form.querySelectorAll(".filter-actions button[type=submit], .dashboard-filter-actions button[type=submit], button[type=submit]").forEach(function (button) {
                const text = button.textContent.trim() || "Apply";
                enhanceFilterAction(button, text, filterApplyIcon);
            });
            form.querySelectorAll(".filter-actions a.btn, .dashboard-filter-actions a.btn, a.btn").forEach(function (link) {
                const text = link.textContent.trim() || "Reset";
                if (/reset/i.test(text)) {
                    enhanceFilterAction(link, text, filterResetIcon);
                }
            });
        });

        document.querySelectorAll(".pos-filter-row input[type=search]").forEach(function (input) {
            if (!input.placeholder) {
                input.placeholder = "Search item";
            }
            enhanceFilterSearch(input);
        });

        document.querySelectorAll("input[type=search], input[name=q], input[name=item_q], input[id*=search], input[id=item_q], input[id=filter_q], input[id=fishpond_q]").forEach(function (input) {
            if (!input.closest(".filter-search-field")) {
                enhanceFilterSearch(input);
            } else {
                input.classList.add("filter-search-input");
                input.dataset.searchIconInput = "1";
            }
        });

        document.querySelectorAll("textarea").forEach(function (element) {
            element.classList.add("min-h-24");
        });

        document.querySelectorAll("table").forEach(function (table) {
            table.classList.add("w-full", "min-w-max", "table-auto", "divide-y", "divide-slate-200", "text-sm");
            table.querySelectorAll("thead").forEach(function (thead) { thead.classList.add("bg-brand-100"); });
            table.querySelectorAll("th").forEach(function (th) {
                th.classList.add("cursor-pointer", "whitespace-nowrap", "px-2.5", "py-2.5", "text-left", "text-xs", "font-bold", "uppercase", "tracking-wide", "text-brand-900");
            });
            table.querySelectorAll("td").forEach(function (td) { td.classList.add("px-2.5", "py-2", "align-top", "text-slate-800", "whitespace-normal", "break-words"); });
            table.querySelectorAll("tbody tr").forEach(function (tr) { tr.classList.add("border-t", "border-brand-100"); });
        });

        const rowActionIcons = {
            view: `<path d="M2.05 12s3.4-6 9.95-6 9.95 6 9.95 6-3.4 6-9.95 6S2.05 12 2.05 12Z"></path><circle cx="12" cy="12" r="3"></circle>`,
            edit: `<path d="M12 20h9"></path><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"></path>`,
            add: `<path d="M12 5v14"></path><path d="M5 12h14"></path>`,
            save: `<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"></path><path d="M17 21v-8H7v8"></path><path d="M7 3v5h8"></path>`,
            close: `<path d="M18 6 6 18"></path><path d="m6 6 12 12"></path>`,
            print: `<path d="M6 9V2h12v7"></path><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><path d="M6 14h12v8H6z"></path>`,
            export: `<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><path d="M7 10l5 5 5-5"></path><path d="M12 15V3"></path>`,
            delete: `<path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="M19 6l-1 14H6L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path>`,
            stock: `<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path>`,
            archive: `<rect x="3" y="4" width="18" height="4" rx="1"></rect><path d="M5 8v11a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8"></path><path d="M10 12h4"></path>`,
            review: `<path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>`,
            approve: `<path d="M20 6 9 17l-5-5"></path>`,
            reject: `<path d="M18 6 6 18"></path><path d="m6 6 12 12"></path>`,
            revise: `<path d="M3 12a9 9 0 1 0 3-6.7"></path><path d="M3 4v5h5"></path>`,
            payment: `<rect x="2" y="5" width="20" height="14" rx="2"></rect><path d="M2 10h20"></path>`,
            return: `<path d="M9 14 4 9l5-5"></path><path d="M20 20v-7a4 4 0 0 0-4-4H4"></path>`,
            forfeit: `<path d="M10.3 2.3 1.8 17a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 2.3a2 2 0 0 0-3.4 0Z"></path><path d="M12 9v4"></path><path d="M12 17h.01"></path>`,
            void: `<circle cx="12" cy="12" r="10"></circle><path d="m4.93 4.93 14.14 14.14"></path>`,
            download: `<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><path d="M7 10l5 5 5-5"></path><path d="M12 15V3"></path>`,
            more: `<circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle><circle cx="5" cy="12" r="1"></circle>`
        };

        function rowActionIconKey(label) {
            const text = label.toLowerCase();
            if (text.includes("view")) return "view";
            if (text.includes("edit")) return "edit";
            if (text.includes("add") || text.includes("new") || text.includes("record entry")) return "add";
            if (text.includes("save") || text.includes("submit") || text.includes("confirm")) return "save";
            if (text.includes("close") || text.includes("cancel")) return "close";
            if (text.includes("print")) return "print";
            if (text.includes("export")) return "export";
            if (text.includes("delete") || text.includes("remove")) return "delete";
            if (text.includes("restock") || text.includes("stock")) return "stock";
            if (text.includes("archive")) return "archive";
            if (text.includes("review")) return "review";
            if (text.includes("approve")) return "approve";
            if (text.includes("reject")) return "reject";
            if (text.includes("revision")) return "revise";
            if (text.includes("payment")) return "payment";
            if (text.includes("return")) return "return";
            if (text.includes("forfeit")) return "forfeit";
            if (text.includes("void")) return "void";
            if (text.includes("download")) return "download";
            return "more";
        }

        function isDangerAction(label) {
            return /delete|remove|void|reject|forfeit|cancel transaction/i.test(label);
        }

        function isWarningAction(label) {
            return /revision|archive|warning|adjust/i.test(label);
        }

        function isPositiveAction(label) {
            return /approve|save|submit|confirm|download|return|restock|stock/i.test(label);
        }

        function decorateRowActionControl(control) {
            if (control.dataset.rowActionIcon === "1") {
                return;
            }
            const label = (control.getAttribute("aria-label") || control.textContent || control.getAttribute("title") || "Action").trim();
            const iconKey = rowActionIconKey(label);
            control.dataset.rowActionIcon = "1";
            control.classList.add("row-action-icon");
            if (isDangerAction(label)) {
                control.classList.add("ui-icon-danger");
            } else if (isWarningAction(label)) {
                control.classList.add("ui-icon-warning");
            } else if (isPositiveAction(label)) {
                control.classList.add("ui-icon-positive");
            } else {
                control.classList.add("ui-icon-neutral");
            }
            installTooltip(control, label);
            control.innerHTML = `<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${rowActionIcons[iconKey] || rowActionIcons.more}</svg>`;
            const srLabel = document.createElement("span");
            srLabel.className = "sr-only";
            srLabel.textContent = label || "Action";
            control.appendChild(srLabel);
        }

        document.querySelectorAll("table").forEach(function (table) {
            const headers = Array.from(table.tHead ? table.tHead.rows[0].cells : []);
            const actionIndex = headers.findIndex(function (header) {
                return header.textContent.trim().toLowerCase() === "actions";
            });
            if (actionIndex < 0 || !table.tBodies[0]) {
                return;
            }

            Array.from(table.tBodies[0].rows).forEach(function (row) {
                const cell = row.cells[actionIndex];
                if (!cell) {
                    return;
                }
                cell.classList.add("row-actions-cell");
                headers[actionIndex].classList.add("row-actions-header");
                cell.querySelectorAll("button, a.btn, details.action-menu > summary").forEach(decorateRowActionControl);
            });
        });

        function decorateActionControl(control) {
            if (!control || control.dataset.uiActionIcon === "1" || control.closest(".app-sidebar")) {
                return;
            }
            if (control.classList.contains("row-action-icon") || control.classList.contains("filter-icon-button")) {
                return;
            }
            const label = (control.getAttribute("aria-label") || control.textContent || control.getAttribute("title") || "Action").trim();
            if (!label) {
                return;
            }
            const iconKey = rowActionIconKey(label);
            control.dataset.uiActionIcon = "1";
            control.classList.add("ui-icon-action");
            if (control.classList.contains("alt") || control.classList.contains("btn-secondary") || /close|cancel|reset/i.test(label)) {
                control.classList.add("ui-icon-secondary");
            }
            if (control.classList.contains("btn-danger") || isDangerAction(label)) {
                control.classList.add("ui-icon-danger");
            }
            installTooltip(control, label);
            control.textContent = label;
        }

        document.querySelectorAll(".section-heading .inline-actions button, .section-heading .inline-actions a.btn, .section-heading .actions-row button, .section-heading .actions-row a.btn, .actions-row button, .actions-row a.btn, .form-submit button, .report-controls .print-actions button, .report-controls .print-actions a.btn").forEach(decorateActionControl);

        document.querySelectorAll(".page-action-icon, .print-button").forEach(function (control) {
            const label = (control.getAttribute("aria-label") || control.getAttribute("title") || control.textContent || "Action").trim();
            if (label) {
                control.textContent = label;
                installTooltip(control, label);
            }
        });

        document.querySelectorAll("[title]").forEach(function (element) {
            const label = element.getAttribute("aria-label") || element.getAttribute("title");
            if (label && element.matches("button, a, summary, [tabindex]")) {
                installTooltip(element, label);
            }
        });

        document.querySelectorAll(".tabs").forEach(function (tabs) {
            const links = Array.from(tabs.querySelectorAll(".tab-link")).filter(function (link) {
                return !link.hidden && link.offsetParent !== null;
            });
            if (links.length <= 1) {
                tabs.classList.add("is-single-tab");
                tabs.setAttribute("aria-hidden", "true");
            }
        });

        document.querySelectorAll(".section-heading h3, .section-heading h4").forEach(function (heading) {
            if (heading.textContent.trim().toLowerCase() === "filters") {
                const wrapper = heading.closest(".section-heading > div") || heading;
                wrapper.classList.add("hidden");
            }
        });

        document.querySelectorAll("dialog.modal").forEach(function (modal) {
            modal.classList.add("w-[min(720px,calc(100vw-2rem))]", "rounded-xl", "border", "border-brand-100", "bg-white", "p-0", "text-slate-900", "shadow-2xl", "backdrop:bg-slate-950/40");
            if (modal.classList.contains("modal-wide")) {
                modal.classList.add("w-[min(980px,calc(100vw-2rem))]");
            }
            const header = modal.querySelector(".modal-header");
            if (header) {
                header.classList.add("flex", "items-start", "gap-3", "border-b", "border-brand-100", "px-5", "py-4");
                header.querySelectorAll(".modal-close").forEach(function (closeButton) {
                    closeButton.remove();
                });
            }
            const forms = modal.querySelectorAll("form");
            forms.forEach(function (form) { form.classList.add("p-5"); });
            if (!modal.querySelector("[data-close-modal], [data-modal-close]") && !modal.querySelector("form")) {
                const actions = document.createElement("div");
                actions.className = "modal-actions";
                actions.innerHTML = `<button type="button" class="btn alt" data-close-modal>Close</button>`;
                modal.appendChild(actions);
            }
        });

        function showToast(message, type) {
            let host = document.getElementById("toast-host");
            if (!host) {
                host = document.createElement("div");
                host.id = "toast-host";
                host.className = "fixed right-4 top-4 z-50 flex w-[min(380px,calc(100vw-2rem))] flex-col gap-2";
                document.body.appendChild(host);
            }
            const toast = document.createElement("div");
            const isError = type === "error";
            toast.className = "rounded-lg border p-4 text-sm font-medium shadow-xl transition " + (isError ? "border-red-200 bg-red-50 text-red-800" : "border-emerald-200 bg-emerald-50 text-emerald-800");
            toast.textContent = message;
            host.appendChild(toast);
            window.setTimeout(function () {
                toast.classList.add("opacity-0");
                window.setTimeout(function () { toast.remove(); }, 250);
            }, 4200);
        }

        window.showToast = showToast;
        document.querySelectorAll(".app-toast-data").forEach(function (node) {
            showToast(node.getAttribute("data-toast-message") || "", node.getAttribute("data-toast-type") || "success");
        });

        const pageLoadingOverlay = document.getElementById("page-loading-overlay");
        let pageLoadingTimer = null;

        function showPageLoading() {
            if (!pageLoadingOverlay) {
                return;
            }
            window.clearTimeout(pageLoadingTimer);
            pageLoadingTimer = window.setTimeout(function () {
                pageLoadingOverlay.classList.add("is-visible");
                pageLoadingOverlay.setAttribute("aria-hidden", "false");
            }, 120);
        }

        function hidePageLoading() {
            if (!pageLoadingOverlay) {
                return;
            }
            window.clearTimeout(pageLoadingTimer);
            pageLoadingOverlay.classList.remove("is-visible");
            pageLoadingOverlay.setAttribute("aria-hidden", "true");
        }

        window.addEventListener("pageshow", hidePageLoading);

        function isSamePageHashLink(link) {
            if (!link.hash) {
                return false;
            }
            return link.origin === window.location.origin
                && link.pathname === window.location.pathname
                && link.search === window.location.search;
        }

        document.addEventListener("click", function (event) {
            const link = event.target.closest("a[href]");
            if (!link || event.defaultPrevented) {
                return;
            }
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            if (link.target && link.target.toLowerCase() !== "_self") {
                return;
            }
            if (link.hasAttribute("download") || link.getAttribute("href").startsWith("#") || isSamePageHashLink(link)) {
                return;
            }

            const url = new URL(link.href, window.location.href);
            if (!["http:", "https:"].includes(url.protocol) || url.origin !== window.location.origin) {
                return;
            }

            showPageLoading();
        });

        document.addEventListener("submit", function (event) {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || event.defaultPrevented) {
                return;
            }
            if (form.target && form.target.toLowerCase() !== "_self") {
                return;
            }
            showPageLoading();
        });

        document.addEventListener("click", function (event) {
            const opener = event.target.closest("[data-open-modal], [data-modal-target]");
            if (opener) {
                const modalId = opener.getAttribute("data-open-modal") || opener.getAttribute("data-modal-target");
                const modal = document.getElementById(modalId);
                if (modal) {
                    // Support for native dialog element
                    if (typeof modal.showModal === "function") {
                        modal.showModal();
                    } else if (modal.style) {
                        // Fallback for custom implementations
                        modal.style.display = "block";
                        modal.classList.add("is-open");
                    }
                }
                event.preventDefault();
                return;
            }

            const closer = event.target.closest("[data-close-modal], [data-modal-close]");
            if (closer) {
                const modal = closer.closest("dialog");
                if (modal) {
                    modal.close();
                }
            }
        });

        document.querySelectorAll("dialog.modal").forEach(function (modal) {
            modal.addEventListener("click", function (event) {
                if (event.target !== modal) {
                    return;
                }

                const rect = modal.getBoundingClientRect();
                const inside = event.clientX >= rect.left && event.clientX <= rect.right
                    && event.clientY >= rect.top && event.clientY <= rect.bottom;
                if (!inside) {
                    modal.close();
                }
            });
        });

        document.querySelectorAll("[data-person-selector]").forEach(function (selector) {
            const searchInput = selector.querySelector("[data-person-search]");
            const hiddenInput = selector.querySelector("[data-person-id]");
            const options = Array.from(selector.querySelectorAll("datalist option"));
            const departmentTarget = selector.getAttribute("data-department-target");
            const nameTarget = selector.getAttribute("data-name-target");
            const codeTarget = selector.getAttribute("data-code-target");
            const roleTarget = selector.getAttribute("data-role-target");

            function setValue(targetId, value, overwrite) {
                if (!targetId) {
                    return;
                }
                const target = document.getElementById(targetId);
                if (!target) {
                    return;
                }
                if (overwrite || target.value.trim() === "") {
                    target.value = value || "";
                }
            }

            function syncPerson() {
                if (!searchInput || !hiddenInput) {
                    return;
                }
                const selected = options.find(function (option) {
                    return option.value === searchInput.value;
                });
                if (!selected) {
                    hiddenInput.value = "";
                    return;
                }
                hiddenInput.value = selected.getAttribute("data-person-id") || "";
                setValue(departmentTarget, selected.getAttribute("data-department") || "", true);
                setValue(nameTarget, selected.getAttribute("data-full-name") || "", true);
                setValue(codeTarget, selected.getAttribute("data-person-code") || "", true);
                setValue(roleTarget, selected.getAttribute("data-role") || "", true);
            }

            if (searchInput) {
                searchInput.addEventListener("input", syncPerson);
                searchInput.addEventListener("change", syncPerson);
                syncPerson();
            }
        });

        document.querySelectorAll("nav details").forEach(function (menu) {
            menu.addEventListener("toggle", function () {
                if (!menu.open) {
                    return;
                }
                document.querySelectorAll("nav details[open]").forEach(function (otherMenu) {
                    if (otherMenu !== menu) {
                        otherMenu.removeAttribute("open");
                    }
                });
            });
        });

        document.addEventListener("click", function (event) {
            document.querySelectorAll("nav details[open]").forEach(function (menu) {
                if (!menu.contains(event.target)) {
                    menu.removeAttribute("open");
                }
            });
        });

        function closeActionMenus() {
            document.querySelectorAll(".action-menu[open]").forEach(function (menu) {
                menu.removeAttribute("open");
            });
        }

        function placeActionMenuPanel(menu, panel) {
            const summary = menu.querySelector("summary");
            if (!summary) {
                return;
            }
            const rect = summary.getBoundingClientRect();
            const panelWidth = panel.offsetWidth || 180;
            const panelHeight = panel.offsetHeight || 120;
            const maxLeft = window.innerWidth - panelWidth - 12;
            const left = Math.max(12, Math.min(rect.right - panelWidth, maxLeft));
            const idealTop = rect.bottom + 8;
            const maxTop = window.innerHeight - panelHeight - 12;
            const top = Math.max(12, Math.min(idealTop, maxTop));

            panel.style.position = "fixed";
            panel.style.left = left + "px";
            panel.style.top = top + "px";
            panel.style.right = "auto";
            panel.style.zIndex = "1000";
        }

        document.querySelectorAll(".action-menu").forEach(function (menu) {
            const panel = menu.querySelector(".action-menu-panel");
            if (!panel) {
                return;
            }
            if (!panel._portalMeta) {
                panel._portalMeta = {
                    parent: panel.parentElement,
                    next: panel.nextSibling
                };
            }

            menu.addEventListener("toggle", function () {
                if (menu.open) {
                    document.body.appendChild(panel);
                    placeActionMenuPanel(menu, panel);
                } else if (panel._portalMeta && panel._portalMeta.parent) {
                    panel.style.position = "";
                    panel.style.left = "";
                    panel.style.top = "";
                    panel.style.right = "";
                    panel.style.zIndex = "";
                    if (panel._portalMeta.next) {
                        panel._portalMeta.parent.insertBefore(panel, panel._portalMeta.next);
                    } else {
                        panel._portalMeta.parent.appendChild(panel);
                    }
                }
            });
        });

        window.addEventListener("resize", closeActionMenus);
        window.addEventListener("scroll", closeActionMenus, true);

        function fieldLabel(field) {
            if (field.id) {
                const label = field.labels && field.labels.length > 0 ? field.labels[0] : null;
                if (label) {
                    return label.textContent.replace("*", "").trim();
                }
            }
            return field.getAttribute("aria-label") || field.name || "This field";
        }

        function validationMessageFor(field) {
            const label = fieldLabel(field);
            if (field.validity.valueMissing) {
                return label + " is required.";
            }
            if (field.validity.tooShort) {
                return label + " must be at least " + field.minLength + " characters.";
            }
            if (field.validity.rangeUnderflow) {
                return label + " must be at least " + field.min + ".";
            }
            if (field.validity.rangeOverflow) {
                return label + " must be no more than " + field.max + ".";
            }
            if (field.validity.typeMismatch) {
                return "Enter a valid " + label.toLowerCase() + ".";
            }
            if (field.validity.patternMismatch) {
                return "Enter a valid " + label.toLowerCase() + ".";
            }
            return field.validationMessage || (label + " is invalid.");
        }

        function clearFormErrors(form) {
            form.querySelectorAll(".field-error").forEach(function (error) { error.remove(); });
            form.querySelectorAll(".field-invalid").forEach(function (field) {
                field.classList.remove("field-invalid");
                field.removeAttribute("aria-invalid");
            });
            const summary = form.querySelector(".form-validation-errors");
            if (summary) {
                summary.remove();
            }
        }

        function showFormErrors(form, invalidFields) {
            clearFormErrors(form);
            const summary = document.createElement("div");
            summary.className = "form-validation-errors";
            summary.setAttribute("role", "alert");
            summary.textContent = "Please fix the highlighted fields before saving.";
            const firstFormChild = form.querySelector("input[type=hidden]") ? form.querySelector("input[type=hidden]").nextSibling : form.firstChild;
            form.insertBefore(summary, firstFormChild || form.firstChild);

            invalidFields.forEach(function (field) {
                field.classList.add("field-invalid");
                field.setAttribute("aria-invalid", "true");
                const error = document.createElement("div");
                error.className = "field-error";
                error.textContent = validationMessageFor(field);
                const wrapper = field.closest("div") || field.parentElement;
                if (wrapper) {
                    wrapper.appendChild(error);
                } else {
                    field.insertAdjacentElement("afterend", error);
                }
            });

            invalidFields[0].focus();
        }

        document.querySelectorAll("form").forEach(function (form) {
            form.setAttribute("novalidate", "novalidate");
            form.addEventListener("input", function (event) {
                if (event.target && event.target.matches("input, select, textarea")) {
                    event.target.classList.remove("field-invalid");
                    event.target.removeAttribute("aria-invalid");
                    const wrapper = event.target.closest("div") || event.target.parentElement;
                    if (wrapper) {
                        wrapper.querySelectorAll(".field-error").forEach(function (error) { error.remove(); });
                    }
                }
            });
            form.addEventListener("submit", function (event) {
                const invalidFields = Array.from(form.querySelectorAll("input, select, textarea")).filter(function (field) {
                    return !field.disabled && field.willValidate && !field.checkValidity();
                });
                if (invalidFields.length > 0) {
                    event.preventDefault();
                    showFormErrors(form, invalidFields);
                }
            });
        });

        function enhanceTable(table, tableIndex) {
            if (table.dataset.enhanced === "1" || table.closest("[data-no-client-table], [data-no-table-enhance], .print-report, .receipt-modal, .receipt-preview")) {
                return;
            }
            const tbody = table.tBodies[0];
            const headers = Array.from(table.tHead ? table.tHead.rows[0].cells : []);
            if (!tbody || headers.length === 0) {
                return;
            }
            table.dataset.enhanced = "1";
            const rows = Array.from(tbody.rows);
            if (rows.length < 2) {
                return;
            }
            const panel = table.closest(".table-card, .data-panel, .table-shell, section");
            const hasServerFilters = Boolean(panel && panel.querySelector(".data-panel-filters, .table-toolbar, .report-controls, .standard-filter-bar"));

            const wrap = table.closest(".table-wrap") || table.parentElement;
            const controls = document.createElement("div");
            controls.className = "table-enhance-controls";
            controls.innerHTML = `
                <div class="min-w-0 flex-1"${hasServerFilters ? " hidden" : ""}>
                    <label for="table-search-${tableIndex}">Search</label>
                    <input id="table-search-${tableIndex}" type="search" aria-label="Search table" placeholder="Search table..." class="table-search">
                </div>
                <div>
                    <label for="table-page-size-${tableIndex}">Rows</label>
                    <select id="table-page-size-${tableIndex}" aria-label="Rows per page" class="table-page-size">
                        <option value="10" selected>10 rows</option>
                    </select>
                </div>`;
            wrap.parentElement.insertBefore(controls, wrap);
            controls.classList.add("standard-filter-bar");
            controls.querySelectorAll(":scope > div").forEach(classifyFilterContainer);
            enhanceFilterSearch(controls.querySelector(".table-search"));

            const pager = document.createElement("nav");
            pager.className = "pagination table-enhance-pager";
            pager.setAttribute("aria-label", "Table pagination");
            wrap.parentElement.insertBefore(pager, wrap.nextSibling);

            let sortIndex = -1;
            let sortDirection = "asc";
            let currentPage = 1;

            headers.forEach(function (header, index) {
                header.addEventListener("click", function () {
                    sortDirection = sortIndex === index && sortDirection === "asc" ? "desc" : "asc";
                    sortIndex = index;
                    currentPage = 1;
                    render();
                });
            });

            controls.addEventListener("input", function () { currentPage = 1; render(); });
            controls.addEventListener("change", function () { currentPage = 1; render(); });

            function cellText(row, index) {
                return (row.cells[index] ? row.cells[index].textContent : "").trim();
            }

            function render() {
                const searchField = controls.querySelector(".table-search");
                const search = searchField ? searchField.value.toLowerCase() : "";
                const pageSize = parseInt(controls.querySelector(".table-page-size").value, 10) || 10;

                let visible = rows.filter(function (row) {
                    const rowText = row.textContent.toLowerCase();
                    return search === "" || rowText.includes(search);
                });

                if (sortIndex >= 0) {
                    visible.sort(function (a, b) {
                        const av = cellText(a, sortIndex);
                        const bv = cellText(b, sortIndex);
                        const an = Number(av.replace(/[^0-9.-]/g, ""));
                        const bn = Number(bv.replace(/[^0-9.-]/g, ""));
                        const result = !Number.isNaN(an) && !Number.isNaN(bn) && av.match(/[0-9]/) && bv.match(/[0-9]/)
                            ? an - bn
                            : av.localeCompare(bv);
                        return sortDirection === "asc" ? result : -result;
                    });
                }

                const totalPages = Math.max(1, Math.ceil(visible.length / pageSize));
                currentPage = Math.min(currentPage, totalPages);
                const start = (currentPage - 1) * pageSize;
                const pageRows = new Set(visible.slice(start, start + pageSize));
                rows.forEach(function (row) { row.hidden = !pageRows.has(row); });

                pager.innerHTML = "";
                const prev = document.createElement("button");
                prev.type = "button";
                prev.className = "page-link page-link-prev";
                prev.textContent = "Previous";
                prev.setAttribute("aria-label", "Previous page");
                prev.disabled = currentPage <= 1;
                prev.classList.toggle("disabled", prev.disabled);
                prev.onclick = function () { currentPage -= 1; render(); };
                const next = document.createElement("button");
                next.type = "button";
                next.className = "page-link page-link-next";
                next.textContent = "Next";
                next.setAttribute("aria-label", "Next page");
                next.disabled = currentPage >= totalPages;
                next.classList.toggle("disabled", next.disabled);
                next.onclick = function () { currentPage += 1; render(); };
                pager.append(prev);
                const pageWindow = 5;
                const firstPage = Math.max(1, Math.min(currentPage - 2, totalPages - pageWindow + 1));
                const lastPage = Math.min(totalPages, firstPage + pageWindow - 1);
                for (let pageNumber = firstPage; pageNumber <= lastPage; pageNumber += 1) {
                    const pageButton = document.createElement("button");
                    pageButton.type = "button";
                    pageButton.className = "page-link";
                    pageButton.textContent = String(pageNumber);
                    pageButton.setAttribute("aria-label", `Page ${pageNumber}`);
                    pageButton.classList.toggle("active", pageNumber === currentPage);
                    if (pageNumber === currentPage) {
                        pageButton.setAttribute("aria-current", "page");
                    }
                    pageButton.onclick = function () { currentPage = pageNumber; render(); };
                    pager.append(pageButton);
                }
                pager.append(next);
            }

            render();
        }

        document.querySelectorAll("table").forEach(enhanceTable);
    </script>';
    echo '<script src="' . h(asset_url('assets/charts.js')) . '"></script>';
    echo '</body>';
    echo '</html>';
}
