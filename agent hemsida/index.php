<?php

require __DIR__ . '/includes/bootstrap.php';
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\d+$/', '', $host) ?? $host;
if ($host === strtolower((string) site_config()['docs_host'])) {
    header('Location: ' . site_href('/docs/'), true, 302);
    exit;
}
$content = require __DIR__ . '/content.php';
$config = site_config();
$title = (string) $config['site_title'];
$hero = $content['hero'];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#ffffff">
<title><?php echo e($title); ?></title>
<meta name="description" content="<?php echo e($hero['lede']); ?>">
<?php site_fonts(); ?>
<?php site_icons(); ?>
<link rel="stylesheet" href="<?php echo e(site_href('/assets/site.css')); ?>">
</head>
<body>
<header class="site-header">
    <div class="wrap">
        <a class="wordmark" href="<?php echo e(site_href('/')); ?>"><img class="mark" src="<?php echo e(site_href('/assets/logo.png')); ?>" alt=""><?php echo e($title); ?></a>
        <button class="nav-toggle" type="button" data-nav-toggle aria-expanded="false" aria-controls="site-nav">Menu</button>
        <nav class="nav" id="site-nav" data-nav>
            <a class="nav-link" href="<?php echo e(site_href('/docs/')); ?>">Docs</a>
            <div class="nav-actions">
                <?php echo github_button('btn btn-ghost btn-small'); ?>
                <?php echo buy_button('btn btn-primary btn-small'); ?>
            </div>
        </nav>
    </div>
