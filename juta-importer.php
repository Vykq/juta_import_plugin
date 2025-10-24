<?php
/**
 * Plugin Name: Juta Importer
 * Description: Import products from JUTA xml
 * Version: 1.0.3
 * Author: Vykintas Venckus
 * Text Domain: juta-importer
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class JutaImporter {
    
    private $log_file_path;
    
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_file_path = $upload_dir['basedir'] . '/juta-importer-logs/';
        
        if (!file_exists($this->log_file_path)) {
            wp_mkdir_p($this->log_file_path);
        }
        
        add_action('plugins_loaded', array($this, 'init'));
        
        // Register activation/deactivation hooks for cron
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate_plugin'));
    }
    
    public function init() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('wp_ajax_juta_start_import', array($this, 'handle_import_request'));
        add_action('wp_ajax_juta_process_batch', array($this, 'process_batch'));
        add_action('wp_ajax_juta_get_import_status', array($this, 'get_import_status'));
        add_action('wp_ajax_juta_stop_import', array($this, 'stop_import'));
        add_action('wp_ajax_juta_view_logs', array($this, 'view_logs'));
        add_action('wp_ajax_juta_clear_logs', array($this, 'clear_logs'));
        add_action('wp_ajax_juta_toggle_auto_import', array($this, 'toggle_auto_import'));
        
        // Register custom cron hook
        add_action('juta_daily_import_hook', array($this, 'run_scheduled_import'));
    }
    
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>' . esc_html__('Juta Importer', 'juta-importer') . '</strong> ' . esc_html__('requires WooCommerce to be installed and active.', 'juta-importer') . '</p></div>';
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Juta Importer', 'juta-importer'),
            __('Juta Importer', 'juta-importer'),
            'manage_woocommerce',
            'juta-importer',
            array($this, 'admin_page')
        );
    }
    
    public function admin_init() {
        register_setting('juta_importer_settings', 'juta_xml_url');
        register_setting('juta_importer_settings', 'juta_batch_size');
        register_setting('juta_importer_settings', 'juta_auto_import_enabled');
    }
    
    public function admin_page() {
        if (isset($_POST['submit'])) {
            $this->handle_form_submission();
        }
        
        $xml_url = get_option('juta_xml_url', '');
        $batch_size = get_option('juta_batch_size', 50);
        $auto_import_enabled = get_option('juta_auto_import_enabled', false);
        $next_scheduled = wp_next_scheduled('juta_daily_import_hook');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php if (isset($_GET['updated']) && $_GET['updated'] === 'true'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Settings saved successfully!', 'juta-importer'); ?></p>
                </div>
            <?php endif; ?>
            
            <div id="import-section" style="margin-top: 20px;">
                <h2><?php esc_html_e('Import Products', 'juta-importer'); ?></h2>
                
                <!-- Auto Import Status -->
                <div id="auto-import-status" style="margin-bottom: 15px; padding: 10px; border: 1px solid #ddd; border-radius: 5px; background: #f9f9f9;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Automatic Import', 'juta-importer'); ?></h3>
                    <p>
                        <strong><?php esc_html_e('Status:', 'juta-importer'); ?></strong> 
                        <span id="auto-import-status-text"><?php echo $auto_import_enabled ? 'Enabled' : 'Disabled'; ?></span>
                    </p>
                    <p>
                        <strong><?php esc_html_e('Next Scheduled Import:', 'juta-importer'); ?></strong>
                        <span id="next-scheduled-time">
                            <?php 
                                if ($auto_import_enabled && $next_scheduled) {
                                    echo wp_date('Y-m-d H:i:s', $next_scheduled);
                                } else {
                                    echo $auto_import_enabled ? 'Not scheduled' : 'Disabled';
                                }
                            ?>
                        </span>
                    </p>
                    <button type="button" id="toggle-auto-import" class="button button-secondary">
                        <?php echo $auto_import_enabled ? esc_html__('Disable Auto Import', 'juta-importer') : esc_html__('Enable Auto Import', 'juta-importer'); ?>
                    </button>
                </div>
                
                <!-- Manual Import -->
                <h3><?php esc_html_e('Manual Import', 'juta-importer'); ?></h3>
                <div id="import-status" style="display: none;">
                    <div class="notice notice-info">
                        <p id="import-message"><?php esc_html_e('Import in progress...', 'juta-importer'); ?></p>
                        <div id="progress-bar" style="width: 100%; background-color: #f1f1f1; border-radius: 5px; margin: 10px 0;">
                            <div id="progress-fill" style="width: 0%; height: 20px; background-color: #0073aa; border-radius: 5px; transition: width 0.3s;"></div>
                        </div>
                        <p id="progress-text">0%</p>
                    </div>
                </div>
                <button type="button" id="start-import" class="button button-primary" <?php echo empty($xml_url) ? 'disabled' : ''; ?>>
                    <?php esc_html_e('Start Import', 'juta-importer'); ?>
                </button>
                <button type="button" id="stop-import" class="button button-secondary" style="display: none;">
                    <?php esc_html_e('Stop Import', 'juta-importer'); ?>
                </button>
                <button type="button" id="view-logs" class="button button-secondary">
                    <?php esc_html_e('View Logs', 'juta-importer'); ?>
                </button>
                <button type="button" id="clear-logs" class="button button-secondary">
                    <?php esc_html_e('Clear Logs', 'juta-importer'); ?>
                </button>
            </div>
            
            <div id="logs-section" style="margin-top: 20px; display: none;">
                <h3><?php esc_html_e('Import Logs', 'juta-importer'); ?></h3>
                <div id="logs-content" style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; height: 400px; overflow-y: scroll; font-family: monospace; font-size: 12px;">
                </div>
                <button type="button" id="hide-logs" class="button" style="margin-top: 10px;">
                    <?php esc_html_e('Hide Logs', 'juta-importer'); ?>
                </button>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('juta_importer_action', 'juta_importer_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="xml_url"><?php esc_html_e('XML URL', 'juta-importer'); ?></label>
                        </th>
                        <td>
                            <input type="url" 
                                   id="xml_url" 
                                   name="xml_url" 
                                   value="<?php echo esc_attr($xml_url); ?>" 
                                   class="regular-text" 
                                   placeholder="https://example.com/data.xml"
                                   required />
                            <p class="description">
                                <?php esc_html_e('Enter the URL of the XML file to import data from.', 'juta-importer'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="batch_size"><?php esc_html_e('Batch Size', 'juta-importer'); ?></label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="batch_size" 
                                   name="batch_size" 
                                   value="<?php echo esc_attr($batch_size); ?>" 
                                   min="1" 
                                   max="1000" 
                                   class="small-text" 
                                   required />
                            <p class="description">
                                <?php esc_html_e('Number of items to process in each batch (default: 50).', 'juta-importer'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            let importRunning = false;
            let importInterval;
            
            $('#start-import').on('click', function() {
                if (importRunning) return;
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'juta_start_import',
                        nonce: '<?php echo wp_create_nonce('juta_import_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            startImportMonitoring();
                        } else {
                            alert(response.data.message || '<?php esc_html_e('Import failed to start.', 'juta-importer'); ?>');
                        }
                    }
                });
            });
            
            $('#stop-import').on('click', function() {
                if (confirm('<?php esc_html_e('Are you sure you want to stop the import?', 'juta-importer'); ?>')) {
                    stopImportRequest();
                }
            });
            
            $('#view-logs').on('click', function() {
                viewLogs();
            });
            
            $('#clear-logs').on('click', function() {
                if (confirm('<?php esc_html_e('Are you sure you want to clear all logs?', 'juta-importer'); ?>')) {
                    clearLogs();
                }
            });
            
            $('#hide-logs').on('click', function() {
                $('#logs-section').hide();
            });
            
            $('#toggle-auto-import').on('click', function() {
                const currentStatus = $('#auto-import-status-text').text();
                const enable = currentStatus === 'Disabled';
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'juta_toggle_auto_import',
                        enable: enable,
                        nonce: '<?php echo wp_create_nonce('juta_import_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            const newStatus = enable ? 'Enabled' : 'Disabled';
                            const newButtonText = enable ? '<?php esc_html_e('Disable Auto Import', 'juta-importer'); ?>' : '<?php esc_html_e('Enable Auto Import', 'juta-importer'); ?>';
                            
                            $('#auto-import-status-text').text(newStatus);
                            $('#toggle-auto-import').text(newButtonText);
                            $('#next-scheduled-time').text(response.data.next_scheduled);
                            
                            alert(response.data.message || '<?php esc_html_e('Auto import settings updated.', 'juta-importer'); ?>');
                        } else {
                            alert(response.data.message || '<?php esc_html_e('Failed to update auto import settings.', 'juta-importer'); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php esc_html_e('Failed to update auto import settings.', 'juta-importer'); ?>');
                    }
                });
            });
            
            function startImportMonitoring() {
                importRunning = true;
                $('#start-import').prop('disabled', true).hide();
                $('#stop-import').show();
                $('#import-status').show();
                
                importInterval = setInterval(function() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'juta_get_import_status',
                            nonce: '<?php echo wp_create_nonce('juta_import_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                updateProgress(response.data);
                                if (response.data.status === 'completed' || response.data.status === 'error' || response.data.status === 'stopped') {
                                    stopImport();
                                }
                            }
                        }
                    });
                }, 2000);
            }
            
            function stopImportRequest() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'juta_stop_import',
                        nonce: '<?php echo wp_create_nonce('juta_import_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            stopImport();
                            alert('<?php esc_html_e('Import stopped successfully.', 'juta-importer'); ?>');
                        } else {
                            alert(response.data.message || '<?php esc_html_e('Failed to stop import.', 'juta-importer'); ?>');
                        }
                    }
                });
            }
            
            function stopImport() {
                importRunning = false;
                clearInterval(importInterval);
                $('#start-import').prop('disabled', false).show();
                $('#stop-import').hide();
            }
            
            function updateProgress(data) {
                let percentage = data.total > 0 ? Math.round((data.processed / data.total) * 100) : 0;
                $('#progress-fill').css('width', percentage + '%');
                $('#progress-text').text(percentage + '% (' + data.processed + '/' + data.total + ')');
                $('#import-message').text(data.message || '<?php esc_html_e('Import in progress...', 'juta-importer'); ?>');
            }
            
            function viewLogs() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'juta_view_logs',
                        nonce: '<?php echo wp_create_nonce('juta_import_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#logs-content').html('<pre>' + response.data.logs + '</pre>');
                            $('#logs-section').show();
                        } else {
                            alert(response.data.message || '<?php esc_html_e('Failed to load logs.', 'juta-importer'); ?>');
                        }
                    }
                });
            }
            
            function clearLogs() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'juta_clear_logs',
                        nonce: '<?php echo wp_create_nonce('juta_import_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#logs-content').html('');
                            alert('<?php esc_html_e('Logs cleared successfully.', 'juta-importer'); ?>');
                        } else {
                            alert(response.data.message || '<?php esc_html_e('Failed to clear logs.', 'juta-importer'); ?>');
                        }
                    }
                });
            }
        });
        </script>
        <?php
    }
    
    private function handle_form_submission() {
        if (!isset($_POST['juta_importer_nonce']) || !wp_verify_nonce($_POST['juta_importer_nonce'], 'juta_importer_action')) {
            wp_die(__('Security check failed.', 'juta-importer'));
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'juta-importer'));
        }
        
        $xml_url = sanitize_url($_POST['xml_url']);
        $batch_size = absint($_POST['batch_size']);
        
        if (empty($xml_url) || !filter_var($xml_url, FILTER_VALIDATE_URL)) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>' . esc_html__('Please enter a valid XML URL.', 'juta-importer') . '</p></div>';
            });
            return;
        }
        
        if ($batch_size < 1 || $batch_size > 1000) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>' . esc_html__('Batch size must be between 1 and 1000.', 'juta-importer') . '</p></div>';
            });
            return;
        }
        
        update_option('juta_xml_url', $xml_url);
        update_option('juta_batch_size', $batch_size);
        
        wp_redirect(add_query_arg('updated', 'true', wp_get_referer()));
        exit;
    }
    
    public function handle_import_request() {
        if (!wp_verify_nonce($_POST['nonce'], 'juta_import_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }
        
        $xml_url = get_option('juta_xml_url');
        $batch_size = get_option('juta_batch_size', 50);
        
        if (empty($xml_url)) {
            wp_send_json_error(array('message' => 'XML URL not configured'));
        }
        
        $this->log('INFO', 'Starting import from XML URL: ' . $xml_url);
        
        $xml_data = $this->fetch_xml_data($xml_url);
        if ($xml_data === false) {
            $this->log('ERROR', 'Failed to fetch XML data from URL: ' . $xml_url);
            wp_send_json_error(array('message' => 'Failed to fetch XML data'));
        }
        
        $this->log('INFO', 'XML data fetched successfully. Size: ' . strlen($xml_data) . ' bytes');
        
        $products = $this->parse_xml_products($xml_data);
        if (empty($products)) {
            $this->log('ERROR', 'No products found in XML data');
            wp_send_json_error(array('message' => 'No products found in XML'));
        }
        
        $this->log('INFO', 'Found ' . count($products) . ' products in XML');
        
        // Store products in a temporary file instead of options table (too large)
        $products_file = $this->log_file_path . 'products-temp.json';
        $json_result = file_put_contents($products_file, json_encode($products, JSON_UNESCAPED_UNICODE));
        
        if ($json_result === false) {
            $this->log('ERROR', 'Failed to store products data to temporary file');
            wp_send_json_error(array('message' => 'Failed to store products data'));
        }
        
        $this->log('INFO', 'Stored ' . count($products) . ' products to temporary file. File size: ' . filesize($products_file) . ' bytes');
        
        update_option('juta_import_status', 'running');
        update_option('juta_import_total', count($products));
        update_option('juta_import_processed', 0);
        update_option('juta_import_batch_size', $batch_size);
        update_option('juta_import_message', 'Import started...');
        
        wp_schedule_single_event(time(), 'juta_process_batch_hook');
        
        wp_send_json_success(array('message' => 'Import started'));
    }
    
    public function process_batch() {
        // Skip nonce check when called via cron/scheduled event
        if (defined('DOING_CRON') && DOING_CRON) {
            // Called from scheduled event, no nonce needed
        } elseif (isset($_POST['nonce'])) {
            // Called via AJAX, verify nonce
            if (!wp_verify_nonce($_POST['nonce'], 'juta_import_nonce')) {
                wp_die('Security check failed');
            }
        } else {
            // No nonce provided and not a cron job
            $this->process_batch_internal();
            return;
        }
        
        $status = get_option('juta_import_status', 'idle');
        if ($status !== 'running') {
            if (isset($_POST['nonce'])) {
                wp_send_json_error(array('message' => 'Import not running'));
            }
            return;
        }
        
        // Load products from temporary file
        $products_file = $this->log_file_path . 'products-temp.json';
        $products = array();
        
        if (file_exists($products_file)) {
            $products_json = file_get_contents($products_file);
            if ($products_json !== false) {
                $products = json_decode($products_json, true);
                if ($products === null) {
                    $this->log('ERROR', 'Failed to decode products JSON from temporary file');
                    $products = array();
                }
            } else {
                $this->log('ERROR', 'Failed to read products from temporary file');
            }
        } else {
            $this->log('ERROR', 'Products temporary file not found: ' . $products_file);
        }
        
        $processed = get_option('juta_import_processed', 0);
        $batch_size = get_option('juta_import_batch_size', 50);
        $total = get_option('juta_import_total', 0);
        
        if ($processed >= $total) {
            update_option('juta_import_status', 'completed');
            update_option('juta_import_message', 'Import completed successfully');
            wp_send_json_success(array('message' => 'Import completed'));
        }
        
        $batch_products = array_slice($products, $processed, $batch_size);
        $imported_count = 0;
        
        $this->log('INFO', 'Processing batch of ' . count($batch_products) . ' products (batch size: ' . $batch_size . ')');
        
        foreach ($batch_products as $product_data) {
            try {
                $this->log('DEBUG', 'Processing product: ' . json_encode($product_data, JSON_UNESCAPED_UNICODE));
                $product_id = $this->import_product($product_data);
                $this->log('SUCCESS', 'Successfully imported/updated product ID: ' . $product_id . ' (SKU: ' . $product_data['id'] . ')');
                $imported_count++;
            } catch (Exception $e) {
                $this->log('ERROR', 'Failed to import product (SKU: ' . $product_data['id'] . '): ' . $e->getMessage());
                error_log('Juta Importer: Failed to import product: ' . $e->getMessage());
            }
        }
        
        $processed += $imported_count;
        update_option('juta_import_processed', $processed);
        update_option('juta_import_message', "Processed {$processed} of {$total} products");
        
        if ($processed >= $total) {
            update_option('juta_import_status', 'completed');
            update_option('juta_import_message', 'Import completed successfully');
            $this->log('INFO', 'Import completed successfully. Total products processed: ' . $processed);
            
            // Clean up temporary file
            $products_file = $this->log_file_path . 'products-temp.json';
            if (file_exists($products_file)) {
                unlink($products_file);
                $this->log('INFO', 'Cleaned up temporary products file');
            }
        } else {
            wp_schedule_single_event(time() + 2, 'juta_process_batch_hook');
        }
        
        if (isset($_POST['nonce'])) {
            wp_send_json_success(array(
                'processed' => $processed,
                'total' => $total,
                'message' => get_option('juta_import_message')
            ));
        }
    }
    
    public function process_batch_internal() {
        $status = get_option('juta_import_status', 'idle');
        if ($status !== 'running') {
            $this->log('INFO', 'Batch processing stopped. Status: ' . $status);
            return;
        }
        
        // Load products from temporary file
        $products_file = $this->log_file_path . 'products-temp.json';
        $products = array();
        
        if (file_exists($products_file)) {
            $products_json = file_get_contents($products_file);
            if ($products_json !== false) {
                $products = json_decode($products_json, true);
                if ($products === null) {
                    $this->log('ERROR', 'Failed to decode products JSON from temporary file');
                    $products = array();
                }
            } else {
                $this->log('ERROR', 'Failed to read products from temporary file');
            }
        } else {
            $this->log('ERROR', 'Products temporary file not found: ' . $products_file);
        }
        
        $processed = get_option('juta_import_processed', 0);
        $batch_size = get_option('juta_import_batch_size', 50);
        $total = get_option('juta_import_total', 0);
        
        if ($processed >= $total) {
            update_option('juta_import_status', 'completed');
            update_option('juta_import_message', 'Import completed successfully');
            $this->log('INFO', 'Import completed successfully. Total products processed: ' . $processed);
            return;
        }
        
        $batch_products = array_slice($products, $processed, $batch_size);
        $imported_count = 0;
        
        $this->log('INFO', 'Processing batch of ' . count($batch_products) . ' products (batch size: ' . $batch_size . ')');
        
        foreach ($batch_products as $product_data) {
            try {
                $this->log('DEBUG', 'Processing product: ' . json_encode($product_data, JSON_UNESCAPED_UNICODE));
                $product_id = $this->import_product($product_data);
                $this->log('SUCCESS', 'Successfully imported/updated product ID: ' . $product_id . ' (SKU: ' . $product_data['id'] . ')');
                $imported_count++;
            } catch (Exception $e) {
                $this->log('ERROR', 'Failed to import product (SKU: ' . $product_data['id'] . '): ' . $e->getMessage());
                error_log('Juta Importer: Failed to import product: ' . $e->getMessage());
            }
        }
        
        $processed += $imported_count;
        update_option('juta_import_processed', $processed);
        update_option('juta_import_message', "Processed {$processed} of {$total} products");
        
        if ($processed >= $total) {
            update_option('juta_import_status', 'completed');
            update_option('juta_import_message', 'Import completed successfully');
            $this->log('INFO', 'Import completed successfully. Total products processed: ' . $processed);
            
            // Clean up temporary file
            $products_file = $this->log_file_path . 'products-temp.json';
            if (file_exists($products_file)) {
                unlink($products_file);
                $this->log('INFO', 'Cleaned up temporary products file');
            }
        } else {
            wp_schedule_single_event(time() + 2, 'juta_process_batch_hook');
        }
    }
    
    public function get_import_status() {
        if (!wp_verify_nonce($_POST['nonce'], 'juta_import_nonce')) {
            wp_die('Security check failed');
        }
        
        wp_send_json_success(array(
            'status' => get_option('juta_import_status', 'idle'),
            'processed' => get_option('juta_import_processed', 0),
            'total' => get_option('juta_import_total', 0),
            'message' => get_option('juta_import_message', '')
        ));
    }
    
    public function stop_import() {
        if (!wp_verify_nonce($_POST['nonce'], 'juta_import_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }
        
        $current_status = get_option('juta_import_status', 'idle');
        
        if ($current_status === 'running') {
            update_option('juta_import_status', 'stopped');
            $this->log('INFO', 'Import manually stopped by user');
            
            // Clean up temporary file
            $products_file = $this->log_file_path . 'products-temp.json';
            if (file_exists($products_file)) {
                unlink($products_file);
                $this->log('INFO', 'Cleaned up temporary products file after stop');
            }
            
            // Clear scheduled events
            wp_clear_scheduled_hook('juta_process_batch_hook');
            
            update_option('juta_import_message', 'Import stopped by user');
            wp_send_json_success(array('message' => 'Import stopped successfully'));
        } else {
            wp_send_json_error(array('message' => 'No active import to stop'));
        }
    }
    
    private function fetch_xml_data($url) {
        $response = wp_remote_get($url, array('timeout' => 30));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return false;
        }
        
        return $body;
    }
    
    private function parse_xml_products($xml_data) {
        $products = array();
        
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_data);
        
        if ($xml === false) {
            return false;
        }
        
        if (isset($xml->products->product)) {
            foreach ($xml->products->product as $product) {
                $products[] = $this->convert_xml_to_array($product);
            }
        }
        
        return $products;
    }
    
    private function convert_xml_to_array($xml_element) {
        $array = array();
        
        foreach ($xml_element->children() as $child) {
            $name = $child->getName();
            $value = (string) $child;
            
            if ($name === 'params' && $child->param) {
                $array['params'] = array();
                foreach ($child->param as $param) {
                    $array['params'][] = array(
                        'id' => (string) $param->id,
                        'value' => (string) $param->value
                    );
                }
            } else {
                $array[$name] = $value;
            }
        }
        
        return $array;
    }
    
    private function import_product($product_data) {
        $sku = sanitize_text_field($product_data['id']);
        $existing_product_id = wc_get_product_id_by_sku($sku);
        
        $is_update = false;
        if ($existing_product_id) {
            $product = wc_get_product($existing_product_id);
            $is_update = true;
            $this->log('INFO', 'Updating existing product ID: ' . $existing_product_id . ' (SKU: ' . $sku . ')');
        } else {
            $product = new WC_Product_Simple();
            $this->log('INFO', 'Creating new product (SKU: ' . $sku . ')');
        }
        
        $product->set_sku($sku);
        
        // Only update product details for new products
        if (!$is_update) {
            // Create product title: Producer + extracted model from note1
            $product_title = $this->create_product_title($product_data);
            $product->set_name(sanitize_text_field($product_title));
            $this->log('DEBUG', 'Set product name: ' . $product_title);
            
            if (!empty($product_data['barcode'])) {
                $product->update_meta_data('_ean', sanitize_text_field($product_data['barcode']));
                $this->log('DEBUG', 'Set EAN: ' . $product_data['barcode']);
            }
            
            if (!empty($product_data['producer'])) {
                // Handle pa_brand attribute (this can be done before save)
                $brand_term = $this->get_or_create_term($product_data['producer'], 'pa_brand');
                if ($brand_term) {
                    $product->update_meta_data('_brand', $brand_term->name);
                    $this->log('DEBUG', 'Set pa_brand attribute: ' . $brand_term->name);
                }
            }
        } else {
            $this->log('DEBUG', 'Skipping name, EAN, and brand updates for existing product');
        }
        
        // Update stock quantity with old -> new logging
        if (isset($product_data['qty']) && is_numeric($product_data['qty'])) {
            $new_qty = intval($product_data['qty']);
            
            if ($is_update) {
                $old_qty = $product->get_stock_quantity();
                $old_status = $product->get_stock_status();
                
                $product->set_stock_quantity($new_qty);
                $product->set_manage_stock(true);
                $new_status = $new_qty > 0 ? 'instock' : 'outofstock';
                $product->set_stock_status($new_status);
                
                $this->log('INFO', 'Updated stock quantity: ' . $old_qty . ' -> ' . $new_qty . ' (status: ' . $old_status . ' -> ' . $new_status . ')');
            } else {
                $product->set_stock_quantity($new_qty);
                $product->set_manage_stock(true);
                $product->set_stock_status($new_qty > 0 ? 'instock' : 'outofstock');
                $this->log('DEBUG', 'Set stock quantity: ' . $new_qty . ' (status: ' . ($new_qty > 0 ? 'instock' : 'outofstock') . ')');
            }
        }
        
        // Update prices with old -> new logging
        if (isset($product_data['price']) && is_numeric($product_data['price'])) {
            $new_sale_price = round( floatval($product_data['price']) / 1.21, 2 );
            
            if ($is_update) {
                $old_sale_price = $product->get_sale_price();
                $old_regular_price = $product->get_regular_price();
                
                $product->set_sale_price($new_sale_price);
                
                if (isset($product_data['oldprice']) && is_numeric($product_data['oldprice'])) {
                    $new_regular_price = round( floatval($product_data['oldprice']) / 1.21, 2 );
                    $product->set_regular_price($new_regular_price);
                    
                    $this->log('INFO', 'Updated prices - Regular: ' . $old_regular_price . ' -> ' . $new_regular_price . ', Sale: ' . $old_sale_price . ' -> ' . $new_sale_price);
                } else {
                    $product->set_regular_price($new_sale_price);
                    $this->log('INFO', 'Updated prices - Regular: ' . $old_regular_price . ' -> ' . $new_sale_price . ', Sale: ' . $old_sale_price . ' -> ' . $new_sale_price);
                }
            } else {
                $product->set_sale_price($new_sale_price);
                
                if (isset($product_data['oldprice']) && is_numeric($product_data['oldprice'])) {
                    $regular_price = round( floatval($product_data['oldprice']) / 1.21, 2 );
                    $product->set_regular_price($regular_price);
                    $this->log('DEBUG', 'Set prices - Regular: ' . $regular_price . ', Sale: ' . $new_sale_price);
                } else {
                    $product->set_regular_price($new_sale_price);
                    $this->log('DEBUG', 'Set price: ' . $new_sale_price);
                }
            }
        }

        // Set shipping class to "juta"
        $shipping_class = get_term_by('slug', 'juta', 'product_shipping_class');
        if ($shipping_class) {
            $product->set_shipping_class_id($shipping_class->term_id);
            $this->log('DEBUG', 'Assigned shipping class "juta" (ID: ' . $shipping_class->term_id . ')');
        } else {
            $this->log('WARNING', 'Shipping class "juta" not found - please create it first');
        }

        $product->set_status('publish');
        $product->save();

        // Store all XML product data as meta fields with juta_ prefix
        $this->store_juta_meta_data($product->get_id(), $product_data);
        
        // After saving, assign category, images, attributes and brands only for new products
        if (!$is_update) {
            // Handle pa_brand taxonomy assignment (needs product ID)
            if (!empty($product_data['producer'])) {
                $brand_term = $this->get_or_create_term($product_data['producer'], 'pa_brand');
                if ($brand_term) {
                    wp_set_object_terms($product->get_id(), $brand_term->term_id, 'pa_brand');
                    $this->log('DEBUG', 'Assigned pa_brand taxonomy: ' . $brand_term->name);
                }
                
                // Handle product_brand taxonomy (needs product ID)
                $this->handle_product_brand($product->get_id(), $product_data['producer']);
            }
            
            if (!empty($product_data['groupid'])) {
                $this->assign_product_category($product, $product_data['groupid']);
            }
            
            if (!empty($product_data['jpg1'])) {
                $this->set_product_image($product, $product_data['jpg1']);
            }
            
            if (isset($product_data['params']) && is_array($product_data['params'])) {
                $this->process_product_attributes($product, $product_data['params']);
            }

            // Save product again after setting image, category, and attributes
            $product->save();
            $this->log('DEBUG', 'Saved product after setting image, category, and attributes');
        } else {
            // For existing products, only set image if product doesn't have a thumbnail
            $needs_save = false;

            if (!empty($product_data['jpg1'])) {
                $current_image_id = $product->get_image_id();

                if (empty($current_image_id)) {
                    $this->log('DEBUG', 'Existing product has no thumbnail, setting image from XML');
                    $this->set_product_image($product, $product_data['jpg1']);
                    $needs_save = true;
                } else {
                    $this->log('DEBUG', 'Existing product already has thumbnail (ID: ' . $current_image_id . '), keeping it');
                }
            }

            if ($needs_save) {
                $product->save();
                $this->log('DEBUG', 'Saved product after setting missing thumbnail');
            } else {
                $this->log('DEBUG', 'No image updates needed for existing product');
            }
        }

        return $product->get_id();
    }
    
    private function get_or_create_term($term_name, $taxonomy) {
        $term_name = sanitize_text_field($term_name);
        
        if (!taxonomy_exists($taxonomy)) {
            return false;
        }
        
        $term = get_term_by('name', $term_name, $taxonomy);
        
        if (!$term) {
            $term_data = wp_insert_term($term_name, $taxonomy);
            if (!is_wp_error($term_data)) {
                $term = get_term($term_data['term_id'], $taxonomy);
            }
        }
        
        return $term;
    }
    
    private function process_product_attributes($product, $xml_params) {
        $attribute_mapping = $this->get_xml_param_to_attribute_mapping();
        $product_attributes = array();
        
        foreach ($xml_params as $param) {
            $param_id = $param['id'];
            $param_value = trim($param['value']);
            
            if (empty($param_value) || !isset($attribute_mapping[$param_id])) {
                continue;
            }
            
            $attribute_slug = $attribute_mapping[$param_id];
            $taxonomy = 'pa_' . $attribute_slug;
            
            if (!taxonomy_exists($taxonomy)) {
                $this->log('WARNING', 'Taxonomy does not exist: ' . $taxonomy);
                continue;
            }
            
            // Format the attribute value based on attribute type
            $formatted_value = $this->format_attribute_value($attribute_slug, $param_value);
            
            $term = $this->get_or_create_term($formatted_value, $taxonomy);
            if ($term) {
                // Set the term to the product
                wp_set_object_terms($product->get_id(), $term->term_id, $taxonomy, true);
                
                // Create proper WooCommerce attribute structure
                $attribute_object = new WC_Product_Attribute();
                $attribute_object->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
                $attribute_object->set_name($taxonomy);
                $attribute_object->set_options(array($term->term_id));
                $attribute_object->set_visible(true);
                $attribute_object->set_variation(false);
                
                $product_attributes[$taxonomy] = $attribute_object;
                
                $this->log('DEBUG', 'Set attribute ' . $attribute_slug . ': ' . $formatted_value . ' (term ID: ' . $term->term_id . ')');
            } else {
                $this->log('WARNING', 'Failed to create/find term for attribute ' . $attribute_slug . ': ' . $formatted_value);
            }
        }
        
        if (!empty($product_attributes)) {
            $product->set_attributes($product_attributes);
            $product->save(); // Save again to persist attributes
            
            // Also save as meta data for backup
            update_post_meta($product->get_id(), '_product_attributes', $this->serialize_attributes($product_attributes));
            
            $this->log('DEBUG', 'Set ' . count($product_attributes) . ' product attributes and saved product');
        }
    }
    
    private function serialize_attributes($product_attributes) {
        $serialized = array();
        
        foreach ($product_attributes as $taxonomy => $attribute_object) {
            $serialized[$taxonomy] = array(
                'name' => $taxonomy,
                'value' => '',
                'position' => 0,
                'is_visible' => 1,
                'is_variation' => 0,
                'is_taxonomy' => 1
            );
        }
        
        return $serialized;
    }
    
    private function format_attribute_value($attribute_slug, $value) {
        switch ($attribute_slug) {
            case 'width':
                // Add 'mm' to width values
                if (is_numeric($value)) {
                    return $value . 'mm';
                }
                return $value;
                
            case 'diameter':
                // Remove 'R' and add inch symbol
                $value = str_replace('R', '', $value);
                if (is_numeric($value)) {
                    return $value . '″';
                }
                return $value;
                
            case 'tire_season':
                // Map tire season values
                $season_mapping = array(
                    'Žieminė' => 'Žieminės',
                    'Vasarinė' => 'Vasarinės', 
                    'Universali' => 'Universalios',
                    'Winter' => 'Žieminės',
                    'Summer' => 'Vasarinės',
                    'All Season' => 'Universalios',
                    'All-Season' => 'Universalios'
                );
                
                return isset($season_mapping[$value]) ? $season_mapping[$value] : $value;
                
            default:
                return $value;
        }
    }
    
    private function get_xml_param_to_attribute_mapping() {
        return array(
            '10000000242' => 'width',
            '10000000243' => 'aspect_ratio', 
            '10000000241' => 'diameter',
            '10000000244' => 'load_index',
            '10000000262' => 'speed_index',
            '10000000245' => 'tire_season',
            '10000000263' => 'pattern_model',
            '10000000264' => 'tyre_class',
            '10000000266' => 'fuel_efficiency',
            '10000000267' => 'wet_grip',
            '10000000268' => 'noise_level',
            '10000000265' => 'run_flat',
            '9990000000622' => 'noise_class',
            '10000000602' => 'tyre_class',
            '10000000163' => 'extra_info',
            '10000000164' => 'spikes',
            '10000000523' => 'oe_marking',
            '10000000524' => 'noise_level',
            '10000000542' => 'dot_year',
            '10000000543' => 'extra_info',
            '10000000562' => 'extra_info',
            '10000000522' => 'extra_load',
            '10000000582' => 'extra_info',
            '9990000000642' => 'snow_grip',
            '9990000000662' => 'ice_grip',
            '9990000000722' => 'extra_info',
            '9990000000762' => 'extra_info',
            '9990000000782' => 'extra_info'
        );
    }
    
    private function assign_product_category($product, $group_id) {
        $category_mapping = $this->get_category_mapping();
        
        if (isset($category_mapping[$group_id])) {
            $category_slug = $category_mapping[$group_id];
            $category_term = get_term_by('slug', $category_slug, 'product_cat');
            
            if ($category_term) {
                wp_set_object_terms($product->get_id(), $category_term->term_id, 'product_cat');
                $this->log('DEBUG', 'Assigned to category: ' . $category_term->name . ' (slug: ' . $category_slug . ')');
            } else {
                $this->log('WARNING', 'Category not found for slug: ' . $category_slug . ' (group ID: ' . $group_id . ')');
            }
        } else {
            $this->log('WARNING', 'No category mapping found for group ID: ' . $group_id);
        }
    }
    
    private function get_category_mapping() {
        return array(
            '10000002322' => 'summer-tires-pv',
            '10000002323' => 'winter-tires-friction', 
            '10000002342' => 'uncategorized',
            '10000002348' => 'truck-tires',
            '10000002346' => 'mc-tires',
            '10000002347' => 'uncategorized',
            '10000002266' => 'uncategorized',
            '10000002375' => 'uncategorized',
            '10000002548' => 'uncategorized',
            '10000002553' => 'uncategorized',
            '10000002422' => 'all-season-pcr',
            '10000002423' => 'summer-tires-pv',
            '10000002902' => 'uncategorized',
            '10000003242' => 'uncategorized'
        );
    }
    
    private function set_product_image($product, $image_url) {
        if (empty($image_url)) {
            return;
        }
        
        $image_url = esc_url_raw($image_url);
        $product_xml_id = $product->get_sku(); // Use SKU as XML product ID
        
        // Check if image already exists for this XML product ID
        $existing_attachment_id = $this->find_existing_image_by_xml_id($product_xml_id);
        
        if ($existing_attachment_id) {
            $product->set_image_id($existing_attachment_id);
            $this->log('DEBUG', 'Reused existing image (ID: ' . $existing_attachment_id . ') for XML product ID: ' . $product_xml_id);
        } else {
            $attachment_id = $this->upload_image_from_url($image_url, $product->get_name(), $product_xml_id);
            
            if ($attachment_id) {
                $product->set_image_id($attachment_id);
                $this->log('DEBUG', 'Set product image from URL: ' . $image_url . ' (new upload ID: ' . $attachment_id . ')');
            } else {
                $this->log('WARNING', 'Failed to upload image from URL: ' . $image_url);
            }
        }
    }
    
    private function upload_image_from_url($image_url, $product_name, $xml_product_id) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $tmp = download_url($image_url);
        
        if (is_wp_error($tmp)) {
            return false;
        }
        
        $file_array = array(
            'name' => basename($image_url),
            'tmp_name' => $tmp
        );
        
        $id = media_handle_sideload($file_array, 0, $product_name . ' - Product Image');
        
        if (is_wp_error($id)) {
            @unlink($tmp);
            return false;
        }
        
        // Add meta field to track XML product ID
        update_post_meta($id, '_juta_xml_product_id', $xml_product_id);
        
        return $id;
    }
    
    private function find_existing_image_by_xml_id($xml_product_id) {
        global $wpdb;
        
        // Query for attachments with our custom meta field
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_juta_xml_product_id' 
             AND meta_value = %s 
             LIMIT 1",
            $xml_product_id
        ));
        
        if ($attachment_id) {
            // Verify the attachment still exists and is valid
            if (wp_attachment_is_image($attachment_id) && get_post($attachment_id)) {
                return intval($attachment_id);
            } else {
                // Clean up invalid meta entry
                delete_post_meta($attachment_id, '_juta_xml_product_id');
                $this->log('DEBUG', 'Cleaned up invalid image meta for XML product ID: ' . $xml_product_id);
            }
        }
        
        return false;
    }
    
    private function handle_product_brand($product_id, $brand_name) {
        $brand_name = trim((string) $brand_name);
        if ($brand_name === '') {
            $this->log('DEBUG', "Brand name empty, skipping for product {$product_id}");
            return;
        }

        $tax = 'product_brand';
        $this->log('DEBUG', "Setting brand '{$brand_name}' for product {$product_id}");

        // Bail early if taxonomy not ready
        if (!taxonomy_exists($tax)) {
            $this->log('WARNING', "Taxonomy '{$tax}' does not exist yet.");
            return;
        }

        // Try to find existing term
        $term = get_term_by('name', $brand_name, $tax);

        if (!$term) {
            $this->log('DEBUG', "Brand term '{$brand_name}' not found, creating...");

            $insert = wp_insert_term($brand_name, $tax, [
                'slug' => sanitize_title($brand_name),
            ]);

            if (is_wp_error($insert)) {
                $this->log('WARNING', "wp_insert_term error: " . $insert->get_error_message());

                $term_id = isset($insert->error_data['term_exists']) ? (int) $insert->error_data['term_exists'] : 0;
                if ($term_id) {
                    $this->log('DEBUG', "Brand already exists with term_id {$term_id}");
                } else {
                    $this->log('ERROR', "Brand creation failed completely, skipping.");
                    return;
                }
            } else {
                $term_id = (int) $insert['term_id'];
                $this->log('DEBUG', "Brand '{$brand_name}' created with term_id {$term_id}");
            }
        } else {
            $term_id = (int) $term->term_id;
            $this->log('DEBUG', "Brand '{$brand_name}' already exists with term_id {$term_id}");
        }

        // Assign brand to product
        wp_set_object_terms($product_id, [$term_id], $tax, false);
        $this->log('DEBUG', "Brand '{$brand_name}' assigned to product {$product_id}");
    }
    
    private function store_juta_meta_data($product_id, $product_data) {
        // Add imported-from identifier
        update_post_meta($product_id, 'imported-from', 'juta');
        
        // Store all XML fields with juta_ prefix
        $meta_fields = array(
            'juta_id' => $product_data['id'] ?? '',
            'juta_name' => $product_data['name'] ?? '',
            'juta_barcode' => $product_data['barcode'] ?? '',
            'juta_producer' => $product_data['producer'] ?? '',
            'juta_unit' => $product_data['unit'] ?? '',
            'juta_groupid' => $product_data['groupid'] ?? '',
            'juta_jpg1' => $product_data['jpg1'] ?? '',
            'juta_jpg2' => $product_data['jpg2'] ?? '',
            'juta_qty' => $product_data['qty'] ?? '',
            'juta_price' => $product_data['price'] ?? '',
            'juta_discount' => $product_data['discount'] ?? '',
            'juta_oldprice' => $product_data['oldprice'] ?? '',
            'juta_note1' => $product_data['note1'] ?? '',
            'juta_note2' => $product_data['note2'] ?? '',
            'juta_note3' => $product_data['note3'] ?? '',
            'juta_note4' => $product_data['note4'] ?? '',
            'juta_note5' => $product_data['note5'] ?? '',
            'juta_note6' => $product_data['note6'] ?? '',
            'juta_note7' => $product_data['note7'] ?? '',
            'juta_note8' => $product_data['note8'] ?? '',
            'juta_note9' => $product_data['note9'] ?? '',
            'juta_note10' => $product_data['note10'] ?? '',
            'juta_note11' => $product_data['note11'] ?? '',
            'juta_netto' => $product_data['netto'] ?? '',
            'juta_brutto' => $product_data['brutto'] ?? '',
        );
        
        foreach ($meta_fields as $meta_key => $meta_value) {
            if (!empty($meta_value)) {
                update_post_meta($product_id, $meta_key, sanitize_text_field($meta_value));
            }
        }
        
        // Store params as JSON
        if (!empty($product_data['params']) && is_array($product_data['params'])) {
            update_post_meta($product_id, 'juta_params', json_encode($product_data['params'], JSON_UNESCAPED_UNICODE));
        }
        
        // Store import timestamp
        update_post_meta($product_id, 'juta_imported_at', current_time('mysql'));
        
        $this->log('DEBUG', 'Stored Juta meta data for product ' . $product_id);
    }
    
    private function create_product_title($product_data) {
        $producer = !empty($product_data['producer']) ? trim($product_data['producer']) : '';
        $model = $this->extract_model_from_note1($product_data);
        
        if (!empty($producer) && !empty($model)) {
            $title = $producer . ' ' . $model;
            $this->log('DEBUG', 'Created title from producer "' . $producer . '" + model "' . $model . '" = "' . $title . '"');
            return $title;
        } elseif (!empty($producer)) {
            $this->log('DEBUG', 'Using producer as title (no model extracted): ' . $producer);
            return $producer;
        } elseif (!empty($model)) {
            $this->log('DEBUG', 'Using model as title (no producer): ' . $model);
            return $model;
        } else {
            // Fallback to original name if both are empty
            $fallback = !empty($product_data['name']) ? $product_data['name'] : 'Product ' . $product_data['id'];
            $this->log('WARNING', 'No producer or model found, using fallback title: ' . $fallback);
            return $fallback;
        }
    }
    
    private function extract_model_from_note1($product_data) {
        if (empty($product_data['note1'])) {
            $this->log('DEBUG', 'Note1 is empty, cannot extract model');
            return '';
        }
        
        $note1 = trim($product_data['note1']);
        $this->log('DEBUG', 'Extracting model from note1: ' . $note1);
        
        // Extract the first part before any space followed by numbers or parentheses
        // Examples:
        // "SL-6 106/104 N ( C C A 70dB )" -> "SL-6"
        // "CW-25 112/110 R ( C C B 72dB )" -> "CW-25"
        // "ADVANTEX SUV TR259 215/70R16" -> "ADVANTEX SUV TR259" (up to first number sequence)
        
        // Method 1: Extract everything before first space followed by numbers
        if (preg_match('/^([A-Za-z0-9\-\/\+\.\s]+?)(?:\s+\d|$)/', $note1, $matches)) {
            $model = trim($matches[1]);
            // Remove trailing spaces and common separators
            $model = rtrim($model, ' -/');
            if (!empty($model)) {
                $this->log('DEBUG', 'Extracted model using pattern 1: "' . $model . '"');
                return $model;
            }
        }
        
        // Method 2: If first method fails, try to extract the first word/phrase before parentheses
        if (preg_match('/^([^()]+?)(?:\s*\(|$)/', $note1, $matches)) {
            $model = trim($matches[1]);
            // Remove trailing numbers and common separators
            $model = preg_replace('/\s+\d+.*$/', '', $model);
            $model = rtrim($model, ' -/');
            if (!empty($model)) {
                $this->log('DEBUG', 'Extracted model using pattern 2: "' . $model . '"');
                return $model;
            }
        }
        
        // Method 3: Fallback - take first 1-3 words that contain letters
        $words = explode(' ', $note1);
        $model_parts = array();
        
        foreach ($words as $word) {
            $word = trim($word);
            // Stop if we hit a word that's mostly numbers or dimensions
            if (preg_match('/^\d+[\/\d]*$/', $word) || preg_match('/^\d+[Rr]\d+/', $word)) {
                break;
            }
            
            // Include words that contain letters (model names usually have letters)
            if (preg_match('/[A-Za-z]/', $word)) {
                $model_parts[] = $word;
                // Limit to 3 words to avoid too long model names
                if (count($model_parts) >= 3) {
                    break;
                }
            }
        }
        
        if (!empty($model_parts)) {
            $model = implode(' ', $model_parts);
            $this->log('DEBUG', 'Extracted model using fallback method: "' . $model . '"');
            return $model;
        }
        
        $this->log('WARNING', 'Could not extract model from note1: ' . $note1);
        return '';
    }
    
    private function log($level, $message) {
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        $log_file = $this->log_file_path . 'import-' . date('Y-m-d') . '.log';
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        $this->manage_log_files();
    }
    
    private function manage_log_files() {
        $max_files = 7;
        $files = glob($this->log_file_path . 'import-*.log');
        
        if (count($files) > $max_files) {
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            for ($i = 0; $i < count($files) - $max_files; $i++) {
                unlink($files[$i]);
            }
        }
    }
    
    public function view_logs() {
        if (!wp_verify_nonce($_POST['nonce'], 'juta_import_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }
        
        $log_files = glob($this->log_file_path . 'import-*.log');
        rsort($log_files);
        
        $logs = '';
        foreach ($log_files as $log_file) {
            if (filesize($log_file) > 0) {
                $logs .= "=== " . basename($log_file) . " ===\n";
                $logs .= file_get_contents($log_file) . "\n";
            }
        }
        
        if (empty($logs)) {
            $logs = 'No logs available.';
        }
        
        wp_send_json_success(array('logs' => $logs));
    }
    
    public function clear_logs() {
        if (!wp_verify_nonce($_POST['nonce'], 'juta_import_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }
        
        $log_files = glob($this->log_file_path . 'import-*.log');
        $cleared = 0;
        
        foreach ($log_files as $log_file) {
            if (unlink($log_file)) {
                $cleared++;
            }
        }
        
        wp_send_json_success(array('message' => "Cleared {$cleared} log files"));
    }
    
    public function activate_plugin() {
        $this->log('INFO', 'Juta Importer plugin activated');
        
        // Schedule daily import if auto import is enabled
        if (get_option('juta_auto_import_enabled', false)) {
            $this->schedule_daily_import();
        }
    }
    
    public function deactivate_plugin() {
        $this->log('INFO', 'Juta Importer plugin deactivated - clearing scheduled imports');
        
        // Clear all scheduled imports
        wp_clear_scheduled_hook('juta_daily_import_hook');
    }
    
    public function run_scheduled_import() {
        $this->log('INFO', 'Starting scheduled daily import at 03:00');
        
        $xml_url = get_option('juta_xml_url');
        $batch_size = get_option('juta_batch_size', 50);
        
        if (empty($xml_url)) {
            $this->log('ERROR', 'Scheduled import failed: XML URL not configured');
            return;
        }
        
        if (!get_option('juta_auto_import_enabled', false)) {
            $this->log('INFO', 'Scheduled import skipped: auto import is disabled');
            return;
        }
        
        // Check if there's already an import running
        $current_status = get_option('juta_import_status', 'idle');
        if ($current_status === 'running') {
            $this->log('WARNING', 'Scheduled import skipped: another import is already running');
            return;
        }
        
        $xml_data = $this->fetch_xml_data($xml_url);
        if ($xml_data === false) {
            $this->log('ERROR', 'Scheduled import failed: Could not fetch XML data from URL: ' . $xml_url);
            return;
        }
        
        $this->log('INFO', 'XML data fetched successfully for scheduled import. Size: ' . strlen($xml_data) . ' bytes');
        
        $products = $this->parse_xml_products($xml_data);
        if (empty($products)) {
            $this->log('ERROR', 'Scheduled import failed: No products found in XML data');
            return;
        }
        
        $this->log('INFO', 'Found ' . count($products) . ' products in XML for scheduled import');
        
        // Store products in temporary file
        $products_file = $this->log_file_path . 'products-temp.json';
        $json_result = file_put_contents($products_file, json_encode($products, JSON_UNESCAPED_UNICODE));
        
        if ($json_result === false) {
            $this->log('ERROR', 'Scheduled import failed: Could not store products data to temporary file');
            return;
        }
        
        $this->log('INFO', 'Stored ' . count($products) . ' products to temporary file for scheduled import');
        
        // Set import status
        update_option('juta_import_status', 'running');
        update_option('juta_import_total', count($products));
        update_option('juta_import_processed', 0);
        update_option('juta_import_batch_size', $batch_size);
        update_option('juta_import_message', 'Scheduled import started at 03:00...');
        
        // Start batch processing
        wp_schedule_single_event(time(), 'juta_process_batch_hook');
        
        $this->log('INFO', 'Scheduled import initiated successfully');
    }
    
    public function toggle_auto_import() {
        if (!wp_verify_nonce($_POST['nonce'], 'juta_import_nonce')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Insufficient permissions');
        }
        
        $enable = isset($_POST['enable']) && $_POST['enable'] === 'true';
        
        update_option('juta_auto_import_enabled', $enable);
        
        if ($enable) {
            $this->schedule_daily_import();
            $next_scheduled = wp_next_scheduled('juta_daily_import_hook');
            $next_time = $next_scheduled ? wp_date('Y-m-d H:i:s', $next_scheduled) : 'Not scheduled';
            
            $this->log('INFO', 'Auto import enabled - next scheduled import: ' . $next_time);
            wp_send_json_success(array(
                'message' => 'Auto import enabled',
                'next_scheduled' => $next_time
            ));
        } else {
            wp_clear_scheduled_hook('juta_daily_import_hook');
            $this->log('INFO', 'Auto import disabled - cleared scheduled imports');
            wp_send_json_success(array(
                'message' => 'Auto import disabled',
                'next_scheduled' => 'Disabled'
            ));
        }
    }
    
    private function schedule_daily_import() {
        // Clear any existing scheduled imports first
        wp_clear_scheduled_hook('juta_daily_import_hook');
        
        // Schedule daily import at 03:00
        $next_3am = strtotime('tomorrow 03:00');
        if (!wp_next_scheduled('juta_daily_import_hook')) {
            wp_schedule_event($next_3am, 'daily', 'juta_daily_import_hook');
            $this->log('INFO', 'Scheduled daily import at 03:00, next run: ' . wp_date('Y-m-d H:i:s', $next_3am));
        }
    }
}

add_action('juta_process_batch_hook', function() {
    $importer = new JutaImporter();
    $importer->process_batch_internal();
});

new JutaImporter();