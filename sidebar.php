<?php
require_once __DIR__ . '/rbac.php';

$self = basename($_SERVER['PHP_SELF']);

if (!function_exists('nav_active')) {
    function nav_active($files = []) {
        global $self;
        return in_array($self, (array)$files, true) ? 'active' : '';
    }
}

if (!function_exists('nav_open')) {
    function nav_open($files = []) {
        global $self;
        return in_array($self, (array)$files, true);
    }
}

$financialPages = [
    'financial_statements.php',
    'income_statement.php',
    'balance_sheet.php',
    'cash_flow_statement.php',
    'owners_equity_statement.php',
    'chart_of_accounts.php'
];

$rolePages = [
    'roles.php',
    'permissions.php'
];

$superManagementPages = [
    'companies.php',
    'admin_management.php',
    'plans.php',
    'subscriptions.php',
    'payments.php'
];

$superSystemPages = [
    'roles.php',
    'permissions.php',
    'users.php',
    'messaging.php',
    'reports.php',
    'notifications.php',
    'activity_logs.php',
    'backup.php',
    'settings.php',
    'profile.php'
];

$operationsPages = [
    'pos.php',
    'sales.php',
    'invoices.php',
    'quotations.php',
    'expenses.php'
];

$inventoryPages = [
    'products.php',
    'products_list.php',
    'purchases.php',
    'stock_transfer.php',
    'stock_movements.php',
    'inventory_reports.php'
];

$contactsPages = [
    'customers.php',
    'suppliers.php'
];

$adminPages = [
    'branches.php',
    'users.php',
    'roles.php',
    'permissions.php',
    'settings.php',
    'backup.php'
];

$accountPages = [
    'reports.php',
    'subscription_portal.php',
    'notifications.php',
    'profile.php'
];

$branding = function_exists('company_branding') ? company_branding() : [
    'name' => 'No9 Cloud',
    'title' => 'No9 Cloud',
    'logo' => '',
    'tagline' => 'Cloud inventory, finance, and multi-branch SaaS',
];

$systemLogo = '';
$systemName = '';
$systemTagline = '';

if (function_exists('setting')) {
    $systemLogo = setting('system_logo', '');
    if (!$systemLogo) {
        $systemLogo = setting('logo', '');
    }
    if (!$systemLogo) {
        $systemLogo = setting('site_logo', '');
    }
    if (!$systemLogo) {
        $systemLogo = setting('app_logo', '');
    }

    $systemName = setting('system_name', '');
    if (!$systemName) {
        $systemName = setting('app_name', '');
    }
    if (!$systemName) {
        $systemName = setting('site_name', '');
    }

    $systemTagline = setting('system_tagline', '');
    if (!$systemTagline) {
        $systemTagline = setting('tagline', '');
    }
}

$resolvedLogo = $systemLogo ?: ($branding['logo'] ?? '');

$brandName = is_super_admin()
    ? ($systemName ?: (defined('APP_NAME') ? APP_NAME : 'No9 Cloud'))
    : ($branding['name'] ?? ($systemName ?: 'No9 Cloud'));

$brandTagline = is_super_admin()
    ? ($systemTagline ?: 'SaaS Control')
    : ($branding['tagline'] ?? ($systemTagline ?: 'Inventory & Finance'));

$currentRoleLabel = current_user()['role'] ?? 'user';
?>
<aside class="sidebar-standard">

