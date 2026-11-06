<?php

require dirname(__DIR__) . '/includes/bootstrap.php';
$config = site_config();
$title = 'Terms of Use — ' . (string) $config['site_title'];
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
            <h1>Terms of Use</h1>
            <p class="lede">2026-11-06<br></p>

            <h2>1. What you get</h2>
            <p>Danaco Agent is a self-hosted <strong>software framework</strong> (orchestrator, Web UI, skills, and related tooling). The open-source code is offered under the MIT License. A paid packaged installer, if purchased, is a convenience for setup. Danaco does not operate your deployment as a hosted AI service.</p>

            <h2>2. Your role</h2>
            <p>You (or your organisation) install, configure, and operate the software. You choose models, tools, data, and the purpose of each deployment. You are the operator of that deployment and are responsible for lawful use under the laws that apply to you.</p>

            <h2>3. No warranty</h2>
            <p>The software and installer are provided <strong>as is</strong>, without warranty of any kind, express or implied, including merchantability, fitness for a particular purpose, and non-infringement. Outputs of language models can be wrong, incomplete, or unsuitable. Do not treat them as professional, legal, medical, or financial advice.</p>

            <h2>4. Limitation of liability</h2>
            <p>To the maximum extent permitted by applicable law, Danaco and its proprietor are not liable for any indirect, incidental, special, consequential, or punitive damages, or for loss of profits, data, goodwill, or business interruption, arising from the software or your use of it. Where liability cannot be excluded, it is limited to the amount you paid for the packaged installer in the twelve months before the claim (or zero if you use only the free MIT source).</p>

            <h2>5. Acceptable use</h2>
            <p>Your use must also follow the <a class="text-link" href="<?php echo e(site_href('/legal/aup.php')); ?>">Acceptable Use Policy</a>.</p>

            <h2>6. Third-party models and services</h2>
            <p>If you enable a cloud model, search provider, email, FTP, MCP, or other external service, that provider’s terms and privacy rules apply between you and them. Danaco is not a party to those arrangements.</p>

            <h2>7. Governing law</h2>
            <p>These terms are governed by the laws of Sweden, without regard to conflict-of-law rules, except where mandatory consumer protections in your country apply. Courts in Sweden have non-exclusive jurisdiction, subject to those protections.</p>

            <h2>8. Changes</h2>
            <p>Updated terms may be published with a new version number. Continued use after you accept a new version means you agree to that version.</p>
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
