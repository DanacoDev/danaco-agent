<?php

require dirname(__DIR__) . '/includes/bootstrap.php';
$config = site_config();
$title = 'Acceptable Use — ' . (string) $config['site_title'];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($title); ?></title>
<?php site_fonts(); ?>
<?php site_icons(); ?>
<link rel="stylesheet" href="<?php echo e(site_href('/assets/site.css')); ?>">
</head>
<body>
<header class="site-header">
    <div class="wrap">
        <a class="wordmark" href="<?php echo e(site_href('/')); ?>"><img class="mark" src="<?php echo e(site_href('/assets/logo.png')); ?>" alt=""><?php echo e((string) $config['site_title']); ?></a>
        <button class="nav-toggle" type="button" data-nav-toggle aria-expanded="false" aria-controls="site-nav">Menu</button>
        <nav class="nav" id="site-nav">
            <a class="nav-link" href="<?php echo e(site_href('/docs/')); ?>">Docs</a>
            <div class="nav-actions">
                <?php echo github_button('btn btn-ghost btn-small'); ?>
                <?php echo buy_button('btn btn-primary btn-small'); ?>
            </div>
        </nav>
    </div>
</header>
<main>
    <section class="section">
        <div class="wrap prose">
            <p class="kicker"><i></i>Legal</p>
            <h1>Acceptable Use Policy</h1>
            <p class="lede">2026-11-06<br></p>

            <h2>1. Intended purpose</h2>
            <p>Agent Danaco is a self-hosted framework for language-model-assisted project work (chat, files, tasks, optional tools) on hardware the operator controls. It is designed to act with a high degree of autonomy within the tools the operator enables.</p>

            <h2>2. Prohibited AI practices</h2>
            <p>You may not use the software for practices that are prohibited under the EU AI Act (or equivalent rules where you operate), including social scoring by public authorities, untargeted scraping of facial images for recognition databases, emotion recognition in the workplace or education where forbidden, and other banned practices as defined by applicable law.</p>

            <h2>3. High-risk uses</h2>
            <p>The software is <strong>not intended</strong> for high-risk AI system use cases under Annex III of the EU AI Act (for example employment decisions, creditworthiness, biometric categorisation or emotion recognition, or critical access to essential private or public services). You must not deploy it for those purposes unless you independently ensure full legal conformity for that deployment. Danaco does not supply a high-risk conformity assessment for this framework.</p>

            <h2>4. Decisions about people</h2>
            <p>Do not use model outputs as the sole basis for decisions that produce legal effects or similarly significant effects on individuals. Where your work affects people, keep a human responsible for the outcome.</p>

            <h2>5. Operator responsibility</h2>
            <p>You choose the purpose, data, models, and tools for each deployment. You are responsible for compliance with the laws that apply to you (including the EU AI Act where it applies, and similar rules elsewhere). Optional oversight tools in the product do not by themselves make a deployment lawful or “certified.”</p>

            <h2>6. Security and abuse</h2>
            <p>Do not use the software to attack systems you are not authorised to access, to distribute malware, or to violate others’ rights, privacy, or intellectual property.</p>

            <h2>7. Related terms</h2>
            <p>Also see the <a class="text-link" href="<?php echo e(site_href('/legal/terms.php')); ?>">Terms of Use</a>.</p>
        </div>
    </section>
</main>
<footer class="site-footer">
    <div class="wrap">
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
<?php site_scripts(); ?>
</body>
</html>