<style>
.sidebar-standard{
    width:272px;
    min-height:100vh;
    padding:18px 14px;
    color:#e5e7eb;
    background:linear-gradient(180deg,#0f172a 0%, #111827 100%);
    border-right:1px solid rgba(255,255,255,.06);
    box-shadow:4px 0 18px rgba(15,23,42,.10);
    overflow-y:auto;
}
.sidebar-standard::-webkit-scrollbar{width:8px}
.sidebar-standard::-webkit-scrollbar-thumb{
    background:rgba(255,255,255,.10);
    border-radius:999px;
}

.brand-box{
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.07);
    border-radius:22px;
    padding:16px;
    margin-bottom:16px;
}
.brand-row{
    display:flex;
    align-items:center;
    gap:12px;
}
.brand-logo{
    width:46px;
    height:46px;
    border-radius:14px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:linear-gradient(135deg,#2563eb,#1d4ed8);
    color:#fff;
    box-shadow:0 10px 22px rgba(37,99,235,.24);
    overflow:hidden;
    flex-shrink:0;
}
.brand-logo img{
    width:100%;
    height:100%;
    object-fit:cover;
}
.brand-title{
    font-size:18px;
    font-weight:800;
    line-height:1.1;
    color:#fff;
}
.brand-subtitle{
    margin-top:4px;
    font-size:13px;
    color:rgba(255,255,255,.62);
}
.role-badge-top{
    margin-top:12px;
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 12px;
    border-radius:999px;
    background:rgba(255,255,255,.06);
    border:1px solid rgba(255,255,255,.06);
    font-size:12px;
    font-weight:700;
    color:#f8fafc;
}

.nav-group{
    margin-bottom:10px;
}
.group-label{
    padding:0 10px 7px;
    font-size:11px;
    font-weight:800;
    letter-spacing:1.1px;
    text-transform:uppercase;
    color:rgba(255,255,255,.38);
}
.nav-list{
    display:flex;
    flex-direction:column;
    gap:6px;
}
.nav-item-link,
.dropdown-toggle-link{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:11px 12px;
    border-radius:14px;
    text-decoration:none;
    color:rgba(255,255,255,.84);
    transition:all .22s ease;
    border:1px solid transparent;
    cursor:pointer;
}
.nav-item-link .left,
.dropdown-toggle-link .left{
    display:flex;
    align-items:center;
    gap:10px;
}
.nav-item-link i,
.dropdown-toggle-link i{
    width:18px;
    text-align:center;
    font-size:15px;
    flex-shrink:0;
}
.nav-item-link:hover,
.dropdown-toggle-link:hover{
    color:#fff;
    background:rgba(255,255,255,.06);
    border-color:rgba(255,255,255,.05);
    transform:translateX(2px);
}
.nav-item-link.active,
.dropdown-root.open > .dropdown-toggle-link{
    background:rgba(37,99,235,.16);
    color:#fff;
    border-color:rgba(59,130,246,.18);
    box-shadow:inset 0 0 0 1px rgba(59,130,246,.08);
}
.nav-item-link.active .icon-pill,
.dropdown-root.open > .dropdown-toggle-link .icon-pill{
    background:linear-gradient(135deg,#2563eb,#1d4ed8);
    color:#fff;
}
.icon-pill{
    width:30px;
    height:30px;
    border-radius:10px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:rgba(255,255,255,.05);
    transition:all .22s ease;
}
.line-sep{
    height:1px;
    margin:12px 8px;
    background:linear-gradient(90deg, transparent, rgba(255,255,255,.10), transparent);
}

.dropdown-root{
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.07);
    border-radius:18px;
    padding:8px;
    transition:all .22s ease;
}
.dropdown-root:hover{
    border-color:rgba(255,255,255,.10);
    background:rgba(255,255,255,.05);
}
.dropdown-menu-box{
    max-height:0;
    overflow:hidden;
    opacity:0;
    display:flex;
    flex-direction:column;
    gap:5px;
    margin-top:0;
    transition:max-height .28s ease, opacity .22s ease, margin-top .22s ease;
}
.dropdown-root.open .dropdown-menu-box{
    max-height:700px;
    opacity:1;
    margin-top:8px;
}
.dropdown-menu-box a{
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 12px;
    text-decoration:none;
    color:rgba(255,255,255,.86);
    border-radius:12px;
    transition:all .2s ease;
}
.dropdown-menu-box a:hover{
    background:rgba(255,255,255,.08);
    color:#fff;
    transform:translateX(2px);
}
.dropdown-menu-box a.active{
    background:rgba(37,99,235,.16);
    color:#fff;
}
.dropdown-arrow{
    transition:transform .22s ease;
    font-size:12px;
}
.dropdown-root.open .dropdown-arrow{
    transform:rotate(180deg);
}

.bottom-box{
    margin-top:16px;
    padding:14px;
    border-radius:18px;
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.06);
}
.bottom-box h6{
    margin:0 0 6px;
    color:#fff;
    font-size:14px;
    font-weight:800;
}
.bottom-box p{
    margin:0 0 10px;
    color:rgba(255,255,255,.62);
    font-size:12px;
    line-height:1.45;
}
.bottom-btn{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:10px 12px;
    border-radius:12px;
    background:#2563eb;
    color:#fff;
    text-decoration:none;
    font-size:13px;
    font-weight:700;
}
.bottom-btn:hover{
    color:#fff;
    background:#1d4ed8;
}

