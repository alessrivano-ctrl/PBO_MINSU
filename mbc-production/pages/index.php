<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$sections = $pdo->query('SELECT section_key, title, body FROM business_center_content WHERE is_active = 1 ORDER BY FIELD(section_key, "hero", "mission_vision", "services", "features", "contact", "footer"), section_key')->fetchAll();
$byKey = [];
foreach ($sections as $section) {
    $byKey[(string) $section['section_key']] = $section;
}

function landing_section(array $sections, string $key, string $title, string $body = ''): array
{
    return $sections[$key] ?? [
        'section_key' => $key,
        'title' => $title,
        'body' => $body,
    ];
}

function landing_lines(?string $body, array $fallback): array
{
    $lines = array_values(array_filter(array_map(
        static fn (string $line): string => trim($line),
        preg_split('/\R+/', (string) $body) ?: []
    )));

    return count($lines) >= 2 ? $lines : $fallback;
}

function landing_vmgo_texts(?string $body, string $defaultVision, string $defaultMission): array
{
    $raw = trim((string) $body);
    if ($raw === '') {
        return [$defaultVision, $defaultMission];
    }

    if (preg_match('/Vision\s*:?\s*(.*?)\s+Mission\s*:?\s*(.*)/is', $raw, $matches) === 1) {
        $vision = trim((string) $matches[1]);
        $mission = trim((string) $matches[2]);

        return [
            $vision !== '' ? $vision : $defaultVision,
            $mission !== '' ? $mission : $defaultMission,
        ];
    }

    return [$defaultVision, $defaultMission];
}

$org = organization_profile($pdo);
$heroSubtitle = $org['campus_display_name'] ?? 'Mindoro State University Bongabong Campus';
$hero = landing_section(
    $byKey,
    'hero',
    'Production and Business Operation Services',
    'Sales, inventory, cash flow, rentals, fishpond operations, proposal requests, transaction logs, and official reports in one record management system.'
);

$defaultVision = 'The Mindoro State University is a center of excellence in agriculture and fishery, science, technology, culture and education of globally competitive lifelong learners in a diverse yet cohesive society.';
$defaultMission = 'The University commits to produce 21st-century skilled lifelong learners and generates and commercializes innovative technologies by providing excellent and relevant services in instruction, research, extension, and production through industry-driven curricula, collaboration, internationalization, and continual organizational growth for sustainable development.';
$missionVision = landing_section($byKey, 'mission_vision', 'Mission and Vision', "Vision: {$defaultVision}\n\nMission: {$defaultMission}");
[$visionText, $missionText] = landing_vmgo_texts($missionVision['body'] ?? '', $defaultVision, $defaultMission);

$servicesSection = landing_section($byKey, 'services', 'Campus Services', 'Daily operation tools for the campus business center and income-generating projects.');
$featuresSection = landing_section($byKey, 'features', 'What the System Helps Manage');
$contactSection = landing_section($byKey, 'contact', 'Visit the Business Center', 'Mindoro State University Bongabong Campus Production and Business Operation Office.');
$footerSection = landing_section($byKey, 'footer', 'Production and Business Operation Record Management System');

$services = [
    ['label' => 'POS', 'title' => 'POS and Sales', 'description' => 'Record transactions, issue OR references, and monitor daily revenue.'],
    ['label' => 'INV', 'title' => 'Inventory and Stock', 'description' => 'Track items, services, low-stock alerts, costs, prices, and stock movement.'],
    ['label' => 'IGP', 'title' => 'Business Operation', 'description' => 'Manage fishpond, rental, toga, printing, photocopy, and other project records.'],
    ['label' => 'REP', 'title' => 'Reports and Audit', 'description' => 'Generate formal reports, review cash flow, and keep accountable activity logs.'],
];

