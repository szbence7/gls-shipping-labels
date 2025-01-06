<?php

class GLS_Shipping_Labels {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
    }

    public function add_settings_page() {
        add_options_page(
            'GLS Shipping Labels Settings',
            'GLS Shipping Labels',
            'manage_options',
            'gls-shipping-labels',
            array($this, 'render_settings_page')
        );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('gls_labels_settings');
                do_settings_sections('gls_labels_settings');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">GLS API Felhasználónév</th>
                        <td>
                            <input type="text" name="gls_api_username" 
                                   value="<?php echo esc_attr(get_option('gls_api_username')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">GLS API Jelszó</th>
                        <td>
                            <input type="password" name="gls_api_password" 
                                   value="<?php echo esc_attr(get_option('gls_api_password')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">GLS API URL</th>
                        <td>
                            <input type="url" name="gls_api_url" 
                                   value="<?php echo esc_attr(get_option('gls_api_url')); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">GLS Feladó ID</th>
                        <td>
                            <input type="text" name="gls_sender_id" 
                                   value="<?php echo esc_attr(get_option('gls_sender_id')); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
} 