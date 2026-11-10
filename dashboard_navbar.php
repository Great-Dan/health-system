<?php

declare(strict_types=1);

/**
 * Reusable Responsive Dashboard Navbar with Hamburger Profile & Security Menu
 * Variables expected (optional):
 *   $portalSubtitle (string) - e.g. "System Administration", "Financial Operations", "Patient Access"
 *   $portalRole (string)     - e.g. "admin", "accountant", "patient"
 */

$userFullName = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User');
$userUsername = (string)($_SESSION['username'] ?? 'user');
$userRole     = (string)($portalRole ?? $_SESSION['role'] ?? 'user');

$roleBadges = [
    'admin'      => ['class' => 'badge-role-admin',      'label' => 'Admin'],
    'accountant' => ['class' => 'badge-role-accountant', 'label' => 'Accountant'],
    'patient'    => ['class' => 'badge-role-patient',    'label' => 'Patient'],
];
$badgeInfo = $roleBadges[$userRole] ?? ['class' => 'badge-role-patient', 'label' => ucfirst($userRole)];
$initial = strtoupper(function_exists('mb_substr') ? mb_substr($userFullName, 0, 1) : substr($userFullName, 0, 1));
if ($initial === '') {
    $initial = 'U';
}
?>
<nav class="portal-navbar admin-navbar" aria-label="Portal Navigation">
    <div class="admin-brand">
        <a href="dashboard.php" class="brand-title-link">
            <strong>SecureHealth Portal</strong>
            <span class="admin-brand-subtitle"><?= e($portalSubtitle ?? 'Healthcare System') ?></span>
        </a>
    </div>

    <!-- Responsive Hamburger & User Profile Container -->
    <div class="hamburger-menu-container">
        <button type="button" 
                class="hamburger-toggle-btn" 
                id="userMenuToggle" 
                aria-label="Toggle user navigation and profile menu" 
                aria-expanded="false" 
                aria-haspopup="true"
                aria-controls="userDropdownMenu">
            <div class="hamburger-user-preview">
                <span class="user-avatar-initial"><?= e($initial) ?></span>
                <span class="user-preview-name"><?= e($userFullName) ?></span>
                <span class="status-badge <?= e($badgeInfo['class']) ?> user-preview-badge"><?= e($badgeInfo['label']) ?></span>
            </div>
            <div class="hamburger-icon-bars" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </button>

        <!-- Slide/Drop User Navigation Menu -->
        <div class="user-dropdown-menu" id="userDropdownMenu" role="menu" aria-labelledby="userMenuToggle">
            <!-- User Information Card -->
            <div class="user-dropdown-header">
                <div class="user-dropdown-avatar" aria-hidden="true">
                    <?= e($initial) ?>
                </div>
                <div class="user-dropdown-info">
                    <strong class="user-dropdown-name"><?= e($userFullName) ?></strong>
                    <span class="user-dropdown-username">@<?= e($userUsername) ?></span>
                    <div>
                        <span class="status-badge <?= e($badgeInfo['class']) ?>"><?= e($badgeInfo['label']) ?></span>
                    </div>
                </div>
            </div>

            <div class="user-dropdown-divider"></div>

            <!-- Security & Account Management -->
            <div class="user-dropdown-section">
                <span class="dropdown-section-title">Security &amp; Account</span>
                <a href="change_password.php" class="user-dropdown-item" role="menuitem">
                    <svg class="dropdown-item-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                    <span>Change Password</span>
                </a>
                <a href="security_questions_change.php" class="user-dropdown-item" role="menuitem">
                    <svg class="dropdown-item-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    </svg>
                    <span>Change Security Questions</span>
                </a>
            </div>

            <div class="user-dropdown-divider"></div>

            <!-- Session Termination -->
            <div class="user-dropdown-section">
                <a href="logout.php" class="user-dropdown-item dropdown-logout-item" role="menuitem">
                    <svg class="dropdown-item-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                    <span>Log Out</span>
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- Accessible Backdrop Overlay for Mobile Drawers -->
<div class="menu-backdrop-overlay" id="menuBackdrop" aria-hidden="true"></div>

<script>
(function() {
    const toggleBtn = document.getElementById('userMenuToggle');
    const menu = document.getElementById('userDropdownMenu');
    const backdrop = document.getElementById('menuBackdrop');
    if (!toggleBtn || !menu) return;

    function openMenu() {
        toggleBtn.setAttribute('aria-expanded', 'true');
        menu.classList.add('is-open');
        if (backdrop) backdrop.classList.add('is-visible');
    }

    function closeMenu() {
        toggleBtn.setAttribute('aria-expanded', 'false');
        menu.classList.remove('is-open');
        if (backdrop) backdrop.classList.remove('is-visible');
    }

    toggleBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        const isOpen = menu.classList.contains('is-open');
        if (isOpen) {
            closeMenu();
        } else {
            openMenu();
        }
    });

    if (backdrop) {
        backdrop.addEventListener('click', closeMenu);
    }

    document.addEventListener('click', function(e) {
        if (!menu.contains(e.target) && !toggleBtn.contains(e.target)) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && menu.classList.contains('is-open')) {
            closeMenu();
            toggleBtn.focus();
        }
    });
})();
</script>
