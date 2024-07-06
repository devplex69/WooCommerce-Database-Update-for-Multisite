<?php
/*
Plugin Name: WooCommerce Database Multisite Updater
Description: Updates the WooCommerce database for all subsites in a WordPress Multisite network.
Version: 1.0
Author: Hemanth(Oykin)
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! is_multisite() ) {
    return; // Exit if not a multisite network.
}

add_action( 'wp_initialize_site', 'woocommerce_multisite_update_db_on_create', 900 );
add_action( 'wpmu_new_blog', 'woocommerce_multisite_update_db_on_create', 900 );
add_action( 'update_option_woocommerce_db_version', 'woocommerce_multisite_update_db_on_update', 10, 2 );
add_action( 'network_admin_menu', 'woocommerce_multisite_updater_menu' );
add_action( 'wp_ajax_woocommerce_multisite_update_db', 'woocommerce_multisite_update_db_ajax' );

function woocommerce_multisite_update_db_on_create( $blog_id ) {
    if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
        require_once( ABSPATH . '/wp-admin/includes/plugin.php' );
    }

    if ( is_plugin_active_for_network( 'woocommerce/woocommerce.php' ) ) {
        switch_to_blog( $blog_id );
        woocommerce_multisite_update_db();
        restore_current_blog();
    }
}

function woocommerce_multisite_update_db_on_update( $old_value, $value ) {
    if ( is_multisite() ) {
        $blog_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
        foreach ( $blog_ids as $blog_id ) {
            switch_to_blog( $blog_id );
            woocommerce_multisite_update_db();
            restore_current_blog();
        }
    }
}

function woocommerce_multisite_update_db() {
    if ( class_exists( 'WooCommerce' ) ) {
        WC_Install::update_db_version();
    }
}

function woocommerce_multisite_updater_menu() {
    add_menu_page(
        'WooCommerce Multisite Updater',
        'WooCommerce Updater',
        'manage_network_options',
        'woocommerce-multisite-updater',
        'woocommerce_multisite_updater_page'
    );
}

function woocommerce_multisite_updater_page() {
    if ( ! current_user_can( 'manage_network_options' ) ) {
        return;
    }

    $blog_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
    ?>
    <div class="wrap">
        <h1>WooCommerce Multisite Updater</h1>
        <form id="woocommerce-multisite-updater-form" method="post">
            <table class="widefat">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all-blogs"></th>
                        <th>Blog ID</th>
                        <th>Blog Name</th>
                        <th>Update Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $blog_ids as $blog_id ) : ?>
                        <tr>
                            <td><input type="checkbox" name="blog_ids[]" value="<?php echo $blog_id; ?>"></td>
                            <td><?php echo $blog_id; ?></td>
                            <td><?php echo get_blog_details( $blog_id )->blogname; ?></td>
                            <td id="status-<?php echo $blog_id; ?>">
                                <?php
                                switch_to_blog( $blog_id );
                                $db_version = get_option( 'woocommerce_db_version' );
                                restore_current_blog();
                                if ( $db_version === WC()->version ) {
                                    echo 'Updated';
                                } else {
                                    echo 'Not Updated';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div id="update-status-bar" style="width: 100%; background-color: #ddd;">
                <div id="progress" style="width: 0%; height: 20px; background-color: #4CAF50; text-align: center; line-height: 20px; color: white;"></div>
            </div>
            <button type="button" id="update-selected-woocommerce-db" class="button button-primary">Update Selected</button>
        </form>
    </div>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#select-all-blogs').on('click', function() {
                $('input[name="blog_ids[]"]').prop('checked', this.checked);
            });

            $('#update-selected-woocommerce-db').on('click', function() {
                var blogIds = [];
                $('input[name="blog_ids[]"]:checked').each(function() {
                    blogIds.push($(this).val());
                });

                if (blogIds.length === 0) {
                    alert('Please select at least one subsite to update.');
                    return;
                }

                $('#update-status-bar #progress').css('width', '0%').text('0%');

                function updateNextBlog(index) {
                    if (index >= blogIds.length) {
                        $('#update-status-bar #progress').css('width', '100%').text('100%');
                        return;
                    }

                    var blogId = blogIds[index];
                    var progress = ((index + 1) / blogIds.length) * 100;
                    $('#update-status-bar #progress').css('width', progress + '%').text(Math.round(progress) + '%');

                    $.ajax({
                        url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
                        type: 'POST',
                        data: {
                            action: 'woocommerce_multisite_update_db',
                            blog_id: blogId,
                            _ajax_nonce: '<?php echo wp_create_nonce( 'woocommerce_multisite_update_db_nonce' ); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#status-' + blogId).html('Updated');
                            } else {
                                $('#status-' + blogId).html('Update failed.');
                            }
                            updateNextBlog(index + 1);
                        },
                        error: function() {
                            $('#status-' + blogId).html('Update failed.');
                            updateNextBlog(index + 1);
                        }
                    });
                }

                updateNextBlog(0);
            });
        });
    </script>
    <?php
}

function woocommerce_multisite_update_db_ajax() {
    check_ajax_referer( 'woocommerce_multisite_update_db_nonce', '_ajax_nonce' );

    if ( ! current_user_can( 'manage_network_options' ) ) {
        wp_send_json_error();
    }

    $blog_id = intval( $_POST['blog_id'] );
    switch_to_blog( $blog_id );
    woocommerce_multisite_update_db();
    restore_current_blog();

    wp_send_json_success();
}