$features = landing_lines($featuresSection['body'] ?? '', [
    'Sales records and POS transactions',
    'Cash in, expenses, and net cash monitoring',
    'Inventory catalog, low stock alerts, and stock ledger',
    'Fishpond monitoring, harvest income, and expense records',
    'Stall rentals, toga releases, payments, and overdue records',
    'Proposal requests and administrative approval workflow',
    'Automatic transaction logs for POS and service activity',
    'Printable official reports for campus operations',
]);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($hero['title']) ?> | <?= h($heroSubtitle) ?></title>
    <style>
        :root {
            --page-bg: #F7F9F6;
            --surface: #FFFFFF;
            --primary: #14532D;
            --primary-deep: #0B2F1A;
            --primary-soft: #E7F1E8;
            --success-soft: #CFE6D2;
            --gold: #D6A51F;
            --gold-soft: #FFF8E1;
            --text: #102018;
            --muted: #4B5C50;
            --border: #CFE6D2;
            --shadow: 0 16px 42px rgba(16, 32, 24, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            background: var(--page-bg);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.6;
        }

        a {
            color: inherit;
        }

        .site-header {
            position: sticky;
            top: 0;
            z-index: 30;
            border-bottom: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(12px);
        }

        .nav-inner {
            display: flex;
            width: min(100% - 2rem, 1280px);
            min-height: 4.25rem;
            margin: 0 auto;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .brand {
            display: flex;
            min-width: 0;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
        }

        .brand img {
            width: 2.75rem;
            height: 2.75rem;
            flex: 0 0 auto;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: #fff;
            object-fit: contain;
            padding: 0.2rem;
        }

        .brand strong,
        .brand span {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .brand strong {
            font-size: 0.95rem;
            line-height: 1.2;
        }

        .brand span {
            color: var(--muted);
            font-size: 0.78rem;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }

        .nav-links a {
            border-radius: 0.375rem;
            padding: 0.5rem 0.75rem;
            color: var(--primary);
            font-size: 0.9rem;
            font-weight: 700;
            text-decoration: none;
        }

        .nav-links a:hover,
        .nav-links a:focus-visible {
            background: var(--primary-soft);
            outline: none;
        }

        .hero {
            position: relative;
            display: flex;
            min-height: clamp(25rem, 68svh, 36rem);
            align-items: center;
            overflow: hidden;
            background: linear-gradient(90deg, rgba(11, 47, 26, 0.88), rgba(20, 83, 45, 0.66), rgba(20, 83, 45, 0.24)), url('assets/images/slides_1.jpg');
            background-position: center;
            background-size: cover;
            color: #fff;
        }

        .hero-inner,
        .section-inner {
            width: min(100% - 2rem, 1280px);
            margin: 0 auto;
        }

        .hero-copy {
            max-width: 46rem;
            padding: 4rem 0;
        }

        .eyebrow {
            margin: 0 0 0.75rem;
            color: #F8E8A6;
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .hero h1 {
            margin: 0;
            max-width: 44rem;
            font-size: clamp(2.2rem, 6vw, 4.4rem);
            font-weight: 800;
            letter-spacing: 0;
            line-height: 1.02;
        }

        .hero-lead {
            max-width: 40rem;
            margin: 1.25rem 0 0;
            color: rgba(255, 255, 255, 0.92);
            font-size: clamp(1rem, 2vw, 1.2rem);
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1.75rem;
        }

        .btn {
            display: inline-flex;
            min-height: 2.75rem;
            align-items: center;
            justify-content: center;
            border-radius: 0.375rem;
            padding: 0.7rem 1rem;
            border: 1px solid transparent;
            font-weight: 800;
            text-decoration: none;
            transition: background 160ms ease, border-color 160ms ease, color 160ms ease, transform 160ms ease;
        }

        .btn:hover,
        .btn:focus-visible {
            transform: translateY(-1px);
            outline: 3px solid rgba(214, 165, 31, 0.45);
            outline-offset: 2px;
        }

        .btn-primary {
            min-width: 8rem;
            padding-inline: 1.35rem;
            background: var(--gold);
            color: var(--primary-deep);
        }

        .hero-facts {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.75rem;
            max-width: 44rem;
            margin-top: 2rem;
        }

        .hero-fact {
            border-left: 3px solid var(--gold);
            background: rgba(255, 255, 255, 0.12);
            padding: 0.8rem 0.9rem;
        }

        .hero-fact strong {
            display: block;
            color: #fff;
            font-size: 0.92rem;
        }

        .hero-fact span {
            display: block;
            margin-top: 0.2rem;
            color: rgba(255, 255, 255, 0.78);
            font-size: 0.78rem;
            line-height: 1.35;
        }

        .section {
            padding: clamp(3rem, 7vw, 5rem) 0;
        }

        .section.surface {
            background: var(--surface);
        }

        .section-head {
            max-width: 48rem;
            margin-bottom: 2rem;
        }

        .section-head.center {
            margin-left: auto;
            margin-right: auto;
            text-align: center;
        }

        .section-kicker {
            margin: 0 0 0.45rem;
            color: var(--primary);
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .section-title {
            margin: 0;
            color: var(--text);
            font-size: clamp(1.65rem, 3vw, 2.4rem);
            line-height: 1.15;
        }

        .section-copy {
            margin: 0.8rem 0 0;
            color: var(--muted);
            font-size: 1rem;
        }

        .vmgo-section {
            background: linear-gradient(180deg, #FFFFFF 0%, #F7F9F6 100%);
        }

        .vmgo-grid,
        .services-grid,
        .feature-grid {
            display: grid;
            gap: 1rem;
        }

        .vmgo-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .vmgo-panel,
        .service-card,
        .feature-item,
        .contact-panel {
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            background: #fff;
        }

        .vmgo-panel {
            position: relative;
            min-height: 14rem;
            padding: 1.5rem;
            box-shadow: 0 10px 28px rgba(16, 32, 24, 0.07);
        }

        .vmgo-panel::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 0.35rem;
            border-radius: 0.5rem 0.5rem 0 0;
            background: var(--gold);
        }

        .vmgo-panel h3 {
            margin: 0 0 0.75rem;
            color: var(--primary);
            font-size: 1.1rem;
        }

        .vmgo-panel p {
            margin: 0;
            color: var(--text);
            font-size: 1rem;
        }

        .source-link {
            display: inline-flex;
            margin-top: 1rem;
            color: var(--primary);
            font-size: 0.86rem;
            font-weight: 700;
            text-decoration: none;
        }

        .source-link:hover,
        .source-link:focus-visible {
            text-decoration: underline;
            outline: none;
        }

        .services-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .service-card {
            display: flex;
            min-height: 13rem;
            flex-direction: column;
            gap: 0.9rem;
            padding: 1.25rem;
            transition: border-color 160ms ease, box-shadow 160ms ease, transform 160ms ease;
        }

        .service-card:hover {
            border-color: var(--gold);
            box-shadow: var(--shadow);
            transform: translateY(-2px);
        }

        .service-label {
            display: inline-flex;
            width: 3rem;
            height: 3rem;
            align-items: center;
            justify-content: center;
            border-radius: 0.5rem;
            background: var(--primary);
            color: #fff;
            font-size: 0.82rem;
            font-weight: 800;
        }

        .service-card h3 {
            margin: 0;
            font-size: 1.05rem;
            line-height: 1.25;
        }

        .service-card p {
            margin: 0;
            color: var(--muted);
            font-size: 0.95rem;
        }

        .feature-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .feature-item {
            border-left: 4px solid var(--gold);
            padding: 1rem;
            color: var(--text);
            font-size: 0.95rem;
            font-weight: 700;
        }

        .contact-panel {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(18rem, 0.9fr);
            gap: 1.5rem;
            padding: clamp(1.25rem, 3vw, 2rem);
        }

        .contact-panel h2,
        .contact-panel h3 {
            margin: 0;
        }

        .contact-panel p {
            margin: 0.75rem 0 0;
            color: var(--muted);
        }

        .contact-details {
            border-radius: 0.5rem;
            background: var(--primary-soft);
            padding: 1rem;
        }

        .footer {
            border-top: 3px solid var(--gold);
            background: var(--primary-deep);
            color: #fff;
            padding: 2rem 0;
        }

        .footer-inner {
            display: flex;
            width: min(100% - 2rem, 1280px);
            margin: 0 auto;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .footer strong,
        .footer span {
            display: block;
        }

        .footer span,
        .footer p {
            color: rgba(255, 255, 255, 0.78);
            font-size: 0.9rem;
        }

        .footer p {
            margin: 0;
            text-align: right;
        }

        @media (max-width: 1080px) {
            .services-grid,
            .feature-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .nav-inner {
                min-height: auto;
                padding: 0.75rem 0;
                align-items: flex-start;
            }

            .brand strong,
            .brand span {
                white-space: normal;
            }

            .nav-links {
                flex-wrap: wrap;
                justify-content: flex-end;
            }

            .hero {
                min-height: clamp(25rem, 72svh, 31rem);
                background-position: 58% center;
            }

            .hero-copy {
                padding: 3rem 0;
            }

            .hero-facts,
            .vmgo-grid,
            .services-grid,
            .feature-grid,
            .contact-panel {
                grid-template-columns: 1fr;
            }

            .hero-fact {
                padding: 0.7rem 0.8rem;
            }

            .footer-inner {
                align-items: flex-start;
                flex-direction: column;
            }

            .footer p {
                text-align: left;
            }
        }

        @media (max-width: 480px) {
            .nav-inner,
            .hero-inner,
            .section-inner,
            .footer-inner {
                width: min(100% - 1rem, 1280px);
            }

            .brand img {
                width: 2.35rem;
                height: 2.35rem;
            }

            .hero-actions .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="nav-inner">
            <a class="brand" href="index.php" aria-label="<?= h($heroSubtitle) ?>">
                <img src="<?= h($org['logo_path']) ?>" alt="<?= h($heroSubtitle) ?> logo">
                <span>
                    <strong><?= h($heroSubtitle) ?></strong>
                    <span>Production and Business Operation</span>
                </span>
            </a>
            <nav class="nav-links" aria-label="Landing page navigation">
                <a href="#mission">Mission</a>
                <a href="#services">Services</a>
                <a href="#contact">Contact</a>
            </nav>
        </div>
    </header>

    <main>
        <section class="hero" aria-label="Production and Business Operation">
            <div class="hero-inner">
                <div class="hero-copy">
                    <p class="eyebrow"><?= h($heroSubtitle) ?></p>
                    <h1><?= h($hero['title']) ?></h1>
                    <p class="hero-lead"><?= h($hero['body']) ?></p>
                    <div class="hero-actions">
                        <a href="login.php" class="btn btn-primary">Log In</a>
                    </div>
                    <div class="hero-facts" aria-label="System coverage">
                        <div class="hero-fact"><strong>POS</strong><span>Sales, OR records, and daily cash.</span></div>
                        <div class="hero-fact"><strong>Inventory</strong><span>Stock levels, service catalog, and ledger.</span></div>
                        <div class="hero-fact"><strong>Projects</strong><span>Fishpond, rentals, proposals, and reports.</span></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="section vmgo-section" id="mission">
            <div class="section-inner">
                <div class="section-head center">
                    <p class="section-kicker">Institutional Direction</p>
                    <h2 class="section-title"><?= h($missionVision['title']) ?></h2>
                </div>
                <div class="vmgo-grid">
                    <article class="vmgo-panel">
                        <h3>Vision</h3>
                        <p><?= h($visionText) ?></p>
                    </article>
                    <article class="vmgo-panel">
                        <h3>Mission</h3>
                        <p><?= h($missionText) ?></p>
                    </article>
                </div>
                <a class="source-link" href="https://minsu.edu.ph/about/vmgo" target="_blank" rel="noopener">Source: Mindoro State University VMGO</a>
            </div>
        </section>

        <section class="section surface" id="services">
            <div class="section-inner">
                <div class="section-head">
                    <p class="section-kicker">Campus Operations</p>
                    <h2 class="section-title"><?= h($servicesSection['title']) ?></h2>
                    <?php if (trim((string) ($servicesSection['body'] ?? '')) !== ''): ?>
                        <p class="section-copy"><?= h($servicesSection['body']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="services-grid">
                    <?php foreach ($services as $service): ?>
                        <article class="service-card">
                            <span class="service-label"><?= h($service['label']) ?></span>
                            <h3><?= h($service['title']) ?></h3>
                            <p><?= h($service['description']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="section">
            <div class="section-inner">
                <div class="section-head center">
                    <p class="section-kicker">Record Coverage</p>
                    <h2 class="section-title"><?= h($featuresSection['title']) ?></h2>
                </div>
                <div class="feature-grid">
                    <?php foreach ($features as $feature): ?>
                        <div class="feature-item"><?= h($feature) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="section surface" id="contact">
            <div class="section-inner">
                <div class="contact-panel">
                    <div>
                        <p class="section-kicker">Campus Office</p>
                        <h2 class="section-title"><?= h($contactSection['title']) ?></h2>
                        <p><?= nl2br(h($contactSection['body'])) ?></p>
                    </div>
                    <div class="contact-details">
                        <h3><?= h($heroSubtitle) ?></h3>
                        <p><strong>Office:</strong> Production and Business Operation</p>
                        <p><strong>System:</strong> <?= h($footerSection['title']) ?></p>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="footer">
        <div class="footer-inner">
            <div>
                <strong><?= h($heroSubtitle) ?></strong>
                <span><?= h($footerSection['title']) ?></span>
            </div>
            <p>&copy; <?= date('Y') ?> Mindoro State University. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