@media (max-width: 991.98px){
    .sidebar-standard{
        width:100%;
        min-height:auto;
        border-radius:0 0 22px 22px;
    }
}
</style>

<div class="brand-box">
    <div class="brand-row">
        <div class="brand-logo">
            <?php if (!empty($resolvedLogo)): ?>
                <img src="<?php echo e($resolvedLogo); ?>" alt="Logo">
            <?php else: ?>
                <i class="bi bi-cloud-fill"></i>
            <?php endif; ?>
        </div>

        <div>
            <div class="brand-title"><?php echo e($brandName); ?></div>
            <div class="brand-subtitle"><?php echo e($brandTagline); ?></div>
        </div>
    </div>

    <div class="role-badge-top">
        <i class="bi bi-shield-check"></i>
        <span><?php echo e(ucwords(str_replace('_', ' ', $currentRoleLabel))); ?></span>
    </div>
</div>

<nav>

<?php if (is_super_admin()): ?>

    <div class="nav-group">
        <div class="group-label">Main</div>
        <div class="nav-list">
            <a class="nav-item-link <?php echo nav_active(['dashboard.php']); ?>" href="dashboard.php">
                <span class="left">
                    <span class="icon-pill"><i class="bi bi-speedometer2"></i></span>
                    <span>Dashboard</span>
                </span>
            </a>
        </div>
    </div>

    <div class="line-sep"></div>

    <div class="nav-group">
        <div class="group-label">Management</div>
        <div class="dropdown-root <?php echo nav_open($superManagementPages) ? 'open' : ''; ?>" data-dropdown>
            <div class="dropdown-toggle-link">
                <span class="left">
                    <span class="icon-pill"><i class="bi bi-buildings"></i></span>
                    <span>Management</span>
                </span>
                <i class="bi bi-chevron-down dropdown-arrow"></i>
            </div>

            <div class="dropdown-menu-box">
                <a class="<?php echo nav_active(['companies.php']); ?>" href="companies.php">
                    <i class="bi bi-buildings"></i> Companies
                </a>
                <a class="<?php echo nav_active(['admin_management.php']); ?>" href="admin_management.php">
                    <i class="bi bi-people-fill"></i> Company Admins
                </a>
                <a class="<?php echo nav_active(['plans.php']); ?>" href="plans.php">
                    <i class="bi bi-box-seam"></i> Plans
                </a>
                <a class="<?php echo nav_active(['subscriptions.php']); ?>" href="subscriptions.php">
                    <i class="bi bi-calendar2-check"></i> Subscriptions
                </a>
                <a class="<?php echo nav_active(['payments.php']); ?>" href="payments.php">
                    <i class="bi bi-cash-coin"></i> Payments
                </a>
            </div>
        </div>
    </div>

    <div class="line-sep"></div>

    <div class="nav-group">
        <div class="group-label">System</div>
        <div class="dropdown-root <?php echo nav_open($superSystemPages) ? 'open' : ''; ?>" data-dropdown>
            <div class="dropdown-toggle-link">
                <span class="left">
                    <span class="icon-pill"><i class="bi bi-gear-wide-connected"></i></span>
                    <span>System</span>
                </span>
                <i class="bi bi-chevron-down dropdown-arrow"></i>
            </div>

            <div class="dropdown-menu-box">

                <?php if (can_read('roles')): ?>
                <a class="<?php echo nav_active($rolePages); ?>" href="roles.php">
                    <i class="bi bi-shield-lock"></i> Roles & Permissions
                </a>
                <?php endif; ?>

                <?php if (can_read('users')): ?>
                <a class="<?php echo nav_active(['users.php']); ?>" href="users.php">
                    <i class="bi bi-person-gear"></i> Users
                </a>
                <?php endif; ?>

                <a class="<?php echo nav_active(['messaging.php']); ?>" href="messaging.php">
                    <i class="bi bi-send-check"></i> Messaging
                </a>

                <?php if (can_read('reports')): ?>
                <a class="<?php echo nav_active(['reports.php']); ?>" href="reports.php">
                    <i class="bi bi-graph-up-arrow"></i> Reports
                </a>
                <?php endif; ?>

                <a class="<?php echo nav_active(['notifications.php']); ?>" href="notifications.php">
                    <i class="bi bi-bell"></i> Notifications
                </a>

                <a class="<?php echo nav_active(['activity_logs.php']); ?>" href="activity_logs.php">
                    <i class="bi bi-clipboard-data"></i> Activity Logs
                </a>

                <?php if (can_read('backup')): ?>
                <a class="<?php echo nav_active(['backup.php']); ?>" href="backup.php">
                    <i class="bi bi-cloud-arrow-down"></i> Backup
                </a>
                <?php endif; ?>

                <?php if (can_read('settings')): ?>
                <a class="<?php echo nav_active(['settings.php']); ?>" href="settings.php">
                    <i class="bi bi-gear"></i> Settings
                </a>
                <?php endif; ?>

                <a class="<?php echo nav_active(['profile.php']); ?>" href="profile.php">
                    <i class="bi bi-person-circle"></i> Profile
                </a>
            </div>
        </div>
    </div>

    <div class="bottom-box">
        <h6>Quick Access</h6>
        <p>Open your main dashboard quickly and continue your work smoothly.</p>
        <a href="dashboard.php" class="bottom-btn">
            <i class="bi bi-lightning-charge-fill"></i>
            Dashboard
        </a>
    </div>