</header>
<main>
    <section class="hero">
        <svg class="hero-net" viewBox="0 0 1200 640" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
            <g fill="none" stroke="#080809" stroke-width="1">
                <path d="M80 120 L210 70 L340 150" opacity="0.12"/>
                <path d="M210 70 L260 190" opacity="0.08"/>
                <path d="M860 90 L980 60 L1120 140 L1040 230" opacity="0.12"/>
                <path d="M980 60 L900 180" opacity="0.08"/>
                <path d="M40 420 L150 360 L90 520" opacity="0.1"/>
                <path d="M150 360 L240 470" opacity="0.07"/>
                <path d="M960 430 L1100 390 L1160 520 L1020 560" opacity="0.12"/>
                <path d="M1100 390 L1080 500" opacity="0.08"/>
            </g>
            <g fill="#080809">
                <circle cx="80" cy="120" r="2.2" opacity="0.28"/>
                <circle cx="210" cy="70" r="1.6" opacity="0.2"/>
                <circle cx="340" cy="150" r="2" opacity="0.22"/>
                <circle cx="260" cy="190" r="1.4" opacity="0.16"/>
                <circle cx="860" cy="90" r="2" opacity="0.22"/>
                <circle cx="980" cy="60" r="1.6" opacity="0.18"/>
                <circle cx="1120" cy="140" r="2.2" opacity="0.26"/>
                <circle cx="1040" cy="230" r="1.5" opacity="0.16"/>
                <circle cx="900" cy="180" r="1.4" opacity="0.14"/>
                <circle cx="40" cy="420" r="1.8" opacity="0.2"/>
                <circle cx="150" cy="360" r="2.2" opacity="0.24"/>
                <circle cx="90" cy="520" r="1.5" opacity="0.16"/>
                <circle cx="240" cy="470" r="1.4" opacity="0.14"/>
                <circle cx="960" cy="430" r="2" opacity="0.22"/>
                <circle cx="1100" cy="390" r="1.6" opacity="0.18"/>
                <circle cx="1160" cy="520" r="2.2" opacity="0.26"/>
                <circle cx="1020" cy="560" r="1.5" opacity="0.16"/>
                <circle cx="1080" cy="500" r="1.4" opacity="0.14"/>
            </g>
            <g fill="#ff6b2b">
                <circle cx="340" cy="150" r="2.2" opacity="0.55"/>
                <circle cx="860" cy="90" r="2.2" opacity="0.45"/>
                <circle cx="150" cy="360" r="2.2" opacity="0.4"/>
            </g>
        </svg>
        <div class="wrap">
            <p class="kicker"><i></i><?php echo e($hero['kicker']); ?></p>
            <h1>
                <span><?php echo e($hero['lines'][0]); ?></span>
                <span><?php echo e($hero['lines'][1]); ?></span>
                <span class="accent"><?php echo e($hero['lines'][2]); ?></span>
            </h1>
            <p class="lede"><?php echo e($hero['lede']); ?></p>
            <div class="hero-actions">
                <?php echo buy_button(); ?>
                <?php echo github_button(); ?>
            </div>
            <p class="micro">
                <span><?php echo e($hero['note']); ?></span>
                <a href="#framework">View features</a>
            </p>
        </div>
        <div class="wrap shot-wrap">
            <div class="shot-row">
                <?php foreach ($content['shots'] as $shot): ?>
                <button class="shot" type="button" data-shot data-caption="<?php echo e($shot['title']); ?>" data-alt="<?php echo e($shot['alt']); ?>">
                    <img src="<?php echo e(site_href($shot['src'])); ?>" alt="">
                    <span><?php echo e($shot['title']); ?></span>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <section class="trust">
        <div class="wrap trust-row">
            <?php foreach ($content['trust'] as $item): ?>
            <div class="trust-item">
                <span class="icon" aria-hidden="true"><span class="dot"></span></span>
                <div>
                    <strong><?php echo e($item['title']); ?></strong>
                    <p><?php echo e($item['text']); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="section" id="requirements">
        <div class="wrap">
            <div class="section-head">
                <p class="kicker"><i></i><?php echo e($content['requirements']['kicker']); ?></p>
                <h2><?php echo e($content['requirements']['title']); ?></h2>
            </div>
            <div class="steps">
                <?php foreach ($content['requirements']['items'] as $item): ?>
                <article class="step">
                    <h3><?php echo e($item['title']); ?></h3>
                    <p><?php echo e($item['text']); ?></p>
                </article>
                <?php endforeach; ?>
            </div>
            <p class="lede req-note"><?php echo e($content['requirements']['note']); ?></p>
        </div>
    </section>
    <section class="section tight" id="features">
        <div class="wrap">
            <div class="section-head">
                <p class="kicker"><i></i><?php echo e($content['values']['kicker']); ?></p>
                <h2><?php echo e($content['values']['title']); ?></h2>
            </div>
            <div class="card-grid">
                <?php foreach ($content['values']['cards'] as $index => $card): ?>
                <article class="card">
                    <div class="num"><?php echo e(sprintf('%02d', $index + 1)); ?></div>
                    <h3><?php echo e($card['title']); ?></h3>
                    <p><?php echo e($card['text']); ?></p>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <section class="section tight">
        <div class="wrap">
            <div class="section-head">
                <p class="kicker"><i></i><?php echo e($content['steps']['kicker']); ?></p>
                <h2><?php echo e($content['steps']['title']); ?></h2>
            </div>
            <div class="steps">
                <?php foreach ($content['steps']['items'] as $index => $step): ?>
                <article class="step">
                    <div class="num"><?php echo e(sprintf('%02d', $index + 1)); ?></div>
                    <h3><?php echo e($step['title']); ?></h3>
                    <p><?php echo e($step['text']); ?></p>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <section class="section tight">
        <div class="wrap terminal-wrap">
            <div>
                <p class="kicker"><i></i><?php echo e($content['terminal']['kicker']); ?></p>
                <h2><?php echo e($content['terminal']['title']); ?></h2>
            </div>
            <div class="terminal">
                <div class="terminal-bar" aria-hidden="true"><span></span><span></span><span></span></div>
                <pre><span class="prompt">&gt; <?php echo e($content['terminal']['prompt']); ?></span>
<?php echo e($content['terminal']['lines'][0]); ?>

