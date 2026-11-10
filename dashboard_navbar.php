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
    'admin'      => ['class' => 'badge-role-admin',      'label' => 'Administrator'],
    'accountant' => ['class' => 'badge-role-accountant', 'label' => 'Accountant'],
    'patient'    => ['class' => 'badge-role-patient',    'label' => 'Patient'],
];
$badgeInfo = $roleBadges[$userRole] ?? ['class' => 'badge-role-patient', 'label' => ucfirst($userRole)];
$initial = strtoupper(function_exists('mb_substr') ? mb_substr($userFullName, 0, 1) : substr($userFullName, 0, 1));
if ($initial === '') {
    $initial = 'U';
}
?>
<style>
/* Critical Inline Styles for Responsive Hamburger Menu (Cache-Proof) */
.portal-navbar,
.admin-navbar {
    background: var(--green-dark, #103b35) !important;
    border-top: 4px solid var(--lime, #c5e86c) !important;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08) !important;
    box-shadow: 0 4px 18px rgba(16, 59, 53, 0.15) !important;
    padding: 12px clamp(16px, 4vw, 48px) !important;
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 16px !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 9000 !important;
    min-height: 68px !important;
    overflow: visible !important;
}

.brand-title-link {
    display: flex;
    flex-direction: column;
    text-decoration: none;
    color: #ffffff;
}

.brand-title-link strong {
    font-size: 1.15rem;
    font-weight: 700;
    color: #ffffff;
    line-height: 1.2;
}

.brand-title-link .admin-brand-subtitle {
    font-size: 0.74rem;
    color: var(--lime, #c5e86c);
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.hamburger-menu-container {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
    z-index: 9100 !important;
}

.hamburger-toggle-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 10px !important;
    background: rgba(255, 255, 255, 0.1) !important;
    border: 1.5px solid rgba(255, 255, 255, 0.24) !important;
    border-radius: 12px !important;
    padding: 10px 14px !important;
    color: #ffffff !important;
    cursor: pointer !important;
    transition: all 0.2s ease-in-out !important;
    outline: none !important;
    font-family: inherit !important;
    user-select: none !important;
    min-width: 46px !important;
    min-height: 44px !important;
}

.hamburger-toggle-btn:hover,
.hamburger-toggle-btn:focus-visible,
.hamburger-toggle-btn[aria-expanded="true"] {
    background: rgba(255, 255, 255, 0.2) !important;
    border-color: var(--lime, #c5e86c) !important;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2) !important;
}

.hamburger-btn-label {
    font-size: 0.85rem;
    font-weight: 700;
    color: #ffffff;
    letter-spacing: 0.02em;
}

.hamburger-icon-bars {
    display: flex !important;
    flex-direction: column !important;
    justify-content: space-between !important;
    width: 22px !important;
    height: 16px !important;
    flex-shrink: 0 !important;
}

.hamburger-icon-bars span {
    display: block !important;
    height: 2.5px !important;
    width: 100% !important;
    background-color: #ffffff !important;
    border-radius: 3px !important;
    transition: transform 0.25s ease-in-out, opacity 0.2s ease-in-out !important;
}

.hamburger-toggle-btn[aria-expanded="true"] .hamburger-icon-bars span:nth-child(1) {
    transform: translateY(6.75px) rotate(45deg) !important;
    background-color: var(--lime, #c5e86c) !important;
}

.hamburger-toggle-btn[aria-expanded="true"] .hamburger-icon-bars span:nth-child(2) {
    opacity: 0 !important;
}

.hamburger-toggle-btn[aria-expanded="true"] .hamburger-icon-bars span:nth-child(3) {
    transform: translateY(-6.75px) rotate(-45deg) !important;
    background-color: var(--lime, #c5e86c) !important;
}

/* Hidden Dropdown Drawer containing User Info, Role, Password, Security Questions, Logout */
.user-dropdown-menu {
    position: absolute !important;
    top: calc(100% + 12px) !important;
    right: 0 !important;
    width: 320px !important;
    background: #ffffff !important;
    border: 1px solid rgba(16, 59, 53, 0.14) !important;
    border-radius: 16px !important;
    box-shadow: 0 20px 42px rgba(16, 59, 53, 0.28), 0 4px 12px rgba(0, 0, 0, 0.1) !important;
    opacity: 0 !important;
    visibility: hidden !important;
    pointer-events: none !important;
    transform: translateY(-10px) scale(0.97) !important;
    transition: opacity 0.22s cubic-bezier(0.16, 1, 0.3, 1),
                transform 0.22s cubic-bezier(0.16, 1, 0.3, 1),
                visibility 0.22s ease !important;
    z-index: 9500 !important;
    overflow: hidden !important;
    text-align: left !important;
}

.user-dropdown-menu.is-open {
    opacity: 1 !important;
    visibility: visible !important;
    pointer-events: auto !important;
    transform: translateY(0) scale(1) !important;
}

.user-dropdown-header {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    padding: 18px 20px !important;
    background: linear-gradient(135deg, #103b35 0%, #1b5e54 100%) !important;
    color: #ffffff !important;
}

.user-dropdown-avatar {
    width: 48px !important;
    height: 48px !important;
    background: var(--lime, #c5e86c) !important;
    color: #103b35 !important;
    font-size: 1.35rem !important;
    font-weight: 800 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-radius: 50% !important;
    flex-shrink: 0 !important;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.25) !important;
}

.user-dropdown-info {
    display: flex !important;
    flex-direction: column !important;
    gap: 3px !important;
    min-width: 0 !important;
}

.user-dropdown-name {
    font-size: 1.02rem !important;
    font-weight: 700 !important;
    color: #ffffff !important;
    line-height: 1.2 !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
}

.user-dropdown-username {
    font-size: 0.82rem !important;
    color: #d1fae5 !important;
    font-weight: 500 !important;
}

.user-dropdown-role-row {
    margin-top: 4px !important;
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
}

.user-dropdown-role-badge {
    display: inline-block !important;
    background: var(--lime, #c5e86c) !important;
    color: #103b35 !important;
    font-size: 0.72rem !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    padding: 3px 10px !important;
    border-radius: 999px !important;
    letter-spacing: 0.04em !important;
}

.user-dropdown-details {
    padding: 12px 20px !important;
    background: #f8fafc !important;
    border-bottom: 1px solid #e2e8f0 !important;
    font-size: 0.83rem !important;
    color: #475569 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 6px !important;
}

.user-detail-row {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
}

.user-detail-label {
    font-weight: 600 !important;
    color: #64748b !important;
}

.user-detail-value {
    font-weight: 700 !important;
    color: #0f172a !important;
}

.user-dropdown-divider {
    height: 1px !important;
    background: #e5e7eb !important;
    margin: 4px 0 !important;
}

.user-dropdown-section {
    padding: 8px 10px !important;
}

.dropdown-section-title {
    display: block !important;
    font-size: 0.7rem !important;
    text-transform: uppercase !important;
    font-weight: 700 !important;
    color: #9ca3af !important;
    letter-spacing: 0.06em !important;
    padding: 4px 12px 6px !important;
}

.user-dropdown-item {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 11px 14px !important;
    border-radius: 10px !important;
    font-size: 0.9rem !important;
    font-weight: 600 !important;
    color: #1f2937 !important;
    text-decoration: none !important;
    transition: background 0.15s ease, color 0.15s ease !important;
}

.user-dropdown-item:hover,
.user-dropdown-item:focus-visible {
    background: #ecfdf5 !important;
    color: #103b35 !important;
}

.dropdown-item-icon {
    width: 19px !important;
    height: 19px !important;
    stroke: currentColor !important;
    flex-shrink: 0 !important;
}

.dropdown-logout-item {
    color: #dc2626 !important;
}

.dropdown-logout-item:hover,
.dropdown-logout-item:focus-visible {
    background: #fef2f2 !important;
    color: #b91c1c !important;
}

.menu-backdrop-overlay {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background: rgba(0, 0, 0, 0.45) !important;
    backdrop-filter: blur(2px) !important;
    z-index: 8900 !important;
    opacity: 0 !important;
    visibility: hidden !important;
    pointer-events: none !important;
    transition: opacity 0.25s ease, visibility 0.25s ease !important;
}

.menu-backdrop-overlay.is-visible {
    opacity: 1 !important;
    visibility: visible !important;
    pointer-events: auto !important;
}

@media (max-width: 640px) {
    .hamburger-btn-label {
        display: none !important;
    }

    .hamburger-toggle-btn {
        padding: 10px 12px !important;
    }

    .user-dropdown-menu {
        position: fixed !important;
        top: auto !important;
        bottom: 0 !important;
        left: 0 !important;
        right: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        border-radius: 22px 22px 0 0 !important;
        transform: translateY(100%) !important;
        box-shadow: 0 -12px 36px rgba(0, 0, 0, 0.3) !important;
        padding-bottom: 24px !important;
    }

    .user-dropdown-menu.is-open {
        transform: translateY(0) !important;
    }
}
</style>

<nav class="portal-navbar admin-navbar" aria-label="Portal Navigation">
    <div class="admin-brand">
        <a href="dashboard.php" class="brand-title-link">
            <strong>SecureHealth Portal</strong>
            <span class="admin-brand-subtitle"><?= e($portalSubtitle ?? 'Healthcare System') ?></span>
        </a>
    </div>

    <!-- Responsive Hamburger Menu Container (All user info & actions hidden inside until clicked) -->
    <div class="hamburger-menu-container">
        <button type="button" 
                class="hamburger-toggle-btn" 
                id="userMenuToggle" 
                aria-label="Open account menu" 
                aria-expanded="false" 
                aria-haspopup="true"
                aria-controls="userDropdownMenu"
                title="Click to view Username, Role, Security & Logout options">
            <span class="hamburger-btn-label">Menu</span>
            <div class="hamburger-icon-bars" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </button>

        <!-- Hidden Drawer / Dropdown Panel -->
        <div class="user-dropdown-menu" id="userDropdownMenu" role="menu" aria-labelledby="userMenuToggle">
            <!-- User Avatar & Header -->
            <div class="user-dropdown-header">
                <div class="user-dropdown-avatar" aria-hidden="true">
                    <?= e($initial) ?>
                </div>
                <div class="user-dropdown-info">
                    <strong class="user-dropdown-name"><?= e($userFullName) ?></strong>
                    <span class="user-dropdown-username">@<?= e($userUsername) ?></span>
                    <div class="user-dropdown-role-row">
                        <span class="user-dropdown-role-badge"><?= e($badgeInfo['label']) ?></span>
                    </div>
                </div>
            </div>

            <!-- Explicit Username & Role Summary Box -->
            <div class="user-dropdown-details">
                <div class="user-detail-row">
                    <span class="user-detail-label">Username:</span>
                    <span class="user-detail-value"><?= e($userUsername) ?></span>
                </div>
                <div class="user-detail-row">
                    <span class="user-detail-label">Assigned Role:</span>
                    <span class="user-detail-value"><?= e($badgeInfo['label']) ?></span>
                </div>
            </div>

            <!-- Security & Account Management Links -->
            <div class="user-dropdown-section">
                <span class="dropdown-section-title">Account &amp; Security</span>
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

            <!-- Session Logout -->
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

<!-- Accessible Backdrop Overlay -->
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