<?php else: ?>

    <?php if (can_read('dashboard')): ?>
    <div class="nav-group">
        <div class="group-label">Main</div>
        <div class="nav-list">
            <a class="nav-item-link <?php echo nav_active(['dashboard.php']); ?>" href="dashboard.php">
                <span class="left">
                    <span class="icon-pill"><i class="bi bi-speedometer2"></i></span>
                    <span>Dashboard</span>
                </span>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <div class="line-sep"></div>

    <div class="nav-group">
        <div class="group-label">Operations</div>
        <div class="dropdown-root <?php echo nav_open($operationsPages) ? 'open' : ''; ?>" data-dropdown>
            <div class="dropdown-toggle-link">
                <span class="left">
                    <span class="icon-pill"><i class="bi bi-shop"></i></span>
                    <span>Operations</span>
                </span>
                <i class="bi bi-chevron-down dropdown-arrow"></i>
            </div>

            <div class="dropdown-menu-box">
                <?php if (can_read('sales')): ?>
                <a class="<?php echo nav_active(['pos.php']); ?>" href="pos.php">
                    <i class="bi bi-cart-check"></i> POS
                </a>
                <a class="<?php echo nav_active(['sales.php']); ?>" href="sales.php">
                    <i class="bi bi-receipt"></i> Receipt Sales List
                </a>
                <a class="<?php echo nav_active(['invoices.php']); ?>" href="invoices.php">
                    <i class="bi bi-file-earmark-text"></i> Credit Invoices List
                </a>
                <a class="<?php echo nav_active(['quotations.php']); ?>" href="quotations.php">
                    <i class="bi bi-file-earmark-richtext"></i> Quotations List
                </a>
                <?php endif; ?>

                <?php if (can_read('expenses')): ?>
                <a class="<?php echo nav_active(['expenses.php']); ?>" href="expenses.php">
                    <i class="bi bi-wallet2"></i> Expenses
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="line-sep"></div>

    <div class="nav-group">
        <div class="group-label">Inventory</div>
        <div class="dropdown-root <?php echo nav_open($inventoryPages) ? 'open' : ''; ?>" data-dropdown>
            <div class="dropdown-toggle-link">
                <span class="left">
                    <span class="icon-pill"><i class="bi bi-box-seam"></i></span>
                    <span>Inventory</span>
                </span>
                <i class="bi bi-chevron-down dropdown-arrow"></i>
            </div>

            <div class="dropdown-menu-box">
                <?php if (can_read('products')): ?>
                <a class="<?php echo nav_active(['products.php']); ?>" href="products.php">
                    <i class="bi bi-box2"></i> Products
                </a>
                <a class="<?php echo nav_active(['products_list.php']); ?>" href="products_list.php">
                    <i class="bi bi-list-ul"></i> Product List
                </a>
                <?php endif; ?>

                <?php if (can_read('stock')): ?>
                <a class="<?php echo nav_active(['purchases.php']); ?>" href="purchases.php">
                    <i class="bi bi-cart-plus"></i> Purchases
                </a>
                <a class="<?php echo nav_active(['stock_transfer.php']); ?>" href="stock_transfer.php">
                    <i class="bi bi-arrow-left-right"></i> Stock Transfer
                </a>
                <a class="<?php echo nav_active(['stock_movements.php']); ?>" href="stock_movements.php">
                    <i class="bi bi-shuffle"></i> Stock Movements
                </a>
                <a class="<?php echo nav_active(['inventory_reports.php']); ?>" href="inventory_reports.php">
                    <i class="bi bi-clipboard-data"></i> Inventory Reports
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (can_read('finance') || can_read('reports')): ?>
    <div class="line-sep"></div>

    <div class="nav-group">
        <div class="group-label">Finance</div>
        <div class="dropdown-root <?php echo nav_open($financialPages) ? 'open' : ''; ?>" data-dropdown>
            <div class="dropdown-toggle-link">
                <span class="left">
                    <span class="icon-pill"><i class="bi bi-bar-chart-line"></i></span>
                    <span>Financial Statements</span>
                </span>
                <i class="bi bi-chevron-down dropdown-arrow"></i>
            </div>

            <div class="dropdown-menu-box">
                <?php if (can_read('finance')): ?>
                <a class="<?php echo nav_active(['financial_statements.php']); ?>" href="financial_statements.php">
                    <i class="bi bi-grid"></i> Overview
                </a>
                <a class="<?php echo nav_active(['income_statement.php']); ?>" href="income_statement.php">
                    <i class="bi bi-graph-up"></i> Income Statement
                </a>
                <a class="<?php echo nav_active(['balance_sheet.php']); ?>" href="balance_sheet.php">
                    <i class="bi bi-table"></i> Balance Sheet
                </a>
                <a class="<?php echo nav_active(['chart_of_accounts.php']); ?>" href="chart_of_accounts.php">
                    <i class="bi bi-diagram-3"></i> Chart of Accounts
                </a>
                <a class="<?php echo nav_active(['cash_flow_statement.php']); ?>" href="cash_flow_statement.php">
                    <i class="bi bi-cash-stack"></i> Cash Flow Statement
                </a>
                <a class="<?php echo nav_active(['owners_equity_statement.php']); ?>" href="owners_equity_statement.php">
                    <i class="bi bi-person-badge"></i> Owner’s Equity
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="line-sep"></div>

    <div class="nav-group">
        <div class="group-label">Contacts</div>
        <div class="dropdown-root <?php echo nav_open($contactsPages) ? 'open' : ''; ?>" data-dropdown>
            <div class="dropdown-toggle-link">
                <span class="left">
                    <span class="icon-pill"><i class="bi bi-people"></i></span>
                    <span>Contacts</span>
                </span>
                <i class="bi bi-chevron-down dropdown-arrow"></i>
            </div>

            <div class="dropdown-menu-box">
                <?php if (can_read('customers')): ?>
                <a class="<?php echo nav_active(['customers.php']); ?>" href="customers.php">
                    <i class="bi bi-people"></i> Customers
                </a>
                <?php endif; ?>

                <?php if (can_read('suppliers')): ?>
                <a class="<?php echo nav_active(['suppliers.php']); ?>" href="suppliers.php">
                    <i class="bi bi-truck"></i> Suppliers
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (is_company_admin() || can_read('users') || can_read('roles') || can_read('settings') || can_read('backup')): ?>
    <div class="line-sep"></div>

    <div class="nav-group">
        <div class="group-label">Administration</div>
        <div class="dropdown-root <?php echo nav_open($adminPages) ? 'open' : ''; ?>" data-dropdown>
            <div class="dropdown-toggle-link">
                <span class="left">
                    <span class="icon-pill"><i class="bi bi-gear-wide-connected"></i></span>
                    <span>Administration</span>
                </span>
                <i class="bi bi-chevron-down dropdown-arrow"></i>
            </div>

            <div class="dropdown-menu-box">
                <?php if (is_company_admin()): ?>
                <a class="<?php echo nav_active(['branches.php']); ?>" href="branches.php">
                    <i class="bi bi-diagram-3"></i> Branches
                </a>
                <?php endif; ?>

                <?php if (can_read('users')): ?>
                <a class="<?php echo nav_active(['users.php']); ?>" href="users.php">
                    <i class="bi bi-person-gear"></i> Users
                </a>
                <?php endif; ?>

                <?php if (can_read('roles')): ?>
                <a class="<?php echo nav_active($rolePages); ?>" href="roles.php">
                    <i class="bi bi-shield-lock"></i> Roles & Permissions
                </a>
                <?php endif; ?>

                <?php if (can_read('settings')): ?>
                <a class="<?php echo nav_active(['settings.php']); ?>" href="settings.php">
                    <i class="bi bi-gear"></i> Settings
                </a>
                <?php endif; ?>

                <?php if (can_read('backup')): ?>
                <a class="<?php echo nav_active(['backup.php']); ?>" href="backup.php">
                    <i class="bi bi-cloud-arrow-down"></i> Backup
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="line-sep"></div>

    <div class="nav-group">
        <div class="group-label">Account</div>
        <div class="dropdown-root <?php echo nav_open($accountPages) ? 'open' : ''; ?>" data-dropdown>
            <div class="dropdown-toggle-link">
                <span class="left">
                    <span class="icon-pill"><i class="bi bi-person-circle"></i></span>
                    <span>Account</span>
                </span>
                <i class="bi bi-chevron-down dropdown-arrow"></i>
            </div>

            <div class="dropdown-menu-box">
                <?php if (can_read('reports')): ?>
                <a class="<?php echo nav_active(['reports.php']); ?>" href="reports.php">
                    <i class="bi bi-graph-up-arrow"></i> Reports
                </a>
                <?php endif; ?>

                <a class="<?php echo nav_active(['subscription_portal.php']); ?>" href="subscription_portal.php">
                    <i class="bi bi-credit-card"></i> Subscription
                </a>

                <a class="<?php echo nav_active(['notifications.php']); ?>" href="notifications.php">
                    <i class="bi bi-bell"></i> Notifications
                </a>

                <a class="<?php echo nav_active(['profile.php']); ?>" href="profile.php">
                    <i class="bi bi-person-circle"></i> Profile
                </a>
            </div>
        </div>
    </div>

    <div class="bottom-box">
        <h6>Quick Access</h6>
        <p>Open your main dashboard quickly and continue your work smoothly.</p>
        <a href="#" class="bottom-btn">
            <i class="bi bi-lightning-charge-fill"></i>
            Dashboard
        </a>
    </div>

<?php endif; ?>

</nav>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const dropdowns = document.querySelectorAll('[data-dropdown]');

    dropdowns.forEach(function(dropdown){
        const toggle = dropdown.querySelector('.dropdown-toggle-link');
        const hasActive = dropdown.querySelector('.dropdown-menu-box a.active');

        if (hasActive) {
            dropdown.classList.add('open');
        }

        if (toggle) {
            toggle.addEventListener('click', function(e){
                e.preventDefault();

                if (dropdown.classList.contains('open')) {
                    dropdown.classList.remove('open');
                } else {
                    dropdowns.forEach(function(other){
                        if (other !== dropdown && !other.querySelector('.dropdown-menu-box a.active')) {
                            other.classList.remove('open');
                        }
                    });
                    dropdown.classList.add('open');
                }
            });
        }

        dropdown.addEventListener('mouseenter', function(){
            dropdown.classList.add('open');
        });

        dropdown.addEventListener('mouseleave', function(){
            if (!dropdown.querySelector('.dropdown-menu-box a.active')) {
                dropdown.classList.remove('open');
            }
        });
    });
});
</script>

</aside>