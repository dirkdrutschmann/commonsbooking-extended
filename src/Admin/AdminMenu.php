<?php

namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Admin;

use DirkDrutschmann\CommonbookingsAdditionalFeatures\Support\PluginPaths;
use DirkDrutschmann\CommonbookingsAdditionalFeatures\UserManagement\UserManagement;

class AdminMenu
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'register']);
    }

    public function register(): void
    {
        $pending_review_count = UserManagement::count_pending_reviews();

        $hook_suffix = add_menu_page(
            $pending_review_count > 0
                ? sprintf('CB Additional Features (%d offene Prüfungen)', $pending_review_count)
                : 'CB Additional Features',
            'CB Additional Features' . $this->format_pending_review_menu_count($pending_review_count),
            'manage_options',
            'cbadf',
            [$this, 'render_default_page'],
            PluginPaths::asset_url('assets/images/icon.png')
        );

        if ($hook_suffix) {
            add_action('load-' . $hook_suffix, [$this, 'redirect_to_user_management']);
        }
    }

    private function format_pending_review_menu_count(int $count): string
    {
        if ($count <= 0) {
            return '';
        }

        return sprintf(
            ' <span class="update-plugins count-%1$d"><span class="plugin-count">%2$s</span></span>',
            $count,
            esc_html(number_format_i18n($count))
        );
    }

    public function redirect_to_user_management(): void
    {
        if (headers_sent()) {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=cbadf-user-management'));
        exit;
    }

    public function render_default_page(): void
    {
        $target_url = admin_url('admin.php?page=cbadf-user-management');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('CB Additional Features', 'cb-additional-features'); ?></h1>
            <p><?php echo esc_html__('Du wirst zum User Management weitergeleitet.', 'cb-additional-features'); ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url($target_url); ?>">
                    <?php echo esc_html__('User Management öffnen', 'cb-additional-features'); ?>
                </a>
            </p>
            <script>
                window.location.replace('<?php echo esc_js($target_url); ?>');
            </script>
        </div>
        <?php
    }
}