<span class="prompt">&gt; <?php echo e($content['terminal']['lines'][1]); ?></span></pre>
            </div>
        </div>
    </section>
    <section class="section tight" id="framework">
        <div class="wrap">
            <div class="section-head">
                <p class="kicker"><i></i><?php echo e($content['problem']['kicker']); ?></p>
                <h2><?php echo e($content['problem']['title']); ?></h2>
                <p class="lede"><?php echo e($content['problem']['lede']); ?></p>
            </div>
            <div class="problem-grid">
                <?php foreach ($content['problem']['pains'] as $pain): ?>
                <article class="problem-box">
                    <h3><span class="check-icon" aria-hidden="true">✓</span><?php echo e($pain['title']); ?></h3>
                    <p><?php echo e($pain['text']); ?></p>
                </article>
                <?php endforeach; ?>
                <?php foreach ($content['problem']['fixes'] as $fix): ?>
                <article class="problem-box">
                    <h3><span class="check-icon" aria-hidden="true">✓</span><?php echo e($fix['title']); ?></h3>
                    <p><?php echo e($fix['text']); ?></p>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <section class="section tight">
        <div class="wrap">
            <div class="section-head">
                <p class="kicker"><i></i><?php echo e($content['features']['kicker']); ?></p>
                <h2><?php echo e($content['features']['title']); ?></h2>
            </div>
            <div class="feature-grid">
                <?php foreach ($content['features']['highlights'] as $feature): ?>
                <article class="feature">
                    <h3><?php echo e($feature['title']); ?></h3>
                    <p><?php echo e($feature['text']); ?></p>
                </article>
                <?php endforeach; ?>
            </div>
            <article class="spotlight">
                <p class="kicker"><i></i><?php echo e($content['features']['updates']['kicker']); ?></p>
                <h3><?php echo e($content['features']['updates']['title']); ?></h3>
                <p><?php echo e($content['features']['updates']['text']); ?></p>
            </article>
            <ul class="plain-list">
                <?php foreach ($content['features']['list'] as $line): ?>
                <li><?php echo e($line); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
    <section class="section tight" id="eu-ai-act">
        <div class="wrap">
            <div class="section-head">
                <p class="kicker"><i></i><?php echo e($content['compliance']['kicker']); ?></p>
                <h2><?php echo e($content['compliance']['title']); ?></h2>
                <p class="lede"><?php echo e($content['compliance']['lede']); ?></p>
            </div>
            <div class="card-grid">
                <?php foreach ($content['compliance']['points'] as $index => $point): ?>
                <article class="card">
                    <div class="num"><?php echo e(sprintf('%02d', $index + 1)); ?></div>
                    <h3><?php echo e($point['title']); ?></h3>
                    <p><?php echo e($point['text']); ?></p>
                </article>
                <?php endforeach; ?>
            </div>
            <p class="micro compliance-note"><?php echo e($content['compliance']['note']); ?></p>
        </div>
    </section>
    <section class="section tight">
        <div class="wrap">
            <div class="section-head">
                <p class="kicker"><i></i><?php echo e($content['compare']['kicker']); ?></p>
                <h2><?php echo e($content['compare']['title']); ?></h2>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <?php foreach ($content['compare']['headers'] as $header): ?>
                            <th><?php echo e($header); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($content['compare']['rows'] as $row): ?>
                        <tr>
                            <?php foreach ($row as $cell): ?>
                            <td><?php echo e($cell); ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>
<footer class="site-footer">
    <div class="wrap">
        <p><?php echo e($content['footer']['note']); ?></p>
        <div class="footer-end">
            <div class="footer-links">
                <a href="<?php echo e(site_href('/docs/')); ?>">Docs</a>
                <a href="<?php echo e(site_href('/legal/terms.php')); ?>">Terms</a>
                <a href="<?php echo e(site_href('/legal/aup.php')); ?>">Acceptable Use</a>
                <a href="<?php echo e((string) $config['github_url']); ?>" target="_blank" rel="noopener noreferrer">Source on Github</a>
                <a href="#" onclick="alert('Coming soon!'); return false;">Buy installer $20</a>
            </div>
            <img class="footer-mark" src="<?php echo e(site_href('/assets/logo.png')); ?>" alt="">
        </div>
    </div>
</footer>
<dialog class="lightbox" data-lightbox aria-label="Screenshot">
    <div class="lightbox-stage">
        <button class="lightbox-nav prev" type="button" data-lightbox-prev aria-label="Previous screenshot">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M14.5 6.5 9 12l5.5 5.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <figure class="lightbox-frame">
            <button class="lightbox-close" type="button" data-lightbox-close aria-label="Close">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
            </button>
            <img alt="" data-lightbox-img>
            <figcaption class="lightbox-caption">
                <span data-lightbox-caption></span>
                <span data-lightbox-index></span>
            </figcaption>
        </figure>
        <button class="lightbox-nav next" type="button" data-lightbox-next aria-label="Next screenshot">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M9.5 6.5 15 12l-5.5 5.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
    </div>
</dialog>
<?php site_scripts(); ?>
</body>
</html>
